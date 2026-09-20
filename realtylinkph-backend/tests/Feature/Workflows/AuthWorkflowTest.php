<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/** Feature 1 — register, log in, read the profile, log out. */
class AuthWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    public function test_a_buyer_can_register_log_in_and_out(): void
    {
        Notification::fake();

        $register = $this->postJson('/api/register', [
            'name'                  => 'Maria Santos',
            'email'                 => 'maria@example.com',
            'password'              => 'Secret123',
            'password_confirmation' => 'Secret123',
            'role_type'             => 'buyer',
        ]);

        $register->assertCreated()->assertJsonPath('data.user.email', 'maria@example.com');
        $this->assertNotEmpty($register->json('data.token'));

        $user = User::where('email', 'maria@example.com')->firstOrFail();
        $this->assertSame('buyer', $user->role_type);
        $this->assertNull($user->email_verified_at, 'a fresh account is unverified until the emailed code is entered');
        Notification::assertSentTo($user, QueuedVerifyEmail::class);

        // Log in with the password, then use the token.
        $login = $this->postJson('/api/login', ['email' => 'maria@example.com', 'password' => 'Secret123']);
        $login->assertOk();
        $token = $login->json('data.token');

        $this->asGuest()->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Maria Santos');

        $this->asGuest()->withToken($token)->postJson('/api/logout')->assertOk();

        // The token is dead after logout.
        $this->asGuest()->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_wrong_password_is_rejected_and_registration_is_validated(): void
    {
        $this->buyer(['email' => 'ana@example.com', 'password' => 'Correct123']);

        $this->postJson('/api/login', ['email' => 'ana@example.com', 'password' => 'nope'])
            ->assertUnauthorized();

        $this->postJson('/api/register', [
            'name' => 'X', 'email' => 'ana@example.com', 'password' => 'short', 'password_confirmation' => 'short', 'role_type' => 'buyer',
        ])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);

        // Nobody can self-register as an admin.
        $this->postJson('/api/register', [
            'name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'role_type' => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors(['role_type']);
    }

    public function test_only_admins_can_reach_the_admin_api(): void
    {
        $this->actingAsUser($this->buyer())->getJson('/api/admin/users')->assertForbidden();
        $this->actingAsUser($this->agent())->getJson('/api/admin/users')->assertForbidden();
        $this->actingAsUser($this->admin())->getJson('/api/admin/users')->assertOk();
    }
}
