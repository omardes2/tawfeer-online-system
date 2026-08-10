<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * تعبئة لقطة تكلفة الجملة للبنود القديمة (كانت تُترك NULL قبل إصلاح OrderService::syncItems).
 * تقارير الربح تحسب التكلفة عبر wholesale_cost_snapshot بلا بديل، فالـNULL كان يجعل
 * التكلفة صفرًا والربح متضخّمًا. نملأها بأفضل تقدير متاح: متوسّط تكلفة المتغيّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            UPDATE order_items
            SET wholesale_cost_snapshot = COALESCE((
                SELECT product_variants.average_cost
                FROM product_variants
                WHERE product_variants.id = order_items.variant_id
            ), 0)
            WHERE wholesale_cost_snapshot IS NULL
        ');
    }

    public function down(): void
    {
        // لا تراجع: إعادة القيم إلى NULL تُعيد خطأ احتساب التكلفة صفرًا.
    }
};
