<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Foundation\Models\DeliveryBusiness;
use App\Modules\Sales\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قناة إعلان — صفحة بيع يُصرَف عليها إعلانيًّا (فيسبوك/إنستغرام/واتساب).
 *
 * تُربَط بحساب «البزنس» لدى شركة التوصيل، ومنه يُستنتج إسنادُ الطلب بلا إدخال
 * يدوي: الطلب ← منشئُه ← حسابُ بزنسه ← الصفحة.
 */
class AdChannel extends Model
{
    /** المنصّات المتاحة للقناة. */
    public const PLATFORMS = [
        'facebook' => 'فيسبوك',
        'instagram' => 'إنستغرام',
        'whatsapp' => 'واتساب',
        'other' => 'أخرى',
    ];

    protected $fillable = ['name', 'platform', 'delivery_business_id', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function deliveryBusiness(): BelongsTo
    {
        return $this->belongsTo(DeliveryBusiness::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function dailySpends(): HasMany
    {
        return $this->hasMany(AdDailySpend::class);
    }

    public function platformLabel(): string
    {
        return __(self::PLATFORMS[$this->platform] ?? $this->platform);
    }

    /** ترتيب العرض الموحّد: النشطة أولًا ثم حسب الترتيب اليدوي فالاسم. */
    public function scopeOrdered($query)
    {
        return $query->orderByDesc('is_active')->orderBy('sort_order')->orderBy('name');
    }
}
