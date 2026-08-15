<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حجم الوحدة بالمتر المكعّب (CBM) — أساس توزيع الشحن البحري على الأصناف في فواتير
 * الاستيراد: نصيب الوحدة من الشحن = حجمها × تكلفة المتر المكعّب في تلك الفاتورة.
 *
 * على المنتج وعلى المتغيّر معًا: المتغيّرات قد تختلف أحجامها (مقاس كبير/صغير)،
 * ويُقرأ حجم المتغيّر أولًا ثم حجم المنتج كقيمة احتياطية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cbm', 12, 4)->nullable()->after('weight');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('cbm', 12, 4)->nullable()->after('weight');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('cbm');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('cbm');
        });
    }
};
