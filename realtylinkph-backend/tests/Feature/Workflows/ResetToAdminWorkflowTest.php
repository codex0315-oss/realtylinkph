<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Clearing a deployment's demo data: everything goes except one admin, the
 * listing photos go from storage, and the same code never runs twice (Render
 * re-runs the entrypoint on every wake-up).
 */
class ResetToAdminWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    public function test_it_leaves_one_admin_deletes_photos_and_runs_once_per_code(): void
    {
        Storage::fake(Uploads::name());
        $agent   = $this->agent();
        $this->buyer();
        $listing = $this->listing($agent);
        Uploads::disk()->put("properties/{$listing->id}/a.jpg", 'x');
        PropertyPhoto::create(['property_id' => $listing->id, 'url' => "properties/{$listing->id}/a.jpg", 'sort_order' => 1, 'is_360' => false]);

        $this->artisan('app:reset-to-admin', ['code' => 'clean-1', '--email' => 'owner@example.com', '--password' => 'short'])
            ->assertFailed();

        $this->artisan('app:reset-to-admin', ['code' => 'clean-1', '--email' => 'owner@example.com', '--password' => 'longenough1'])
            ->assertSuccessful();

        $this->assertSame(['owner@example.com'], User::pluck('email')->all());
        $this->assertSame('admin', User::first()->role_type);
        $this->assertSame(1, User::first()->id);
        $this->assertSame(0, Property::count());
        Uploads::disk()->assertMissing("properties/{$listing->id}/a.jpg");

        // Same code again (a restart with the setting still on): data made since survives.
        $this->buyer(['email' => 'real@example.com']);
        $this->artisan('app:reset-to-admin', ['code' => 'clean-1', '--email' => 'owner@example.com', '--password' => 'longenough1'])
            ->assertSuccessful();
        $this->assertTrue(User::where('email', 'real@example.com')->exists());

        $this->asGuest()->postJson('/api/login', ['email' => 'owner@example.com', 'password' => 'longenough1'])->assertOk();
    }
}
