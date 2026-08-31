<?php

namespace App\Support\Performance;

use App\Models\PerformanceImport;

/**
 * The outcome of one operational-performance import (or dry run).
 * Validation is always whole-file: if $ok is false, NOTHING was written.
 */
final readonly class ImportResult
{
    /**
     * @param  list<string>  $errors  row-numbered, human-readable
     * @param  array<string, int>  $stats  e.g. ['created' => 3, 'updated' => 41]
     */
    public function __construct(
        public bool $ok,
        public bool $committed,
        public bool $dryRun,
        public int $acceptedRows,
        public int $rejectedRows,
        public array $errors,
        public array $stats,
        public ?PerformanceImport $import,
    ) {}
}
