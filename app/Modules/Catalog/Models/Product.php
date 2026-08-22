<?php

namespace App\Modules\Catalog\Models;

use App\Modules\AiAgent\Models\ProductKnowledge;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Sales\Models\OrderItem;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Catalog\ProductFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * المنتج الكتالوجي (PHASE_2_DESIGN §16 + ADR-023).
 *
 * `is_active` مُشتقّ من `status` عبر ProductService (ADR-023). حقول التكلفة
 * حسّاسة وتُحجب في الـResource بلا صلاحية `pricing.view_cost` (ADR-013).
 */
class Product extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'branch_id', 'category_id', 'brand_id', 'unit_id', 'tax_id',
        'name', 'name_en', 'slug', 'sku', 'barcode', 'type',
        'short_description', 'short_description_en', 'description', 'description_en',
        'cost_price', 'average_cost', 'retail_price', 'wholesale_price', 'marketer_price',
        'min_price', 'promo_price', 'promo_starts_at', 'promo_ends_at', 'target_margin',
        'weight', 'cbm', 'reorder_level', 'track_inventory',
        'status', 'visibility', 'available_to_affiliates', 'is_featured', 'sort_order', 'is_active',
        'meta_title', 'meta_description', 'meta_keywords', 'search_keywords',
        'revenue_account_id', 'inventory_account_id', 'cogs_account_id',
    ];

    protected $casts = [
        'available_to_affiliates' => 'boolean',
        'cost_price' => 'decimal:4',
        'average_cost' => 'decimal:4',
        'retail_price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'marketer_price' => 'decimal:2',
        'min_price' => 'decimal:2',
        'promo_price' => 'decimal:2',
        'target_margin' => 'decimal:4',
        'weight' => 'decimal:3',
        'cbm' => 'decimal:6',
        'reorder_level' => 'decimal:3',
        'promo_starts_at' => 'datetime',
        'promo_ends_at' => 'datetime',
        'track_inventory' => 'boolean',
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    /**
     * المعرفة البيعية — ما يقوله وكيل المبيعات عن الصنف.
     *
     * منفصلةٌ عن الوصف عمدًا: الوصف للزبون يقرؤه في المتجر، وهذه للوكيل يبني
     * عليها حواره. وخلطُهما يُنتج وصفًا تسويقيًّا في صفحة المنتج وردًّا جافًّا
     * في واتساب — أو العكس.
     */
    public function knowledge(): HasOne
    {
        return $this->hasOne(ProductKnowledge::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /** عروض الكمّية — على الصنف، وتطال متغيّراته جميعًا. */
    public function offers(): HasMany
    {
        return $this->hasMany(ProductOffer::class)->orderBy('min_qty');
    }

    /** ما يُعرَض منها في المتجر. */
    public function activeOffers(): HasMany
    {
        return $this->offers()->where('is_active', true);
    }

    /** المعتمَد وحده — ما يراه زوّار المتجر. */
    public function approvedReviews(): HasMany
    {
        return $this->reviews()->where('status', ProductReview::APPROVED)->latest();
    }

    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    /** أرصدة المخزون لكل متغيّرات المنتج (عبر ProductVariant) — لتجميع المتوفّر. */
    public function stocks(): HasManyThrough
    {
        return $this->hasManyThrough(
            InventoryStock::class,
            ProductVariant::class,
            'product_id', 'variant_id', 'id', 'id',
        );
    }

    /** بنود الطلبات لكل متغيّرات المنتج (عبر ProductVariant) — لتجميع المباع. */
    public function orderItems(): HasManyThrough
    {
        return $this->hasManyThrough(
            OrderItem::class,
            ProductVariant::class,
            'product_id', 'variant_id', 'id', 'id',
        );
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ProductTag::class, 'product_tag_links', 'product_id', 'product_tag_id')->withTimestamps();
    }

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttribute::class, 'product_attribute_links', 'product_id', 'attribute_id')->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVisible($query)
    {
        return $query->where('visibility', 'visible');
    }

    /**
     * الأصناف التي يجوز للمسوّق بيعها.
     *
     * مستقلّ عن `visible`: الظهور للزبون شيء، وإتاحة الصنف للمسوّق شيء آخر.
     */
    public function scopeAvailableToAffiliates($query)
    {
        return $query->where('available_to_affiliates', true);
    }

    protected static function newFactory(): Factory
    {
        return ProductFactory::new();
    }
}
