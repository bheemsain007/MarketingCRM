<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Models\Builders\AuditLogBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Immutable compliance audit trail (SEC-AUD-01).
 *
 * Append-only, and enforced here rather than asserted in prose. This docblock
 * used to say "the application must never update or delete rows" and nothing
 * stopped it: every future caller had to remember, and a compliance trail the
 * audited code can rewrite is not evidence of anything. So the refusal lives in
 * the model, where it holds for callers that have not been written yet.
 *
 * Both doors are shut, because they are genuinely separate: the model events
 * below cover a loaded row (`$log->update()`, `$log->delete()`), and
 * AuditLogBuilder covers the mass paths that fire no events at all
 * (`AuditLog::where(...)->delete()`).
 *
 * A mistaken entry is CORRECTED BY WRITING ANOTHER ONE, the way a ledger is.
 * That is the point of the constraint, not a limitation of it.
 *
 * PII and secrets are redacted before old_values/new_values are written
 * (SEC-PII-03).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property array|null $old_values
 * @property array|null $new_values
 * @property string|null $description
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'user_id', 'action', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'description', 'ip_address', 'user_agent',
    ];

    /**
     * Refuse mutation loudly rather than cancelling it silently.
     *
     * Returning false from these listeners would also stop the write, but the
     * caller would carry on believing it had succeeded and the rule would only
     * be discovered by whoever later noticed the row had not changed. An
     * exception fails the request instead, which is the correct outcome: code
     * that tries to edit the audit trail is broken and should be fixed, not
     * accommodated.
     *
     * `updating` fires before the UPDATE statement and `deleting` before the
     * DELETE, so the throw happens while the stored row is still intact.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw ImmutableRecordException::for('audit_logs', 'updated');
        });

        static::deleting(function (): void {
            throw ImmutableRecordException::for('audit_logs', 'deleted');
        });
    }

    /**
     * @param  QueryBuilder  $query
     */
    public function newEloquentBuilder($query): AuditLogBuilder
    {
        return new AuditLogBuilder($query);
    }

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable()
    {
        return $this->morphTo();
    }
}
