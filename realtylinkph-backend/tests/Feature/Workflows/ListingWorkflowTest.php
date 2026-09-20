<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Jobs\GeocodeProperty;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Features 3, 4 & 13 — an agent starts a draft with nothing filled in, adds
 * a photo (resized) and a panorama, cannot publish until the listing is
 * complete, then publishes and the listing appears in public search.
 */
class ListingWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['filesystems.uploads' => 'public']);
        Queue::fake([GeocodeProperty::class]);
    }

    public function test_draft_to_published_listing(): void
    {
        $agent = $this->agent();

        // 1. The wizard autosaves an empty draft the moment the agent starts.
        $draft = $this->actingAsUser($agent)->postJson('/api/properties', [])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_complete', false);
        $id = $draft->json('data.id');

        // Drafts are invisible to the public.
        $this->asGuest()->getJson("/api/properties/{$id}")->assertNotFound();
        $this->assertSame(0, count($this->asGuest()->getJson('/api/properties')->json('data')));

        // 2. Photos upload straight away — resized, with a thumbnail.
        $photo = $this->actingAsUser($agent)->post("/api/properties/{$id}/photos", [
            'photo' => UploadedFile::fake()->image('front.jpg', 3000, 2000),
        ])->assertCreated();
        $this->assertStringContainsString('-thumb.jpg', $photo->json('data.thumb_url'));
        $this->assertFalse($photo->json('data.is_360'));

        $this->actingAsUser($agent)->post("/api/properties/{$id}/photos", [
            'photo'  => UploadedFile::fake()->image('tour.jpg', 2000, 1000),
            'is_360' => 1,
        ])->assertCreated()->assertJsonPath('data.is_360', true);

        // 3. Publishing an incomplete draft is refused with a useful message.
        $this->actingAsUser($agent)->postJson("/api/properties/{$id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['listing']);

        // 4. Fill it in.
        $this->actingAsUser($agent)->putJson("/api/properties/{$id}", [
            'title'      => 'Studio unit near Ayala Center',
            'price'      => 2_800_000,
            'type'       => 'condo',
            'offer_type' => 'sale',
            'bedrooms'   => 1,
            'bathrooms'  => 1,
            'floor_area' => 24,
            'address'    => 'Cebu Business Park, Cebu City',
            'lat'        => 10.3187,
            'lng'        => 123.9051,
        ])->assertOk()->assertJsonPath('data.is_complete', true);

        // 5. Publish.
        $this->actingAsUser($agent)->postJson("/api/properties/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        // 6. Now it is public, searchable, and its photos come with thumbnails.
        $this->asGuest()->getJson("/api/properties/{$id}")->assertOk()->assertJsonPath('data.title', 'Studio unit near Ayala Center');

        $search = $this->asGuest()->getJson('/api/properties?type=condo&offer_type=sale&max_price=3000000')->assertOk();
        $this->assertSame([$id], array_column($search->json('data'), 'id'));
        $this->assertCount(2, $search->json('data.0.photos'));

        $this->asGuest()->getJson('/api/properties?type=house')->assertOk()->assertJsonCount(0, 'data');

        // 7. Viewing it counts a view for everyone but the owner.
        $before = Property::find($id)->views;
        $this->asGuest()->getJson("/api/properties/{$id}");
        $this->actingAsUser($agent)->getJson("/api/properties/{$id}");
        $this->assertSame($before + 1, Property::find($id)->views);
    }

    public function test_only_the_owner_or_an_admin_can_change_a_listing(): void
    {
        $owner = $this->agent();
        $other = $this->agent();
        $listing = $this->listing($owner);

        $this->actingAsUser($other)->putJson("/api/properties/{$listing->id}", ['title' => 'Hijacked'])->assertForbidden();
        $this->actingAsUser($other)->deleteJson("/api/properties/{$listing->id}")->assertForbidden();
        $this->actingAsUser($this->buyer())->postJson("/api/properties/{$listing->id}/photos")->assertForbidden();

        $this->actingAsUser($owner)->putJson("/api/properties/{$listing->id}", ['title' => 'Renamed by owner'])
            ->assertOk()->assertJsonPath('data.title', 'Renamed by owner');
    }
}
