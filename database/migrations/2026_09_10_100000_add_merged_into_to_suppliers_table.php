<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دمج الموردين المكرّرين — أثرُ الدمج على المورد المدموج.
 *
 * المورد الواحد قد يُدخَل مرّتين بفارق حرف («بضاعه» و«بضاعة»)، فيُفتح له حسابان
 * فرعيّان وتتوزّع حركاته بينهما، ويظهر في ميزان المراجعة مرّتين.
 *
 * وحذفُه ليس حلًّا: حسابه يحمل قيودًا مُرحّلة، وحذفُ حسابٍ ذي تاريخ يكسر الدفتر.
 * فيُدمَج: يُنقل رصيده بقيد إعادة تصنيف، ويُعطَّل حسابه، ويُشار به إلى من دُمج
 * فيه — فيبقى تاريخه مقروءًا ولا يُحسب مرّتين.
 *
 * إضافةٌ خالصة: عمودٌ واحد nullable بلا `UPDATE` ولا backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('merged_into_id')->nullable()->after('gl_account_id')
                ->constrained('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_id');
        });
    }
};
