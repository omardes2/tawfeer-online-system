<?php

namespace App\Modules\Shipping\Models;

use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Foundation\Models\ShippingZone;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Shipping\ShipmentFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * شحنة (ADR-027، ADR-010/BR-ORD-10). كيان حسّاس: uuid + soft-delete + auditable.
 * not_shipped → preparing → in_transit → out_for_delivery → delivered (+failed).
 */
class Shipment extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'number', 'order_id', 'branch_id', 'warehouse_id', 'status',
        'delivery_status', 'provider_status', 'on_hold_reason', 'closed_at',
        'carrier_name', 'tracking_number',
        'recipient_name', 'recipient_phone', 'address_text',
        'governorate_id', 'city_id', 'area_id', 'shipping_zone_id',
        'shipping_cost', 'cost_source', 'cost_currency',
        'delivery_provider_id', 'external_id', 'external_code', 'provider_metadata',
        'last_synced_at', 'sync_status', 'sync_attempts', 'sync_error',
        'dispatched_at', 'delivered_at', 'failed_at', 'delivery_attempts',
        'failure_reason', 'notes', 'created_by',
    ];

    protected $casts = [
        'shipping_cost' => 'decimal:2',
        'provider_metadata' => 'array',
        'last_synced_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'closed_at' => 'datetime',
        'delivery_attempts' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function shippingZone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('id');
    }

    /** سجلّ انتقالات الحالة القانونية للتوصيل (ADR-038). */
    public function deliveryTransitions(): HasMany
    {
        return $this->hasMany(DeliveryStatusTransition::class)->orderBy('id');
    }

    /** سجلّ انتقالات حالة المزوّد الخام (ADR-038). */
    public function providerTransitions(): HasMany
    {
        return $this->hasMany(DeliveryProviderTransition::class)->orderBy('id');
    }

    /** سجلّ استيعاب أحداث المزوّد (webhook/مزامنة — ADR-039). */
    public function providerEvents(): HasMany
    {
        return $this->hasMany(DeliveryProviderEvent::class)->orderBy('id');
    }

    /** استثناءات التوصيل (ADR-039). */
    public function exceptions(): HasMany
    {
        return $this->hasMany(DeliveryException::class)->orderBy('id');
    }

    /** مكوّنات رسوم الشحنة (ADR-039). */
    public function feeComponents(): HasMany
    {
        return $this->hasMany(ShipmentFeeComponent::class)->orderBy('id');
    }

    protected static function newFactory(): Factory
    {
        return ShipmentFactory::new();
    }
}
