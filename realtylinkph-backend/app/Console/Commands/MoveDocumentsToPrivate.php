<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AgentProfile;
use App\Support\Documents;
use App\Support\Uploads;
use Illuminate\Console\Command;

/**
 * One-off: applicant documents used to be written to the public uploads
 * disk. Moves every file an agent profile still points at from there to the
 * documents disk (same path), then deletes the public copy. Idempotent —
 * a file already on the private disk is only cleaned up on the public side.
 */
class MoveDocumentsToPrivate extends Command
{
    private const FIELDS = ['license_doc', 'accreditation_doc', 'valid_id', 'face_image'];

    protected $signature = 'documents:move-private {--dry-run : Report without moving anything}';

    protected $description = 'Move applicant documents from the public uploads disk to the private documents disk';

    public function handle(): int
    {
        if (Uploads::name() === Documents::name()) {
            $this->warn('Uploads and documents are the same disk (' . Uploads::name() . ') — nothing to move. Set DOCUMENT_DISK first.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $moved = $skipped = $missing = 0;

        AgentProfile::query()->orderBy('id')->lazy()->each(function (AgentProfile $p) use ($dry, &$moved, &$skipped, &$missing): void {
            foreach (self::FIELDS as $field) {
                $path = $p->{$field};
                if (! $path) {
                    continue;
                }

                $onPrivate = Documents::disk()->exists($path);
                $onPublic  = Uploads::disk()->exists($path);

                if ($onPrivate && ! $onPublic) {
                    $skipped++;
                    continue;
                }
                if (! $onPublic) {
                    $this->warn("profile #{$p->id} {$field}: {$path} not found on either disk");
                    $missing++;
                    continue;
                }

                $this->line("profile #{$p->id} {$field}: {$path} → private" . ($onPrivate ? ' (already there; removing public copy)' : ''));
                if (! $dry) {
                    if (! $onPrivate) {
                        Documents::disk()->put($path, Uploads::disk()->get($path));
                    }
                    Uploads::disk()->delete($path);
                }
                $moved++;
            }
        });

        $this->info(sprintf('%s: %d moved, %d already private, %d missing', $dry ? 'Dry run' : 'Done', $moved, $skipped, $missing));

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }
}
