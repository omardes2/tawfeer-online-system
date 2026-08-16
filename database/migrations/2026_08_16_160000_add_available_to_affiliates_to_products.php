<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إتاحة الصنف للمسوّقين — مفتاحٌ مستقلّ عن الظهور على الموقع.
 *
 * الظهور على الموقع يخصّ الزبون، وهذا يخصّ المسوّق: صنفٌ يُباع في المتجر وعلى
 * يد موظفي المبيعات وقد لا تريد للمسوّق أن يبيعه جملةً (هامشه لا يحتمل، أو
 * حصريٌّ لقناة أخرى). خلطهما في مفتاحٍ واحد يجعل منع المسوّق يُخفي الصنف عن
 * الزبائن أيضًا.
 *
 * الافتراض `true`: الكتالوج القائم كلّه متاح كما كان قبل هذا الحقل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('available_to_affiliates')->default(true)->after('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('available_to_affiliates'));
    }
};
