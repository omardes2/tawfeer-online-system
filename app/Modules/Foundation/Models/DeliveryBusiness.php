<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * حساب «بزنس» لدى شركة التوصيل (Opost). يُزامَن من المزوّد ويُربَط به المستخدمون.
 */
class DeliveryBusiness extends Model
{
    protected $fillable = [
        'provider', 'external_id', 'name', 'address_external_id', 'phone', 'is_active', 'raw',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'raw' => 'array',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'delivery_business_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
