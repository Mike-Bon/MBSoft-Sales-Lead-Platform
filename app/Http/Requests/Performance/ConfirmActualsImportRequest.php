<?php

namespace App\Http\Requests\Performance;

use App\Models\PerformanceImport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST — the Manager confirms a staged preview. `authorize()` re-checks
 * PerformanceImportPolicy server-side; the `fingerprint` binds this
 * confirmation to exactly the parsed payload that was reviewed
 * (PerformanceImportService::commitPreview re-verifies it under a row
 * lock and re-checks the live data).
 */
class ConfirmActualsImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $import = $this->route('import');

        return $user !== null
            && $import instanceof PerformanceImport
            && $user->can('confirm', $import);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fingerprint' => ['required', 'string', 'size:64'],
        ];
    }

    public function fingerprint(): string
    {
        return (string) $this->validated()['fingerprint'];
    }
}
