<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جعل هاتف العميل اختياريًا (Phase 3.5 / ADR-036): عملاء الدخول الاجتماعي لا يملكون
 * هاتفًا وقت التسجيل — يُكمَّل في شاشة إكمال الملف قبل الشراء. تغيير موسّع لا كاسر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('primary_phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('primary_phone')->nullable(false)->change();
        });
    }
};
