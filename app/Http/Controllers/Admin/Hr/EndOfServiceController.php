<?php

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Hr\Services\EndOfServiceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * صرف مكافأة نهاية الخدمة — مرّةً في نهاية السنة، بيد إنسان.
 *
 * ## لماذا شاشةٌ مستقلّة
 *
 * المخصّص يتراكم شهرًا بشهر مع كل مسيّر لأنه التزامٌ ينشأ بالعمل، لكنّه **لا
 * يُصرف مع الراتب**. وصرفُه من ملفّ كل موظف على حدة يجعل عمليّةً واحدة في
 * السنة اثني عشر فتحًا لصفحاتٍ متفرّقة، فيُنسى واحدٌ ويبقى مخصّصُه معلّقًا بلا
 * أن يُنتبه له. فهذه الشاشة تجمع الجميع في جدولٍ واحد بأرصدتهم.
 *
 * ## والصرف يدويّ لا آليّ
 *
 * لا موعد مُبرمَج يصرف من تلقائه، ولا تحويل يُرسله النظام. المبلغ يُسلَّم
 * باليد، ثم يُسجَّل هنا فيُقيَّد سندُ صرفٍ لكل موظف ويُطفأ مخصّصه بقيمته.
 * وصرفٌ آليّ يُخرج في الدفتر نقدًا لم يُسلَّم بعد، فيُقرأ الصندوق أفقر ممّا
 * هو، ويبقى الموظف دائنًا في الواقع مصفًّى في الدفتر.
 */
class EndOfServiceController extends Controller
{
    public function __construct(private readonly EndOfServiceService $endOfService) {}

    public function index(Request $request): View
    {
        $this->authorize('hr.payroll.view');

        $year = (int) ($request->integer('year') ?: today()->year);
        $rows = $this->endOfService->yearEndRows($year);

        return view('admin.hr.end_of_service.index', [
            'year' => $year,
            'rows' => $rows,
            'treasuries' => Treasury::active()->orderBy('name')->get(['id', 'name']),
            'totals' => [
                'balance' => round((float) $rows->sum('balance'), 2),
                'accrued' => round((float) $rows->sum('accrued'), 2),
                'settled' => round((float) $rows->sum('settled'), 2),
                'due' => $rows->where('balance', '>', 0)->count(),
            ],
        ]);
    }

    public function settle(Request $request): RedirectResponse
    {
        $this->authorize('hr.payroll.manage');

        $data = $request->validate([
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
            'note' => ['nullable', 'string', 'max:255'],
            // لا `required`: من يُرسل بلا اختيارٍ يستحقّ رسالةً مفهومة لا
            // «حقل amounts مطلوب».
            'amounts' => ['nullable', 'array'],
            'amounts.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $count = $this->endOfService->settleMany(
                $data['amounts'] ?? [],
                (int) $data['treasury_id'],
                $request->user(),
                $data['note'] ?? null,
            );
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' · '))->withInput();
        }

        return back()->with('success', __('صُرفت مكافأة نهاية الخدمة لـ:c موظفًا — سندُ صرفٍ لكلٍّ منهم، والمخصّص أُطفئ بقيمتها.', [
            'c' => $count,
        ]));
    }
}
