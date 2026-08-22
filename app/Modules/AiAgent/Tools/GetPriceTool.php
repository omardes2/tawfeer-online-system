<?php

namespace App\Modules\AiAgent\Tools;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\OfferPricing;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Store\Services\CartService;

/**
 * سعر البيع النهائي لكمّيةٍ بعينها.
 *
 * **لا تحسب هذه الأداة سعرًا بنفسها.** تُفوِّض إلى `OfferPricing` — مسار تسعير
 * السلة نفسه — لأن العروض الكمّية («٣ بـ١٠٠») تُطبَّق هناك. ولو حَسبَت هنا
 * حسابًا مستقلًّا لقال الوكيل رقمًا **لا تقبله شاشة الدفع**، فيصل الزبون إلى
 * السلة فيجد سعرًا آخر — وهو أسرع طريقٍ إلى فقد الثقة.
 *
 * والمبالغ تُمرَّر إلى النموذج **نصوصًا** لا أعدادًا عائمة: النموذج يُعيد كتابة
 * ما يُعطى، وكسرُ العائم يُنتج «99.99999» في رسالةٍ للزبون.
 *
 * TODO(pricing-decision): زبون واتساب بلا مسوّق ⇒ سعر التجزئة. وحين تُحسم
 * قوائم أسعار التجّار على القنوات، يُمرَّر المشتري هنا كما في `OrderService`.
 */
class GetPriceTool implements ToolContract
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OfferPricing $offers,
    ) {}

    public function name(): string
    {
        return 'get_price';
    }

    public function description(): string
    {
        return 'احسب السعر النهائي لكمّية. لا تذكر أي سعر للزبون قبل استدعاء هذه الأداة.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'variant_id' => ['type' => 'integer'],
                'qty' => ['type' => 'integer', 'minimum' => 1],
            ],
            'required' => ['variant_id', 'qty'],
        ];
    }

    public function handle(array $arguments): array
    {
        $variant = ProductVariant::with('product.offers')->find((int) ($arguments['variant_id'] ?? 0));
        $qty = max(1, (int) ($arguments['qty'] ?? 1));

        if ($variant === null) {
            return ['error' => 'not_found', 'message' => 'الصنف غير موجود.'];
        }

        $regular = $this->carts->sellingPrice($variant);
        $offers = $variant->product?->activeOffers ?? collect();
        $unit = $this->offers->unitPrice($offers, $qty, $regular);

        $total = round($unit * $qty, 2);
        $before = round($regular * $qty, 2);

        return [
            'variant_id' => $variant->id,
            'qty' => $qty,
            'unit_price' => number_format($unit, 2, '.', ''),
            'total' => number_format($total, 2, '.', ''),
            'discount' => number_format(max(0, $before - $total), 2, '.', ''),
            'currency' => (string) Settings::get('store.currency', 'ILS'),
            // الضريبة مؤجّلة في النظام (ADR-015) والسعر المعروض شاملٌ لما يدفعه
            // الزبون. TODO(pricing-decision) عند تفعيل الضريبة.
            'tax_included' => true,
        ];
    }
}
