<?php

namespace App\Support;

use Generator;
use RuntimeException;

/**
 * Streaming delimited-file reader for lead imports (FR-LEAD-07).
 *
 * Deliberately dependency-free and row-at-a-time. A 50,000-row file on
 * Hostinger shared hosting cannot be loaded into an array - the PHP memory
 * limit is not ours to raise (DEPLOYMENT §3A) - so nothing here ever holds
 * more than one row.
 *
 * Handles the three things real exported files do that break naive readers:
 * a UTF-8 BOM in front of the first header (which makes "Name" not equal
 * "Name"), semicolon or tab delimiters from non-UK/US locales, and trailing
 * blank lines.
 *
 * Scope: CSV/TSV only. `.xlsx` is a ZIP container of XML and needs a real
 * spreadsheet library - see the note in docs/TODO.md.
 */
class CsvReader
{
    private const BOM = "\xEF\xBB\xBF";

    /** Delimiters tried when sniffing, most likely first. */
    private const CANDIDATES = [',', ';', "\t", '|'];

    private readonly string $delimiter;

    /** @var array<int, string> */
    private readonly array $header;

    public function __construct(
        private readonly string $path,
        ?string $delimiter = null,
    ) {
        if (! is_readable($this->path)) {
            throw new RuntimeException('The import file could not be read.');
        }

        $this->delimiter = $delimiter ?? $this->sniffDelimiter();
        $this->header = $this->readHeader();
    }

    /** @return array<int, string> Header labels, in file order. */
    public function header(): array
    {
        return $this->header;
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * Counts data rows without materialising them, so the import can report a
     * total before processing starts.
     */
    public function countDataRows(): int
    {
        $count = 0;

        foreach ($this->rows() as $ignored) {
            $count++;
        }

        return $count;
    }

    /**
     * Yields `rowNumber => [header => value]` for each data row.
     *
     * Row numbers are 1-based over DATA rows - the header is not row 1 - so a
     * reported row number matches the spreadsheet line minus one.
     *
     * @return Generator<int, array<string, string>>
     */
    public function rows(): Generator
    {
        $handle = $this->open();

        try {
            // Discard the header line; it was already parsed in the constructor.
            fgetcsv($handle, 0, $this->delimiter, '"', '\\');

            $rowNumber = 0;

            while (($values = fgetcsv($handle, 0, $this->delimiter, '"', '\\')) !== false) {
                // fgetcsv returns [null] for a blank line. Skipping these keeps
                // trailing newlines - which almost every export has - from
                // being reported as invalid rows.
                if ($values === [null] || $this->isBlank($values)) {
                    continue;
                }

                $rowNumber++;

                yield $rowNumber => $this->combine($values);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Pairs values with header labels.
     *
     * Short rows are padded and long rows truncated rather than skipped: a
     * missing trailing column is extremely common in hand-edited files and
     * should not cost the operator a lead. If a REQUIRED field ends up empty,
     * row validation rejects it with a specific reason.
     *
     * @param  array<int, string|null>  $values
     * @return array<string, string>
     */
    private function combine(array $values): array
    {
        $row = [];

        foreach ($this->header as $index => $label) {
            $row[$label] = trim((string) ($values[$index] ?? ''));
        }

        return $row;
    }

    /** @return array<int, string> */
    private function readHeader(): array
    {
        $handle = $this->open();

        try {
            $values = fgetcsv($handle, 0, $this->delimiter, '"', '\\');
        } finally {
            fclose($handle);
        }

        if ($values === false || $values === [null] || $this->isBlank((array) $values)) {
            throw new RuntimeException('The file is empty or has no header row.');
        }

        $header = [];

        foreach ($values as $index => $label) {
            $label = trim((string) $label);

            if ($index === 0) {
                // Strip the BOM Excel writes in front of the first cell.
                $label = ltrim($label, self::BOM);
                $label = trim($label);
            }

            // An unnamed column still occupies a position, so it is kept with a
            // generated label rather than dropped - dropping it would shift
            // every value to its right onto the wrong field.
            $header[] = $label !== '' ? $label : 'column_'.($index + 1);
        }

        return $header;
    }

    /**
     * Picks the delimiter that splits the header into the most columns.
     *
     * Counting on the header alone is enough and is safer than sampling data
     * rows, where a comma inside a quoted address would skew the count.
     */
    private function sniffDelimiter(): string
    {
        $handle = $this->open();

        try {
            $line = fgets($handle);
        } finally {
            fclose($handle);
        }

        if ($line === false) {
            return ',';
        }

        $line = ltrim($line, self::BOM);

        $best = ',';
        $bestCount = 0;

        foreach (self::CANDIDATES as $candidate) {
            $count = count(str_getcsv($line, $candidate, '"', '\\'));

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /** @return resource */
    private function open()
    {
        $handle = fopen($this->path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The import file could not be opened.');
        }

        return $handle;
    }

    /** @param array<int, string|null> $values */
    private function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
