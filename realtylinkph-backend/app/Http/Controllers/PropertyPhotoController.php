<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Property\ReorderPhotosRequest;
use App\Http\Requests\Property\UploadPhotoRequest;
use App\Http\Resources\PropertyPhotoResource;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Services\PropertyPhotoService;
use Illuminate\Http\JsonResponse;

class PropertyPhotoController extends Controller
{
    public function __construct(private readonly PropertyPhotoService $service) {}

    public function upload(UploadPhotoRequest $request, Property $property): JsonResponse
    {
        $this->authorize('managePhotos', $property);

        $photo = $this->service->upload(
            $property,
            $request->file('photo'),
            (bool) $request->input('is_360', false)
        );

        return ApiResponse::success(PropertyPhotoResource::make($photo), 'Photo uploaded.', 201);
    }

    public function reorder(ReorderPhotosRequest $request, Property $property): JsonResponse
    {
        $this->authorize('managePhotos', $property);

        $this->service->reorder($property, $request->validated('photo_ids'));

        return ApiResponse::success(
            PropertyPhotoResource::collection($property->fresh()->photos),
            'Photos reordered.',
            200
        );
    }

    public function destroy(Property $property, PropertyPhoto $photo): JsonResponse
    {
        $this->authorize('managePhotos', $property);

        $this->service->delete($photo);

        return ApiResponse::success(null, 'Photo deleted.', 200);
    }
}
