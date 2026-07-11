<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\InventoryStockResource;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryStockController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('inventory.stocks.view');

        $query = InventoryStock::query()->with(['variant', 'warehouse']);

        if ($request->filled('warehouse')) {
            $query->where('warehouse_id', Warehouse::where('uuid', $request->input('warehouse'))->value('id'));
        }

        if ($request->filled('variant')) {
            $query->where('variant_id', ProductVariant::where('uuid', $request->input('variant'))->value('id'));
        }

        if ($request->boolean('low_stock')) {
            $query->whereNotNull('reorder_level')->whereColumn('on_hand', '<=', 'reorder_level');
        }

        $this->applySorting($query, $request, ['on_hand', 'updated_at'], 'updated_at', 'desc');

        return InventoryStockResource::collection($query->paginate($this->perPage()));
    }
}
