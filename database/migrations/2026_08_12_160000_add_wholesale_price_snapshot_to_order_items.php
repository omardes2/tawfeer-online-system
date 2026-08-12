<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فصل **سعر الجملة** عن **تكلفة الشراء**: ربح المسوّق = سعر البيع − سعر الجملة
 * (السعر الذي «يشتري» به المسوّق)، بينما تكلفة البضاعة المباعة محاسبيًا تبقى على
 * متوسط تكلفة الشراء (WAC) في `wholesale_cost_snapshot`. كان الاثنان عمودًا واحدًا
 * فاحتُسب ربح المسوّق على WAC فظهر أقل/أكثر من الفرق الحقيقي.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_items') || Schema::hasColumn('order_items', 'wholesale_price_snapshot')) {
            return;
        }

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('wholesale_price_snapshot', 15, 2)->nullable()->after('wholesale_cost_snapshot');
        });

        // ترقيع البنود القائمة بسعر الجملة الحالي للمتغيّر (أقرب تقدير متاح بأثر رجعي).
        // استعلام فرعي مرتبط لا JOIN — SQLite لا يدعم UPDATE…JOIN (الاختبارات تعمل عليه).
        DB::table('order_items')->whereNull('wholesale_price_snapshot')->update([
            'wholesale_price_snapshot' => DB::raw(
                '(select wholesale_price from product_variants where product_variants.id = order_items.variant_id)'
            ),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'wholesale_price_snapshot')) {
            Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('wholesale_price_snapshot'));
        }
    }
};
