<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ختم زمني لتأكيد استلام المرتجع في المستودع (حالة أوبتيموس «مرتجع مع السائق»).
 * وجوده يمنع إعادة الكمية للمخزون أكثر من مرّة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('return_received_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('return_received_at');
        });
    }
};
