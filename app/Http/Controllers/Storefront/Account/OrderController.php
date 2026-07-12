<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Order;
use App\Modules\Store\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * طلبات العميل (Phase 3.4): السجلّ، التتبّع (حالة + سجلّ + شحنة)، حالة حيّة (JSON)،
 * وإعادة الطلب (يُعاد استخدام AccountService/OrderService — بلا منطق أعمال جديد).
 */
class OrderController extends Controller
{
    use InteractsWithCustomer;

    public function __construct(private readonly AccountService $account) {}

    public function index(): View
    {
        return view('storefront.account.orders.index', [
            'orders' => $this->currentCustomer()->orders()
                ->withCount('items')->latest('id')->paginate(10),
        ]);
    }

    public function show(Order $order): View
    {
        $this->authorizeOrder($order);

        return view('storefront.account.orders.show', [
            'order' => $order->load(['items.variant', 'statusHistory', 'shipments', 'payments']),
        ]);
    }

    public function status(Order $order): JsonResponse
    {
        $this->authorizeOrder($order);

        return response()->json([
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'shipment_status' => $order->shipments()->latest('id')->value('status'),
        ]);
    }

    public function reorder(Order $order, Request $request): RedirectResponse
    {
        $this->authorizeOrder($order);
        $added = $this->account->reorder($request->user(), $order);

        return redirect()->route('storefront.cart')
            ->with('status', __('account.reorder_added', ['count' => $added]));
    }

    private function authorizeOrder(Order $order): void
    {
        abort_unless($order->customer_id === $this->currentCustomer()->id, 403);
    }
}
