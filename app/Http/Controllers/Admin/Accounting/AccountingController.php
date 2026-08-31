<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\AccountRequest;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\AccountService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AccountingController extends Controller
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly AccountService $accounts,
    ) {}

    public function accounts(): View
    {
        abort_unless(request()->user()?->can('accounting.accounts.view'), 403);

        $tree = $this->accountTree();

        return view('admin.accounting.accounts.index', [
            'accounts' => $tree,
            // الآباء وحدهم صالحون لاستقبال فرعٍ جديد: النوع يُورَث منهم،
            // والرمز يُبنى على رمزهم.
            'parents' => $tree,
            'canManage' => request()->user()?->can('accounting.accounts.manage') ?? false,
        ]);
    }

    /** إضافة بندٍ إلى الدليل — النوع يُورَث والرمز يُقترح، راجع `AccountService`. */
    public function storeAccount(AccountRequest $request): RedirectResponse
    {
        abort_unless($request->user()?->can('accounting.accounts.manage'), 403);

        try {
            $account = $this->accounts->create($request->validated());
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('admin.accounting.accounts.index')
            ->with('success', __('أُضيف الحساب :code — :name.', ['code' => $account->code, 'name' => $account->name]));
    }

    /** تعديل الاسم/العملة/التفعيل — لا الرمز ولا النوع ولا الأب. */
    public function updateAccount(AccountRequest $request, Account $account): RedirectResponse
    {
        abort_unless($request->user()?->can('accounting.accounts.manage'), 403);

        try {
            $this->accounts->update($account, $request->validated());
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('admin.accounting.accounts.index')->with('success', __('حُدّث الحساب.'));
    }

    /** حذف بندٍ لم يتحرّك ولا فروعَ له. */
    public function destroyAccount(Account $account): RedirectResponse
    {
        abort_unless(request()->user()?->can('accounting.accounts.manage'), 403);

        try {
            $this->accounts->delete($account);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.accounting.accounts.index')->with('success', __('حُذف الحساب.'));
    }

    /**
     * ترتيب دليل الحسابات هرميًا: كل حساب رئيسي يليه فروعه (مع عمق للمسافة البادئة).
     * يعرض الحسابات الرئيسية (بلا أب) في مستوى واحد، والفرعية مُزاحة تحت أبيها.
     *
     * @return Collection<int, Account>
     */
    private function accountTree(): Collection
    {
        $byParent = Account::orderBy('code')->get()->groupBy('parent_id');
        $ordered = collect();
        $seen = [];

        $walk = function ($parentId, int $depth) use (&$walk, $byParent, $ordered, &$seen): void {
            foreach ($byParent->get($parentId, collect()) as $account) {
                $account->depth = $depth;
                $ordered->push($account);
                $seen[$account->id] = true;
                $walk($account->id, $depth + 1);
            }
        };
        $walk(null, 0);

        // أي حساب أبوه مفقود (يتيم) يُعرض في المستوى الأعلى حتى لا يختفي.
        foreach ($byParent->flatten() as $account) {
            if (empty($seen[$account->id])) {
                $account->depth = 0;
                $ordered->push($account);
                $seen[$account->id] = true;
            }
        }

        return $ordered;
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
