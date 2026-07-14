<?php

namespace App\Modules\Shipping\Jobs;

use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Services\OrderDeliveryDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * إرسال الطلب لشركة التوصيل (Opost) في الخلفية بعد التأكيد (ADR-038).
 * يُنقل الاتصال المتزامن خارج طلب الويب لتفادي مهلة الانتظار — يرجع «تأكيد» فورًا.
 * فشل الإرسال لا يؤثّر على حالة الطلب؛ يُعاد المحاولة عبر الطابور.
 */
class DispatchOrderShipment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** محاولات + مهلة إعادة (ثوانٍ) — Opost قد يتأخّر أحيانًا. */
    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    public function __construct(public readonly int $orderId) {}

    public function handle(OrderDeliveryDispatcher $dispatcher): void
    {
        $order = Order::find($this->orderId);
        if ($order === null) {
            return;
        }

        $result = $dispatcher->dispatch($order);

        Log::info('Order delivery dispatch (queued)', [
            'order' => $this->orderId,
            'status' => $result['status'] ?? null,
            'tracking' => $result['tracking_number'] ?? null,
            'message' => $result['message'] ?? null,
        ]);

        // فشل التكامل ⇒ أعِد المحاولة عبر الطابور (لا يكسر حالة الطلب).
        if (($result['status'] ?? null) === 'failed' && $this->attempts() < $this->tries) {
            $this->release($this->backoff);
        }
    }
}
