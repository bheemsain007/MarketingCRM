<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A stored override for one config key (SEC-CFG-04).
 *
 * Read through `SettingsService`, never directly - the service is what applies
 * the config fallback and the cache, and a caller reading this model straight
 * would see "no row" as "no value" rather than "use the default".
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $key
 * @property string|null $value
 * @property bool $is_secret
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $updatedBy
 */
class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'key',
        'value',
        'is_secret',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            // Encrypted for every setting, not only secrets - see the migration.
            'value' => 'encrypted',
            'is_secret' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
