<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شحنات مرتبطة للمرتجع/الاستبدال (Phase 4.4 / ADR-040) — دون تعديل الشحنة الأصلية.
 * `kind` = outbound (افتراضي) | return_pickup | exchange_delivery؛ `parent_shipment_id` يربطها بالأصل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('kind', 20)->default('outbound')->after('status')->index();
            $table->foreignId('parent_shipment_id')->nullable()->after('order_id')->constrained('shipments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_shipment_id');
            $table->dropColumn('kind');
        });
    }
};
