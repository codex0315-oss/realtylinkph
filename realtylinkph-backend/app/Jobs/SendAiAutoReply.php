<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\AiTyping;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\GroqService;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RealtyLink AI answers a buyer in chat while the listing's agent is offline.
 *
 * Queued because Gemini takes a few seconds — the buyer's own send must return
 * instantly. Presence is re-checked when the job runs: if the agent came back
 * in the meantime, or already replied, the AI stays quiet.
 */
class SendAiAutoReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $triggerMessageId,
    ) {}

    public function handle(GroqService $groq, GeminiService $gemini, NotificationService $notifications): void
    {
        $conversation = Conversation::with(['property', 'agent', 'buyer'])->find($this->conversationId);
        if (! $conversation || ! $conversation->property || ! $conversation->agent || ! $conversation->buyer) {
            return;
        }

        // Agent is back, or answered themselves since the buyer wrote — stand down.
        if ($conversation->agent->isOnline()) {
            return;
        }
        $latest = Message::where('conversation_id', $conversation->id)->latest('id')->first();
        if (! $latest || $latest->id !== $this->triggerMessageId) {
            return;
        }

        broadcast(new AiTyping($conversation->id));

        // Last 12 messages, oldest first, as a chat history. AI turns are "assistant".
        $history = Message::where('conversation_id', $conversation->id)
            ->latest('id')->limit(12)->get()->reverse()->values()
            ->map(fn (Message $m) => [
                'role'    => $m->is_ai ? 'assistant' : ($m->sender_id === $conversation->buyer_id ? 'user' : 'assistant'),
                'content' => $m->body,
            ])->all();

        // Groq first (fast Llama), Gemini if Groq is unconfigured or fails —
        // the buyer should get an answer as long as either provider is up.
        $args  = [$conversation->property, $conversation->agent, $conversation->buyer->name, $history];
        $reply = $groq->autoReplyWhileAway(...$args) ?? $gemini->autoReplyWhileAway(...$args);

        if ($reply === null || $reply === '') {
            Log::warning('AI auto-reply produced no text from any provider', ['conversation' => $conversation->id]);
            return;
        }

        $ai = User::realtyAi();

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id'       => $ai->id,
            'body'            => $reply,
            'is_ai'           => true,
            'is_read'         => false,
        ]);

        // Keep the thread ordered; don't clear delete flags — this isn't a human reply.
        $conversation->update(['last_message_at' => now()]);

        // No socket to exclude from a queued job, so everyone on the channel
        // (the buyer, and the agent if they're watching) receives it.
        try {
            broadcast(new MessageSent($message));
        } catch (\Throwable $e) {
            Log::warning('AI auto-reply broadcast failed', ['message' => $message->id, 'error' => $e->getMessage()]);
        }

        // Bell for the buyer, in case they've navigated away from the thread.
        $notifications->send($conversation->buyer, 'new_message', [
            'conversation_id' => $conversation->id,
            'sender_name'     => 'RealtyLink AI',
            'preview'         => mb_substr($reply, 0, 80),
            'message'         => 'RealtyLink AI replied while ' . $conversation->agent->name . ' is away: ' . mb_substr($reply, 0, 80),
        ]);
    }
}
