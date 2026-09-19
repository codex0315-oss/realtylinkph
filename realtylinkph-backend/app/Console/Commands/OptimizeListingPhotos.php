<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PropertyPhoto;
use App\Services\PropertyPhotoService;
use App\Support\ListingImage;
use App\Support\Uploads;
use Illuminate\Console\Command;

/**
 * Backfills the resize-on-upload pipeline for photos stored before it existed:
 * writes a card thumbnail for every photo that lacks one, and replaces
 * oversized main images (non-360) with the 1600 px version. Safe to re-run —
 * a photo that already has a thumbnail and a small main image is skipped.
 */
class OptimizeListingPhotos extends Command
{
    protected $signature = 'photos:optimize
        {--dry-run : Report what would change without writing anything}
        {--limit=0 : Stop after this many photos (0 = all)}';

    protected $description = 'Generate thumbnails and shrink oversized listing photos uploaded before resize-on-upload';

    public function handle(): int
    {
        $disk    = Uploads::disk();
        $options = ['CacheControl' => PropertyPhotoService::CACHE_CONTROL];
        $limit   = (int) $this->option('limit');
        $dry     = (bool) $this->option('dry-run');

        $query = PropertyPhoto::query()->orderBy('id');
        $done = $skipped = $failed = 0;
        $savedBytes = 0;

        foreach ($query->lazy() as $photo) {
            if ($limit > 0 && $done >= $limit) {
                break;
            }

            // Already an absolute URL (seeded from elsewhere) — nothing to fetch.
            if (str_starts_with($photo->url, 'http')) {
                $skipped++;
                continue;
            }

            if (! $disk->exists($photo->url)) {
                $this->warn("#{$photo->id}: missing object {$photo->url}");
                $failed++;
                continue;
            }

            $size         = (int) $disk->size($photo->url);
            $needsThumb   = $photo->thumb_url === null;
            $needsShrink  = ! $photo->is_360 && ListingImage::isOversized($size);

            if (! $needsThumb && ! $needsShrink) {
                $skipped++;
                continue;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'rlph');
            file_put_contents($tmp, $disk->get($photo->url));

            $sizes = ListingImage::process($tmp, (bool) $photo->is_360);
            @unlink($tmp);

            if ($sizes === null) {
                $this->warn("#{$photo->id}: could not decode {$photo->url}");
                $failed++;
                continue;
            }

            $dir  = dirname($photo->url);
            $base = pathinfo($photo->url, PATHINFO_FILENAME);
            $newMain  = $needsShrink ? "{$dir}/{$base}-w" . ListingImage::MAIN_EDGE . '.jpg' : $photo->url;
            $newThumb = $needsThumb ? "{$dir}/{$base}-thumb.jpg" : $photo->thumb_url;

            $mainKb  = (int) round(strlen($sizes['main']) / 1024);
            $thumbKb = (int) round(strlen($sizes['thumb']) / 1024);
            $this->line(sprintf(
                '#%d %s %dx%d %d KB → main %s, thumb %d KB',
                $photo->id,
                $photo->is_360 ? '(360)' : '',
                $sizes['width'],
                $sizes['height'],
                (int) round($size / 1024),
                $needsShrink ? "{$mainKb} KB" : 'kept (already small)',
                $thumbKb,
            ));

            if ($dry) {
                $done++;
                continue;
            }

            if ($needsThumb) {
                $disk->put($newThumb, $sizes['thumb'], $options);
            }
            if ($needsShrink) {
                $disk->put($newMain, $sizes['main'], $options);
            }

            $oldMain = $photo->url;
            $photo->forceFill(['url' => $newMain, 'thumb_url' => $newThumb])->save();

            // Only drop the original once the row points at the replacement.
            if ($needsShrink && $newMain !== $oldMain) {
                $disk->delete($oldMain);
                $savedBytes += $size - strlen($sizes['main']);
            }

            $done++;
        }

        $this->info(sprintf(
            '%s: %d processed, %d skipped, %d failed, %.1f MB freed',
            $dry ? 'Dry run' : 'Done',
            $done,
            $skipped,
            $failed,
            $savedBytes / 1024 / 1024,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
