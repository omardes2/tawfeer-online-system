<?php

namespace App\Modules\Marketing\Jobs;

use App\Modules\Sales\Models\Order;
use App\Support\Integrations\Pixel\ConversionEvent;
use App\Support\Integrations\Pixel\ConversionTrackerManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * إرسال حدث «شراء» إلى منصّة القياس بعد تسجيل الطلب.
 *
 * ثلاثة قرارات:
 *
 * 1. **في طابور لا في الطلب نفسه.** مسار الإتمام محميّ ولا يُمسّ، والأهمّ أن
 *    نداءً بطيئًا إلى المنصّة كان سيؤخّر تأكيد الطلب أمام الزبون — أو يُفشله
 *    إن انقطعت الشبكة. القياس لا يجوز أن يعترض البيع.
 *
 * 2. **معرّف الحدث من الطلب لا عشوائيًّا** (`purchase.{uuid}`): إعادة المحاولة
 *    تحمل المعرّف نفسه فلا تُحتسب شراءً ثانيًا، ولو أُضيف حدث المتصفّح لاحقًا
 *    لالتقى به على المعرّف نفسه.
 *
 * 3. **لقطة البيانات في المهمّة لا قراءتها لاحقًا:** الطلب قد يُلغى أو تتغيّر
 *    أرقامه قبل أن يعمل الطابور، والحدث يجب أن يصف ما جرى لحظة الشراء.
 */
class SendPurchaseConversion implements ShouldQueue
{
    use Queueable;

    /** محاولاتٌ متباعدة: فشل المنصّة العابر شائع، والحدث المفقود لا يُعوَّض. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 300];

    public function __construct(private readonly int $orderId) {}

    public function handle(ConversionTrackerManager $trackers): void
    {
        $tracker = $trackers->tracker();

        if (! $tracker->isConfigured()) {
            return;
        }

        $order = Order::with('items')->find($this->orderId);

        if (! $order) {
            return;
        }

        try {
            $tracker->track(new ConversionEvent(
                name: 'Purchase',
                eventId: 'purchase.'.$order->uuid,
                eventTime: $order->created_at?->getTimestamp() ?? time(),
                sourceUrl: route('storefront.home'),
                email: $order->customer_email,
                phone: $order->customer_phone,
                clickId: $order->ad_click_id,
                value: (float) $order->total,
                currency: (string) config('ads.pixel.currency', 'ILS'),
                contents: $this->contents($order),
            ));
        } catch (Throwable $e) {
            Log::warning('ads.pixel.purchase_failed', [
                'order' => $order->id, 'message' => $e->getMessage(),
            ]);

            throw $e; // ليعيد الطابور المحاولة.
        }
    }

    /**
     * أصناف الطلب بالصيغة التي تتوقّعها المنصّة.
     *
     * المعرّف هو معرّف المنتج عندنا — ويجب أن يطابق معرّفات كتالوج المنتجات
     * حين يُبنى، وإلّا لم تربط المنصّة الشراء بالصنف المُعلَن عنه.
     *
     * @return array<int, array{id: string, quantity: int, item_price: float}>
     */
    private function contents(Order $order): array
    {
        return $order->items
            ->map(fn ($item) => [
                'id' => (string) ($item->variant?->product_id ?? $item->variant_id),
                'quantity' => (int) $item->qty,
                'item_price' => round((float) $item->unit_price, 2),
            ])
            ->values()
            ->all();
    }
}
