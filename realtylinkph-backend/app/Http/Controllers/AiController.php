<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Resources\PropertyResource;
use App\Services\GeminiService;
use App\Services\PropertyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly PropertyService $properties,
    ) {}

    /**
     * RealtyLink AI — buyer assistant chat. Extracts search criteria from the
     * conversation, finds REAL matching listings, and returns a grounded reply.
     */
    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'messages'           => ['required', 'array', 'min:1', 'max:30'],
            'messages.*.role'    => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:2000'],
        ]);

        $history = $data['messages'];

        // 1) Understand what the buyer is looking for.
        $criteria = $this->gemini->extractSearchCriteria($history);

        // 2) Pull real, published listings that match.
        $filters = array_filter($criteria, static fn ($v) => $v !== null);
        $results = $this->properties->list($filters, 4);
        $items   = $results->items();

        // 3) Write a friendly, grounded recommendation.
        $reply = $this->gemini->generateAssistantReply($history, $items);

        return ApiResponse::success([
            'reply'      => $reply,
            'criteria'   => $criteria,
            'properties' => PropertyResource::collection(collect($items)),
        ], 'AI replied.', 200);
    }

    /**
     * RealtyLink AI — agent companion. Writes descriptions (optionally from an
     * attached photo), proposes listings to create, and answers questions about
     * the agent's own listings. Sent as multipart so a photo can ride along.
     */
    public function agentChat(Request $request): JsonResponse
    {
        // `messages` arrives as a JSON string (multipart form-data).
        $decoded = json_decode((string) $request->input('messages'), true);
        $request->merge(['messages' => is_array($decoded) ? $decoded : null]);

        $request->validate([
            'messages'           => ['required', 'array', 'min:1', 'max:30'],
            'messages.*.role'    => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:2000'],
            'image'              => ['nullable', 'image', 'max:5120'], // 5MB
        ]);

        $image = null;
        if ($request->hasFile('image')) {
            $file  = $request->file('image');
            $image = [
                'mime' => $file->getMimeType() ?: 'image/jpeg',
                'data' => base64_encode((string) file_get_contents($file->getRealPath())),
            ];
        }

        $result = $this->gemini->agentChat($request->user(), $request->input('messages'), $image);

        return ApiResponse::success($result, 'AI replied.', 200);
    }
}
