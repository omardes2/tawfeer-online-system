<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سعر متغيّرٍ بعينه داخل قائمة أسعار. */
class PriceListItem extends Model
{
    protected $fillable = ['price_list_id', 'variant_id', 'price'];

    protected $casts = ['price' => 'decimal:2'];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
