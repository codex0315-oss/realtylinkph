<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Resources\AdminActionResource;
use App\Http\Resources\AgentProfileResource;
use App\Http\Resources\PropertyResource;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\UserResource;
use App\Models\AdminAction;
use App\Models\AgentProfile;
use App\Models\Appointment;
use App\Models\AgentReview;
use App\Models\Property;
use App\Models\User;
use App\Support\AdminAudit;
use App\Services\FeaturedScoreService;
use App\Services\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $role   = $request->query('role');
        $search = $request->query('search');
        $only   = $request->query('only');   // quick filter: online | unverified | applied

        $query = User::with('agentProfile')
            ->where('is_system', false)   // the RealtyLink AI sender account isn't a user
            ->when($role === 'admin', fn ($q) => $q->whereIn('role_type', ['admin', 'super_admin']))
            ->when($role === 'buyer', fn ($q) => $q->whereIn('role_type', ['buyer', 'ghost_buyer']))
            ->when($role && ! in_array($role, ['admin', 'buyer'], true), fn ($q) => $q->where('role_type', $role))
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%")))
            ->when($only === 'online', fn ($q) => $q->where('last_seen_at', '>=', now()->subMinutes(2)))
            ->when($only === 'unverified', fn ($q) => $q->whereNull('email_verified_at'))
            ->when($only === 'applied', fn ($q) => $q->whereHas('agentProfile', fn ($a) => $a->where('status', 'pending')));

        // The id tiebreak keeps pages stable when several share a value.
        match ($request->query('sort')) {
            'oldest' => $query->oldest()->orderBy('id'),
            'name'   => $query->orderByRaw('lower(name)')->orderBy('id'),
            'active' => $query->orderByRaw('last_seen_at desc nulls last')->orderByDesc('id'),
            default  => $query->latest()->orderByDesc('id'),
        };

        $users = $query->paginate(15);

        return ApiResponse::paginated(UserResource::collection($users), 'Users retrieved.');
    }

    /** Why is this property featured (or not)? Real score breakdown + a grounded AI summary. */
    public function featuredExplain(Property $property, FeaturedScoreService $featured, GeminiService $gemini): JsonResponse
    {
        $breakdown   = $featured->breakdownFor($property);
        $explanation = $gemini->explainFeatured($breakdown, $property->title);

        return ApiResponse::success([
            'breakdown'   => $breakdown,
            'explanation' => $explanation,
        ], 'Featured explanation retrieved.', 200);
    }

    /**
     * The admin Listings page. `status` is a tab: published | unpublished
     * (taken down by an admin: a draft with a reason) | draft (the agent's own,
     * unfinished) | sold. Search matches title, address or the agent's name.
     */
    public function properties(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $query = Property::with(['agent', 'photos'])
            ->when($request->query('status') === 'published', fn ($q) => $q->where('status', 'published'))
            ->when($request->query('status') === 'sold', fn ($q) => $q->where('status', 'sold'))
            ->when($request->query('status') === 'unpublished', fn ($q) => $q->where('status', 'draft')->whereNotNull('unpublish_reason'))
            ->when($request->query('status') === 'draft', fn ($q) => $q->where('status', 'draft')->whereNull('unpublish_reason'))
            ->when(in_array($request->query('type'), ['house', 'condo', 'lot', 'commercial', 'apartment'], true), fn ($q) => $q->ofType($request->query('type')))
            ->when(in_array($request->query('offer_type'), ['sale', 'rent'], true), fn ($q) => $q->where('offer_type', $request->query('offer_type')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'ilike', "%{$search}%")
                ->orWhere('address', 'ilike', "%{$search}%")
                ->orWhereHas('agent', fn ($a) => $a->where('name', 'ilike', "%{$search}%"))));

        // The id tiebreak keeps pages stable when several share a value.
        match ($request->query('sort')) {
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            'price_asc'  => $query->orderBy('price')->orderByDesc('id'),
            'views'      => $query->orderByDesc('views')->orderByDesc('id'),
            'featured'   => $query->orderByDesc('featured_score')->orderByDesc('id'),
            default      => $query->latest()->orderByDesc('id'),
        };

        return ApiResponse::paginated(PropertyResource::collection($query->paginate(15)), 'Properties retrieved.');
    }

    /**
     * Tab counts and the side panel for the admin Listings page: published
     * listings that need a look (no photos, no map pin, a very short
     * description) and the agents with the most live listings.
     */
    public function propertyStats(): JsonResponse
    {
        $published = Property::with(['agent', 'photos'])->where('status', 'published')->latest()->get();

        $flagged = $published->map(function (Property $p) {
            $flags = array_values(array_filter([
                $p->photos->isEmpty() ? 'No photos' : null,
                ! $p->lat || ! $p->lng ? 'No map pin' : null,
                mb_strlen(trim((string) $p->description)) < 80 ? 'Short description' : null,
            ]));

            return $flags ? ['id' => $p->id, 'title' => $p->title, 'agent' => $p->agent?->name, 'flags' => $flags] : null;
        })->filter()->values();

        $topAgents = $published->groupBy('agent_id')
            ->map(fn ($group) => [
                'id'     => $group->first()->agent?->id,
                'name'   => $group->first()->agent?->name,
                'avatar' => \App\Support\Uploads::url($group->first()->agent?->avatar),
                'count'  => $group->count(),
            ])
            ->filter(fn ($a) => $a['id'] !== null)
            ->sortByDesc('count')
            ->take(5)
            ->values();

        return ApiResponse::success([
            'counts' => [
                'all'         => Property::count(),
                'published'   => $published->count(),
                'unpublished' => Property::where('status', 'draft')->whereNotNull('unpublish_reason')->count(),
                'draft'       => Property::where('status', 'draft')->whereNull('unpublish_reason')->count(),
                'sold'        => Property::where('status', 'sold')->count(),
            ],
            'flagged'      => $flagged->take(6),
            'flagged_total' => $flagged->count(),
            'top_agents'   => $topAgents,
        ], 'Listing stats retrieved.');
    }

    public function pendingAgents(): JsonResponse
    {
        $profiles = AgentProfile::with('user')->pending()->latest()->paginate(15);

        return ApiResponse::paginated(AgentProfileResource::collection($profiles), 'Pending agents retrieved.');
    }

    /**
     * The admin Reviews page: visible/hidden, a star filter (5, 4, 3, or
     * `low` = 2★ and below), search by reviewer or agent, and sort.
     */
    public function reviews(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $stars  = $request->query('stars');

        $query = AgentReview::with(['agent', 'buyer', 'appointment.property.photos'])
            ->when($request->query('visible') !== null, fn ($q) => $q->where('is_visible', $request->boolean('visible')))
            ->when(in_array($stars, ['3', '4', '5'], true), fn ($q) => $q->where('rating', (int) $stars))
            ->when($stars === 'low', fn ($q) => $q->where('rating', '<=', 2))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('buyer', fn ($b) => $b->where('name', 'ilike', "%{$search}%"))
                ->orWhereHas('agent', fn ($a) => $a->where('name', 'ilike', "%{$search}%"))));

        match ($request->query('sort')) {
            'oldest' => $query->oldest()->orderBy('id'),
            'lowest' => $query->orderBy('rating')->latest()->orderByDesc('id'),
            default  => $query->latest()->orderByDesc('id'),
        };

        return ApiResponse::paginated(ReviewResource::collection($query->paginate(15)), 'Reviews retrieved.');
    }

    /**
     * Tab counts and the ratings side panel: the platform average over visible
     * reviews, how they spread from 5★ to 1★, and the agents with the lowest
     * average (the ones worth a look).
     */
    public function reviewStats(): JsonResponse
    {
        $visible = AgentReview::where('is_visible', true);
        $spread  = (clone $visible)->selectRaw('rating, count(*) as n')->groupBy('rating')->pluck('n', 'rating');

        $lowest = (clone $visible)
            ->selectRaw('agent_id, avg(rating) as avg_rating, count(*) as n')
            ->groupBy('agent_id')
            ->orderBy('avg_rating')
            ->take(5)
            ->get()
            ->map(function ($row) {
                $agent = User::find($row->agent_id);

                return $agent ? [
                    'id'     => $agent->id,
                    'name'   => $agent->name,
                    'avatar' => \App\Support\Uploads::url($agent->avatar),
                    'rating' => round((float) $row->avg_rating, 1),
                    'count'  => (int) $row->n,
                ] : null;
            })
            ->filter()
            ->values();

        return ApiResponse::success([
            'counts'  => [
                'all'     => AgentReview::count(),
                'visible' => (clone $visible)->count(),
                'hidden'  => AgentReview::where('is_visible', false)->count(),
            ],
            'average' => round((float) (clone $visible)->avg('rating'), 1),
            'spread'  => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($n) => [$n => (int) ($spread[$n] ?? 0)]),
            'lowest_agents' => $lowest,
        ], 'Review stats retrieved.');
    }

    /**
     * The audit trail: what every administrator did, newest first. Filterable
     * by action type and by admin so "who unpublished this?" is one query.
     */
    public function actions(Request $request): JsonResponse
    {
        $area = AdminAction::AREAS[$request->query('area')] ?? null;
        $days = (int) $request->query('days', 0);

        $actions = AdminAction::query()
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->query('action')))
            ->when($area, fn ($q) => $q->where(fn ($w) => collect($area)->each(fn ($prefix) => $w->orWhere('action', 'like', "{$prefix}%"))))
            ->when($days > 0, fn ($q) => $q->where('created_at', '>=', now()->subDays($days)))
            ->when($request->filled('admin_id'), fn ($q) => $q->where('admin_id', (int) $request->query('admin_id')))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%' . $request->query('search') . '%';
                $q->where(fn ($w) => $w->where('subject_label', 'ilike', $term)->orWhere('admin_name', 'ilike', $term));
            })
            ->orderByDesc('id')
            ->paginate(25);

        return ApiResponse::paginated(AdminActionResource::collection($actions), 'Admin activity retrieved.');
    }

    /**
     * The Activity page's side panel and tab counts: everything recorded, the
     * last 7 days by area, and the most active admins over the last 30 days.
     */
    public function actionStats(): JsonResponse
    {
        $week = AdminAction::where('created_at', '>=', now()->subDays(7))->pluck('action');

        $byArea = collect(AdminAction::AREAS)->map(fn ($prefixes) => $week
            ->filter(fn ($a) => collect($prefixes)->contains(fn ($p) => str_starts_with($a, $p)))
            ->count());

        $admins = AdminAction::where('created_at', '>=', now()->subDays(30))
            ->selectRaw('admin_id, admin_name, count(*) as n')
            ->groupBy('admin_id', 'admin_name')
            ->orderByDesc('n')
            ->take(5)
            ->get()
            ->map(fn ($row) => ['id' => $row->admin_id, 'name' => $row->admin_name, 'count' => (int) $row->n]);

        return ApiResponse::success([
            'total'  => AdminAction::count(),
            'week'   => $week->count(),
            'areas'  => $byArea,
            'admins' => $admins,
            'admin_list' => User::whereIn('role_type', ['admin', 'super_admin'])->orderBy('name')->get(['id', 'name']),
        ], 'Activity stats retrieved.');
    }

    /**
     * The admin's home page: what needs action first, then the platform's
     * headline numbers (each with how many are from the last 7 days).
     */
    public function overview(): JsonResponse
    {
        $weekAgo = now()->subDays(7);
        $people  = User::where('is_system', false);

        $oldestPending = AgentProfile::with('user')->pending()->oldest()->take(3)->get();

        return ApiResponse::success([
            'attention' => [
                'applications'   => AgentProfile::pending()->count(),
                'hidden_reviews' => AgentReview::where('is_visible', false)->count(),
                // An admin unpublish sends the listing back to draft with a reason.
                'unpublished'    => Property::where('status', 'draft')->whereNotNull('unpublish_reason')->count(),
                'support'        => \App\Models\SupportTicket::where('status', 'open')->count(),
            ],
            'pending_applications' => $oldestPending->map(fn (AgentProfile $p) => [
                'id'             => $p->id,
                'name'           => $p->user?->name,
                'email'          => $p->user?->email,
                'avatar'         => \App\Support\Uploads::url($p->user?->avatar),
                'applicant_type' => $p->applicant_type,
                'submitted_at'   => $p->created_at?->toISOString(),
            ])->values(),
            'numbers' => [
                'users'    => ['total' => (clone $people)->count(), 'week' => (clone $people)->where('created_at', '>=', $weekAgo)->count()],
                'agents'   => [
                    'total' => AgentProfile::where('status', 'approved')->count(),
                    'week'  => AgentProfile::where('status', 'approved')->where('reviewed_at', '>=', $weekAgo)->count(),
                ],
                'listings' => [
                    'total' => Property::published()->count(),
                    'week'  => Property::published()->where('created_at', '>=', $weekAgo)->count(),
                ],
                'viewings' => [
                    'total' => Appointment::where('status', '!=', 'cancelled')->count(),
                    'week'  => Appointment::where('created_at', '>=', $weekAgo)->count(),
                ],
            ],
            'online' => (clone $people)->where('last_seen_at', '>=', now()->subMinutes(2))->count(),
        ], 'Overview retrieved.');
    }

    /**
     * The Agents page: applications in one status, with counts for the tabs.
     * Pending is oldest first (they've waited longest); decided ones newest
     * decision first, each with the admin who made it (from the audit trail).
     */
    public function applications(Request $request): JsonResponse
    {
        $status = in_array($request->query('status'), ['approved', 'rejected'], true) ? $request->query('status') : 'pending';

        $items = AgentProfile::with('user')
            ->where('status', $status)
            ->whereHas('user')
            ->when($status === 'pending', fn ($q) => $q->oldest(), fn ($q) => $q->orderByDesc('reviewed_at'))
            ->orderBy('id')
            ->take(100)
            ->get();

        if ($status !== 'pending' && $items->isNotEmpty()) {
            $by = AdminAction::where('subject_type', 'AgentProfile')
                ->whereIn('subject_id', $items->pluck('id'))
                ->whereIn('action', ['agent.approved', 'agent.rejected'])
                ->orderBy('id')
                ->get()
                ->keyBy('subject_id');   // the latest decision wins
            $items->each(fn (AgentProfile $p) => $p->setAttribute('reviewed_by', $by[$p->id]->admin_name ?? null));
        }

        $counts = AgentProfile::whereHas('user')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return ApiResponse::success([
            'items'  => AgentProfileResource::collection($items),
            'counts' => [
                'pending'  => (int) ($counts['pending'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
            ],
        ], 'Applications retrieved.');
    }

    /**
     * "Check later": the license couldn't be confirmed yet (typically PRC's
     * lookup is down), so the application stays pending with an admin-only
     * note. DELETE takes the hold off. Any decision clears it too.
     */
    public function holdApplication(Request $request, AgentProfile $profile): JsonResponse
    {
        abort_unless($profile->status === 'pending', 422, 'Only pending applications can be put on hold.');

        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);
        $profile->forceFill(['hold_note' => $data['note'], 'held_by' => $request->user()->name, 'held_at' => now()])->save();

        $profile->loadMissing('user');
        AdminAudit::log('agent.held', $profile, "{$profile->user?->name} ({$profile->user?->email})", ['reason' => $data['note']]);

        return ApiResponse::success(AgentProfileResource::make($profile->load('user')), 'Application put on hold.', 200);
    }

    public function releaseApplication(AgentProfile $profile): JsonResponse
    {
        $profile->forceFill(['hold_note' => null, 'held_by' => null, 'held_at' => null])->save();

        return ApiResponse::success(AgentProfileResource::make($profile->load('user')), 'Hold removed.', 200);
    }

    /** Everything the Users page's details drawer shows about one person. */
    public function showUser(User $user): JsonResponse
    {
        abort_if($user->is_system, 404);

        $received = $user->reviewsReceived()->where('is_visible', true);

        return ApiResponse::success([
            'user'   => UserResource::make($user->load('agentProfile')),
            'counts' => [
                'listings'         => $user->properties()->count(),
                'published'        => $user->properties()->published()->count(),
                'viewings_booked'  => $user->appointmentsAsBuyer()->count(),
                'viewings_hosted'  => $user->appointmentsAsAgent()->count(),
                'reviews_written'  => $user->reviewsGiven()->count(),
                'reviews_received' => (clone $received)->count(),
                'rating'           => round((float) (clone $received)->avg('rating'), 1),
                'saved'            => count($user->favorites ?? []),
            ],
        ], 'User retrieved.');
    }

    /** Headline counts for the admin dashboard. */
    public function stats(): JsonResponse
    {
        $people = User::where('is_system', false);   // exclude the RealtyLink AI sender account

        return ApiResponse::success([
            'total'  => (clone $people)->count(),
            'buyers' => (clone $people)->whereIn('role_type', ['buyer', 'ghost_buyer'])->count(),
            'agents' => (clone $people)->where('role_type', 'agent')->count(),
            'admins' => (clone $people)->whereIn('role_type', ['admin', 'super_admin'])->count(),
            'week'   => (clone $people)->where('created_at', '>=', now()->subDays(7))->count(),
            // The Users page's side panel.
            'recent' => UserResource::collection((clone $people)->with('agentProfile')->latest()->orderByDesc('id')->take(5)->get()),
            'admin_list' => UserResource::collection((clone $people)->whereIn('role_type', ['admin', 'super_admin'])->orderBy('created_at')->get()),
        ], 'Stats retrieved.');
    }

    /**
     * Take a listing down. A reason is mandatory: it is stored on the listing
     * so the agent sees it on My Listings until they re-publish, and pushed to
     * them as a notification so they hear about it immediately.
     */
    public function unpublishProperty(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:10', 'max:500']],
            [
                'reason.required' => 'Please tell the agent why the listing is being unpublished.',
                'reason.min'      => 'Please give a reason the agent can act on (at least 10 characters).',
                'reason.max'      => 'Keep the reason under 500 characters.',
            ],
        );

        $property->update([
            'status'           => 'draft',
            'unpublish_reason' => $data['reason'],
            'unpublished_at'   => now(),
        ]);

        $property->loadMissing('agent');

        AdminAudit::log('listing.unpublished', $property, $property->title, [
            'reason' => $data['reason'],
            'agent'  => $property->agent?->name,
        ]);

        if ($property->agent) {
            app(\App\Services\NotificationService::class)->send($property->agent, 'listing_unpublished', [
                'property_id'    => $property->id,
                'property_title' => $property->title,
                'reason'         => $data['reason'],
                // `message` is what the notification bell renders.
                'message'        => "Your listing \"{$property->title}\" was unpublished by an admin: {$data['reason']}",
            ]);
        }

        return ApiResponse::success(PropertyResource::make($property->fresh(['agent', 'photos'])), 'Listing unpublished and the agent has been notified.', 200);
    }

    /** Delete a listing. Like an unpublish, it needs a reason, and the agent is told. */
    public function deleteProperty(Request $request, Property $property): JsonResponse
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'min:10', 'max:500']],
            [
                'reason.required' => 'Please tell the agent why the listing is being deleted.',
                'reason.min'      => 'Please give a reason the agent can understand (at least 10 characters).',
            ],
        );

        $property->loadMissing('agent');
        $title = $property->title ?: "Untitled listing #{$property->id}";
        AdminAudit::log('listing.deleted', $property, $title, [
            'agent'  => $property->agent?->name,
            'status' => $property->status,
            'reason' => $data['reason'],
        ]);

        $agent = $property->agent;
        app(\App\Services\PropertyService::class)->delete($property);

        if ($agent) {
            app(\App\Services\NotificationService::class)->send($agent, 'listing_deleted', [
                'property_title' => $title,
                'reason'         => $data['reason'],
                'message'        => "Your listing \"{$title}\" was removed by an admin: {$data['reason']}",
            ]);
        }

        return ApiResponse::success(null, 'Listing deleted.', 200);
    }

    /**
     * Create another admin — e.g. to hand over before an admin steps down.
     * The leaving admin can then be removed without losing any system data.
     */
    public function createAdmin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            // Same rule the Create Admin form checks live, and as changing a password.
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()],
        ]);

        $admin = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => $data['password'],   // 'hashed' cast hashes it
            'role_type' => 'admin',
        ]);
        $admin->email_verified_at = now();
        $admin->save();

        AdminAudit::log('admin.created', $admin, "{$admin->name} ({$admin->email})");

        return ApiResponse::success(UserResource::make($admin), 'Admin created.', 201);
    }

    /**
     * Take down an agent's cover photo (covers go live without review). The
     * reason is mandatory and lands in the audit trail, like an unpublish.
     */
    public function removeAgentCover(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        if (! $user->cover_photo) {
            return ApiResponse::error('This agent has no cover photo.', [], 422);
        }

        \App\Support\Uploads::disk()->delete($user->cover_photo);
        $user->update(['cover_photo' => null, 'cover_position' => 50]);

        AdminAudit::log('user.cover_removed', $user, $user->name, ['reason' => $data['reason']]);

        // Tell the agent why, so the banner doesn't just vanish.
        app(\App\Services\NotificationService::class)->send($user, 'cover_removed', [
            'reason'  => $data['reason'],
            'message' => "Your profile cover photo was removed by an admin: {$data['reason']}",
        ]);

        return ApiResponse::success(null, 'Cover photo removed.', 200);
    }

    public function deleteUser(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return ApiResponse::error('You cannot delete your own account.', [], 422);
        }

        // Never allow removing the last remaining admin (avoids locking everyone out).
        if ($user->isAdmin() && User::whereIn('role_type', ['admin', 'super_admin'])->count() <= 1) {
            return ApiResponse::error('You cannot delete the last admin.', [], 422);
        }

        AdminAudit::log('user.deleted', $user, "{$user->name} ({$user->email})", ['role' => $user->role_type]);

        $user->delete();

        return ApiResponse::success(null, 'User deleted.', 200);
    }
}
