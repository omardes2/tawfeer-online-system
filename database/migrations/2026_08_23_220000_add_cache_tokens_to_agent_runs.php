<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * توكنز التخزين المؤقّت في سجلّ الدورات.
 *
 * التخزين يقسم المُدخَل ثلاثة أقسام بأسعارٍ مختلفة: جديدٌ بالسعر الكامل، وكتابةٌ
 * في المخزن بنحو ١٫٢٥ ضعفًا، وقراءةٌ منه بنحو العُشر. وعمود `input_tokens` بعد
 * تفعيل التخزين **لا يشمل المخزَّن** — فبلا هذين العمودين تصير التكلفة المسجَّلة
 * أقلّ من الحقيقية، وهو أسوأ من عدم تسجيلها: رقمٌ يُطمئن وهو خاطئ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->unsignedInteger('cache_write_tokens')->default(0)->after('input_tokens');
            $table->unsignedInteger('cache_read_tokens')->default(0)->after('cache_write_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn(['cache_write_tokens', 'cache_read_tokens']);
        });
    }
};
