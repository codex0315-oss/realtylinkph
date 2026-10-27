<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Why a review was hidden, by whom and when. The reviewer is told the reason,
 * and the admin Reviews page shows it on the hidden card. Cleared when the
 * review is shown again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_reviews', function (Blueprint $table): void {
            $table->string('hidden_reason', 500)->nullable()->after('is_visible');
            $table->string('hidden_by')->nullable()->after('hidden_reason');
            $table->timestamp('hidden_at')->nullable()->after('hidden_by');
        });
    }

    public function down(): void
    {
        Schema::table('agent_reviews', function (Blueprint $table): void {
            $table->dropColumn(['hidden_reason', 'hidden_by', 'hidden_at']);
        });
    }
};
