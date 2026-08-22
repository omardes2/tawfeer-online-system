<?php

namespace App\Modules\AiAgent\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * المعرفة البيعية للصنف — ما يقوله البائع لا ما يقوله الكتالوج.
 *
 * `is_ready` حارسٌ لا زينة: صنفٌ لم يُكتب له بيعٌ بعد لا يبيعه الوكيل بل يحوّل
 * إلى موظفة — فالصمت أفضل من كلامٍ مخترَع باسم الشركة.
 */
class ProductKnowledge extends Model
{
    use Auditable;

    protected $table = 'product_knowledge';

    protected $fillable = [
        'product_id', 'selling_points', 'use_cases', 'objections',
        'faq', 'comparisons', 'tone_notes', 'is_ready', 'updated_by',
    ];

    protected $casts = [
        'selling_points' => 'array',
        'use_cases' => 'array',
        'objections' => 'array',
        'faq' => 'array',
        'comparisons' => 'array',
        'is_ready' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('is_ready', true);
    }
}
