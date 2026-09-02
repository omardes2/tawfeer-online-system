<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حذفٌ ناعم لأرشيف الدفعات — سجلٌّ ماليّ لا يُمحى.
 *
 * الدفعة تُقابل سندَ صرفٍ خرج به مال. وحذفُها محوًا يترك السند في الدفتر بلا
 * صاحبٍ ظاهر في كشف المسوّق، ويُفقد جوابَ «ما هذا السند؟ ولمن صُرف؟» — وهو
 * أوّل ما يُسأل عند المراجعة.
 *
 * فيُخفى الصفّ عن الأرشيف ويبقى في قاعدة البيانات مربوطًا بسنده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_payouts', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('commission_payouts', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
