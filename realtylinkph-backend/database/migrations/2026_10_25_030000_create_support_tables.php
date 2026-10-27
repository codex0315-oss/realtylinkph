<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Help & support. A buyer or agent chats with RealtyLink AI first; when the
 * AI can't help (or they ask for a person) the conversation becomes a ticket
 * an admin answers.
 *
 *   status: ai (only the AI so far) → open (waiting for an admin)
 *           → in_progress (an admin replied) → resolved
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('ai')->index();
            $table->string('category', 30)->nullable();
            $table->text('summary')->nullable();
            $table->text('suggested_reply')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by')->nullable();
            $table->string('rating', 4)->nullable();     // up | down
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->string('sender_type', 10);           // user | ai | admin | system
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->json('attachments')->nullable();     // private storage paths
            $table->timestamp('read_at')->nullable();    // by the other side
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('support_quick_replies', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 80);
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_quick_replies');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
