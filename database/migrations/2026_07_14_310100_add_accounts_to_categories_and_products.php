<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حسابات محاسبية اختيارية على الفئة والمنتج (تجاوز الافتراضي في account_mappings).
 * أولوية الحلّ عند الترحيل: منتج → فئة → إعدادات الترحيل الافتراضية.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'products'] as $tbl) {
            Schema::table($tbl, function (Blueprint $table) {
                $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->foreignId('inventory_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->foreignId('cogs_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['categories', 'products'] as $tbl) {
            Schema::table($tbl, function (Blueprint $table) {
                $table->dropConstrainedForeignId('revenue_account_id');
                $table->dropConstrainedForeignId('inventory_account_id');
                $table->dropConstrainedForeignId('cogs_account_id');
            });
        }
    }
};
