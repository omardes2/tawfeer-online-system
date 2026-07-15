<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceItem extends Model
{
    protected $fillable = [
        'purchase_invoice_id', 'variant_id', 'description',
        'new_product_name', 'new_product_sell_price',
        'qty', 'unit_cost', 'tax_rate', 'tax_amount', 'line_total',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'new_product_sell_price' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
