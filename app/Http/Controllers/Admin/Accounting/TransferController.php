<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\VoucherService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * التحويلات بين الخزائن/البنوك (Phase 7.1): صندوق↔صندوق، بنك↔بنك، صندوق↔بنك.
 * كل تحويل = قيد واحد متوازن عبر VoucherService (kind=transfer). idempotent + قابل للعكس.
 */
class TransferController extends Controller
{
    public function __construct(private readonly VoucherService $service) {}

    public function index(Request $request): View
    {
        $this->auth('view');

        $transfers = FinancialVoucher::query()->kind('transfer')
            ->with(['treasury', 'counterTreasury'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')->paginate(20)->withQueryString();

        return view('admin.accounting.transfers.index', ['transfers' => $transfers, 'filters' => $request->only(['status'])]);
    }

    public function create(): View
    {
        $this->auth('create');

        return view('admin.accounting.transfers.form', [
            'treasuries' => Treasury::active()->orderBy('type')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->auth('create');
        $data = $request->validate([
            'voucher_date' => ['required', 'date'],
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],            // المصدر
            'counter_treasury_id' => ['required', 'integer', 'different:treasury_id', 'exists:treasuries,id'], // الوجهة
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $transfer = $this->service->create('transfer', $data);

        return redirect()->route('admin.accounting.transfers.show', $transfer)->with('success', __('حُفظ التحويل كمسودّة.'));
    }

    public function show(FinancialVoucher $transfer): View
    {
        $this->auth('view');
        abort_unless($transfer->kind === 'transfer', 404);

        return view('admin.accounting.transfers.show', [
            'transfer' => $transfer->load(['treasury.glAccount', 'counterTreasury.glAccount', 'journalEntry', 'creator']),
        ]);
    }

    public function approve(FinancialVoucher $transfer): RedirectResponse
    {
        $this->auth('approve');
        abort_unless($transfer->kind === 'transfer', 404);
        $this->service->approve($transfer);

        return back()->with('success', __('اعتُمد التحويل.'));
    }

    public function cancel(FinancialVoucher $transfer): RedirectResponse
    {
        $this->auth('create');
        abort_unless($transfer->kind === 'transfer', 404);
        $this->service->cancel($transfer);

        return back()->with('success', __('أُلغي التحويل.'));
    }

    public function post(FinancialVoucher $transfer): RedirectResponse
    {
        $this->auth('post');
        abort_unless($transfer->kind === 'transfer', 404);
        $this->service->post($transfer);

        return back()->with('success', __('رُحّل التحويل.'));
    }

    public function reverse(FinancialVoucher $transfer, Request $request): RedirectResponse
    {
        $this->auth('post');
        abort_unless($transfer->kind === 'transfer', 404);
        $this->service->reverse($transfer, $request->string('reason')->toString() ?: null);

        return back()->with('success', __('عُكس التحويل.'));
    }

    private function auth(string $action): void
    {
        abort_unless(auth()->user()?->can("accounting.transfers.{$action}") ?? false, 403);
    }
}
