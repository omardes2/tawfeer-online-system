<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تقييمات المنتجات وآراء الزبائن.
 *
 * لا يُكتب التقييم إلا ممّن استلم المنتج فعلًا، ولا يُعرض إلا بعد موافقة إدارية
 * — لذلك `order_id` مطلوب و`status` يبدأ `pending`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // الطلب الذي يثبت الشراء. يبقى التقييم إن حُذف الطلب لاحقًا.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedTinyInteger('rating');           // 1..5
            $table->string('title')->nullable();
            $table->text('body')->nullable();

            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('moderation_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // رأي واحد لكل زبون في كل منتج — لا تكرار يرفع المعدّل صناعيًّا.
            $table->unique(['product_id', 'customer_id']);
            // استعلام صفحة المنتج: المعتمَد من هذا المنتج، الأحدث أولًا.
            $table->index(['product_id', 'status', 'created_at']);
            // شاشة المراجعة: المعلّق أولًا.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
