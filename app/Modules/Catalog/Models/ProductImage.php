<?php

namespace App\Modules\Catalog\Models;

use Database\Factories\Catalog\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * وسائط المنتج (PHASE_2_DESIGN §20). `path` مخزّن على قرص public.
 */
class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = ['product_id', 'variant_id', 'path', 'alt', 'sort_order', 'is_primary'];

    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * رابط عام للصورة من قرص التخزين public.
     */
    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    protected static function newFactory(): Factory
    {
        return ProductImageFactory::new();
    }
}
