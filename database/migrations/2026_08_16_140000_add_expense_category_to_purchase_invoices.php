<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تصنيف فاتورة المصاريف: شحن بحري · تخليص وجمارك · عمولة · نقل داخلي · أخرى.
 *
 * كان `kind` يفرّق بين البضاعة والمصاريف فقط، فتتشابه فواتير الشحنة الواحدة في
 * القائمة ولا يُعرف ما تخصّه إلا بفتحها. والتصنيف حقلٌ مستقلّ لا اشتقاقٌ من نصّ
 * الوصف: الوصف يُكتب بألف صيغة («تخليص»، «جمارك»، «رسوم جمركية») فلا يصلح
 * أساسًا لتجميعٍ ولا لفلترة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->string('expense_category', 30)->nullable()->after('kind');
        });

        // فواتير المصاريف القائمة تُصنَّف «أخرى» بدل تخمين النوع من وصفها —
        // والتصنيف قابل للتعديل من صفحة الفاتورة بلا مساس بمبالغها.
        DB::table('purchase_invoices')
            ->where('kind', 'expenses')
            ->whereNull('expense_category')
            ->update(['expense_category' => 'other']);
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', fn (Blueprint $table) => $table->dropColumn('expense_category'));
    }
};
