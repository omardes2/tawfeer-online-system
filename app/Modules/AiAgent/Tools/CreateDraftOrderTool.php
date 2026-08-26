<?php

namespace App\Modules\AiAgent\Tools;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\OfferPricing;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Store\Services\CartService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * إنشاء **مسودّة** طلبٍ من محادثة واتساب.
 *
 * تُفوِّض إلى `OrderService::create` — لا منطقَ طلباتٍ موازٍ: الترقيم وحارس
 * البيع بأقل من الجملة واحتساب المجاميع وسجلّ الحالة كلُّها هناك، ونسخُها هنا
 * يُنتج طلبًا يبدو سليمًا ويخالف بقيّة النظام.
 *
 * ## Protected Delivery Integration — Do Not Modify
 *
 * **المسودّة تقف عند `draft` ولا تتجاوزها.** مكنسةُ الشحن المجدولة كل دقيقة
 * (`shipping:dispatch-pending`) تلتقط أيّ طلبٍ حالته ضمن
 * `confirmed · stock_reserved · preparing · …` وله `city_id` وقناته ليست
 * `pos`، فتُنشئ له **طردًا حقيقيًّا عند شركة التوصيل**. فلو أكّد الوكيل الطلب
 * لخرجت بضاعةٌ إلى الشارع خلال دقيقة بلا قرار إنسان. والمسودّة خارج تلك
 * الحالات، فتبقى في اللوحة حتى تؤكّدها موظفة.
 *
 * ورسوم التوصيل تُقرأ من `DeliveryCityRate` كما يقرؤها نموذج الطلب في اللوحة
 * — من الخلفية دائمًا، بلا سعرٍ ثابت ولا احتسابٍ جديد. والقراءة مكرّرة هنا
 * عمدًا لا سهوًا: استخراجُها إلى خدمةٍ مشتركة إعادةُ هيكلةٍ لمسار التوصيل،
 * وهو مُجمَّد.
 *
 * والأسعار من `OfferPricing` — مسار تسعير السلة نفسه — كي لا يُنشأ طلبٌ بسعرٍ
 * لا تقبله شاشة الدفع.
 */
class CreateDraftOrderTool implements ContextAwareTool, ToolContract
{
    private ?Conversation $conversation = null;

    public function __construct(
        private readonly OrderService $orders,
        private readonly CartService $carts,
        private readonly OfferPricing $offers,
    ) {}

    public function setConversation(Conversation $conversation): void
    {
        $this->conversation = $conversation;
    }

    public function name(): string
    {
        return 'create_draft_order';
    }

    public function description(): string
    {
        return 'أنشئ مسودّة طلب بعد أن يوافق الزبون على الملخّص. '
            .'تحتاج: الأصناف والكميات، واسم الزبون، والمدينة والمنطقة، والعنوان. '
            .'المسودّة لا تُشحن حتى تعتمدها موظفة.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'variant_id' => ['type' => 'integer'],
                            'qty' => ['type' => 'integer', 'minimum' => 1],
                        ],
                        'required' => ['variant_id', 'qty'],
                    ],
                ],
                'customer_name' => ['type' => 'string'],
                'city_id' => ['type' => 'integer'],
                'area_id' => ['type' => 'integer'],
                'shipping_address' => ['type' => 'string', 'description' => 'الشارع والمعلَم القريب'],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['items', 'customer_name', 'city_id', 'area_id', 'shipping_address'],
        ];
    }

    public function handle(array $arguments): array
    {
        $phone = $this->conversation?->contact?->external_id;

        // الرقم من جهة الاتصال لا من النموذج: رقمٌ يمليه النموذج قابل
        // للاختلاق، وطلبٌ برقمٍ مخترع يصل إلى شخصٍ آخر.
        if (blank($phone)) {
            return ['error' => 'no_contact', 'message' => 'لا رقم للزبون في هذه المحادثة.'];
        }

        $branch = Branch::default();
        $warehouse = Warehouse::where('branch_id', $branch->id)->orderBy('id')->first()
            ?? Warehouse::orderBy('id')->first();

        if ($warehouse === null) {
            return ['error' => 'no_warehouse', 'message' => 'لا مستودع مهيّأ.'];
        }

        [$items, $missing] = $this->priceItems((array) ($arguments['items'] ?? []));

        if ($missing !== []) {
            return ['error' => 'unknown_variant', 'message' => 'أصناف غير موجودة: '.implode('، ', $missing)];
        }

        if ($items === []) {
            return ['error' => 'no_items', 'message' => 'لا أصناف في الطلب.'];
        }

        try {
            $order = DB::transaction(fn () => $this->orders->create([
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'customer_name' => (string) $arguments['customer_name'],
                'customer_phone' => $phone,
                'shipping_address' => (string) $arguments['shipping_address'],
                'city_id' => (int) $arguments['city_id'],
                'area_id' => (int) $arguments['area_id'],
                'shipping_total' => $this->deliveryFee((int) $arguments['city_id']),
                'channel' => 'whatsapp',
                'notes' => $arguments['notes'] ?? null,
            ], $items, (int) now()->year));
        } catch (ValidationException $e) {
            // خطأ التحقّق يعود إلى النموذج نصًّا كي يسأل الزبون أو يحوّل، لا
            // ليُسقط الدورة.
            return ['error' => 'rejected', 'message' => collect($e->errors())->flatten()->first() ?? 'تعذّر إنشاء الطلب.'];
        }

        $this->conversation?->forceFill(['order_id' => $order->id])->save();

        return [
            'order_number' => $order->number,
            'status' => 'draft',
            'items_total' => number_format((float) $order->subtotal, 2, '.', ''),
            'shipping_total' => number_format((float) $order->shipping_total, 2, '.', ''),
            'total' => number_format((float) $order->total, 2, '.', ''),
            'note_for_agent' => 'الطلب مسودّة. أبلغ الزبون أن موظفة ستؤكّده وتتواصل معه، ولا تَعِد بموعد تسليم.',
        ];
    }

    /**
     * تسعير البنود من مسار السلة.
     *
     * @param  array<int, array<string, mixed>>  $raw
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, int>}
     */
    private function priceItems(array $raw): array
    {
        $items = [];
        $missing = [];

        foreach ($raw as $line) {
            $id = (int) ($line['variant_id'] ?? 0);
            $qty = max(1, (int) ($line['qty'] ?? 1));
            $variant = ProductVariant::with('product.offers')->find($id);

            if ($variant === null) {
                $missing[] = $id;

                continue;
            }

            $regular = $this->carts->sellingPrice($variant);
            $unit = $this->offers->unitPrice($variant->product?->activeOffers ?? collect(), $qty, $regular);

            $items[] = ['variant_id' => $variant->id, 'qty' => $qty, 'unit_price' => round($unit, 2), 'discount' => 0];
        }

        return [$items, $missing];
    }

    /**
     * رسوم التوصيل من الخلفية — نفس ما يقرؤه نموذج الطلب في اللوحة.
     *
     * Protected Delivery Integration — Do Not Modify.
     */
    private function deliveryFee(int $cityId): float
    {
        // سعر البيع للزبون لا تكلفة الشركة — ما يقوله الوكيل للزبون هو ما
        // سيُقيَّد على طلبه.
        return (float) (DeliveryCityRate::where('is_active', true)
            ->where('city_id', $cityId)
            ->first()?->customerFee() ?? 0);
    }
}
