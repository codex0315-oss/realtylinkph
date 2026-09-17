<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The app now writes viewings to a secondary calendar it creates in the
        // user's account, not to their primary one. That is what lets it use the
        // non-sensitive `calendar.app.created` scope — no "unverified app" wall.
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_calendar_id')->nullable()->after('google_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_calendar_id');
        });
    }
};
