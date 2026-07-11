<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\IssueStockRequest;
use App\Http\Requests\Inventory\ReceiveStockRequest;
use App\Http\Requests\Inventory\TransferStockRequest;
use App\Http\Resources\Inventory\InventoryMovementResource;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Http\JsonResponse;

class InventoryOperationController extends Controller
{
    public function __construct(private readonly InventoryService $service) {}

    public function receive(ReceiveStockRequest $request): InventoryMovementResource
    {
        $this->authorize('inventory.operations.receive');
        [$variant, $warehouse] = $this->resolve($request);

        $movement = $this->service->receive($variant, $warehouse, (float) $request->validated('qty'), (float) $request->validated('unit_cost'), $request->only(['reason', 'note']));

        return new InventoryMovementResource($movement->load(['variant', 'warehouse']));
    }

    public function issue(IssueStockRequest $request): InventoryMovementResource
    {
        $this->authorize('inventory.operations.issue');
        [$variant, $warehouse] = $this->resolve($request);

        $movement = $this->service->issue($variant, $warehouse, (float) $request->validated('qty'), $request->only(['reason', 'note']));

        return new InventoryMovementResource($movement->load(['variant', 'warehouse']));
    }

    public function transfer(TransferStockRequest $request): JsonResponse
    {
        $this->authorize('inventory.operations.transfer');
        $variant = ProductVariant::where('uuid', $request->validated('variant'))->firstOrFail();
        $from = Warehouse::where('uuid', $request->validated('from_warehouse'))->firstOrFail();
        $to = Warehouse::where('uuid', $request->validated('to_warehouse'))->firstOrFail();

        $movements = $this->service->transfer($variant, $from, $to, (float) $request->validated('qty'), $request->only(['reason', 'note']));

        return InventoryMovementResource::collection(collect($movements)->each->load(['variant', 'warehouse']))
            ->response()->setStatusCode(201);
    }

    private function resolve($request): array
    {
        return [
            ProductVariant::where('uuid', $request->validated('variant'))->firstOrFail(),
            Warehouse::where('uuid', $request->validated('warehouse'))->firstOrFail(),
        ];
    }
}
