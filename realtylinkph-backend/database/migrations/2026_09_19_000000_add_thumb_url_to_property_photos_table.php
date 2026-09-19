<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listing photos used to be stored exactly as uploaded — a phone photo is
 * 3–10 MB — and every card on the Browse page downloaded the full file.
 * Uploads are now resized on the way in (PropertyPhotoService) and a small
 * card-sized copy is kept alongside. Nullable because photos uploaded before
 * this change have no thumbnail until `photos:optimize` backfills them; the
 * resource falls back to the main image in the meantime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_photos', function (Blueprint $table): void {
            $table->string('thumb_url')->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('property_photos', function (Blueprint $table): void {
            $table->dropColumn('thumb_url');
        });
    }
};
