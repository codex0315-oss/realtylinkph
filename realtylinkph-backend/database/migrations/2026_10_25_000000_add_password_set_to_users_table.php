<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Whether the person knows their password. "Continue with Google" sign-ups get
 * a random one they never see, so "change password" can't work for them until
 * they set one through "Forgot password". RealtyLink AI uses this to explain
 * that instead of showing a form that can only fail.
 *
 * Existing rows default to true: accounts made before this column can't be
 * told apart, and the old behaviour (show the form) is the safe assumption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('password_set')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_set');
        });
    }
};
