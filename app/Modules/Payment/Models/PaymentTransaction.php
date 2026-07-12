<?php

namespace App\Modules\Payment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تفاعل مزوّد دفع (ADR-028، المتطلّب 5). append-only: بلا updated_at.
 * بنية عامة لمراجع/حالات/callbacks/بيانات أي مزوّد.
 */
class PaymentTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_id', 'kind', 'status', 'amount', 'provider_reference',
        'request_payload', 'response_payload', 'created_by', 'created_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
