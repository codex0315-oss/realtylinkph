<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\Appointment\BookAppointmentRequest;
use App\Http\Requests\Appointment\CancelAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Property;
use App\Services\AppointmentService;
use App\Services\ViewingSanctionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function __construct(
        private readonly AppointmentService $service,
        private readonly ViewingSanctionService $sanctions,
    ) {}

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

        // Booking lockout: too many late cancellations in the last month.
        if ($lockedUntil = $request->user()->bookingLockedUntil()) {
            return ApiResponse::error(
                'Booking is paused until ' . $lockedUntil->setTimezone('Asia/Manila')->format('M j, Y g:i A')
                . ' because of repeated late cancellations.',
                ['locked_until' => $lockedUntil->toISOString()],
                423,
            );
        }

        // The agent whose calendar this is can also be locked out.
        if ($property->agent && $property->agent->isBookingLocked()) {
            return ApiResponse::error(
                'This agent is not accepting viewing requests at the moment. Please try another listing.',
                [],
                423,
            );
        }

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

        // An agent whose viewings are paused (too many strikes, or an admin pause)
        // can't take on new ones. Their existing confirmed viewings are untouched.
        if ($lockedUntil = request()->user()->bookingLockedUntil()) {
            return ApiResponse::error(
                'Your viewings are paused until ' . $lockedUntil->setTimezone('Asia/Manila')->format('M j, Y g:i A')
                . ', so you cannot confirm new requests right now.',
                ['locked_until' => $lockedUntil->toISOString()],
                423,
            );
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

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('cancel', $appointment);

        if ($appointment->isCancelled()) {
            return ApiResponse::error('Appointment is already cancelled.', [], 422);
        }

        $wasLate = $appointment->wouldBeLateCancellation();

        $appointment = $this->service->cancel(
            $appointment,
            $request->validated('reason_code'),
            $request->user(),
            $request->validated('reason_note'),
        );

        return ApiResponse::success(
            AppointmentResource::make($appointment),
            $wasLate
                ? 'Viewing cancelled. Because it was within ' . Appointment::LATE_WINDOW_HOURS . ' hours of the slot, it counts toward your reliability.'
                : 'Appointment cancelled.',
            200,
        );
    }

    /**
     * Report that the other party did not turn up to a viewing. It counts as two
     * strikes against them, they are told straight away, and an admin can waive
     * it if the report turns out to be wrong.
     */
    public function reportNoShow(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize('view', $appointment);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        if ($appointment->hasNoShowReport()) {
            return ApiResponse::error('A no-show has already been reported for this viewing.', [], 422);
        }

        if (! $appointment->canReportNoShow($request->user())) {
            return ApiResponse::error(
                'A no-show can be reported at least ' . Appointment::NO_SHOW_REPORT_AFTER_HOURS
                . ' hour after a confirmed viewing, and for ' . Appointment::NO_SHOW_REPORT_DAYS . ' days afterwards.',
                [],
                422,
            );
        }

        $appointment = $this->sanctions->reportNoShow($appointment, $request->user(), $data['note'] ?? null);

        return ApiResponse::success(AppointmentResource::make($appointment), 'No-show reported. They have been told.', 200);
    }
}
