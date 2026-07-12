<?php

namespace App\Modules\Commissions\Models;

use App\Models\User;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * حركة دفتر عمولة/أرباح (Phase 4.2 / ADR-037) — **غير قابلة للتعديل** في مبالغها.
 * الحالة (state) تتحوّل ضمن آلة حالات مع تدوين كل تحوّل في commission_transitions.
 * المبالغ/الأساس/النوع ثابتة بعد الإنشاء (حارس أدناه) — التعديل عبر حركة جديدة.
 */
class CommissionEntry extends Model
{
    use HasUuid;

    /** الحقول غير القابلة للتعديل بعد الإنشاء (دفتر غير قابل للتعديل). */
    private const IMMUTABLE = ['earner_type', 'earner_id', 'order_id', 'order_item_id', 'entry_type', 'basis', 'rate', 'amount'];

    protected $fillable = [
        'earner_type', 'earner_id', 'order_id', 'order_item_id', 'variant_id',
        'entry_type', 'basis', 'rate', 'amount', 'wholesale_cost_snapshot',
        'rule_id', 'rule_snapshot', 'state', 'reverses_entry_id', 'adjusts_entry_id',
        'settlement_reference', 'created_by',
    ];

    protected $casts = [
        'basis' => 'decimal:2',
        'rate' => 'decimal:6',
        'amount' => 'decimal:2',
        'wholesale_cost_snapshot' => 'decimal:2',
        'rule_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        // حارس عدم القابلية للتعديل: يُسمح بتغيير الحالة والمرجع فقط، لا المبالغ.
        static::updating(function (CommissionEntry $entry) {
            foreach (self::IMMUTABLE as $field) {
                if ($entry->isDirty($field)) {
                    throw new RuntimeException("Commission ledger entry field [{$field}] is immutable.");
                }
            }
        });
    }

    public function earner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'earner_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(CommissionTransition::class)->orderBy('id');
    }
}
