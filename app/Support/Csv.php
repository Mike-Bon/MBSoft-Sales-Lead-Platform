<?php

namespace App\Support;

use RuntimeException;

/**
 * A minimal, dependency-free CSV reader for the operational-performance
 * importer. Reads a UTF-8 CSV file into header-keyed rows, each tagged
 * with its 1-based source line number for row-numbered error reporting.
 * No spreadsheet library is added.
 */
final class Csv
{
    /**
     * @return list<array{line: int, data: array<string, string>}>
     */
    public static function readFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("CSV file not found or not readable: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open CSV file: {$path}");
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false || $header === null) {
                throw new RuntimeException('CSV file is empty.');
            }

            // Strip a UTF-8 BOM from the first header cell.
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

            if (count(array_filter($header, fn ($h) => $h !== '')) === 0) {
                throw new RuntimeException('CSV header row is blank.');
            }

            $rows = [];
            $line = 1;

            while (($record = fgetcsv($handle)) !== false && $record !== null) {
                $line++;

                // Skip entirely blank lines.
                if (count(array_filter($record, fn ($c) => trim((string) $c) !== '')) === 0) {
                    continue;
                }

                $data = [];
                foreach ($header as $i => $key) {
                    if ($key === '') {
                        continue;
                    }
                    $data[$key] = isset($record[$i]) ? trim((string) $record[$i]) : '';
                }

                $rows[] = ['line' => $line, 'data' => $data];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
