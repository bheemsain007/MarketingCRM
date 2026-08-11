<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Raw inbound webhook payloads, stored BEFORE processing so a handler bug can be
 * fixed and the event replayed rather than the data being lost.
 *
 * The unique (provider, provider_event_id) index is the replay protection
 * itself (SEC-WH-03).
 *
 * @property int $id
 * @property string $provider
 * @property string|null $event_type
 * @property string|null $provider_event_id
 * @property bool $signature_valid
 * @property array $payload
 * @property array|null $headers
 * @property Carbon|null $processed_at
 * @property string|null $processing_error
 * @property int $processing_attempts
 * @property Carbon $created_at
 */
class ProviderWebhookLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'provider', 'event_type', 'provider_event_id', 'signature_valid',
        'payload', 'headers', 'processed_at', 'processing_error', 'processing_attempts',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'headers' => 'array',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function scopeUnprocessed($query)
    {
        return $query->whereNull('processed_at');
    }
}
