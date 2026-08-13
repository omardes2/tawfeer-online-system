<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المدينة والمنطقة في جلسة إتمام شراء المتجر.
 *
 * كانت الجلسة تحمل عنوانًا نصّيًا فقط، فيصل طلب الويب بلا مدينة مُعيَّنة: لا رسوم
 * توصيل صحيحة ولا حمولة صالحة لشركة التوصيل. الأعمدة نفسها موجودة على `orders`
 * منذ البداية — هذه الهجرة تسدّ الفجوة بين الجلسة والطلب لا أكثر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->foreignId('city_id')->nullable()->after('shipping_address')
                ->constrained('cities')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->after('city_id')
                ->constrained('areas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('area_id');
            $table->dropConstrainedForeignId('city_id');
        });
    }
};
