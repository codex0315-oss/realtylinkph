<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\MessageSent;
use App\Events\MessageUnsent;
use App\Events\MessageUpdated;
use App\Events\MessagesReceipt;
use App\Jobs\SendAiAutoReply;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Property;
use App\Models\User;
use App\Notifications\MessageWhileAway;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConversationService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function findOrCreate(User $buyer, Property $property): Conversation
    {
        $conversation = Conversation::firstOrCreate(
            [
                'property_id' => $property->id,
                'buyer_id'    => $buyer->id,
                'agent_id'    => $property->agent_id,
            ],
            ['last_message_at' => now()]
        );

        // "Message Agent" on a thread the buyer had removed brings it back.
        if ($conversation->buyer_deleted_at !== null) {
            $conversation->update(['buyer_deleted_at' => null]);
        }

        return $conversation;
    }

    /**
     * Remove the conversation from this participant's inbox only. The other
     * side keeps it; a later message from either side restores it.
     */
    public function deleteFor(Conversation $conversation, User $user): void
    {
        $column = $conversation->deletedAtColumnFor($user);
        if ($column) {
            $conversation->update([$column => now()]);
        }
    }

    /**
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files  already checked by SendMessageRequest
     */
    public function sendMessage(User $sender, Conversation $conversation, string $body, array $files = []): Message
    {
        // Files are stored first (slow, outside the transaction); if saving the
        // message then fails, they are removed again.
        $stored = [];
        try {
            foreach ($files as $file) {
                $check = \App\Support\ChatFiles::check($file);
                if (is_array($check)) {
                    $stored[] = \App\Support\ChatFiles::store($conversation->id, $file, $check['kind'], $check['mime']);
                }
            }

            return $this->saveMessage($sender, $conversation, $body, $stored);
        } catch (\Throwable $e) {
            foreach ($stored as $s) {
                \App\Support\ChatFiles::delete($s['path'], $s['thumb_path']);
            }
            throw $e;
        }
    }

    private function saveMessage(User $sender, Conversation $conversation, string $body, array $stored): Message
    {
        return DB::transaction(function () use ($sender, $conversation, $body, $stored): Message {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id'       => $sender->id,
                'body'            => $body,
                'is_read'         => false,
            ]);
            foreach ($stored as $s) {
                $message->attachments()->create($s);
            }
            $message->load('attachments');
            $preview = $body !== '' ? mb_substr($body, 0, 80) : (string) $message->attachmentSummary();

            // A new message resurfaces the thread for anyone who had removed it.
            $conversation->update([
                'last_message_at'  => now(),
                'buyer_deleted_at' => null,
                'agent_deleted_at' => null,
            ]);

            // Synchronous broadcast: if Reverb is down the message must still
            // save — the recipient gets it on next load — so never let this throw.
            try {
                broadcast(new MessageSent($message))->toOthers();
            } catch (\Throwable $e) {
                Log::warning('MessageSent broadcast failed', [
                    'message' => $message->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            $recipientId = $sender->id === $conversation->buyer_id
                ? $conversation->agent_id
                : $conversation->buyer_id;

            $recipient = User::find($recipientId);

            if ($recipient) {
                $this->notificationService->send($recipient, 'new_message', [
                    'conversation_id' => $conversation->id,
                    'sender_name'     => $sender->name,
                    'preview'         => $preview,
                ]);
            }

            // Buyer wrote to an agent who isn't around → email the agent (throttled)
            // and let RealtyLink AI hold the conversation until they're back.
            if ($sender->id === $conversation->buyer_id && $recipient && ! $recipient->isOnline()) {
                $this->handleAgentAway($conversation, $recipient, $message);
            }

            return $message->load(['sender', 'attachments']);
        });
    }

    /**
     * The "agent is offline" branch of sendMessage. Runs inside the transaction,
     * so the job and the mail are only queued once the message is committed.
     */
    private function handleAgentAway(Conversation $conversation, User $agent, Message $message): void
    {
        $emailedAt = $conversation->agent_offline_emailed_at;
        if ($emailedAt === null || $emailedAt->lt(now()->subMinutes(30))) {
            $conversation->update(['agent_offline_emailed_at' => now()]);
            $agent->notify(new MessageWhileAway($conversation, $message));
        }

        SendAiAutoReply::dispatch($conversation->id, $message->id)->afterCommit();
    }

    /**
     * The reader had the thread open: everything from the other party is now
     * read (and, implicitly, delivered). The sender is told which messages,
     * so their ticks can turn gold on exactly those.
     */
    public function markAsRead(Conversation $conversation, User $reader): void
    {
        $ids = Message::where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $reader->id)
            ->where('is_read', false)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        $now = now();
        Message::whereIn('id', $ids)->update([
            'is_read'      => true,
            'read_at'      => $now,
            'delivered_at' => DB::raw('COALESCE(delivered_at, NOW())'),
        ]);

        $this->broadcastReceipt($conversation->id, $reader->id, 'read', $ids, $now->toISOString());
    }

    /**
     * The recipient's app received these messages (thread not necessarily
     * open). Only unread, undelivered messages from the other party count.
     */
    public function markAsDelivered(Conversation $conversation, User $recipient): void
    {
        $ids = Message::where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $recipient->id)
            ->whereNull('delivered_at')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return;
        }

        $now = now();
        Message::whereIn('id', $ids)->update(['delivered_at' => $now]);

        $this->broadcastReceipt($conversation->id, $recipient->id, 'delivered', $ids, $now->toISOString());
    }

    /**
     * Everything addressed to this user that is still undelivered — called on
     * the presence heartbeat, so messages that arrived while they were away
     * flip to ✓✓ the moment their app comes back, without opening each thread.
     */
    public function markAllDeliveredFor(User $user): void
    {
        $rows = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where(fn ($q) => $q->where('conversations.buyer_id', $user->id)->orWhere('conversations.agent_id', $user->id))
            ->where('messages.sender_id', '!=', $user->id)
            ->whereNull('messages.delivered_at')
            ->get(['messages.id', 'messages.conversation_id']);

        if ($rows->isEmpty()) {
            return;
        }

        $now = now();
        Message::whereIn('id', $rows->pluck('id'))->update(['delivered_at' => $now]);

        foreach ($rows->groupBy('conversation_id') as $conversationId => $group) {
            $this->broadcastReceipt((int) $conversationId, $user->id, 'delivered', $group->pluck('id')->all(), $now->toISOString());
        }
    }

    private function broadcastReceipt(int $conversationId, int $byUserId, string $kind, array $ids, string $at): void
    {
        try {
            broadcast(new MessagesReceipt($conversationId, $byUserId, $kind, $ids, $at))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('MessagesReceipt broadcast failed', ['conversation' => $conversationId, 'kind' => $kind, 'error' => $e->getMessage()]);
        }
    }

    public function listForUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return Conversation::with(['property', 'buyer', 'agent', 'messages' => function ($q) use ($user): void {
            // The relation already orders oldest-first, so a bare latest() only
            // appended a second ORDER BY and kept returning the OLDEST message.
            // Skip messages this user removed for themselves.
            $q->visibleTo($user)->reorder()->orderByDesc('created_at')->orderByDesc('id')->limit(1)->with('attachments');
        }])
            ->where(function ($q) use ($user): void {
                // Mine, and not removed from my side of the inbox.
                $q->where(fn ($b) => $b->where('buyer_id', $user->id)->whereNull('buyer_deleted_at'))
                  ->orWhere(fn ($a) => $a->where('agent_id', $user->id)->whereNull('agent_deleted_at'));
            })
            ->latest('last_message_at')
            ->paginate($perPage);
    }

    public function getMessages(Conversation $conversation, ?User $viewer = null, int $perPage = 30): LengthAwarePaginator
    {
        return Message::with(['sender', 'attachments'])
            ->where('conversation_id', $conversation->id)
            ->when($viewer, fn ($q) => $q->visibleTo($viewer))
            // id breaks ties: two messages sent in the same second (text, then
            // a file) came back in random order without it.
            ->latest()->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * "Unsend for everyone": allowed for your own messages, and for an agent on
     * the RealtyLink AI replies sent for them while they were away. Both people
     * see "… unsent a message"; the text is kept for admins only.
     */
    public function unsend(Message $message, User $user): Message
    {
        $conversation = $message->conversation;
        $mine   = $message->sender_id === $user->id;
        $aiMine = $message->is_ai && $conversation->agent_id === $user->id;
        abort_unless($mine || $aiMine, 403, 'You can only unsend your own messages.');

        if (! $message->isUnsent()) {
            // An unsent message can't stay pinned.
            $message->forceFill(['unsent_at' => now(), 'pinned_at' => null, 'pinned_by_id' => null])->save();
            // Its photos, videos and files are deleted for both people.
            foreach ($message->attachments as $a) {
                \App\Support\ChatFiles::delete($a->path, $a->thumb_path);
            }
            $message->attachments()->delete();
            $message->setRelation('attachments', collect());
            try {
                broadcast(new MessageUnsent($message))->toOthers();
            } catch (\Throwable $e) {
                Log::warning('MessageUnsent broadcast failed', ['message' => $message->id, 'error' => $e->getMessage()]);
            }
        }

        return $message;
    }

    /**
     * Edit your own message, with no time limit (like Messenger). The first
     * version is kept for admins only; both people see the new text + "Edited".
     */
    public function edit(Message $message, User $user, string $body): Message
    {
        abort_unless($message->sender_id === $user->id && ! $message->is_ai, 403, 'You can only edit your own messages.');
        abort_if($message->isUnsent(), 422, 'An unsent message can’t be edited.');

        $body = trim($body);
        if ($body === $message->body) {
            return $message;
        }

        $message->forceFill([
            'original_body' => $message->original_body ?? $message->body,
            'body'          => $body,
            'edited_at'     => now(),
        ])->save();
        $this->broadcastUpdate($message);

        return $message;
    }

    /** Pin or unpin any message in the chat; both people see the pins. */
    public function setPinned(Message $message, User $user, bool $pinned): Message
    {
        abort_if($pinned && $message->isUnsent(), 422, 'An unsent message can’t be pinned.');

        $message->forceFill($pinned
            ? ['pinned_at' => $message->pinned_at ?? now(), 'pinned_by_id' => $message->pinned_by_id ?? $user->id]
            : ['pinned_at' => null, 'pinned_by_id' => null])->save();
        $this->broadcastUpdate($message);

        return $message;
    }

    /** The chat's pinned messages that this user can see, newest pin first. */
    public function pinned(Conversation $conversation, User $viewer)
    {
        return Message::where('conversation_id', $conversation->id)
            ->whereNotNull('pinned_at')->whereNull('unsent_at')
            ->visibleTo($viewer)
            ->orderByDesc('pinned_at')->get();
    }

    private function broadcastUpdate(Message $message): void
    {
        try {
            broadcast(new MessageUpdated($message))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('MessageUpdated broadcast failed', ['message' => $message->id, 'error' => $e->getMessage()]);
        }
    }

    /** "Remove for you": hide the message from this user's view only. */
    public function hideFor(Message $message, User $user): void
    {
        $hidden = $message->hidden_for ?? [];
        if (! in_array($user->id, $hidden, true)) {
            $hidden[] = $user->id;
            $message->forceFill(['hidden_for' => $hidden])->save();
        }
    }
}
