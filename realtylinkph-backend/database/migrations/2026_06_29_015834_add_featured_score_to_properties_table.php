<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Merit-based ranking for the homepage "Featured" row; 0 = not featurable.
            $table->float('featured_score')->default(0)->after('views');
            $table->index('featured_score');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['featured_score']);
            $table->dropColumn('featured_score');
        });
    }
};
