<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
