<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unsend, like Messenger:
 *  - unsent_at: "Unsend for everyone". Both people see "… unsent a message"
 *    from then on. The text stays in the database (never shown to users) so
 *    an admin can still review it if someone reports harassment or a scam.
 *  - hidden_for: "Remove for you". User ids who removed the message from their
 *    own view; the other person still sees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('unsent_at')->nullable()->after('read_at');
            $table->jsonb('hidden_for')->nullable()->after('unsent_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn(['unsent_at', 'hidden_for']);
        });
    }
};
