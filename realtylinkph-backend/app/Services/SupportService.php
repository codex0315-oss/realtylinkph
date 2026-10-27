<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Documents;
use Illuminate\Http\UploadedFile;

/**
 * Help & support.
 *
 * A user's messages go to their active ticket (one at a time; a new one starts
 * after the last was resolved). While a ticket is with the AI, RealtyLink AI
 * answers; once it hands over — or the user asks for a person — the ticket is
 * "open" for the admins, who reply until it's resolved. Users and admins are
 * notified at each hand-off.
 */
class SupportService
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly NotificationService $notifications,
    ) {}

    public function activeTicket(User $user): ?SupportTicket
    {
        return SupportTicket::where('user_id', $user->id)->where('status', '!=', 'resolved')->latest('id')->first();
    }

    /**
     * A message from the user. Returns the ticket, with the AI's answer (or
     * the hand-off) already added when the AI is still handling it.
     *
     * @param  array<int, UploadedFile>  $images
     */
    public function postFromUser(User $user, ?string $body, array $images = []): SupportTicket
    {
        $ticket = $this->activeTicket($user)
            ?? SupportTicket::create(['user_id' => $user->id, 'status' => 'ai']);

        $this->addMessage($ticket, 'user', $user->id, $body, $images);

        if ($ticket->isEscalated()) {
            // With the team: let them know there's something new.
            $this->notifyAdmins($ticket, 'support_message', "{$user->name} added to a support ticket");
        }

        // RealtyLink AI answers until an admin has replied (status ai or open).
        // After that the conversation is the team's, except while no admin is
        // online: then the AI stands in with the basics so nobody waits in silence.
        $standIn = $ticket->status === 'in_progress' && ! self::teamOnline();
        if (in_array($ticket->status, ['ai', 'open'], true) || $standIn) {
            $answer = $this->gemini->supportReply(
                $standIn ? $this->standInHistory($ticket) : $this->aiHistory($ticket),
                $user->role_type === 'agent' ? 'real estate agent' : 'home buyer',
                $standIn,
            );

            if ($answer['reply'] === null) {
                // Every AI provider failed: say so rather than silently handing over.
                $this->addMessage($ticket, 'ai', null, $ticket->isEscalated()
                    ? "I can't answer right now, but our team has your message and will reply here."
                    : "Sorry, I can't answer right now. Try again in a moment, or tap \"Talk to a person\" and our team will help.");
            } else {
                $this->addMessage($ticket, 'ai', null, $answer['reply']);
                if ($answer['needs_human'] && $ticket->status === 'ai') {
                    $this->escalate($ticket, $answer['category']);
                }
            }
        }

        return $ticket->fresh();
    }

    /** Hand the ticket to the admins, with an AI summary and a draft reply for them. */
    public function escalate(SupportTicket $ticket, ?string $category = null): SupportTicket
    {
        if ($ticket->isEscalated() || ! $ticket->isActive()) {
            return $ticket;
        }

        $ticket->loadMissing('user');
        $brief = $this->gemini->supportBrief($this->briefHistory($ticket), $ticket->user?->name ?? 'the user');

        $ticket->update([
            'status'          => 'open',
            'escalated_at'    => now(),
            'category'        => $brief['category'] ?? $category ?? 'other',
            'summary'         => $brief['summary'],
            'suggested_reply' => $brief['suggested_reply'],
        ]);
        $this->addMessage($ticket, 'system', null, 'Passed to the RealtyLink PH team. They usually reply within a day, right here.');
        $this->notifyAdmins($ticket, 'support_ticket', "New support ticket from {$ticket->user?->name}: " . ($ticket->summary ?? 'needs a look'));

        return $ticket->fresh();
    }

    /** @param array<int, UploadedFile> $images */
    public function replyFromAdmin(SupportTicket $ticket, User $admin, ?string $body, array $images = []): SupportTicket
    {
        $this->addMessage($ticket, 'admin', $admin->id, $body, $images);
        $ticket->update([
            'status'          => 'in_progress',
            'escalated_at'    => $ticket->escalated_at ?? now(),
            'resolved_at'     => null,
            'resolved_by'     => null,
            'suggested_reply' => null,   // used or replaced; regenerate on demand
        ]);
        $this->notifications->send($ticket->user, 'support_reply', [
            'ticket_id' => $ticket->id,
            'message'   => 'The RealtyLink PH team replied to your support request.',
        ]);

        return $ticket->fresh();
    }

    public function resolve(SupportTicket $ticket, User $admin): SupportTicket
    {
        $ticket->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $admin->name, 'rating' => null]);
        $this->addMessage($ticket, 'system', null, "Marked as resolved by {$admin->name}. Was this helpful? Rate it below. Anything else, just send a new message.");
        $this->notifications->send($ticket->user, 'support_resolved', [
            'ticket_id' => $ticket->id,
            'message'   => 'Your support request was resolved. Let us know if it helped.',
        ]);

        return $ticket->fresh();
    }

    public function reopen(SupportTicket $ticket): SupportTicket
    {
        $ticket->update(['status' => 'in_progress', 'resolved_at' => null, 'resolved_by' => null]);
        $this->addMessage($ticket, 'system', null, 'Reopened by the RealtyLink PH team.');

        return $ticket->fresh();
    }

    /** Refresh the admin's draft reply from the conversation so far. */
    public function suggest(SupportTicket $ticket): SupportTicket
    {
        $ticket->loadMissing('user');
        $brief = $this->gemini->supportBrief($this->briefHistory($ticket), $ticket->user?->name ?? 'the user');
        $ticket->update([
            'summary'         => $brief['summary'] ?? $ticket->summary,
            'suggested_reply' => $brief['suggested_reply'],
        ]);

        return $ticket->fresh();
    }

    /** Messages from the other side become read. */
    public function markRead(SupportTicket $ticket, string $reader): void
    {
        $from = $reader === 'admin' ? ['user'] : ['ai', 'admin', 'system'];
        $ticket->messages()->whereIn('sender_type', $from)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** @param array<int, UploadedFile> $images */
    private function addMessage(SupportTicket $ticket, string $type, ?int $senderId, ?string $body, array $images = []): SupportMessage
    {
        $paths = array_map(fn (UploadedFile $f) => Documents::put("support/{$ticket->id}", $f), $images);

        $message = $ticket->messages()->create([
            'sender_type' => $type,
            'sender_id'   => $senderId,
            'body'        => $body !== null && trim($body) !== '' ? trim($body) : null,
            'attachments' => $paths ?: null,
        ]);
        $ticket->forceFill(['last_message_at' => now()])->save();

        return $message;
    }

    /** The user ↔ AI turns, for the AI to continue. Screenshots are mentioned, not sent. */
    private function aiHistory(SupportTicket $ticket): array
    {
        return $ticket->messages()->whereIn('sender_type', ['user', 'ai'])->get()->slice(-20)
            ->map(fn (SupportMessage $m) => [
                'role'    => $m->sender_type === 'user' ? 'user' : 'assistant',
                'content' => trim(($m->body ?? '') . ($m->attachments ? ' [attached ' . count($m->attachments) . ' screenshot(s)]' : '')) ?: '[screenshot]',
            ])->values()->all();
    }

    /** Is any admin online right now (active in the last 2 minutes)? */
    public static function teamOnline(): bool
    {
        return User::whereIn('role_type', ['admin', 'super_admin'])
            ->where('is_system', false)
            ->where('last_seen_at', '>=', now()->subMinutes(2))
            ->exists();
    }

    /**
     * For the AI standing in while the team is away: the whole thread, with
     * the team's replies marked so it builds on them rather than contradicting.
     */
    private function standInHistory(SupportTicket $ticket): array
    {
        return $ticket->messages()->where('sender_type', '!=', 'system')->get()->slice(-20)
            ->map(fn (SupportMessage $m) => [
                'role'    => $m->sender_type === 'user' ? 'user' : 'assistant',
                'content' => ($m->sender_type === 'admin' ? '[RealtyLink PH team] ' : '')
                    . (trim(($m->body ?? '') . ($m->attachments ? ' [attached ' . count($m->attachments) . ' screenshot(s)]' : '')) ?: '[screenshot]'),
            ])->values()->all();
    }

    /** The whole thread, labelled, for the admin brief. */
    private function briefHistory(SupportTicket $ticket): array
    {
        return $ticket->messages()->get()->slice(-30)
            ->map(fn (SupportMessage $m) => [
                'role'    => $m->sender_type === 'user' ? 'user' : 'assistant',
                'content' => ($m->sender_type === 'admin' ? '[Admin] ' : ($m->sender_type === 'system' ? '[System] ' : ''))
                    . (trim(($m->body ?? '') . ($m->attachments ? ' [attached ' . count($m->attachments) . ' screenshot(s)]' : '')) ?: '[screenshot]'),
            ])->values()->all();
    }

    private function notifyAdmins(SupportTicket $ticket, string $type, string $message): void
    {
        User::whereIn('role_type', ['admin', 'super_admin'])->where('is_system', false)->get()
            ->each(fn (User $admin) => $this->notifications->send($admin, $type, ['ticket_id' => $ticket->id, 'message' => $message]));
    }
}
