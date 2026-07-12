<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جرد المخزون الفعلي — Stock-take (Phase 5 / ADR-043). يُنتج تسويات عبر محرّك المخزون القائم
 * (لا يمسّ الأرصدة مباشرة). لا حذف — تسويات موثّقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 30)->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            // draft → counting → review → completed (+ cancelled)
            $table->string('status', 20)->default('counting')->index();
            $table->string('scope', 12)->default('full'); // full | partial
            $table->string('notes', 1000)->nullable();
            $table->unsignedInteger('adjusted_count')->default(0); // بنود طُبّقت تسويتها
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_counts');
    }
};
