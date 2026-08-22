<?php

namespace App\Modules\Catalog\Services;

use App\Models\User;
use App\Modules\Catalog\Models\PriceList;
use App\Modules\Catalog\Models\PriceListItem;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * حسم سعر شراء المشتري.
 *
 * الترتيب: **سعر قائمته ← سعر القائمة الأب ← سعر الجملة ← التكلفة**. ومن لا
 * قائمة له يبقى على سعر الجملة تمامًا كما كان — إدخال طبقة سعرٍ على نظامٍ يبيع
 * فعلًا يجب ألّا يحرّك سعر أحدٍ حتى تُسنَد له قائمةٌ بيدٍ صريحة.
 *
 * وهذا السعر هو ما يُجمَّد في `wholesale_price_snapshot` على بند الطلب، فيجري
 * حساب الربح والعمولة بعده بلا تغيير: هامش التاجر = سعر البيع − سعر قائمته،
 * كما كان هامش المسوّق = سعر البيع − سعر الجملة.
 */
class PriceListService
{
    /**
     * أسعار قائمة المستخدم لمجموعة متغيّرات — استعلامٌ واحد لا استعلامٌ لكل بند.
     *
     * @param  array<int, int>  $variantIds
     * @return Collection<int, float> [variant_id => price]
     */
    public function pricesFor(?User $user, array $variantIds): Collection
    {
        $list = $this->listFor($user);
        if ($list === null || $variantIds === []) {
            return collect();
        }

        return $this->pricesForList($list, $variantIds);
    }

    /**
     * الأسعار الفعّالة لقائمةٍ بعد الوراثة.
     *
     * الأقرب يفوز: تُقرأ السلسلة من الابن صعودًا، وأوّل سعرٍ يُوجَد للمتغيّر هو
     * المعتمَد. ولذلك تكفي قائمةَ التاجر الخاصّة أصنافُه المختلفة وحدها.
     *
     * @param  array<int, int>  $variantIds
     * @return Collection<int, float>
     */
    public function pricesForList(PriceList $list, array $variantIds): Collection
    {
        $chain = $list->ancestryIds();
        if ($chain === [] || $variantIds === []) {
            return collect();
        }

        $rows = PriceListItem::query()
            ->whereIn('price_list_id', $chain)
            ->whereIn('variant_id', $variantIds)
            ->get(['price_list_id', 'variant_id', 'price']);

        $rank = array_flip($chain); // 0 = القائمة نفسها، فالأعلى ترتيبًا يفوز.
        $best = [];

        foreach ($rows as $row) {
            $depth = $rank[$row->price_list_id] ?? PHP_INT_MAX;
            $variantId = (int) $row->variant_id;

            if (! isset($best[$variantId]) || $depth < $best[$variantId]['depth']) {
                $best[$variantId] = ['depth' => $depth, 'price' => (float) $row->price];
            }
        }

        return collect($best)->map(fn (array $r) => $r['price']);
    }

    /**
     * سعر شراء متغيّرٍ واحد لمشترٍ، مع الاحتياط إلى الجملة ثم التكلفة.
     *
     * يُرجع صفرًا حين لا سعر جملة ولا تكلفة — والصفر هنا يعني «لا قيد» تمامًا
     * كما يفهمه حارس أقلّ سعرٍ اليوم.
     */
    public function buyPrice(?User $user, ProductVariant $variant): float
    {
        $listPrice = $this->pricesFor($user, [$variant->id])->get($variant->id);

        if ($listPrice !== null) {
            return (float) $listPrice;
        }

        $wholesale = $variant->effectiveWholesalePrice();

        return $wholesale > 0 ? $wholesale : (float) ($variant->average_cost ?? 0);
    }

    /** قائمة المستخدم إن كانت مفعّلة — القائمة المعطَّلة كأنها غير موجودة. */
    public function listFor(?User $user): ?PriceList
    {
        if ($user?->price_list_id === null) {
            return null;
        }

        $list = PriceList::with('parent')->find($user->price_list_id);

        return $list && $list->is_active ? $list : null;
    }

    /**
     * منع الدوران في شجرة القوائم.
     *
     * قائمةٌ صارت أبًا لنفسها — مباشرةً أو عبر حلقة — تُدخل حسم السعر في دورانٍ
     * لا نهاية له عند كل طلب. الحارس هنا وقتَ الحفظ، والحارس الثاني في
     * `ancestryIds` لبياناتٍ فسدت قبل هذا الحارس.
     */
    public function assertNoCycle(PriceList $list, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $list->id) {
            throw ValidationException::withMessages([
                'parent_id' => __('لا تكون القائمة أبًا لنفسها.'),
            ]);
        }

        $parent = PriceList::with('parent')->find($parentId);

        if ($parent && in_array($list->id, $parent->ancestryIds(), true)) {
            throw ValidationException::withMessages([
                'parent_id' => __('هذه القائمة ترث من المختارة أصلًا — والوراثة المتبادلة حلقةٌ لا تنتهي.'),
            ]);
        }
    }
}
