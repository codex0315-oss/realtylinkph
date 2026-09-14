<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Resources\AgentResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    /**
     * Public directory of approved agents (used by the homepage "top agents"
     * section and the /agents listing page).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 12), 1), 50);

        $agents = User::query()
            ->where('role_type', 'agent')
            ->whereHas('agentProfile', fn (Builder $q) => $q->where('status', 'approved'))
            ->with('agentProfile')
            ->withCount(['properties as listing_count' => fn (Builder $q) => $q->where('status', 'published')])
            ->withCount('reviewsReceived as review_count')
            ->withAvg('reviewsReceived as average_rating', 'rating')
            ->orderByDesc('listing_count')
            ->paginate($perPage);

        return ApiResponse::paginated(AgentResource::collection($agents), 'Agents retrieved.');
    }

    /**
     * A single approved agent's public profile.
     */
    public function show(User $agent): JsonResponse
    {
        $agent->load('agentProfile');

        if ($agent->role_type !== 'agent' || $agent->agentProfile?->status !== 'approved') {
            return ApiResponse::error('Agent not found.', [], 404);
        }

        $agent->loadCount(['properties as listing_count' => fn (Builder $q) => $q->where('status', 'published')])
            ->loadCount('reviewsReceived as review_count')
            ->loadAvg('reviewsReceived as average_rating', 'rating');

        return ApiResponse::success(AgentResource::make($agent), 'Agent retrieved.');
    }
}
