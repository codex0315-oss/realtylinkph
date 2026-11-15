<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Emails are now stored lowercase. Convert existing accounts so an account
 * created as "Juan@Gmail.com" can sign in as "juan@gmail.com" or with Google.
 * If two accounts differ only by case, neither is changed (logged instead), so
 * no account is merged or lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('users')->whereRaw('email <> lower(email)')->get(['id', 'email']);

        foreach ($rows as $row) {
            $lower = mb_strtolower(trim($row->email));
            $taken = DB::table('users')->where('id', '!=', $row->id)->whereRaw('lower(email) = ?', [$lower])->exists();

            if ($taken) {
                Log::warning('Email not lowercased: another account uses the same address', ['user_id' => $row->id]);

                continue;
            }

            DB::table('users')->where('id', $row->id)->update(['email' => $lower]);
        }
    }

    public function down(): void
    {
        // Original letter case isn't kept; nothing to undo.
    }
};
