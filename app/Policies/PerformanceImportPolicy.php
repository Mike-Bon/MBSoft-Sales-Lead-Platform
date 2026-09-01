<?php

namespace App\Policies;

use App\Enums\PerformanceImportChannel;
use App\Enums\PerformanceImportType;
use App\Models\PerformanceImport;
use App\Models\User;

/**
 * Governs the web "Import Actuals" staged-preview record. A CLI batch, a
 * plan import, or another Manager's preview can never be driven through
 * the web review/confirm/cancel screens.
 *
 * Mirrors App\Policies\ProspectLeadProposalPolicy: only the Manager who
 * uploaded the file may review, confirm or cancel it.
 */
class PerformanceImportPolicy
{
    private function isOwnActualsCsvPreview(User $user, PerformanceImport $import): bool
    {
        return $user->isManager()
            && $import->type === PerformanceImportType::Actual
            && $import->channel === PerformanceImportChannel::CsvImport
            && $import->imported_by === $user->id;
    }

    public function view(User $user, PerformanceImport $import): bool
    {
        return $this->isOwnActualsCsvPreview($user, $import);
    }

    public function confirm(User $user, PerformanceImport $import): bool
    {
        return $this->isOwnActualsCsvPreview($user, $import);
    }

    public function cancel(User $user, PerformanceImport $import): bool
    {
        return $this->isOwnActualsCsvPreview($user, $import);
    }
}
