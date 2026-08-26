<?php

namespace App\Support\Csv;

/**
 * Neutralises formula-injection payloads before they reach a CSV cell
 * (SEC-IN-07).
 *
 * Excel, Sheets and most spreadsheet apps treat a cell as a formula the
 * moment it OPENS with =, +, - or @ - so a lead named `=cmd|'/c calc'!A1`
 * executes the instant an operator opens the exported file. Prefixing the
 * cell with a leading apostrophe forces every one of those apps to render it
 * as literal text instead, without changing what a human reads.
 *
 * A single dedicated place for this, rather than scattering the trigger list
 * through every export path this codebase ever grows, is the whole point -
 * one list to keep in sync with the next spreadsheet quirk somebody reports.
 */
class CsvFormulaGuard
{
    private const TRIGGERS = ['=', '+', '-', '@'];

    public static function neutralise(mixed $value): string
    {
        $value = (string) ($value ?? '');

        if ($value !== '' && in_array($value[0], self::TRIGGERS, true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Neutralises every value in one CSV row in place.
     *
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, string>
     */
    public static function neutraliseRow(array $row): array
    {
        return array_map(self::neutralise(...), $row);
    }
}
