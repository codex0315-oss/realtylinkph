<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __construct(private readonly AvailabilityService $service) {}

    public function slots(Request $request, User $agent): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $slots = $this->service->getAvailableSlots($agent, $request->query('date'));

        return ApiResponse::success(
            ['date' => $request->query('date'), 'slots' => $slots],
            'Available slots retrieved.',
            200
        );
    }

    /** Fully-blocked vs partially-blocked upcoming dates — for the buyer's date picker. */
    public function unavailableDates(User $agent): JsonResponse
    {
        return ApiResponse::success($this->service->getUnavailability($agent), 'Unavailability retrieved.', 200);
    }
}
