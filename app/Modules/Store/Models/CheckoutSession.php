<?php

namespace App\Modules\Store\Models;

use App\Models\User;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use App\Modules\Sales\Models\Order;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * جلسة إتمام الشراء متعدّدة الخطوات (ADR-033). تُنشأ من سلة نشطة وتتراكم عليها
 * بيانات الإتمام، ثم يُنشأ الطلب ذرّيًا في خطوة الإتمام النهائية.
 */
class CheckoutSession extends Model
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'cart_id', 'user_id', 'session_token', 'status',
        'customer_name', 'customer_phone', 'customer_email',
        'shipping_address', 'city_id', 'area_id',
        'payment_method_code', 'notes', 'order_id',
        'recovery_status', 'recovery_note', 'recovery_contacted_at',
        'recovery_attempts', 'recovery_user_id', 'recovery_order_id',
    ];

    protected $casts = [
        'recovery_contacted_at' => 'datetime',
        'recovery_attempts' => 'integer',
    ];

    /** نتائج متابعة الاسترداد — `recovered` تُسنَد يدويًّا أو تُكشَف من طلبٍ لاحق. */
    public const RECOVERY_STATUSES = ['new', 'contacted', 'no_answer', 'refused', 'recovered', 'ignored'];

    /** حالاتٌ ما زال صاحبها يستحقّ اتصالًا. */
    public const OPEN_RECOVERY_STATUSES = ['new', 'contacted', 'no_answer'];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function recoveryUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recovery_user_id');
    }

    public function recoveryOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'recovery_order_id');
    }

    /** اكتملت البيانات المطلوبة للإتمام؟ */
    public function isReady(): bool
    {
        return filled($this->customer_name)
            && filled($this->customer_phone)
            && filled($this->shipping_address)
            && filled($this->payment_method_code);
    }
}
