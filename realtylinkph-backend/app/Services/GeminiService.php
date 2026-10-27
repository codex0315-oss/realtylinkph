<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Property;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\AgentCredentialReference;
use App\Support\Documents;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GeminiService
{
    private function endpoint(string $model): string
    {
        return "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    }

    /**
     * The model to try first, then resilient fallbacks for when it's overloaded
     * (Gemini returns 503 UNAVAILABLE under high demand) or rate-limited.
     *
     * @return array<int, string>
     */
    private function models(): array
    {
        $primary = config('services.gemini.model', 'gemini-2.5-flash');

        // Primary, then a fallback with its own free-tier quota. (gemini-2.0-flash
        // was retired by Google in Oct 2026 and now returns 404.)
        return array_values(array_unique([
            $primary,
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
        ]));
    }

    /**
     * Low-level Gemini call. Tries the primary model then ONE fallback, each with a
     * short timeout — deliberately bounded so a synchronous request can never reach
     * PHP's max_execution_time (which previously caused a fatal error + CORS failure).
     * Returns the model's text, or null on failure.
     */
    /**
     * @param  int  $timeout  Per-model seconds. The default suits a web request
     *                        (2 models × 12s stays under the 60s limit); queued
     *                        jobs pass more, since multi-megabyte document
     *                        payloads routinely need it.
     */
    private function call(array $payload, int $timeout = 12): ?string
    {
        $models = array_slice($this->models(), 0, 2);

        foreach ($models as $i => $model) {
            try {
                $response = Http::withQueryParameters(['key' => config('services.gemini.api_key')])
                    ->timeout($timeout)
                    ->post($this->endpoint($model), $payload);

                if ($response->successful()) {
                    $text = $response->json('candidates.0.content.parts.0.text');
                    if (is_string($text) && $text !== '') {
                        return $text;
                    }
                }

                Log::warning('Gemini call failed', ['model' => $model, 'status' => $response->status()]);
            } catch (\Throwable $e) {
                Log::warning('Gemini call exception', ['model' => $model, 'error' => $e->getMessage()]);
            }

            if ($i < count($models) - 1) {
                usleep(250_000); // brief pause before the fallback model
            }
        }

        return $this->viaGroq($payload);
    }

    /**
     * Last resort when both Gemini models fail (the free tier's daily quota is
     * small): the same request through Groq, if it's configured. Text only — a
     * request carrying images (documents, photos) has no Groq equivalent here.
     */
    private function viaGroq(array $payload): ?string
    {
        $groq = app(GroqService::class);
        if (! $groq->isConfigured()) {
            return null;
        }

        $history = [];
        foreach ($payload['contents'] ?? [] as $c) {
            $text = '';
            foreach ($c['parts'] ?? [] as $part) {
                if (isset($part['inline_data'])) {
                    return null;
                }
                $text .= $part['text'] ?? '';
            }
            $history[] = ['role' => ($c['role'] ?? 'user') === 'model' ? 'assistant' : 'user', 'content' => $text];
        }

        $system = $payload['systemInstruction']['parts'][0]['text'] ?? 'You are a helpful assistant.';
        if (($payload['generationConfig']['responseMimeType'] ?? null) === 'application/json') {
            $system .= "\n\nReply with the JSON object only: no code fences, no other text.";
        }

        // Generous budget: Groq's model reasons before it answers, and that counts.
        $text = $groq->chat($system, $history, (float) ($payload['generationConfig']['temperature'] ?? 0.6), 1200);

        return $text !== null ? self::stripFences($text) : null;
    }

    /** "```json {…} ```" → "{…}". */
    private static function stripFences(string $text): string
    {
        return trim((string) preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', $text));
    }

    /**
     * Map a chat history ([{role, content}]) to Gemini's "contents" format.
     */
    private function toContents(array $history): array
    {
        return array_map(static function (array $m): array {
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $text = (string) ($m['content'] ?? $m['text'] ?? '');

            return ['role' => $role, 'parts' => [['text' => $text]]];
        }, $history);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Existing features (now on a current model)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{mime:string,data:string}>  $images  optional property photos (base64)
     */
    public function generatePropertyDescription(array $attributes, array $images = []): string
    {
        $parts = [['text' => $this->buildDescriptionPrompt($attributes, ! empty($images))]];

        foreach ($images as $img) {
            $parts[] = ['inline_data' => ['mime_type' => $img['mime'], 'data' => $img['data']]];
        }

        $text = $this->call([
            'contents'         => [['parts' => $parts]],
            'generationConfig' => ['temperature' => 0.7],
        ]);

        return $text ?? '';
    }

    public function answerPropertyQuestion(Property $property, string $question): string
    {
        $context = "Property: {$property->title}\n"
            . "Type: {$property->type}\n"
            . "Price: ₱{$property->price}\n"
            . "Bedrooms: {$property->bedrooms}, Bathrooms: {$property->bathrooms}\n"
            . "Address: {$property->address}\n"
            . "Description: {$property->description}\n\n"
            . "Buyer question: {$question}";

        $text = $this->call([
            'contents'          => [['parts' => [['text' => $context]]]],
            'systemInstruction' => ['parts' => [['text' => 'You are a helpful real estate assistant in the Philippines. Answer questions about properties concisely and accurately based on the provided information. Keep answers under 200 words.']]],
        ]);

        return $text ?? 'I could not process your question at this time.';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RealtyLink AI — stand-in replies while the agent is offline
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A warm, factual reply to a buyer's chat message while the listing's agent
     * is away. Grounded on the listing and the thread so far. May also answer
     * general Philippine real-estate questions, but never negotiates, never
     * promises the agent's time or availability, and never invents details.
     *
     * @param  array<int, array{role:string, content:string}>  $history  oldest first, last entry is the buyer's latest
     */
    public function autoReplyWhileAway(Property $property, User $agent, string $buyerName, array $history): ?string
    {
        // Prompt is shared with GroqService (App\Support\AutoReplyPrompt) so the
        // fallback provider is held to exactly the same rules.
        $system = \App\Support\AutoReplyPrompt::system($property, $agent, $buyerName);

        $text = $this->call([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => $this->toContents($history),
            'generationConfig'  => ['temperature' => 0.6, 'maxOutputTokens' => 220],
        ]);

        return $text !== null ? trim($text) : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RealtyLink AI — buyer assistant
    // ─────────────────────────────────────────────────────────────────────────

    /** Words that name each property type. */
    private const TYPE_WORDS = [
        'house'      => '/\b(houses?|house and lot|house & lot|bungalows?|townhouses?|town house|duplex|single[- ]detached)\b/u',
        'condo'      => '/\b(condos?|condominiums?|studio|penthouse|units?)\b/u',
        'lot'        => '/(?<!\ba )\blots?\b(?! of)|\b(land|vacant)\b/u',   // not "a lot of space"
        'commercial' => '/\b(commercial|office|shop|store|warehouse|business space)\b/u',
        'apartment'  => '/\b(apartments?|apt|flat)\b/u',
    ];

    /** Everything the buyer (not the assistant) said, lowercased. */
    private function buyerText(array $history): string
    {
        $text = '';
        foreach ($history as $m) {
            if (($m['role'] ?? 'user') !== 'assistant') {
                $text .= ' ' . mb_strtolower((string) ($m['content'] ?? $m['text'] ?? ''));
            }
        }

        return $text;
    }

    /** Things RealtyLink AI can do in the app rather than search. */
    public const ACTIONS = ['theme_dark', 'theme_light', 'theme_system', 'gcal_connect', 'gcal_disconnect', 'password', 'open_page', 'capabilities'];

    /** Sections "open_page" can take a buyer to: key => [label, path]. */
    public const PAGES = [
        'browse'        => ['Browse listings', '/dashboard/browse'],
        'saved'         => ['Saved homes', '/dashboard/saved'],
        'appointments'  => ['Appointments', '/dashboard/appointments'],
        'history'       => ['History', '/dashboard/history'],
        'reviews'       => ['Reviews', '/dashboard/reviews'],
        'messages'      => ['Messages', '/dashboard?panel=messages'],
        'notifications' => ['Notifications', '/dashboard?panel=notifications'],
        'profile'       => ['My Profile', '/dashboard/profile'],
        'verify'        => ['Become an agent', '/dashboard/verify'],
    ];

    /** Places a buyer can ask to be near; keys match NearbyService::KINDS. */
    private const NEAR_KINDS = ['school', 'hospital', 'clinic', 'pharmacy', 'mall', 'grocery', 'market', 'church', 'transit', 'bank', 'police', 'fire'];

    /**
     * Extract structured search criteria from the conversation.
     *
     * The search fields describe the whole conversation; `more`, `saved` and
     * `action` (+ `page`) describe only the buyer's latest message.
     *
     * @return array{search:?string,type:?string,offer_type:?string,min_price:?float,max_price:?float,bedrooms:?int,bathrooms:?int,near:array<int,string>,has_360:bool,sort:?string,more:bool,saved:bool,action:?string,page:?string}
     */
    public function extractSearchCriteria(array $history): array
    {
        $system = 'Extract the buyer\'s property search criteria from the conversation. '
            . 'Respond ONLY with a JSON object using these keys: '
            . 'location (string or null: a city, municipality, barangay or area as it would appear in an address; for a landmark, give its city), '
            . 'type (one of: house, condo, lot, commercial, apartment, or null — "home", "homes", "place" or "property" alone is null, not house), '
            . 'offer_type (one of: sale, rent, or null — choose "rent" if they mention renting, leasing, or a MONTHLY budget; "sale" if buying/purchasing; null if they want either), '
            . 'min_price (number or null), max_price (number or null), bedrooms (integer or null, the minimum), bathrooms (integer or null, the minimum), '
            . 'near (array of zero or more of: ' . implode(', ', self::NEAR_KINDS) . ' — places they want the home to be close to; '
            . 'university/college count as school, supermarket as grocery, palengke as market, drugstore as pharmacy, jeepney/bus/MRT/LRT as transit, ATM as bank), '
            . 'has_360 (true only if they want a 360° or virtual tour), '
            . 'sort (price_asc if they want the cheapest/most affordable first, price_desc for the most expensive first, newest for the latest listings, else null), '
            . 'more (true only if their LATEST message asks for more or different listings for the same search, e.g. "show me more", "any others?"), '
            . 'saved (true only if their LATEST message is about the homes they saved or favorited), '
            . 'action (null unless their LATEST message asks the app to do something other than find homes; then one of: '
            . 'theme_dark, theme_light, theme_system (switch dark/light mode, or follow the device; "turn off light mode" is theme_dark, "turn off dark mode" is theme_light), '
            . 'gcal_connect, gcal_disconnect (connect or disconnect Google Calendar), '
            . 'password (change or reset their password), '
            . 'open_page (go to or show a section of their account), '
            . 'capabilities (what can you do / how can you help)), '
            . 'page (only with open_page: one of ' . implode(', ', array_keys(self::PAGES)) . '; "become an agent" is verify, "favorites" is saved, "viewings" is appointments, "past viewings" is history, "settings" or "account" is profile). '
            . 'Use null when a value is not specified. Prices are in Philippine pesos — interpret shorthand like "5M" as 5000000, "500k" as 500000, "25 thousand" as 25000. '
            . 'A stated budget is the MAXIMUM (max_price). Only fill a field if the buyer clearly stated or implied it. '
            . 'Later messages override earlier ones (a new budget replaces the old one).';

        $text = $this->call([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => $this->toContents($history),
            'generationConfig'  => ['responseMimeType' => 'application/json', 'temperature' => 0],
        ]);

        $data = json_decode($text ?? '', true);
        if (! is_array($data)) {
            $data = [];
        }

        // Heuristic backstop — fills fields the model missed (or everything if the
        // model was unavailable). Keeps the assistant useful without an LLM.
        $heur  = $this->heuristicCriteria($history);
        $types = ['house', 'condo', 'lot', 'commercial', 'apartment'];

        $type      = in_array($data['type'] ?? null, $types, true) ? $data['type'] : $heur['type'];
        // The model reads "homes" as house; keep a type only if the buyer used a word for it.
        if ($type !== null && ! preg_match(self::TYPE_WORDS[$type], $this->buyerText($history))) {
            $type = $heur['type'];
        }
        $offerType = in_array($data['offer_type'] ?? null, ['sale', 'rent'], true) ? $data['offer_type'] : $heur['offer_type'];
        // "for sale or rent" is either; the model tends to pick one.
        $lastAsk = mb_strtolower((string) (collect($history)->last(fn ($m) => ($m['role'] ?? 'user') !== 'assistant')['content'] ?? ''));
        if (preg_match('/\b(sale|buy)\b.{0,12}\bor\b.{0,12}\b(rent|lease)|\b(rent|lease)\b.{0,12}\bor\b.{0,12}\b(sale|buy)\b/u', $lastAsk)) {
            $offerType = null;
        }
        $maxPrice  = is_numeric($data['max_price'] ?? null) ? (float) $data['max_price'] : $heur['max_price'];
        $minPrice  = is_numeric($data['min_price'] ?? null) ? (float) $data['min_price'] : $heur['min_price'];
        $bedrooms  = is_numeric($data['bedrooms'] ?? null) ? (int) $data['bedrooms'] : $heur['bedrooms'];
        $bathrooms = is_numeric($data['bathrooms'] ?? null) ? (int) $data['bathrooms'] : $heur['bathrooms'];

        $near = is_array($data['near'] ?? null)
            ? array_values(array_intersect(self::NEAR_KINDS, $data['near']))
            : $heur['near'];
        $sort = in_array($data['sort'] ?? null, ['price_asc', 'price_desc', 'newest'], true) ? $data['sort'] : $heur['sort'];

        return [
            'search'     => is_string($data['location'] ?? null) && trim($data['location']) !== '' ? trim($data['location']) : null,
            'type'       => $type,
            'offer_type' => $offerType,
            'min_price'  => $minPrice,
            'max_price'  => $maxPrice,
            'bedrooms'   => $bedrooms,
            'bathrooms'  => $bathrooms,
            'near'       => array_slice($near, 0, 3),
            'has_360'    => is_bool($data['has_360'] ?? null) ? $data['has_360'] : $heur['has_360'],
            'sort'       => $sort,
            'more'       => is_bool($data['more'] ?? null) ? $data['more'] : $heur['more'],
            'saved'      => is_bool($data['saved'] ?? null) ? $data['saved'] : $heur['saved'],
            ...$this->pickAction($data, $heur),
        ];
    }

    /**
     * The model's action when it's a known one (and, for open_page, names a
     * known page); otherwise the keyword backstop's.
     *
     * @return array{action: ?string, page: ?string}
     */
    private function pickAction(array $data, array $heur): array
    {
        $action = in_array($data['action'] ?? null, self::ACTIONS, true) ? $data['action'] : null;
        $page   = isset(self::PAGES[$data['page'] ?? '']) ? $data['page'] : null;

        if ($action === 'open_page' && $page === null) {
            $action = null;
        }
        if ($action === null) {
            // A model that answered "no action" is trusted over the keywords.
            return $data === [] ? ['action' => $heur['action'], 'page' => $heur['page']] : ['action' => null, 'page' => null];
        }

        return ['action' => $action, 'page' => $action === 'open_page' ? $page : null];
    }

    /**
     * Regex-based criteria extraction from the buyer's own messages — a backstop
     * for when Gemini is unavailable, and to catch fields the model misses.
     *
     * @return array{offer_type:?string,type:?string,min_price:?float,max_price:?float,bedrooms:?int,bathrooms:?int,near:array<int,string>,has_360:bool,sort:?string,more:bool,saved:bool}
     */
    private function heuristicCriteria(array $history): array
    {
        $text   = $this->buyerText($history);
        $latest = '';
        foreach ($history as $m) {
            if (($m['role'] ?? 'user') !== 'assistant') {
                $latest = mb_strtolower((string) ($m['content'] ?? $m['text'] ?? ''));
            }
        }

        $out = [
            'offer_type' => null, 'type' => null, 'min_price' => null, 'max_price' => null, 'bedrooms' => null, 'bathrooms' => null,
            'near' => [], 'has_360' => false, 'sort' => null, 'more' => false, 'saved' => false,
            'action' => null, 'page' => null,
        ];

        // App actions, from the latest message only.
        if ($theme = self::themeAction($latest)) {
            $out['action'] = $theme;
        } elseif (str_contains($latest, 'calendar') && preg_match('/\b(disconnect|unlink|remove|stop)\b/', $latest)) {
            $out['action'] = 'gcal_disconnect';
        } elseif (str_contains($latest, 'calendar') && preg_match('/\b(connect|link|sync|add|set ?up)\b/', $latest)) {
            $out['action'] = 'gcal_connect';
        } elseif (preg_match('/\bpassword\b/', $latest)) {
            $out['action'] = 'password';
        } elseif (preg_match('/\bwhat (else )?can you do\b|\bhow can you help\b|\bwhat are you (able|capable)\b/', $latest)) {
            $out['action'] = 'capabilities';
        } elseif (preg_match('/\b(open|go to|take me to|bring me to)\s+(my\s+|the\s+)?(appointments?|viewings?|saved( homes)?|favou?rites|history|reviews?|profile|settings|account|messages?|notifications?|browse)\b/', $latest, $m)) {
            $out['action'] = 'open_page';
            $out['page']   = match (true) {
                str_starts_with($m[3], 'appointment'), str_starts_with($m[3], 'viewing') => 'appointments',
                str_starts_with($m[3], 'saved'), str_starts_with($m[3], 'favo')        => 'saved',
                str_starts_with($m[3], 'review')                                       => 'reviews',
                str_starts_with($m[3], 'message')                                      => 'messages',
                str_starts_with($m[3], 'notification')                                 => 'notifications',
                in_array($m[3], ['profile', 'settings', 'account'], true)              => 'profile',
                default                                                                => $m[3],   // history, browse
            };
        }

        if (preg_match('/(\d+)\s*(?:ba\b|bath|bathroom|t&b|cr\b|toilet)/', $text, $m)) {
            $out['bathrooms'] = (int) $m[1];
        }

        // Only read places when the buyer talks about being close to them, so
        // "a commercial lot for a school" isn't taken as "near a school".
        if (preg_match('/\b(near|nearby|close to|walking distance|beside|next to|around|minutes? from|access to)\b/', $text)) {
            $patterns = [
                'school'   => '/\b(schools?|universit(y|ies)|colleges?|campus)\b/',
                'hospital' => '/\bhospitals?\b/',
                'clinic'   => '/\bclinics?\b/',
                'pharmacy' => '/\b(pharmac(y|ies)|drugstores?|botika)\b/',
                'mall'     => '/\b(malls?|shopping)\b/',
                'grocery'  => '/\b(supermarkets?|grocer(y|ies))\b/',
                'market'   => '/\b(public market|wet market|palengke)\b/',
                'church'   => '/\b(church(es)?|chapel|parish)\b/',
                'transit'  => '/\b(jeepneys?|jeep|bus (stop|terminal)|terminal|mrt|lrt|train|public transport|commute)\b/',
                'bank'     => '/\b(banks?|atms?)\b/',
                'police'   => '/\bpolice\b/',
                'fire'     => '/\bfire station\b/',
            ];
            foreach ($patterns as $kind => $re) {
                if (preg_match($re, $text)) {
                    $out['near'][] = $kind;
                }
            }
        }

        $out['has_360'] = (bool) preg_match('/\b360\b|virtual tour/', $text);

        if (preg_match('/\b(cheapest|lowest price|most affordable|least expensive)\b/', $text)) {
            $out['sort'] = 'price_asc';
        } elseif (preg_match('/\b(most expensive|highest price|priciest)\b/', $text)) {
            $out['sort'] = 'price_desc';
        } elseif (preg_match('/\b(newest|latest|most recent)\b/', $text)) {
            $out['sort'] = 'newest';
        }

        $out['more']  = (bool) preg_match('/\b(show|see|give|any)\s+(me\s+)?(more|others?)\b|\bmore (options|listings|homes|properties|results|matches)\b|\banything else\b|\bother (options|listings|homes|ones|properties)\b/', $latest);
        $out['saved'] = (bool) preg_match('/\bmy (saved|favou?rites?|shortlist)|\bsaved (homes?|listings?|properties)\b|\b(i|i\'ve) (saved|hearted|favou?rited)\b/', $latest);

        if (preg_match('/\b(rent|rental|lease|leasing|monthly|per month|a month)\b|\/month/', $text)) {
            $out['offer_type'] = 'rent';
        } elseif (preg_match('/\b(buy|buying|purchase|purchasing|for sale|own)\b/', $text)) {
            $out['offer_type'] = 'sale';
        }

        foreach (['condo', 'apartment', 'house', 'lot', 'commercial'] as $kw) {
            if (preg_match(self::TYPE_WORDS[$kw], $text)) {
                $out['type'] = $kw;
                break;
            }
        }

        if (preg_match('/(\d+)\s*(?:br|bed|bedroom)/', $text, $m)) {
            $out['bedrooms'] = (int) $m[1];
        }

        $amount = $this->parseAmount($text);
        if ($amount !== null) {
            $out['max_price'] = $amount;   // a stated budget is a ceiling
        }

        return $out;
    }

    /**
     * "dark mode" / "switch to light" / "follow my device" → theme_dark,
     * theme_light, theme_system. "Turn off light mode" (or "turn of", as it's
     * often typed) means the other one.
     */
    public static function themeAction(string $latest): ?string
    {
        $latest = mb_strtolower($latest);
        $verb   = '\b(switch|change|turn|make|go|back|set|use|enable)\b[^.?!]{0,15}\b';
        $off    = '\b(turn(ed)?\s*of+|switch\s*of+|disable|no more|stop)\b[^.?!]{0,10}\b';

        return match (true) {
            (bool) preg_match('/' . $off . '(dark|night)(\s*(mode|theme))?\b/u', $latest)  => 'theme_light',
            (bool) preg_match('/' . $off . '(light|day)(\s*(mode|theme))?\b/u', $latest)   => 'theme_dark',
            (bool) preg_match('/\b(dark|night)\s*(mode|theme)\b|' . $verb . 'dark(er)?\b/u', $latest) => 'theme_dark',
            (bool) preg_match('/\b(light|day)\s*(mode|theme)\b|' . $verb . 'light(er)?\b/u', $latest)  => 'theme_light',
            (bool) preg_match('/\b(system|device)\s*(theme|setting)|\bfollow (my )?(device|system|phone)\b/u', $latest) => 'theme_system',
            default => null,
        };
    }

    /** Parse a peso amount from free text, handling 5M / 500k / 25 thousand / 25,000. */
    private function parseAmount(string $text): ?float
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:m\b|million)/', $text, $m)) {
            return (float) $m[1] * 1_000_000;
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:k\b|thousand)/', $text, $m)) {
            return (float) $m[1] * 1_000;
        }
        if (preg_match('/(?:₱|php|budget(?:\s+is)?|under|below|max(?:imum)?|around)\D{0,8}(\d[\d,]{3,})/', $text, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }

        return null;
    }

    /** One listing as a line of facts for the reply prompt. */
    private function listingLine(Property $p, array $nearby = [], bool $saved = false, array $near = []): string
    {
        $price = '₱' . number_format((float) $p->price) . ($p->offer_type === 'rent' ? '/month' : '');
        $line  = "{$p->title} — {$p->type} for " . ($p->offer_type === 'rent' ? 'rent' : 'sale') . ", {$price}"
            . ($p->bedrooms > 0 ? ", {$p->bedrooms}BR" : '') . ($p->bathrooms > 0 ? ", {$p->bathrooms}BA" : '')
            . ((float) $p->floor_area > 0 ? ', ' . rtrim(rtrim((string) $p->floor_area, '0'), '.') . ' sqm' : '')
            . ", {$p->address}";

        if ($p->relationLoaded('photos') && $p->photos->contains('is_360', true)) {
            $line .= '. Has a 360° tour';
        }
        if ($saved) {
            $line .= '. The buyer has saved this one';
        }
        foreach ($nearby as $kind => $fact) {
            $label = NearbyService::KINDS[$kind][0] ?? $kind;
            $line .= $fact
                ? ". Nearest {$kind}: {$fact['name']}, about " . self::distanceText($fact['distance_m'])
                : ". No {$kind} found within " . round((NearbyService::KINDS[$kind][3] ?? 0) / 1000) . " km ({$label})";
        }
        foreach (array_diff($near, array_keys($nearby)) as $kind) {
            $line .= ". Distance to a {$kind} couldn't be checked";
        }

        return $line;
    }

    private static function distanceText(int $metres): string
    {
        return $metres < 1000 ? (round($metres / 10) * 10) . ' m' : round($metres / 1000, 1) . ' km';
    }

    /**
     * Generate a friendly, grounded reply that recommends ONLY the given listings.
     *
     * @param  array<int, Property>  $properties
     * @param  array{mode?:string, total?:int, remaining?:int, more?:bool, near?:array<int,string>, nearby?:array<int,array<string,mixed>>, nearby_available?:bool, saved?:array<int,Property>, saved_ids?:array<int,int>, broadened?:?array{from:string,to:string}}  $context
     */
    public function generateAssistantReply(array $history, array $properties, array $context = []): string
    {
        $mode     = $context['mode'] ?? 'search';
        $nearby   = $context['nearby'] ?? [];
        $savedIds = $context['saved_ids'] ?? [];

        if (empty($properties)) {
            $listingText = $mode === 'saved'
                ? ($savedIds ? 'None of the buyer\'s saved homes fit what they asked.' : 'The buyer has not saved any homes yet.')
                : 'No matching listings were found in the database for the current criteria.';
        } else {
            $lines = [];
            foreach (array_values($properties) as $i => $p) {
                $lines[] = ($i + 1) . '. ' . $this->listingLine(
                    $p,
                    $nearby[$p->id] ?? [],
                    in_array($p->id, $savedIds, true),
                    ($context['nearby_available'] ?? false) ? ($context['near'] ?? []) : [],
                );
            }
            $listingText = implode("\n", $lines);
        }

        $facts = [];
        if ($mode === 'saved') {
            $facts[] = 'The buyer asked about their SAVED homes; the listings above are from their saved list. Compare them plainly on price, size, location and what each is best for.';
        } else {
            $total = (int) ($context['total'] ?? count($properties));
            $facts[] = "{$total} listing(s) match this search in total.";
            if (! empty($context['more'])) {
                $facts[] = 'The buyer asked for more options; these are listings they have NOT been shown yet.';
            }
            if (($context['remaining'] ?? 0) > 0) {
                $facts[] = "{$context['remaining']} more match(es) are not shown yet — the buyer can tap \"Show more\" under the cards.";
            }
            if (! empty($context['broadened'])) {
                $facts[] = "Nothing matched \"{$context['broadened']['from']}\" exactly, so these are from the wider \"{$context['broadened']['to']}\" area — say so.";
            }
        }
        if (! empty($context['near'])) {
            $facts[] = ($context['nearby_available'] ?? false)
                ? 'The buyer wants to be near: ' . implode(', ', $context['near']) . '. Listings are ranked by how close those are; mention the distances given (they are straight-line estimates). Never state a distance that is not given.'
                : 'The buyer wants to be near: ' . implode(', ', $context['near']) . ', but nearby-place data is unavailable right now. Say you couldn\'t check distances this time and suggest the "What\'s nearby" section on each listing page.';
        }
        if ($mode !== 'saved' && ! empty($context['saved'])) {
            $facts[] = "For reference, the buyer's saved homes:\n" . implode("\n", array_map(
                fn (Property $p) => '- ' . $p->title . ' (₱' . number_format((float) $p->price) . ($p->offer_type === 'rent' ? '/month' : '') . ", {$p->address})",
                $context['saved'],
            ));
        }

        $system = "You are RealtyLink AI, a friendly and knowledgeable real estate assistant for buyers in the Philippines. "
            . "Help the buyer find a home based on their budget, location, and preferences. You may give brief, practical buying advice "
            . "(financing, what to inspect, neighborhood considerations), but stay concise. "
            . "IMPORTANT RULES: Only recommend properties from the 'Available listings' below — never invent listings, prices, distances, or details. "
            . "Refer to listings by their title. When comparing, be specific (price per sqm, bedrooms, location) and say which suits what kind of buyer. "
            . "If no listings match, say so honestly and suggest how to broaden the search (e.g. higher budget, nearby area) or offer general advice. "
            . "If the buyer hasn't shared a budget or location yet, still present the listings found, then end with one short question to narrow it down. "
            . "The app shows the listings as cards under your reply, each with Save and Book viewing buttons; if the buyer wants to save, book or contact an agent, point them to those buttons — you cannot do it for them. Never draw or list the cards or buttons yourself. "
            . "Write plain text: no markdown, no asterisks, no headings. Never copy the listing data lines below into your reply; describe listings in your own words. "
            . "Greet only in your first reply of the conversation. Use Philippine pesos (₱). Keep replies under 180 words. Be warm but not overly verbose.\n\n"
            . "Available listings:\n{$listingText}\n\n"
            . implode("\n", $facts);

        $text = $this->call([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => $this->toContents($history),
            'generationConfig'  => ['temperature' => 0.6],
        ]);

        if (is_string($text) && trim($text) !== '') {
            // The model sometimes echoes the prompt's data lines back; the cards already show them.
            // So do imitation cards: a bare title line, or "Save | Book viewing".
            foreach ($properties as $p) {
                $title = preg_quote($p->title, '/');
                $text  = preg_replace(["/^\\s*\\d+\\.\\s+{$title}\\s+—.*$\\R?/mu", "/^\\s*{$title}\\s*$\\R?/mu"], '', $text) ?? $text;
            }
            $text = preg_replace('/^\s*Save\s*\|\s*Book viewing\s*$\R?/mui', '', $text) ?? $text;

            return self::plainText($text);
        }

        if ($mode === 'saved' && ! $savedIds) {
            return "You haven't saved any homes yet. Tap the heart on a listing (or the Save button on the cards I show you) and I can compare them for you.";
        }

        // Model unavailable — still be useful since we have REAL matches in hand.
        if (! empty($properties)) {
            $top   = array_slice($properties, 0, 3);
            $names = array_map(
                static fn ($p) => $p->title . ' (₱' . number_format((float) $p->price) . ($p->offer_type === 'rent' ? '/month' : '') . ')',
                $top,
            );

            return "Here are some listings that match what you're looking for: " . implode('; ', $names)
                . '. Tap any listing to see full details — or tell me your budget, preferred area, and number of '
                . "bedrooms and I'll narrow it down.";
        }

        return "I couldn't find listings matching that just yet. Share your budget and preferred area "
            . '(e.g. "a condo for rent under ₱25,000 in Cebu City") and I\'ll pull up some options.';
    }

    /** The chat shows plain text, so markdown the model slips in would show as literal asterisks. */
    private static function plainText(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*|__(.+?)__/u', '$1$2', $text);
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/mu', '', $text);
        $text = preg_replace('/^\s*[*-]\s+/mu', '• ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", (string) $text);

        return trim((string) $text);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RealtyLink AI — Help & support
    // ─────────────────────────────────────────────────────────────────────────

    /** What support may tell people about how RealtyLink PH works. The only source it answers from. */
    private const SUPPORT_FACTS = <<<'TXT'
RealtyLink PH connects home buyers and renters in the Philippines with verified real estate agents.
Buyers: browse listings (filters, map, 360° tours, nearby places), save homes (heart), compare up to 3, message an agent from a listing, book a viewing (pick a time; the agent confirms; reminders are sent; unanswered requests expire), cancel a viewing (at least 24 hours before is free; five late cancellations in 30 days pause booking for a while), rate an agent after a viewing or a chat, use RealtyLink AI to search, connect Google Calendar in Profile → Connected apps, change theme and password in Profile.
Becoming an agent: Profile → Become an agent. Brokers upload their PRC license card; salespersons upload their accreditation and a valid ID; everyone does a live face scan. An admin reviews it, usually within 24 hours. Rejected applicants see the reason and can apply again after 12 hours.
Agents: create listings (draft → publish), edit or mark sold, answer inquiries and messages, confirm or decline viewings, set unavailable dates on the Calendar, see reviews. RealtyLink AI replies to buyers while an agent is offline.
Admins can unpublish listings (the agent sees the reason and can fix and re-publish), hide reviews, and review agent applications.
Accounts: sign up with email (a 6-digit code verifies it) or Continue with Google. Forgot password is on the sign-in screen. RealtyLink PH does not handle payments or deposits; never pay anyone through the app.
TXT;

    /**
     * First-line support reply. Answers how-to questions from SUPPORT_FACTS
     * only, and says when a person is needed (a bug, an account problem, a
     * dispute, anything it can't fix or isn't sure about).
     *
     * @param  array<int, array{role:string, content:string}>  $history  user/assistant turns
     * @return array{reply: ?string, needs_human: bool, category: ?string}
     */
    public function supportReply(array $history, string $role, bool $standIn = false): array
    {
        // Standing in while the team is away: they've already replied in this
        // thread, so build on what they said and keep to the basics.
        $away = $standIn
            ? 'The RealtyLink PH team has been helping in this conversation (their messages start with [RealtyLink PH team]) but nobody from the team is online right now. '
              . 'If you have not said so already in this conversation, briefly say the team is away right now and you will help in the meantime. Answer only basic questions from the facts below, never contradict or overrule what the team said, '
              . 'and for anything else say the team will pick it up when they are back. '
            : '';

        $system = "You are RealtyLink AI, the support assistant for RealtyLink PH. You are chatting with a {$role}. " . $away
            . "Help with questions about how the app works using ONLY these facts:\n" . self::SUPPORT_FACTS . "\n\n"
            . 'Rules: never invent features, fixes, refunds, approvals or timelines that are not in the facts. You cannot change accounts, listings, viewings or applications. '
            . 'Set needs_human to true when the person reports something broken (a bug or error), an account or login problem you cannot solve with the facts, '
            . 'a complaint about another user, a scam or safety concern, a request to undo an admin decision, or when they ask for a person, or you are unsure. '
            . 'When needs_human is true, say briefly that you are passing it to the RealtyLink PH team and they will reply here. '
            . 'Be warm and short (under 120 words), plain text, no markdown. '
            . 'Respond ONLY with JSON: {"reply": string, "needs_human": boolean, "category": one of ' . implode(', ', SupportTicket::CATEGORIES) . '}.';

        $text = $this->call([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => $this->toContents($history),
            'generationConfig'  => ['responseMimeType' => 'application/json', 'temperature' => 0.3],
        ]);
        $data = json_decode($text ?? '', true);

        // A null reply means no AI could answer; the caller says so to the person.
        if (! is_array($data) || ! is_string($data['reply'] ?? null) || trim($data['reply']) === '') {
            return ['reply' => null, 'needs_human' => false, 'category' => null];
        }

        return [
            'reply'       => self::plainText($data['reply']),
            'needs_human' => (bool) ($data['needs_human'] ?? false),
            'category'    => in_array($data['category'] ?? null, SupportTicket::CATEGORIES, true) ? $data['category'] : null,
        ];
    }

    /**
     * For the admin: a category, a two-line summary of the problem, and a
     * draft reply grounded in the same facts. Null fields when unavailable.
     *
     * @param  array<int, array{role:string, content:string}>  $history
     * @return array{category: ?string, summary: ?string, suggested_reply: ?string}
     */
    public function supportBrief(array $history, string $userName): array
    {
        $system = 'You help an admin of RealtyLink PH handle a support ticket. The app works like this:' . "\n" . self::SUPPORT_FACTS . "\n\n"
            . "Read the conversation between {$userName} (user) and the support assistant (model). "
            . 'Respond ONLY with JSON: {"category": one of ' . implode(', ', SupportTicket::CATEGORIES) . ', '
            . '"summary": what the problem is in at most two plain sentences, '
            . '"suggested_reply": a friendly reply the admin could send, addressed to the user by first name, plain text, under 100 words, '
            . 'promising nothing the admin has not decided (say you are looking into it when unsure)}.';

        $text = $this->call([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => $this->toContents($history),
            'generationConfig'  => ['responseMimeType' => 'application/json', 'temperature' => 0.3],
        ]);
        $data = json_decode($text ?? '', true);

        return [
            'category'        => in_array($data['category'] ?? null, SupportTicket::CATEGORIES, true) ? $data['category'] : null,
            'summary'         => is_string($data['summary'] ?? null) ? self::plainText($data['summary']) : null,
            'suggested_reply' => is_string($data['suggested_reply'] ?? null) ? self::plainText($data['suggested_reply']) : null,
        ];
    }

    /**
     * Explain to an admin WHY a property ranks (or doesn't) in the featured list —
     * grounded strictly in the provided score breakdown so it can't invent reasons.
     */
    public function explainFeatured(array $breakdown, string $title): string
    {
        $lines = [];
        foreach ($breakdown['factors'] as $f) {
            $lines[] = "- {$f['label']}: {$f['score']}/{$f['max']} (" . implode(', ', $f['notes']) . ')';
        }
        $gateText = ($breakdown['gate_failures'] ?? []) === []
            ? 'Passes all eligibility gates.'
            : 'NOT eligible — fails: ' . implode(', ', $breakdown['gate_failures']) . '.';

        $system = 'You explain to a RealtyLinkPH ADMIN why a property does or does not rank in the homepage "Featured" list. '
            . 'Base your explanation ONLY on the scoring factors provided — never invent data. Be concise (2–3 sentences), '
            . 'name the concrete strengths and the weakest factor, and if it is not eligible, state the blocking reason plainly.';

        $user = "Property: {$title}\nTotal featured score: {$breakdown['total']}/100\nEligibility: {$gateText}\nFactors:\n" . implode("\n", $lines);

        $text = $this->call([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => [['parts' => [['text' => $user]]]],
            'generationConfig'  => ['temperature' => 0.4],
        ]);

        return is_string($text) && trim($text) !== '' ? $text : $this->fallbackExplainFeatured($breakdown);
    }

    /** Templated explanation when the model is unavailable — still grounded in the factors. */
    private function fallbackExplainFeatured(array $breakdown): string
    {
        if (($breakdown['gate_failures'] ?? []) !== []) {
            return 'Not eligible to be featured: ' . implode('; ', $breakdown['gate_failures']) . '.';
        }

        $factors = collect($breakdown['factors']);
        $top = $factors->sortByDesc(fn ($f) => $f['score'] / $f['max'])->first();
        $low = $factors->sortBy(fn ($f) => $f['score'] / $f['max'])->first();

        return "Scores {$breakdown['total']}/100. Strongest: {$top['label']} (" . implode(', ', $top['notes'])
            . "). Weakest: {$low['label']} (" . implode(', ', $low['notes']) . ').';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RealtyLink AI — agent application pre-screening (multimodal, advisory)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Produce an advisory assessment of an agent application from its documents.
     *
     * @param  string  $applicantType  'salesperson' | 'broker'
     * @param  array<int, array{label:string,path:string}>  $documents  storage-relative paths
     */
    /**
     * @param  array<int, array{label:string,path:string}>  $documents
     * @param  array{name?:string|null,number?:string|null,supervising_broker?:string|null}  $declared
     *         What the applicant typed into the form, so the model can check
     *         the card against it — a mismatch there is the finding that
     *         matters most and the one a human is slowest to spot.
     */
    public function assessAgentApplication(string $applicantType, array $documents, array $declared = [], int $timeout = 12): string
    {
        $role = $applicantType === 'broker' ? 'BROKER' : 'SALESPERSON';
        $docs = $applicantType === 'broker'
            ? "You are given the applicant's PRC broker license card and a live selfie."
            : "You are given the applicant's accreditation document, a valid government ID, and a live selfie.";

        $declaredLines = array_values(array_filter([
            'Declared full name: ' . ($declared['name'] ?? '(not given)'),
            'Declared ' . ($applicantType === 'broker' ? 'PRC license number' : 'accreditation number') . ': ' . ($declared['number'] ?? '(not given)'),
            $applicantType !== 'broker' && ! empty($declared['supervising_broker'])
                ? 'Declared supervising broker: ' . $declared['supervising_broker']
                : null,
        ]));

        $instruction = "You are RealtyLink AI, assisting a human admin who reviews real estate {$role} applications in the Philippines. {$docs}\n\n"
            . AgentCredentialReference::knowledge() . "\n\n"
            . AgentCredentialReference::method() . "\n\n"
            . "TODAY'S DATE: " . now()->timezone('Asia/Manila')->format('j F Y') . "\n"
            . "APPLICANT'S DECLARED DETAILS (compare the documents against these):\n- " . implode("\n- ", $declaredLines);

        $parts = [['text' => 'Application documents follow, each labeled:']];
        foreach ($documents as $doc) {
            // Read through the disk, not a filesystem path: on S3/R2 there is
            // no local file to stat, so `->path()` would have skipped every
            // document and the AI would have reviewed an empty application.
            if (! Documents::disk()->exists($doc['path'])) {
                continue;
            }
            $bytes = Documents::disk()->get($doc['path']);
            if ($bytes === null || $bytes === '') {
                continue;
            }
            $parts[] = ['text' => "Label: {$doc['label']}"];
            $parts[] = ['inline_data' => [
                'mime_type' => $this->mimeForPath($doc['path']),
                'data'      => base64_encode($bytes),
            ]];
        }

        $text = $this->call([
            'systemInstruction' => ['parts' => [['text' => $instruction]]],
            'contents'          => [['parts' => $parts]],
            'generationConfig'  => ['temperature' => 0.3],
        ], $timeout);

        return $text ?? 'AI assessment unavailable — please review this application manually.';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RealtyLink AI — agent companion (listing assistant, multimodal)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Agent assistant chat. Helps write descriptions (from an optional attached
     * photo), proposes listings to create, and answers questions about the
     * agent's own listings.
     *
     * @param  array<int, array{role:string,content:string}>  $history
     * @param  array{mime:string,data:string}|null  $image  optional attached photo (base64)
     * @return array{reply:string, listing_proposal:?array}
     */
    public function agentChat(User $agent, array $history, ?array $image = null): array
    {
        $listings = $agent->properties()
            ->latest()
            ->limit(20)
            ->get(['title', 'type', 'offer_type', 'price', 'status', 'views', 'bedrooms', 'bathrooms', 'floor_area', 'address']);

        if ($listings->isEmpty()) {
            $listingText = 'The agent has no listings yet.';
            $perfText    = 'No performance data yet.';
        } else {
            $listingText = $listings->values()->map(function (Property $p, int $i): string {
                return ($i + 1) . ". {$p->title} — {$p->type}/{$p->offer_type}, ₱" . number_format((float) $p->price)
                    . ", {$p->status}, {$p->views} views, {$p->bedrooms}BR/{$p->bathrooms}BA"
                    . ($p->floor_area ? ", {$p->floor_area}sqm" : '') . ", {$p->address}";
            })->implode("\n");

            // Performance snapshot grounded in real view counts.
            $published  = $listings->where('status', 'published');
            $totalViews = (int) $listings->sum('views');
            $best       = $listings->sortByDesc('views')->first();
            $zeroViews  = $published->where('views', 0)->pluck('title');

            $perfText = "{$listings->count()} listings ({$published->count()} published), {$totalViews} total views.";
            if ($best && (int) $best->views > 0) {
                $perfText .= " Most-viewed: \"{$best->title}\" ({$best->views} views).";
            }
            if ($zeroViews->isNotEmpty()) {
                $perfText .= ' Published with 0 views (consider sharper price/title/photos): ' . $zeroViews->take(3)->implode('; ') . '.';
            }
        }

        // Platform comparables for grounded pricing advice — avg & range by type/offer.
        $market = Property::query()->published()
            ->selectRaw('type, offer_type, COUNT(*) as n, ROUND(AVG(price)) as avg_price, MIN(price) as min_price, MAX(price) as max_price')
            ->groupBy('type', 'offer_type')
            ->get();
        $marketText = $market->isEmpty()
            ? 'No comparable market data yet.'
            : $market->map(fn ($r): string => ucfirst((string) $r->type) . ' for ' . $r->offer_type
                . ": {$r->n} listings, avg ₱" . number_format((float) $r->avg_price)
                . ', range ₱' . number_format((float) $r->min_price) . '–₱' . number_format((float) $r->max_price))->implode("\n");

        $system = "You are RealtyLink AI, an expert companion for a real estate AGENT on RealtyLinkPH (Philippines). You help them work faster and sell smarter. You can:\n"
            . "1) Write compelling, professional property listing DESCRIPTIONS. If the agent attaches a photo, describe what you see and weave it into the copy.\n"
            . "2) Help CREATE a listing. When the agent has given enough detail (at minimum: property type, price, and a location/address), PROPOSE a complete listing by appending — after your normal reply — a single fenced block exactly like:\n"
            . "```json\n{\"title\":\"...\",\"type\":\"house|condo|lot|commercial|apartment\",\"offer_type\":\"sale|rent\",\"price\":0,\"bedrooms\":0,\"bathrooms\":0,\"floor_area\":null,\"address\":\"...\",\"description\":\"...\"}\n```\n"
            . "Set offer_type to \"rent\" if the agent says it's for rent/lease, otherwise \"sale\". Only include the json block when you are actually proposing a listing to create; otherwise never output a json block. Keep conversational text BEFORE the block.\n"
            . "3) Give grounded PRICING advice: compare the agent's listing to the market comparables below (avg & range for that type/offer) and say whether it is priced above/below market and roughly by how much. Never invent figures — use only the data provided.\n"
            . "4) Give MARKETING & performance insights: use the performance snapshot to say which listing performs best and which underperform (0/few views), and suggest concrete fixes (price, title, photos, 360° tour).\n"
            . "Rules: Property types are limited to house, condo, lot, commercial, apartment. Use Philippine pesos (₱). Be concise and practical (under ~220 words, except when writing a full description).\n\n"
            . "The agent's current listings:\n{$listingText}\n\n"
            . "Performance snapshot:\n{$perfText}\n\n"
            . "Market comparables (published listings across RealtyLinkPH):\n{$marketText}";

        $contents = $this->toContents($history);

        // Attach the photo to the latest user turn (multimodal).
        if ($image !== null && ! empty($contents)) {
            $last = count($contents) - 1;
            $contents[$last]['parts'][] = ['inline_data' => [
                'mime_type' => $image['mime'],
                'data'      => $image['data'],
            ]];
        }

        $text = $this->call([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => $contents,
            'generationConfig'  => ['temperature' => 0.6],
        ]) ?? "Sorry, I'm having trouble responding right now. Please try again in a moment.";

        return $this->extractListingProposal($text);
    }

    /**
     * Pull a fenced ```json listing proposal out of the model's reply (if any),
     * returning the cleaned reply text + the parsed/validated proposal.
     *
     * @return array{reply:string, listing_proposal:?array}
     */
    private function extractListingProposal(string $text): array
    {
        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $text, $m)) {
            $data  = json_decode($m[1], true);
            $clean = trim(str_replace($m[0], '', $text));

            if (is_array($data) && isset($data['type'], $data['price'])) {
                $types = ['house', 'condo', 'lot', 'commercial', 'apartment'];

                return [
                    'reply' => $clean !== '' ? $clean : "Here's a draft listing for your review — tweak anything, then create it.",
                    'listing_proposal' => [
                        'title'       => (string) ($data['title'] ?? ''),
                        'type'        => in_array($data['type'] ?? null, $types, true) ? $data['type'] : 'house',
                        'offer_type'  => in_array($data['offer_type'] ?? null, ['sale', 'rent'], true) ? $data['offer_type'] : 'sale',
                        'price'       => is_numeric($data['price'] ?? null) ? (float) $data['price'] : 0,
                        'bedrooms'    => is_numeric($data['bedrooms'] ?? null) ? (int) $data['bedrooms'] : 0,
                        'bathrooms'   => is_numeric($data['bathrooms'] ?? null) ? (int) $data['bathrooms'] : 0,
                        'floor_area'  => is_numeric($data['floor_area'] ?? null) ? (float) $data['floor_area'] : null,
                        'address'     => (string) ($data['address'] ?? ''),
                        'description' => (string) ($data['description'] ?? ''),
                    ],
                ];
            }
        }

        return ['reply' => $text, 'listing_proposal' => null];
    }

    /**
     * What the agent's latest message asks for, when the keyword reader in
     * AgentAssistantService couldn't tell. Null when Gemini is unavailable.
     *
     * @param  array<int, string>  $intents
     * @param  array<int, string>  $pages
     * @return array<string, mixed>|null
     */
    public function agentIntent(array $history, array $intents, array $pages, string $today): ?array
    {
        $system = 'You route messages for a real estate AGENT\'s assistant on RealtyLinkPH. Today is ' . $today . ' (Asia/Manila). '
            . 'Read ONLY the agent\'s LATEST message (earlier turns are context) and respond ONLY with a JSON object using these keys: '
            . 'intent (one of: ' . implode(', ', $intents) . '). '
            . 'chat = anything else: writing a description, creating a new listing, general questions or advice. '
            . 'listings = show their listings; viewings = show their viewing appointments; inquiries = show inquiries from buyers; reviews = show reviews buyers left them; '
            . 'price_check = is a listing priced right / suggest a price; listing_check = what to improve on their listings; '
            . 'draft_reply = write a reply to an inquiry, review or chat message for them; '
            . 'confirm_viewing, block_day (mark a whole day unavailable), block_hours (block a time range), publish, unpublish, mark_sold = do that task; '
            . 'theme_dark, theme_light, theme_system ("turn off light mode" is theme_dark, "turn off dark mode" is theme_light), gcal_connect, gcal_disconnect, password, open_page, capabilities (what can you do). '
            . 'listing (string or null: words naming one of their listings), '
            . 'person (string or null: a buyer\'s name), '
            . 'target (only for draft_reply: inquiry, review, chat, or null), '
            . 'status (only for listings: draft, published, sold, or null; for viewings: pending, upcoming, or null), '
            . 'date (YYYY-MM-DD or null: resolve "tomorrow", "Friday", "Oct 30" against today), '
            . 'start_time and end_time ("HH:MM" 24-hour or null), '
            . 'page (only with open_page: one of ' . implode(', ', $pages) . ').';

        $text = $this->call([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => $this->toContents($history),
            'generationConfig'  => ['responseMimeType' => 'application/json', 'temperature' => 0],
        ]);

        $data = json_decode($text ?? '', true);

        return is_array($data) && in_array($data['intent'] ?? null, $intents, true) ? $data : null;
    }

    /**
     * A reply the agent can send to a buyer: an inquiry, a review, or a chat
     * message. A draft only; the agent reviews it and sends it themselves.
     */
    public function draftAgentReply(string $kind, string $context, string $agentName, string $ask = ''): ?string
    {
        $how = match ($kind) {
            'review'  => 'a short public reply to this review (2–4 sentences): thank the buyer, address any concern calmly and specifically, no excuses, no discounts or promises',
            'inquiry' => 'a reply to this inquiry (3–6 sentences): answer what they asked from the listing facts, invite them to book a viewing on RealtyLink PH, sign off with the agent\'s first name',
            default   => 'a chat reply to the buyer\'s latest message (1–4 sentences, friendly and direct, no sign-off)',
        };

        $system = "You write on behalf of {$agentName}, a verified real estate agent on RealtyLinkPH (Philippines). Write {$how}. "
            . 'Use only the facts given; never invent prices, dates or features. Plain text, no markdown, no placeholders like [Name]. '
            . 'Reply in the same language the buyer used (English, Filipino or Taglish).'
            . ($ask !== '' ? " The agent's instructions: {$ask}" : '');

        $text = $this->call([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $context]]]],
            'generationConfig'  => ['temperature' => 0.5],
        ]);

        return $text !== null ? self::plainText($text) : null;
    }

    private function mimeForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'pdf'  => 'application/pdf',
            default => 'image/jpeg',
        };
    }

    private function buildDescriptionPrompt(array $attributes, bool $hasPhotos = false): string
    {
        $facts = array_filter([
            ! empty($attributes['type'])              ? 'Type: ' . $attributes['type'] : null,
            ! empty($attributes['offer_type'])        ? 'Offer: ' . ($attributes['offer_type'] === 'rent' ? 'For Rent' : 'For Sale') : null,
            ! empty($attributes['price'])             ? 'Price: ₱' . number_format((float) $attributes['price']) : null,
            ! empty($attributes['bedrooms'])          ? 'Bedrooms: ' . $attributes['bedrooms'] : null,
            ! empty($attributes['bathrooms'])         ? 'Bathrooms: ' . $attributes['bathrooms'] : null,
            ! empty($attributes['floor_area'])        ? 'Floor area: ' . $attributes['floor_area'] . ' sqm' : null,
            ! empty($attributes['lot_area'])          ? 'Lot area: ' . $attributes['lot_area'] . ' sqm' : null,
            ! empty($attributes['address'])           ? 'Location: ' . $attributes['address'] : null,
        ]);

        $lines = ['Write a compelling, professional real estate listing description in English for a property in the Philippines.'];

        if ($hasPhotos) {
            $lines[] = 'Carefully study the attached property photo(s): describe the visible rooms, style, finishes, lighting, and ambiance, and weave that into the copy so it feels specific and real.';
        }

        if (! empty($facts)) {
            $lines[] = '';
            $lines[] = 'Known details:';
            $lines[] = implode("\n", $facts);
        }

        $lines[] = '';
        $lines[] = 'Write a CONCISE, direct description in 2-3 short sentences — straight to the key selling points. No fluff, clichés, or repetition. Do not invent specifics that contradict the details or photos. Return only the description text (no headings or markdown).';

        return implode("\n", $lines);
    }
}
