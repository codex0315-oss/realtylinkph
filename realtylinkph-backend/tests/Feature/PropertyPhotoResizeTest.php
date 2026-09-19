<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\PropertyPhotoResource;
use App\Models\Property;
use App\Models\User;
use App\Services\PropertyPhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PropertyPhotoResizeTest extends TestCase
{
    use RefreshDatabase;

    private function property(): Property
    {
        $agent = User::factory()->create(['role_type' => 'agent']);

        return Property::create([
            'agent_id' => $agent->id, 'title' => 'T', 'description' => '', 'price' => 1,
            'type' => 'house', 'offer_type' => 'sale', 'address' => 'x',
        ]);
    }

    /** A 4000×3000 JPEG like a phone produces. */
    private function bigPhoto(int $w = 4000, int $h = 3000): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        // Noise so the JPEG doesn't compress to nothing and the size check means something.
        for ($i = 0; $i < 300; $i++) {
            $x = rand(0, $w - 200);
            $y = rand(0, $h - 200);
            imagefilledrectangle($im, $x, $y, $x + rand(20, 200), $y + rand(20, 200), imagecolorallocate($im, rand(0, 255), rand(0, 255), rand(0, 255)));
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ph') . '.jpg';
        imagejpeg($im, $tmp, 95);

        return new UploadedFile($tmp, 'IMG_0001.jpg', 'image/jpeg', null, true);
    }

    public function test_upload_stores_a_capped_main_image_and_a_thumbnail(): void
    {
        Storage::fake('public');
        config(['filesystems.uploads' => 'public']);

        $property = $this->property();
        $file     = $this->bigPhoto();
        $original = filesize($file->getRealPath());

        $photo = app(PropertyPhotoService::class)->upload($property, $file);

        Storage::disk('public')->assertExists($photo->url);
        Storage::disk('public')->assertExists($photo->thumb_url);
        $this->assertStringEndsWith('-thumb.jpg', $photo->thumb_url);

        [$mw, $mh] = getimagesizefromstring(Storage::disk('public')->get($photo->url));
        [$tw, $th] = getimagesizefromstring(Storage::disk('public')->get($photo->thumb_url));

        $this->assertSame(1600, $mw);
        $this->assertSame(1200, $mh);
        $this->assertSame(640, $tw);
        $this->assertSame(480, $th);
        $this->assertLessThan($original, Storage::disk('public')->size($photo->url));
        $this->assertLessThan(Storage::disk('public')->size($photo->url), Storage::disk('public')->size($photo->thumb_url));
    }

    public function test_portrait_photo_is_capped_on_its_long_edge(): void
    {
        Storage::fake('public');
        config(['filesystems.uploads' => 'public']);

        $photo = app(PropertyPhotoService::class)->upload($this->property(), $this->bigPhoto(3000, 4000));
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($photo->url));

        $this->assertSame(1200, $w);
        $this->assertSame(1600, $h);
    }

    public function test_panorama_keeps_original_bytes_but_gets_a_thumbnail(): void
    {
        Storage::fake('public');
        config(['filesystems.uploads' => 'public']);

        $file  = $this->bigPhoto(4000, 2000);
        $bytes = file_get_contents($file->getRealPath());
        $photo = app(PropertyPhotoService::class)->upload($this->property(), $file, is360: true);

        $this->assertSame($bytes, Storage::disk('public')->get($photo->url));
        Storage::disk('public')->assertExists($photo->thumb_url);
        [$tw] = getimagesizefromstring(Storage::disk('public')->get($photo->thumb_url));
        $this->assertSame(640, $tw);
    }

    public function test_small_photo_is_not_upscaled(): void
    {
        Storage::fake('public');
        config(['filesystems.uploads' => 'public']);

        $photo = app(PropertyPhotoService::class)->upload($this->property(), $this->bigPhoto(800, 600));
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($photo->url));
        [$tw]    = getimagesizefromstring(Storage::disk('public')->get($photo->thumb_url));

        $this->assertSame([800, 600], [$w, $h]);
        $this->assertSame(640, $tw);
    }

    public function test_undecodable_upload_is_stored_untouched(): void
    {
        Storage::fake('public');
        config(['filesystems.uploads' => 'public']);

        $tmp = tempnam(sys_get_temp_dir(), 'ph') . '.jpg';
        file_put_contents($tmp, 'definitely not an image');
        $file = new UploadedFile($tmp, 'broken.jpg', 'image/jpeg', null, true);

        $photo = app(PropertyPhotoService::class)->upload($this->property(), $file);

        $this->assertNull($photo->thumb_url);
        $this->assertSame('definitely not an image', Storage::disk('public')->get($photo->url));
        // Resource falls back to the main image so cards always have something.
        $this->assertSame(
            PropertyPhotoResource::make($photo)->resolve()['url'],
            PropertyPhotoResource::make($photo)->resolve()['thumb_url'],
        );
    }

    public function test_delete_removes_both_files(): void
    {
        Storage::fake('public');
        config(['filesystems.uploads' => 'public']);

        $service = app(PropertyPhotoService::class);
        $photo   = $service->upload($this->property(), $this->bigPhoto(1000, 800));
        $service->delete($photo);

        Storage::disk('public')->assertMissing($photo->url);
        Storage::disk('public')->assertMissing($photo->thumb_url);
        $this->assertDatabaseMissing('property_photos', ['id' => $photo->id]);
    }
}
