<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\AccountingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

class AccountingController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function accounts(): View
    {
        abort_unless(request()->user()?->can('accounting.accounts.view'), 403);

        return view('admin.accounting.accounts.index', [
            'accounts' => $this->accountTree(),
        ]);
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
