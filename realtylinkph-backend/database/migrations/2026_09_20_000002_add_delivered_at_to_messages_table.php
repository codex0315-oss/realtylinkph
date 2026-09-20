<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-message delivery receipts. `read_at` already records "seen" (the
 * recipient had the thread open); `delivered_at` records the weaker
 * "their app received it" so the sender's ticks can show ✓ sent → ✓✓
 * delivered → ✓✓ seen, and survive a refresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('delivered_at')->nullable()->after('is_read');
        });

        // Anything already read was, by definition, delivered.
        \Illuminate\Support\Facades\DB::statement('UPDATE messages SET delivered_at = read_at WHERE read_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('delivered_at');
        });
    }
};
