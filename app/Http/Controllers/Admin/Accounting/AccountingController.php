<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\AccountingService;
use Illuminate\Contracts\View\View;

class AccountingController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function accounts(): View
    {
        abort_unless(request()->user()?->can('accounting.accounts.view'), 403);

        return view('admin.accounting.accounts.index', [
            'accounts' => Account::orderBy('code')->get(),
        ]);
    }

    public function trialBalance(): View
    {
        abort_unless(request()->user()?->can('accounting.reports.view'), 403);

        $rows = $this->accounting->trialBalance(FiscalYear::open()->first());

        return view('admin.accounting.reports.trial-balance', [
            'rows' => $rows,
            'totalDebit' => round($rows->sum('debit'), 2),
            'totalCredit' => round($rows->sum('credit'), 2),
        ]);
    }
}
