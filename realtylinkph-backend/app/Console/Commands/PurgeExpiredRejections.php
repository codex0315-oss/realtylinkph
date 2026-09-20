<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AgentProfile;
use App\Support\Documents;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the uploaded documents of rejected agent applications once their
 * 12h re-apply cooldown has passed.
 *
 * A rejected applicant's government ID, live selfie and licence card must not
 * sit on the server indefinitely. The profile row is kept — an admin can still
 * see that this person was reviewed and why — but the files and their paths go.
 */
class PurgeExpiredRejections extends Command
{
    protected $signature = 'agents:purge-rejected-documents {--dry-run : List what would be deleted without deleting}';

    protected $description = 'Delete uploaded documents from rejected agent applications past their re-apply cooldown';

    public function handle(): int
    {
        $cutoff = now()->subHours(AgentProfile::REAPPLY_COOLDOWN_HOURS);

        $profiles = AgentProfile::where('status', 'rejected')
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at', '<=', $cutoff)
            ->where(function ($q) {
                $q->whereNotNull('license_doc')
                    ->orWhereNotNull('accreditation_doc')
                    ->orWhereNotNull('valid_id')
                    ->orWhereNotNull('face_image');
            })
            ->get();

        if ($profiles->isEmpty()) {
            $this->info('Nothing to purge.');

            return self::SUCCESS;
        }

        $dry   = (bool) $this->option('dry-run');
        $files = 0;

        foreach ($profiles as $profile) {
            $paths = $profile->documentPaths();
            $this->line(sprintf(
                '%s profile #%d (user %d) — %d file(s)',
                $dry ? 'Would purge' : 'Purging',
                $profile->id,
                $profile->user_id,
                count($paths),
            ));

            if ($dry) {
                $files += count($paths);
                continue;
            }

            foreach ($paths as $path) {
                if (Documents::disk()->exists($path)) {
                    Documents::disk()->delete($path);
                }
                $files++;
            }

            // Clear the paths too, so nothing points at files that are gone.
            $profile->update([
                'license_doc'       => null,
                'accreditation_doc' => null,
                'valid_id'          => null,
                'face_image'        => null,
            ]);
        }

        $this->info(sprintf(
            '%s %d file(s) across %d application(s).',
            $dry ? 'Would delete' : 'Deleted',
            $files,
            $profiles->count(),
        ));

        return self::SUCCESS;
    }
}
