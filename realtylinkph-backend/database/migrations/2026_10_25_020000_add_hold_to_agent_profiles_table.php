<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Check later" on an agent application: when PRC's lookup is down the admin
 * can't confirm the license, so the application stays pending with a note
 * (admin-only — unlike admin_note, which the applicant sees). Cleared by any
 * decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->string('hold_note', 500)->nullable();
            $table->string('held_by')->nullable();
            $table->timestamp('held_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->dropColumn(['hold_note', 'held_by', 'held_at']);
        });
    }
};
