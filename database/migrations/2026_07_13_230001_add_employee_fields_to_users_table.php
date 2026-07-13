<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حقول الموظّف على المستخدم (إدارة المستخدمين — Production).
 * إضافية: القسم والمسمّى الوظيفي. الأدوار عبر spatie (لا تُخزَّن هنا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('department')->nullable()->after('phone');
            $table->string('job_title')->nullable()->after('department');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['department', 'job_title']);
        });
    }
};
