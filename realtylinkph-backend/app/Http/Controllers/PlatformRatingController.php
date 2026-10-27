<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\PlatformRating;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rate RealtyLink PH (buyers and agents) and, for admins, the results. */
class PlatformRatingController extends Controller
{
    /** The signed-in person's own rating, or null. */
    public function mine(Request $request): JsonResponse
    {
        $r = PlatformRating::where('user_id', $request->user()->id)->first();

        return ApiResponse::success($r ? $this->row($r) : null, 'Rating retrieved.');
    }

    /** Give or change the rating. */
    public function save(Request $request): JsonResponse
    {
        abort_if($request->user()->isAdmin(), 403, 'Admins don’t rate the platform.');

        $data = $request->validate([
            'rating'  => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $r = PlatformRating::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['rating' => $data['rating'], 'comment' => isset($data['comment']) && trim($data['comment']) !== '' ? trim($data['comment']) : null],
        );

        return ApiResponse::success($this->row($r), 'Thanks for rating RealtyLink PH!', 200);
    }

    /** Admin: the average, how it spreads, buyers vs agents, and the latest comments. */
    public function summary(): JsonResponse
    {
        $all = PlatformRating::with('user')->latest('updated_at')->get();
        $avg = fn ($rows) => $rows->count() ? round($rows->avg('rating'), 1) : null;

        return ApiResponse::success([
            'count'   => $all->count(),
            'average' => $avg($all),
            'spread'  => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($n) => [$n => $all->where('rating', $n)->count()]),
            'buyers'  => ['average' => $avg($all->filter(fn ($r) => $r->user?->role_type !== 'agent')), 'count' => $all->filter(fn ($r) => $r->user?->role_type !== 'agent')->count()],
            'agents'  => ['average' => $avg($all->filter(fn ($r) => $r->user?->role_type === 'agent')), 'count' => $all->filter(fn ($r) => $r->user?->role_type === 'agent')->count()],
            'latest'  => $all->whereNotNull('comment')->take(5)->map(fn ($r) => [
                'rating'  => $r->rating,
                'comment' => $r->comment,
                'name'    => $r->user?->name,
                'avatar'  => Uploads::url($r->user?->avatar),
                'role'    => $r->user?->role_type,
                'date'    => $r->updated_at?->toISOString(),
            ])->values(),
        ], 'Platform ratings retrieved.');
    }

    private function row(PlatformRating $r): array
    {
        return ['rating' => $r->rating, 'comment' => $r->comment, 'updated_at' => $r->updated_at?->toISOString()];
    }
}
