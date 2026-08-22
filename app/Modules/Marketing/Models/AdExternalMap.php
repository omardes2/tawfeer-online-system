<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * ربط معرّف خارجي (حملة أو مجموعة إعلانية) بكيان في النظام.
 */
class AdExternalMap extends Model
{
    public const TYPE_CAMPAIGN = 'campaign';

    public const TYPE_ADSET = 'adset';

    protected $fillable = [
        'provider', 'external_type', 'external_id', 'external_name', 'parent_external_id',
        'ad_channel_id', 'product_id',
        'suggested_ad_channel_id', 'suggested_product_id',
        'is_ignored', 'last_seen_at',
    ];

    protected $casts = [
        'is_ignored' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AdChannel::class, 'ad_channel_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * أصناف المجموعة الإعلانية — واحدٌ أو أكثر.
     *
     * المجموعة الواحدة في مدير إعلانات ميتا قد تُعلن عن ثلاثة أصناف بميزانيةٍ
     * واحدة، وربطُها بصنفٍ واحد يُحمّله إنفاقَ ثلاثة.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ad_external_map_products');
    }

    /**
     * معرّفات الأصناف المربوطة — الجدول أوّلًا ثم العمود القديم.
     *
     * العمود يبقى للحالة الشائعة (مجموعة لصنف) ولا يُحذف، فيُقرأ الاثنان معًا
     * ولا يبقى ربطٌ خارج الحساب.
     *
     * @return array<int, int>
     */
    public function productIds(): array
    {
        $linked = $this->relationLoaded('products')
            ? $this->products->pluck('id')->all()
            : $this->products()->pluck('products.id')->all();

        if ($linked !== []) {
            return array_map('intval', $linked);
        }

        return $this->product_id ? [(int) $this->product_id] : [];
    }

    public function suggestedChannel(): BelongsTo
    {
        return $this->belongsTo(AdChannel::class, 'suggested_ad_channel_id');
    }

    public function suggestedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'suggested_product_id');
    }

    /** الهدف المربوط فعلًا — قناةٌ للحملة، وصنفٌ للمجموعة الإعلانية. */
    public function isLinked(): bool
    {
        return $this->external_type === self::TYPE_CAMPAIGN
            ? $this->ad_channel_id !== null
            : $this->product_id !== null;
    }

    /** ما يحتاج قرارًا من المستخدم: ظهر في المنصّة، وغير مربوط، ولم يُتجاهَل. */
    public function scopePendingLink(Builder $query): Builder
    {
        return $query->where('is_ignored', false)
            ->where(fn ($q) => $q
                ->where(fn ($c) => $c->where('external_type', self::TYPE_CAMPAIGN)->whereNull('ad_channel_id'))
                ->orWhere(fn ($a) => $a->where('external_type', self::TYPE_ADSET)->whereNull('product_id')));
    }
}
