<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قيد الرصيد الافتتاحي على المورد.
 *
 * `suppliers.opening_balance` كان موجودًا منذ البداية ويُقبل في نموذج المورد،
 * لكنه **لم يُرحَّل قط**: رقمٌ على الصفّ يظهر في قائمة الموردين وصفحتهم، ولا
 * أثر له في دليل الحسابات. فيقول الجدولُ إن على الشركة مبلغًا لا يعرفه ميزان
 * المراجعة.
 *
 * وبمعرفة القيد يصير الرقم قابلًا للتصحيح: يُعكس الأصل ويُرحّل مصحَّح، بدل أن
 * يُضاف قيدٌ ثانٍ فوق الأول فتتضاعف ذمّة المورد.
 *
 * الأرصدة القائمة **لا تُرحَّل هنا**: ترحيلُ قيودٍ من هجرة يكتب في دفاترٍ قد
 * تكون فتراتها مقفلة دون أن يطلب أحد ذلك. تبقى كما هي، وتُرحَّل عند أول حفظٍ
 * للمورد — و`syncOpeningBalance` يعرف الفرق بين «رقمٌ مُرحّل» و«رقمٌ بلا قيد».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('opening_entry_id')->nullable()->after('opening_balance')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_entry_id');
        });
    }
};
