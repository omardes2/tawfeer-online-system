<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الرصيد الافتتاحي للعميل — ما كان عليه قبل دخوله النظام.
 *
 * `opening_entry_id` ليس زينةً بل الحارس: بدونه لا يعرف النظام أن القيد رُحّل
 * مرّة، فيُعيد ترحيله مع كل حفظٍ لصفحة التعديل ويتضاعف رصيدُ العميل بصمت.
 * وبوجوده يُعرف القيد الأصلي فيُعكس ويُصحَّح عند تغيير الرقم — تصحيحًا لا حذفًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('opening_balance', 15, 2)->default(0)->after('credit_limit');
            $table->foreignId('opening_entry_id')->nullable()->after('opening_balance')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_entry_id');
            $table->dropColumn('opening_balance');
        });
    }
};
