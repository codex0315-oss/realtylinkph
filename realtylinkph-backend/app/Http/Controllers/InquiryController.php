<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Inquiry\SubmitInquiryRequest;
use App\Http\Resources\InquiryResource;
use App\Models\Inquiry;
use App\Models\Property;
use App\Services\InquiryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InquiryController extends Controller
{
    public function __construct(private readonly InquiryService $service) {}

    public function submit(SubmitInquiryRequest $request, Property $property): JsonResponse
    {
        $inquiry = $this->service->submit($property, $request->validated(), $request->user());

        return ApiResponse::success(InquiryResource::make($inquiry), 'Inquiry submitted.', 201);
    }

    public function index(Request $request): JsonResponse
    {
        $inquiries = $this->service->listForAgent($request->user());

        return ApiResponse::paginated(InquiryResource::collection($inquiries), 'Inquiries retrieved.');
    }

    public function markRead(Request $request, Inquiry $inquiry): JsonResponse
    {
        // The route only checked "is a verified agent", not "is *this* agent" —
        // any agent could mark any other agent's inquiries as read by id.
        $inquiry->loadMissing('property');

        if ($inquiry->property->agent_id !== $request->user()->id) {
            throw new AuthorizationException('This inquiry is not on one of your listings.');
        }

        $this->service->markAsRead($inquiry);

        return ApiResponse::success(InquiryResource::make($inquiry->fresh()), 'Inquiry marked as read.', 200);
    }
}
