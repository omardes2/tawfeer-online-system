<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعات صرف العمولة/الأرباح (Phase 4.2 / ADR-037) — دفعة صرف مجمّعة لمستفيد. مرجع
 * الصرف وربط محاسبي (يُملأ في 4.6). تُصرف حصريًا العمولات المعتمدة (منع الدفع المزدوج).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_payouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('earner_id')->constrained('users')->cascadeOnDelete();
            $table->string('earner_type', 20);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('reference', 80)->nullable();
            $table->string('status', 20)->default('paid'); // paid (تُنشأ مدفوعة)
            $table->foreignId('accounting_entry_id')->nullable(); // ربط 4.6 (بلا FK صارم الآن)
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['earner_id', 'earner_type']);
        });

        Schema::create('commission_payout_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_payout_id')->constrained('commission_payouts')->cascadeOnDelete();
            $table->foreignId('commission_entry_id')->constrained('commission_entries')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(
                ['commission_payout_id', 'commission_entry_id'],
                'cp_payout_entry_unique'
            );
            // بند العمولة يُدفَع مرّة واحدة فقط (منع الدفع المزدوج).
            $table->unique('commission_entry_id', 'uniq_entry_paid_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payout_entries');
        Schema::dropIfExists('commission_payouts');
    }
};
