<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تصنيفات المصروفات — الاسم الذي يفهمه المستخدم فوق حسابٍ في الدليل.
 *
 * كان اختيار المصروف يقع مباشرةً على حسابات الدليل، وليس في اللوحة مسارٌ
 * لإنشاء حساب أصلًا: فمن أراد «عمال تنزيل» لم يجد له مكانًا، ومن اضطُرّ رماه
 * على أقرب حسابٍ موجود فاختلط بمصروفٍ آخر ولم يعد يُفرَز.
 *
 * `account_id` يبقى مصدر الحقيقة المحاسبي — التصنيف اسمٌ وواجهة، والقيدُ
 * يُرحَّل على الحساب كما كان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('name_en', 120)->nullable();
            // الحساب الطرفي المقابل تحت «مصاريف تشغيلية». فريدٌ: تصنيفان على
            // حسابٍ واحد يعنيان رقمين في التقرير لا يجتمعان أبدًا.
            $table->foreignId('account_id')->unique()->constrained('accounts')->restrictOnDelete();
            // تصنيفات النظام (الشحن، عمولات المسوّقين) مربوطة بحسابات يُرحّل
            // عليها النظام آليًا: تُستخدم ولا تُحذف ولا يُغيَّر حسابها.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
