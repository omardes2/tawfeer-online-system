<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التصنيف على السند.
 *
 * `counter_account_id` يبقى كما هو — هو ما يُرحَّل عليه القيد. والتصنيف يُحفظ
 * إلى جانبه لأن الحساب وحده لا يكفي للتجميع: حسابٌ قد يُعاد تسميته، وتصنيفان
 * قد يُدمجان لاحقًا، فيبقى السند شاهدًا على ما اختاره المستخدم يومَه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_vouchers', function (Blueprint $table) {
            $table->foreignId('expense_category_id')->nullable()->after('counter_account_id')
                ->constrained('expense_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_category_id');
        });
    }
};
