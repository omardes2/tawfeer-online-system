<?php

namespace App\Http\Controllers\Admin\Settlements;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settlements\StoreSettlementRequest;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Settlements\Models\DeliverySettlement;
use App\Modules\Settlements\Services\SettlementService;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Support\DeliveryStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * لوحة التسويات المالية (Phase 4.6 / ADR-041) — RTL. المنطق في SettlementService.
 */
class SettlementController extends Controller
{
    public function __construct(private readonly SettlementService $service) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');
        $query = DeliverySettlement::with('provider:id,name')->latest('id');
        if ($status) {
            $query->where('status', $status);
        }

        return view('admin.settlements.index', [
            'settlements' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'statuses' => array_keys(DeliverySettlement::TRANSITIONS),
        ]);
    }

    public function create(): View
    {
        return view('admin.settlements.create', ['providers' => DeliveryProvider::orderBy('name')->get()]);
    }

    public function store(StoreSettlementRequest $request): RedirectResponse
    {
        $settlement = $this->service->create($request->validated(), $request->user());

        return redirect()->route('admin.settlements.show', $settlement)->with('status', __('settlements.created'));
    }

    public function show(DeliverySettlement $settlement): View
    {
        // شحنات مُغلقة غير مُسوّاة بعد (لاقتراح إضافتها).
        $closedShipments = Shipment::with('order:id,number,total')
            ->where('delivery_status', DeliveryStatus::CLOSED)
            ->whereDoesntHave('order', fn ($q) => $q->whereNotNull('settled_at'))
            ->latest('id')->limit(50)->get();

        return view('admin.settlements.show', [
            'settlement' => $settlement->load(['lines.order:id,number', 'provider:id,name', 'reconciledBy:id,name', 'postedBy:id,name']),
            'closedShipments' => $closedShipments,
        ]);
    }

    public function addShipments(DeliverySettlement $settlement, Request $request): RedirectResponse
    {
        $data = $request->validate(['shipment_ids' => ['required', 'array', 'min:1'], 'shipment_ids.*' => ['integer']]);
        $this->service->addClosedShipments($settlement, $data['shipment_ids'], $request->user());

        return back()->with('status', __('settlements.lines_added'));
    }

    public function reconcile(DeliverySettlement $settlement, Request $request): RedirectResponse
    {
        $this->service->reconcile($settlement, $request->user());

        return back()->with('status', __('settlements.reconciled'));
    }

    public function post(DeliverySettlement $settlement, Request $request): RedirectResponse
    {
        $this->service->post($settlement, $request->user());

        return back()->with('status', __('settlements.posted'));
    }

    public function close(DeliverySettlement $settlement, Request $request): RedirectResponse
    {
        $this->service->close($settlement, $request->user());

        return back()->with('status', __('settlements.closed'));
    }

    public function cancel(DeliverySettlement $settlement, Request $request): RedirectResponse
    {
        $this->service->cancel($settlement, $request->user());

        return back()->with('status', __('settlements.cancelled'));
    }
}
