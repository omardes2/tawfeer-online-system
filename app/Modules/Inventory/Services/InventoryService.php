<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryLedger;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * محرّك المخزون (§22-24، ADR-005/007/008). كل عملية ذرّية مع قفل صف الرصيد،
 * تُحدّث الدلو، تحتسب WAC، تمنع السالب، وتكتب حركة + قيد دفتر.
 */
class InventoryService
{
    /** استلام (purchase_in) — يزيد on_hand ويعيد حساب WAC (ADR-005). */
    public function receive(ProductVariant $variant, Warehouse $warehouse, float $qty, float $unitCost, array $opts = []): InventoryMovement
    {
        $this->assertPositive($qty);

        return $this->tx(fn () => $this->record($variant, $warehouse, 'purchase_in', 'on_hand', $qty, $unitCost, true, $opts));
    }

    /** صرف/بيع (sale_out) — يخصم on_hand بتكلفة WAC (COGS). */
    public function issue(ProductVariant $variant, Warehouse $warehouse, float $qty, array $opts = []): InventoryMovement
    {
        $this->assertPositive($qty);

        return $this->tx(fn () => $this->record($variant, $warehouse, 'sale_out', 'on_hand', -$qty, null, false, $opts));
    }

    /** تسوية زيادة (adjustment_in). */
    public function adjustIn(ProductVariant $variant, Warehouse $warehouse, float $qty, ?float $unitCost, array $opts = []): InventoryMovement
    {
        $this->assertPositive($qty);

        return $this->tx(fn () => $this->record($variant, $warehouse, 'adjustment_in', 'on_hand', $qty, $unitCost, $unitCost !== null, $opts));
    }

    /** تسوية نقص (adjustment_out). */
    public function adjustOut(ProductVariant $variant, Warehouse $warehouse, float $qty, array $opts = []): InventoryMovement
    {
        $this->assertPositive($qty);

        return $this->tx(fn () => $this->record($variant, $warehouse, 'adjustment_out', 'on_hand', -$qty, null, false, $opts));
    }

    /** تحويل بين مستودعين — حركتان ذرّيتان (transfer_out ثم transfer_in). */
    public function transfer(ProductVariant $variant, Warehouse $from, Warehouse $to, float $qty, array $opts = []): array
    {
        $this->assertPositive($qty);

        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['warehouse' => __('لا يمكن التحويل إلى المستودع نفسه.')]);
        }

        return $this->tx(function () use ($variant, $from, $to, $qty, $opts) {
            $sourceWac = (float) $this->lockedStock($variant->id, $from)->average_cost;
            $out = $this->record($variant, $from, 'transfer_out', 'on_hand', -$qty, null, false, $opts + ['to_warehouse_id' => $to->id]);
            $in = $this->record($variant, $to, 'transfer_in', 'on_hand', $qty, $sourceWac, true, $opts);

            return [$out, $in];
        });
    }

    /** مرتجع مشتريات صادر (purchase_return_out) — يخفّض on_hand بتكلفة WAC (ADR-025). */
    public function purchaseReturn(ProductVariant $variant, Warehouse $warehouse, float $qty, array $opts = []): InventoryMovement
    {
        $this->assertPositive($qty);

        return $this->tx(fn () => $this->record($variant, $warehouse, 'purchase_return_out', 'on_hand', -$qty, null, false, $opts));
    }

    /** حجز — يزيد دلو reserved (لا يمسّ on_hand). */
    public function reserve(ProductVariant $variant, Warehouse $warehouse, float $qty, array $opts = []): InventoryMovement
    {
        $this->assertPositive($qty);

        return $this->tx(fn () => $this->record($variant, $warehouse, 'reserve', 'reserved', $qty, null, false, $opts));
    }

    /** تحرير حجز — يخصم دلو reserved. */
    public function release(ProductVariant $variant, Warehouse $warehouse, float $qty, array $opts = []): InventoryMovement
    {
        $this->assertPositive($qty);

        return $this->tx(fn () => $this->record($variant, $warehouse, 'release', 'reserved', -$qty, null, false, $opts));
    }

    /**
     * النواة: تطبّق تغيّرًا على دلو واحد داخل معاملة (مفترضة قائمة)، مع منع السالب،
     * وإعادة حساب WAC عند الإدخال، وكتابة الحركة والقيد.
     */
    private function record(ProductVariant $variant, Warehouse $warehouse, string $type, string $bucket, float $qtyChange, ?float $unitCost, bool $recomputeWac, array $opts): InventoryMovement
    {
        $stock = $this->lockedStock($variant->id, $warehouse);

        $onHand = (float) $stock->on_hand;
        $reserved = (float) $stock->reserved;
        $wac = (float) $stock->average_cost;

        // إعادة حساب WAC عند إدخال بتكلفة (ADR-005).
        if ($recomputeWac && $qtyChange > 0 && $unitCost !== null) {
            $newQty = $onHand + $qtyChange;
            $wac = $newQty > 0 ? (($onHand * $wac) + ($qtyChange * $unitCost)) / $newQty : $unitCost;
            $stock->cost_price = $unitCost;
        }

        // تحديث الدلو المستهدف.
        $newBucketValue = (float) $stock->{$bucket} + $qtyChange;

        // منع السالب (ADR-007a): available = on_hand − reserved لا يقلّ عن الصفر.
        $projectedOnHand = $bucket === 'on_hand' ? $onHand + $qtyChange : $onHand;
        $projectedReserved = $bucket === 'reserved' ? $reserved + $qtyChange : $reserved;

        if (! $warehouse->allow_negative && ($projectedOnHand - $projectedReserved) < -1e-9) {
            throw ValidationException::withMessages([
                'qty' => __('الكمية المتاحة غير كافية في المستودع (:wh).', ['wh' => $warehouse->name]),
            ]);
        }
        if ($newBucketValue < -1e-9 && ! $warehouse->allow_negative) {
            throw ValidationException::withMessages([
                'qty' => __('لا يمكن أن يصبح رصيد الدلو سالبًا.'),
            ]);
        }

        $stock->{$bucket} = $newBucketValue;
        $stock->average_cost = $wac;
        $stock->last_movement_at = $stock->freshTimestamp();
        $stock->save();

        // مزامنة WAC المتغيّر (مرجع للتقارير).
        if ($recomputeWac) {
            $variant->forceFill(['average_cost' => $wac, 'cost_price' => $unitCost ?? $variant->cost_price])->saveQuietly();
        }

        $movement = InventoryMovement::create([
            'branch_id' => $opts['branch_id'] ?? $warehouse->branch_id,
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'to_warehouse_id' => $opts['to_warehouse_id'] ?? null,
            'type' => $type,
            'bucket' => $bucket,
            'qty' => abs($qtyChange),
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost !== null ? abs($qtyChange) * $unitCost : null,
            'reference_type' => $opts['reference_type'] ?? null,
            'reference_id' => $opts['reference_id'] ?? null,
            'reason' => $opts['reason'] ?? null,
            'note' => $opts['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        $valueChange = $bucket === 'on_hand' ? $qtyChange * ($unitCost !== null && $qtyChange > 0 ? $unitCost : $wac) : 0.0;

        InventoryLedger::create([
            'variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'movement_id' => $movement->id,
            'branch_id' => $movement->branch_id,
            'movement_type' => $type,
            'bucket' => $bucket,
            'reference_type' => $opts['reference_type'] ?? null,
            'reference_id' => $opts['reference_id'] ?? null,
            'qty_change' => $qtyChange,
            'balance_after' => $newBucketValue,
            'unit_cost' => $unitCost,
            'wac_after' => $wac,
            'value_change' => $valueChange,
            'balance_value_after' => (float) $stock->on_hand * $wac,
            'created_by' => auth()->id(),
            'created_at' => $movement->created_at,
        ]);

        return $movement;
    }

    private function lockedStock(int $variantId, Warehouse $warehouse): InventoryStock
    {
        $stock = InventoryStock::where('variant_id', $variantId)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()->first();

        if (! $stock) {
            $stock = InventoryStock::create(['variant_id' => $variantId, 'warehouse_id' => $warehouse->id]);
            $stock = InventoryStock::whereKey($stock->id)->lockForUpdate()->first();
        }

        return $stock;
    }

    private function assertPositive(float $qty): void
    {
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => __('الكمية يجب أن تكون أكبر من صفر.')]);
        }
    }

    /**
     * معاملة مع إعادة محاولة عند التزاحم/الجمود (deadlock) — مهم للتحويلات المتوازية.
     */
    private function tx(callable $callback): mixed
    {
        return DB::transaction($callback, 3);
    }
}
