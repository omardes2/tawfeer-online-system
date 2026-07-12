<?php

namespace App\Modules\Store\Models;

use App\Models\User;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Support\Concerns\HasUuid;
use Database\Factories\Store\CartFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * سلة تسوّق (ADR-031). سلة نشطة واحدة لكل مستخدم؛ تُحوَّل إلى طلب في الدفع (3.2).
 */
class Cart extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = ['user_id', 'customer_id', 'branch_id', 'session_token', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    public function subtotal(): float
    {
        return (float) $this->items->sum(fn ($i) => (float) $i->qty * (float) $i->unit_price);
    }

    protected static function newFactory(): Factory
    {
        return CartFactory::new();
    }
}
