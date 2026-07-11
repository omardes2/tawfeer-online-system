<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreReservationRequest;
use App\Http\Resources\Inventory\StockReservationResource;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockReservationController extends Controller
{
    public function __construct(private readonly ReservationService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StockReservation::class);

        $query = StockReservation::query()->with(['variant', 'warehouse']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('warehouse')) {
            $query->where('warehouse_id', Warehouse::where('uuid', $request->input('warehouse'))->value('id'));
        }

        return StockReservationResource::collection($query->latest('id')->paginate($this->perPage()));
    }

    public function store(StoreReservationRequest $request): StockReservationResource
    {
        $this->authorize('create', StockReservation::class);

        $variant = ProductVariant::where('uuid', $request->validated('variant'))->firstOrFail();
        $warehouse = Warehouse::where('uuid', $request->validated('warehouse'))->firstOrFail();

        $reservation = $this->service->reserve($variant, $warehouse, (float) $request->validated('qty'), [
            'expires_at' => $request->validated('expires_at'),
        ]);

        return new StockReservationResource($reservation->load(['variant', 'warehouse']));
    }

    public function release(StockReservation $reservation): StockReservationResource
    {
        $this->authorize('release', $reservation);

        $this->service->release($reservation);

        return new StockReservationResource($reservation->fresh()->load(['variant', 'warehouse']));
    }
}
