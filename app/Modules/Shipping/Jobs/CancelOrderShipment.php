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
 * إلغاء شحنة الطلب لدى شركة التوصيل (Opost) في الخلفية عند إلغاء الطلب.
 * يُنقل الاتصال المتزامن خارج طلب الويب (يتفادى مهلة الانتظار).
 */
class CancelOrderShipment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

        $result = $dispatcher->cancelShipment($order);

        Log::info('Order delivery cancel (queued)', [
            'order' => $this->orderId,
            'status' => $result['status'] ?? null,
            'message' => $result['message'] ?? null,
        ]);

        if (($result['status'] ?? null) === 'failed' && $this->attempts() < $this->tries) {
            $this->release($this->backoff);
        }
    }
}
