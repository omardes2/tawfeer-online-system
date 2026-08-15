<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الشحنة/الكونتينر — وعاءُ التكلفة الذي يجمع فاتورة البضاعة وفواتير المصاريف
 * التي تصل بعدها بأشهر، فيُعرف أيّ رصيدٍ في «مصاريف استيراد مستحقة» يخصّ أيّ شحنة.
 *
 * الاسم `import_shipments` لا `shipments`: الأخير لشحنات التوصيل للعملاء.
 *
 * الإغلاق إجراءٌ يدوي: يُقفل ما تبقّى من التقدير في حساب فروق التقدير، ويُحفظ
 * رقمُ القيد ليمكن عكسُه إن أُغلقت الشحنة قبل أوانها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 40)->unique();               // CNTR-{YYYY}-{seq}
            $table->string('reference', 80)->nullable();          // رقم الكونتينر لدى الخط الملاحي
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('status', 16)->default('open');        // open|closed
            $table->date('shipped_at')->nullable();
            $table->date('arrived_at')->nullable();
            $table->decimal('variance_amount', 15, 2)->default(0); // فرق التقدير المُقفَل
            $table->foreignId('variance_entry_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('supplier_id');
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->foreignId('import_shipment_id')->nullable()->after('goods_receipt_id')
                ->constrained('import_shipments')->nullOnDelete();
            // goods = فاتورة بضاعة (تُدخل مخزونًا)، expenses = فاتورة مصاريف شحنة
            // (تُطفئ الحساب الوسيط ولا تمسّ المخزون).
            $table->string('kind', 16)->default('goods')->after('status');

            $table->index('import_shipment_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropForeign(['import_shipment_id']);
            $table->dropColumn(['import_shipment_id', 'kind']);
        });

        Schema::dropIfExists('import_shipments');
    }
};
