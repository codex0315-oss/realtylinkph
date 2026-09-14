<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            // When an admin approves/rejects — drives the 12h re-apply cooldown.
            $table->timestamp('reviewed_at')->nullable()->after('ai_assessed_at');
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->dropColumn('reviewed_at');
        });
    }
};
