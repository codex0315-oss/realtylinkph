<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Conversation\SendMessageRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Property;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(private readonly ConversationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $conversations = $this->service->listForUser($request->user());

        return ApiResponse::paginated(ConversationResource::collection($conversations), 'Conversations retrieved.');
    }

    public function show(Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $this->service->markAsRead($conversation, request()->user());

        return ApiResponse::success(
            ConversationResource::make($conversation->load(['property', 'buyer', 'agent'])),
            'Conversation retrieved.',
            200
        );
    }

    public function startOrGet(Request $request, Property $property): JsonResponse
    {
        $conversation = $this->service->findOrCreate($request->user(), $property);

        return ApiResponse::success(
            ConversationResource::make($conversation->load(['property', 'buyer', 'agent'])),
            'Conversation ready.',
            200
        );
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $this->service->markAsRead($conversation, $request->user());

        $messages = $this->service->getMessages($conversation);

        return ApiResponse::paginated(MessageResource::collection($messages), 'Messages retrieved.');
    }

    public function sendMessage(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);

        $message = $this->service->sendMessage($request->user(), $conversation, $request->validated('body'));

        return ApiResponse::success(MessageResource::make($message), 'Message sent.', 201);
    }

    /**
     * Mark the other party's messages in this thread as read. The frontend has
     * called this on every thread open since the start; the route never existed,
     * so every open logged a 404 and read receipts only worked via whispers.
     */
    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $this->service->markAsRead($conversation, $request->user());

        return ApiResponse::success(null, 'Conversation marked as read.', 200);
    }

    /** Remove the conversation from the caller's inbox (the other side keeps it). */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);

        $this->service->deleteFor($conversation, $request->user());

        return ApiResponse::success(null, 'Conversation removed from your inbox.', 200);
    }
}
