<?php

namespace App\Http\Requests\Performance;

use App\Models\ReportingUnit;
use App\Rules\PerformanceAmount;
use App\Services\Performance\ManualActualEntryService;
use App\Services\PerformanceAuthorizer;
use App\Support\Performance\ActualAmountParser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST — the Manager enters or corrects a single reporting-unit/month
 * actual. Same numeric rule as the CSV importer (PerformanceAmount).
 *
 * A `reason` is:
 *   - optional when creating a previously-missing actual;
 *   - REQUIRED when changing an existing reported value to a different one.
 * The service re-checks everything under a row lock at write time.
 */
class StoreManualActualRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && app(PerformanceAuthorizer::class)->canManageActuals($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fiscal_year' => ['required', 'integer', 'between:2000,2100'],
            'reporting_unit_id' => ['required', 'integer', 'exists:reporting_units,id'],
            'period_month' => ['required', 'integer', 'between:1,12'],
            'actual_revenue' => ['required', 'string', new PerformanceAmount(allowBlank: false)],
            'actual_units' => ['nullable', 'string', new PerformanceAmount(allowBlank: true)],
            'reason' => ['nullable', 'string', 'max:500'],
            'lock' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $unit = ReportingUnit::query()->find($this->integer('reporting_unit_id'));
            if ($unit === null) {
                return;
            }

            $revenue = ActualAmountParser::parse((string) $this->input('actual_revenue'), allowBlank: false);
            $units = ActualAmountParser::parse((string) ($this->input('actual_units') ?? ''), allowBlank: true);
            if ($revenue === false || $units === false) {
                return; // already reported by the rule
            }

            $changesExisting = app(ManualActualEntryService::class)->wouldChangeExisting(
                $this->integer('fiscal_year'), $unit, $this->integer('period_month'), (float) $revenue, $units,
            );

            if ($changesExisting && trim((string) $this->input('reason', '')) === '') {
                $validator->errors()->add('reason', 'Explain why this reported value is being changed.');
            }
        });
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->input('reason', ''));

        return $reason === '' ? null : $reason;
    }
}
