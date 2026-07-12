<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ انتقالات الحالة القانونية (append-only، ADR-038 / المتطلّب 1).
 * يحفظ لكل انتقال: الحالة السابقة، الجديدة، الوقت، الفاعل (user/system/provider)،
 * السبب المصنّف، وملاحظة. لا تعديل ولا حذف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->string('actor_type', 20)->default('user'); // user | system | provider
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code', 40)->nullable();       // سبب مصنّف (عند on_hold)
            $table->string('note', 500)->nullable();
            $table->string('provider_status', 60)->nullable();   // حالة المزوّد الخام وقت الانتقال (توثيق)
            $table->timestamp('created_at')->nullable();

            $table->index(['shipment_id', 'to_status']);
            $table->index(['to_status', 'reason_code']); // تقارير أسباب التعليق
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_status_transitions');
    }
};
