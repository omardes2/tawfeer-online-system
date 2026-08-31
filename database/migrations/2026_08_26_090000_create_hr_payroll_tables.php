<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الرواتب والموظفون.
 *
 * ## لماذا الموظف ملفٌّ لا مستخدم
 *
 * كل موظفٍ مستخدم، وليس كل مستخدمٍ موظفًا: المسوّق والتاجر يدخلان النظام بلا
 * راتبٍ ولا تاريخ تعيين. فحقولُ التوظيف في جدولٍ مستقلّ يُنشأ لمن يُوظَّف
 * فعلًا — لا أعمدةٌ فارغة على `users` تجعل كل حسابٍ موظفًا محتملًا.
 *
 * ## ولماذا الراتب صفوفٌ لا عمود
 *
 * الراتب يتغيّر، والكشف القديم يجب أن يبقى صحيحًا. فلو كان عمودًا واحدًا
 * لأعادت الزيادةُ كتابةَ رواتب السنة الماضية. والساري لشهرٍ ما هو **أحدثُ
 * صفٍّ تاريخُ سريانه ≤ نهاية ذلك الشهر** — وهو نفس نمط `operating_daily_costs`.
 *
 * ## والبنود لقطةٌ لا حساب
 *
 * بند الكشف يحمل الراتب والبدل والخصم أرقامًا مُجمَّدة لحظةَ التوليد، لا
 * مراجعَ تُقرأ عند العرض. الكشف المُرحَّل مستندٌ محاسبيّ: قيمتُه يجب ألّا
 * تتحرّك بعد ترحيله مهما تغيّر العقد.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ————— ملفّ الموظف —————
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // مستخدمٌ واحد = ملفٌّ واحد. الفريد يمنع ملفّين لشخصٍ فيُحتسب راتبه مرّتين.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('hire_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active');   // active | ended
            $table->string('employment_type', 20)->default('full_time');
            // المستحقّ السنويّ من الإجازة. يُخزَّن على الملفّ لأنه يختلف بالعقد.
            $table->decimal('annual_leave_days', 6, 2)->default(14);
            $table->string('national_id', 30)->nullable();
            $table->string('bank_account', 60)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        // ————— تاريخ الرواتب —————
        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->decimal('basic_salary', 15, 2);
            $table->decimal('allowances', 15, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // راتبٌ واحد لكل تاريخ سريان: الإدخال يُعاد حتى يستقرّ، فيجب أن
            // يُحدِّث لا أن يتراكم صفّين ساريين في اليوم نفسه.
            $table->unique(['employee_profile_id', 'effective_from'], 'employee_salaries_unique');
        });

        // ————— الإجازات —————
        Schema::create('employee_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                        // annual | unpaid | sick
            $table->date('from_date');
            $table->date('to_date');
            // الأيام مُخزَّنة لا محسوبة من الفرق: العطل الرسمية ونصف اليوم
            // تجعل الفرق التقويميّ خاطئًا، والمُدخِل يعرف العدد الفعليّ.
            $table->decimal('days', 6, 2);
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_profile_id', 'kind']);
            $table->index('from_date');
        });

        // ————— كشف الرواتب —————
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 40)->unique();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('status', 20)->default('draft');    // draft | posted | paid | reversed
            $table->decimal('total_earnings', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('total_net', 15, 2)->default(0);
            $table->decimal('total_eos', 15, 2)->default(0);   // مخصّص نهاية الخدمة للشهر
            // قيدان لا واحد: الرواتب مصروفٌ يقابله التزامٌ يُدفَع، ومخصّص نهاية
            // الخدمة مصروفٌ يقابله التزامٌ لا يُدفَع الآن. خلطُهما في قيدٍ واحد
            // يمنع عكس أحدهما دون الآخر.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('eos_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // كشفٌ واحد للشهر: الثاني يعني ترحيل الرواتب مرّتين.
            $table->unique(['period_year', 'period_month'], 'payroll_runs_period_unique');
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained()->restrictOnDelete();
            // لقطاتٌ مُجمَّدة: الكشف المُرحَّل مستندٌ لا يتحرّك بتغيّر العقد.
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->decimal('allowances', 15, 2)->default(0);
            $table->decimal('other_additions', 15, 2)->default(0);
            $table->decimal('unpaid_leave_days', 6, 2)->default(0);
            $table->decimal('unpaid_leave_amount', 15, 2)->default(0);
            $table->decimal('other_deductions', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->decimal('eos_provision', 15, 2)->default(0);
            // سند الصرف الذي دُفع به هذا البند — فارغٌ حتى يُدفع.
            $table->foreignId('financial_voucher_id')->nullable()->constrained('financial_vouchers')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            // بندٌ واحد للموظف في الكشف: الثاني يُضاعف راتبه.
            $table->unique(['payroll_run_id', 'employee_profile_id'], 'payroll_lines_unique');
        });

        // ————— حركات مخصّص نهاية الخدمة —————
        // دفترٌ لا عمود رصيد: الرصيد مجموعُ حركاته، فلا يُصحَّح رقمٌ محفوظ
        // ولا يفترق عن القيود التي وُلِّد منها.
        Schema::create('end_of_service_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_profile_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                        // accrual | settlement | adjustment
            $table->date('entry_date');
            // موجبٌ للتراكم وسالبٌ للتصفية — فالجمع وحده يعطي الرصيد.
            $table->decimal('amount', 15, 2);
            $table->foreignId('payroll_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('financial_voucher_id')->nullable()->constrained('financial_vouchers')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_profile_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('end_of_service_entries');
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employee_leaves');
        Schema::dropIfExists('employee_salaries');
        Schema::dropIfExists('employee_profiles');
    }
};
