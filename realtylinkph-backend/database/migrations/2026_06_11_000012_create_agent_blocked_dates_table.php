<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_blocked_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->date('blocked_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('reason')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->unsignedTinyInteger('recurring_day_of_week')->nullable()->comment('0=Sunday, 6=Saturday');
            $table->timestamps();

            $table->index('agent_id');
            $table->index('blocked_date');
            $table->index(['agent_id', 'blocked_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_blocked_dates');
    }
};
