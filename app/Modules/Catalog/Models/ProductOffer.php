<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * عرض كمّية على صنف: «اشترِ :min_qty بـ:total_price».
 */
class ProductOffer extends Model
{
    protected $fillable = ['product_id', 'min_qty', 'total_price', 'label', 'is_active', 'sort_order'];

    protected $casts = [
        'min_qty' => 'integer',
        'total_price' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** الأصغر كمّيةً أولًا — وهو ترتيب العرض الطبيعي: من السعر الأصلي صعودًا. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('min_qty');
    }

    /**
     * سعر القطعة داخل العرض — مشتقٌّ لا مخزَّن.
     *
     * التقريب للعرض وحده؛ الحساب يجري على `total_price` كي لا يفترق مجموع
     * البنود عن السعر المُعلَن («ثلاث بمئة» ⇐ 33.33 × 3 = 99.99).
     */
    public function unitPrice(): float
    {
        return $this->min_qty > 0 ? round((float) $this->total_price / $this->min_qty, 2) : 0.0;
    }

    /** كم يوفّر الزبون مقابل الشراء بالسعر العادي. */
    public function savingsAgainst(float $regularUnitPrice): float
    {
        return round($regularUnitPrice * $this->min_qty - (float) $this->total_price, 2);
    }

    /** نصّ البطاقة — المُدخَل إن وُجد، وإلّا تركيبٌ من الكمّية والسعر. */
    public function title(): string
    {
        return $this->label ?: __('اشترِ :n بـ:p', [
            'n' => $this->min_qty,
            'p' => number_format((float) $this->total_price, 2),
        ]);
    }
}
