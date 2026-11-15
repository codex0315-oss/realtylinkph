<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edit and pin, like Messenger.
 *  - edited_at / original_body: the sender changed the text. The first version
 *    is kept for admins only (same policy as unsend); users see "Edited".
 *  - pinned_at / pinned_by_id: either person pinned the message; both see it
 *    in the chat's "Pinned messages" bar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('edited_at')->nullable()->after('read_at');
            $table->text('original_body')->nullable()->after('edited_at');
            $table->timestamp('pinned_at')->nullable()->after('original_body');
            $table->foreignId('pinned_by_id')->nullable()->after('pinned_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pinned_by_id');
            $table->dropColumn(['edited_at', 'original_body', 'pinned_at']);
        });
    }
};
