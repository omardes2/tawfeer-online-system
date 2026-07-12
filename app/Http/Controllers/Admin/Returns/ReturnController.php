<?php

namespace App\Http\Controllers\Admin\Returns;

use App\Http\Controllers\Controller;
use App\Http\Requests\Returns\InspectReturnRequest;
use App\Http\Requests\Returns\StoreReturnRequest;
use App\Modules\Returns\Models\ReturnRequest;
use App\Modules\Returns\Services\ReturnService;
use App\Modules\Returns\Support\ReturnWorkflow;
use App\Modules\Sales\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * لوحة المرتجعات/الاستبدال — RMA (Phase 4.4 / ADR-040) — RTL موبايل-أوّلًا. المنطق في الخدمة.
 */
class ReturnController extends Controller
{
    public function __construct(private readonly ReturnService $service) {}

    public function index(Request $request): View
    {
        $status = $request->query('status');
        $query = ReturnRequest::with(['order:id,number', 'requester:id,name'])->latest('id');
        if ($status) {
            $query->where('status', $status);
        }

        return view('admin.returns.index', [
            'returns' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'statuses' => array_keys(ReturnWorkflow::TRANSITIONS),
        ]);
    }

    public function create(Request $request): View
    {
        $order = null;
        if ($request->filled('order')) {
            $order = Order::where('number', $request->query('order'))->orWhere('uuid', $request->query('order'))
                ->with('items.variant.product')->first();
        }

        return view('admin.returns.create', [
            'order' => $order,
            'types' => ReturnWorkflow::TYPES,
            'reasons' => ReturnWorkflow::REASONS,
            'resolutions' => ReturnWorkflow::RESOLUTIONS,
        ]);
    }

    public function store(StoreReturnRequest $request): RedirectResponse
    {
        $order = Order::where('uuid', $request->validated('order'))->firstOrFail();
        // في اللوحة نستقبل بنودًا مُختارة فقط (qty > 0).
        $items = collect($request->validated('items'))->filter(fn ($i) => (float) $i['qty'] > 0)->values()->all();
        $return = $this->service->create($order, $request->validated(), $items, (int) now()->year, $request->user());

        return redirect()->route('admin.returns.show', $return)->with('status', __('returns.created'));
    }

    public function show(ReturnRequest $return): View
    {
        return view('admin.returns.show', [
            'return' => $return->load(['order:id,number,status', 'items.orderItem', 'items.variant.product', 'photos', 'events.actor', 'linkedShipment:id,number,kind']),
            'results' => ReturnWorkflow::INSPECTION_RESULTS,
            'routes' => ReturnWorkflow::INVENTORY_ROUTES,
        ]);
    }

    public function approve(ReturnRequest $return, Request $request): RedirectResponse
    {
        $this->service->approve($return, $request->user());

        return back()->with('status', __('returns.approved'));
    }

    public function reject(ReturnRequest $return, Request $request): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->service->reject($return, $data['reason'], $request->user());

        return back()->with('status', __('returns.rejected'));
    }

    public function receive(ReturnRequest $return, Request $request): RedirectResponse
    {
        $linked = $request->boolean('create_linked_shipment') ? ['carrier_name' => $request->input('carrier_name')] : null;
        $this->service->receive($return, $request->user(), $linked);

        return back()->with('status', __('returns.received'));
    }

    public function inspect(InspectReturnRequest $request, ReturnRequest $return): RedirectResponse
    {
        $inspections = collect($request->validated('inspections'))
            ->keyBy('item_id')
            ->map(fn ($i) => ['inspection_result' => $i['inspection_result'], 'inventory_route' => $i['inventory_route']])
            ->all();
        $this->service->inspect($return, $inspections, $request->user());

        return back()->with('status', __('returns.inspected'));
    }

    public function complete(ReturnRequest $return, Request $request): RedirectResponse
    {
        $this->service->complete($return, $request->user());

        return back()->with('status', __('returns.completed'));
    }

    public function cancel(ReturnRequest $return, Request $request): RedirectResponse
    {
        $this->service->cancel($return, $request->user());

        return back()->with('status', __('returns.cancelled'));
    }

    public function photo(ReturnRequest $return, Request $request): RedirectResponse
    {
        $request->validate(['photo' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120']]);
        $path = $request->file('photo')->store('returns', 'public');
        $this->service->addPhoto($return, $path, $request->user());

        return back()->with('status', __('returns.photo_added'));
    }
}
