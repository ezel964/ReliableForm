<?php

declare(strict_types=1);

/**
 * Pure CSV helpers for the export (extracted from pages/export_csv.php so
 * they are unit-testable; behavior identical). No I/O, no globals.
 */

/**
 * Encode one CSV cell (pure: string in, encoded string out).
 * - Formula-injection guard: a leading = + - @ gets a single-quote prefix so
 *   spreadsheets treat the cell as text, never as a formula.
 * - RFC-4180: wrap in double quotes when the value contains a quote, comma,
 *   CR or LF; inner quotes are doubled.
 */
function csv_cell(string $value): string
{
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        $value = "'" . $value;
    }
    if (strpbrk($value, "\",\r\n") !== false) {
        $value = '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

/** Flatten a stored answer to the exported string (arrays = checkbox values). */
function csv_answer(mixed $answer): string
{
    if (is_array($answer)) {
        return implode('; ', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $answer));
    }
    return is_scalar($answer) ? (string) $answer : '';
}
