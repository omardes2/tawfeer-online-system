<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// المنتجات (PHASE_2_DESIGN §16 + توسعة ADR-023).
// tax_id عمود مؤجّل الـFK حتى إنشاء جدول taxes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // العلاقات (branch/category/brand/unit موجودة؛ tax مؤجّل)
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('tax_id')->nullable(); // FK مؤجّل (taxes)

            // الهوية (ثنائي اللغة — ADR-023)
            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('slug', 220)->unique();
            $table->string('sku', 60)->unique();
            $table->string('barcode', 60)->nullable();
            $table->string('type', 20)->default('simple'); // simple/variable

            // الأوصاف (ثنائي اللغة — ADR-023)
            $table->string('short_description', 500)->nullable();
            $table->string('short_description_en', 500)->nullable();
            $table->text('description')->nullable();
            $table->text('description_en')->nullable();

            // الأسعار (§16 — موجودة للوفاء بالتصميم؛ لا محرّك تسعير في 2.3 — ADR-023)
            $table->decimal('cost_price', 15, 4)->default(0);
            $table->decimal('average_cost', 15, 4)->default(0);
            $table->decimal('retail_price', 15, 2)->default(0);
            $table->decimal('wholesale_price', 15, 2)->nullable();
            $table->decimal('marketer_price', 15, 2)->nullable();
            $table->decimal('min_price', 15, 2)->nullable();
            $table->decimal('promo_price', 15, 2)->nullable();
            $table->timestamp('promo_starts_at')->nullable();
            $table->timestamp('promo_ends_at')->nullable();
            $table->decimal('target_margin', 8, 4)->nullable();

            // خصائص فيزيائية/مخزون (§16)
            $table->decimal('weight', 15, 3)->nullable();
            $table->decimal('reorder_level', 15, 3)->nullable();
            $table->boolean('track_inventory')->default(true);

            // الحالة والظهور (ADR-023)
            $table->string('status', 20)->default('draft'); // draft/active/archived
            $table->string('visibility', 20)->default('visible'); // visible/hidden
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false); // مُشتقّ من status (ADR-023)

            // SEO + بحث (ADR-023)
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('meta_keywords', 255)->nullable();
            $table->text('search_keywords')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('category_id');
            $table->index('brand_id');
            $table->index('barcode');
            $table->index(['is_active', 'type']);
            $table->index(['status', 'visibility']);
            $table->index('is_featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
