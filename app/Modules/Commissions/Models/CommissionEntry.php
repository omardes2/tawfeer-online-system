<?php

namespace App\Modules\Commissions\Models;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
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

    /** الصنف المُباع — به يُميَّز سطران لطلبٍ واحد في كشف المستحقّات. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(CommissionTransition::class)->orderBy('id');
    }

    // ————————————— قراءة الربح في الكشف —————————————

    /**
     * قيمة بيع البند — لا `basis`.
     *
     * أساسُ حركة المسوّق هو **الهامش** لا سعر البيع: قاعدة `margin` تُعطيه
     * الهامش كاملًا فيتساوى `basis` و`amount`. فعرضُ `basis` تحت عنوان «سعر
     * المنتج» يكتب الربح مرّتين ويُخفي السعر الذي بِيع به فعلًا — وبه وحده
     * يُراجَع الكشف أمام الفاتورة.
     *
     * وأساسُ حركة البائع قيمةُ المبيعات أصلًا، فيُقرأ كما هو.
     */
    public function saleValue(): ?float
    {
        if ($this->earner_type !== 'affiliate') {
            return round((float) $this->basis, 2);
        }

        $item = $this->orderItem;

        return $item ? round((float) $item->qty * (float) $item->unit_price, 2) : null;
    }

    /**
     * ما اشترى به المستفيد: سعر الجملة أو سعر قائمته المخصّصة — **لقطةً وقت
     * البيع** لا سعرَ اليوم، فتغيُّر القائمة لاحقًا لا يُعيد كتابة كشفٍ مضى.
     *
     * يُضرب في الكمية ليُقرأ في سطر الإجماليات نفسه: الربح = البيع − الجملة.
     */
    public function costValue(): ?float
    {
        if ($this->wholesale_cost_snapshot === null) {
            return null;   // حركة بائعٍ بنسبة — لا شراءَ لها.
        }

        $qty = (float) ($this->orderItem?->qty ?? 0);

        return $qty > 0 ? round($qty * (float) $this->wholesale_cost_snapshot, 2) : null;
    }
}
