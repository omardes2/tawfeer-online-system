<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فواتير الموردين/الشراء (REQUIREMENTS §2.5). تُرحَّل عبر محرّك القيد المزدوج:
 * مدين المخزون (1200) [+ ضريبة 2100] / دائن ذمم الموردين (2010). لا ترحيل مالي في
 * الاستلام (GRN) — قيمة المخزون والالتزام تُسجَّلان هنا (بلا ازدواج). عكس لا حذف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 40)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained('goods_receipts')->nullOnDelete();
            $table->string('supplier_reference', 80)->nullable(); // رقم فاتورة المورد
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('status', 16)->default('draft');          // draft|approved|posted|cancelled|reversed
            $table->string('payment_status', 16)->default('unpaid');  // unpaid|partial|paid
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->string('notes', 500)->nullable();
            $table->foreignId('journal_entry_id')->nullable();
            $table->foreignId('reversal_entry_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable();
            $table->foreignId('posted_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_id', 'status']);
            $table->index('payment_status');
        });

        Schema::create('purchase_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('description')->nullable();
            $table->decimal('qty', 15, 3)->default(1);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('tax_rate', 6, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_items');
        Schema::dropIfExists('purchase_invoices');
    }
};
