<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReturnItem extends Model
{
    protected $fillable = ['supplier_return_id', 'variant_id', 'qty', 'unit_cost'];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_cost' => 'decimal:4',
    ];

    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
