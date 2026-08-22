<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ استدعاء الأدوات — **append-only** كسجلّ الاستدعاءات.
 *
 * وهو ما يجعل المبدأ الأول قابلًا للإثبات: «لا يذكر الوكيل سعرًا إلّا نتيجةَ
 * أداة». وبلا هذا الجدول تبقى القاعدة وعدًا لا يمكن التحقّق منه — فإن قال
 * الوكيل رقمًا خاطئًا لم يُعرَف أجاء من أداةٍ أم اخترعه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_run_id')->constrained('agent_runs')->cascadeOnDelete();
            $table->string('tool_name', 60);
            $table->json('arguments')->nullable();
            $table->json('result')->nullable();
            $table->string('status', 20)->default('ok');   // ok | error
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['agent_run_id', 'created_at']);
            $table->index('tool_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_calls');
    }
};
