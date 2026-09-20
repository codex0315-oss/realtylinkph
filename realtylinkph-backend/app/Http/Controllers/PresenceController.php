<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PresenceController extends Controller
{
    /** Frontend pings this periodically to keep the user marked "online". */
    public function heartbeat(Request $request): JsonResponse
    {
        // Raw update — no model events / no updated_at bump on a frequent ping.
        DB::table('users')->where('id', $request->user()->id)->update(['last_seen_at' => now()]);

        // Being online means anything sent to you has now reached your app.
        app(\App\Services\ConversationService::class)->markAllDeliveredFor($request->user());

        return ApiResponse::success(null, 'ok', 200);
    }

    /** Presence of another user (used by the chat to show online / last seen). */
    public function show(User $user): JsonResponse
    {
        return ApiResponse::success([
            'last_seen_at' => $user->last_seen_at?->toISOString(),
            'is_online'    => $user->isOnline(),
        ], 'Presence retrieved.', 200);
    }
}
