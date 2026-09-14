<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an admin takes a listing down they must say why. The reason lives
     * on the listing itself — not only in a notification — so the agent still
     * sees it on My Listings until they fix the issue and re-publish, at which
     * point both columns are cleared.
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->text('unpublish_reason')->nullable()->after('status');
            $table->timestamp('unpublished_at')->nullable()->after('unpublish_reason');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['unpublish_reason', 'unpublished_at']);
        });
    }
};
