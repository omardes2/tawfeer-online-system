<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المصروف التشغيلي الثابت لليوم (رواتب، إيجار مستودع، كهرباء).
 *
 * **لا يُوزَّع على الأصناف.** فهو لا يتغيّر بتغيّر الإعلان: الرواتب والإيجار
 * تُدفع سواء صُرف على صنفٍ أم أُوقف. وتوزيعُه يجعل الصنف الذي باع ثلاثة طلبات
 * في يوم هادئ يبدو خاسرًا، فيُوقَف إعلانٌ كان **يساهم** في تغطيته — ولا يوفّر
 * إيقافُه شيئًا من المصروف، بل يُسقط مساهمته. فيبقى على مستوى اليوم وحده:
 * صفوف الأصناف تقرّر الإعلان، وبطاقة اليوم تقرّر ربح النشاط.
 *
 * وبتاريخ سريان لا بقيمة واحدة: تغيّرُ الرواتب لاحقًا يجب ألّا يُعيد كتابة ربح
 * الأشهر الماضية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operating_daily_costs', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->decimal('amount', 15, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // القيمة القائمة اليوم. التاريخ بعيدٌ عمدًا كي تشمل التقاريرَ الرجعية.
        DB::table('operating_daily_costs')->insert([
            'effective_from' => '2026-01-01',
            'amount' => 500,
            'note' => 'رواتب وإيجار مستودع وكهرباء',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('operating_daily_costs');
    }
};
