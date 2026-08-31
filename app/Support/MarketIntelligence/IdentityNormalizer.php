<?php

namespace App\Support\MarketIntelligence;

use Illuminate\Support\Str;

/**
 * V2.4 (spec §11): deterministic, conservative normalization of the
 * identity fields V2.4 compares — a prospect's website / domain / name
 * against an authorised CRM organisation's.
 *
 * "Conservative" is the whole point: it must never fold two genuinely
 * different businesses into one. It lowercases, trims, collapses
 * whitespace, strips a leading `www.`, drops only well-known trailing
 * legal-form suffixes, and tokenises — nothing cleverer. No LLM, no
 * embeddings, no fuzzy country/region stripping.
 *
 * `host()` matches the existing MI `registrableDomain()` behaviour
 * (parse host, lowercase, strip `www.`) so a prospect domain that came
 * from V2.1–V2.3 compares identically here.
 */
final class IdentityNormalizer
{
    /** Trailing tokens removed from a company name before comparison. */
    private const LEGAL_SUFFIXES = [
        'inc', 'incorporated', 'corp', 'corporation', 'co', 'company',
        'ltd', 'limited', 'llc', 'lp', 'llp', 'plc', 'gmbh', 'pty',
        'ent', 'enterprise', 'enterprises', 'holdings', 'group',
    ];

    /**
     * Generic / non-distinctive tokens. A name whose distinctive tokens
     * (everything here removed) number fewer than the policy minimum is
     * treated as "generic" — see spec §13.
     *
     * @var list<string>
     */
    private const GENERIC_TOKENS = [
        'the', 'and', 'of', 'a', 'an', 'for', 'by',
        'shop', 'store', 'online', 'general', 'merchandise', 'trading',
        'trade', 'traders', 'business', 'services', 'service', 'solutions',
        'ph', 'philippines', 'pilipinas', 'international', 'global',
        'official', 'shopping', 'mart', 'market', 'sales', 'retail',
        'wholesale', 'supply', 'supplies', 'distribution', 'distributor',
        'cebu', 'manila', 'davao', 'quezon', 'city', 'metro', 'luzon',
        'visayas', 'mindanao',
    ];

    /**
     * Normalised host: `https://www.ABCBeauty.ph/products?x=1` → `abcbeauty.ph`.
     * `null` when nothing host-like can be extracted.
     */
    public static function host(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(mb_strtolower($value));
        if ($value === '') {
            return null;
        }

        $host = (string) parse_url(Str::startsWith($value, ['http://', 'https://']) ? $value : 'https://'.$value, PHP_URL_HOST);
        if ($host === '') {
            // e.g. a bare token with a space, or "not a url"
            $host = (string) preg_replace('#^.*?([a-z0-9.-]+\.[a-z]{2,}).*$#', '$1', $value);
        }

        $host = trim($host, '.');
        $host = Str::startsWith($host, 'www.') ? substr($host, 4) : $host;

        return ($host !== '' && str_contains($host, '.')) ? $host : null;
    }

    /**
     * Normalised full website for an "exact website" comparison:
     * scheme dropped, host normalised, path kept but a lone trailing
     * slash removed, query/fragment dropped.
     */
    public static function website(?string $value): ?string
    {
        $host = self::host($value);
        if ($host === null) {
            return null;
        }

        $value = trim(mb_strtolower((string) $value));
        $parts = parse_url(Str::startsWith($value, ['http://', 'https://']) ? $value : 'https://'.$value);
        $path = rtrim($parts['path'] ?? '', '/');

        return $host.$path;
    }

    /**
     * A normalised company-name key for an exact comparison:
     * `"ABC Beauty Corporation"` → `"abc beauty"`.
     */
    public static function nameKey(?string $value): string
    {
        return implode(' ', self::nameTokens($value));
    }

    /**
     * Distinctive-plus-common tokens of a company name, legal suffixes
     * removed, punctuation stripped. `"ABC Beauty Corp., Inc."` →
     * `["abc", "beauty"]`.
     *
     * @return list<string>
     */
    public static function nameTokens(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $value = mb_strtolower($value);
        $value = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        if ($value === '') {
            return [];
        }

        $tokens = explode(' ', $value);

        // Strip trailing legal-form tokens (only trailing — never mid-name).
        while ($tokens !== [] && in_array(end($tokens), self::LEGAL_SUFFIXES, true)) {
            array_pop($tokens);
        }

        return array_values(array_filter($tokens, fn (string $t) => $t !== ''));
    }

    /**
     * The distinctive tokens only — generic words removed. Used to
     * decide whether a name is too generic to trust (spec §13).
     *
     * @return list<string>
     */
    public static function distinctiveTokens(?string $value): array
    {
        return array_values(array_filter(
            self::nameTokens($value),
            fn (string $t) => ! in_array($t, self::GENERIC_TOKENS, true) && mb_strlen($t) >= 2,
        ));
    }

    /**
     * Sørensen–Dice coefficient over the two token multisets (0–1).
     * Deterministic; no external library.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function tokenDice(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $bCounts = array_count_values($b);
        $intersection = 0;

        foreach (array_count_values($a) as $token => $count) {
            if (isset($bCounts[$token])) {
                $intersection += min($count, $bCounts[$token]);
            }
        }

        return (2 * $intersection) / (count($a) + count($b));
    }

    /**
     * True when every distinctive token of the shorter name also appears
     * in the longer one (a conservative "contained in" test).
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function tokenSubset(array $a, array $b): bool
    {
        if ($a === [] || $b === []) {
            return false;
        }

        [$short, $long] = count($a) <= count($b) ? [$a, $b] : [$b, $a];

        foreach ($short as $token) {
            if (! in_array($token, $long, true)) {
                return false;
            }
        }

        return true;
    }
}
