<?php

namespace App\Modules\Commissions\Models;

use App\Models\User;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * دفعة صرف عمولة/أرباح مجمّعة (Phase 4.2 / ADR-037).
 */
class CommissionPayout extends Model
{
    use HasUuid;

    protected $fillable = [
        'earner_id', 'earner_type', 'treasury_id', 'financial_voucher_id', 'total', 'reference',
        'period_start', 'period_end', 'status', 'accounting_entry_id', 'notes', 'created_by', 'paid_at',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'period_start' => 'date',
        'period_end' => 'date',
        'paid_at' => 'datetime',
    ];

    public function earner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'earner_id');
    }

    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class, 'treasury_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(FinancialVoucher::class, 'financial_voucher_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CommissionPayoutEntry::class);
    }
}
