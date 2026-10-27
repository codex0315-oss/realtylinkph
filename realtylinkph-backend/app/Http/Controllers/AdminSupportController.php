<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Resources\SupportTicketResource;
use App\Models\SupportQuickReply;
use App\Models\SupportTicket;
use App\Services\SupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The admin Support inbox: tickets the AI handed over, replies, resolve. */
class AdminSupportController extends Controller
{
    public function __construct(private readonly SupportService $support) {}

    /**
     * Tickets by status: open (waiting for the team) | in_progress | resolved.
     * Conversations still with the AI aren't tickets yet and stay out.
     */
    public function index(Request $request): JsonResponse
    {
        $status = in_array($request->query('status'), ['in_progress', 'resolved'], true) ? $request->query('status') : 'open';
        $search = trim((string) $request->query('search', ''));

        $tickets = SupportTicket::with(['user', 'lastMessage'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('sender_type', 'user')->whereNull('read_at')])
            ->where('status', $status)
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('summary', 'ilike', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%"))))
            // Waiting longest first while open; most recent first otherwise.
            ->when($status === 'open', fn ($q) => $q->orderBy('escalated_at'), fn ($q) => $q->orderByDesc('last_message_at'))
            ->orderBy('id')
            ->paginate(20);

        return ApiResponse::paginated(SupportTicketResource::collection($tickets), 'Tickets retrieved.');
    }

    public function stats(): JsonResponse
    {
        $counts = SupportTicket::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $rated  = SupportTicket::whereNotNull('rating')->selectRaw('rating, count(*) as n')->groupBy('rating')->pluck('n', 'rating');
        $byCategory = SupportTicket::whereIn('status', ['open', 'in_progress'])
            ->selectRaw('category, count(*) as n')->groupBy('category')->pluck('n', 'category');

        return ApiResponse::success([
            'counts' => [
                'open'        => (int) ($counts['open'] ?? 0),
                'in_progress' => (int) ($counts['in_progress'] ?? 0),
                'resolved'    => (int) ($counts['resolved'] ?? 0),
                'ai'          => (int) ($counts['ai'] ?? 0),   // answered by the AI alone
            ],
            'ratings'     => ['up' => (int) ($rated['up'] ?? 0), 'down' => (int) ($rated['down'] ?? 0)],
            'by_category' => $byCategory,
        ], 'Support stats retrieved.');
    }

    public function show(SupportTicket $ticket): JsonResponse
    {
        abort_if($ticket->status === 'ai', 404);
        $this->support->markRead($ticket, 'admin');

        return ApiResponse::success(SupportTicketResource::make($ticket->load(['user', 'messages.sender'])), 'Ticket retrieved.');
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        abort_if($ticket->status === 'ai', 404);
        $data = $request->validate([
            'body'     => ['nullable', 'string', 'max:2000', 'required_without:images'],
            'images'   => ['nullable', 'array', 'max:3'],
            'images.*' => ['image', 'max:5120'],
        ]);

        $ticket = $this->support->replyFromAdmin($ticket, $request->user(), $data['body'] ?? null, $request->file('images', []));

        return ApiResponse::success(SupportTicketResource::make($ticket->load(['user', 'messages.sender'])), 'Reply sent.', 201);
    }

    public function resolve(Request $request, SupportTicket $ticket): JsonResponse
    {
        abort_if($ticket->status === 'ai', 404);
        $ticket = $this->support->resolve($ticket, $request->user());

        return ApiResponse::success(SupportTicketResource::make($ticket->load(['user', 'messages.sender'])), 'Resolved.', 200);
    }

    public function reopen(SupportTicket $ticket): JsonResponse
    {
        abort_unless($ticket->status === 'resolved', 422, 'Only resolved tickets can be reopened.');
        $ticket = $this->support->reopen($ticket);

        return ApiResponse::success(SupportTicketResource::make($ticket->load(['user', 'messages.sender'])), 'Reopened.', 200);
    }

    public function suggest(SupportTicket $ticket): JsonResponse
    {
        abort_if($ticket->status === 'ai', 404);

        return ApiResponse::success(SupportTicketResource::make($this->support->suggest($ticket)), 'Suggestion ready.', 200);
    }

    // ── Quick replies ──

    public function quickReplies(): JsonResponse
    {
        return ApiResponse::success(SupportQuickReply::orderBy('title')->get(['id', 'title', 'body']), 'Quick replies retrieved.');
    }

    public function storeQuickReply(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:80'], 'body' => ['required', 'string', 'max:2000']]);

        return ApiResponse::success(SupportQuickReply::create($data), 'Saved.', 201);
    }

    public function destroyQuickReply(SupportQuickReply $quickReply): JsonResponse
    {
        $quickReply->delete();

        return ApiResponse::success(null, 'Deleted.', 200);
    }
}
