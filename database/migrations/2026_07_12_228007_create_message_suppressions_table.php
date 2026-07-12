<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قائمة الحجب (opt-out صريح) لرسائل التسويق (Phase 6 / ADR-046).
 * القبول (opt-in) من `customers.communication_preferences` القائم؛ هنا الحجب الصريح.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->string('contact', 190)->nullable(); // هاتف/بريد (للضيوف)
            $table->string('channel', 20)->nullable();   // null = كل القنوات
            $table->string('reason', 200)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_suppressions');
    }
};
