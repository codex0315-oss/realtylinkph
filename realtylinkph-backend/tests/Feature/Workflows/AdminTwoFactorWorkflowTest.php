<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\User;
use App\Notifications\AdminSignInCode;
use App\Services\AdminTwoFactorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Admins sign in in two steps: the password, then a 6-digit code emailed to
 * them. The token only exists after the second step.
 */
class AdminTwoFactorWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function adminWithPassword(): User
    {
        return $this->admin(['email' => 'boss@example.com', 'password' => bcrypt('Secret123')]);
    }

    /** Sign in with the password and return [challenge, code] (the code read from the email). */
    private function startSignIn(User $admin): array
    {
        $code = null;
        $res  = $this->asGuest()->postJson('/api/login', ['email' => $admin->email, 'password' => 'Secret123'])->assertOk();

        Notification::assertSentTo($admin, AdminSignInCode::class, function (AdminSignInCode $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return [$res->json('data.two_factor.challenge'), $code, $res];
    }

    public function test_an_admin_password_gets_a_challenge_not_a_token(): void
    {
        Notification::fake();
        $admin = $this->adminWithPassword();

        [$challenge, $code, $res] = $this->startSignIn($admin);

        $res->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.user')
            ->assertJsonPath('data.two_factor.email', 'bo***@example.com');
        $this->assertNotEmpty($challenge);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $this->assertSame(0, $admin->tokens()->count(), 'no token before the code is entered');
    }

    public function test_the_right_code_signs_in_once_and_only_once(): void
    {
        Notification::fake();
        $admin = $this->adminWithPassword();
        [$challenge, $code] = $this->startSignIn($admin);

        $this->asGuest()->postJson('/api/login/two-factor', ['challenge' => $challenge, 'code' => $code])
            ->assertOk()->assertJsonPath('data.user.email', 'boss@example.com')->assertJsonStructure(['data' => ['token']]);
        $this->assertSame(1, $admin->tokens()->count());

        // The same code cannot be used again.
        $this->asGuest()->postJson('/api/login/two-factor', ['challenge' => $challenge, 'code' => $code])
            ->assertStatus(422)->assertJsonPath('errors.code', 'expired');
    }

    public function test_wrong_codes_are_refused_and_five_of_them_kill_the_challenge(): void
    {
        Notification::fake();
        $admin = $this->adminWithPassword();
        [$challenge, $code] = $this->startSignIn($admin);
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, AdminTwoFactorService::MAX_ATTEMPTS - 1) as $i) {
            $this->asGuest()->postJson('/api/login/two-factor', ['challenge' => $challenge, 'code' => $wrong])
                ->assertStatus(422)->assertJsonPath('errors.code', 'wrong');
        }
        $this->asGuest()->postJson('/api/login/two-factor', ['challenge' => $challenge, 'code' => $wrong])
            ->assertStatus(422)->assertJsonPath('errors.code', 'locked');

        // Even the right code is useless now.
        $this->asGuest()->postJson('/api/login/two-factor', ['challenge' => $challenge, 'code' => $code])
            ->assertStatus(422)->assertJsonPath('errors.code', 'expired');
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_a_code_expires_and_malformed_input_is_rejected(): void
    {
        Notification::fake();
        $admin = $this->adminWithPassword();
        [$challenge, $code] = $this->startSignIn($admin);

        $this->asGuest()->postJson('/api/login/two-factor', ['challenge' => $challenge, 'code' => '12ab56'])->assertStatus(422);
        $this->asGuest()->postJson('/api/login/two-factor', ['challenge' => 'made-up', 'code' => $code])->assertStatus(422)->assertJsonPath('errors.code', 'expired');

        Carbon::setTestNow(now()->addMinutes(AdminTwoFactorService::TTL_MINUTES + 1));
        $this->asGuest()->postJson('/api/login/two-factor', ['challenge' => $challenge, 'code' => $code])
            ->assertStatus(422)->assertJsonPath('errors.code', 'expired');
    }

    public function test_a_new_code_can_be_requested_but_not_straight_away(): void
    {
        Notification::fake();
        $admin = $this->adminWithPassword();
        [$challenge] = $this->startSignIn($admin);

        $this->asGuest()->postJson('/api/login/two-factor/resend', ['challenge' => $challenge])
            ->assertStatus(429)->assertJsonPath('errors.code', 'too_soon');

        Carbon::setTestNow(now()->addSeconds(AdminTwoFactorService::RESEND_AFTER_SECONDS + 1));
        $this->asGuest()->postJson('/api/login/two-factor/resend', ['challenge' => $challenge])->assertOk();
        Notification::assertSentToTimes($admin, AdminSignInCode::class, 2);
    }

    public function test_buyers_and_agents_still_sign_in_with_just_the_password(): void
    {
        Notification::fake();
        $buyer = $this->buyer(['email' => 'b@example.com', 'password' => bcrypt('Secret123')]);

        $this->asGuest()->postJson('/api/login', ['email' => 'b@example.com', 'password' => 'Secret123'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
        Notification::assertNotSentTo($buyer, AdminSignInCode::class);
    }

    public function test_the_step_can_be_switched_off_with_the_setting(): void
    {
        Notification::fake();
        config(['auth.admin_two_factor' => false]);
        $this->adminWithPassword();

        $this->asGuest()->postJson('/api/login', ['email' => 'boss@example.com', 'password' => 'Secret123'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_a_wrong_password_does_not_start_a_challenge(): void
    {
        Notification::fake();
        $admin = $this->adminWithPassword();

        $this->asGuest()->postJson('/api/login', ['email' => $admin->email, 'password' => 'nope'])->assertStatus(401);
        Notification::assertNothingSent();
    }

    /** Finish the code step with "trust this device" ticked; returns the device key. */
    private function trustedKey(User $admin): string
    {
        [$challenge, $code] = $this->startSignIn($admin);

        return $this->asGuest()->postJson('/api/login/two-factor', ['challenge' => $challenge, 'code' => $code, 'remember' => true])
            ->assertOk()->assertJsonPath('data.device_days', AdminTwoFactorService::TRUST_DAYS)->json('data.device_token');
    }

    public function test_without_the_tick_box_no_device_is_trusted(): void
    {
        Notification::fake();
        $admin = $this->adminWithPassword();
        [$challenge, $code] = $this->startSignIn($admin);

        $this->asGuest()->postJson('/api/login/two-factor', ['challenge' => $challenge, 'code' => $code])
            ->assertOk()->assertJsonPath('data.device_token', null);
        $this->assertDatabaseCount('admin_trusted_devices', 0);
    }

    public function test_a_trusted_browser_skips_the_code_but_still_needs_the_password(): void
    {
        Notification::fake();
        $admin = $this->adminWithPassword();
        $key   = $this->trustedKey($admin);
        $this->assertNotEmpty($key);
        $this->assertDatabaseMissing('admin_trusted_devices', ['token_hash' => $key]);

        // Same browser, right password: straight in, no new email.
        $this->asGuest()->postJson('/api/login', ['email' => $admin->email, 'password' => 'Secret123', 'device_token' => $key])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
        Notification::assertSentToTimes($admin, AdminSignInCode::class, 1);

        // The device key alone is worthless.
        $this->asGuest()->postJson('/api/login', ['email' => $admin->email, 'password' => 'wrong', 'device_token' => $key])->assertStatus(401);
    }

    public function test_an_unknown_expired_or_someone_elses_device_key_still_gets_the_code(): void
    {
        Notification::fake();
        $admin = $this->adminWithPassword();
        $other = $this->admin(['email' => 'other@example.com', 'password' => bcrypt('Secret123')]);
        $key   = $this->trustedKey($admin);

        // Not a key we issued.
        $this->asGuest()->postJson('/api/login', ['email' => $admin->email, 'password' => 'Secret123', 'device_token' => 'made-up'])
            ->assertOk()->assertJsonPath('data.two_factor.email', 'bo***@example.com');

        // A different admin's key cannot be used here.
        $this->asGuest()->postJson('/api/login', ['email' => $other->email, 'password' => 'Secret123', 'device_token' => $key])
            ->assertOk()->assertJsonStructure(['data' => ['two_factor']]);

        // Past 30 days it lapses.
        Carbon::setTestNow(now()->addDays(AdminTwoFactorService::TRUST_DAYS + 1));
        $this->asGuest()->postJson('/api/login', ['email' => $admin->email, 'password' => 'Secret123', 'device_token' => $key])
            ->assertOk()->assertJsonStructure(['data' => ['two_factor']]);
    }

    public function test_changing_the_password_makes_every_browser_start_over(): void
    {
        Notification::fake();
        $admin = $this->adminWithPassword();
        $key   = $this->trustedKey($admin);

        $this->actingAsUser($admin)->postJson('/api/me/password', [
            'current_password' => 'Secret123', 'password' => 'Better456pass', 'password_confirmation' => 'Better456pass',
        ])->assertOk();

        $this->assertDatabaseCount('admin_trusted_devices', 0);
        $this->asGuest()->postJson('/api/login', ['email' => $admin->email, 'password' => 'Better456pass', 'device_token' => $key])
            ->assertOk()->assertJsonStructure(['data' => ['two_factor']]);
    }
}
