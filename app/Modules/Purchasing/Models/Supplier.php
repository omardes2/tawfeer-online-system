<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Foundation\Models\Branch;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Purchasing\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * المورد (PHASE_2_DESIGN §9). كيان حسّاس: uuid + soft-delete + auditable.
 */
class Supplier extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'branch_id', 'name', 'code', 'legal_name', 'tax_number', 'email', 'phone',
        'address', 'governorate_id', 'city_id', 'currency_id',
        'payment_terms_days', 'credit_limit', 'opening_balance', 'notes', 'is_active',
    ];

    protected $casts = [
        'payment_terms_days' => 'integer',
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): Factory
    {
        return SupplierFactory::new();
    }
}
