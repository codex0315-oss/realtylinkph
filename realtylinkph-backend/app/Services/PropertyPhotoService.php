<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PropertyPhotoService
{
    public function upload(Property $property, UploadedFile $file, bool $is360 = false): PropertyPhoto
    {
        return DB::transaction(function () use ($property, $file, $is360): PropertyPhoto {
            // Store the uploaded image as-is (no GD/Imagick driver available in this env).
            $filename = $file->store('properties/' . $property->id, 'public');

            $nextOrder = $property->photos()->max('sort_order') + 1;

            return PropertyPhoto::create([
                'property_id' => $property->id,
                'url'         => $filename,
                'is_360'      => $is360,
                'sort_order'  => $nextOrder,
            ]);
        });
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
        Storage::disk('public')->delete($photo->url);
        $photo->delete();
    }
}
