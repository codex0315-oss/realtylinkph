<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * How buyers and agents rate RealtyLink PH itself: 1–5 stars and an optional
 * comment, one per person (they can change it later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_ratings');
    }
};
