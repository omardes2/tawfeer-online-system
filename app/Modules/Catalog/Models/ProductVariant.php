<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Inventory\Models\InventoryStock;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Catalog\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * متغيّر المنتج (PHASE_2_DESIGN §17) — الوحدة القابلة للبيع/التخزين، أساس المخزون (ADR-024).
 */
class ProductVariant extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'product_id', 'sku', 'barcode', 'name',
        'cost_price', 'average_cost', 'retail_price', 'wholesale_price',
        'marketer_price', 'min_price', 'promo_price', 'weight', 'reorder_level',
        'is_default', 'is_active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:4',
        'average_cost' => 'decimal:4',
        'retail_price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'marketer_price' => 'decimal:2',
        'min_price' => 'decimal:2',
        'promo_price' => 'decimal:2',
        'weight' => 'decimal:3',
        'reorder_level' => 'decimal:3',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** أرصدة المخزون لهذا المتغيّر — للتحميل المُسبق وتفادي N+1 عند حساب التوافر. */
    public function inventoryStocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class, 'variant_id');
    }

    /** قيم السمات التي يمثّلها هذا المتغيّر (مقاس + لون ...) — نظام المتغيّرات الكاملة. */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductAttributeValue::class,
            'product_variant_attribute_values',
            'variant_id',
            'attribute_value_id',
        )->withTimestamps();
    }

    /** متغيّر خيارات (مرتبط بقيم سمات) لا المتغيّر الافتراضي المجرّد. */
    public function scopeOptionVariants($query)
    {
        return $query->whereHas('attributeValues');
    }

    /** وصف المتغيّر من قيم سماته، مثل «L / أسود». يعود لاسمه أو SKU عند غياب القيم. */
    public function optionLabel(): string
    {
        $values = $this->relationLoaded('attributeValues') ? $this->attributeValues : $this->attributeValues()->get();

        if ($values->isEmpty()) {
            return (string) ($this->name ?: $this->sku);
        }

        return $values->map(fn ($v) => $v->label ?: $v->value)->implode(' / ');
    }

    protected static function newFactory(): Factory
    {
        return ProductVariantFactory::new();
    }
}
