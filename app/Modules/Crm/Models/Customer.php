<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Sales\Models\Order;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Crm\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * عميل (ADR-030، BR-CUST). كيان مهم: uuid + soft-delete + auditable.
 * حقول credit_limit/loyalty_points جاهزة للربط المستقبلي دون إعادة تصميم.
 */
class Customer extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'user_id', 'branch_id', 'name', 'email', 'primary_phone', 'source',
        'category', 'tags', 'credit_limit', 'loyalty_points',
        'is_high_risk', 'is_blocked', 'blocked_reason', 'blocked_at',
        'cancelled_orders_count', 'returns_count', 'merged_into_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'tags' => 'array',
        'credit_limit' => 'decimal:2',
        'loyalty_points' => 'integer',
        'is_high_risk' => 'boolean',
        'is_blocked' => 'boolean',
        'blocked_at' => 'datetime',
        'cancelled_orders_count' => 'integer',
        'returns_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function phones(): HasMany
    {
        return $this->hasMany(CustomerPhone::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function customerNotes(): HasMany
    {
        return $this->hasMany(CustomerNote::class)->latest('id');
    }

    /** سجلّ الطلبات (BR-CUST-07) — عبر عمود orders.customer_id (مربوط بالخدمة). */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_blocked', false)->whereNull('merged_into_id');
    }

    protected static function newFactory(): Factory
    {
        return CustomerFactory::new();
    }
}
