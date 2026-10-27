<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * The admin's home page (what needs action + headline numbers) and the Users
 * page's details drawer.
 */
class AdminOverviewWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    public function test_overview_counts_what_needs_action_and_this_weeks_numbers(): void
    {
        $agent = $this->agent();
        $this->listing($agent);
        $this->listing($agent, ['status' => 'draft', 'unpublish_reason' => 'Photos show another brokerage logo.']);

        $applicant = $this->buyer(['name' => 'Waiting Applicant']);
        AgentProfile::create(['user_id' => $applicant->id, 'applicant_type' => 'salesperson', 'prc_number' => 'A-1', 'status' => 'pending']);

        $old = $this->buyer();
        $old->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->actingAsUser($this->admin());
        $res = $this->getJson('/api/admin/overview')->assertOk();

        $res->assertJsonPath('data.attention.applications', 1)
            ->assertJsonPath('data.attention.unpublished', 1)
            ->assertJsonPath('data.attention.hidden_reviews', 0)
            ->assertJsonPath('data.pending_applications.0.name', 'Waiting Applicant')
            ->assertJsonPath('data.numbers.listings.total', 1)
            ->assertJsonPath('data.numbers.agents.total', 1);

        // agent + applicant + old buyer + admin; the 30-day-old buyer isn't "this week".
        $this->assertSame(4, $res->json('data.numbers.users.total'));
        $this->assertSame(3, $res->json('data.numbers.users.week'));
    }

    public function test_user_details_for_the_drawer(): void
    {
        $agent = $this->agent();
        $this->listing($agent);
        $this->listing($agent, ['status' => 'draft']);
        $buyer = $this->buyer(['favorites' => [1, 2, 3]]);

        $this->actingAsUser($this->admin());

        $this->getJson("/api/admin/users/{$agent->id}")->assertOk()
            ->assertJsonPath('data.user.agent_profile.status', 'approved')
            ->assertJsonPath('data.counts.listings', 2)
            ->assertJsonPath('data.counts.published', 1);

        $this->getJson("/api/admin/users/{$buyer->id}")->assertOk()
            ->assertJsonPath('data.counts.saved', 3)
            ->assertJsonPath('data.counts.viewings_booked', 0);

        $this->getJson('/api/admin/users/' . User::realtyAi()->id)->assertNotFound();
    }

    public function test_users_sort_and_quick_filters(): void
    {
        $online = $this->buyer(['name' => 'Online Olive', 'last_seen_at' => now()]);
        $this->buyer(['name' => 'Away Ana', 'last_seen_at' => now()->subDay(), 'email_verified_at' => null]);
        $applicant = $this->buyer(['name' => 'Applying Abe']);
        AgentProfile::create(['user_id' => $applicant->id, 'applicant_type' => 'broker', 'prc_number' => 'B-1', 'status' => 'pending']);
        $admin = $this->admin(['name' => 'Zed Admin']);
        $this->actingAsUser($admin);

        $names = fn (string $q) => collect($this->getJson("/api/admin/users?{$q}")->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Online Olive'], $names('only=online'));
        $this->assertSame(['Away Ana'], $names('only=unverified'));
        $this->assertSame(['Applying Abe'], $names('only=applied'));
        $this->assertSame(['Applying Abe', 'Away Ana', 'Online Olive', 'Zed Admin'], $names('sort=name'));
        $this->assertSame('Online Olive', $names('sort=active')[0]);

        $this->getJson('/api/admin/stats')->assertOk()
            ->assertJsonCount(4, 'data.recent')
            ->assertJsonPath('data.admin_list.0.name', 'Zed Admin');
    }

    public function test_a_new_admin_needs_a_confirmed_password_with_letters_and_numbers(): void
    {
        $this->actingAsUser($this->admin());

        $this->postJson('/api/admin/admins', ['name' => 'New', 'email' => 'new@example.com', 'password' => 'lettersonly', 'password_confirmation' => 'lettersonly'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/admin/admins', ['name' => 'New', 'email' => 'new@example.com', 'password' => 'Secret123', 'password_confirmation' => 'Different1'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/admin/admins', ['name' => 'New', 'email' => 'new@example.com', 'password' => 'Secret123', 'password_confirmation' => 'Secret123'])
            ->assertCreated();
    }

    public function test_applications_by_status_with_counts_and_who_decided(): void
    {
        $older = $this->buyer(['name' => 'First In']);
        $newer = $this->buyer(['name' => 'Second In']);
        $gone  = $this->buyer(['name' => 'Turned Down']);
        $p1 = AgentProfile::create(['user_id' => $older->id, 'applicant_type' => 'broker', 'prc_number' => 'P-1', 'status' => 'pending']);
        $p1->forceFill(['created_at' => now()->subDays(3)])->save();
        AgentProfile::create(['user_id' => $newer->id, 'applicant_type' => 'salesperson', 'prc_number' => 'P-2', 'status' => 'pending']);
        $p3 = AgentProfile::create(['user_id' => $gone->id, 'applicant_type' => 'broker', 'prc_number' => 'P-3', 'status' => 'pending']);

        $admin = $this->admin(['name' => 'Deciding Admin']);
        $this->actingAsUser($admin);
        $this->postJson("/api/admin/agent-applications/{$p3->id}/review", ['action' => 'reject', 'reason' => 'The license photo is too blurry to read.'])->assertOk();

        $this->getJson('/api/admin/applications')->assertOk()
            ->assertJsonPath('data.counts', ['pending' => 2, 'approved' => 0, 'rejected' => 1])
            ->assertJsonPath('data.items.0.user.name', 'First In')   // waited longest first
            ->assertJsonPath('data.items.1.user.name', 'Second In');

        $this->getJson('/api/admin/applications?status=rejected')->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.admin_note', 'The license photo is too blurry to read.')
            ->assertJsonPath('data.items.0.reviewed_by', 'Deciding Admin');

        $this->postJson("/api/admin/agent-applications/{$p1->id}/review", ['action' => 'approve'])->assertOk();
        $this->getJson('/api/admin/applications?status=approved')->assertOk()
            ->assertJsonPath('data.items.0.user.name', 'First In')
            ->assertJsonPath('data.items.0.reviewed_by', 'Deciding Admin')
            ->assertJsonPath('data.counts.pending', 1);
    }

    public function test_listings_tabs_search_filters_and_sort(): void
    {
        $agent = $this->agent(['name' => 'Agent Ana']);
        $this->listing($agent, ['title' => 'Cheap condo', 'price' => 1_000_000])->forceFill(['views' => 5])->save();
        $this->listing($agent, ['title' => 'Big house', 'type' => 'house', 'price' => 9_000_000])->forceFill(['views' => 50])->save();
        $this->listing($agent, ['title' => 'Rental flat', 'offer_type' => 'rent', 'price' => 20_000]);
        $this->listing($agent, ['title' => 'Taken down', 'status' => 'draft', 'unpublish_reason' => 'Photos are of a different unit.']);
        $this->listing($agent, ['title' => 'Half done', 'status' => 'draft']);
        $this->actingAsUser($this->admin());

        $titles = fn (string $q) => collect($this->getJson("/api/admin/properties?{$q}")->assertOk()->json('data'))->pluck('title')->all();

        $this->assertSame(['Taken down'], $titles('status=unpublished'));
        $this->assertSame(['Half done'], $titles('status=draft'));
        $this->assertSame(['Big house'], $titles('type=house'));
        $this->assertSame(['Rental flat'], $titles('offer_type=rent'));
        $this->assertCount(5, $titles('search=agent ana'));            // by agent name
        $this->assertSame('Big house', $titles('status=published&sort=price_desc')[0]);
        $this->assertSame('Big house', $titles('sort=views')[0]);

        $this->getJson('/api/admin/properties/stats')->assertOk()
            ->assertJsonPath('data.counts', ['all' => 5, 'published' => 3, 'unpublished' => 1, 'draft' => 1, 'sold' => 0])
            ->assertJsonPath('data.top_agents.0.name', 'Agent Ana')
            ->assertJsonPath('data.top_agents.0.count', 3)
            ->assertJsonPath('data.flagged_total', 3);   // no photos on any test listing
    }

    public function test_deleting_a_listing_needs_a_reason_and_tells_the_agent(): void
    {
        $agent = $this->agent();
        $listing = $this->listing($agent, ['title' => 'Duplicate post']);
        $this->actingAsUser($this->admin());

        $this->deleteJson("/api/admin/properties/{$listing->id}")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->deleteJson("/api/admin/properties/{$listing->id}", ['reason' => 'This is a duplicate of your other listing.'])->assertOk();

        $this->assertDatabaseMissing('properties', ['id' => $listing->id]);
        $this->assertTrue($agent->notifications()->where('type', 'listing_deleted')->exists());
    }

    public function test_reviews_filters_and_ratings_overview(): void
    {
        $good = $this->agent(['name' => 'Good Agent']);
        $poor = $this->agent(['name' => 'Poor Agent']);
        // One review per buyer and agent, so each review gets its own buyer.
        $make = fn (User $agent, int $rating, bool $visible = true) => \App\Models\AgentReview::create([
            'agent_id' => $agent->id, 'buyer_id' => $this->buyer()->id, 'rating' => $rating, 'review_text' => 'Fine.', 'is_visible' => $visible,
        ]);
        $make($good, 5);
        $make($good, 4);
        $make($poor, 1);
        $make($poor, 2, false);   // hidden: left out of the averages
        $this->actingAsUser($this->admin());

        $ratings = fn (string $q) => collect($this->getJson("/api/admin/reviews?{$q}")->assertOk()->json('data'))->pluck('rating')->all();
        $this->assertSame([2], $ratings('visible=0'));
        $this->assertSame([1, 2], collect($ratings('stars=low'))->sort()->values()->all());
        $this->assertSame([5], $ratings('stars=5'));
        $this->assertCount(2, $ratings('search=poor agent'));
        $this->assertSame(1, $ratings('sort=lowest')[0]);

        $this->getJson('/api/admin/reviews/stats')->assertOk()
            ->assertJsonPath('data.counts', ['all' => 4, 'visible' => 3, 'hidden' => 1])
            ->assertJsonPath('data.average', 3.3)
            ->assertJsonPath('data.spread.5', 1)
            ->assertJsonPath('data.spread.2', 0)
            ->assertJsonPath('data.lowest_agents.0.name', 'Poor Agent')
            ->assertJsonPath('data.lowest_agents.0.rating', 1);
    }

    public function test_activity_filters_by_area_and_time_with_stats(): void
    {
        $admin = $this->admin(['name' => 'Ada Admin']);
        $listing = $this->listing($this->agent());
        \App\Support\AdminAudit::log('listing.unpublished', $listing, $listing->title, ['reason' => 'Wrong photos here.'], $admin);
        \App\Support\AdminAudit::log('user.cover_removed', $admin, 'Someone', [], $admin);
        \App\Support\AdminAudit::log('agent.approved', $listing, 'Old approval', [], $admin);
        \App\Models\AdminAction::latest('id')->first()->forceFill(['created_at' => now()->subDays(40)])->save();
        $this->actingAsUser($admin);

        $labels = fn (string $q) => collect($this->getJson("/api/admin/actions?{$q}")->assertOk()->json('data'))->pluck('label')->all();
        $this->assertSame(['Unpublished listing'], $labels('area=listings'));
        $this->assertSame(['Removed cover photo'], $labels('area=users'));
        $this->assertCount(2, $labels('days=30'));        // the 40-day-old approval drops out

        $this->getJson('/api/admin/actions/stats')->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.week', 2)
            ->assertJsonPath('data.areas.listings', 1)
            ->assertJsonPath('data.areas.agents', 0)
            ->assertJsonPath('data.admins.0.name', 'Ada Admin')
            ->assertJsonPath('data.admins.0.count', 2);
    }

    public function test_check_later_holds_a_pending_application_until_a_decision(): void
    {
        $applicant = $this->buyer();
        $profile = AgentProfile::create(['user_id' => $applicant->id, 'applicant_type' => 'broker', 'prc_number' => 'H-1', 'status' => 'pending']);
        $admin = $this->admin(['name' => 'Hold Admin']);
        $this->actingAsUser($admin);

        $this->postJson("/api/admin/agent-applications/{$profile->id}/hold", ['note' => 'PRC verification site is down.'])->assertOk()
            ->assertJsonPath('data.hold_note', 'PRC verification site is down.')
            ->assertJsonPath('data.held_by', 'Hold Admin')
            ->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('admin_actions', ['action' => 'agent.held', 'subject_id' => $profile->id]);

        // The applicant never sees the hold note.
        $this->actingAsUser($applicant);
        $this->assertNull($this->getJson('/api/me')->json('data.agent_profile.hold_note'));

        // A decision clears it.
        $this->actingAsUser($admin)->postJson("/api/admin/agent-applications/{$profile->id}/review", ['action' => 'approve'])->assertOk();
        $this->assertNull($profile->fresh()->hold_note);
        $this->postJson("/api/admin/agent-applications/{$profile->id}/hold", ['note' => 'Too late now.'])->assertStatus(422);
    }

    public function test_the_main_prc_link_is_the_working_verification_site(): void
    {
        $links = \App\Support\AgentCredentialReference::links('broker');
        $this->assertSame('https://verification.prc.gov.ph', $links[0]['url']);
        $this->assertContains('https://online1.prc.gov.ph/Verification', array_column($links, 'url'));
    }

    public function test_an_admin_opening_a_listing_does_not_count_as_a_view(): void
    {
        $listing = $this->listing($this->agent());

        $this->actingAsUser($this->admin());
        $this->getJson("/api/properties/{$listing->id}")->assertOk();
        $this->assertSame(0, (int) $listing->fresh()->views);

        $this->actingAsUser($this->buyer());
        $this->getJson("/api/properties/{$listing->id}")->assertOk();
        $this->assertSame(1, (int) $listing->fresh()->views);
    }

    public function test_only_admins_can_see_them(): void
    {
        $buyer = $this->buyer();
        $this->actingAsUser($buyer);

        $this->getJson('/api/admin/overview')->assertForbidden();
        $this->getJson('/api/admin/applications')->assertForbidden();
        $this->getJson("/api/admin/users/{$buyer->id}")->assertForbidden();
    }
}
