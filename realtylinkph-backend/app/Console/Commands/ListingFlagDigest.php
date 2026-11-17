<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PropertyFlag;
use App\Models\User;
use App\Notifications\ViewingSanctionNotice;
use App\Services\ListingIntegrityService;
use Illuminate\Console\Command;

/**
 * One email a day to each admin listing what RealtyLink AI flagged and buyers
 * reported in the last 24 hours, so a busy day doesn't flood their inbox
 * (each flag is also a real-time bell notification).
 */
class ListingFlagDigest extends Command
{
    protected $signature = 'listings:flag-digest';

    protected $description = 'Email admins a daily summary of newly flagged listings';

    public function handle(): int
    {
        $flags = PropertyFlag::with('property:id,title')->open()->where('created_at', '>=', now()->subDay())->get();
        if ($flags->isEmpty()) {
            $this->info('Nothing flagged in the last 24 hours.');

            return self::SUCCESS;
        }

        $byListing = $flags->groupBy('property_id');
        $lines = $byListing->take(15)->map(function ($group) {
            $title   = $group->first()->property?->title ?? 'A listing';
            $reasons = $group->flatMap->reasons->unique()->map(fn ($r) => ListingIntegrityService::LABELS[$r] ?? $r)->implode('; ');

            return "{$title}: {$reasons}" . ($group->contains('paused', true) ? ' (paused)' : '');
        })->values()->all();
        $count = $byListing->count();

        User::whereIn('role_type', ['admin', 'super_admin'])->where('is_system', false)->get()->each(fn (User $admin) => $admin->notify(new ViewingSanctionNotice(
            "RealtyLink AI: {$count} " . ($count === 1 ? 'listing needs' : 'listings need') . ' your review',
            "{$count} " . ($count === 1 ? 'listing was' : 'listings were') . ' flagged in the last 24 hours',
            $lines,
            null,
            'Open flagged listings',
            '/admin/listings?tab=flagged',
        )));

        $this->info("Sent a summary of {$count} flagged listings.");

        return self::SUCCESS;
    }
}
