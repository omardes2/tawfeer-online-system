<?php

namespace App\Modules\Sales\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند طلب بيع (ADR-026). لقطة سعر ثابتة (BR-ORD-18). التكلفة/COGS يحسبها المحرّك بـ WAC.
 */
class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'variant_id', 'qty', 'unit_price', 'discount',
        'tax_rate', 'tax_amount', 'line_total', 'qty_reserved', 'qty_shipped',
        // Phase 4.1 — لقطات السعر/التكلفة وتعديل السعر اليدوي (BR-ORD-18، المتطلّب 2).
        'retail_price_snapshot', 'wholesale_cost_snapshot', 'price_change_reason', 'price_approved_by',
        // سعر الجملة وقت البيع — أساس ربح المسوّق (منفصل عن تكلفة الشراء أعلاه).
        'wholesale_price_snapshot',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'qty_reserved' => 'decimal:3',
        'qty_shipped' => 'decimal:3',
        'retail_price_snapshot' => 'decimal:2',
        'wholesale_cost_snapshot' => 'decimal:2',
        'wholesale_price_snapshot' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * خيارات المتغيّر (لون/مقاس) كنصّ واحد، أو `''` لصنفٍ بلا خيارات.
     *
     * موضعٌ واحد تقرأ منه الفاتورةُ وحمولةُ الشحنة معًا: بند «قميص» بلا لونه
     * ولا مقاسه لا يكفي مَن يجهّزه في المستودع ولا مَن يسلّمه للزبون، وتكرار
     * التنسيق في الموضعين يجعلهما يفترقان بأول تعديل.
     */
    public function optionsLabel(): string
    {
        $values = $this->variant?->attributeValues;

        if ($values === null) {
            return '';
        }

        return $values->map(fn ($v) => $v->label ?: $v->value)->filter()->implode(' - ');
    }
}
