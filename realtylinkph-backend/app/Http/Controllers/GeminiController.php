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
    /** Photos sent to the AI for a description (small thumbnails). */
    private const AI_PHOTOS = 3;

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
            // "emoji": a short intro plus a few highlight lines led by a property emoji.
            'style'       => ['nullable', 'in:plain,emoji'],
        ]);

        $attributes = $request->only([
            'type', 'offer_type', 'price', 'bedrooms', 'bathrooms', 'floor_area', 'lot_area', 'address',
        ]);

        // Three small photos are plenty for a description: six full-size photos
        // made the request slow and used up the free AI quota far faster.
        $images = [];
        foreach (array_slice((array) $request->file('photos', []), 0, self::AI_PHOTOS) as $file) {
            $small = null;
            try {
                $small = \App\Support\ListingImage::process($file->getRealPath())['thumb'] ?? null;
            } catch (\Throwable $e) {
                report($e);
            }
            $images[] = [
                'mime' => $small ? 'image/jpeg' : ($file->getMimeType() ?: 'image/jpeg'),
                'data' => base64_encode($small ?? (string) file_get_contents($file->getRealPath())),
            ];
        }

        if (empty($images) && $request->filled('property_id')) {
            $property = \App\Models\Property::with('photos')->find($request->integer('property_id'));
            if ($property && $property->agent_id === $request->user()->id) {
                foreach ($property->photos->where('is_360', false)->take(self::AI_PHOTOS) as $photo) {
                    // A photo storage can't hand back is skipped, not fatal:
                    // the description can still be written from the details.
                    try {
                        $bytes = \App\Support\Uploads::disk()->get($photo->thumb_url ?: $photo->url);
                    } catch (\Throwable $e) {
                        report($e);
                        continue;
                    }
                    if ($bytes === null || $bytes === '') continue;
                    $images[] = ['mime' => 'image/jpeg', 'data' => base64_encode($bytes)];
                }
            }
        }

        if (empty($images) && empty(array_filter($attributes, static fn ($v) => $v !== null && $v !== ''))) {
            return ApiResponse::error('Add a few photos or details first so the AI has something to work with.', [], 422);
        }

        @set_time_limit(90);   // worst case: photos, one retry, then the details-only route
        $result = $this->service->describeProperty($attributes, $images, $request->input('style') === 'emoji' ? 'emoji' : 'plain');

        if ($result === null) {
            // Was a 200 with an empty description, which the app showed as "busy" with no way to tell.
            return ApiResponse::error('RealtyLink AI is busy right now. Please try again in 30 seconds.', [], 503);
        }

        return ApiResponse::success([
            'description' => $result['description'],
            // False when the photos couldn't be read and only the details were used.
            'used_photos' => $result['used_photos'],
        ], 'Description generated.', 200);
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
