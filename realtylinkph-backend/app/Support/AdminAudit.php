<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * One call per admin action: AdminAudit::log('listing.deleted', $property, $property->title, [...]).
 *
 * Never throws — the audit row is important, but not more important than
 * the action it describes; a failed insert is logged and the request goes on.
 */
final class AdminAudit
{
    public static function log(string $action, ?Model $subject, string $label, array $details = [], ?User $admin = null): void
    {
        $admin ??= request()->user();

        try {
            AdminAction::create([
                'admin_id'      => $admin?->id,
                'admin_name'    => $admin?->name ?? 'system',
                'action'        => $action,
                'subject_type'  => $subject ? class_basename($subject) : null,
                'subject_id'    => $subject?->getKey(),
                'subject_label' => mb_substr($label, 0, 255),
                'details'       => $details ?: null,
                'ip'            => request()->ip(),
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('admin audit row not written', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
