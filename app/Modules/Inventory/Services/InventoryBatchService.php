<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * عمليات مخزون دفعية (Phase 5 / ADR-043) — تسويات متعدّدة الأسطر دفعةً واحدة **عبر محرّك
 * المخزون** (`InventoryService::adjustIn/adjustOut`)، فتُسجَّل الحركة والدفتر والتدقيق لكل سطر.
 * ذرّية: تنجح كلّها أو تفشل كلّها.
 */
class InventoryBatchService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * تسوية دفعية بفروق (delta) موقّعة لكل صنف.
     *
     * @param  array<int, array{variant_id:int, delta:float, note?:string}>  $lines
     * @return int عدد الأسطر المطبَّقة
     */
    public function adjust(Warehouse $warehouse, array $lines, string $reason): int
    {
        if (empty($lines)) {
            throw ValidationException::withMessages(['lines' => __('لا توجد أسطر.')]);
        }

        return DB::transaction(function () use ($warehouse, $lines, $reason) {
            $applied = 0;
            foreach ($lines as $line) {
                $delta = round((float) $line['delta'], 3);
                if (abs($delta) < 1e-9) {
                    continue;
                }
                $variant = ProductVariant::findOrFail((int) $line['variant_id']);
                $opts = ['reference_type' => 'batch', 'reason' => $reason, 'note' => $line['note'] ?? null];

                if ($delta > 0) {
                    $this->inventory->adjustIn($variant, $warehouse, $delta, null, $opts);
                } else {
                    $this->inventory->adjustOut($variant, $warehouse, abs($delta), $opts);
                }
                $applied++;
            }

            return $applied;
        });
    }
}
