<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Appointment\BookAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Property;
use App\Services\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function __construct(private readonly AppointmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $appointments = $this->service->listForUser($request->user());

        return ApiResponse::paginated(AppointmentResource::collection($appointments), 'Appointments retrieved.');
    }

    public function show(Appointment $appointment): JsonResponse
    {
        $this->authorize('view', $appointment);

        return ApiResponse::success(
            AppointmentResource::make($appointment->load(['property', 'buyer', 'agent'])),
            'Appointment retrieved.',
            200
        );
    }

    public function book(BookAppointmentRequest $request, Property $property): JsonResponse
    {
        $this->authorize('create', Appointment::class);

        try {
            $appointment = $this->service->book($request->user(), $property, $request->validated());
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 422);
        }

        return ApiResponse::success(AppointmentResource::make($appointment), 'Appointment booked.', 201);
    }

    public function confirm(Appointment $appointment): JsonResponse
    {
        $this->authorize('confirm', $appointment);

        if (! $appointment->isPending()) {
            return ApiResponse::error('Only pending appointments can be confirmed.', [], 422);
        }

        $appointment = $this->service->confirm($appointment);

        return ApiResponse::success(AppointmentResource::make($appointment), 'Appointment confirmed.', 200);
    }

    public function complete(Appointment $appointment): JsonResponse
    {
        $this->authorize('complete', $appointment);

        if (! $appointment->isConfirmed()) {
            return ApiResponse::error('Only confirmed appointments can be marked as done.', [], 422);
        }

        $appointment = $this->service->complete($appointment);

        return ApiResponse::success(AppointmentResource::make($appointment), 'Appointment marked as done.', 200);
    }

    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('cancel', $appointment);

        if ($appointment->isCancelled()) {
            return ApiResponse::error('Appointment is already cancelled.', [], 422);
        }

        $appointment = $this->service->cancel($appointment, $request->input('reason'), $request->user());

        return ApiResponse::success(AppointmentResource::make($appointment), 'Appointment cancelled.', 200);
    }
}
