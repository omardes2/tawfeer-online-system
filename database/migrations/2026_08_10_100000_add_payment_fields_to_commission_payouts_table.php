<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// دفع أرباح المسوّقين/الموظفين بمبلغ حرّ من بنك/خزينة محدّدة، موثّق بسند صرف مالي (ADR-012e).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_payouts', function (Blueprint $table) {
            // البنك/الخزينة التي يُسحب منها المبلغ.
            $table->foreignId('treasury_id')->nullable()->after('earner_type')->constrained('treasuries')->nullOnDelete();
            // سند الصرف المالي المرتبط (مصدر الحقيقة للحالة المحاسبية: draft/posted/...).
            $table->foreignId('financial_voucher_id')->nullable()->after('treasury_id')->constrained('financial_vouchers')->nullOnDelete();
            // الفترة التي تغطّيها الدفعة (للأرشيف/التقارير).
            $table->date('period_start')->nullable()->after('reference');
            $table->date('period_end')->nullable()->after('period_start');
        });
    }

    public function down(): void
    {
        Schema::table('commission_payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('financial_voucher_id');
            $table->dropConstrainedForeignId('treasury_id');
            $table->dropColumn(['period_start', 'period_end']);
        });
    }
};
