<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// امتدادات المرحلة 2.1 على جدول الفروع (PHASE_2_DESIGN §1).
// default_currency_id عمود مؤجّل الـFK حتى إنشاء جدول currencies (ADR-001).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('email', 150)->nullable()->after('phone');
            $table->string('tax_number', 50)->nullable()->after('email');
            // FK يُضاف لاحقًا عند إنشاء currencies — عمود فقط الآن.
            $table->unsignedBigInteger('default_currency_id')->nullable()->after('tax_number');
            $table->string('timezone', 40)->default('Asia/Riyadh')->after('default_currency_id');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['email', 'tax_number', 'default_currency_id', 'timezone']);
        });
    }
};
