<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قائمة أسعار المستخدم.
 *
 * فارغةٌ للجميع عند الترقية، فلا يتغيّر سعرُ أحدٍ حتى تُسنَد قائمةٌ بيدٍ صريحة —
 * وهذا شرطُ إدخال طبقة سعرٍ جديدة على نظامٍ يبيع فعلًا.
 *
 * `nullOnDelete`: حذف القائمة يُعيد صاحبها إلى سعر الجملة، ولا يمنع الحذف ولا
 * يترك مفتاحًا معلّقًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('price_list_id')->nullable()->after('delivery_business_id')
                ->constrained('price_lists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_list_id');
        });
    }
};
