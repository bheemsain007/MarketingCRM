<?php

namespace App\Models\Builders;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * The query-builder half of audit immutability (SEC-AUD-01).
 *
 * Model events (`updating`, `deleting`) only fire when a row is mutated through
 * a loaded model. `AuditLog::where(...)->delete()` goes straight to the query
 * builder, fires nothing, and would sail past a guard that only hooked those
 * events - which makes it the first thing anybody would reach for, deliberately
 * or by habit. Hence a builder that refuses the three mass operations as well,
 * so the rule holds whichever door the call comes through.
 *
 * Inserts are untouched: this is append-ONLY, not read-only.
 *
 * @extends Builder<AuditLog>
 */
class AuditLogBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values)
    {
        throw ImmutableRecordException::for('audit_logs', 'updated');
    }

    public function delete()
    {
        throw ImmutableRecordException::for('audit_logs', 'deleted');
    }

    public function forceDelete()
    {
        throw ImmutableRecordException::for('audit_logs', 'deleted');
    }

    /**
     * An update wearing an insert's clothes: a matched row is rewritten without
     * a model ever being loaded, so it needs its own refusal.
     *
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw ImmutableRecordException::for('audit_logs', 'updated');
    }
}
