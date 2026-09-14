<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('preferred_datetime');
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending');
            $table->string('gcal_event_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('buyer_id');
            $table->index('agent_id');
            $table->index('property_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
