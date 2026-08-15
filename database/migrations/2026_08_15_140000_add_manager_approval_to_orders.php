<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اعتماد المدير للطلب («تأكيد»).
 *
 * مستقلّ عن `confirmed_at`: ذاك تأكيدٌ داخلي يُرحّل الطلب محاسبيًّا ويرسله لشركة
 * التوصيل، وهذا قرارُ مراجعةٍ من المدير يُغلق الإلغاء في وجه مُدخِل الطلب بينما
 * الطرد ما زال بانتظار الاستلام.
 *
 * لا علاقة له بتكامل التوصيل: لا يُرسَل للمزوّد ولا يدخل في أي حمولة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('confirmed_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
