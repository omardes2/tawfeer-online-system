<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قبول الشروط والخصوصية (Phase 3.5 / ADR-036) — إضافي فقط. يُملأ عند التسجيل العادي
 * (خانة مطلوبة) وعند التسجيل الاجتماعي (قبول ضمني بالضغط على «المتابعة عبر…»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('terms_accepted_at');
        });
    }
};
