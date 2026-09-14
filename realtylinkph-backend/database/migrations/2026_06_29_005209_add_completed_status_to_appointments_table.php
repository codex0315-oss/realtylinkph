<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Allow 'completed' (Postgres enforces the enum via a CHECK constraint).
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_status_check');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_check CHECK (status::text IN ('pending', 'confirmed', 'cancelled', 'completed'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE appointments SET status = 'confirmed' WHERE status = 'completed'");
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_status_check');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_check CHECK (status::text IN ('pending', 'confirmed', 'cancelled'))");
    }
};
