<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Payment\Models\Payment;
use App\Modules\Shipping\Models\Shipment;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Sales\OrderFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * طلب بيع (ADR-009/010/026). كيان حسّاس: uuid + soft-delete + auditable.
 * draft → confirmed → stock_reserved → preparing → ready_to_ship → shipped → delivered (+cancelled).
 */
class Order extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'number', 'branch_id', 'warehouse_id', 'customer_id',
        'customer_name', 'customer_phone', 'customer_email', 'shipping_address',
        'channel', 'status', 'payment_status', 'assigned_to',
        'subtotal', 'discount_total', 'tax_total', 'shipping_total', 'total', 'amount_paid',
        'notes', 'cancel_reason',
        'confirmed_at', 'reserved_at', 'shipped_at', 'delivered_at', 'cancelled_at',
        'created_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'shipping_total' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'reserved_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    protected static function newFactory(): Factory
    {
        return OrderFactory::new();
    }
}
