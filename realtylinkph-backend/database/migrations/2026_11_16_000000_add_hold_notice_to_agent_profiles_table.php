<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A hold now tells the applicant. `hold_reason` is the preset picked
 * (prc_unavailable, documents, other) and `hold_message` the notice they were
 * sent, shown on their application. `hold_note` stays the admins' own note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->string('hold_reason', 30)->nullable();
            $table->text('hold_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->dropColumn(['hold_reason', 'hold_message']);
        });
    }
};
