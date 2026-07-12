<?php

namespace App\Modules\Returns\Models;

use App\Models\User;
use App\Modules\Payment\Models\Payment;
use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Models\Shipment;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * طلب مرتجع/استبدال — RMA (Phase 4.4 / ADR-040). كيان حسّاس: uuid + soft-delete + auditable.
 */
class ReturnRequest extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    protected $fillable = [
        'number', 'order_id', 'type', 'reason_code', 'resolution', 'scope', 'status',
        'refund_amount', 'price_difference', 'refund_payment_id', 'linked_shipment_id', 'notes',
        'requested_by', 'approved_by', 'received_by', 'inspected_by', 'decided_by', 'reject_reason',
        'approved_at', 'received_at', 'inspected_at', 'completed_at', 'created_by',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:2',
        'price_difference' => 'decimal:2',
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
        'inspected_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class)->orderBy('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ReturnRequestPhoto::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReturnRequestEvent::class)->orderBy('id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function linkedShipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'linked_shipment_id');
    }

    public function refundPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refund_payment_id');
    }
}
