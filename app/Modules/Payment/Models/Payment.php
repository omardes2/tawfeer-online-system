<?php

namespace App\Modules\Payment\Models;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\PaymentMethod;
use App\Modules\Sales\Models\Order;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Payment\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * دفعة مقابل طلب (ADR-028). كيان حسّاس: uuid + soft-delete + auditable.
 * pending → processing → paid | failed | cancelled | (paid →) refunded/partially_refunded.
 */
class Payment extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'number', 'order_id', 'branch_id', 'payment_method_id',
        'amount', 'refunded_amount', 'currency', 'status',
        'provider_reference', 'provider_metadata',
        'paid_at', 'failed_at', 'refunded_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'provider_metadata' => 'array',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function newFactory(): Factory
    {
        return PaymentFactory::new();
    }
}
