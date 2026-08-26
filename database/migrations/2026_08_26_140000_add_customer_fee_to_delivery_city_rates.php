<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سعر بيع التوصيل للزبون — منفصلًا عن تكلفته لدى شركة التوصيل.
 *
 * ## Protected Delivery Integration — Do Not Modify
 *
 * المسار قبل هذه الهجرة: `delivery_city_rates.delivery_fee` رقمٌ واحد يُزامَن من
 * المزوّد، ثم يُكتب في مكانين معًا — `shipments.shipping_cost` (ما ندفع)
 * و`orders.shipping_total` (ما نتقاضى). فالهامش صفرٌ **بحكم البنية** لا بحكم
 * التسعير: لا موضعَ في المخطّط يقول «تدفع ١٧ وتتقاضى ٢٠».
 *
 * ## ولماذا عمودٌ ثانٍ لا تعديلُ الأوّل
 *
 * `delivery_fee` تُزامَن من Opost بزرٍّ واحد وتُكتب فوق أي تعديل يدويّ. فسعرُ
 * بيعٍ يُكتب فيها يُمحى عند أوّل مزامنة. والعمود الثاني يبقى بيدك وحدك.
 *
 * ## والفراغ يعني «لا هامش»
 *
 * `NULL` لا صفر: من تتركه فارغًا يبقى سعرُه للزبون هو التكلفة تمامًا كما كان.
 * فإدخال طبقة تسعيرٍ على نظامٍ يبيع فعلًا يجب ألّا يحرّك سعر مدينةٍ واحدة حتى
 * يُضبط سعرُها بيدٍ صريحة. والصفر كان سيعني «التوصيل مجّاني» ويُلغي الرسوم
 * كلّها عند أوّل نشر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_city_rates') || Schema::hasColumn('delivery_city_rates', 'customer_fee')) {
            return;
        }

        Schema::table('delivery_city_rates', function (Blueprint $table) {
            $table->decimal('customer_fee', 15, 2)->nullable()->after('delivery_fee');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('delivery_city_rates', 'customer_fee')) {
            Schema::table('delivery_city_rates', fn (Blueprint $table) => $table->dropColumn('customer_fee'));
        }
    }
};
