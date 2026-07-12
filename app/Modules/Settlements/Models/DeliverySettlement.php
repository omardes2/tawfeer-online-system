<?php

namespace App\Modules\Settlements\Models;

use App\Models\User;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * تسوية مالية مع مزوّد التوصيل (Phase 4.6 / ADR-041). كيان حسّاس: uuid + soft-delete + auditable.
 */
class DeliverySettlement extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    /** الحالات القانونية. */
    public const TRANSITIONS = [
        'draft' => ['reconciled', 'cancelled'],
        'reconciled' => ['posted', 'draft', 'cancelled'],
        'posted' => ['closed'],
        'closed' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'number', 'delivery_provider_id', 'period_start', 'period_end', 'status',
        'reported_cod_total', 'reported_fees_total', 'reported_deductions_total', 'reported_net',
        'computed_cod_total', 'computed_fees_total', 'computed_deductions_total', 'computed_net', 'variance',
        'notes', 'accounting_entry_id', 'reconciled_by', 'posted_by', 'reconciled_at', 'posted_at', 'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'reported_cod_total' => 'decimal:2',
        'reported_fees_total' => 'decimal:2',
        'reported_deductions_total' => 'decimal:2',
        'reported_net' => 'decimal:2',
        'computed_cod_total' => 'decimal:2',
        'computed_fees_total' => 'decimal:2',
        'computed_deductions_total' => 'decimal:2',
        'computed_net' => 'decimal:2',
        'variance' => 'decimal:2',
        'reconciled_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SettlementLine::class)->orderBy('id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
