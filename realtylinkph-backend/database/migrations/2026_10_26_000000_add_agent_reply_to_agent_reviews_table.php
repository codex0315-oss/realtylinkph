<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The agent's public reply to a review: one per review, editable, shown under
 * the review on the agent's profile and on the buyer's Reviews page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_reviews', function (Blueprint $table): void {
            $table->text('agent_reply')->nullable()->after('review_text');
            $table->timestamp('agent_replied_at')->nullable()->after('agent_reply');
        });
    }

    public function down(): void
    {
        Schema::table('agent_reviews', function (Blueprint $table): void {
            $table->dropColumn(['agent_reply', 'agent_replied_at']);
        });
    }
};
