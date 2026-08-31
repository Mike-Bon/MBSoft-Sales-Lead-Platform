<?php

namespace App\Support;

/**
 * User-facing money presentation for the application's single business
 * currency (config('app.currency'), default PHP — Philippine Peso).
 *
 * This is presentation only. It never converts an amount and never
 * changes what a stored value represents: a record that already carries
 * an explicit non-default currency code (e.g. a legacy 'USD' opportunity)
 * is still shown with that ISO code, unmixed and unconverted — exactly
 * the behaviour the CRM and PerformanceService already relied on. Only
 * the default currency (a missing / null / empty code) is rendered with
 * the peso sign.
 *
 * Machine-readable contexts — AI tool JSON, snapshot toArray(), form
 * <input> values, exports — keep using the raw ISO code, not this
 * formatter.
 */
final class Money
{
    /** The application's default business currency as an ISO 4217 code. */
    public static function defaultCurrency(): string
    {
        $code = strtoupper(trim((string) config('app.currency', 'PHP')));

        return $code !== '' ? $code : 'PHP';
    }

    /**
     * Format a monetary amount for display.
     *
     * PHP renders as "₱1,250.00"; any other currency keeps the existing
     * "CODE 1,250.00" ISO-prefix convention. A null/empty currency is
     * treated as the application default.
     */
    public static function format(float|int|string|null $amount, ?string $currency = null, int $decimals = 2): string
    {
        $code = strtoupper(trim((string) ($currency ?? '')));

        if ($code === '') {
            $code = self::defaultCurrency();
        }

        $number = number_format((float) $amount, $decimals);

        return $code === 'PHP' ? '₱'.$number : $code.' '.$number;
    }

    /** The user-facing symbol for a currency code (peso sign for PHP). */
    public static function symbol(?string $currency = null): string
    {
        $code = strtoupper(trim((string) ($currency ?? '')));

        if ($code === '') {
            $code = self::defaultCurrency();
        }

        return $code === 'PHP' ? '₱' : $code.' ';
    }
}
