<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * البيع المُساعد (Phase 4.1 / ADR-037) — إضافي فقط: إسناد المسوّق على الطلب، ولقطات
 * السعر/التكلفة على بنود الطلب (المتطلّب 2). لا إعادة تصميم لمخطط 2.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // المسوّق المُحيل (كيان المحفظة/الدفتر يأتي في 4.2). assigned_to = موظف المبيعات.
            $table->foreignId('affiliate_id')->nullable()->after('assigned_to')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('retail_price_snapshot', 15, 2)->nullable()->after('unit_price');
            $table->decimal('wholesale_cost_snapshot', 15, 2)->nullable()->after('retail_price_snapshot');
            $table->string('price_change_reason', 255)->nullable()->after('wholesale_cost_snapshot');
            $table->foreignId('price_approved_by')->nullable()->after('price_change_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_approved_by');
            $table->dropColumn(['retail_price_snapshot', 'wholesale_cost_snapshot', 'price_change_reason']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('affiliate_id');
        });
    }
};
