<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// مراجع القيود المحاسبية لطلب الإرجاع (عكس الإيراد + عكس التكلفة) — تضمن ترحيلًا واحدًا.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->foreignId('revenue_entry_id')->nullable()->after('refund_payment_id')
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('cogs_entry_id')->nullable()->after('revenue_entry_id')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cogs_entry_id');
            $table->dropConstrainedForeignId('revenue_entry_id');
        });
    }
};
