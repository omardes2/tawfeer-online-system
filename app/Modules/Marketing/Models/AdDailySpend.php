<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صرفُ يومٍ واحد على صنفٍ واحد في قناةٍ واحدة، كما نُسخ من مدير إعلانات Meta.
 */
class AdDailySpend extends Model
{
    protected $fillable = [
        'spend_date', 'ad_channel_id', 'product_id',
        'amount_usd', 'fx_rate', 'conversations', 'entered_by',
    ];

    protected $casts = [
        'spend_date' => 'date',
        'amount_usd' => 'decimal:2',
        'fx_rate' => 'decimal:4',
        'conversations' => 'integer',
    ];

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
