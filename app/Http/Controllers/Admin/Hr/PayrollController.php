<?php

namespace App\Http\Controllers\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Hr\Models\EmployeeProfile;
use App\Modules\Hr\Models\PayrollLine;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * مسيّرات الرواتب — توليدٌ فترحيلٌ فصرف.
 *
 * متحكّم رفيع: كل قاعدةٍ محاسبية في `PayrollService`. وما هنا هو تحويل الخطأ
 * إلى رسالةٍ يفهمها المستخدم بدل صفحة ٤٢٢.
 */
class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $service) {}

    public function index(): View
    {
        $this->authorize('hr.payroll.view');

        return view('admin.hr.payroll.index', [
            'runs' => PayrollRun::withCount('lines')->orderByDesc('period_year')
                ->orderByDesc('period_month')->paginate(24),
            'year' => (int) today()->year,
            'month' => (int) today()->month,
            // من بلا عقدٍ ساري لا يدخل المسيّر — يُعرَض العدد كي لا يُكتشف بعد الترحيل.
            'withoutSalary' => EmployeeProfile::active()
                ->whereDoesntHave('salaries', fn ($q) => $q->whereDate('effective_from', '<=', today()))
                ->count(),
        ]);
    }

    public function show(PayrollRun $payroll): View
    {
        $this->authorize('hr.payroll.view');

        $payroll->load(['lines.profile.user', 'lines.voucher:id,number,status', 'creator', 'poster']);

        return view('admin.hr.payroll.show', [
            'run' => $payroll,
            'lines' => $payroll->lines->sortBy(fn (PayrollLine $l) => $l->profile?->user?->name)->values(),
            'treasuries' => Treasury::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $this->authorize('hr.payroll.manage');

        $data = $request->validate([
            'period_year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        try {
            $run = $this->service->generate(
                (int) $data['period_year'],
                (int) $data['period_month'],
                $request->user(),
            );
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.hr.payroll.show', $run)
            ->with('success', __('وُلّد مسيّر :p مسودّةً — راجعه قبل الترحيل.', ['p' => $run->periodLabel()]));
    }

    public function updateLine(Request $request, PayrollRun $payroll, PayrollLine $line): RedirectResponse
    {
        $this->authorize('hr.payroll.manage');

        abort_unless($line->payroll_run_id === $payroll->id, 404);

        $data = $request->validate([
            'other_additions' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'other_deductions' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->service->updateLine($line, $data);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', __('حُدّث البند.'));
    }

    public function post(Request $request, PayrollRun $payroll): RedirectResponse
    {
        $this->authorize('hr.payroll.manage');

        try {
            $this->service->post($payroll, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', __('رُحّل المسيّر: مصروف الرواتب مدين ورواتب مستحقة دائن.'));
    }

    public function pay(Request $request, PayrollRun $payroll): RedirectResponse
    {
        $this->authorize('hr.payroll.manage');

        $data = $request->validate([
            'treasury_id' => ['required', 'integer', 'exists:treasuries,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['integer'],
        ]);

        try {
            $count = $this->service->pay(
                $payroll,
                array_map('intval', $data['lines']),
                (int) $data['treasury_id'],
                $request->user(),
            );
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with(
            $count > 0 ? 'success' : 'error',
            $count > 0
                ? __('صُرف :c راتبًا بسندِ صرفٍ لكلٍّ منها.', ['c' => $count])
                : __('لا بنود قابلة للصرف في ما اخترته — قد تكون صُرفت قبل قليل.'),
        );
    }

    public function reverse(Request $request, PayrollRun $payroll): RedirectResponse
    {
        $this->authorize('hr.payroll.manage');

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $this->service->reverse($payroll, $request->user(), $data['reason'] ?? null);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', __('عُكس المسيّر بقيدٍ عاكس — القيد الأصلي باقٍ في الدفتر.'));
    }

    public function destroy(PayrollRun $payroll): RedirectResponse
    {
        $this->authorize('hr.payroll.manage');

        // المُرحَّل مستندٌ محاسبيّ: يُصحَّح بالعكس لا بالحذف.
        if (! $payroll->isDraft()) {
            return back()->with('error', __('المسيّر المُرحَّل لا يُحذف — اعكسه.'));
        }

        $payroll->lines()->delete();
        $payroll->delete();

        return redirect()->route('admin.hr.payroll.index')->with('success', __('حُذفت المسودّة.'));
    }
}
