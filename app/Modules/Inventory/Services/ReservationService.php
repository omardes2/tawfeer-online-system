<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * حجوزات المخزون (§25، ADR-009): إنشاء يزيد دلو reserved؛ التحرير يعيده.
 */
class ReservationService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function reserve(ProductVariant $variant, Warehouse $warehouse, float $qty, array $opts = []): StockReservation
    {
        return DB::transaction(function () use ($variant, $warehouse, $qty, $opts) {
            $reservation = StockReservation::create([
                'variant_id' => $variant->id,
                'warehouse_id' => $warehouse->id,
                'reference_type' => $opts['reference_type'] ?? null,
                'reference_id' => $opts['reference_id'] ?? null,
                'qty' => $qty,
                'status' => 'active',
                'reserved_at' => now(),
                'expires_at' => $opts['expires_at'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // يزيد دلو reserved ويكتب حركة + قيد (قد يفشل لو المتاح غير كافٍ → rollback).
            $this->inventory->reserve($variant, $warehouse, $qty, [
                'reference_type' => StockReservation::class,
                'reference_id' => $reservation->id,
                'reason' => 'reservation',
            ]);

            return $reservation;
        });
    }

    public function release(StockReservation $reservation): StockReservation
    {
        if ($reservation->status !== 'active') {
            throw ValidationException::withMessages(['status' => __('لا يمكن تحرير حجز غير نشط.')]);
        }

        return DB::transaction(function () use ($reservation) {
            $this->inventory->release($reservation->variant, $reservation->warehouse, (float) $reservation->qty, [
                'reference_type' => StockReservation::class,
                'reference_id' => $reservation->id,
                'reason' => 'release',
            ]);

            $reservation->update(['status' => 'released', 'released_at' => now()]);

            return $reservation;
        });
    }

    /**
     * استهلاك الحجز عند الشحن (ADR-009): يخفّض دلو reserved ويضع الحجز في حالة consumed.
     * الخصم النهائي من on_hand (sale_out/COGS) يجريه المستهلك (OrderService) عبر issue().
     */
    public function consume(StockReservation $reservation): StockReservation
    {
        if ($reservation->status !== 'active') {
            throw ValidationException::withMessages(['status' => __('لا يمكن استهلاك حجز غير نشط.')]);
        }

        return DB::transaction(function () use ($reservation) {
            $this->inventory->release($reservation->variant, $reservation->warehouse, (float) $reservation->qty, [
                'reference_type' => StockReservation::class,
                'reference_id' => $reservation->id,
                'reason' => 'consume',
            ]);

            $reservation->update(['status' => 'consumed', 'released_at' => now()]);

            return $reservation;
        });
    }
}
