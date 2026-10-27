<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentBlockedDate;
use App\Models\AgentReview;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Inquiry;
use App\Models\Property;
use App\Models\User;
use App\Support\Uploads;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * RealtyLink AI for agents: one chat turn.
 *
 *   1. Work out what the latest message asks for: keywords first (free and
 *      instant), Gemini only when they can't tell.
 *   2. Answer from the agent's real data, written here rather than by the
 *      model so it is always true and costs no quota: their listings,
 *      viewings, inquiries and reviews as cards, a price check against
 *      comparable listings, or a listing check with fixes.
 *   3. Tasks (confirm a viewing, block a day or hours, publish, unpublish,
 *      mark sold) come back as a card the agent approves; nothing changes here.
 *   4. Replies to buyers are drafts the agent sends themselves.
 *
 * Anything else (descriptions, creating a listing, advice) goes to
 * GeminiService::agentChat as before.
 */
class AgentAssistantService
{
    public const INTENTS = [
        'chat', 'listings', 'viewings', 'inquiries', 'reviews', 'price_check', 'listing_check', 'draft_reply',
        'confirm_viewing', 'block_day', 'block_hours', 'publish', 'unpublish', 'mark_sold',
        'theme_dark', 'theme_light', 'theme_system', 'gcal_connect', 'gcal_disconnect', 'password', 'open_page', 'capabilities',
    ];

    /** Sections "open_page" can take an agent to: key => [label, path]. */
    public const PAGES = [
        'dashboard'     => ['Dashboard', '/dashboard'],
        'listings'      => ['My Listings', '/dashboard/listings'],
        'new_listing'   => ['New listing', '/dashboard/listings/new'],
        'appointments'  => ['Appointments', '/dashboard/appointments'],
        'calendar'      => ['Calendar', '/dashboard/calendar'],
        'history'       => ['History', '/dashboard/history'],
        'reviews'       => ['Reviews', '/dashboard/reviews'],
        'messages'      => ['Messages', '/dashboard?panel=messages'],
        'inquiries'     => ['Inquiries', '/dashboard?panel=inquiries'],
        'notifications' => ['Notifications', '/dashboard?panel=notifications'],
        'profile'       => ['My Profile', '/dashboard/profile'],
        'support'       => ['Help & support', '/dashboard/support'],
        'browse'        => ['Browse listings', '/dashboard/browse'],
    ];

    /** Cards per reply. */
    private const PER_REPLY = 6;

    /** The Calendar page's presets, so "block tomorrow afternoon" matches it. */
    private const PERIODS = [
        'morning'   => ['08:00', '12:00'],
        'afternoon' => ['13:00', '17:00'],
        'evening'   => ['17:00', '20:00'],
    ];

    private const TYPE_WORDS = [
        'house'      => '/\b(houses?|house and lot|bungalows?|townhouses?|duplex)\b/u',
        'condo'      => '/\b(condos?|condominiums?|studio|penthouse)\b/u',
        'lot'        => '/(?<!\ba )\blots?\b(?! of)|\b(land|vacant)\b/u',
        'commercial' => '/\b(commercial|office|shop|store|warehouse)\b/u',
        'apartment'  => '/\b(apartments?|apt|flat)\b/u',
    ];

    /** Words that never pick out one listing or buyer. */
    private const STOP = [
        'the', 'my', 'a', 'an', 'and', 'or', 'of', 'for', 'to', 'in', 'on', 'at', 'is', 'it', 'its', 'this', 'that', 'one', 'please', 'pls',
        'listing', 'listings', 'property', 'properties', 'publish', 'unpublish', 'mark', 'sold', 'as', 'take', 'down', 'hide', 'make', 'live',
        'price', 'check', 'priced', 'right', 'how', 'much', 'should', 'what', 'is', 'can', 'you', 'me', 'now', 'already', 'with', 'from',
        'house', 'condo', 'lot', 'commercial', 'apartment', 'sale', 'rent', 'city', 'reply', 'draft', 'write', 'review', 'inquiry',
        'confirm', 'viewing', 'appointment', 'request', 'message', 'chat', 'buyer', 'set', 'go', 'unit', 'new', 'old', 'na',
    ];

    public function __construct(
        private readonly GeminiService $gemini,
        private readonly PropertyService $properties,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  array{mime: string, data: string}|null  $image
     * @return array{intent: string, reply: string, listing_proposal: ?array, action: ?array, card: ?array}
     */
    public function reply(User $agent, array $history, ?array $image = null): array
    {
        // Nothing that looks like a password goes to Gemini.
        $history = array_map(fn (array $m) => ['role' => $m['role'], 'content' => BuyerAssistantService::redact((string) $m['content'])], $history);
        $latest  = (string) (collect($history)->last(fn ($m) => $m['role'] === 'user')['content'] ?? '');
        $today   = CarbonImmutable::today();

        // A photo is always a description job.
        $ask = $image === null ? $this->readIntent($latest, $today) : null;
        if ($ask === null && $image === null) {
            $ai  = $this->gemini->agentIntent($history, self::INTENTS, array_keys(self::PAGES), $today->format('Y-m-d (l)'));
            $ask = $ai !== null ? $this->fromModel($ai, $latest, $today) : null;
        }
        $ask ??= ['intent' => 'chat'];

        $out = ['intent' => $ask['intent'], 'reply' => '', 'listing_proposal' => null, 'action' => null, 'card' => null];

        return array_merge($out, match ($ask['intent']) {
            'listings'        => $this->listings($agent, $ask),
            'viewings'        => $this->viewings($agent, $ask),
            'inquiries'       => $this->inquiries($agent),
            'reviews'         => $this->reviews($agent),
            'price_check'     => $this->priceCheck($agent, $ask),
            'listing_check'   => $this->listingCheck($agent, $ask),
            'draft_reply'     => $this->draftReply($agent, $ask),
            'confirm_viewing' => $this->confirmViewing($agent, $ask),
            'block_day', 'block_hours' => $this->block($agent, $ask),
            'publish', 'unpublish', 'mark_sold' => $this->listingTask($agent, $ask),
            'chat'            => $this->gemini->agentChat($agent, $history, $image),
            default           => $this->act($agent, $ask['intent'], $ask['page'] ?? null),
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reading the message
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The keyword reader. Null when nothing matches clearly; Gemini decides.
     *
     * @return array<string, mixed>|null
     */
    public function readIntent(string $latest, CarbonImmutable $today): ?array
    {
        $t = mb_strtolower(trim($latest));
        if ($t === '') {
            return null;
        }
        $base = ['text' => $latest, 'date' => $this->parseDate($t, $today), 'hours' => $this->parseHours($t)];

        $intent = match (true) {
            GeminiService::themeAction($t) !== null => GeminiService::themeAction($t),
            (bool) preg_match('/\bwhat (else )?can you do\b|\bhow can you help\b|\bwhat are you (able|capable)\b|^help\??$/u', $t) => 'capabilities',
            (bool) preg_match('/\b(google calendar|gcal)\b|\bcalendar\b.{0,20}\b(connect|link|sync)/u', $t)
                => preg_match('/\b(disconnect|unlink|remove|stop)\b/u', $t) ? 'gcal_disconnect' : 'gcal_connect',
            (bool) preg_match('/\bpassword\b/u', $t) => 'password',
            (bool) preg_match('/\b(draft|write|compose|suggest|prepare|help me)\b.{0,30}\b(reply|response|answer)\b|\b(reply|respond|answer)\b\s+(to|sa)\b/u', $t) => 'draft_reply',
            (bool) preg_match('/\b(confirm|accept|approve)\b/u', $t) => 'confirm_viewing',
            (bool) preg_match('/(?<!un)\bblock\b|\bblackout\b|\bday off\b|\b(not available|unavailable)\b|\bi(\'m| am) (busy|off|away)\b/u', $t)
                => $base['hours'] !== null ? 'block_hours' : 'block_day',
            (bool) preg_match('/\b(mark|set|tag)\b.{0,40}\bsold\b|\bas sold\b|\bsold (na|already)\b/u', $t) => 'mark_sold',
            (bool) preg_match('/\b(unpublish|un-publish|unlist|take (it )?down|take .{1,40} down|hide)\b/u', $t) => 'unpublish',
            (bool) preg_match('/\b(publish|go live|make .{0,30}live|post it)\b/u', $t) => 'publish',
            (bool) preg_match('/\b(price check|priced (right|fairly|correctly|too (high|low))|fair price|right price|suggest(ed)? (a )?price|how much should|what price|over ?priced|under ?priced|pricing|market value)\b/u', $t) => 'price_check',
            (bool) preg_match('/\b(check|review|audit|improve|optimi[sz]e|fix)\b.{0,25}\blistings?\b|\bwhy\b.{0,30}\b(no|few|low|zero)\b.{0,10}\b(views|inquiries)\b|\bwhat(\'s| is) wrong\b|\bneeds? attention\b/u', $t) => 'listing_check',
            (bool) preg_match('/\b(open|go to|take me to|bring me to)\b/u', $t) && $this->pageIn($t) !== null => 'open_page',
            (bool) preg_match('/\b(viewings?|appointments?|bookings?|trippings?|schedule)\b/u', $t) => 'viewings',
            (bool) preg_match('/\binquir(y|ies)\b|\bleads?\b/u', $t) => 'inquiries',
            (bool) preg_match('/\b(reviews?|ratings?|feedback)\b/u', $t) => 'reviews',
            (bool) preg_match('/\b(my|show|list|see|which|how many)\b.{0,30}\b(listings?|properties|drafts?)\b/u', $t) => 'listings',
            default => null,
        };

        if ($intent === null) {
            return null;
        }

        return $base + [
            'intent' => $intent,
            'page'   => $intent === 'open_page' ? $this->pageIn($t) : null,
            'status' => $this->statusIn($t, $intent),
            'target' => $this->targetIn($t),
            'sort'   => preg_match('/\b(most|top|best|popular|highest)\b.{0,12}\b(views?|viewed|performing|inquir)/u', $t) ? 'views' : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function fromModel(array $ai, string $latest, CarbonImmutable $today): ?array
    {
        $intent = $ai['intent'];
        if ($intent === 'open_page' && ! isset(self::PAGES[$ai['page'] ?? ''])) {
            return null;
        }

        $date = null;
        if (is_string($ai['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ai['date'])) {
            $date = CarbonImmutable::parse($ai['date'])->startOfDay();
        }
        $hours = null;
        $time  = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (preg_match($time, (string) ($ai['start_time'] ?? '')) && preg_match($time, (string) ($ai['end_time'] ?? '')) && $ai['end_time'] > $ai['start_time']) {
            $hours = [$ai['start_time'], $ai['end_time']];
        }
        if ($intent === 'block_hours' && $hours === null) {
            $intent = 'block_day';
        }

        $t = mb_strtolower($latest);

        return [
            'intent' => $intent,
            'text'   => trim(($ai['listing'] ?? '') . ' ' . ($ai['person'] ?? '')) ?: $latest,
            'date'   => $date ?? $this->parseDate($t, $today),
            'hours'  => $hours ?? ($intent === 'block_hours' ? $this->parseHours($t) : null),
            'page'   => $ai['page'] ?? null,
            'status' => in_array($ai['status'] ?? null, ['draft', 'published', 'sold', 'pending', 'upcoming'], true) ? $ai['status'] : $this->statusIn($t, $intent),
            'target' => in_array($ai['target'] ?? null, ['inquiry', 'review', 'chat'], true) ? $ai['target'] : $this->targetIn($t),
            'sort'   => null,
        ];
    }

    private function pageIn(string $t): ?string
    {
        $words = [
            'new_listing'   => '/\b(new|add|create) (a )?listing\b|\blisting wizard\b/u',
            'listings'      => '/\blistings?\b/u',
            'calendar'      => '/\bcalendar|availability\b/u',
            'appointments'  => '/\b(appointments?|viewings?)\b/u',
            'history'       => '/\bhistory\b/u',
            'reviews'       => '/\breviews?\b/u',
            'inquiries'     => '/\binquir(y|ies)\b/u',
            'messages'      => '/\b(messages?|chats?|inbox)\b/u',
            'notifications' => '/\bnotifications?\b/u',
            'profile'       => '/\b(profile|settings|account)\b/u',
            'support'       => '/\b(support|help)\b/u',
            'browse'        => '/\bbrowse\b/u',
            'dashboard'     => '/\b(dashboard|home|overview)\b/u',
        ];
        foreach ($words as $page => $re) {
            if (preg_match($re, $t)) {
                return $page;
            }
        }

        return null;
    }

    private function statusIn(string $t, string $intent): ?string
    {
        if ($intent === 'viewings' || $intent === 'confirm_viewing') {
            return preg_match('/\b(pending|requests?|waiting|needs?|respond|unconfirmed|new)\b/u', $t) ? 'pending' : null;
        }

        return match (true) {
            (bool) preg_match('/\bdrafts?\b|\bunpublished\b/u', $t)                      => 'draft',
            (bool) preg_match('/\bsold\b/u', $t) && $intent === 'listings'               => 'sold',
            (bool) preg_match('/\b(published|live|active)\b/u', $t) && $intent === 'listings' => 'published',
            default                                                                      => null,
        };
    }

    private function targetIn(string $t): ?string
    {
        return match (true) {
            (bool) preg_match('/\binquir(y|ies)\b/u', $t)                => 'inquiry',
            (bool) preg_match('/\b(review|rating|feedback)s?\b/u', $t)   => 'review',
            (bool) preg_match('/\b(chat|message|messaged|dm)s?\b/u', $t) => 'chat',
            default                                                      => null,
        };
    }

    /** today / tomorrow / Friday / next Monday / Oct 30 / 30 October / 2026-10-30 / 10/30. */
    public function parseDate(string $t, CarbonImmutable $today): ?CarbonImmutable
    {
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        $mon    = '(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?';

        $date = match (true) {
            (bool) preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $t, $m)     => $today->setDate((int) $m[1], (int) $m[2], (int) $m[3]),
            (bool) preg_match('/\b' . $mon . '\s+(\d{1,2})\b/u', $t, $m)  => $today->setDate($today->year, $months[$m[1]], (int) $m[2]),
            (bool) preg_match('/\b(\d{1,2})\s+' . $mon . '/u', $t, $m)    => $today->setDate($today->year, $months[$m[2]], (int) $m[1]),
            (bool) preg_match('/\b(\d{1,2})\/(\d{1,2})\b/', $t, $m)        => $today->setDate($today->year, (int) $m[1], (int) $m[2]),
            (bool) preg_match('/\bday after tomorrow\b/u', $t)               => $today->addDays(2),
            (bool) preg_match('/\b(tomorrow|tmrw|tmr|bukas)\b/u', $t)        => $today->addDay(),
            (bool) preg_match('/\b(today|tonight|ngayon)\b/u', $t)           => $today,
            (bool) preg_match('/\b(mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun)(day|nesday|rsday|urday)?\b/u', $t, $m)
                => $today->next(['mon' => 1, 'tue' => 2, 'tues' => 2, 'wed' => 3, 'thu' => 4, 'thur' => 4, 'thurs' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 0][$m[1]]),
            default => null,
        };

        // "Oct 3" typed in December means next year's.
        if ($date !== null && $date->lt($today) && ! preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $t) && $date->diffInDays($today) > 31) {
            $date = $date->addYear();
        }

        return $date?->startOfDay();
    }

    /**
     * "2pm to 5pm" / "2-5pm" / "from 9am until 12nn" / "14:00-17:00" / "morning".
     *
     * @return array{0: string, 1: string}|null
     */
    public function parseHours(string $t): ?array
    {
        $re = '/\b(\d{1,2})(?::(\d{2}))?\s*(am|pm|nn|noon)?\s*(?:-|–|to|until|till|hanggang)\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm|nn|noon)?\b/u';
        if (preg_match($re, $t, $m)) {
            [$h1, $h2] = [(int) $m[1], (int) $m[4]];
            $mer1 = $m[3] ?? '';
            $mer2 = $m[6] ?? '';
            // "2-5pm": the second meridiem covers the first.
            if ($mer1 === '' && $mer2 !== '') {
                $mer1 = ($mer2 === 'pm' && $h1 > $h2 && $h1 < 12) ? 'am' : $mer2;
            }
            // Bare "2-5": viewing hours, so the afternoon.
            if ($mer1 === '' && $mer2 === '' && ! str_contains($m[0], ':')) {
                $mer1 = $h1 <= 7 ? 'pm' : 'am';
                $mer2 = $h2 <= 8 ? 'pm' : 'am';
            }
            $to24 = static fn (int $h, string $mer) => match (true) {
                in_array($mer, ['nn', 'noon'], true) => 12,
                $mer === 'pm' && $h < 12             => $h + 12,
                $mer === 'am' && $h === 12           => 0,
                default                              => $h,
            };
            $start = sprintf('%02d:%02d', $to24($h1, $mer1), (int) ($m[2] ?? 0));
            $end   = sprintf('%02d:%02d', $to24($h2, $mer2), (int) ($m[5] ?? 0));
            if ($end > $start && $end <= '23:59') {
                return [$start, $end];
            }
        }

        foreach (['morning' => '/\b(morning|umaga|am only)\b/u', 'afternoon' => '/\b(afternoon|hapon|pm only)\b/u', 'evening' => '/\b(evening|gabi|night)\b/u'] as $period => $word) {
            if (preg_match($word, $t)) {
                return self::PERIODS[$period];
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Their data, as cards
    // ─────────────────────────────────────────────────────────────────────

    private function listings(User $agent, array $ask): array
    {
        $status = in_array($ask['status'] ?? null, ['draft', 'published', 'sold'], true) ? $ask['status'] : null;
        $query  = $agent->properties()->with('photos')->withCount('inquiries')->when($status, fn ($q) => $q->where('status', $status));
        $total  = (clone $query)->count();
        $items  = ($ask['sort'] ?? null) === 'views'
            ? $query->orderByDesc('views')->latest()->limit(self::PER_REPLY)->get()
            : $query->latest()->limit(self::PER_REPLY)->get();

        $word = ['draft' => 'draft', 'published' => 'published listing', 'sold' => 'sold listing'][$status] ?? 'listing';
        $many = $total === 1 ? $word : Str::plural($word);

        if ($total === 0) {
            $reply = $status
                ? "You don't have any {$many} right now."
                : "You don't have any listings yet. Tell me about a property (type, price and location) and I'll draft one for you.";
        } elseif (($ask['sort'] ?? null) === 'views') {
            $top   = $items->first();
            $reply = "Your most-viewed listing is “{$top->title}” with " . number_format($top->views) . ' ' . Str::plural('view', $top->views) . '.';
            $zero  = $items->where('status', 'published')->where('views', 0)->count();
            if ($zero) {
                $reply .= " {$zero} published " . Str::plural('listing', $zero) . ' still ' . ($zero === 1 ? 'has' : 'have') . ' no views; ask me to check them for fixes.';
            }
        } else {
            $reply = "You have {$total} {$many}" . ($total > self::PER_REPLY ? ', here are the latest ' . self::PER_REPLY : '') . '.';
            if (! $status) {
                $counts = $agent->properties()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
                $parts  = array_filter([
                    ($counts['published'] ?? 0) ? "{$counts['published']} published" : null,
                    ($counts['draft'] ?? 0) ? "{$counts['draft']} " . Str::plural('draft', (int) $counts['draft']) : null,
                    ($counts['sold'] ?? 0) ? "{$counts['sold']} sold" : null,
                ]);
                $reply = "You have {$total} " . Str::plural('listing', $total) . ($parts ? ': ' . implode(', ', $parts) : '') . '.';
            }
        }

        return [
            'reply' => $reply,
            'card'  => $total ? ['type' => 'listings', 'items' => $items->map(fn (Property $p) => $this->listingItem($p))->all(), 'total' => $total, 'link' => '/dashboard/listings'] : null,
        ];
    }

    private function viewings(User $agent, array $ask): array
    {
        $pending = ($ask['status'] ?? null) === 'pending';
        /** @var CarbonImmutable|null $day */
        $day   = $ask['date'] ?? null;
        $query = $agent->appointmentsAsAgent()->with(['buyer:id,name', 'property:id,title'])
            ->whereIn('status', $pending ? ['pending'] : ['pending', 'confirmed'])
            ->where('preferred_datetime', '>=', now())
            ->when($day, fn ($q) => $q->whereDate('preferred_datetime', $day->toDateString()))
            ->orderBy('preferred_datetime');
        $total = (clone $query)->count();
        $items = $query->limit(self::PER_REPLY)->get();

        $when = $day ? ' on ' . $this->dayLabel($day) : '';
        if ($total === 0) {
            $reply = $pending ? 'No viewing requests are waiting for you. 🎉' : "You don't have any upcoming viewings{$when}.";
        } else {
            $next  = $items->first();
            $reply = ($pending
                    ? "{$total} viewing " . Str::plural('request', $total) . ' ' . ($total === 1 ? 'is' : 'are') . ' waiting for your answer.'
                    : "You have {$total} upcoming " . Str::plural('viewing', $total) . "{$when}.")
                . " Next: {$next->buyer?->name} for “{$next->property?->title}”, " . $this->whenLabel(CarbonImmutable::instance($next->preferred_datetime)) . '.';
            $waiting = $items->where('status', 'pending')->count();
            if (! $pending && $waiting) {
                $reply .= " {$waiting} still " . ($waiting === 1 ? 'needs' : 'need') . ' your confirmation; you can confirm right here.';
            }
        }

        return [
            'reply' => $reply,
            'card'  => $total ? ['type' => 'viewings', 'items' => $items->map(fn (Appointment $a) => $this->viewingItem($a))->all(), 'total' => $total, 'link' => '/dashboard/appointments'] : null,
        ];
    }

    private function inquiries(User $agent): array
    {
        $query  = Inquiry::query()->whereHas('property', fn ($q) => $q->where('agent_id', $agent->id));
        $total  = (clone $query)->count();
        $unread = (clone $query)->where('is_read', false)->count();
        $items  = $query->with('property:id,title')->orderBy('is_read')->latest()->limit(self::PER_REPLY)->get();

        $reply = match (true) {
            $total === 0  => 'No inquiries yet. They arrive here when a buyer uses the contact form on one of your listings.',
            $unread === 0 => "You've read all {$total} " . Str::plural('inquiry', $total) . '. Here are the latest.',
            default       => "{$unread} unread " . Str::plural('inquiry', $unread) . " out of {$total}. The newest is from {$items->first()->name} about “{$items->first()->property?->title}”. Ask me to draft a reply.",
        };

        return [
            'reply' => $reply,
            'card'  => $total ? ['type' => 'inquiries', 'items' => $items->map(fn (Inquiry $i) => $this->inquiryItem($i))->all(), 'total' => $total, 'link' => '/dashboard?panel=inquiries'] : null,
        ];
    }

    private function reviews(User $agent): array
    {
        $query     = $agent->reviewsReceived()->where('is_visible', true);
        $total     = (clone $query)->count();
        $avg       = round((float) (clone $query)->avg('rating'), 1);
        $unreplied = (clone $query)->whereNull('agent_reply')->count();
        $items     = $query->with('buyer:id,name')->orderByRaw('agent_reply IS NOT NULL')->latest()->limit(self::PER_REPLY)->get();

        $reply = $total === 0
            ? 'No reviews yet. Buyers can rate you after a viewing or a chat with you.'
            : "You're rated {$avg} ★ from {$total} " . Str::plural('review', $total) . '.'
                . ($unreplied ? " {$unreplied} " . ($unreplied === 1 ? "hasn't" : "haven't") . ' had a reply yet; ask me to draft one.' : ' You\'ve replied to all of them. 👏');

        return [
            'reply' => $reply,
            'card'  => $total ? ['type' => 'reviews', 'items' => $items->map(fn (AgentReview $r) => $this->reviewItem($r))->all(), 'total' => $total, 'average' => $avg, 'link' => '/dashboard/reviews'] : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pricing advisor and listing check
    // ─────────────────────────────────────────────────────────────────────

    private function priceCheck(User $agent, array $ask): array
    {
        $text = mb_strtolower((string) $ask['text']);
        [$listing, $candidates] = $this->resolveListing($agent, $ask['text'], ['draft', 'published']);

        // "Suggest a price for a 120sqm lot in Cebu": a property that isn't listed yet.
        // A property described with "a/an" ("a 120 sqm lot in Cebu") isn't one of theirs.
        $spec = $this->specIn($text);
        $hypothetical = preg_match('/\b(a|an)\s+[^,.?!"“]{0,20}\b(houses?|condos?|lots?|commercial|apartments?|studio|townhouse|bungalow|office|unit|space)\b/u', $text)
            && ! preg_match('/\bmy\b/u', $text);
        if ($spec !== null && ($listing === null || $hypothetical)) {
            $comps = $this->comparables($spec['type'], $spec['offer_type'], $spec['places'], null);

            return $this->priceReply(null, $spec, $comps);
        }

        if ($listing === null) {
            return $this->whichListing($candidates, 'price_check', 'Which listing should I price-check?');
        }

        $area  = $this->areaOf($listing);
        $comps = $this->comparables($listing->type, $listing->offer_type, $this->placesOf($listing->address), $listing->id);

        return $this->priceReply($listing, ['type' => $listing->type, 'offer_type' => $listing->offer_type, 'area' => $area], $comps);
    }

    /**
     * @param  array<int, string>  $places  most specific first
     * @return array{area: ?string, rows: Collection<int, Property>}
     */
    private function comparables(string $type, string $offer, array $places, ?int $excludeId): array
    {
        $base = Property::query()->published()->where('type', $type)->where('offer_type', $offer)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId));

        $fallback = null;
        foreach ($places as $place) {
            $rows = (clone $base)->whereRaw('LOWER(address) LIKE ?', ['%' . mb_strtolower($place) . '%'])->limit(200)->get(['id', 'price', 'floor_area', 'lot_area']);
            if ($rows->count() >= 3) {
                return ['area' => $place, 'rows' => $rows];
            }
            if ($rows->isNotEmpty() && $fallback === null) {
                $fallback = ['area' => $place, 'rows' => $rows];
            }
        }

        $all = (clone $base)->limit(200)->get(['id', 'price', 'floor_area', 'lot_area']);

        return $all->count() >= 3 || $fallback === null ? ['area' => null, 'rows' => $all] : $fallback;
    }

    private function priceReply(?Property $listing, array $spec, array $comps): array
    {
        /** @var Collection<int, Property> $rows */
        $rows  = $comps['rows'];
        $noun  = ['house' => 'house', 'condo' => 'condo', 'lot' => 'lot', 'commercial' => 'commercial space', 'apartment' => 'apartment'][$spec['type']];
        $kind  = Str::plural($noun) . ' for ' . $spec['offer_type'];
        $one   = $noun . ' for ' . $spec['offer_type'];
        $where = $comps['area'] ? " in {$comps['area']}" : ' on RealtyLink PH';
        $unit  = $spec['offer_type'] === 'rent' ? '/month' : '';

        if ($rows->isEmpty()) {
            return [
                'reply' => "There aren't any other published {$kind} to compare with yet, so I can't judge the price from RealtyLink PH data. I'd look at recent sales nearby and price within that range.",
                'card'  => null,
            ];
        }

        $prices = $rows->map(fn (Property $p) => (float) $p->price)->sort()->values();
        $perSqm = $rows->map(function (Property $p) use ($spec) {
            $a = $this->areaOf($p, $spec['type']);

            return $a ? (float) $p->price / $a : null;
        })->filter()->sort()->values();

        $median    = $this->quantile($prices, 0.5);
        $medianSqm = $perSqm->count() >= 2 ? $this->quantile($perSqm, 0.5) : null;
        $area      = $spec['area'] ?? null;

        // The fair range: middle half of the market, scaled to this size when we know it.
        if ($area && $medianSqm) {
            $low  = $area * $this->quantile($perSqm, 0.25);
            $high = $area * $this->quantile($perSqm, 0.75);
            if ($high - $low < 0.1 * $area * $medianSqm) {
                [$low, $high] = [$area * $medianSqm * 0.95, $area * $medianSqm * 1.05];
            }
        } else {
            [$low, $high] = $prices->count() >= 3
                ? [$this->quantile($prices, 0.25), $this->quantile($prices, 0.75)]
                : [$prices->first(), $prices->last()];
        }

        $verdict = null;
        $diff    = null;
        if ($listing) {
            $own  = (float) $listing->price;
            $base = $area && $medianSqm ? $own / $area : $own;
            $ref  = $area && $medianSqm ? $medianSqm : $median;
            $diff = (int) round(($base - $ref) / $ref * 100);
            $verdict = $diff > 10 ? 'above' : ($diff < -10 ? 'below' : 'in_line');
        }

        $n     = $rows->count();
        $basis = "{$n} similar " . ($n === 1 ? $one : $kind) . $where;
        $range = $this->peso($low) === $this->peso($high)
            ? 'around ' . $this->peso($low) . $unit
            : $this->peso($low) . '–' . $this->peso($high) . $unit;

        $reply = match ($verdict) {
            'above'   => "“{$listing->title}” is priced about {$diff}% above {$basis}" . ($medianSqm && $area ? ' per square metre' : '') . ". If inquiries are slow, {$range} is where the market sits.",
            'below'   => "“{$listing->title}” is priced about " . abs($diff) . "% below {$basis}. It should draw interest; you may have room to ask up to {$range}.",
            'in_line' => "“{$listing->title}” is priced in line with {$basis}. The market sits at {$range}.",
            default   => 'Based on ' . $basis . ', ' . ($area ? "a {$area} sqm {$one}" : "a {$one}") . " would typically list at {$range}.",
        };
        if ($n < 3) {
            $reply .= ' Only a few listings to compare, so treat this as a rough guide.';
        }

        return [
            'reply' => $reply,
            'card'  => [
                'type'       => 'price_check',
                'listing'    => $listing ? $this->listingItem($listing) : null,
                'kind'       => $kind,
                'area_name'  => $comps['area'],
                'size'       => $area,
                'unit'       => $unit,
                'count'      => $n,
                'median'     => round($median),
                'min'        => round($prices->first()),
                'max'        => round($prices->last()),
                'median_sqm' => $medianSqm ? round($medianSqm) : null,
                'low'        => round($low),
                'high'       => round($high),
                'verdict'    => $verdict,
                'diff_pct'   => $diff,
            ],
        ];
    }

    private function listingCheck(User $agent, array $ask): array
    {
        [$one] = $this->resolveListing($agent, $ask['text'], ['draft', 'published']);
        $listings = $one
            ? collect([$one->load('photos')])
            : $agent->properties()->with('photos')->whereIn('status', ['draft', 'published'])->latest()->limit(20)->get();

        if ($listings->isEmpty()) {
            return ['reply' => "You don't have any active listings to check yet.", 'card' => null];
        }

        $checked = $listings->map(fn (Property $p) => ['listing' => $this->listingItem($p), 'issues' => $this->issuesOf($p)])
            ->filter(fn ($row) => $row['issues'] !== [])
            ->sortByDesc(fn ($row) => count($row['issues']))
            ->values();

        if ($checked->isEmpty()) {
            return [
                'reply' => $one
                    ? "“{$one->title}” looks good: enough photos, a full description and its details filled in."
                    : "All {$listings->count()} of your active listings look good. Nothing to fix right now. 👏",
                'card' => null,
            ];
        }

        $top   = $checked->first();
        $reply = $one
            ? "Here's what would help “{$one->title}”:"
            : "{$checked->count()} of your {$listings->count()} active " . Str::plural('listing', $listings->count()) . ' could do better. Start with “' . $top['listing']['title'] . '”: ' . lcfirst($top['issues'][0]['label']) . '.';

        return ['reply' => $reply, 'card' => ['type' => 'listing_check', 'items' => $checked->take(self::PER_REPLY)->all()]];
    }

    /** @return array<int, array{code: string, label: string, tip: string, fix: array<string, string>}> */
    private function issuesOf(Property $p): array
    {
        $edit    = ['label' => 'Edit listing', 'href' => "/dashboard/listings/{$p->id}/edit"];
        $photos  = $p->photos->where('is_360', false)->count();
        $issues  = [];

        if ($p->status === 'draft') {
            $missing = $this->properties->missingForPublish($p);
            $issues[] = $missing
                ? ['code' => 'cant_publish', 'label' => 'Can\'t be published yet', 'tip' => 'Still needs ' . implode(', ', $missing) . '.', 'fix' => $edit]
                : ['code' => 'ready', 'label' => 'Ready to publish', 'tip' => 'Everything buyers need is filled in.', 'fix' => ['label' => 'Publish', 'prompt' => "Publish “{$p->title}”"]];
        }
        if ($photos > 0 && $photos < 5) {
            $issues[] = ['code' => 'few_photos', 'label' => "Only {$photos} " . Str::plural('photo', $photos), 'tip' => 'Listings with 5 or more photos get more attention.', 'fix' => $edit];
        }
        if (! $p->photos->contains('is_360', true)) {
            $issues[] = ['code' => 'no_360', 'label' => 'No 360° tour', 'tip' => 'Buyers can filter for virtual tours, and they stay longer on listings that have one.', 'fix' => $edit];
        }
        if (mb_strlen(trim((string) $p->description)) < 150) {
            $issues[] = ['code' => 'short_description', 'label' => 'Short description', 'tip' => 'A few more lines on the layout, area and nearby places help buyers decide.', 'fix' => ['label' => 'Write one for me', 'prompt' => "Write a description for “{$p->title}”"]];
        }
        if ($this->areaOf($p) === null) {
            $issues[] = ['code' => 'no_area', 'label' => $p->type === 'lot' ? 'Lot area missing' : 'Floor area missing', 'tip' => 'Buyers compare homes by price per square metre.', 'fix' => $edit];
        }
        if ($p->status === 'published' && (int) $p->views === 0 && $p->created_at?->lt(now()->subDays(3))) {
            $issues[] = ['code' => 'no_views', 'label' => 'No views yet', 'tip' => 'The price or the cover photo may be holding it back.', 'fix' => ['label' => 'Check the price', 'prompt' => "Price check “{$p->title}”"]];
        }
        $price = (float) $p->price;
        if ($price > 0 && (($p->offer_type === 'rent' && $price > Property::RENT_MAX_SANE) || ($p->offer_type === 'sale' && $price < Property::SALE_MIN_SANE))) {
            $issues[] = ['code' => 'price_off', 'label' => 'The price looks off', 'tip' => $this->peso($price) . ' is unusual for a ' . ($p->offer_type === 'rent' ? 'monthly rent' : 'sale') . '. Is it a typo?', 'fix' => $edit];
        }

        return $issues;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Drafting replies (never sent from here)
    // ─────────────────────────────────────────────────────────────────────

    private function draftReply(User $agent, array $ask): array
    {
        $target = $ask['target'] ?? null;
        $text   = (string) $ask['text'];
        $found  = null;

        foreach ($target ? [$target] : ['chat', 'inquiry', 'review'] as $kind) {
            $found = match ($kind) {
                'chat'    => $this->chatToAnswer($agent, $text),
                'inquiry' => $this->inquiryToAnswer($agent, $text),
                'review'  => $this->reviewToAnswer($agent, $text),
            };
            if ($found) {
                break;
            }
        }

        if ($found === null) {
            $what = ['chat' => 'chat waiting for your reply', 'inquiry' => 'inquiry', 'review' => 'review'][$target] ?? 'chat, inquiry or review waiting for a reply';

            return ['reply' => "I couldn't find a {$what}. You're all caught up!", 'card' => null];
        }

        $first = Str::before((string) $agent->name, ' ');
        $draft = $this->gemini->draftAgentReply($found['kind'], $found['context'], (string) $agent->name, $text)
            ?? $found['fallback'];

        $label = ['chat' => 'message', 'inquiry' => 'inquiry', 'review' => 'review'][$found['kind']];

        return [
            'reply' => "Here's a draft reply to {$found['card']['to']}'s {$label}. Edit it if you like; I won't send anything for you.",
            'card'  => ['type' => 'draft', 'kind' => $found['kind'], 'text' => $draft, 'signed' => $first] + $found['card'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function chatToAnswer(User $agent, string $text): ?array
    {
        $chats = Conversation::query()->where('agent_id', $agent->id)->whereNull('agent_deleted_at')
            ->with(['buyer:id,name', 'property'])->orderByDesc('last_message_at')->limit(30)->get();

        $chat = $this->byPerson($chats, fn (Conversation $c) => (string) $c->buyer?->name, $text)
            ?? $chats->first(function (Conversation $c) use ($agent) {
                $last = $c->messages()->latest('id')->first();

                return $last && $last->sender_id !== $agent->id && ! $last->is_ai;
            });
        if (! $chat) {
            return null;
        }

        $lines = $chat->messages()->latest('id')->limit(8)->get()->reverse()
            ->map(fn ($m) => ($m->sender_id === $agent->id || $m->is_ai ? 'Agent' : 'Buyer') . ': ' . Str::limit((string) $m->body, 400))->implode("\n");
        $lastBuyer = $chat->messages()->where('sender_id', '!=', $agent->id)->where('is_ai', false)->latest('id')->value('body');

        return [
            'kind'     => 'chat',
            'context'  => $this->facts($chat->property) . "\n\nConversation so far:\n{$lines}",
            'fallback' => 'Hi ' . Str::before((string) $chat->buyer?->name, ' ') . '! Thanks for your message about ' . ($chat->property?->title ?? 'the listing') . '. Let me check and get back to you shortly.',
            'card'     => ['target_id' => $chat->id, 'to' => (string) $chat->buyer?->name, 'about' => $chat->property?->title, 'quote' => Str::limit((string) $lastBuyer, 160)],
        ];
    }

    /** @return array<string, mixed>|null */
    private function inquiryToAnswer(User $agent, string $text): ?array
    {
        $all = Inquiry::query()->whereHas('property', fn ($q) => $q->where('agent_id', $agent->id))
            ->with('property')->orderBy('is_read')->latest()->limit(30)->get();
        $inquiry = $this->byPerson($all, fn (Inquiry $i) => (string) $i->name, $text) ?? $all->first();
        if (! $inquiry) {
            return null;
        }

        $title = $inquiry->property?->title ?? 'your listing';

        return [
            'kind'     => 'inquiry',
            'context'  => $this->facts($inquiry->property) . "\n\nInquiry from {$inquiry->name}:\n" . Str::limit((string) $inquiry->message, 1500),
            'fallback' => 'Hi ' . Str::before((string) $inquiry->name, ' ') . ",\n\nThank you for your interest in {$title}. It's still available, and I'd be happy to answer any questions or arrange a viewing. You can book a time directly on RealtyLink PH.\n\nBest regards,\n" . Str::before((string) $agent->name, ' '),
            'card'     => [
                'target_id' => $inquiry->id,
                'to'        => (string) $inquiry->name,
                'email'     => $inquiry->email,
                'subject'   => "Re: {$title}",
                'about'     => $inquiry->property?->title,
                'quote'     => Str::limit((string) $inquiry->message, 160),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function reviewToAnswer(User $agent, string $text): ?array
    {
        $all = $agent->reviewsReceived()->where('is_visible', true)->with('buyer:id,name')
            ->orderByRaw('agent_reply IS NOT NULL')->latest()->limit(30)->get();
        $review = $this->byPerson($all, fn (AgentReview $r) => (string) $r->buyer?->name, $text) ?? $all->first();
        if (! $review) {
            return null;
        }

        $name = (string) $review->buyer?->name;

        return [
            'kind'     => 'review',
            'context'  => "Review from {$name}, {$review->rating} out of 5 stars:\n" . ($review->review_text ? Str::limit((string) $review->review_text, 1500) : '(no written comment)')
                . ($review->agent_reply ? "\n\nThe agent's current reply (to rewrite): {$review->agent_reply}" : ''),
            'fallback' => $review->rating >= 4
                ? 'Thank you so much, ' . Str::before($name, ' ') . '! It was a pleasure helping you, and I hope to work with you again.'
                : 'Thank you for the honest feedback, ' . Str::before($name, ' ') . '. I\'m sorry it fell short, and I\'ll use this to do better. Please message me anytime if there\'s anything I can still help with.',
            'card'     => ['target_id' => $review->id, 'to' => $name, 'rating' => (int) $review->rating, 'quote' => Str::limit((string) $review->review_text, 160)],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tasks: a card the agent approves
    // ─────────────────────────────────────────────────────────────────────

    private function confirmViewing(User $agent, array $ask): array
    {
        $pending = $agent->appointmentsAsAgent()->with(['buyer:id,name', 'property:id,title'])
            ->where('status', 'pending')->where('preferred_datetime', '>=', now())
            ->when($ask['date'] ?? null, fn ($q, $d) => $q->whereDate('preferred_datetime', $d->toDateString()))
            ->orderBy('preferred_datetime')->get();

        if ($pending->isEmpty()) {
            return ['reply' => 'No viewing requests are waiting for confirmation' . (($ask['date'] ?? null) ? ' on ' . $this->dayLabel($ask['date']) : '') . '.', 'card' => null];
        }

        $one = $this->byPerson($pending, fn (Appointment $a) => (string) $a->buyer?->name, (string) $ask['text'])
            ?? ($pending->count() === 1 ? $pending->first() : null);

        if ($one === null) {
            return [
                'reply' => "{$pending->count()} viewing requests are waiting. Which one? You can confirm any of them below.",
                'card'  => ['type' => 'viewings', 'items' => $pending->take(self::PER_REPLY)->map(fn (Appointment $a) => $this->viewingItem($a))->values()->all(), 'total' => $pending->count(), 'link' => '/dashboard/appointments'],
            ];
        }

        $at       = CarbonImmutable::instance($one->preferred_datetime);
        $clash    = $agent->appointmentsAsAgent()->where('status', 'confirmed')->where('id', '!=', $one->id)
            ->whereBetween('preferred_datetime', [$at->subMinutes(59), $at->addMinutes(59)])->with('buyer:id,name')->first();

        return [
            'reply' => "Ready to confirm {$one->buyer?->name}'s viewing. They'll be notified" . ($agent->google_access_token ? ' and it goes on your Google Calendar' : '') . '.',
            'card'  => $this->task('confirm_viewing', 'Confirm viewing', [
                ['label' => 'Buyer', 'value' => (string) $one->buyer?->name],
                ['label' => 'Listing', 'value' => (string) $one->property?->title],
                ['label' => 'When', 'value' => $this->whenLabel($at)],
            ], 'Confirm viewing', ['appointment_id' => $one->id], $clash ? "You already have a confirmed viewing with {$clash->buyer?->name} at " . $clash->preferred_datetime->format('g:i A') . '.' : null),
        ];
    }

    private function block(User $agent, array $ask): array
    {
        /** @var CarbonImmutable|null $day */
        $day   = $ask['date'] ?? null;
        $hours = $ask['intent'] === 'block_hours' ? ($ask['hours'] ?? null) : null;

        if ($day === null) {
            return ['reply' => 'Which day should I block? For example: "block tomorrow", "block Friday afternoon" or "block Oct 30, 2pm to 5pm".', 'card' => null];
        }
        if ($day->lt(CarbonImmutable::today())) {
            return ['reply' => $this->dayLabel($day) . ' has already passed. Which upcoming day did you mean?', 'card' => null];
        }

        $already = AgentBlockedDate::query()->where('agent_id', $agent->id)->whereDate('blocked_date', $day->toDateString())->whereNull('start_time')->exists();
        if ($already) {
            return ['reply' => $this->dayLabel($day) . ' is already blocked for the whole day.', 'card' => ['type' => 'action_link', 'label' => 'Open Calendar', 'href' => '/dashboard/calendar']];
        }

        $viewings = $agent->appointmentsAsAgent()->whereIn('status', ['pending', 'confirmed'])->whereDate('preferred_datetime', $day->toDateString())->get()
            ->filter(function (Appointment $a) use ($hours) {
                if (! $hours) {
                    return true;
                }
                $t = $a->preferred_datetime->format('H:i');

                return $t >= $hours[0] && $t < $hours[1];
            });
        $warning = $viewings->isNotEmpty()
            ? "You have {$viewings->count()} " . Str::plural('viewing', $viewings->count()) . ' booked then. Blocking stops new bookings only; those stay until you decline them.'
            : null;

        $range = $hours ? $this->timeLabel($hours[0]) . '–' . $this->timeLabel($hours[1]) : 'All day';

        return [
            'reply' => $hours
                ? "I'll block {$range} on " . $this->dayLabel($day) . ' so buyers can\'t book then.'
                : "I'll block all of " . $this->dayLabel($day) . ' so buyers can\'t book a viewing that day.',
            'card'  => $this->task($hours ? 'block_hours' : 'block_day', $hours ? 'Block hours' : 'Block a day', [
                ['label' => 'Day', 'value' => $this->dayLabel($day)],
                ['label' => 'Time', 'value' => $range],
            ], $hours ? 'Block these hours' : 'Block the day', ['date' => $day->toDateString(), 'start' => $hours[0] ?? null, 'end' => $hours[1] ?? null], $warning),
        ];
    }

    private function listingTask(User $agent, array $ask): array
    {
        $task  = $ask['intent'];
        $scope = ['publish' => ['draft'], 'unpublish' => ['published'], 'mark_sold' => ['published', 'draft']][$task];
        [$listing, $candidates] = $this->resolveListing($agent, $ask['text'], $scope);

        // Named a listing that's in the wrong state.
        if ($listing === null) {
            [$any] = $this->resolveListing($agent, $ask['text'], ['draft', 'published', 'sold']);
            if ($any !== null && ! in_array($any->status, $scope, true)) {
                return ['reply' => match (true) {
                    $any->status === 'sold'      => "“{$any->title}” is already marked sold. To sell it again, re-list it from History.",
                    $task === 'publish'          => "“{$any->title}” is already live.",
                    default                      => "“{$any->title}” isn't published, so there's nothing to take down.",
                }, 'card' => null];
            }

            $question = ['publish' => 'Which draft should I publish?', 'unpublish' => 'Which listing should I take down?', 'mark_sold' => 'Which listing was sold?'][$task];

            return $this->whichListing($candidates, $task, $candidates->isEmpty()
                ? ['publish' => 'You have no drafts to publish.', 'unpublish' => 'None of your listings are live right now.', 'mark_sold' => 'You have no active listings to mark sold.'][$task]
                : $question);
        }

        $lines = [
            ['label' => 'Listing', 'value' => $listing->title],
            ['label' => 'Price', 'value' => $this->peso((float) $listing->price) . ($listing->offer_type === 'rent' ? '/month' : '')],
        ];
        $args = ['property_id' => $listing->id];

        if ($task === 'publish') {
            $missing = $this->properties->missingForPublish($listing);
            if ($missing) {
                return [
                    'reply' => "“{$listing->title}” can't go live yet: it still needs " . implode(', ', $missing) . '. Add ' . (count($missing) === 1 ? 'it' : 'them') . ' and ask me again.',
                    'card'  => ['type' => 'action_link', 'label' => 'Finish the listing', 'href' => "/dashboard/listings/{$listing->id}/edit"],
                ];
            }

            return ['reply' => "Ready to publish “{$listing->title}”. Buyers will see it on Browse right away.",
                'card' => $this->task('publish', 'Publish listing', $lines, 'Publish now', $args)];
        }
        if ($task === 'unpublish') {
            return ['reply' => "This hides “{$listing->title}” from buyers and keeps it as a draft. You can publish it again anytime.",
                'card' => $this->task('unpublish', 'Unpublish listing', $lines, 'Unpublish', $args, null, 'danger')];
        }

        $open = $listing->appointments()->whereIn('status', ['pending', 'confirmed'])->where('preferred_datetime', '>=', now())->count();

        return ['reply' => "Congratulations! 🎉 Marking “{$listing->title}” sold takes it off Browse and moves it to History.",
            'card' => $this->task('mark_sold', 'Mark as sold', $lines, 'Mark as sold', $args,
                $open ? "{$open} upcoming " . Str::plural('viewing', $open) . ' for this listing will still be on your schedule; decline ' . ($open === 1 ? 'it' : 'them') . ' from Appointments.' : null)];
    }

    /** @param array<int, array{label: string, value: string}> $lines */
    private function task(string $task, string $title, array $lines, string $cta, array $args, ?string $warning = null, string $tone = 'primary'): array
    {
        return ['type' => 'task', 'task' => $task, 'title' => $title, 'lines' => $lines, 'cta' => $cta, 'args' => $args, 'warning' => $warning, 'tone' => $tone, 'state' => 'ready'];
    }

    /** Ask which listing, offering the likely ones as cards with a one-tap pick. */
    private function whichListing(Collection $candidates, string $task, string $question): array
    {
        return [
            'reply' => $question,
            'card'  => $candidates->isEmpty() ? null : [
                'type'  => 'listings',
                'items' => $candidates->take(self::PER_REPLY)->map(fn (Property $p) => $this->listingItem($p))->values()->all(),
                'total' => $candidates->count(),
                'pick'  => ['task' => $task, 'label' => ['publish' => 'Publish this', 'unpublish' => 'Unpublish this', 'mark_sold' => 'Mark this sold', 'price_check' => 'Check this price'][$task]],
                'link'  => '/dashboard/listings',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Account actions (same cards as the buyer assistant)
    // ─────────────────────────────────────────────────────────────────────

    private function act(User $agent, string $action, ?string $page): array
    {
        $calendar = $agent->google_access_token !== null;

        $out = match ($action) {
            'theme_dark'   => ['reply' => 'Done, dark mode is on. Tap Undo if you\'d rather go back.', 'action' => ['type' => 'theme', 'mode' => 'dark']],
            'theme_light'  => ['reply' => 'Done, light mode is on. Tap Undo if you\'d rather go back.', 'action' => ['type' => 'theme', 'mode' => 'light']],
            'theme_system' => ['reply' => 'Done, the theme now follows your device\'s light or dark setting. Tap Undo to go back.', 'action' => ['type' => 'theme', 'mode' => 'system']],
            'gcal_connect' => [
                'reply'  => $calendar
                    ? 'Your Google Calendar is already connected. Confirmed viewings are added to it automatically.'
                    : 'Connecting Google Calendar adds every viewing you confirm to your calendar automatically. Tap the button below: Google will ask you to sign in and allow access, then bring you back.',
                'action' => ['type' => 'gcal_connect'],
            ],
            'gcal_disconnect' => [
                'reply'  => $calendar
                    ? 'Disconnecting stops new viewings from syncing to your Google Calendar; viewings already there stay. Tap the button below and confirm.'
                    : 'Your Google Calendar isn\'t connected, so there\'s nothing to disconnect.',
                'action' => ['type' => 'gcal_disconnect'],
            ],
            'password' => $agent->password_set !== false
                ? ['reply' => 'You can change it right here. For your safety, type passwords only in the secure form below, never in the chat. The form goes straight to RealtyLink PH, not to me.', 'action' => ['type' => 'password', 'can_change' => true]]
                : ['reply' => 'Your account signs in with Google, so there\'s no RealtyLink PH password to change. To also sign in with email and a password, choose "Forgot password" on the sign-in screen.', 'action' => ['type' => 'password', 'can_change' => false]],
            'open_page' => isset(self::PAGES[$page]) ? [
                'reply'  => 'Here you go. Tap below to open ' . self::PAGES[$page][0] . '.',
                'action' => ['type' => 'open_page', 'page' => $page, 'label' => self::PAGES[$page][0], 'href' => self::PAGES[$page][1]],
            ] : null,
            default => null,
        };

        return $out ?? [
            'reply' => "Here's what I can do for you:\n"
                . "• Show your listings, viewings, inquiries and reviews\n"
                . "• Confirm a viewing, block a day or a few hours, publish, unpublish or mark a listing sold (you approve each one)\n"
                . "• Check a listing's price against similar listings, or check what to improve on your listings\n"
                . "• Draft replies to inquiries, reviews and chats for you to send\n"
                . "• Write descriptions (attach a photo) and draft new listings from a few details\n"
                . "• Switch dark or light mode, connect Google Calendar, change your password, or open any page\n"
                . 'What would you like to start with?',
            'action' => ['type' => 'capabilities'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The listing the message names: a quoted title, or the best overlap of
     * its words with a title or address. Also returns the listings in scope,
     * for asking "which one?".
     *
     * @param  array<int, string>  $statuses
     * @return array{0: ?Property, 1: Collection<int, Property>}
     */
    private function resolveListing(User $agent, string $text, array $statuses): array
    {
        $candidates = $agent->properties()->with('photos')->whereIn('status', $statuses)->latest()->limit(50)->get();
        $t = mb_strtolower($text);

        if (preg_match('/[“"\'‘](.{3,120}?)[”"\'’]/u', $text, $m)) {
            $quoted = mb_strtolower(trim($m[1]));
            $hit = $candidates->first(fn (Property $p) => mb_strtolower($p->title) === $quoted)
                ?? $candidates->first(fn (Property $p) => str_contains(mb_strtolower($p->title), $quoted));
            if ($hit) {
                return [$hit, $candidates];
            }
        }

        // Numbers count whatever their length: "Tower 3" isn't "Tower 1".
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $t) ?: [],
            fn ($w) => (mb_strlen($w) >= 3 || ctype_digit($w)) && ! in_array($w, self::STOP, true),
        ));

        if ($words) {
            $scored = $candidates->map(function (Property $p) use ($words, $t) {
                $hay = mb_strtolower($p->title . ' ' . $p->address);
                // The whole title typed out beats any word overlap.
                $score = str_contains($t, mb_strtolower(trim($p->title, " !.\t"))) ? 100 : 0;
                foreach ($words as $w) {
                    $score += preg_match('/\b' . preg_quote($w, '/') . '\b/u', $hay) ? (str_contains(mb_strtolower($p->title), $w) ? 2 : 1) : 0;
                }

                return ['p' => $p, 'score' => $score];
            })->sortByDesc('score')->values();

            $best = $scored->first();
            if ($best && $best['score'] > 0 && ($scored->count() === 1 || $scored[1]['score'] < $best['score'])) {
                return [$best['p'], $candidates];
            }
        }

        // "publish it" / "my draft" with only one in scope.
        if ($candidates->count() === 1 && ! $words) {
            return [$candidates->first(), $candidates];
        }

        return [null, $candidates];
    }

    /**
     * The row whose person the message names: full name, or a first name
     * that only one of them has.
     *
     * @template T
     * @param  Collection<int, T>  $rows
     * @return T|null
     */
    private function byPerson(Collection $rows, callable $name, string $text): mixed
    {
        $t = mb_strtolower($text);
        $full = $rows->first(fn ($r) => ($n = mb_strtolower(trim($name($r)))) !== '' && str_contains($t, $n));
        if ($full) {
            return $full;
        }

        $byFirst = $rows->filter(function ($r) use ($name, $t) {
            $first = mb_strtolower(Str::before(trim($name($r)), ' '));

            return mb_strlen($first) >= 3 && ! in_array($first, self::STOP, true) && preg_match('/\b' . preg_quote($first, '/') . '\b/u', $t);
        });
        $people = $byFirst->map(fn ($r) => mb_strtolower(trim($name($r))))->unique();

        return $people->count() === 1 ? $byFirst->first() : null;
    }

    /** "a 120sqm lot for sale in Cebu" → type, offer, size, places. */
    private function specIn(string $t): ?array
    {
        $type = null;
        foreach (self::TYPE_WORDS as $kind => $re) {
            if (preg_match($re, $t)) {
                $type = $kind;
                break;
            }
        }
        if ($type === null || ! preg_match('/\b(in|at|sa|near)\s+([\p{L}][\p{L}\s.\-]{2,40}?)(?=[,.?!]|\s+(for|with|that|which|and)\b|$)/u', $t, $m)) {
            return null;
        }

        $place = Str::title(trim($m[2]));
        $parts = array_values(array_filter([$place, trim((string) preg_replace('/\s+city$/iu', '', $place))]));
        $area  = preg_match('/(\d+(?:\.\d+)?)\s*(sqm|sq\.?\s?m|square met(er|re)s?|m2|m²)/u', $t, $a) ? (float) $a[1] : null;

        return [
            'type'       => $type,
            'offer_type' => preg_match('/\b(rent|rental|lease|monthly|per month)\b/u', $t) ? 'rent' : 'sale',
            'area'       => $area,
            'places'     => array_values(array_unique($parts)),
        ];
    }

    /** "Unit 5, Brgy Lahug, Cebu City, Cebu" → ["Brgy Lahug", "Cebu City", "Cebu"]: specific first, street dropped. */
    private function placesOf(?string $address): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $address)), fn ($p) => mb_strlen($p) >= 3));
        // Drop the street ("123 Main St"), but keep a short "BGC, Taguig".
        if (count($parts) > 1 && (count($parts) >= 3 || preg_match('/\d/', $parts[0]))) {
            array_shift($parts);
        }

        return array_values(array_filter($parts, fn ($p) => ! preg_match('/^(philippines|ph)$/i', $p)));
    }

    private function areaOf(Property $p, ?string $type = null): ?float
    {
        $a = ($type ?? $p->type) === 'lot' ? ($p->lot_area ?? $p->floor_area) : ($p->floor_area ?? null);

        return $a !== null && (float) $a > 0 ? (float) $a : null;
    }

    /** @param Collection<int, float> $sorted */
    private function quantile(Collection $sorted, float $q): float
    {
        $n = $sorted->count();
        if ($n === 1) {
            return (float) $sorted[0];
        }
        $pos = ($n - 1) * $q;
        $lo  = (int) floor($pos);

        return (float) $sorted[$lo] + ($pos - $lo) * ((float) ($sorted[$lo + 1] ?? $sorted[$lo]) - (float) $sorted[$lo]);
    }

    private function facts(?Property $p): string
    {
        if (! $p) {
            return 'Listing: (no longer available)';
        }

        return "Listing: {$p->title} — {$p->type} for {$p->offer_type}, " . $this->peso((float) $p->price) . ($p->offer_type === 'rent' ? '/month' : '')
            . ($p->bedrooms ? ", {$p->bedrooms} bedrooms" : '') . ($p->bathrooms ? ", {$p->bathrooms} bathrooms" : '')
            . ($p->floor_area ? ", {$p->floor_area} sqm floor area" : '') . ($p->lot_area ? ", {$p->lot_area} sqm lot" : '')
            . ", {$p->address}. Status: {$p->status}.";
    }

    private function listingItem(Property $p): array
    {
        $photo = $p->photos->where('is_360', false)->sortBy('sort_order')->first() ?? $p->photos->first();

        return [
            'id'          => $p->id,
            'title'       => $p->title,
            'status'      => $p->status,
            'type'        => $p->type,
            'offer_type'  => $p->offer_type,
            'price'       => (float) $p->price,
            'address'     => $p->address,
            'views'       => (int) $p->views,
            'inquiries'   => $p->inquiries_count ?? null,
            'photos'      => $p->photos->count(),
            'has_360'     => $p->photos->contains('is_360', true),
            'thumb'       => $photo ? Uploads::url($photo->thumb_url ?? $photo->url) : null,
        ];
    }

    private function viewingItem(Appointment $a): array
    {
        return [
            'id'       => $a->id,
            'status'   => $a->status,
            'at'       => $a->preferred_datetime->toIso8601String(),
            'buyer'    => (string) $a->buyer?->name,
            'listing'  => $a->property?->title,
            'notes'    => $a->notes ? Str::limit((string) $a->notes, 120) : null,
        ];
    }

    private function inquiryItem(Inquiry $i): array
    {
        return [
            'id'         => $i->id,
            'name'       => $i->name,
            'email'      => $i->email,
            'phone'      => $i->phone,
            'message'    => Str::limit((string) $i->message, 140),
            'is_read'    => (bool) $i->is_read,
            'listing'    => $i->property?->title,
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }

    private function reviewItem(AgentReview $r): array
    {
        return [
            'id'         => $r->id,
            'rating'     => (int) $r->rating,
            'text'       => $r->review_text ? Str::limit((string) $r->review_text, 160) : null,
            'buyer'      => (string) $r->buyer?->name,
            'replied'    => $r->agent_reply !== null,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private function dayLabel(CarbonImmutable $d): string
    {
        $today = CarbonImmutable::today();

        return match (true) {
            $d->isSameDay($today)          => 'today (' . $d->format('D, M j') . ')',
            $d->isSameDay($today->addDay()) => 'tomorrow (' . $d->format('D, M j') . ')',
            default                        => $d->format('D, M j') . ($d->year !== $today->year ? ', ' . $d->year : ''),
        };
    }

    private function whenLabel(CarbonImmutable $at): string
    {
        return $this->dayLabel($at->startOfDay()) . ' at ' . $at->format('g:i A');
    }

    private function timeLabel(string $hm): string
    {
        return CarbonImmutable::createFromFormat('H:i', $hm)->format('g:i A');
    }

    private function peso(float $n): string
    {
        return match (true) {
            $n >= 1_000_000 => '₱' . rtrim(rtrim(number_format($n / 1_000_000, 2), '0'), '.') . 'M',
            $n >= 10_000    => '₱' . number_format($n / 1_000, 0) . 'K',
            default         => '₱' . number_format($n),
        };
    }
}
