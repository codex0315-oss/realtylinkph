<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Models\PropertyPhoto;
use App\Support\Uploads;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/**
 * Storage that refuses writes (expired keys, a full bucket). The disks don't
 * throw, so this used to save photo rows for files that were never stored:
 * listings showed "8 photos" and no picture. Now the upload fails out loud,
 * and the AI description still works from the listing's details.
 */
class StorageFailureWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    private function refusingStorage(): void
    {
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturn(false);
        $disk->shouldReceive('putFileAs')->andReturn(false);
        $disk->shouldReceive('get')->andThrow(new \RuntimeException('storage down'));
        Storage::set(Uploads::name(), $disk);
    }

    public function test_a_refused_photo_upload_is_an_error_and_saves_nothing(): void
    {
        $agent   = $this->agent();
        $listing = $this->listing($agent, ['status' => 'draft']);
        $this->refusingStorage();

        $this->actingAsUser($agent)
            ->postJson("/api/properties/{$listing->id}/photos", ['photo' => UploadedFile::fake()->image('living.jpg', 1200, 900)])
            ->assertStatus(503);

        $this->assertSame(0, PropertyPhoto::count());
    }

    public function test_the_ai_description_skips_photos_storage_cannot_read(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'A bright condo.']]]]]])]);
        $agent   = $this->agent();
        $listing = $this->listing($agent, ['status' => 'draft']);
        PropertyPhoto::create(['property_id' => $listing->id, 'url' => "properties/{$listing->id}/gone.jpg", 'sort_order' => 1, 'is_360' => false]);
        $this->refusingStorage();

        $this->actingAsUser($agent)
            ->postJson('/api/ai/generate-description', ['property_id' => $listing->id, 'type' => 'condo', 'title' => 'Condo'])
            ->assertOk()
            ->assertJsonPath('data.description', 'A bright condo.');
    }
}
