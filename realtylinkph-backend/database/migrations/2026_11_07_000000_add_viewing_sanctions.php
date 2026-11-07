<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Viewing sanctions.
 *
 * appointments:
 *   - no-show reports: who did not turn up, who reported it, and when. A no-show
 *     counts as two strikes (see User::NO_SHOW_WEIGHT).
 *   - strike_waived_by_id: which admin forgave a strike (strike_waived_at exists).
 *
 * viewing_sanctions: what an admin did about someone's viewing behaviour —
 * a warning, a manual booking pause, lifting a pause, or waiving a strike.
 * Kept as its own history so the user and the next admin can see it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('no_show_user_id')->nullable()->after('strike_waived_at')->constrained('users')->nullOnDelete();
            $table->foreignId('no_show_reported_by_id')->nullable()->after('no_show_user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('no_show_reported_at')->nullable()->after('no_show_reported_by_id');
            $table->string('no_show_note', 500)->nullable()->after('no_show_reported_at');
            $table->foreignId('strike_waived_by_id')->nullable()->after('no_show_note')->constrained('users')->nullOnDelete();

            $table->index(['no_show_user_id', 'no_show_reported_at']);
        });

        Schema::create('viewing_sanctions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            // warning | pause | lift | waive
            $table->string('type', 16);
            $table->text('reason');
            $table->unsignedSmallInteger('days')->nullable();
            $table->timestamp('until')->nullable();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewing_sanctions');

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('strike_waived_by_id');
            $table->dropColumn(['no_show_note', 'no_show_reported_at']);
            $table->dropConstrainedForeignId('no_show_reported_by_id');
            $table->dropConstrainedForeignId('no_show_user_id');
        });
    }
};
