<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Google Calendar connection now lives on the user (works for buyers AND agents).
        Schema::table('users', function (Blueprint $table) {
            $table->text('google_access_token')->nullable()->after('property_alerts');
            $table->text('google_refresh_token')->nullable()->after('google_access_token');
            $table->timestamp('google_token_expires_at')->nullable()->after('google_refresh_token');
        });

        // Track the buyer's calendar event separately from the agent's.
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('gcal_event_id_buyer')->nullable()->after('gcal_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_access_token', 'google_refresh_token', 'google_token_expires_at']);
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('gcal_event_id_buyer');
        });
    }
};
