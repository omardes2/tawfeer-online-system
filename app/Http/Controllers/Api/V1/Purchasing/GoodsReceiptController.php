<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreGoodsReceiptRequest;
use App\Http\Resources\Purchasing\GoodsReceiptResource;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\GoodsReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GoodsReceiptController extends Controller
{
    public function __construct(private readonly GoodsReceiptService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GoodsReceipt::class);

        $query = GoodsReceipt::query()->with(['purchaseOrder', 'warehouse']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return GoodsReceiptResource::collection($query->latest('id')->paginate($this->perPage()));
    }

    public function show(GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        $this->authorize('view', $goodsReceipt);

        return new GoodsReceiptResource($goodsReceipt->load(['purchaseOrder', 'warehouse', 'items']));
    }

    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        $this->authorize('create', GoodsReceipt::class);

        $po = PurchaseOrder::where('uuid', $request->validated('purchase_order'))->firstOrFail();

        $receipt = $this->service->create(
            $po,
            [
                'additional_cost' => $request->validated('additional_cost', 0),
                'notes' => $request->validated('notes'),
            ],
            $this->items($request),
            (int) now()->year,
        );

        return (new GoodsReceiptResource($receipt->load(['purchaseOrder', 'warehouse', 'items'])))->response()->setStatusCode(201);
    }

    public function post(GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        $this->authorize('post', $goodsReceipt);

        return new GoodsReceiptResource($this->service->post($goodsReceipt)->load(['purchaseOrder', 'warehouse', 'items']));
    }

    private function items(StoreGoodsReceiptRequest $request): array
    {
        return collect($request->validated('items'))->map(fn ($item) => [
            'variant_id' => ProductVariant::where('uuid', $item['variant'])->value('id'),
            'purchase_order_item_id' => $item['purchase_order_item_id'] ?? null,
            'qty_received' => $item['qty_received'],
            'unit_cost' => $item['unit_cost'],
        ])->all();
    }
}
