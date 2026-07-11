<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\InventoryLedgerResource;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryLedgerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('inventory.movements.view');

        $query = InventoryLedger::query();

        if ($request->filled('warehouse')) {
            $query->where('warehouse_id', Warehouse::where('uuid', $request->input('warehouse'))->value('id'));
        }

        if ($request->filled('variant')) {
            $query->where('variant_id', ProductVariant::where('uuid', $request->input('variant'))->value('id'));
        }

        return InventoryLedgerResource::collection($query->latest('id')->paginate($this->perPage()));
    }
}
