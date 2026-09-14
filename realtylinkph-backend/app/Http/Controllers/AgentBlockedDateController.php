<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\BlockedDate\StoreBlockedDateRequest;
use App\Http\Requests\BlockedDate\UpdateBlockedDateRequest;
use App\Models\AgentBlockedDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentBlockedDateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $blockedDates = AgentBlockedDate::forAgent($request->user()->id)->get();

        return ApiResponse::success($blockedDates->toArray(), 'Blocked dates retrieved.', 200);
    }

    public function store(StoreBlockedDateRequest $request): JsonResponse
    {
        $this->authorize('create', AgentBlockedDate::class);

        $blocked = DB::transaction(function () use ($request): AgentBlockedDate {
            return AgentBlockedDate::create([
                ...$request->validated(),
                'agent_id' => $request->user()->id,
            ]);
        });

        return ApiResponse::success($blocked->toArray(), 'Blocked date created.', 201);
    }

    public function update(UpdateBlockedDateRequest $request, AgentBlockedDate $agentBlockedDate): JsonResponse
    {
        $this->authorize('update', $agentBlockedDate);

        $agentBlockedDate->update($request->validated());

        return ApiResponse::success($agentBlockedDate->fresh()->toArray(), 'Blocked date updated.', 200);
    }

    public function destroy(AgentBlockedDate $agentBlockedDate): JsonResponse
    {
        $this->authorize('delete', $agentBlockedDate);

        $agentBlockedDate->delete();

        return ApiResponse::success(null, 'Blocked date deleted.', 200);
    }
}
