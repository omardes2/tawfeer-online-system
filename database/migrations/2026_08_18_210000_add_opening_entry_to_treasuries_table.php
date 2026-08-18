<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قيد الرصيد الافتتاحي على الخزينة.
 *
 * كان الرصيد الافتتاحي يُرحَّل مرّة واحدة عند إنشاء الخزينة ثم يُنسى: لا الجدول
 * يعرف قيده، ولا نموذج التعديل يُظهر الحقل. فمن نسيه لحظة الإنشاء لم يجد له
 * بابًا، ولجأ إلى قيدٍ يدوي — يضبط الدفاتر ويترك عمود «افتتاحي» صفرًا.
 *
 * وبمعرفة القيد يصير الرقم قابلًا للتصحيح: يُعكس الأصل ويُرحّل مصحَّح، بدل أن
 * يُضاف قيدٌ ثانٍ فوق الأول فيتضاعف الرصيد.
 *
 * والربط الرجعي أدناه ليس تجميلًا: خزينةٌ أُنشئت بقيدٍ افتتاحي ولا يعرفه
 * عمودُها كانت — عند أول تعديل — تُرحّل قيدًا جديدًا بلا عكسٍ للأول.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasuries', function (Blueprint $table) {
            $table->foreignId('opening_entry_id')->nullable()->after('opening_balance')
                ->constrained('journal_entries')->nullOnDelete();
        });

        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        $entries = DB::table('journal_entries')
            ->where('reference_type', 'treasury_opening')
            ->whereNotNull('reference_id')
            ->orderBy('id')
            ->get(['id', 'reference_id']);

        foreach ($entries as $entry) {
            DB::table('treasuries')
                ->where('id', $entry->reference_id)
                ->whereNull('opening_entry_id')
                ->update(['opening_entry_id' => $entry->id]);
        }
    }

    public function down(): void
    {
        Schema::table('treasuries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_entry_id');
        });
    }
};
