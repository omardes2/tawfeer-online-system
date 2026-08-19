<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * متابعة استرداد الطلبات غير المكتملة.
 *
 * جلسة الإتمام تحمل أصلًا اسم الزبون ورقمه ومدينته، لكنّها بلا ذاكرةِ متابعة:
 * من اتّصل؟ ومتى؟ وبِمَ انتهى؟ فبلا هذه الحقول يتّصل موظّفان بالزبون نفسه،
 * أو لا يتّصل أحد ظنًّا أن غيره اتّصل.
 *
 * الحقول على الجلسة نفسها لا في جدولٍ منفصل: العلاقة واحدٌ لواحد (جلسة معلّقة
 * واحدة لكل سلّة)، وجدولٌ ثانٍ يزيد وصلةً بلا فائدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            // new / contacted / no_answer / refused / recovered / ignored.
            // فارغٌ = لم يُتواصل معه بعد.
            $table->string('recovery_status', 20)->nullable()->after('order_id');
            $table->string('recovery_note', 500)->nullable()->after('recovery_status');
            $table->timestamp('recovery_contacted_at')->nullable()->after('recovery_note');
            $table->unsignedSmallInteger('recovery_attempts')->default(0)->after('recovery_contacted_at');
            $table->foreignId('recovery_user_id')->nullable()->after('recovery_attempts')
                ->constrained('users')->nullOnDelete();
            // الطلب الذي أُنشئ نتيجة المتابعة — يميّز الاسترداد بجهدٍ عن الشراء
            // التلقائي المتأخّر.
            $table->foreignId('recovery_order_id')->nullable()->after('recovery_user_id')
                ->constrained('orders')->nullOnDelete();

            // قائمة الاتصال تُصفّى بالحالة والزمن، وهما معًا مفتاح الاستعلام.
            $table->index(['status', 'recovery_status']);
        });
    }

    public function down(): void
    {
        Schema::table('checkout_sessions', function (Blueprint $table) {
            $table->dropIndex(['status', 'recovery_status']);
            $table->dropConstrainedForeignId('recovery_order_id');
            $table->dropConstrainedForeignId('recovery_user_id');
            $table->dropColumn(['recovery_status', 'recovery_note', 'recovery_contacted_at', 'recovery_attempts']);
        });
    }
};
