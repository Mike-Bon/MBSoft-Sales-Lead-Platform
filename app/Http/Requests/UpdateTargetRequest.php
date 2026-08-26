<?php

namespace App\Http\Requests;

use App\Enums\TargetPeriodType;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Target;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Target $target */
        $target = $this->route('target');

        return $this->user()?->can('update', $target) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_type' => ['required', Rule::enum(TargetType::class)],
            'owner_id' => [
                Rule::requiredIf(fn () => in_array($this->input('target_type'), [TargetType::Manager->value, TargetType::Individual->value], true)),
                'nullable', 'integer', 'exists:users,id',
            ],
            'team_id' => [
                Rule::requiredIf(fn () => $this->input('target_type') === TargetType::Team->value),
                'nullable', 'integer', 'exists:teams,id',
            ],
            'period_type' => ['required', Rule::enum(TargetPeriodType::class)],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'target_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'status' => ['nullable', Rule::enum(TargetStatus::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
