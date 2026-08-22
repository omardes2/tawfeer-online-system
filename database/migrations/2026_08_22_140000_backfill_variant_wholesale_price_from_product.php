<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تعبئة سعر جملة المتغيّر من المنتج حيث هو فارغ.
 *
 * شاشة تعديل المنتج فيها حقلُ جملةٍ واحد على مستوى المنتج، وكان النظام ينسخه
 * إلى **المتغيّر الافتراضي وحده**. فكل منتجٍ بمقاسات أو ألوان وُلدت متغيّراته
 * بعمودٍ فارغ، ولا سبيل في الشاشة لتعبئتها.
 *
 * والاحتياط في الكود (`effectiveWholesalePrice`) يُصلح القراءة، لكنه لا يُصلح
 * ما يقرأ العمود مباشرةً: تقاريرُ باستعلامٍ خام، وتصديرٌ إلى ملف، واستيرادٌ
 * يقارن بالقائم. فيُملأ العمود مرّةً واحدة ليستقيم المصدران.
 *
 * **لا يُلمس متغيّرٌ له سعرُه**: الشرط `IS NULL` وحده. متغيّرٌ سُعِّر بيدٍ
 * صريحة (مقاسٌ أكبر أغلى) لا يجوز أن تسحقه تعبئةٌ جماعية.
 *
 * ولا `down` يُفرغ العمود: لا سبيل للتمييز بعدها بين ما ملأته الهجرة وما ملأه
 * إنسان، وإفراغُ الاثنين يمحو عملًا يدويًّا لا يُستعاد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_variants') || ! Schema::hasTable('products')) {
            return;
        }

        // تحديثٌ بجملةٍ واحدة عبر استعلامٍ فرعي: يعمل على MySQL وSQLite سواء،
        // بخلاف `UPDATE ... JOIN` الذي يختلف تركيبه بينهما.
        DB::table('product_variants')
            ->whereNull('wholesale_price')
            ->whereIn('product_id', function ($query) {
                $query->select('id')->from('products')->where('wholesale_price', '>', 0);
            })
            ->update([
                'wholesale_price' => DB::raw(
                    '(select wholesale_price from products where products.id = product_variants.product_id)'
                ),
            ]);
    }

    public function down(): void
    {
        // مقصود: انظر شرح الصنف أعلاه.
    }
};
