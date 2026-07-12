<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// حالة الدفع على الطلب (ADR-028) — مشتقّة من المدفوعات الناجحة. مفردات payment_statuses المجمّدة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_status', 20)->default('unpaid')->after('status'); // unpaid/partially_paid/paid/refunded
            $table->decimal('amount_paid', 15, 2)->default(0)->after('shipping_total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'amount_paid']);
        });
    }
};
