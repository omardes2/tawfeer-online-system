<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// قيود اليومية (ADR-029/016). ترقيم JE-{YYYY}-{seq}. المُرحّل غير قابل للتعديل/الحذف (BR-ACC-09).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 30)->unique();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('accounting_periods')->nullOnDelete();
            $table->date('entry_date');
            $table->string('description', 255)->nullable();
            $table->string('source', 20)->default('manual'); // manual/system/event
            $table->string('status', 15)->default('draft');   // draft/posted
            // مرجع المصدر (polymorphic) — الطلب/الدفعة/الاستلام... (تكامل مستقبلي، المتطلّب 5).
            $table->nullableMorphs('reference');
            // العكس لا الحذف (BR-ACC-09): القيد العاكس يشير إلى الأصل؛ حالة "معكوس" تُشتقّ.
            $table->foreignId('reverses_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('entry_date');
            $table->index('number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
