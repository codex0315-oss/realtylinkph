<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Either participant can remove a conversation from *their* inbox. It is a
     * per-side flag rather than a hard delete: one person tidying their list
     * must not erase the other person's record of what was said. A new message
     * from either side clears both flags so the thread resurfaces.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('buyer_deleted_at')->nullable()->after('last_message_at');
            $table->timestamp('agent_deleted_at')->nullable()->after('buyer_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['buyer_deleted_at', 'agent_deleted_at']);
        });
    }
};
