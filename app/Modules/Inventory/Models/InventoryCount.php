<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Foundation\Models\Warehouse;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * جرد مخزون فعلي (Phase 5 / ADR-043). كيان حسّاس: uuid + soft-delete + auditable.
 * counting → review → completed (+ cancelled). الإكمال يطبّق الفروق عبر محرّك المخزون.
 */
class InventoryCount extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    public const TRANSITIONS = [
        'counting' => ['review', 'cancelled'],
        'review' => ['completed', 'counting', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'number', 'warehouse_id', 'status', 'scope', 'notes', 'adjusted_count',
        'counted_by', 'reviewed_by', 'completed_at', 'created_by',
    ];

    protected $casts = ['completed_at' => 'datetime', 'adjusted_count' => 'integer'];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryCountItem::class)->orderBy('id');
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
