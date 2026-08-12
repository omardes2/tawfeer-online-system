<?php

namespace App\Modules\Commissions\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    // العلاقات — لعرض نطاق القاعدة بالأسماء بدل المعرّفات.

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** وصف مقروء لنطاق القاعدة: «عامة» أو الأبعاد المحدَّدة بأسمائها. */
    public function scopeLabel(): string
    {
        $parts = array_filter([
            $this->user?->name,
            $this->product?->name,
            $this->category?->name,
            $this->branch?->name,
            $this->campaign,
            $this->role,
        ]);

        return $parts === [] ? __('commissions.scope_general') : implode(' • ', $parts);
    }
}
