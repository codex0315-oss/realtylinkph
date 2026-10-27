<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Property;
use App\Models\User;

/**
 * RealtyLink AI for buyers: one chat turn.
 *
 *   1. Gemini reads the conversation into search criteria.
 *   2. Real published listings are found for them: the buyer's saved homes,
 *      "more" of the last search (skipping what the chat already showed), or a
 *      fresh search, ranked by distance when they asked to be near something.
 *   3. Gemini writes a reply grounded in exactly those listings.
 *
 * Or, when the latest message asks the app to do something (switch theme,
 * Google Calendar, password, open a page), step 2–3 become act().
 */
class BuyerAssistantService
{
    /** Cards per reply. */
    private const PER_REPLY = 4;

    /** Listings ranked by distance when the buyer asks to be near something. */
    private const NEAR_POOL = 12;

    public function __construct(
        private readonly GeminiService $gemini,
        private readonly PropertyService $properties,
        private readonly NearbyService $nearby,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @param  array<int, int>  $shownIds  listings already shown in this chat
     * @return array{reply: string, criteria: array<string, mixed>, mode: string, properties: array<int, Property>, nearby: array<int, array<string, mixed>>, total: int, remaining: int, action?: array<string, mixed>}
     */
    public function reply(User $buyer, array $history, array $shownIds = []): array
    {
        // Nothing that looks like a password goes to Gemini.
        $history = array_map(fn (array $m) => ['role' => $m['role'], 'content' => self::redact((string) $m['content'])], $history);

        $criteria = $this->gemini->extractSearchCriteria($history);

        if ($criteria['action'] !== null) {
            return ['criteria' => $criteria, 'mode' => 'action', 'properties' => [], 'nearby' => [], 'total' => 0, 'remaining' => 0]
                + $this->act($buyer, $criteria['action'], $criteria['page']);
        }

        $savedIds = array_values(array_map('intval', $buyer->favorites ?? []));

        $filters = array_filter([
            'search'        => $criteria['search'],
            'type'          => $criteria['type'],
            'offer_type'    => $criteria['offer_type'],
            'min_price'     => $criteria['min_price'],
            'max_price'     => $criteria['max_price'],
            'bedrooms'      => $criteria['bedrooms'],
            'min_bathrooms' => $criteria['bathrooms'],
            'has_360'       => $criteria['has_360'] ?: null,
            'sort'          => $criteria['sort'],
        ], static fn ($v) => $v !== null);

        $context = ['near' => $criteria['near'], 'saved_ids' => $savedIds];
        $nearby  = [];

        if ($criteria['saved']) {
            // "Compare my saved condos": the saved list, narrowed by whatever
            // they asked for, or all of it when that leaves nothing.
            $context['mode'] = 'saved';
            $items = [];
            if ($savedIds) {
                $scoped = ['only_ids' => $savedIds, 'sort' => $filters['sort'] ?? null];
                $items  = $this->properties->list($scoped + array_diff_key($filters, ['search' => 1]), 6)->items()
                    ?: $this->properties->list($scoped, 6)->items();
            }
            $total = count($items);
            $remaining = 0;
        } else {
            $context['mode'] = 'search';
            if ($criteria['more'] && $shownIds) {
                $filters['exclude_ids'] = $shownIds;
                $context['more'] = true;
            }

            $found = $this->search($filters, $criteria['near'] ? self::NEAR_POOL : self::PER_REPLY);
            if (! $found['items'] && ! empty($filters['search'])) {
                $wider = $this->widerArea($filters['search']);
                if ($wider !== null) {
                    $found = $this->search(['search' => $wider] + $filters, $criteria['near'] ? self::NEAR_POOL : self::PER_REPLY);
                    if ($found['items']) {
                        $context['broadened'] = ['from' => $filters['search'], 'to' => $wider];
                    }
                }
            }
            $items = $found['items'];
            $total = $found['total'];

            if ($criteria['near'] && $items) {
                $nearby = $this->nearby->nearestTo($items, $criteria['near']);
                $context['nearby_available'] = $nearby !== [];
                if ($nearby) {
                    $items = $this->rankByDistance($items, $nearby, $criteria['near']);
                }
                $items = array_slice($items, 0, self::PER_REPLY);
            }
            $remaining = max(0, $total - count($items));

            // Lets "is this better than the one I saved?" work mid-search.
            $context['saved'] = $savedIds
                ? $this->properties->list(['only_ids' => $savedIds], 6)->items()
                : [];
        }

        $context += ['total' => $total, 'remaining' => $remaining, 'nearby' => $nearby];
        $reply = $this->gemini->generateAssistantReply($history, $items, $context);

        // Distance chips for the cards: only the places actually found.
        $shown = [];
        foreach ($items as $p) {
            $facts = array_values(array_filter(array_map(
                fn ($kind) => isset($nearby[$p->id][$kind]) ? ['kind' => $kind] + $nearby[$p->id][$kind] : null,
                $criteria['near'],
            )));
            if ($facts) {
                $shown[$p->id] = $facts;
            }
        }

        return [
            'reply'      => $reply,
            'criteria'   => $criteria,
            'mode'       => $context['mode'],
            'properties' => array_values($items),
            'nearby'     => $shown,
            'total'      => $total,
            'remaining'  => $remaining,
        ];
    }

    /**
     * Things the AI does in the app rather than search. The reply is written
     * here, not by Gemini, so it's instant and always true to the account; the
     * `action` tells the chat which card to show. Nothing changes the account
     * from here: the theme is switched by the browser (with Undo), and the
     * calendar and password need the buyer to tap and confirm.
     *
     * @return array{reply: string, action: array<string, mixed>}
     */
    private function act(User $buyer, string $action, ?string $page): array
    {
        $calendar = $buyer->google_access_token !== null;

        return match ($action) {
            'theme_dark'   => ['reply' => 'Done, dark mode is on. Tap Undo if you\'d rather go back.', 'action' => ['type' => 'theme', 'mode' => 'dark']],
            'theme_light'  => ['reply' => 'Done, light mode is on. Tap Undo if you\'d rather go back.', 'action' => ['type' => 'theme', 'mode' => 'light']],
            'theme_system' => ['reply' => 'Done, the theme now follows your device\'s light or dark setting. Tap Undo to go back.', 'action' => ['type' => 'theme', 'mode' => 'system']],

            'gcal_connect' => [
                'reply' => $calendar
                    ? 'Your Google Calendar is already connected. Confirmed viewings are added to it automatically.'
                    : 'Connecting Google Calendar adds every confirmed viewing to your calendar automatically. Tap the button below: Google will ask you to sign in and allow access, then bring you back to your profile.',
                'action' => ['type' => 'gcal_connect'],
            ],
            'gcal_disconnect' => [
                'reply' => $calendar
                    ? 'Disconnecting stops new viewings from syncing to your Google Calendar; viewings already there stay. Tap the button below and confirm.'
                    : 'Your Google Calendar isn\'t connected, so there\'s nothing to disconnect.',
                'action' => ['type' => 'gcal_disconnect'],
            ],

            'password' => $buyer->password_set !== false
                ? [
                    'reply'  => 'You can change it right here. For your safety, type passwords only in the secure form below, never in the chat. The form goes straight to RealtyLink PH, not to me.',
                    'action' => ['type' => 'password', 'can_change' => true],
                ]
                : [
                    'reply'  => 'Your account signs in with Google, so there\'s no RealtyLink PH password to change; just keep using "Continue with Google". If you\'d also like to sign in with your email and a password, choose "Forgot password" on the sign-in screen and we\'ll email you a link to set one.',
                    'action' => ['type' => 'password', 'can_change' => false],
                ],

            'open_page' => [
                'reply'  => 'Here you go. Tap below to open ' . GeminiService::PAGES[$page][0] . '.',
                'action' => ['type' => 'open_page', 'page' => $page, 'label' => GeminiService::PAGES[$page][0], 'href' => GeminiService::PAGES[$page][1]],
            ],

            default => [   // capabilities
                'reply' => "Here's what I can do for you:\n"
                    . "• Find homes from a budget, area, type, bedrooms or bathrooms, for sale or rent\n"
                    . "• Rank homes by how close they are to schools, hospitals, malls, churches, transport and more\n"
                    . "• Compare the homes you've saved, and show more matches when there are any\n"
                    . "• Save a listing or start booking a viewing straight from the chat\n"
                    . "• Switch dark or light mode, connect or disconnect Google Calendar, and help you change your password\n"
                    . "• Take you to your appointments, saved homes, history, reviews, messages or profile\n"
                    . "• Answer questions about buying or renting in the Philippines\n"
                    . 'What would you like to start with?',
                'action' => ['type' => 'capabilities'],
            ],
        };
    }

    /**
     * "my password is hunter2" / "pw: Abc12345" / "password Abc12345" →
     * "… [hidden]". A word after "is/to/:" is hidden whatever it is; a bare
     * word after "password" only when it has a digit or symbol, so "change my
     * password please" survives.
     */
    public static function redact(string $text): string
    {
        $word = '\b(password|passcode|passwd|pwd|pw)\b';

        return preg_replace([
            "/{$word}(\\s*(?:\\bis\\b|\\bto\\b|\\binto\\b|\\bas\\b|:|=)\\s*)(?!\\[hidden\\])(\"[^\"]+\"|'[^']+'|\\S{4,})/iu",
            "/{$word}(\\s+)(?!\\[hidden\\])(?=\\S*[\\d!@#$%^&*_])(\\S{6,})/iu",
        ], '$1$2[hidden]', $text) ?? $text;
    }

    /** @return array{items: array<int, Property>, total: int} */
    private function search(array $filters, int $limit): array
    {
        $page = $this->properties->list($filters, $limit);

        return ['items' => $page->items(), 'total' => $page->total()];
    }

    /**
     * "Mandaue, Cebu" or "Cebu City" can miss an address written as "Mandaue
     * City, Cebu". Retry with the most specific single place word.
     */
    private function widerArea(string $search): ?string
    {
        $words = array_filter(
            preg_split('/[\s,]+/u', $search) ?: [],
            static fn ($w) => mb_strlen($w) >= 4 && ! in_array(mb_strtolower($w), ['city', 'province', 'town', 'area', 'near', 'metro'], true),
        );
        $first = reset($words);

        return $first !== false && mb_strtolower($first) !== mb_strtolower($search) ? $first : null;
    }

    /**
     * Closest first, summing the distance to each place they asked for. A place
     * missing or out of range counts as twice its search radius, so a home near
     * a school but not a hospital still beats one near neither.
     *
     * @param  array<int, Property>  $items
     * @return array<int, Property>
     */
    private function rankByDistance(array $items, array $nearby, array $kinds): array
    {
        $score = function (Property $p) use ($nearby, $kinds): int {
            $sum = 0;
            foreach ($kinds as $kind) {
                $sum += $nearby[$p->id][$kind]['distance_m'] ?? 2 * NearbyService::KINDS[$kind][3];
            }

            return $sum;
        };

        $order = array_flip(array_map(fn (Property $p) => $p->id, $items));
        usort($items, fn ($a, $b) => [$score($a), $order[$a->id]] <=> [$score($b), $order[$b->id]]);

        return $items;
    }
}
