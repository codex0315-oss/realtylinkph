<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When someone signs out, they should stop looking "Active now" straight away,
 * not up to two minutes later when their last heartbeat ages out. This is the
 * moment of sign-out; any heartbeat after it (another open tab) brings them back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('signed_out_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('signed_out_at');
        });
    }
};
