<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreOrderRequest;
use App\Http\Requests\Sales\UpdateOrderRequest;
use App\Http\Resources\Sales\OrderResource;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Order::class);

        $query = Order::query()->with(['warehouse', 'assignee']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('number', 'like', $term)
                ->orWhere('customer_name', 'like', $term)
                ->orWhere('customer_phone', 'like', $term));
        }

        $this->applySorting($query, $request, ['number', 'status', 'created_at']);

        return OrderResource::collection($query->paginate($this->perPage()));
    }

    public function show(Order $order): OrderResource
    {
        $this->authorize('view', $order);

        return new OrderResource($order->load(['warehouse', 'assignee', 'items', 'statusHistory.changedBy']));
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $this->authorize('create', Order::class);

        $order = $this->service->create($this->header($request), $this->items($request), (int) now()->year);

        return (new OrderResource($order->load(['warehouse', 'items', 'statusHistory'])))->response()->setStatusCode(201);
    }

    public function update(UpdateOrderRequest $request, Order $order): OrderResource
    {
        $this->authorize('update', $order);

        $items = $request->has('items') ? $this->items($request) : null;
        $order = $this->service->update($order, $this->header($request, false), $items);

        return new OrderResource($order->load(['warehouse', 'items', 'statusHistory']));
    }

    public function destroy(Order $order): JsonResponse
    {
        $this->authorize('delete', $order);

        $this->service->delete($order);

        return response()->json(['message' => __('تم حذف الطلب.')]);
    }

    private function header(Request $request, bool $withDefaults = true): array
    {
        $data = [];

        if ($request->filled('warehouse')) {
            $warehouse = Warehouse::where('uuid', $request->string('warehouse'))->firstOrFail();
            $data['warehouse_id'] = $warehouse->id;
            $data['branch_id'] = $warehouse->branch_id;
        }
        foreach (['customer_name', 'customer_phone', 'customer_email', 'shipping_address', 'channel', 'notes'] as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        // ربط عميل CRM (اختياري): يضبط customer_id ويشتقّ لقطة العميل إن لم تُمرَّر (تبقى ثابتة تاريخيًا).
        if ($request->filled('customer')) {
            $customer = Customer::where('uuid', $request->string('customer'))->firstOrFail();
            $data['customer_id'] = $customer->id;
            $data['customer_name'] ??= $customer->name;
            $data['customer_phone'] ??= $customer->primary_phone;
            $data['customer_email'] ??= $customer->email;
        }

        return $data;
    }

    private function items(Request $request): array
    {
        return collect($request->input('items', []))->map(fn ($item) => [
            'variant_id' => ProductVariant::where('uuid', $item['variant'])->value('id'),
            'qty' => $item['qty'],
            'unit_price' => $item['unit_price'],
            'discount' => $item['discount'] ?? 0,
        ])->all();
    }
}
