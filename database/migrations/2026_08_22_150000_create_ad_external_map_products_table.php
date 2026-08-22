<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ربط مجموعةٍ إعلانية بعدّة أصناف.
 *
 * `ad_external_maps.product_id` يربط المجموعة بصنفٍ واحد، والواقع في مدير
 * إعلانات ميتا أن المجموعة الواحدة تُعلن أحيانًا عن ثلاثة أصناف بميزانيةٍ
 * واحدة. فكانت تُربَط بأحدها فيُحمَّل صنفٌ واحد إنفاقَ ثلاثة، أو تُترك بلا ربط
 * فيسقط إنفاقُها من الحساب كلّه.
 *
 * والعمود القديم **يبقى ولا يُحذف**: هو الحالة الشائعة (مجموعة لصنف)، وسحبُه
 * يكسر المزامنة القائمة بلا مقابل. فيُقرأ الجدولان معًا — الجدول أوّلًا، ثم
 * العمود.
 *
 * ويُملأ الجدول من العمود للمربوط سابقًا، كي يقرأ المسار الجديد كلَّ الربط من
 * موضعٍ واحد بلا فرعٍ في الكود لكل حالة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_external_map_products')) {
            return;
        }

        Schema::create('ad_external_map_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_external_map_id')->constrained('ad_external_maps')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ad_external_map_id', 'product_id'], 'ad_map_products_unique');
        });

        // نقل الربط القائم — لا يُفقد شيء.
        DB::table('ad_external_maps')
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->chunkById(200, function ($maps) {
                DB::table('ad_external_map_products')->insertOrIgnore(
                    collect($maps)->map(fn ($m) => [
                        'ad_external_map_id' => $m->id,
                        'product_id' => $m->product_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all(),
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_external_map_products');
    }
};
