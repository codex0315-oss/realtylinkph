<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews used to hang off a completed viewing only, one per appointment.
 * That protected exactly the agents who deserve a review least — the ones
 * who never reply or cancel. A review is now one per buyer↔agent pair and
 * can rest on either a viewing (completed, or cancelled by the agent /
 * expired unanswered) or a conversation the agent actually replied in.
 *
 * `appointment_id` stays as the "verified viewing" link when there is one;
 * `conversation_id` records the chat basis otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_reviews', function (Blueprint $table): void {
            $table->dropUnique(['appointment_id']);
            $table->dropForeign(['appointment_id']);
        });

        Schema::table('agent_reviews', function (Blueprint $table): void {
            $table->foreignId('appointment_id')->nullable()->change();
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->after('appointment_id')
                ->constrained()->nullOnDelete();
        });

        // One review per buyer per agent: keep the newest, drop the rest.
        DB::statement(<<<'SQL'
            DELETE FROM agent_reviews r
            USING agent_reviews newer
            WHERE newer.buyer_id = r.buyer_id
              AND newer.agent_id = r.agent_id
              AND (newer.created_at > r.created_at OR (newer.created_at = r.created_at AND newer.id > r.id))
        SQL);

        Schema::table('agent_reviews', function (Blueprint $table): void {
            $table->unique(['buyer_id', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_reviews', function (Blueprint $table): void {
            $table->dropUnique(['buyer_id', 'agent_id']);
            $table->dropConstrainedForeignId('conversation_id');
        });
        // Reviews without an appointment can't go back to the old shape.
        DB::statement('DELETE FROM agent_reviews WHERE appointment_id IS NULL');
        Schema::table('agent_reviews', function (Blueprint $table): void {
            $table->dropForeign(['appointment_id']);
            $table->foreignId('appointment_id')->nullable(false)->change();
            $table->foreign('appointment_id')->references('id')->on('appointments')->cascadeOnDelete();
            $table->unique('appointment_id');
        });
    }
};
