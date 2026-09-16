<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accountability for cancelled viewings.
     *
     * Cancelling used to be silent: a `reason` was accepted by the controller
     * and thrown away. Now every cancellation records who did it, why, and
     * whether it counted as a late cancellation (a CONFIRMED viewing dropped
     * within 24h of the slot). Those late ones drive the reliability figure and
     * the booking lockout.
     *
     * `strike_waived_at` is the escape hatch: an admin can forgive a specific
     * late cancellation — a real emergency shouldn't cost someone their access.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('cancel_reason_code', 40)->nullable()->after('notes');
            $table->text('cancel_reason_note')->nullable()->after('cancel_reason_code');
            $table->foreignId('cancelled_by_id')->nullable()->after('cancel_reason_note')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by_id');
            $table->boolean('late_cancellation')->default(false)->after('cancelled_at');
            $table->timestamp('strike_waived_at')->nullable()->after('late_cancellation');

            $table->index(['cancelled_by_id', 'late_cancellation', 'cancelled_at'], 'appointments_strike_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_strike_idx');
            $table->dropConstrainedForeignId('cancelled_by_id');
            $table->dropColumn([
                'cancel_reason_code', 'cancel_reason_note',
                'cancelled_at', 'late_cancellation', 'strike_waived_at',
            ]);
        });
    }
};
