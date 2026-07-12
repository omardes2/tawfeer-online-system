<?php

namespace App\Http\Controllers\Admin\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\BlockCustomerRequest;
use App\Http\Requests\Crm\StoreCustomerRequest;
use App\Http\Requests\Crm\StoreNoteRequest;
use App\Http\Requests\Crm\UpdateCustomerRequest;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Customer::class);

        $query = Customer::query()->withCount('orders');
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $normalized = $this->service->normalizePhone((string) $request->string('search'));
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('primary_phone', 'like', "%{$normalized}%"));
        }

        return view('admin.crm.customers.index', [
            'customers' => $query->latest('id')->paginate(20)->withQueryString(),
            'search' => $request->input('search'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Customer::class);

        return view('admin.crm.customers.form', ['customer' => new Customer]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $this->authorize('create', Customer::class);

        $data = $request->safe()->except(['phones', 'addresses', 'contacts']);
        if ($request->user()->branch_id) {
            $data['branch_id'] = $request->user()->branch_id;
        }

        $customer = $this->service->create($data, $request->validated('phones', []), $request->validated('addresses', []), $request->validated('contacts', []));

        return redirect()->route('admin.crm.customers.show', $customer)->with('success', __('أُضيف العميل.'));
    }

    public function edit(Customer $customer): View
    {
        $this->authorize('update', $customer);

        return view('admin.crm.customers.form', ['customer' => $customer->load(['phones', 'addresses', 'contacts'])]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $this->service->update(
            $customer,
            $request->safe()->except(['phones', 'addresses', 'contacts']),
            $request->has('phones') ? $request->validated('phones', []) : null,
            $request->has('addresses') ? $request->validated('addresses', []) : null,
            $request->has('contacts') ? $request->validated('contacts', []) : null,
        );

        return redirect()->route('admin.crm.customers.show', $customer)->with('success', __('حُدّث العميل.'));
    }

    public function show(Customer $customer): View
    {
        $this->authorize('view', $customer);

        return view('admin.crm.customers.show', [
            'customer' => $customer->load(['phones', 'addresses', 'contacts', 'customerNotes.author', 'orders']),
        ]);
    }

    public function addNote(StoreNoteRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $this->service->addNote($customer, $request->validated('body'));

        return back()->with('success', __('أُضيفت الملاحظة.'));
    }

    public function block(BlockCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorize('block', $customer);

        $this->service->block($customer, $request->validated('reason'));

        return back()->with('success', __('حُظر العميل.'));
    }

    public function unblock(Customer $customer): RedirectResponse
    {
        $this->authorize('block', $customer);

        $this->service->unblock($customer);

        return back()->with('success', __('رُفع الحظر.'));
    }
}
