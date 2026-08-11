<?php

namespace App\Models;

use App\Enums\DialerState;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A telecaller's dialling run (FR-CALL-06).
 *
 * @property DialerState $state
 * @property-read User|null $user
 * @property-read Collection<int, AutoDialerQueueItem> $items
 */
class AutoDialerSession extends Model
{
    use HasFactory;

    /**
     * State and the counters are excluded from mass assignment: they are
     * written by AutoDialerService, never from request input (SEC-IN-06). A
     * client that could set `state` could resume a stopped run.
     */
    protected $fillable = ['tenant_id', 'user_id', 'filters'];

    protected function casts(): array
    {
        return [
            'state' => DialerState::class,
            'filters' => 'array',
            'total_items' => 'integer',
            'dialled_items' => 'integer',
            'skipped_items' => 'integer',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AutoDialerQueueItem::class)->orderBy('position');
    }

    /** How far through the list this run is, 0-100. */
    public function progress(): int
    {
        if ($this->total_items < 1) {
            return 100;
        }

        $handled = $this->dialled_items + $this->skipped_items;

        return (int) floor(min($handled, $this->total_items) / $this->total_items * 100);
    }

    public function remaining(): int
    {
        return max(0, $this->total_items - $this->dialled_items - $this->skipped_items);
    }
}
