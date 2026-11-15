<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Emails are case-insensitive, so an account created manually can also sign in
 * with "Continue with Google" using the same address, whatever the letter case.
 */
class EmailCaseAndGoogleLinkWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function fakeGoogle(string $email): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'g-token'], 200),
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => $email, 'email_verified' => true, 'name' => 'Juan Dela Cruz'], 200),
        ]);
    }

    public function test_sign_up_stores_the_email_in_lowercase_and_sign_in_ignores_case(): void
    {
        $this->asGuest()->postJson('/api/register', [
            'name' => 'Juan Dela Cruz', 'email' => '  Juan.DelaCruz@Gmail.com ', 'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'role_type' => 'buyer',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'juan.delacruz@gmail.com']);

        $this->asGuest()->postJson('/api/login', ['email' => 'juan.delacruz@gmail.com', 'password' => 'Secret123'])->assertOk();
        $this->asGuest()->postJson('/api/login', ['email' => 'JUAN.DELACRUZ@GMAIL.COM', 'password' => 'Secret123'])->assertOk();

        // The same address in other letters can't create a second account.
        $this->asGuest()->postJson('/api/register', [
            'name' => 'Copy', 'email' => 'juan.delacruz@GMAIL.com', 'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'role_type' => 'buyer',
        ])->assertStatus(422);
    }

    public function test_continue_with_google_signs_in_to_an_account_created_manually(): void
    {
        $this->asGuest()->postJson('/api/register', [
            'name' => 'Juan Dela Cruz', 'email' => 'Juan.DelaCruz@Gmail.com', 'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'role_type' => 'buyer',
        ])->assertCreated();

        $this->fakeGoogle('juan.delacruz@gmail.com');
        $this->asGuest()->postJson('/api/auth/google/callback', ['code' => 'abc', 'intent' => 'login'])->assertOk()
            ->assertJsonPath('data.user.email', 'juan.delacruz@gmail.com')
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertSame(1, User::where('email', 'juan.delacruz@gmail.com')->count(), 'no duplicate account');
    }

    public function test_an_old_mixed_case_account_is_still_found_by_google(): void
    {
        // An account saved before this change, with capitals in the database.
        $user = $this->buyer();
        \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->update(['email' => 'Maria.Santos@Gmail.com']);

        $this->fakeGoogle('maria.santos@gmail.com');
        $this->asGuest()->postJson('/api/auth/google/callback', ['code' => 'abc', 'intent' => 'login'])->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }
}
