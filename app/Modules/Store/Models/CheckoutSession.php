<?php

namespace App\Modules\Store\Models;

use App\Models\User;
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
        'shipping_address', 'payment_method_code', 'notes', 'order_id',
    ];

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

    /** اكتملت البيانات المطلوبة للإتمام؟ */
    public function isReady(): bool
    {
        return filled($this->customer_name)
            && filled($this->customer_phone)
            && filled($this->shipping_address)
            && filled($this->payment_method_code);
    }
}
