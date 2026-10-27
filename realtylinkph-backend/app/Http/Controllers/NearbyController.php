<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Property;
use App\Services\NearbyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public "what's nearby" and "how far from me" for published listings only,
 * so a draft's location never leaks through here.
 */
class NearbyController extends Controller
{
    public function __construct(private readonly NearbyService $service) {}

    public function show(Property $property): JsonResponse
    {
        abort_unless($property->status === 'published', 404);

        return ApiResponse::success($this->service->forProperty($property), 'Nearby places retrieved.');
    }

    public function travelTime(Request $request, Property $property): JsonResponse
    {
        abort_unless($property->status === 'published', 404);

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $result = $this->service->travelTime($property, (float) $data['lat'], (float) $data['lng']);

        return $result
            ? ApiResponse::success($result, 'Travel time estimated.')
            : ApiResponse::error('No driving route found from your location.');
    }
}
