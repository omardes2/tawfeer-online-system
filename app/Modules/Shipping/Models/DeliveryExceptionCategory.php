<?php

namespace App\Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * فئة استثناء توصيل قابلة للضبط (ADR-039) — SLA/تصعيد لكل فئة.
 */
class DeliveryExceptionCategory extends Model
{
    protected $fillable = ['code', 'name', 'sla_hours', 'escalate_after_hours', 'escalate_to_role', 'is_active'];

    protected $casts = [
        'sla_hours' => 'integer',
        'escalate_after_hours' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
