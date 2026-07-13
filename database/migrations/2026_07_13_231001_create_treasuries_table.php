<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الخزائن والبنوك (Phase 7.1). كل خزينة/حساب بنكي مرتبط بحساب GL مخصّص (فريد) —
 * الرصيد يُشتقّ من القيود المُرحّلة على ذلك الحساب (لا رصيد مخزَّن). type: cash|bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasuries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('type', 10)->default('cash'); // cash | bank
            $table->foreignId('gl_account_id')->nullable()->unique()->constrained('accounts')->nullOnDelete();
            $table->string('currency', 3)->default('SAR');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            // حقول بنكية (type=bank).
            $table->string('bank_name')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('iban')->nullable();
            $table->string('swift', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasuries');
    }
};
