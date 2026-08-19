<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * قائمة أسعارٍ تُسنَد إلى مشترين بعينهم (تجّار).
 *
 * `parent` يجعل تخصيص تاجرٍ بعينه ممكنًا بلا تكرار: قائمته الخاصّة تحمل ما
 * اختلف عليه وحده، وترث الباقي.
 */
class PriceList extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    protected $fillable = ['name', 'code', 'parent_id', 'is_active', 'notes', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * سلسلة الوراثة من هذه القائمة صعودًا.
     *
     * حارس الزيارة يقطع الدوران: قائمةٌ صارت أبًا لنفسها بخطأ إدخالٍ قديم كانت
     * ستُدخل الحساب في حلقةٍ لا نهائية عند كل طلب.
     *
     * @return array<int, int>
     */
    public function ancestryIds(int $maxDepth = 10): array
    {
        $ids = [];
        $node = $this;

        while ($node !== null && count($ids) < $maxDepth && ! in_array($node->id, $ids, true)) {
            $ids[] = $node->id;
            $node = $node->parent;
        }

        return $ids;
    }
}
