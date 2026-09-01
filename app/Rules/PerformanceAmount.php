<?php

namespace App\Rules;

use App\Support\Performance\ActualAmountParser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a manually-entered operational figure with EXACTLY the rules
 * the CSV importer applies (via App\Support\Performance\ActualAmountParser):
 * plain integer or fixed-point decimal, no negatives, no scientific
 * notation, no NaN/Infinity, no formulas.
 *
 * new PerformanceAmount(allowBlank: true)  → an empty units field is OK
 *                                            ("units not reported" → NULL)
 * new PerformanceAmount(allowBlank: false) → a revenue field must have a
 *                                            value (there is no "blank
 *                                            revenue" — no row = not
 *                                            reported)
 */
class PerformanceAmount implements ValidationRule
{
    public function __construct(private readonly bool $allowBlank) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $raw = is_scalar($value) ? (string) $value : '';

        $parsed = ActualAmountParser::parse($raw, $this->allowBlank);

        if ($parsed === false) {
            $fail(ActualAmountParser::rejectsNegatives()
                ? 'The :attribute must be a non-negative number (for example 0, 278 or 278.40).'
                : 'The :attribute must be a valid number (for example 0, 278 or 278.40).');
        }
    }
}
