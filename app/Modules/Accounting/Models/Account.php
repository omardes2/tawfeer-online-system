<?php

namespace App\Modules\Accounting\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * حساب في دليل الحسابات (ADR-029، BR-ACC). الرصيد يُشتقّ من journal_lines لا يُخزَّن (المتطلّب 4).
 */
class Account extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    /** أنواع مدينة الطبيعة (asset/expense) مقابل دائنة (liability/equity/revenue). */
    public const DEBIT_NORMAL = ['asset', 'expense'];

    protected $fillable = ['code', 'name', 'name_en', 'type', 'parent_id', 'is_postable', 'currency', 'is_active'];

    protected $casts = ['is_postable' => 'boolean', 'is_active' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function isDebitNormal(): bool
    {
        return in_array($this->type, self::DEBIT_NORMAL, true);
    }

    public function scopePostable($query)
    {
        return $query->where('is_postable', true);
    }
}
