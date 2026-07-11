<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * مستودع افتراضي للفرع الافتراضي، ويُضبط كمستودع افتراضي للفرع.
 * يمنح المخزون (Phase 2.4) موطنًا افتراضيًا دون قيم ثابتة في الكود.
 */
class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::default();

        if (! $branch) {
            return;
        }

        $warehouse = Warehouse::query()->firstOrCreate(
            ['branch_id' => $branch->id, 'code' => 'WH-MAIN'],
            [
                'name' => 'المستودع الرئيسي',
                'type' => 'main',
                'allow_negative' => false,
                'is_default' => true,
                'is_active' => true,
            ],
        );

        if (is_null($branch->default_warehouse_id)) {
            $branch->update(['default_warehouse_id' => $warehouse->id]);
        }
    }
}
