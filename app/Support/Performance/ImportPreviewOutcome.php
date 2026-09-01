<?php

namespace App\Support\Performance;

use App\Models\PerformanceImport;

/**
 * The result of confirming (committing) a staged web import preview.
 *
 * status:
 *   committed            — the actual lines were written
 *   already_completed    — a second confirm of the same preview (double-click safe)
 *   expired              — the preview TTL passed; nothing written
 *   not_actionable       — the preview was cancelled / is not in Previewing state
 *   fingerprint_mismatch — the submitted token does not match the reviewed payload
 *   data_changed         — an actual line changed since the preview; a fresh
 *                          preview has been generated ($import is the new state)
 */
final readonly class ImportPreviewOutcome
{
    /**
     * @param  array<string, int>  $stats  created / updated / unchanged
     */
    public function __construct(
        public string $status,
        public PerformanceImport $import,
        public array $stats = [],
        public string $message = '',
    ) {}

    public function committed(): bool
    {
        return $this->status === 'committed';
    }
}
