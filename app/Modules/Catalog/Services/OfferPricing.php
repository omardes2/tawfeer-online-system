<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\ProductOffer;
use Illuminate\Support\Collection;

/**
 * تسعير عروض الكمّية.
 *
 * ثلاثة قرارات تحكم هذا الملف:
 *
 * 1. **الكمّية تُجمع على مستوى الصنف لا المتغيّر.** الزبون يأخذ خمس قطعٍ
 *    بمقاساتٍ مختلفة ويعدّها عرضًا واحدًا. والجمع على المتغيّر كان سيمنع العرض
 *    عن أكثر الحالات شيوعًا بلا أن يفهم أحدٌ لماذا.
 *
 * 2. **أعلى عرضٍ تبلغه الكمّية، ويطال كل القطع.** من اشترى ستًّا وعرضُك «خمس
 *    بمئة» يأخذ سعر العرض للستّ كلّها. والبديل — خمسٌ بسعر العرض وواحدةٌ
 *    بالسعر العادي — أدقّ حسابيًّا وأسوأ عمليًّا: يرى الزبون سطرين بسعرين
 *    لصنفٍ واحد فيظنّها غلطة.
 *
 * 3. **الحساب من السعر الإجمالي لا من سعر القطعة.** «ثلاث بمئة» تعطي 33.33
 *    للقطعة، وضربُها في ثلاثة يعطي 99.99 — فيفترق ما يدفعه الزبون عمّا أُعلن
 *    له. سعر القطعة للعرض، والفرق يُحمَّل على القطعة الأخيرة.
 */
class OfferPricing
{
    /**
     * أعلى عرضٍ تبلغه هذه الكمّية.
     *
     * @param  Collection<int, ProductOffer>  $offers
     */
    public function bestFor(Collection $offers, float $qty): ?ProductOffer
    {
        return $offers
            ->filter(fn (ProductOffer $o) => $o->is_active && $o->min_qty > 0 && $qty >= $o->min_qty)
            ->sortByDesc('min_qty')
            ->first();
    }

    /**
     * سعر القطعة بعد تطبيق العرض على كمّيةٍ من صنف.
     *
     * القسمة على الكمّية **الفعلية** لا على `min_qty`: من اشترى ستًّا وعرضُه
     * «خمس بمئة» يدفع مئةً على ستّ قطع — وهذا ما يعنيه القرار 2 أعلاه، وأيّ
     * حسابٍ آخر يجعل السادسة مجّانية أو يجعل المجموع يتجاوز المئة.
     */
    public function unitPrice(Collection $offers, float $qty, float $regularUnitPrice): float
    {
        $offer = $this->bestFor($offers, $qty);

        if (! $offer || $qty <= 0) {
            return $regularUnitPrice;
        }

        $offerUnit = round((float) $offer->total_price / $qty, 2);

        // لا يرفع العرضُ السعر أبدًا: صاحب المتجر قد يترك عرضًا قديمًا على صنفٍ
        // خُفِّض سعره، والزبون لا يجوز أن يدفع أكثر لأنه اشترى أكثر.
        return min($offerUnit, $regularUnitPrice);
    }

    /**
     * توزيع السعر الإجمالي على القطع بلا كسورٍ ضائعة.
     *
     * تُقرَّب كل قطعةٍ إلى قرشين، ويُحمَّل الفرق المتبقّي على الأخيرة — فمجموع
     * البنود يساوي المُعلَن بالضبط. وبدونه يدفع الزبون 99.99 على عرضِ مئة،
     * وهو فرقٌ لا يهمّ حسابيًّا ويهمّ حين يقرأه على الفاتورة.
     *
     * @return array<int, float>
     */
    public function split(float $total, int $units): array
    {
        if ($units < 1) {
            return [];
        }

        $each = floor($total / $units * 100) / 100;
        $lines = array_fill(0, $units, $each);
        $lines[$units - 1] = round($total - $each * ($units - 1), 2);

        return $lines;
    }
}
