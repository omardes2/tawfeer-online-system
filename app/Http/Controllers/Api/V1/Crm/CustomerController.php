<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\BlockCustomerRequest;
use App\Http\Requests\Crm\MergeCustomerRequest;
use App\Http\Requests\Crm\StoreCustomerRequest;
use App\Http\Requests\Crm\StoreNoteRequest;
use App\Http\Requests\Crm\UpdateCustomerRequest;
use App\Http\Resources\Crm\CustomerResource;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);

        $query = Customer::query()->withCount('orders');
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $normalized = $this->service->normalizePhone((string) $request->string('search'));
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('primary_phone', 'like', "%{$normalized}%"));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }
        if ($request->boolean('blocked_only')) {
            $query->where('is_blocked', true);
        }

        return CustomerResource::collection($query->latest('id')->paginate($this->perPage()));
    }

    public function show(Customer $customer): CustomerResource
    {
        $this->authorize('view', $customer);

        return new CustomerResource($customer->load(['phones', 'addresses', 'contacts', 'customerNotes.author'])->loadCount('orders'));
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $customer = $this->service->create(
            $this->header($request),
            $request->validated('phones', []),
            $request->validated('addresses', []),
            $request->validated('contacts', []),
        );

        return (new CustomerResource($customer->load(['phones', 'addresses', 'contacts'])))->response()->setStatusCode(201);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $this->authorize('update', $customer);

        $customer = $this->service->update(
            $customer,
            $this->header($request),
            $request->has('phones') ? $request->validated('phones', []) : null,
            $request->has('addresses') ? $request->validated('addresses', []) : null,
            $request->has('contacts') ? $request->validated('contacts', []) : null,
        );

        return new CustomerResource($customer->load(['phones', 'addresses', 'contacts']));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        $this->service->delete($customer);

        return response()->json(['message' => __('حُذف العميل.')]);
    }

    public function addNote(StoreNoteRequest $request, Customer $customer): CustomerResource
    {
        $this->authorize('update', $customer);

        $this->service->addNote($customer, $request->validated('body'));

        return new CustomerResource($customer->load('customerNotes.author'));
    }

    public function block(BlockCustomerRequest $request, Customer $customer): CustomerResource
    {
        $this->authorize('block', $customer);

        return new CustomerResource($this->service->block($customer, $request->validated('reason')));
    }

    public function unblock(Customer $customer): CustomerResource
    {
        $this->authorize('block', $customer);

        return new CustomerResource($this->service->unblock($customer));
    }

    public function merge(MergeCustomerRequest $request, Customer $customer): CustomerResource
    {
        $this->authorize('merge', $customer);

        $target = Customer::where('uuid', $request->validated('target'))->firstOrFail();
        $merged = $this->service->merge($customer, $target);

        return new CustomerResource($merged->load(['phones', 'addresses', 'contacts']));
    }

    public function duplicates(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);

        $request->validate(['phone' => ['required', 'string']]);

        return CustomerResource::collection($this->service->findDuplicatesByPhone((string) $request->string('phone')));
    }

    private function header(Request $request): array
    {
        $data = $request->only(['name', 'primary_phone', 'email', 'source', 'category', 'tags', 'credit_limit', 'is_high_risk', 'notes']);

        // فرع العميل من فرع المستخدم المُنشئ (متعدد الفروع) — عند الإنشاء فقط.
        if ($request->isMethod('post') && $request->user()?->branch_id) {
            $data['branch_id'] = $request->user()->branch_id;
        }

        return $data;
    }
}
