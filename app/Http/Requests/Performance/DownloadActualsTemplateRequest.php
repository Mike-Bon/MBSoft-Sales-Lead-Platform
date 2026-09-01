<?php

namespace App\Http\Requests\Performance;

use App\Services\PerformanceAuthorizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET — the Manager picks a fiscal year + fiscal month, then downloads a
 * pre-filled month-scoped actuals template.
 */
class DownloadActualsTemplateRequest extends FormRequest
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
            'period_month' => ['required', 'integer', 'between:1,12'],
        ];
    }

    public function fiscalYear(): int
    {
        return (int) $this->validated()['fiscal_year'];
    }

    public function periodMonth(): int
    {
        return (int) $this->validated()['period_month'];
    }
}
