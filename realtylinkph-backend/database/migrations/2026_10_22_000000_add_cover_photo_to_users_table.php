<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * An agent's profile cover photo, shown on their public profile, their own
 * My Profile, directory cards and "Listed by" on listings.
 *
 * cover_position is the vertical focal point as a percentage (0 = top,
 * 100 = bottom) — the agent drags the photo to choose what the wide banner
 * shows, and every place that renders the cover uses the same value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('cover_photo')->nullable()->after('avatar');
            $table->unsignedTinyInteger('cover_position')->default(50)->after('cover_photo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['cover_photo', 'cover_position']);
        });
    }
};
