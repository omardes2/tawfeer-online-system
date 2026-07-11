<?php

namespace App\Http\Controllers\Admin\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\StoreSupplierRequest;
use App\Http\Requests\Purchasing\UpdateSupplierRequest;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\SupplierService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Supplier::class);

        $query = Supplier::query();
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }

        return view('admin.purchasing.suppliers.index', [
            'suppliers' => $query->latest('id')->paginate(20)->withQueryString(),
            'search' => $request->input('search'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Supplier::class);

        return view('admin.purchasing.suppliers.form', ['supplier' => new Supplier]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $this->service->create($request->safe()->except('contacts'), $request->validated('contacts', []));

        return redirect()->route('admin.purchasing.suppliers.index')->with('success', __('أُضيف المورد.'));
    }

    public function edit(Supplier $supplier): View
    {
        $this->authorize('update', $supplier);

        return view('admin.purchasing.suppliers.form', ['supplier' => $supplier->load('contacts')]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $contacts = $request->has('contacts') ? $request->validated('contacts', []) : null;
        $this->service->update($supplier, $request->safe()->except('contacts'), $contacts);

        return redirect()->route('admin.purchasing.suppliers.index')->with('success', __('حُدّث المورد.'));
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->authorize('delete', $supplier);

        $this->service->delete($supplier);

        return redirect()->route('admin.purchasing.suppliers.index')->with('success', __('حُذف المورد.'));
    }
}
