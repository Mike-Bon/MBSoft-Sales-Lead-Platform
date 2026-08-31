<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use Tests\TestCase;

/**
 * The application's single business currency is PHP (Philippine Peso).
 * App\Support\Money is presentation only — it never converts an amount
 * and never relabels a value that already carries an explicit ISO code.
 */
class MoneyTest extends TestCase
{
    public function test_the_default_currency_is_php(): void
    {
        $this->assertSame('PHP', Money::defaultCurrency());
    }

    public function test_php_amounts_render_with_the_peso_sign(): void
    {
        $this->assertSame('₱1,250.00', Money::format(1250, 'PHP'));
        $this->assertSame('₱25,000.00', Money::format(25000, 'PHP'));
        $this->assertSame('₱1,000,000.00', Money::format(1000000, 'PHP'));
        $this->assertSame('₱1,250', Money::format(1250, 'PHP', 0));
    }

    public function test_a_missing_currency_is_treated_as_the_application_default(): void
    {
        $this->assertSame('₱500.00', Money::format(500, null));
        $this->assertSame('₱500.00', Money::format(500, ''));
    }

    public function test_an_explicit_non_default_currency_keeps_its_iso_code_and_is_never_converted(): void
    {
        // A legacy USD record still shows "USD 1,000.00" — the number is
        // untouched, no peso sign, no exchange-rate maths.
        $this->assertSame('USD 1,000.00', Money::format(1000, 'USD'));
        $this->assertSame('EUR 2,500.00', Money::format(2500, 'eur'));
        $this->assertSame('GBP 900.00', Money::format(900, 'GBP'));
    }

    public function test_the_amount_is_formatted_but_never_scaled(): void
    {
        $this->assertSame('₱1,000.00', Money::format(1000, 'PHP'));
        $this->assertSame('₱1,234.57', Money::format(1234.567, 'PHP'));
    }

    public function test_the_configured_default_currency_is_honoured(): void
    {
        config(['app.currency' => 'USD']);

        $this->assertSame('USD', Money::defaultCurrency());
        $this->assertSame('USD 10.00', Money::format(10, null));
    }
}
