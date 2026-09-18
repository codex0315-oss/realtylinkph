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
            'photos'      => ['nullable', 'array', 'max:6'],
            'photos.*'    => ['image', 'max:5120'],
            // A draft's photos already live on the server — the wizard uploads
            // them as they're added, so after a refresh there are no File
            // objects in the browser to send. Read them from storage instead.
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
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

        if (empty($images) && $request->filled('property_id')) {
            $property = \App\Models\Property::with('photos')->find($request->integer('property_id'));
            if ($property && $property->agent_id === $request->user()->id) {
                foreach ($property->photos->take(6) as $photo) {
                    if (! \App\Support\Uploads::disk()->exists($photo->url)) continue;
                    $bytes = \App\Support\Uploads::disk()->get($photo->url);
                    if ($bytes === null || $bytes === '') continue;
                    $ext = strtolower(pathinfo($photo->url, PATHINFO_EXTENSION));
                    $images[] = [
                        'mime' => $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg'),
                        'data' => base64_encode($bytes),
                    ];
                }
            }
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
