<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Resources\PropertyResource;
use App\Services\AgentAssistantService;
use App\Services\BuyerAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    /**
     * RealtyLink AI — buyer assistant chat. Extracts search criteria from the
     * conversation, finds REAL matching listings, and returns a grounded reply.
     * `shown_ids` are the listings this chat already showed, for "show me more".
     */
    public function chat(Request $request, BuyerAssistantService $assistant): JsonResponse
    {
        $data = $request->validate([
            'messages'           => ['required', 'array', 'min:1', 'max:30'],
            'messages.*.role'    => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:2000'],
            'shown_ids'          => ['sometimes', 'array', 'max:100'],
            'shown_ids.*'        => ['integer'],
        ]);

        $result = $assistant->reply($request->user(), $data['messages'], $data['shown_ids'] ?? []);

        return ApiResponse::success([
            ...$result,
            'properties' => PropertyResource::collection(collect($result['properties'])),
            // Keyed by listing id; an object even when empty.
            'nearby'     => (object) $result['nearby'],
        ], 'AI replied.', 200);
    }

    /**
     * RealtyLink AI — agent companion. Shows the agent's own data as cards,
     * prepares tasks for them to approve, checks prices and listings, drafts
     * replies, and still writes descriptions (optionally from an attached
     * photo) and proposes listings. Multipart so a photo can ride along.
     */
    public function agentChat(Request $request, AgentAssistantService $assistant): JsonResponse
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

        $result = $assistant->reply($request->user(), $request->input('messages'), $image);

        return ApiResponse::success($result, 'AI replied.', 200);
    }
}
