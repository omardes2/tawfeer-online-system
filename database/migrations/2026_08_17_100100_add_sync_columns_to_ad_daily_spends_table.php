<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مصدر الصرف وقيمة المنصّة — لتعايش الإدخال اليدوي مع المزامنة.
 *
 * المزامنة **لا تدهس ما أُدخل باليد**: قد يكون المستخدم صحّح رقمًا، أو أدخل صرفًا
 * لقناةٍ لم تُربَط بعد. لكنّ إخفاء ما تقوله Meta ليس حلًّا أيضًا — فتُكتَب قيمة
 * المنصّة دائمًا في `synced_*`، وتُعرَض بجانب القيمة اليدوية عند اختلافهما
 * ليقرّر المستخدم أيّهما الصحيح.
 *
 * وصفٌّ بلا `source` (سابقٌ لهذه الهجرة) يدويٌّ بحكم الواقع — لا مزامنة كانت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_daily_spends', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('conversations');
            $table->decimal('synced_amount_usd', 15, 2)->nullable()->after('source');
            $table->unsignedInteger('synced_conversations')->nullable()->after('synced_amount_usd');
            $table->timestamp('synced_at')->nullable()->after('synced_conversations');
        });
    }

    public function down(): void
    {
        Schema::table('ad_daily_spends', function (Blueprint $table) {
            $table->dropColumn(['source', 'synced_amount_usd', 'synced_conversations', 'synced_at']);
        });
    }
};
