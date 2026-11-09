<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Turns what RealtyLink AI READ from an application's documents into a fixed
 * checklist and a verdict.
 *
 * The model only transcribes (name, number, validity date, a few yes/no
 * observations). Every judgement made here is plain code, so the same
 * documents give the same advice every time. The checklist is advisory:
 * the admin still decides and confirms the number on PRC's own site.
 */
final class AgentApplicationVerdict
{
    public const CONSISTENT = 'consistent';

    public const NEEDS_ATTENTION = 'needs_attention';

    public const CANNOT_VERIFY = 'cannot_verify';

    private const TITLES = ['mr', 'mrs', 'ms', 'miss', 'dr', 'atty', 'engr', 'arch', 'rem', 'jr', 'sr', 'ii', 'iii', 'iv'];

    /**
     * @param  array<string, mixed>  $read  the model's transcription
     * @param  array{name?: ?string, number?: ?string, supervising_broker?: ?string, type?: ?string}  $declared
     * @return array{verdict: string, checks: list<array{key: string, label: string, state: string, detail: string}>, summary: ?string}
     */
    public static function evaluate(array $read, array $declared, CarbonInterface $today): array
    {
        $checks = [
            self::name($read, $declared),
            self::number($read, $declared),
            self::validity($read, $today),
            self::document($read, $declared),
            self::photo($read),
        ];
        if (($declared['type'] ?? null) !== 'broker' && ! empty($declared['supervising_broker'])) {
            $checks[] = self::supervisor($read, $declared);
        }

        $states  = array_column($checks, 'state');
        $verdict = in_array('bad', $states, true) ? self::NEEDS_ATTENTION
            : (in_array('unknown', $states, true) ? self::CANNOT_VERIFY : self::CONSISTENT);

        $summary = isset($read['summary']) && is_string($read['summary']) ? trim($read['summary']) : null;

        return ['verdict' => $verdict, 'checks' => $checks, 'summary' => $summary !== '' ? $summary : null];
    }

    /** The one-line headline for each verdict. */
    public static function headline(string $verdict): string
    {
        return match ($verdict) {
            self::CONSISTENT => 'Documents look consistent with the application',
            self::NEEDS_ATTENTION => 'Something needs your attention',
            default => 'Some things could not be checked from the photos',
        };
    }

    /** @return array{key: string, label: string, state: string, detail: string} */
    private static function check(string $key, string $label, string $state, string $detail): array
    {
        return compact('key', 'label', 'state', 'detail');
    }

    private static function name(array $read, array $declared): array
    {
        $card = self::text($read['name_on_document'] ?? null);
        if ($card === null) {
            return self::check('name', 'Name', 'unknown', 'The name on the document could not be read.');
        }

        return self::sameName($card, (string) ($declared['name'] ?? ''))
            ? self::check('name', 'Name', 'ok', "“{$card}” matches the application.")
            : self::check('name', 'Name', 'bad', "Document says “{$card}”, the application says “" . ($declared['name'] ?? '') . '”.');
    }

    private static function number(array $read, array $declared): array
    {
        $card   = self::text($read['number_on_document'] ?? null);
        $digits = $card === null ? '' : ltrim((string) preg_replace('/\D+/', '', $card), '0');
        if ($digits === '') {
            return self::check('number', 'License number', 'unknown', 'The number on the document could not be read.');
        }

        $typed = ltrim((string) preg_replace('/\D+/', '', (string) ($declared['number'] ?? '')), '0');

        return $digits === $typed
            ? self::check('number', 'License number', 'ok', "{$card} matches the application.")
            : self::check('number', 'License number', 'bad', "Document says {$card}, the application says " . ($declared['number'] ?? '') . '.');
    }

    private static function validity(array $read, CarbonInterface $today): array
    {
        $raw  = self::text($read['valid_until'] ?? null);
        $date = null;
        if ($raw !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            try {
                $date = Carbon::createFromFormat('!Y-m-d', $raw, $today->getTimezone());
            } catch (\Throwable) {
                $date = null;
            }
        }
        if ($date === null) {
            return self::check('validity', 'Validity', 'unknown', 'No validity date could be read from the document.');
        }

        $pretty = $date->format('j F Y');

        return $date->lt($today->copy()->startOfDay())
            ? self::check('validity', 'Validity', 'bad', "Expired on {$pretty}.")
            : self::check('validity', 'Validity', 'ok', "Valid until {$pretty}.");
    }

    private static function document(array $read, array $declared): array
    {
        $looks      = $read['looks_like_prc_document'] ?? null;
        $band       = $read['profession'] ?? null;
        $wantBroker = ($declared['type'] ?? null) === 'broker';

        if (! empty($read['signs_of_editing'])) {
            return self::check('document', 'Document', 'bad', 'The document shows signs of editing.');
        }
        if ($looks === 'no') {
            return self::check('document', 'Document', 'bad', 'This does not look like a PRC real estate credential.');
        }
        if (($band === 'broker' && ! $wantBroker) || ($band === 'salesperson' && $wantBroker)) {
            return self::check('document', 'Document', 'bad', 'The document names a different profession from the one applied for.');
        }
        if ($looks === 'yes' && empty($read['document_unreadable'])) {
            return self::check('document', 'Document', 'ok', 'Looks like a real PRC credential. Only PRC’s site can confirm that.');
        }

        return self::check('document', 'Document', 'unknown', 'The photo is too unclear to judge the document.');
    }

    private static function photo(array $read): array
    {
        $match = $read['face_match'] ?? null;
        if ($match === 'dissimilar') {
            return self::check('photo', 'Selfie', 'bad', 'The selfie does not look like the person in the document photo.');
        }
        if ($match === 'similar' && ($read['selfie_clear'] ?? true) !== false) {
            return self::check('photo', 'Selfie', 'ok', 'The selfie looks like the person in the document photo.');
        }

        return self::check('photo', 'Selfie', 'unknown', 'The selfie and document photo could not be compared clearly.');
    }

    private static function supervisor(array $read, array $declared): array
    {
        $doc = self::text($read['supervising_broker'] ?? null);
        if ($doc === null) {
            return self::check('supervisor', 'Supervising broker', 'unknown', 'No supervising broker is shown on the documents.');
        }

        return self::sameName($doc, (string) $declared['supervising_broker'])
            ? self::check('supervisor', 'Supervising broker', 'ok', "“{$doc}” matches the application.")
            : self::check('supervisor', 'Supervising broker', 'bad', "Documents say “{$doc}”, the application says “{$declared['supervising_broker']}”.");
    }

    private static function text(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' || in_array(Str::lower($v), ['null', 'n/a', 'unknown', 'not legible'], true) ? null : $v;
    }

    /** Same person, allowing order, case, accents, middle names and titles to differ. */
    public static function sameName(string $a, string $b): bool
    {
        $x = self::tokens($a);
        $y = self::tokens($b);
        if (! $x || ! $y) {
            return false;
        }
        $shared = count(array_intersect($x, $y));
        $least  = min(count($x), count($y));

        return $shared >= $least && $shared >= min(2, $least);
    }

    /** @return list<string> */
    private static function tokens(string $name): array
    {
        $words = preg_split('/[^a-z]+/', Str::lower(Str::ascii($name)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $w) => ! in_array($w, self::TITLES, true) && strlen($w) > 1)));
    }
}
