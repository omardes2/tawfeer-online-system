<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التسوية المالية للطلب (ADR-038 / المتطلّب 4). يُختم `settled_at` **فقط** عند إغلاق
 * التوصيل القانوني (CLOSE) — نقطة اكتمال المال الوحيدة التي تُفعّل استحقاق العمولات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('settled_at')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('settled_at');
        });
    }
};
