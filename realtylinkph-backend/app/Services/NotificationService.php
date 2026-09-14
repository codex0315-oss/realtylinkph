<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NotificationSent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationService
{
    public function send(User $recipient, string $type, array $payload = []): void
    {
        // Persist first so the notification survives a refresh even if broadcasting fails.
        $recipient->notifications()->create([
            'id'      => (string) Str::uuid(),
            'type'    => $type,
            'data'    => $payload,
            'read_at' => null,
        ]);

        try {
            broadcast(new NotificationSent($recipient->id, $type, $payload));
        } catch (\Throwable $e) {
            Log::warning('NotificationService broadcast failed', [
                'recipient' => $recipient->id,
                'type'      => $type,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
