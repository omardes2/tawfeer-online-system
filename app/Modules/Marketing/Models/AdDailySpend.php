<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * صرفُ يومٍ واحد في قناةٍ واحدة، كما نُسخ من مدير إعلانات Meta.
 *
 * الصفّ إمّا لصنفٍ واحد (`product_id`)، وإمّا **مشترَك** بين عدّة أصناف بميزانيةٍ
 * واحدة — فيُترك `product_id` فارغًا وتُسجَّل أصنافه في `products()`. وهذا ما
 * يقع فعلًا في مدير الإعلانات: إعلانٌ واحد يعرض ثلاثة أصناف بميزانيةٍ واحدة.
 */
class AdDailySpend extends Model
{
    protected $fillable = [
        'spend_date', 'ad_channel_id', 'product_id', 'label',
        'amount_usd', 'fx_rate', 'conversations', 'entered_by',
        'source', 'synced_amount_usd', 'synced_conversations', 'synced_at',
    ];

    protected $casts = [
        'spend_date' => 'date',
        'amount_usd' => 'decimal:2',
        'fx_rate' => 'decimal:4',
        'conversations' => 'integer',
        'synced_amount_usd' => 'decimal:2',
        'synced_conversations' => 'integer',
        'synced_at' => 'datetime',
    ];

    /**
     * قيمة المنصّة تخالف ما أُدخل يدويًّا.
     *
     * لا يُدهَس اليدوي ولا يُخفى ما تقوله المنصّة — يُعرَض الاختلاف ليقرّر المستخدم.
     */
    public function conflictsWithPlatform(): bool
    {
        return $this->source === 'manual'
            && $this->synced_at !== null
            && (abs((float) $this->amount_usd - (float) $this->synced_amount_usd) >= 0.01
                || (int) $this->conversations !== (int) $this->synced_conversations);
    }

    /** أصناف الإعلان المشترك — فارغةٌ في الصفّ ذي الصنف الواحد. */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ad_daily_spend_products');
    }

    /** الأصناف التي يخصّها هذا الصرف: أصناف الإعلان المشترك أو الصنف الوحيد. */
    public function productIds(): array
    {
        $shared = $this->relationLoaded('products')
            ? $this->products->pluck('id')->all()
            : $this->products()->pluck('products.id')->all();

        if ($shared !== []) {
            return array_map('intval', $shared);
        }

        return $this->product_id ? [(int) $this->product_id] : [];
    }

    /** إعلانٌ بميزانيةٍ واحدة لأكثر من صنف. */
    public function isShared(): bool
    {
        return count($this->productIds()) > 1;
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AdChannel::class, 'ad_channel_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** الصرف بالعملة المحلية بسعر صرف **يوم الصرف** لا يوم الإدخال. */
    public function amountLocal(): float
    {
        return round((float) $this->amount_usd * (float) $this->fx_rate, 2);
    }
}
