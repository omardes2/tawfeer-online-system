<?php

namespace App\Modules\AiAgent\Tools;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Store\Services\CartService;

/**
 * التوفّر الفعلي لمتغيّر.
 *
 * **المتاح = الموجود ناقص المحجوز** لا الموجود وحده: المحجوز مبيعٌ فعلًا وإن لم
 * يخرج من المستودع بعد. وقولُ «متوفّر» عن قطعةٍ محجوزة لطلبٍ آخر يُنتج طلبًا
 * لا يمكن تنفيذه — واعتذارًا لزبونٍ وُعد.
 */
class CheckStockTool implements ToolContract
{
    public function __construct(private readonly CartService $carts) {}

    public function name(): string
    {
        return 'check_stock';
    }

    public function description(): string
    {
        return 'تأكّد من توفّر الصنف قبل أن تَعِد الزبون بأي شيء.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['variant_id' => ['type' => 'integer']],
            'required' => ['variant_id'],
        ];
    }

    public function handle(array $arguments): array
    {
        $variant = ProductVariant::with('inventoryStocks')->find((int) ($arguments['variant_id'] ?? 0));

        if ($variant === null) {
            return ['error' => 'not_found', 'message' => 'الصنف غير موجود.'];
        }

        $available = $this->carts->availableQty($variant);

        return [
            'variant_id' => $variant->id,
            'available_qty' => (string) $available,
            'is_available' => $available > 0,
        ];
    }
}
