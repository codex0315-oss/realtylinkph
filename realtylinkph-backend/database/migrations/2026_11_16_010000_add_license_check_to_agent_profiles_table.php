<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What the admin found when checking the license on an official site:
 * prc_found, dhsud_found (with the "Valid Until" date shown there) or
 * not_found — with who checked and when. Evidence behind the decision, even
 * when PRC's lookup is down and DHSUD's registry is used instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->string('license_check', 20)->nullable();
            $table->date('license_check_valid_until')->nullable();
            $table->string('license_check_note', 300)->nullable();
            $table->string('license_checked_by')->nullable();
            $table->timestamp('license_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->dropColumn(['license_check', 'license_check_valid_until', 'license_check_note', 'license_checked_by', 'license_checked_at']);
        });
    }
};
