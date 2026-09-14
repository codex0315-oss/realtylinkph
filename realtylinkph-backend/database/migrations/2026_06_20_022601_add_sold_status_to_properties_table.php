<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Allow 'sold' as a status (Postgres enforces the enum via a CHECK constraint).
        DB::statement("ALTER TABLE properties DROP CONSTRAINT IF EXISTS properties_status_check");
        DB::statement("ALTER TABLE properties ADD CONSTRAINT properties_status_check CHECK (status::text IN ('draft', 'published', 'sold'))");

        Schema::table('properties', function (Blueprint $table) {
            // When the listing was marked sold/rented — powers the Inventory view.
            $table->timestamp('sold_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('sold_at');
        });

        DB::statement("UPDATE properties SET status = 'draft' WHERE status = 'sold'");
        DB::statement("ALTER TABLE properties DROP CONSTRAINT IF EXISTS properties_status_check");
        DB::statement("ALTER TABLE properties ADD CONSTRAINT properties_status_check CHECK (status::text IN ('draft', 'published'))");
    }
};
