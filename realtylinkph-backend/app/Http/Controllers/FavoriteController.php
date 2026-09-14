<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Resources\PropertyResource;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    /**
     * List the authenticated user's favorited (saved) properties.
     */
    public function index(Request $request): JsonResponse
    {
        $ids = $request->user()->favorites ?? [];

        $properties = Property::whereIn('id', $ids)
            ->with(['photos', 'agent.agentProfile'])
            ->paginate(15);

        return ApiResponse::paginated(
            PropertyResource::collection($properties),
            'Favorites retrieved.'
        );
    }

    /**
     * Toggle a property in the authenticated user's favorites.
     */
    public function toggle(Request $request, Property $property): JsonResponse
    {
        $favorited = $request->user()->toggleFavorite($property->id);

        return ApiResponse::success(
            ['favorited' => $favorited],
            $favorited ? 'Added to favorites.' : 'Removed from favorites.'
        );
    }
}
