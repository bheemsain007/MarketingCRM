<?php

namespace App\Enums;

/**
 * Outcome of a single import row (FR-LEAD-07 acceptance criteria).
 *
 * `Duplicate` is deliberately not `Invalid`. A duplicate row is not a mistake
 * by the person who prepared the file - the lead already exists and has an
 * owner (BR-DUP-02, BR-ASSIGN-05). Reporting the two together would hide the
 * one number an operator actually needs: how many rows were genuinely
 * malformed and need fixing before a re-upload.
 *
 * `Failed` means the row was well-formed but the write itself broke - a
 * database error, a lost connection. Those are retryable; invalid rows are not.
 */
enum ImportRowStatus: string
{
    case Imported = 'imported';
    case Duplicate = 'duplicate';
    case Invalid = 'invalid';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Imported => 'Imported',
            self::Duplicate => 'Already exists',
            self::Invalid => 'Invalid',
            self::Failed => 'Failed',
        };
    }

    /** Column on `lead_imports` that this outcome increments. */
    public function counterColumn(): string
    {
        return match ($this) {
            self::Imported => 'imported_rows',
            self::Duplicate => 'duplicate_rows',
            // A row that broke on write is still a row the operator must
            // resolve, so it is counted with the rejects rather than silently.
            self::Invalid, self::Failed => 'invalid_rows',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
