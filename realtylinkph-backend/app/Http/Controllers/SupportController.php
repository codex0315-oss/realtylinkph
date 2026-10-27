<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Resources\SupportTicketResource;
use App\Models\SupportTicket;
use App\Services\SupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Help & support, for buyers and agents: chat with RealtyLink AI, then the team. */
class SupportController extends Controller
{
    public function __construct(private readonly SupportService $support) {}

    /** The active ticket (with its messages) and past ones, for the Help & support page. */
    public function index(Request $request): JsonResponse
    {
        $user   = $request->user();
        $active = $this->support->activeTicket($user)?->load('messages.sender');
        if ($active) {
            $this->support->markRead($active, 'user');
        }

        $past = SupportTicket::where('user_id', $user->id)->where('status', 'resolved')
            ->with('lastMessage')->latest('id')->take(20)->get();

        return ApiResponse::success([
            'active' => $active ? SupportTicketResource::make($active) : null,
            'past'   => SupportTicketResource::collection($past),
            'unread' => $this->unreadFor($user->id),
        ], 'Support retrieved.');
    }

    /** One of the user's own tickets, read-only history included. */
    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        abort_unless($ticket->user_id === $request->user()->id, 404);
        $this->support->markRead($ticket, 'user');

        return ApiResponse::success(SupportTicketResource::make($ticket->load('messages.sender')), 'Ticket retrieved.');
    }

    /** Unread replies, for the badge on the support entry points. */
    public function unread(Request $request): JsonResponse
    {
        return ApiResponse::success(['unread' => $this->unreadFor($request->user()->id)], 'Unread retrieved.');
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body'     => ['nullable', 'string', 'max:2000', 'required_without:images'],
            'images'   => ['nullable', 'array', 'max:3'],
            'images.*' => ['image', 'max:5120'],
        ]);

        $ticket = $this->support->postFromUser($request->user(), $data['body'] ?? null, $request->file('images', []));
        $this->support->markRead($ticket, 'user');

        return ApiResponse::success(SupportTicketResource::make($ticket->load('messages.sender')), 'Sent.', 201);
    }

    /** "Talk to a person". */
    public function escalate(Request $request): JsonResponse
    {
        $ticket = $this->support->activeTicket($request->user());
        abort_unless($ticket, 422, 'Send a message first so the team knows what you need.');

        $ticket = $this->support->escalate($ticket);

        return ApiResponse::success(SupportTicketResource::make($ticket->load('messages.sender')), 'Passed to the team.', 200);
    }

    public function rate(Request $request, SupportTicket $ticket): JsonResponse
    {
        abort_unless($ticket->user_id === $request->user()->id, 404);
        abort_unless($ticket->status === 'resolved', 422, 'You can rate a request once it is resolved.');
        $data = $request->validate(['rating' => ['required', 'in:up,down']]);

        $ticket->update(['rating' => $data['rating']]);

        return ApiResponse::success(SupportTicketResource::make($ticket), 'Thanks for the feedback.', 200);
    }

    private function unreadFor(int $userId): int
    {
        return \App\Models\SupportMessage::whereHas('ticket', fn ($q) => $q->where('user_id', $userId))
            ->whereIn('sender_type', ['admin', 'system'])
            ->whereNull('read_at')
            ->count();
    }
}
