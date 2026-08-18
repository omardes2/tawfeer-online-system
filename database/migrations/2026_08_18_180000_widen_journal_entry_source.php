<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توسيع `journal_entries.source` من 20 إلى 40 حرفًا.
 *
 * `import_shipment_close` واحدٌ وعشرون حرفًا — حرفٌ واحد فوق السعة. وMySQL في
 * الوضع الصارم يرفض الإدخال (1406 Data too long) فيسقط إغلاق الشحنة بخطأ 500،
 * بينما SQLite لا يفرض أطوال varchar أصلًا فمرّت الاختبارات كلها خضراء. السعة
 * القديمة كانت ضيّقة على أسماء المصادر لا على الخطأ وحده: `sales_return_cogs`
 * سبعة عشر، و`purchase_invoice_fx` تسعة عشر — أي أن التالي كان سيقع كذلك.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('source', 40)->default('manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->change();
        });
    }
};
