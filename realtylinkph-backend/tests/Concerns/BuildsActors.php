<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\AgentProfile;
use App\Models\Property;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * The people and things every workflow test needs: a buyer, an approved
 * agent, an admin, and a published listing. Built directly rather than
 * through the API so each test exercises one workflow, not five.
 */
trait BuildsActors
{
    protected function buyer(array $attrs = []): User
    {
        return User::factory()->create(['role_type' => 'buyer', 'email_verified_at' => now(), ...$attrs])->fresh();
    }

    protected function admin(array $attrs = []): User
    {
        return User::factory()->create(['role_type' => 'admin', 'email_verified_at' => now(), ...$attrs])->fresh();
    }

    /** A verified agent: role `agent` plus an approved profile (agent.verified middleware checks both). */
    protected function agent(array $attrs = []): User
    {
        $user = User::factory()->create(['role_type' => 'agent', 'email_verified_at' => now(), ...$attrs]);
        AgentProfile::create([
            'user_id'        => $user->id,
            'applicant_type' => 'broker',
            'prc_number'     => 'PRC-' . $user->id,
            'status'         => 'approved',
            'reviewed_at'    => now(),
        ]);

        return $user->fresh();
    }

    protected function listing(User $agent, array $attrs = []): Property
    {
        return Property::create([
            'agent_id'    => $agent->id,
            'title'       => 'Two-bedroom condo in Cebu IT Park',
            'description' => 'Bright corner unit with a city view.',
            'price'       => 4_500_000,
            'type'        => 'condo',
            'offer_type'  => 'sale',
            'bedrooms'    => 2,
            'bathrooms'   => 1,
            'floor_area'  => 54,
            'address'     => 'Cebu IT Park, Cebu City',
            'lat'         => 10.3300,
            'lng'         => 123.9060,
            'status'      => 'published',
            ...$attrs,
        ]);
    }

    /**
     * Switch the acting user. Guards are reset first: within one test the
     * app instance is reused, so a user resolved (or logged in via a session
     * guard) on an earlier request would otherwise stick to the next one.
     */
    protected function actingAsUser(User $user): static
    {
        $this->asGuest();
        Sanctum::actingAs($user);

        return $this;
    }

    /** Next request is unauthenticated, whatever happened before. */
    protected function asGuest(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse(config('auth.defaults.guard'));
        // Auth::attempt() in /login writes to the (array) session, which the
        // test app keeps between requests; a real API request has no session.
        $this->app['session']->flush();

        return $this;
    }

    /**
     * Point the real `local` disk at a scratch directory so Documents::url()
     * still produces signed /storage URLs (Storage::fake would replace the
     * disk with one that has no serving route, and therefore no signature).
     */
    protected function useScratchDocumentsDisk(): void
    {
        $root = storage_path('framework/testing/documents');
        \Illuminate\Support\Facades\File::deleteDirectory($root);
        \Illuminate\Support\Facades\File::ensureDirectoryExists($root);
        config(['filesystems.disks.local.root' => $root, 'filesystems.documents' => 'local']);
        \Illuminate\Support\Facades\Storage::forgetDisk('local');
    }
}
