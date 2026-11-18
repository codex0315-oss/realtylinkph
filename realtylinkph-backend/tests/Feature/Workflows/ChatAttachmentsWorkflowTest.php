<?php

declare(strict_types=1);

namespace Tests\Feature\Workflows;

use App\Events\MessageSent;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsActors;
use Tests\TestCase;

/** Buyers and agents send photos, videos and files in chat. */
class ChatAttachmentsWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsActors;

    private User $buyer;
    private User $agent;
    private int $conv;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([MessageSent::class]);
        Storage::fake(config('filesystems.uploads'));
        $this->agent = $this->agent(['last_seen_at' => now()]);   // online: no AI auto-reply here
        $this->buyer = $this->buyer();
        $this->conv  = $this->actingAsUser($this->buyer)->postJson("/api/properties/{$this->listing($this->agent)->id}/conversations")->json('data.id');
    }

    /** A real temp file, so the type is read from its content like in production. */
    private function realFile(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'chat');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function send(User $as, array $data)
    {
        return $this->actingAsUser($as)->post("/api/conversations/{$this->conv}/messages", $data, ['Accept' => 'application/json']);
    }

    public function test_a_buyer_sends_photos_with_text_and_the_agent_sees_them(): void
    {
        $res = $this->send($this->buyer, ['body' => 'Here is the view from my unit', 'files' => [
            UploadedFile::fake()->image('view.jpg', 2400, 1600),
            UploadedFile::fake()->image('kitchen.png', 800, 600),
        ]])->assertCreated();

        $res->assertJsonCount(2, 'data.attachments')->assertJsonPath('data.attachments.0.kind', 'image')
            ->assertJsonPath('data.attachments.0.name', 'view.jpg')->assertJsonPath('data.attachment_summary', '📷 2 photos');
        $this->assertNotNull($res->json('data.attachments.0.thumb_url'));
        $this->assertSame(1600, MessageAttachment::first()->width, 'resized to 1600 px like listing photos');
        $this->assertStringStartsWith("chat/{$this->conv}/", MessageAttachment::first()->path);

        $this->actingAsUser($this->agent)->getJson("/api/conversations/{$this->conv}/messages")
            ->assertJsonPath('data.0.attachments.1.name', 'kitchen.png');
        Event::assertDispatched(MessageSent::class, fn ($e) => count($e->broadcastWith()['attachments']) === 2);
    }

    public function test_an_agent_sends_a_pdf_and_a_video_without_text(): void
    {
        $this->send($this->agent, ['files' => [
            $this->realFile('Reservation form.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << >>\n%%EOF"),
            $this->realFile('tour.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 64)),
        ]])->assertCreated()
            ->assertJsonPath('data.attachments.0.kind', 'file')
            ->assertJsonPath('data.attachments.1.kind', 'video')
            ->assertJsonPath('data.attachment_summary', '🎥 Video, 📄 File');

        // The buyer's bell shows what was sent, not an empty preview.
        $data = json_decode((string) DB::table('notifications')->where('notifiable_id', $this->buyer->id)->where('type', 'new_message')->value('data'), true);
        $this->assertSame('🎥 Video, 📄 File', $data['preview']);
    }

    public function test_a_renamed_program_is_refused_whatever_its_name(): void
    {
        $this->send($this->buyer, ['files' => [$this->realFile('photo.jpg', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF" . str_repeat("\x00", 200) . 'This program cannot be run in DOS mode')]])
            ->assertStatus(422)->assertJsonValidationErrors('files');
        $this->assertSame(0, MessageAttachment::count());
    }

    public function test_limits_on_count_and_size_and_an_empty_message(): void
    {
        $six = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 50, 50), range(1, 6));
        $this->send($this->buyer, ['files' => $six])->assertStatus(422)->assertJsonValidationErrors('files');

        $big = $this->realFile('big.pdf', '%PDF-1.4 ' . str_repeat('x', 11 * 1024 * 1024));
        $this->send($this->buyer, ['files' => [$big]])->assertStatus(422)
            ->assertJsonFragment(['“big.pdf” is too big. Files can be up to 10 MB.']);

        $this->send($this->buyer, ['body' => ''])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_unsending_deletes_the_files_for_both_people(): void
    {
        $id = $this->send($this->buyer, ['files' => [UploadedFile::fake()->image('id.jpg', 600, 400)]])->json('data.id');
        $a = MessageAttachment::first();
        Storage::disk(config('filesystems.uploads'))->assertExists([$a->path, $a->thumb_path]);

        $this->actingAsUser($this->buyer)->postJson("/api/messages/{$id}/unsend")->assertOk()->assertJsonCount(0, 'data.attachments');

        Storage::disk(config('filesystems.uploads'))->assertMissing([$a->path, $a->thumb_path]);
        $this->assertSame(0, MessageAttachment::count());
    }

    /** Text then a file in the same second must come back in the order they were sent. */
    public function test_messages_sent_in_the_same_second_keep_their_order(): void
    {
        $this->travelTo(now()->startOfSecond());
        $first  = $this->send($this->buyer, ['body' => 'Photos coming'])->json('data.id');
        $second = $this->send($this->buyer, ['files' => [UploadedFile::fake()->image('a.jpg', 50, 50)]])->json('data.id');
        $third  = $this->send($this->buyer, ['body' => 'and one more thing'])->json('data.id');

        $ids = collect($this->actingAsUser($this->agent)->getJson("/api/conversations/{$this->conv}/messages")->json('data'))->pluck('id')->all();
        $this->assertSame([$third, $second, $first], $ids, 'newest first, ties broken by send order');
    }

    public function test_someone_outside_the_chat_cannot_send_files_into_it(): void
    {
        $this->send($this->buyer(), ['files' => [UploadedFile::fake()->image('x.jpg', 50, 50)]])->assertForbidden();
    }
}
