<?php

namespace App\Http\Controllers\Admin\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\AbandonedCheckoutOutcomeRequest;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Store\Models\CheckoutSession;
use App\Modules\Store\Services\CheckoutRecoveryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * طلبات لم تكتمل — قائمة اتصالٍ لا تقرير.
 *
 * من ملأ بياناته في الإتمام ثم تردّد: إعلانٌ دُفع ثمنه، وسلّةٌ اختيرت، وعنوانٌ
 * كُتب. مكالمةٌ واحدة تُنقذ ما أنفقته عليه بالفعل.
 */
class AbandonedCheckoutController extends Controller
{
    public function __construct(private readonly CheckoutRecoveryService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('sales.abandoned_checkouts.view');

        $range = DateRange::resolve($request->query('range', 'month'), $request->query('from'), $request->query('to'));
        $status = (string) $request->query('status', 'open');

        return view('admin.sales.abandoned_checkouts.index', [
            'rows' => $this->service->list($range, ['status' => $status]),
            'stats' => $this->service->stats($range),
            'range' => $range,
            'status' => $status,
            'statuses' => $this->statusLabels(),
            'waTemplate' => (string) Settings::get(
                'store.abandoned_checkout_message',
                __('مرحبًا :name، لاحظنا أنك لم تُكمل طلبك من :store. هل نساعدك في إتمامه؟'),
            ),
            'storeName' => (string) Settings::get('store.name', 'توفير أونلاين'),
        ]);
    }

    /** تسجيل نتيجة الاتصال. */
    public function outcome(AbandonedCheckoutOutcomeRequest $request, string $uuid): RedirectResponse
    {
        $session = CheckoutSession::where('uuid', $uuid)->firstOrFail();

        $this->service->markOutcome(
            $session,
            $request->validated('recovery_status'),
            $request->validated('recovery_note'),
            $request->user(),
        );

        return back()->with('success', __('سُجّلت نتيجة الاتصال.'));
    }

    /** @return array<string, string> */
    private function statusLabels(): array
    {
        return [
            'new' => __('لم يُتواصل معه'),
            'contacted' => __('تم الاتصال'),
            'no_answer' => __('لا يرد'),
            'refused' => __('رفض'),
            'recovered' => __('تحوّل إلى طلب'),
            'ignored' => __('تجاهُل'),
        ];
    }
}
