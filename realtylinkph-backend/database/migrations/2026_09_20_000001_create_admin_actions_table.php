<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail of what administrators did: who approved an agent, who took a
 * listing down and why, who deleted a user. The subject is recorded both as
 * a polymorphic reference and as a plain label, so a row still reads
 * sensibly after the user or listing it refers to is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_name');                 // kept even if the admin account is removed
            $table->string('action', 40);                 // e.g. agent.approved, listing.unpublished
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label');              // "Juan Dela Cruz (juan@example.com)", listing title
            $table->json('details')->nullable();          // reason, previous state, counts
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at');

            $table->index(['action', 'created_at']);
            $table->index('admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
    }
};
