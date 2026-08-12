<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CancelOrderRequest;
use App\Http\Resources\Sales\OrderResource;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\OrderVoidService;

class OrderTransitionController extends Controller
{
    public function __construct(private readonly OrderService $service) {}

    public function confirm(Order $order): OrderResource
    {
        $this->authorize('confirm', $order);

        return $this->respond($this->service->confirm($order));
    }

    public function reserve(Order $order): OrderResource
    {
        $this->authorize('reserve', $order);

        return $this->respond($this->service->reserveStock($order));
    }

    public function prepare(Order $order): OrderResource
    {
        // خطوات التجهيز/الجاهزية عمل مستودعي يقود إلى الشحن (صلاحية الشحن).
        $this->authorize('ship', $order);

        return $this->respond($this->service->startPreparing($order));
    }

    public function ready(Order $order): OrderResource
    {
        $this->authorize('ship', $order);

        return $this->respond($this->service->markReady($order));
    }

    public function ship(Order $order): OrderResource
    {
        $this->authorize('ship', $order);

        return $this->respond($this->service->ship($order));
    }

    public function deliver(Order $order): OrderResource
    {
        $this->authorize('deliver', $order);

        return $this->respond($this->service->deliver($order));
    }

    /**
     * الإلغاء عبر الـAPI يسلك نفس مسار الواجهة: يُلغي الطلب **ويُلغي طرده لدى شركة
     * التوصيل** (وإن تعذّر سُجّل الفشل وأعادت المكنسة المحاولة). كان يُلغي محليًا فقط
     * فيبقى الطرد نشطًا لديهم.
     */
    public function cancel(CancelOrderRequest $request, Order $order, OrderVoidService $void): OrderResource
    {
        $this->authorize('cancel', $order);

        $order = $this->service->cancel($order, $request->validated('reason'));
        $void->cancelAtProvider($order);

        return $this->respond($order->fresh());
    }

    private function respond(Order $order): OrderResource
    {
        return new OrderResource($order->load(['warehouse', 'items', 'statusHistory.changedBy']));
    }
}
