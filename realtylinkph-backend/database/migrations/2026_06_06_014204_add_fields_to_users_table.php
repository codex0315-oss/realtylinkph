<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('avatar')->nullable()->after('phone');
            $table->enum('role_type', [
                'buyer',
                'ghost_buyer',
                'agent',
                'admin',
                'super_admin',
            ])->default('buyer')->after('avatar');
            // Buyer's saved/favorite property IDs (kept inline to avoid an extra migration).
            $table->json('favorites')->nullable()->after('role_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'avatar', 'role_type', 'favorites']);
        });
    }
};
