<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط كل مورد بحساب فرعي في دليل الحسابات تحت «ذمم الموردين».
 * القيود المحاسبية للمورد (فواتير/مدفوعات) تُرحَّل على حسابه الفرعي لا الحساب العام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('gl_account_id')->nullable()->after('currency_id')
                ->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gl_account_id');
        });
    }
};
