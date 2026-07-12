<?php

namespace App\Modules\Commissions\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * قاعدة عمولة/أرباح قابلة للإعداد (Phase 4.2 / ADR-037). الأولويّة تُشتقّ من أخصّ نطاق.
 */
class CommissionRule extends Model
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'earner_type', 'method', 'rate', 'amount',
        'user_id', 'campaign', 'product_id', 'category_id', 'branch_id', 'role',
        'period_start', 'period_end', 'include_shipping', 'priority', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'amount' => 'decimal:2',
        'period_start' => 'date',
        'period_end' => 'date',
        'include_shipping' => 'boolean',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];
}
