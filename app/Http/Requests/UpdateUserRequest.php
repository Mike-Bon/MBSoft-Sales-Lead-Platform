<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $target */
        $target = $this->route('user');

        return $this->user()?->can('update', $target) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(UserRole::class)],
            'team_id' => [
                Rule::requiredIf(fn () => $this->input('role') !== UserRole::Manager->value),
                'nullable',
                Rule::exists(Team::class, 'id'),
            ],
        ];
    }
}
