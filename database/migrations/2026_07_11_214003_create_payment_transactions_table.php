<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// سجلّ تفاعلات المزوّد (ADR-028، المتطلّب 5). بنية عامة: مراجع/حالات/callbacks/بيانات.
// append-only: بلا updated_at/soft-delete.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('kind', 20); // charge/capture/verify/refund/callback/webhook
            $table->string('status', 20);
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('provider_reference', 120)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('payment_id');
            $table->index('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
