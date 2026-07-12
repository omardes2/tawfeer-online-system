<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\AddressRequest;
use App\Modules\Crm\Models\CustomerAddress;
use App\Modules\Crm\Services\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * عناوين العميل المحفوظة (Phase 3.4): تعدّد + افتراضي واحد. المنطق في CustomerService.
 */
class AddressController extends Controller
{
    use InteractsWithCustomer;

    public function __construct(private readonly CustomerService $customers) {}

    public function index(): View
    {
        return view('storefront.account.addresses.index', [
            'addresses' => $this->currentCustomer()->addresses()->orderByDesc('is_default')->get(),
        ]);
    }

    public function store(AddressRequest $request): RedirectResponse
    {
        $this->customers->addAddress($this->currentCustomer(), $request->validated());

        return back()->with('status', __('account.address_saved'));
    }

    public function update(AddressRequest $request, CustomerAddress $address): RedirectResponse
    {
        $this->customers->updateAddress($this->currentCustomer(), $address, $request->validated());

        return back()->with('status', __('account.address_saved'));
    }

    public function destroy(CustomerAddress $address): RedirectResponse
    {
        $this->customers->removeAddress($this->currentCustomer(), $address);

        return back()->with('status', __('account.address_removed'));
    }

    public function setDefault(CustomerAddress $address): RedirectResponse
    {
        $this->customers->setDefaultAddress($this->currentCustomer(), $address);

        return back()->with('status', __('account.address_default_set'));
    }
}
