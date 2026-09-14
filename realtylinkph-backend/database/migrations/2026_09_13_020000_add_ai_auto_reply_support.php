<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RealtyLink AI answers buyers in chat while the listing's agent is offline.
     *
     * - users.is_system: the AI posts as a real user row (messages.sender_id is a
     *   FK to users), so it needs an account — one that can never log in, list,
     *   or show up in user lists.
     * - messages.is_ai: lets both parties see which bubbles were the AI.
     * - conversations.agent_offline_emailed_at: throttles the "you have a
     *   message while away" email to one per thread per 30 minutes.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('role_type');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_ai')->default(false)->after('body');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('agent_offline_emailed_at')->nullable()->after('agent_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $t) => $t->dropColumn('agent_offline_emailed_at'));
        Schema::table('messages',      fn (Blueprint $t) => $t->dropColumn('is_ai'));
        Schema::table('users',         fn (Blueprint $t) => $t->dropColumn('is_system'));
    }
};
