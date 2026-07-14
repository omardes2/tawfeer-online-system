<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قيدا المبيعة المحاسبيان (يُنشآن عند تأكيد الطلب): الإيراد والتكلفة. وجودهما يمنع
 * الترحيل المزدوج (idempotency).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('revenue_entry_id')->nullable()->after('return_received_at')->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('cogs_entry_id')->nullable()->after('revenue_entry_id')->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revenue_entry_id');
            $table->dropConstrainedForeignId('cogs_entry_id');
        });
    }
};
