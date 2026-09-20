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

        $users = User::with('agentProfile')
            ->where('is_system', false)   // the RealtyLink AI sender account isn't a user
            ->when($role === 'admin', fn ($q) => $q->whereIn('role_type', ['admin', 'super_admin']))
            ->when($role === 'buyer', fn ($q) => $q->whereIn('role_type', ['buyer', 'ghost_buyer']))
            ->when($role && ! in_array($role, ['admin', 'buyer'], true), fn ($q) => $q->where('role_type', $role))
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%")))
            ->latest()
            ->paginate(15);

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

    public function properties(Request $request): JsonResponse
    {
        $properties = Property::with(['agent', 'photos'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(15);

        return ApiResponse::paginated(PropertyResource::collection($properties), 'Properties retrieved.');
    }

    public function pendingAgents(): JsonResponse
    {
        $profiles = AgentProfile::with('user')->pending()->latest()->paginate(15);

        return ApiResponse::paginated(AgentProfileResource::collection($profiles), 'Pending agents retrieved.');
    }

    public function reviews(Request $request): JsonResponse
    {
        $reviews = AgentReview::with(['agent', 'buyer', 'appointment.property'])
            ->when($request->query('visible') !== null, fn ($q) => $q->where('is_visible', $request->boolean('visible')))
            ->latest()
            ->paginate(15);

        return ApiResponse::paginated(ReviewResource::collection($reviews), 'Reviews retrieved.');
    }

    /**
     * The audit trail: what every administrator did, newest first. Filterable
     * by action type and by admin so "who unpublished this?" is one query.
     */
    public function actions(Request $request): JsonResponse
    {
        $actions = AdminAction::query()
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->query('action')))
            ->when($request->filled('admin_id'), fn ($q) => $q->where('admin_id', (int) $request->query('admin_id')))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%' . $request->query('search') . '%';
                $q->where(fn ($w) => $w->where('subject_label', 'ilike', $term)->orWhere('admin_name', 'ilike', $term));
            })
            ->orderByDesc('id')
            ->paginate(25);

        return ApiResponse::paginated(AdminActionResource::collection($actions), 'Admin activity retrieved.');
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

    public function deleteProperty(Property $property): JsonResponse
    {
        $property->loadMissing('agent');
        AdminAudit::log('listing.deleted', $property, $property->title ?: "Untitled listing #{$property->id}", [
            'agent'  => $property->agent?->name,
            'status' => $property->status,
        ]);

        app(\App\Services\PropertyService::class)->delete($property);

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
            'password' => ['required', 'string', 'min:8'],
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
