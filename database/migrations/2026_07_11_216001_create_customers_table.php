<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// العملاء (ADR-030، BR-CUST). كيان مهم: uuid + soft-delete + auditable.
// حقول جاهزة للربط المستقبلي (نقاط ولاء/حدّ ائتمان) دون إعادة تصميم.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // BR-CUST-02
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 180);
            $table->string('email', 180)->nullable();
            $table->string('primary_phone', 40); // مُطبَّع — كشف التكرار (BR-CUST-03)
            $table->string('source', 20)->default('manual'); // manual/web/marketer/pos (BR-CUST-01)
            $table->string('category', 30)->nullable(); // retail/wholesale/vip (BR-CUST-09)
            $table->json('tags')->nullable(); // وسوم حرّة (BR-CUST-09)
            $table->decimal('credit_limit', 15, 2)->default(0); // جاهز للربط المستقبلي
            $table->integer('loyalty_points')->default(0);       // جاهز للربط المستقبلي
            $table->boolean('is_high_risk')->default(false); // BR-CUST-10
            $table->boolean('is_blocked')->default(false);   // BR-CUST-12
            $table->string('blocked_reason', 255)->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->unsignedInteger('cancelled_orders_count')->default(0); // BR-CUST-08
            $table->unsignedInteger('returns_count')->default(0);
            $table->foreignId('merged_into_id')->nullable()->constrained('customers')->nullOnDelete(); // BR-CUST-14
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes(); // BR-CUST-13

            $table->index('primary_phone');
            $table->index('is_blocked');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
