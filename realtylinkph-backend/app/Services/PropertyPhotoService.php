<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Support\ListingImage;
use App\Support\Uploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PropertyPhotoService
{
    /**
     * Objects never change once written (a re-upload gets a new random name),
     * so browsers and Supabase's CDN may keep them for a year. Without this
     * Supabase answers `cache-control: no-cache` and every visit re-downloads
     * every card image.
     */
    public const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function upload(Property $property, UploadedFile $file, bool $is360 = false): PropertyPhoto
    {
        return DB::transaction(function () use ($property, $file, $is360): PropertyPhoto {
            [$path, $thumbPath] = $this->store($property->id, $file, $is360);

            $nextOrder = $property->photos()->max('sort_order') + 1;

            return PropertyPhoto::create([
                'property_id' => $property->id,
                'url'         => $path,
                'thumb_url'   => $thumbPath,
                'is_360'      => $is360,
                'sort_order'  => $nextOrder,
            ]);
        });
    }

    /**
     * Resize and write the two sizes. Returns [mainPath, thumbPath]; thumbPath
     * is null (and the original is stored untouched) only when the image can't
     * be decoded — the upload still succeeds rather than failing on an odd file.
     *
     * @return array{0: string, 1: string|null}
     */
    public function store(int $propertyId, UploadedFile $file, bool $is360): array
    {
        $dir      = 'properties/' . $propertyId;
        $base     = Str::random(40);
        $sizes    = ListingImage::process($file->getRealPath(), $is360);
        $options  = ['CacheControl' => self::CACHE_CONTROL];

        if ($sizes === null) {
            return [$file->storeAs($dir, $base . '.' . $file->getClientOriginalExtension(), [
                'disk' => Uploads::name(),
                ...$options,
            ]), null];
        }

        // A pano keeps its original bytes, so keep its original extension too.
        $mainExt   = $is360 ? strtolower($file->getClientOriginalExtension() ?: 'jpg') : 'jpg';
        $mainPath  = "{$dir}/{$base}.{$mainExt}";
        $thumbPath = "{$dir}/{$base}-thumb.jpg";

        Uploads::disk()->put($mainPath, $sizes['main'], $options);
        Uploads::disk()->put($thumbPath, $sizes['thumb'], $options);

        return [$mainPath, $thumbPath];
    }

    public function reorder(Property $property, array $orderedIds): void
    {
        DB::transaction(function () use ($property, $orderedIds): void {
            foreach ($orderedIds as $order => $photoId) {
                $property->photos()->where('id', $photoId)->update(['sort_order' => $order + 1]);
            }
        });
    }

    public function delete(PropertyPhoto $photo): void
    {
        Uploads::disk()->delete(array_filter([$photo->url, $photo->thumb_url]));
        $photo->delete();
    }
}
