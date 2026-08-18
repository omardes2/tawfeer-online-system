<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * خزينة/حساب بنكي (Phase 7.1). مرتبطة بحساب GL مخصّص؛ الرصيد يُشتقّ من القيود المُرحّلة
 * على ذلك الحساب (عبر AccountingService::accountBalance) — لا رصيد مخزَّن. type: cash|bank.
 */
class Treasury extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'name_en', 'type', 'gl_account_id', 'currency', 'opening_balance',
        'is_active', 'is_default', 'bank_name', 'account_name', 'account_number', 'iban', 'swift', 'created_by',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    /**
     * قيد الرصيد الافتتاحي.
     *
     * خارج `$fillable` عمدًا: يُكتب من الخدمة مع قيده في معاملةٍ واحدة، فإسنادٌ
     * جماعي يربط عمودًا بقيدٍ لم يُرحَّل — أو يفكّ ربطًا فيتضاعف الرصيد عند أول
     * تعديل.
     */
    public function openingEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'opening_entry_id');
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(FinancialVoucher::class, 'treasury_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isBank(): bool
    {
        return $this->type === 'bank';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
