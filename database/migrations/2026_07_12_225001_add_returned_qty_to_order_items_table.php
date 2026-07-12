<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تتبّع الكمية المرتجعة لكل بند طلب (Phase 4.4 / ADR-040) — لمنع تجاوز الإرجاع
 * والتمييز بين الإرجاع الكامل والجزئي (BR-RET-05/10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('returned_qty', 15, 3)->default(0)->after('qty_shipped');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('returned_qty');
        });
    }
};
