<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * السندات المالية الموحّدة (Phase 7.1): قبض/صرف/مصروف/إيراد آخر/تحويل.
 * كل ترحيل يُنشئ قيدًا متوازنًا عبر AccountingService (journal_entry_id). المُرحّل غير قابل
 * للحذف — التصحيح بالعكس (reversal_entry_id). idempotency_key يمنع الترحيل المكرّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_vouchers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number')->unique();
            $table->string('kind', 12);   // receipt | payment | expense | income | transfer
            $table->string('status', 16)->default('draft'); // draft|approved|posted|cancelled|reversed|rejected
            $table->date('voucher_date');

            // الطرف الخزيني (صندوق/بنك) والطرف المقابل.
            $table->foreignId('treasury_id')->constrained('treasuries')->restrictOnDelete();
            $table->foreignId('counter_treasury_id')->nullable()->constrained('treasuries')->restrictOnDelete(); // للتحويل (الوجهة)
            $table->foreignId('counter_account_id')->nullable()->constrained('accounts')->nullOnDelete();        // GL المقابل (إيراد/مصروف/ذمم…)

            // أطراف اختيارية.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('party_name')->nullable(); // "استلمنا من" / "دفعنا إلى"

            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('SAR');
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->string('category')->nullable();      // فئة المصروف/الإيراد
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->string('description')->nullable();
            $table->text('notes')->nullable();
            $table->json('attachments')->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->json('recurrence')->nullable();

            $table->string('idempotency_key')->nullable()->unique();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['kind', 'status', 'voucher_date']);
            $table->index(['treasury_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_vouchers');
    }
};
