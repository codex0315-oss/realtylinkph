<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyFlag;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Support\AdminAudit;
use App\Support\ImageHash;
use App\Support\Uploads;
use Illuminate\Support\Facades\DB;

/**
 * RealtyLink AI's listing checks. It finds, an administrator decides.
 *
 *   duplicate_photos   a photo already used on another listing (picture fingerprint)
 *   duplicate_address  same address and a similar price under another agent
 *   contact_info       phone numbers, emails or links in the description
 *   payment_request    the description asks buyers to send money first — the one
 *                      case that pauses the listing, because that's where buyers lose money
 *
 * Buyers can also report a listing (sold, wrong details, scam, photos don't
 * match). Every flag lands in the admin's Flagged tab and notifies admins.
 */
class ListingIntegrityService
{
    public const LABELS = [
        'duplicate_photos'     => 'Uses the same photos as another listing',
        'duplicate_address'    => 'Same address and a similar price as another agent\'s listing',
        'contact_info'         => 'Description has phone numbers, emails or outside links',
        'payment_request'      => 'Description asks buyers to send money before a viewing',
        'report_sold'          => 'Buyer report: already sold or not available',
        'report_wrong_details' => 'Buyer report: wrong price or details',
        'report_scam'          => 'Buyer report: looks fake or like a scam',
        'report_photos'        => 'Buyer report: photos don\'t match the property',
        'report_other'         => 'Buyer report: something else',
    ];

    public const REPORT_REASONS = ['sold', 'wrong_details', 'scam', 'photos', 'other'];

    /** Money-transfer words; with a "pay first" word nearby this is a payment request. */
    private const PAY_CHANNELS = '/\b(g\s?cash|maya|pay\s?maya|palawan|cebuana|western union|bank transfer|bank deposit|bdo|bpi|metrobank|landbank|send money|padala)\b/i';
    private const PAY_FIRST    = '/\b(reservation fee|reserve fee|viewing fee|deposit|down ?payment|pay|send|transfer)\b.{0,60}\b(first|before|advance|in advance|to reserve|to secure|to view|prior)\b|\b(first|advance)\b.{0,30}\b(payment|deposit|reservation)\b/i';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly GeminiService $gemini,
    ) {
    }

    // ── Detection ────────────────────────────────────────────────────────────

    /** Check a listing (on publish and on edits) and open, update or close its AI flag. */
    public function scan(Property $property): ?PropertyFlag
    {
        $property->loadMissing(['photos', 'agent']);
        $reasons = [];
        $details = [];

        if ($match = $this->duplicatePhotos($property)) {
            $reasons[] = 'duplicate_photos';
            $details['duplicate_photos'] = $match;
        }
        if ($match = $this->duplicateAddress($property)) {
            $reasons[] = 'duplicate_address';
            $details['duplicate_address'] = $match;
        }

        $text = trim(($property->title ?? '') . "\n" . ($property->description ?? ''));
        $contacts = $this->contactDetails($text);
        $patternPayment = preg_match(self::PAY_CHANNELS, $text) === 1 && preg_match(self::PAY_FIRST, $text) === 1;
        $looksOff = $contacts !== [] || $patternPayment || preg_match(self::PAY_CHANNELS, $text) === 1;

        // AI reads the wording only when the patterns found something, so normal
        // listings never use AI quota. If the AI is down, the patterns decide.
        $ai = $looksOff ? $this->gemini->checkListingText((string) $property->title, (string) $property->description) : null;
        $payment = $ai !== null ? $ai['payment_request'] : $patternPayment;

        if ($contacts !== [] || ($ai['off_platform'] ?? false)) {
            $reasons[] = 'contact_info';
            $details['contact_info'] = ['found' => $contacts];
        }
        if ($payment) {
            $reasons[] = 'payment_request';
        }
        if ($ai && $ai['reason'] !== '') {
            $details['ai_reason'] = $ai['reason'];
        }

        return $this->saveAiFlag($property, $reasons, $details);
    }

    /** First photo on this listing that matches a photo on another listing. */
    private function duplicatePhotos(Property $property): ?array
    {
        $mine = $property->photos->where('is_360', false);
        foreach ($mine as $photo) {
            $this->ensureHash($photo);
        }
        $mine = $mine->filter(fn ($p) => $p->phash);
        if ($mine->isEmpty()) {
            return null;
        }

        $match = null;
        PropertyPhoto::whereNotNull('phash')->where('property_id', '!=', $property->id)->where('is_360', false)
            ->select(['id', 'property_id', 'phash'])
            ->chunkById(500, function ($others) use ($mine, &$match) {
                foreach ($others as $other) {
                    foreach ($mine as $p) {
                        if (ImageHash::distance($p->phash, $other->phash) <= ImageHash::SAME_PHOTO_BITS) {
                            $match = $other->property_id;

                            return false;
                        }
                    }
                }
            });

        if (! $match) {
            return null;
        }
        $other = Property::with('agent')->find($match);

        return $other ? ['property_id' => $other->id, 'title' => $other->title, 'agent' => $other->agent?->name, 'same_agent' => $other->agent_id === $property->agent_id] : null;
    }

    /** Fingerprint a photo that was uploaded before fingerprints existed. */
    private function ensureHash(PropertyPhoto $photo): void
    {
        if ($photo->phash) {
            return;
        }
        try {
            $bytes = Uploads::disk()->get($photo->thumb_url ?: $photo->url);
            if ($bytes && ($hash = ImageHash::fromBytes($bytes))) {
                $photo->forceFill(['phash' => $hash])->saveQuietly();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function duplicateAddress(Property $property): ?array
    {
        $norm = self::normaliseAddress((string) $property->address);
        if (mb_strlen($norm) < 8 || ! $property->price) {
            return null;
        }
        $price = (float) $property->price;
        $other = Property::with('agent')
            ->where('id', '!=', $property->id)
            ->where('agent_id', '!=', $property->agent_id)
            ->where('status', 'published')
            ->whereBetween('price', [$price * 0.9, $price * 1.1])
            ->get(['id', 'title', 'address', 'price', 'agent_id'])
            ->first(fn ($p) => self::normaliseAddress((string) $p->address) === $norm);

        return $other ? ['property_id' => $other->id, 'title' => $other->title, 'agent' => $other->agent?->name] : null;
    }

    public static function normaliseAddress(string $a): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', mb_strtolower($a))));
    }

    /** Phone numbers, emails and links, partly masked so the admin sees what kind without the full number. */
    private function contactDetails(string $text): array
    {
        $found = [];
        if (preg_match_all('/(\+?63|0)9\d{2}[\s.-]?\d{3}[\s.-]?\d{4}/', $text, $m)) {
            foreach ($m[0] as $n) {
                $found[] = 'phone ' . substr(preg_replace('/\D/', '', $n), 0, 4) . '…';
            }
        }
        if (preg_match_all('/[\w.+-]+@[\w-]+\.[\w.]+/', $text, $m)) {
            foreach ($m[0] as $e) {
                $found[] = 'email ' . substr($e, 0, 3) . '…';
            }
        }
        if (preg_match_all('/\b(https?:\/\/\S+|www\.\S+|\S+\.(com|ph|net|me)\b\S*|m\.me\/\S+|fb\.com\/\S+)/i', $text, $m)) {
            foreach ($m[0] as $u) {
                $found[] = 'link ' . mb_substr($u, 0, 24);
            }
        }
        if (preg_match('/\b(viber|whatsapp|telegram|wechat)\b/i', $text, $m)) {
            $found[] = mb_strtolower($m[1]);
        }

        return array_values(array_unique($found));
    }

    private function saveAiFlag(Property $property, array $reasons, array $details): ?PropertyFlag
    {
        $flag = PropertyFlag::where('property_id', $property->id)->where('source', PropertyFlag::AI)->open()->first();

        if ($reasons === []) {
            // Fixed by the agent (and not waiting on an admin): close it quietly.
            if ($flag && ! $flag->paused) {
                $flag->update(['status' => 'cleared', 'resolved_by' => 'RealtyLink AI', 'resolved_at' => now()]);
            }

            return null;
        }

        $isNew = $flag === null;
        $grew  = $flag && array_diff($reasons, $flag->reasons ?? []) !== [];
        $pause = in_array('payment_request', $reasons, true);

        $flag ??= new PropertyFlag(['property_id' => $property->id, 'source' => PropertyFlag::AI, 'status' => 'open']);
        $flag->reasons = $reasons;
        $flag->details = $details;
        if ($pause && ! $flag->paused) {
            $flag->paused = true;
            $this->pause($property);
        }
        $flag->save();

        if ($isNew || $grew) {
            $this->tellAdmins($property, 'RealtyLink AI flagged a listing: "' . $property->title . '". ' . self::LABELS[$reasons[0]] . ($pause ? ' (paused until you review it).' : '.'));
        }

        return $flag;
    }

    private function pause(Property $property): void
    {
        if ($property->status === 'published') {
            $property->update([
                'status'           => 'draft',
                'unpublish_reason' => 'Paused for review: the description appears to ask buyers to send money before a viewing. An administrator will check it shortly.',
                'unpublished_at'   => now(),
            ]);
        }
        if ($property->agent) {
            $this->notifications->send($property->agent, 'listing_paused', [
                'property_id' => $property->id, 'property_title' => $property->title,
                'message' => "Your listing \"{$property->title}\" is paused while an administrator checks it: it appears to ask buyers to send money before a viewing.",
            ]);
        }
    }

    // ── Buyer reports ────────────────────────────────────────────────────────

    public function report(Property $property, User $reporter, string $reason, ?string $note): PropertyFlag
    {
        abort_if($property->agent_id === $reporter->id, 422, 'You cannot report your own listing.');
        abort_unless(in_array($reason, self::REPORT_REASONS, true), 422, 'Please choose a reason.');

        $flag = PropertyFlag::where('property_id', $property->id)->where('reporter_id', $reporter->id)->open()->first();
        $isNew = $flag === null;
        $flag ??= new PropertyFlag(['property_id' => $property->id, 'source' => PropertyFlag::REPORT, 'reporter_id' => $reporter->id, 'status' => 'open']);
        $flag->reasons = ['report_' . $reason];
        $flag->note = $note;
        $flag->save();

        if ($isNew) {
            $this->tellAdmins($property, "A buyer reported the listing \"{$property->title}\": " . mb_strtolower(substr(self::LABELS['report_' . $reason], 14)) . '.');
        }

        return $flag;
    }

    // ── Admin decisions ──────────────────────────────────────────────────────

    /** "Looks fine": close every open flag; a paused listing goes live again. */
    public function clear(Property $property, User $admin): void
    {
        $flags = PropertyFlag::where('property_id', $property->id)->open()->get();
        abort_if($flags->isEmpty(), 422, 'This listing has no open flags.');
        $wasPaused = $flags->contains('paused', true);

        DB::transaction(function () use ($flags, $admin, $property, $wasPaused) {
            foreach ($flags as $f) {
                $f->update(['status' => 'cleared', 'resolved_by' => $admin->name, 'resolved_at' => now()]);
            }
            if ($wasPaused && $property->status === 'draft') {
                $property->update(['status' => 'published', 'unpublish_reason' => null, 'unpublished_at' => null]);
            }
        });

        AdminAudit::log('listing.flags_cleared', $property, $property->title, ['reasons' => $flags->flatMap->reasons->unique()->values()->all(), 'restored' => $wasPaused], $admin);

        if ($wasPaused && $property->agent) {
            $this->notifications->send($property->agent, 'listing_restored', [
                'property_id' => $property->id, 'property_title' => $property->title,
                'message' => "An administrator checked \"{$property->title}\" and it's live again.",
            ]);
        }
    }

    /** Taken down or deleted by an admin: the flags are dealt with. */
    public function markActioned(Property $property, User $admin): void
    {
        PropertyFlag::where('property_id', $property->id)->open()->update(['status' => 'actioned', 'resolved_by' => $admin->name, 'resolved_at' => now()]);
    }

    public function isPaused(Property $property): bool
    {
        return PropertyFlag::where('property_id', $property->id)->open()->where('paused', true)->exists();
    }

    private function tellAdmins(Property $property, string $message): void
    {
        User::whereIn('role_type', ['admin', 'super_admin'])->where('is_system', false)->get()
            ->each(fn (User $a) => $this->notifications->send($a, 'listing_flagged', ['property_id' => $property->id, 'property_title' => $property->title, 'message' => $message]));
    }
}
