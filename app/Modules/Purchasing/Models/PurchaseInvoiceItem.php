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
        'qty', 'unit_price_foreign', 'cbm_per_unit',
        'unit_cost', 'landed_unit_cost', 'landed_line_total', 'landed_is_manual',
        'tax_rate', 'tax_amount', 'line_total',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price_foreign' => 'decimal:4',
        'cbm_per_unit' => 'decimal:6',
        'unit_cost' => 'decimal:4',
        'landed_unit_cost' => 'decimal:4',
        'landed_line_total' => 'decimal:2',
        'landed_is_manual' => 'boolean',
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
