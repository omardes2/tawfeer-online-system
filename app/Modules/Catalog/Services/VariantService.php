<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * توليد وإدارة متغيّرات المنتج المرتبطة بقيم السمات (نظام المتغيّرات الكاملة).
 * كل متغيّر = تركيبة قيم فريدة (مقاس + لون ...) له مخزونه وسعره الخاص (ADR-024).
 */
class VariantService
{
    /**
     * يولّد متغيّرات لكل تركيبات القيم المختارة (الضرب الديكارتي عبر السمات)،
     * متجاوزًا التركيبات الموجودة مسبقًا. يعيد عدد المتغيّرات المُنشأة.
     *
     * @param  array<int>  $valueIds  معرّفات قيم السمات المختارة
     */
    public function generate(Product $product, array $valueIds): int
    {
        $values = ProductAttributeValue::whereIn('id', $valueIds)
            ->where('is_active', true)
            ->get();

        if ($values->isEmpty()) {
            return 0;
        }

        // محاور التنويع: قائمة معرّفات القيم مجمّعة حسب السمة.
        $groups = $values->groupBy('attribute_id')
            ->map(fn ($group) => $group->pluck('id')->all())
            ->values()->all();

        $combinations = $this->cartesian($groups);

        // بصمات التركيبات الموجودة مسبقًا (لتفادي التكرار).
        $existing = $product->variants()->with('attributeValues:id')->get()
            ->map(fn ($v) => $this->signature($v->attributeValues->pluck('id')->all()))
            ->filter()
            ->flip()
            ->all();

        $defaults = $this->priceDefaults($product);
        $created = 0;

        DB::transaction(function () use ($product, $combinations, &$existing, $defaults, &$created) {
            foreach ($combinations as $ids) {
                $signature = $this->signature($ids);
                if ($signature === '' || array_key_exists($signature, $existing)) {
                    continue;
                }

                $variant = $product->variants()->create($defaults + [
                    'sku' => $this->generateSku(),
                    'name' => $this->labelFor($ids),
                    'is_default' => false,
                    'is_active' => true,
                ]);
                $variant->attributeValues()->sync($ids);

                $existing[$signature] = true;
                $created++;
            }
        });

        return $created;
    }

    /**
     * مزامنة مصفوفة المتغيّرات من الواجهة الحيّة: ينشئ التركيبات الجديدة، ويحدّث سعر
     * الموجودة، ويحذف (ناعمًا) ما غاب. يعيد قائمة [variant => stock] لضبط المخزون خارجيًا.
     * يزامن أيضًا السمات المستخدمة مع «السمات المطبّقة» ليبقى المتجر متسقًا.
     *
     * @param  array<array{values: array<int>, price?: mixed, stock?: mixed}>  $combos
     * @return Collection<int, array{variant: ProductVariant, stock: float}>
     */
    public function sync(Product $product, array $combos): Collection
    {
        return DB::transaction(function () use ($product, $combos) {
            $existing = $product->variants()->with('attributeValues:id')->get()
                ->filter(fn ($v) => $v->attributeValues->isNotEmpty())
                ->keyBy(fn ($v) => $this->signature($v->attributeValues->pluck('id')->all()));

            $kept = [];
            $usedAttributeIds = [];
            $result = collect();

            foreach ($combos as $combo) {
                $ids = array_values(array_unique(array_map('intval', $combo['values'] ?? [])));
                $signature = $this->signature($ids);
                if ($signature === '' || isset($kept[$signature])) {
                    continue;
                }

                $price = isset($combo['price']) && $combo['price'] !== '' ? (float) $combo['price'] : null;

                $variant = $existing->get($signature);
                if ($variant) {
                    $variant->update([
                        'retail_price' => $price ?? $variant->retail_price,
                        'is_active' => true,
                    ]);
                } else {
                    // array_merge حتى تَغلِب القيم الصريحة (السعر) على الموروثة من المتغيّر الافتراضي.
                    $variant = $product->variants()->create(array_merge($this->priceDefaults($product), [
                        'sku' => $this->generateSku(),
                        'name' => $this->labelFor($ids),
                        'retail_price' => $price ?? 0,
                        'is_default' => false,
                        'is_active' => true,
                    ]));
                    $variant->attributeValues()->sync($ids);
                }

                $kept[$signature] = true;
                $result->push(['variant' => $variant, 'stock' => (float) ($combo['stock'] ?? 0)]);

                foreach (ProductAttributeValue::whereIn('id', $ids)->pluck('attribute_id') as $attrId) {
                    $usedAttributeIds[(int) $attrId] = true;
                }
            }

            // حذف التركيبات التي أُزيلت من المصفوفة.
            foreach ($existing as $signature => $variant) {
                if (! isset($kept[$signature])) {
                    $variant->delete();
                }
            }

            if ($usedAttributeIds !== []) {
                $product->attributes()->syncWithoutDetaching(array_keys($usedAttributeIds));
            }

            return $result;
        });
    }

    /** حذف متغيّر خيارات (حذف ناعم). لا يُسمح بحذف المتغيّر الافتراضي. */
    public function delete(ProductVariant $variant): void
    {
        if ($variant->is_default) {
            return;
        }

        $variant->delete();
    }

    /**
     * الضرب الديكارتي لمجموعات معرّفات القيم.
     *
     * @param  array<array<int>>  $groups
     * @return array<array<int>>
     */
    private function cartesian(array $groups): array
    {
        $result = [[]];

        foreach ($groups as $group) {
            $next = [];
            foreach ($result as $combo) {
                foreach ($group as $value) {
                    $next[] = array_merge($combo, [$value]);
                }
            }
            $result = $next;
        }

        return $result;
    }

    /** بصمة تركيبة = معرّفات القيم مرتّبة ومفصولة، مستقلة عن ترتيب الإدخال. */
    private function signature(array $ids): string
    {
        $ids = array_map('intval', $ids);
        sort($ids);

        return implode('-', $ids);
    }

    /** اسم المتغيّر من تسميات قيمه، مثل «L / أسود» (مرتّب حسب السمة). */
    private function labelFor(array $valueIds): string
    {
        return ProductAttributeValue::whereIn('id', $valueIds)
            ->orderBy('attribute_id')
            ->get()
            ->map(fn ($v) => $v->label ?: $v->value)
            ->implode(' / ');
    }

    /** أسعار المتغيّر الجديد تُورَّث من المتغيّر الافتراضي (أو سعر المنتج). */
    private function priceDefaults(Product $product): array
    {
        $base = $product->defaultVariant()->first();

        return [
            'retail_price' => $base?->retail_price ?? $product->retail_price ?? 0,
            'promo_price' => $base?->promo_price,
            'cost_price' => $base?->cost_price ?? 0,
        ];
    }

    /** رمز SKU فريد للمتغيّر بنمط «V-XXXXXXXX». */
    private function generateSku(): string
    {
        do {
            $sku = 'V-'.Str::upper(Str::random(8));
        } while (ProductVariant::withTrashed()->where('sku', $sku)->exists());

        return $sku;
    }
}
