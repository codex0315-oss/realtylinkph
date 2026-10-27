<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A lighter copy of a 360° panorama (long edge 4096 px) so the virtual tour
 * opens quickly; the viewer swaps in the full original once it has loaded.
 * Null for regular photos and for panoramas already small enough.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_photos', function (Blueprint $table): void {
            $table->string('preview_url')->nullable()->after('thumb_url');
        });
    }

    public function down(): void
    {
        Schema::table('property_photos', function (Blueprint $table): void {
            $table->dropColumn('preview_url');
        });
    }
};
