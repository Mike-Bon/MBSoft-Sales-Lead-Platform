<?php

namespace App\Support\Performance;

/**
 * The single authoritative numeric-parsing rule for operational
 * performance figures (units and revenue), shared by:
 *   - the CSV importer (App\Services\Performance\PerformanceImportService)
 *   - the manual-entry validation rule (App\Rules\PerformanceAmount)
 *
 * Accepts an optional-sign integer or fixed-point decimal only —
 * "0", "278", "278.4", "278.40", "0.25" — after thousands separators,
 * a peso mark, an explicit "PHP" token and whitespace are stripped.
 *
 * Rejects (returns false): blank when not allowed, non-numeric text,
 * "NaN" / "Infinity", scientific notation, a bare leading/trailing dot,
 * a formula / CSV-injection string ("=…", "+…", "@…"), and — when
 * config('performance.import.reject_negative_values') is true — any
 * negative value.
 *
 * Never rounds: the exact decimal value is returned and only quantised
 * to 2 dp at persistence by the model's decimal:2 cast.
 */
final class ActualAmountParser
{
    /**
     * @return float|null|false null = blank (only when $allowBlank), false = malformed / negative
     */
    public static function parse(string $raw, bool $allowBlank): float|null|false
    {
        $clean = self::stripNoise($raw);

        if ($clean === '') {
            return $allowBlank ? null : false;
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $clean) !== 1) {
            return false;
        }

        $value = (float) $clean;

        if (! is_finite($value)) {
            return false;
        }

        if ($value < 0 && self::rejectsNegatives()) {
            return false;
        }

        return $value;
    }

    public static function rejectsNegatives(): bool
    {
        return (bool) config('performance.import.reject_negative_values', true);
    }

    private static function stripNoise(string $raw): string
    {
        // Remove an explicit "PHP"/"php" token first, then thousands
        // separators, the peso mark and whitespace (incl. non-breaking
        // spaces). A leading "=", "+", "@" is left in place and fails the
        // numeric regex above — it is never evaluated.
        $raw = str_ireplace('php', '', trim($raw));

        return str_replace(["\xC2\xA0", ' ', ',', '₱'], '', $raw);
    }
}
