<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\AdminAction;
use App\Models\Property;
use App\Models\PropertyFlag;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Notifications\ViewingSanctionNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * RealtyLink AI checks listings (contact details, payment requests); buyers
 * can report them; an admin decides. Duplicates are not flagged (PKS).
 */
class ListingIntegrityWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = $this->admin();
    }

    private function gemini(bool $payment, bool $offPlatform = false): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(
            ['payment_request' => $payment, 'off_platform' => $offPlatform, 'reason' => $payment ? 'Asks for a GCash reservation fee before the viewing.' : 'Lists a phone number.']
        )]]]]]])]);
    }

    /** A complete draft, ready to publish, with one photo (fingerprint given). */
    private function draft(User $agent, array $attrs = [], string $phash = 'ffff0000ffff0000'): Property
    {
        $p = $this->listing($agent, ['status' => 'draft', ...$attrs]);
        PropertyPhoto::create(['property_id' => $p->id, 'url' => "properties/{$p->id}/a.jpg", 'thumb_url' => "properties/{$p->id}/a-thumb.jpg", 'is_360' => false, 'sort_order' => 1])
            ->forceFill(['phash' => $phash])->save();

        return $p;
    }

    private function publish(User $agent, Property $p)
    {
        return $this->actingAsUser($agent)->postJson("/api/properties/{$p->id}/publish");
    }

    private function adminBells(): int
    {
        return DB::table('notifications')->where('notifiable_id', $this->admin->id)->where('type', 'listing_flagged')->count();
    }

    public function test_an_ordinary_listing_is_not_flagged_and_uses_no_ai(): void
    {
        Http::fake();
        $agent = $this->agent();
        $this->publish($agent, $this->draft($agent))->assertOk();

        $this->assertSame(0, PropertyFlag::count());
        Http::assertNothingSent();
    }

    /**
     * Agents from the same developer seminar (PKS) post the same project with the
     * developer's photos, address and price. That is normal, so it is not flagged.
     */
    public function test_the_same_project_posted_by_several_agents_is_not_flagged(): void
    {
        Http::fake();
        $attrs = ['address' => 'Amaia Steps Mandaue, Mandaue City', 'price' => 4_500_000];
        foreach (range(1, 3) as $_) {
            $agent = $this->agent();
            $this->publish($agent, $this->draft($agent, $attrs, 'abcdef0123456789'))->assertOk();
        }

        $this->assertSame(0, PropertyFlag::count());
        $this->assertSame(0, $this->adminBells());
    }

    public function test_contact_details_are_flagged_but_the_listing_stays_live(): void
    {
        $this->gemini(false, true);
        $agent = $this->agent();
        $p = $this->draft($agent, ['description' => 'Spacious unit. Text me at 0917 123 4567 for a faster reply.']);
        $this->publish($agent, $p)->assertOk();

        $flag = PropertyFlag::where('property_id', $p->id)->first();
        $this->assertSame(['contact_info'], $flag->reasons);
        $this->assertSame('published', $p->fresh()->status);
    }

    public function test_a_payment_request_pauses_the_listing_until_an_admin_clears_it(): void
    {
        $this->gemini(true);
        $agent = $this->agent();
        $p = $this->draft($agent, ['description' => 'Nice house. Send the reservation fee to my GCash first to secure your viewing slot.']);
        $this->publish($agent, $p)->assertOk();

        $p->refresh();
        $this->assertSame('draft', $p->status);
        $this->assertStringContainsString('Paused for review', $p->unpublish_reason);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $agent->id)->where('type', 'listing_paused')->count());

        // The agent can't put it back by publishing again.
        $this->publish($agent, $p)->assertStatus(422);

        // The admin sees it first in the queue and clears it: live again, agent told.
        $this->actingAsUser($this->admin)->getJson('/api/admin/listing-flags')->assertOk()
            ->assertJsonPath('data.0.property.id', $p->id)->assertJsonPath('data.0.paused', true)
            ->assertJsonPath('data.0.flags.0.reasons.0.code', 'payment_request');
        $this->postJson("/api/admin/listing-flags/{$p->id}/clear")->assertOk();

        $this->assertSame('published', $p->fresh()->status);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $agent->id)->where('type', 'listing_restored')->count());
        $this->assertTrue(AdminAction::where('action', 'listing.flags_cleared')->exists());
    }

    public function test_without_ai_the_pattern_check_still_catches_a_payment_request(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500), 'api.groq.com/*' => Http::response([], 500)]);
        $agent = $this->agent();
        $p = $this->draft($agent, ['description' => 'Reservation fee of 5,000 via GCash before the viewing to reserve the unit.']);
        $this->publish($agent, $p)->assertOk();

        $this->assertTrue(PropertyFlag::where('property_id', $p->id)->value('paused'));
    }

    public function test_buyers_report_a_listing_and_a_take_down_closes_the_flags(): void
    {
        $agent = $this->agent();
        $p = $this->listing($agent);
        $buyer = $this->buyer();

        $this->actingAsUser($buyer)->postJson("/api/properties/{$p->id}/report", ['reason' => 'sold'])->assertCreated();
        $this->postJson("/api/properties/{$p->id}/report", ['reason' => 'other'])->assertStatus(422);   // "other" needs a note
        $this->actingAsUser($agent)->postJson("/api/properties/{$p->id}/report", ['reason' => 'scam'])->assertStatus(422); // not your own
        $this->assertSame(1, $this->adminBells());

        $this->actingAsUser($this->admin)->getJson('/api/admin/listing-flags')->assertJsonPath('data.0.reports', 1)
            ->assertJsonPath('data.0.flags.0.reasons.0.code', 'report_sold');
        $this->getJson('/api/admin/properties/stats')->assertJsonPath('data.counts.review', 1);

        $this->postJson("/api/admin/properties/{$p->id}/unpublish", ['reason' => 'Buyers report it has already been sold.'])->assertOk();
        $this->assertSame('actioned', PropertyFlag::where('property_id', $p->id)->value('status'));
        $this->getJson('/api/admin/listing-flags')->assertJsonCount(0, 'data');
    }

    public function test_admins_get_one_daily_email_summary(): void
    {
        $agent = $this->agent();
        $p = $this->listing($agent);
        $this->actingAsUser($this->buyer())->postJson("/api/properties/{$p->id}/report", ['reason' => 'wrong_details'])->assertCreated();

        $this->artisan('listings:flag-digest')->assertSuccessful();
        Notification::assertSentTo($this->admin, ViewingSanctionNotice::class, fn ($n) => str_contains($n->subject, '1 listing needs your review'));
    }
}
