<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\Property;
use App\Services\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeminiController extends Controller
{
    public function __construct(private readonly GeminiService $service) {}

    public function generateDescription(Request $request): JsonResponse
    {
        // Multipart: details are optional, and the AI can also read attached photos.
        $request->validate([
            'type'       => ['nullable', 'in:house,condo,lot,commercial,apartment'],
            'offer_type' => ['nullable', 'in:sale,rent'],
            'price'      => ['nullable', 'numeric', 'min:0'],
            'bedrooms'   => ['nullable', 'integer', 'min:0'],
            'bathrooms'  => ['nullable', 'integer', 'min:0'],
            'floor_area' => ['nullable', 'numeric', 'min:0'],
            'lot_area'   => ['nullable', 'numeric', 'min:0'],
            'address'    => ['nullable', 'string', 'max:500'],
            'photos'     => ['nullable', 'array', 'max:6'],
            'photos.*'   => ['image', 'max:5120'],
        ]);

        $attributes = $request->only([
            'type', 'offer_type', 'price', 'bedrooms', 'bathrooms', 'floor_area', 'lot_area', 'address',
        ]);

        $images = [];
        foreach ((array) $request->file('photos', []) as $file) {
            $images[] = [
                'mime' => $file->getMimeType() ?: 'image/jpeg',
                'data' => base64_encode((string) file_get_contents($file->getRealPath())),
            ];
        }

        if (empty($images) && empty(array_filter($attributes, static fn ($v) => $v !== null && $v !== ''))) {
            return ApiResponse::error('Add a few photos or details first so the AI has something to work with.', [], 422);
        }

        $description = $this->service->generatePropertyDescription($attributes, $images);

        return ApiResponse::success(['description' => $description], 'Description generated.', 200);
    }

    public function askQuestion(Request $request, Property $property): JsonResponse
    {
        $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        $answer = $this->service->answerPropertyQuestion($property, $request->validated('question'));

        return ApiResponse::success(['answer' => $answer], 'Question answered.', 200);
    }
}
