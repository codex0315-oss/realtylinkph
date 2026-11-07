<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Appointment;
use App\Models\User;
use App\Models\ViewingSanction;
use App\Services\ViewingSanctionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin → Viewings: who is cancelling late or not turning up, and what to do
 * about it. The numbers come from User (strikes, lock, reliability) so the page
 * and the booking rules can never disagree.
 */
class AdminViewingController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(private readonly ViewingSanctionService $sanctions) {}

    /** Buyers and agents who have ever had a strike or a sanction. */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $role   = $request->query('role');
        $filter = $request->query('filter', 'flagged');   // flagged | paused | all
        $page   = max(1, (int) $request->query('page', 1));

        $ids = Appointment::query()->whereNotNull('cancelled_by_id')->where('late_cancellation', true)->pluck('cancelled_by_id')
            ->merge(Appointment::query()->whereNotNull('no_show_user_id')->pluck('no_show_user_id'))
            ->merge(ViewingSanction::query()->pluck('user_id'))
            ->unique()->values();

        $users = User::query()
            ->whereIn('id', $ids)
            ->whereIn('role_type', ['buyer', 'ghost_buyer', 'agent'])
            ->when($role === 'buyer', fn ($q) => $q->whereIn('role_type', ['buyer', 'ghost_buyer']))
            ->when($role === 'agent', fn ($q) => $q->where('role_type', 'agent'))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%")))
            ->get();

        $rows = $users->map(fn (User $u) => $this->row($u));

        $counts = [
            'flagged' => $rows->filter(fn ($r) => $r['strikes'] > 0 || $r['locked_until'] !== null)->count(),
            'paused'  => $rows->filter(fn ($r) => $r['locked_until'] !== null)->count(),
            'all'     => $rows->count(),
        ];

        $rows = match ($filter) {
            'paused' => $rows->filter(fn ($r) => $r['locked_until'] !== null),
            'all'    => $rows,
            default  => $rows->filter(fn ($r) => $r['strikes'] > 0 || $r['locked_until'] !== null),
        };

        // Paused first, then the most strikes, then the most recent trouble.
        $rows = $rows->sort(function ($a, $b) {
            return [$b['locked_until'] !== null, $b['strikes'], $b['last_incident_at']]
                <=> [$a['locked_until'] !== null, $a['strikes'], $a['last_incident_at']];
        })->values();

        $total = $rows->count();

        return response()->json([
            'success' => true,
            'message' => 'Viewing behaviour retrieved.',
            'data'    => $rows->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values(),
            'meta'    => [
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / self::PER_PAGE)),
                'per_page'     => self::PER_PAGE,
                'total'        => $total,
            ],
            'counts'  => $counts,
        ]);
    }

    /** One person's full record: every strike, and everything an admin has done. */
    public function show(User $user): JsonResponse
    {
        $this->ensureViewingUser($user);

        $incidents = Appointment::query()
            ->with(['property:id,title', 'buyer:id,name', 'agent:id,name', 'noShowReporter:id,name', 'strikeWaivedBy:id,name'])
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('cancelled_by_id', $user->id)->where('late_cancellation', true))
                ->orWhere('no_show_user_id', $user->id))
            ->get()
            ->map(fn (Appointment $a) => $this->incident($a, $user))
            ->sortByDesc('occurred_at')
            ->values();

        $sanctions = ViewingSanction::query()
            ->with(['admin:id,name', 'appointment.property:id,title'])
            ->where('user_id', $user->id)
            ->latest()->limit(40)->get()
            ->map(fn (ViewingSanction $s) => [
                'id'          => $s->id,
                'type'        => $s->type,
                'reason'      => $s->reason,
                'days'        => $s->days,
                'until'       => $s->until?->toISOString(),
                'lifted_at'   => $s->lifted_at?->toISOString(),
                'admin'       => $s->admin?->name,
                'property'    => $s->appointment?->property?->title,
                'created_at'  => $s->created_at?->toISOString(),
            ]);

        return ApiResponse::success([
            'user'      => $this->row($user),
            'incidents' => $incidents,
            'sanctions' => $sanctions,
            'pause_days' => ViewingSanction::PAUSE_DAYS,
        ], 'Viewing record retrieved.');
    }

    public function warn(Request $request, User $user): JsonResponse
    {
        $this->ensureViewingUser($user);
        $data = $request->validate(['message' => ['required', 'string', 'min:10', 'max:500']]);

        $this->sanctions->warn($user, $request->user(), trim($data['message']));

        return ApiResponse::success($this->row($user->fresh()), 'Warning sent.');
    }

    public function pause(Request $request, User $user): JsonResponse
    {
        $this->ensureViewingUser($user);
        $data = $request->validate([
            'days'   => ['required', 'integer', Rule::in(ViewingSanction::PAUSE_DAYS)],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $this->sanctions->pause($user, $request->user(), (int) $data['days'], trim($data['reason']));

        return ApiResponse::success($this->row($user->fresh()), "Paused for {$data['days']} days. They have been told.");
    }

    public function lift(Request $request, User $user): JsonResponse
    {
        $this->ensureViewingUser($user);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        if (! $user->isBookingLocked()) {
            return ApiResponse::error('This user is not paused.', [], 422);
        }

        $this->sanctions->lift($user, $request->user(), $data['reason'] ?? null);

        return ApiResponse::success($this->row($user->fresh()), 'Pause lifted. They have been told.');
    }

    public function waive(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        if (! $appointment->hasLiveStrike()) {
            return ApiResponse::error('There is no strike on this viewing to waive.', [], 422);
        }

        $this->sanctions->waive($appointment, $request->user(), trim($data['reason']));

        $user = User::findOrFail($appointment->strikeUserId());

        return ApiResponse::success($this->row($user), 'Strike waived. They have been told.');
    }

    // ─── Shapes ──────────────────────────────────────────────────────────────

    private function ensureViewingUser(User $user): void
    {
        abort_unless(in_array($user->role_type, ['buyer', 'ghost_buyer', 'agent'], true), 422, 'Only buyers and agents have a viewing record.');
    }

    /** @return array<string, mixed> */
    private function row(User $u): array
    {
        $locked   = $u->bookingLockedUntil();
        $manual   = $u->manualPauseUntil();
        $reason   = $locked === null ? null : ($manual !== null && ($u->automaticLockUntil() === null || $manual->gte($u->automaticLockUntil())) ? 'admin' : 'strikes');

        $lateAt   = Appointment::where('cancelled_by_id', $u->id)->where('late_cancellation', true)->max('cancelled_at');
        $noShowAt = Appointment::where('no_show_user_id', $u->id)->max('no_show_reported_at');
        $last     = collect([$lateAt, $noShowAt])->filter()->max();

        return [
            'id'               => $u->id,
            'name'             => $u->name,
            'email'            => $u->email,
            'avatar'           => $u->avatar,
            'role_type'        => $u->role_type === 'agent' ? 'agent' : 'buyer',
            'strikes'          => $u->recentStrikes(),
            'strike_limit'     => User::STRIKE_LIMIT,
            'window_days'      => User::STRIKE_WINDOW_DAYS,
            'locked_until'     => $locked?->toISOString(),
            'locked_by'        => $reason,
            'reliability'      => $u->viewingReliability(),
            'last_incident_at' => $last ? \Illuminate\Support\Carbon::parse($last)->toISOString() : null,
            'warnings'         => ViewingSanction::where('user_id', $u->id)->where('type', ViewingSanction::WARNING)->count(),
        ];
    }

    private function cancelReason(Appointment $a, bool $isAgent): ?string
    {
        $text = trim((Appointment::reasonLabel($a->cancel_reason_code, $isAgent) ?? '') . ($a->cancel_reason_note ? ': ' . $a->cancel_reason_note : ''));

        return $text !== '' ? $text : null;
    }

    /** @return array<string, mixed> */
    private function incident(Appointment $a, User $user): array
    {
        $isNoShow = $a->no_show_user_id === $user->id;
        $isAgent  = $user->role_type === 'agent';

        return [
            'appointment_id' => $a->id,
            'kind'           => $isNoShow ? 'no_show' : 'late_cancellation',
            'weight'         => $isNoShow ? User::NO_SHOW_WEIGHT : 1,
            'property'       => $a->property?->title,
            'slot'           => $a->preferred_datetime?->toISOString(),
            'occurred_at'    => ($isNoShow ? $a->no_show_reported_at : $a->cancelled_at)?->toISOString(),
            'reason'         => $isNoShow ? ($a->no_show_note ?: null) : $this->cancelReason($a, $isAgent),
            'reported_by'    => $isNoShow ? $a->noShowReporter?->name : null,
            'other_party'    => $isAgent ? $a->buyer?->name : $a->agent?->name,
            'waived'         => $a->strike_waived_at !== null,
            'waived_at'      => $a->strike_waived_at?->toISOString(),
            'waived_by'      => $a->strikeWaivedBy?->name,
            'counts_now'     => $a->strike_waived_at === null
                && ($isNoShow ? $a->no_show_reported_at : $a->cancelled_at)?->gte(now()->subDays(User::STRIKE_WINDOW_DAYS)),
        ];
    }
}
