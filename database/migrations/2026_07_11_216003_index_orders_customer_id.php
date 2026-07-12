<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// فهرس عمود orders.customer_id (موجود مؤجّلًا منذ 2.6) لاستعلامات سجلّ طلبات العميل.
// يبقى بلا FK صارم (اتساقًا مع تأجيل 2.6 وسلامة اللقطة التاريخية) — الربط عبر الخدمة.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
        });
    }
};
