<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إعدادات الترحيل المحاسبي (Account Mapping / Posting Setup): لكل نوع مستند ووظيفة
 * محاسبية (revenue/cogs/inventory/receivable/tax/cash) → حساب من دليل الحسابات.
 * تحلّ محلّ الربط الثابت في config، وقابلة للتعديل من اللوحة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 40);   // sales_invoice | purchase_invoice ...
            $table->string('function', 40);         // revenue | cogs | inventory | receivable | tax | cash | shipping_revenue
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['document_type', 'function']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_mappings');
    }
};
