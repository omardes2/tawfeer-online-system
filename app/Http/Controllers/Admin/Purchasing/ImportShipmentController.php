<?php

namespace App\Http\Controllers\Admin\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreImportShipmentRequest;
use App\Modules\Purchasing\Models\ImportShipment;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\ImportShipmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * شحنات الاستيراد (الكونتينرات). لا منطق أعمال هنا — كله في الخدمة.
 */
class ImportShipmentController extends Controller
{
    public function __construct(
        private readonly ImportShipmentService $service,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', ImportShipment::class);

        $status = in_array($request->query('status'), ['open', 'closed'], true) ? $request->query('status') : null;

        $query = ImportShipment::with('supplier')->withCount('invoices')->latest('id');
        if ($status !== null) {
            $query->where('status', $status);
        }
        $shipments = $query->paginate(20)->withQueryString();

        return view('admin.purchasing.shipments.index', [
            'shipments' => $shipments,
            'activeStatus' => $status,
            'openCount' => ImportShipment::open()->count(),
            'closedCount' => ImportShipment::where('status', 'closed')->count(),
            // الرصيد المُعلَّق: مجموع ما لم تصل فواتيره بعد عبر الشحنات المفتوحة.
            'openAccrued' => ImportShipment::open()->get()
                ->sum(fn (ImportShipment $s) => $this->service->summary($s)['variance']),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', ImportShipment::class);

        return view('admin.purchasing.shipments.form', $this->formViewData(null));
    }

    public function store(StoreImportShipmentRequest $request): RedirectResponse
    {
        $shipment = $this->service->create($request->validated());

        return redirect()->route('admin.purchasing.shipments.show', $shipment)
            ->with('success', __('أُنشئت الشحنة :n.', ['n' => $shipment->number]));
    }

    public function edit(ImportShipment $shipment): View
    {
        $this->authorize('update', $shipment);

        return view('admin.purchasing.shipments.form', $this->formViewData($shipment));
    }

    public function update(StoreImportShipmentRequest $request, ImportShipment $shipment): RedirectResponse
    {
        $this->authorize('update', $shipment);

        return $this->guard(
            fn () => $this->service->update($shipment, $request->validated()),
            __('حُدّثت بيانات الشحنة.'),
            route('admin.purchasing.shipments.show', $shipment),
        );
    }

    public function show(ImportShipment $shipment): View
    {
        $this->authorize('view', $shipment);

        return view('admin.purchasing.shipments.show', [
            'shipment' => $shipment->load(['supplier', 'closer', 'varianceEntry']),
            'summary' => $this->service->summary($shipment),
            'invoices' => $shipment->invoices()->with('supplier')->latest('id')->get(),
            'tolerance' => ImportShipmentService::VARIANCE_TOLERANCE_PCT,
        ]);
    }

    /** إغلاق الشحنة: يُقفل فرق التقدير في حساب النتيجة. */
    public function close(Request $request, ImportShipment $shipment): RedirectResponse
    {
        $this->authorize('close', $shipment);
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        return $this->guard(
            fn () => $this->service->close($shipment, $data['notes'] ?? null),
            __('أُغلقت الشحنة ورُحّل فرق التقدير.'),
        );
    }

    public function reopen(ImportShipment $shipment): RedirectResponse
    {
        $this->authorize('reopen', $shipment);

        return $this->guard(fn () => $this->service->reopen($shipment), __('أُعيد فتح الشحنة وعُكس قيد الفرق.'));
    }

    public function destroy(ImportShipment $shipment): RedirectResponse
    {
        $this->authorize('delete', $shipment);

        try {
            $this->service->delete($shipment);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.purchasing.shipments.index')->with('success', __('حُذفت الشحنة.'));
    }

    /** @return array<string, mixed> */
    private function formViewData(?ImportShipment $shipment): array
    {
        return [
            'shipment' => $shipment,
            'editing' => (bool) $shipment,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
        ];
    }

    private function guard(callable $action, string $success, ?string $to = null): RedirectResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return ($to ? redirect()->to($to) : back())->with('success', $success);
    }
}
