<?php

namespace App\Modules\Commissions\Models;

use App\Models\User;
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
        'earner_id', 'earner_type', 'total', 'reference', 'status',
        'accounting_entry_id', 'notes', 'created_by', 'paid_at',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function earner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'earner_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CommissionPayoutEntry::class);
    }
}
