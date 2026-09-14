<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Property\StorePropertyRequest;
use App\Http\Requests\Property\UpdatePropertyRequest;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use App\Services\PropertyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function __construct(private readonly PropertyService $service) {}

    public function index(Request $request): JsonResponse
    {
        $properties = $this->service->list($request->query(), 15);

        return ApiResponse::paginated(PropertyResource::collection($properties), 'Properties retrieved.');
    }

    public function featured(): JsonResponse
    {
        $items = $this->service->featured(4);

        return ApiResponse::success(PropertyResource::collection($items), 'Featured properties retrieved.', 200);
    }

    /** Browse-first homepage: rows of listings grouped by city or theme. */
    public function homeRows(): JsonResponse
    {
        $rows = array_map(fn (array $row) => [
            'key'        => $row['key'],
            'title'      => $row['title'],
            'href'       => $row['href'],
            'properties' => PropertyResource::collection($row['properties'])->resolve(),
        ], $this->service->homeRows());

        return ApiResponse::success($rows, 'Home rows retrieved.', 200);
    }

    public function myListings(Request $request): JsonResponse
    {
        $properties = $this->service->listForAgent($request->user(), 15);

        return ApiResponse::paginated(PropertyResource::collection($properties), 'My listings retrieved.');
    }

    public function show(Request $request, Property $property): JsonResponse
    {
        // Public route — resolve the optional bearer token ourselves.
        $viewer = $request->user('sanctum');

        // Draft listings are visible only to their owner (or an admin).
        if ($property->status !== 'published'
            && (! $viewer || ($property->agent_id !== $viewer->id && ! $viewer->isAdmin()))) {
            return ApiResponse::error('Listing not found.', [], 404);
        }

        // Count a view — but not when the owner opens their own listing.
        if (! $viewer || $viewer->id !== $property->agent_id) {
            $property->increment('views');
        }

        return ApiResponse::success(
            PropertyResource::make($property->load(['agent.agentProfile', 'photos'])),
            'Property retrieved.',
            200
        );
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $this->authorize('create', Property::class);

        $property = $this->service->create($request->user(), $request->validated());

        return ApiResponse::success(
            PropertyResource::make($property->load(['agent', 'photos'])),
            'Property created.',
            201
        );
    }

    public function update(UpdatePropertyRequest $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $property = $this->service->update($property, $request->validated());

        return ApiResponse::success(
            PropertyResource::make($property),
            'Property updated.',
            200
        );
    }

    public function destroy(Property $property): JsonResponse
    {
        $this->authorize('delete', $property);

        $this->service->delete($property);

        return ApiResponse::success(null, 'Property deleted.', 200);
    }

    public function publish(Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        return ApiResponse::success(
            PropertyResource::make($this->service->publish($property)),
            'Property published.',
            200
        );
    }

    public function unpublish(Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        return ApiResponse::success(
            PropertyResource::make($this->service->unpublish($property)),
            'Property unpublished.',
            200
        );
    }

    /** The agent's sold/rented listings (Inventory). */
    public function inventory(Request $request): JsonResponse
    {
        $properties = $this->service->listSoldForAgent($request->user(), 15);

        return ApiResponse::paginated(PropertyResource::collection($properties), 'Inventory retrieved.');
    }

    public function markSold(Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        return ApiResponse::success(
            PropertyResource::make($this->service->markSold($property)),
            'Property marked as sold.',
            200
        );
    }

    public function relist(Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        return ApiResponse::success(
            PropertyResource::make($this->service->relist($property)),
            'Property re-listed as a draft.',
            200
        );
    }
}
