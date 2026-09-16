<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Stop two buyers landing on the same slot with the same agent.
     *
     * The service already checks availability before inserting, but two
     * requests can both pass that check before either row is written — a
     * genuine race on a popular listing, or a double-tapped button. Only the
     * database can settle it.
     *
     * Partial index: it applies to live bookings only, so a cancelled or
     * completed viewing releases the slot for someone else to take.
     */
    public function up(): void
    {
        // Clear any duplicates that already slipped through, keeping the earliest.
        DB::statement(<<<'SQL'
            UPDATE appointments SET status = 'cancelled',
                   cancel_reason_code = 'double_booked',
                   cancel_reason_note = 'Released automatically: the slot was already taken.',
                   cancelled_at = NOW()
            WHERE id IN (
                SELECT id FROM (
                    SELECT id, ROW_NUMBER() OVER (
                        PARTITION BY agent_id, preferred_datetime ORDER BY id
                    ) AS rn
                    FROM appointments
                    WHERE status IN ('pending', 'confirmed')
                ) dupes WHERE rn > 1
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX appointments_agent_slot_unique
            ON appointments (agent_id, preferred_datetime)
            WHERE status IN ('pending', 'confirmed')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS appointments_agent_slot_unique');
    }
};
