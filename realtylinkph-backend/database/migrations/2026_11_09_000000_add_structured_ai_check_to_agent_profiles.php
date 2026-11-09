<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RealtyLink AI's pre-check of an agent application is now a fixed checklist
 * (name, number, validity, document, photo) with a verdict decided by rules in
 * code, so the same documents always give the same advice. Older applications
 * keep their free-text `ai_comment` only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->json('ai_checks')->nullable()->after('ai_comment');
            $table->string('ai_verdict', 24)->nullable()->after('ai_checks');
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->dropColumn(['ai_checks', 'ai_verdict']);
        });
    }
};
