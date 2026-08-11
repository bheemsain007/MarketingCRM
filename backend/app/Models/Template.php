<?php

namespace App\Models;

use App\Enums\Channel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $code
 * @property Channel $channel
 * @property string|null $subject
 * @property string $body
 * @property array|null $variables
 * @property array|null $media
 * @property string|null $provider
 * @property string|null $provider_template_id
 * @property string $approval_status
 * @property string|null $rejection_reason
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Campaign> $campaigns
 */
class Template extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'channel', 'subject', 'body',
        'variables', 'media', 'provider', 'provider_template_id',
        'approval_status', 'rejection_reason', 'is_active',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'variables' => 'array',
            'media' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * WhatsApp and RCS templates must be approved by the PROVIDER, not just
     * locally, before they can be used in a send.
     */
    public function isSendable(): bool
    {
        return $this->is_active && $this->approval_status === 'approved';
    }
}
