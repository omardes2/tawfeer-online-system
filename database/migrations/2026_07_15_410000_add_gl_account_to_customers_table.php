<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط كل عميل بحساب فرعي في دليل الحسابات تحت «ذمم العملاء».
 * قيود مبيعات العميل (ذمم) تُرحَّل على حسابه الفرعي لا الحساب العام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('gl_account_id')->nullable()->after('preferred_branch_id')
                ->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gl_account_id');
        });
    }
};
