<?php

namespace App\Modules\Commissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ربط بند عمولة بدفعة صرف (Phase 4.2). قيد فريد على bند العمولة يمنع الدفع المزدوج.
 */
class CommissionPayoutEntry extends Model
{
    protected $fillable = ['commission_payout_id', 'commission_entry_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(CommissionEntry::class, 'commission_entry_id');
    }
}
