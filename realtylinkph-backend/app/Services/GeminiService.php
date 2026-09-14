<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Property;
use App\Models\User;
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

        // Primary, then the most-available fallbacks first.
        return array_values(array_unique([
            $primary,
            'gemini-2.0-flash',
            'gemini-2.5-flash',
        ]));
    }

    /**
     * Low-level Gemini call. Tries the primary model then ONE fallback, each with a
     * short timeout — deliberately bounded so a synchronous request can never reach
     * PHP's max_execution_time (which previously caused a fatal error + CORS failure).
     * Returns the model's text, or null on failure.
     */
    private function call(array $payload): ?string
    {
        // At most 2 models × 12s ≈ 24s worst case — safely under the 60s web limit.
        $models = array_slice($this->models(), 0, 2);

        foreach ($models as $i => $model) {
            try {
                $response = Http::withQueryParameters(['key' => config('services.gemini.api_key')])
                    ->timeout(12)
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

        return null;
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

    /**
     * Extract structured search criteria from the conversation.
     *
     * @return array{search:?string,type:?string,min_price:?float,max_price:?float,bedrooms:?int,bathrooms:?int}
     */
    public function extractSearchCriteria(array $history): array
    {
        $system = 'Extract the buyer\'s property search criteria from the conversation. '
            . 'Respond ONLY with a JSON object using these keys: '
            . 'location (string or null), type (one of: house, condo, lot, commercial, apartment, or null), '
            . 'offer_type (one of: sale, rent, or null — choose "rent" if they mention renting, leasing, or a MONTHLY budget; "sale" if buying/purchasing), '
            . 'min_price (number or null), max_price (number or null), bedrooms (integer or null), bathrooms (integer or null). '
            . 'Use null when a value is not specified. Prices are in Philippine pesos — interpret shorthand like "5M" as 5000000, "500k" as 500000, "25 thousand" as 25000. '
            . 'A stated budget is the MAXIMUM (max_price). Only fill a field if the buyer clearly stated or implied it.';

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
        $offerType = in_array($data['offer_type'] ?? null, ['sale', 'rent'], true) ? $data['offer_type'] : $heur['offer_type'];
        $maxPrice  = is_numeric($data['max_price'] ?? null) ? (float) $data['max_price'] : $heur['max_price'];
        $minPrice  = is_numeric($data['min_price'] ?? null) ? (float) $data['min_price'] : $heur['min_price'];
        $bedrooms  = is_numeric($data['bedrooms'] ?? null) ? (int) $data['bedrooms'] : $heur['bedrooms'];

        return [
            'search'     => is_string($data['location'] ?? null) && $data['location'] !== '' ? $data['location'] : null,
            'type'       => $type,
            'offer_type' => $offerType,
            'min_price'  => $minPrice,
            'max_price'  => $maxPrice,
            'bedrooms'   => $bedrooms,
            'bathrooms'  => is_numeric($data['bathrooms'] ?? null) ? (int) $data['bathrooms'] : null,
        ];
    }

    /**
     * Regex-based criteria extraction from the buyer's own messages — a backstop
     * for when Gemini is unavailable, and to catch fields the model misses.
     *
     * @return array{offer_type:?string,type:?string,min_price:?float,max_price:?float,bedrooms:?int}
     */
    private function heuristicCriteria(array $history): array
    {
        $text = '';
        foreach ($history as $m) {
            if (($m['role'] ?? 'user') !== 'assistant') {
                $text .= ' ' . mb_strtolower((string) ($m['content'] ?? $m['text'] ?? ''));
            }
        }

        $out = ['offer_type' => null, 'type' => null, 'min_price' => null, 'max_price' => null, 'bedrooms' => null];

        if (preg_match('/\b(rent|rental|lease|leasing|monthly|per month|a month)\b|\/month/', $text)) {
            $out['offer_type'] = 'rent';
        } elseif (preg_match('/\b(buy|buying|purchase|purchasing|for sale|own)\b/', $text)) {
            $out['offer_type'] = 'sale';
        }

        foreach (['condo', 'apartment', 'house', 'lot', 'commercial'] as $kw) {
            if (str_contains($text, $kw)) {
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

    /**
     * Generate a friendly, grounded reply that recommends ONLY the given listings.
     *
     * @param  array<int, Property>  $properties
     */
    public function generateAssistantReply(array $history, array $properties): string
    {
        if (empty($properties)) {
            $listingText = 'No matching listings were found in the database for the current criteria.';
        } else {
            $lines = [];
            foreach ($properties as $i => $p) {
                $lines[] = ($i + 1) . ". {$p->title} — {$p->type}, ₱" . number_format((float) $p->price)
                    . ", {$p->bedrooms}BR/{$p->bathrooms}BA, {$p->address}";
            }
            $listingText = implode("\n", $lines);
        }

        $system = "You are RealtyLink AI, a friendly and knowledgeable real estate assistant for buyers in the Philippines. "
            . "Help the buyer find a home based on their budget, location, and preferences. You may give brief, practical buying advice "
            . "(financing, what to inspect, neighborhood considerations), but stay concise. "
            . "IMPORTANT RULES: Only recommend properties from the 'Available listings' below — never invent listings, prices, or details. "
            . "If no listings match, say so honestly and suggest how to broaden the search (e.g. higher budget, nearby area) or offer general advice. "
            . "Ask a short clarifying question if the buyer hasn't shared a budget or location yet. "
            . "Use Philippine pesos (₱). Keep replies under 180 words. Be warm but not overly verbose.\n\n"
            . "Available listings:\n{$listingText}";

        $text = $this->call([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents'          => $this->toContents($history),
            'generationConfig'  => ['temperature' => 0.6],
        ]);

        if (is_string($text) && trim($text) !== '') {
            return $text;
        }

        // Model unavailable — still be useful since we have REAL matches in hand.
        if (! empty($properties)) {
            $top   = array_slice($properties, 0, 3);
            $names = array_map(
                static fn ($p) => $p->title . ' (₱' . number_format((float) $p->price) . ')',
                $top,
            );

            return "Here are some listings that match what you're looking for: " . implode('; ', $names)
                . '. Tap any listing to see full details — or tell me your budget, preferred area, and number of '
                . "bedrooms and I'll narrow it down.";
        }

        return "I couldn't find listings matching that just yet. Share your budget and preferred area "
            . '(e.g. "a condo for rent under ₱25,000 in Cebu City") and I\'ll pull up some options.';
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
    public function assessAgentApplication(string $applicantType, array $documents): string
    {
        if ($applicantType === 'broker') {
            $instruction = 'You are RealtyLink AI assisting a human admin who reviews real estate AGENT (Broker) applications in the Philippines. '
                . 'A broker can transact independently. You are given a broker license card image and a live selfie. Briefly: '
                . '(1) read any legible details from the license card (name, license/PRC number, validity); '
                . '(2) QUALITATIVELY compare the face on the license card with the live selfie and state whether they appear to be the same person; '
                . '(3) flag red flags (blurry, expired, edited, mismatched name/photo). '
                . 'You are an ADVISORY tool only — the admin makes the final decision. Do NOT say "approved" or "rejected". Keep under 140 words.';
        } else {
            $instruction = 'You are RealtyLink AI assisting a human admin who reviews real estate AGENT (Salesperson) applications in the Philippines. '
                . 'A salesperson needs accreditation. You are given an accreditation document, a valid ID, and a live selfie. Briefly: '
                . '(1) read legible details from the accreditation document and ID (name, number, validity/expiry); '
                . '(2) note whether the name on the ID appears consistent with the accreditation document; '
                . '(3) QUALITATIVELY state whether the live selfie appears to be a clear photo of a real person consistent with the ID photo; '
                . '(4) flag red flags. '
                . 'You are an ADVISORY tool only — the admin makes the final decision. Do NOT say "approved" or "rejected". Keep under 150 words.';
        }

        $parts = [['text' => 'Application documents follow, each labeled:']];
        foreach ($documents as $doc) {
            $abs = Storage::disk('public')->path($doc['path']);
            if (! is_file($abs)) {
                continue;
            }
            $parts[] = ['text' => "Label: {$doc['label']}"];
            $parts[] = ['inline_data' => [
                'mime_type' => $this->mimeForPath($abs),
                'data'      => base64_encode((string) file_get_contents($abs)),
            ]];
        }

        $text = $this->call([
            'systemInstruction' => ['parts' => [['text' => $instruction]]],
            'contents'          => [['parts' => $parts]],
            'generationConfig'  => ['temperature' => 0.3],
        ]);

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
