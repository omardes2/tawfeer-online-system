<?php

namespace App\Modules\Store\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Crm\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * عنصر قائمة المفضّلة (Phase 3.4 / ADR-035): منتج مفضّل لعميل.
 * جاهزية «ذكاء قائمة الأمنيات» مستقبلًا عبر الاستعلامات/الأحداث (بلا منطق نمو).
 */
class WishlistItem extends Model
{
    protected $fillable = ['customer_id', 'product_id'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
