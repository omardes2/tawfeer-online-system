<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\InventoryMovementResource;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryMovementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('inventory.movements.view');

        $query = InventoryMovement::query()->with(['variant', 'warehouse']);

        if ($request->filled('warehouse')) {
            $query->where('warehouse_id', Warehouse::where('uuid', $request->input('warehouse'))->value('id'));
        }

        if ($request->filled('variant')) {
            $query->where('variant_id', ProductVariant::where('uuid', $request->input('variant'))->value('id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return InventoryMovementResource::collection($query->latest('id')->paginate($this->perPage()));
    }
}
