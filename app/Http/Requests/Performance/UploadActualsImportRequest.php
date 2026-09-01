<?php

namespace App\Http\Requests\Performance;

use App\Services\PerformanceAuthorizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST — the Manager uploads an actuals CSV. This request validates the
 * transport only (a real CSV, within the size cap). Row-level validation
 * is PerformanceImportService::preview(); nothing is written here.
 */
class UploadActualsImportRequest extends FormRequest
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
            'file' => [
                'required', 'file',
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
                'extensions:csv,txt',
                'max:'.(int) config('performance.import.max_upload_kb', 512),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => 'The file must be :max KB or smaller.',
            'file.mimetypes' => 'The file must be a CSV.',
            'file.extensions' => 'The file must be a .csv file.',
        ];
    }
}
