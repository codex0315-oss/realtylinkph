<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Review integrity: fake buyer accounts used to boost an agent's rating.
 *
 *  - account_signals: a scrambled (HMAC) code of the network address and of the
 *    network + browser a person signs up / signs in from. Never the raw IP or
 *    user agent, only enough to notice two accounts using the same device.
 *  - agent_reviews: why a review was flagged for an administrator to look at.
 *  - agent_sanctions: warning / listing suspension / verification revoked.
 *  - agent_profiles.revoked_at: a revoked agent waits longer before re-applying.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('network_hash', 64)->index();
            $table->string('device_hash', 64)->index();
            $table->string('event', 20);
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('agent_reviews', function (Blueprint $table): void {
            $table->json('flag_reasons')->nullable();
            $table->timestamp('flagged_at')->nullable()->index();
            $table->timestamp('flag_cleared_at')->nullable();
            $table->string('flag_cleared_by')->nullable();
        });

        Schema::create('agent_sanctions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_name')->nullable();
            $table->string('type', 20); // warning | suspension | revocation | lift
            $table->text('reason');
            $table->timestamp('until')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'type']);
        });

        Schema::table('agent_profiles', function (Blueprint $table): void {
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_profiles', fn (Blueprint $t) => $t->dropColumn('revoked_at'));
        Schema::dropIfExists('agent_sanctions');
        Schema::table('agent_reviews', fn (Blueprint $t) => $t->dropColumn(['flag_reasons', 'flagged_at', 'flag_cleared_at', 'flag_cleared_by']));
        Schema::dropIfExists('account_signals');
    }
};
