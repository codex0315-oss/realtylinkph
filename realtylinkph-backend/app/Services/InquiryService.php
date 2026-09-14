<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Inquiry;
use App\Models\Property;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InquiryService
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function submit(Property $property, array $data, ?User $user = null): Inquiry
    {
        return DB::transaction(function () use ($property, $data, $user): Inquiry {
            $inquiry = Inquiry::create([
                'property_id' => $property->id,
                'user_id'     => $user?->id,
                'name'        => $data['name'],
                'email'       => $data['email'],
                'phone'       => $data['phone'] ?? null,
                'message'     => $data['message'],
                'is_read'     => false,
            ]);

            $this->notificationService->send($property->agent, 'new_inquiry', [
                'inquiry_id'     => $inquiry->id,
                'property_title' => $property->title,
                'sender_name'    => $data['name'],
            ]);

            return $inquiry;
        });
    }

    public function markAsRead(Inquiry $inquiry): void
    {
        $inquiry->update(['is_read' => true]);
    }

    public function listForAgent(User $agent, int $perPage = 15): LengthAwarePaginator
    {
        return Inquiry::with('property')
            ->whereHas('property', fn ($q) => $q->where('agent_id', $agent->id))
            ->latest()
            ->paginate($perPage);
    }
}
