<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\AssistedOrderRequest;
use App\Http\Resources\Sales\AssistedOrderResource;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Sales\Models\OrderPriceChange;
use App\Modules\Sales\Services\AssistedOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * البيع المُساعد (Phase 4.1): إنشاء طلب من قناة + إدارة اعتماد تعديلات السعر.
 * متحكّم رفيع — المنطق في AssistedOrderService. الصلاحيات عبر can: على المسارات.
 */
class AssistedOrderController extends Controller
{
    public function __construct(private readonly AssistedOrderService $service) {}

    public function store(AssistedOrderRequest $request): AssistedOrderResource
    {
        $data = $request->validated();
        // تحويل uuid المتغيّر إلى المعرّف الداخلي (لا كشف id في الإدخال).
        $data['items'] = array_map(function (array $line) {
            $line['variant_id'] = ProductVariant::where('uuid', $line['variant'])->value('id');

            return $line;
        }, $data['items']);

        $order = $this->service->create($request->user(), $data, $data['items']);

        return new AssistedOrderResource($order);
    }

    public function priceChanges(): JsonResponse
    {
        $pending = OrderPriceChange::where('status', 'pending')
            ->with(['order:id,uuid,number', 'variant:id,uuid,sku'])
            ->latest('id')->paginate(20);

        return response()->json([
            'data' => $pending->getCollection()->map(fn (OrderPriceChange $c) => [
                'id' => $c->id,
                'order' => $c->order?->number,
                'sku' => $c->variant?->sku,
                'original_retail' => $c->original_retail,
                'min_price' => $c->min_price,
                'new_price' => $c->new_price,
                'reason' => $c->reason,
                'status' => $c->status,
            ])->all(),
            'meta' => ['total' => $pending->total(), 'per_page' => $pending->perPage(), 'current_page' => $pending->currentPage()],
        ]);
    }

    public function approve(Request $request, OrderPriceChange $priceChange): JsonResponse
    {
        $change = $this->service->approvePriceChange($request->user(), $priceChange);

        return response()->json(['data' => ['id' => $change->id, 'status' => $change->status]]);
    }

    public function reject(Request $request, OrderPriceChange $priceChange): JsonResponse
    {
        $change = $this->service->rejectPriceChange($request->user(), $priceChange, $request->input('reason'));

        return response()->json(['data' => ['id' => $change->id, 'status' => $change->status]]);
    }
}
