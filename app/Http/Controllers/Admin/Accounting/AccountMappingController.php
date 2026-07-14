<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * إعدادات الترحيل المحاسبي (Posting Setup): تعديل الحساب المرتبط بكل وظيفة لكل نوع مستند.
 */
class AccountMappingController extends Controller
{
    /** الوظائف المعروضة لكل نوع مستند. */
    private const FUNCTIONS = [
        'sales_invoice' => [
            'receivable' => 'ذمم العملاء (طلبات التوصيل)',
            'cash' => 'الصندوق (المبيعات المباشرة)',
            'revenue' => 'إيراد المبيعات',
            'shipping_revenue' => 'إيراد الشحن',
            'tax' => 'الضريبة المستحقة',
            'cogs' => 'تكلفة البضاعة المباعة',
            'inventory' => 'المخزون',
        ],
        'purchase_invoice' => [
            'inventory' => 'المخزون',
            'payable' => 'ذمم الموردين',
            'tax' => 'ضريبة المدخلات',
        ],
    ];

    public function index(): View
    {
        $this->authorize('accounting.accounts.manage');

        // مفتاح مركّب document_type|function → account_id
        $map = AccountMapping::get()->mapWithKeys(fn ($m) => [$m->document_type.'|'.$m->function => $m->account_id])->all();

        return view('admin.accounting.posting_setup', [
            'documents' => self::FUNCTIONS,
            'map' => $map,
            'accounts' => Account::where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('accounting.accounts.manage');

        $data = $request->validate([
            'mappings' => ['array'],
            'mappings.*' => ['nullable', 'integer', 'exists:accounts,id'],
        ])['mappings'] ?? [];

        foreach ($data as $key => $accountId) {
            [$documentType, $function] = array_pad(explode('|', (string) $key, 2), 2, null);
            if (! $documentType || ! $function || ! isset(self::FUNCTIONS[$documentType][$function]) || ! $accountId) {
                continue;
            }
            AccountMapping::updateOrCreate(
                ['document_type' => $documentType, 'function' => $function],
                ['account_id' => $accountId],
            );
        }

        return back()->with('success', __('حُفظت إعدادات الترحيل المحاسبي.'));
    }
}
