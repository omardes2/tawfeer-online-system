<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حقول تعريف الصنف الجديد على بند فاتورة الشراء: يُحفظ الاسم/سعر البيع المقترح في المسودّة،
 * ويُنشأ المنتج فعليًا (ويدخل المخزون) عند ترحيل الفاتورة فقط — لا في المسودّة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->string('new_product_name', 180)->nullable()->after('description');
            $table->decimal('new_product_sell_price', 15, 2)->nullable()->after('new_product_name');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->dropColumn(['new_product_name', 'new_product_sell_price']);
        });
    }
};
