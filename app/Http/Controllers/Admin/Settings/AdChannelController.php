<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\AdChannelRequest;
use App\Models\User;
use App\Modules\Foundation\Models\DeliveryBusiness;
use App\Modules\Marketing\Models\AdChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * إدارة قنوات الإعلان (صفحات البيع) وربط كلٍّ منها بحساب البزنس الخاصّ بها.
 *
 * الربط هو ما يجعل إسناد الطلب آليًّا: الطلب ← منشئُه ← حسابُ بزنسه ← الصفحة.
 * ولذلك تُعرَض هنا أسماء الموظفات المرتبطات بكل حساب — ليَظهر الخطأ بالعين قبل
 * أن يظهر رقمًا مغلوطًا في تقرير.
 */
class AdChannelController extends Controller
{
    public function index(): View
    {
        $channels = AdChannel::with('deliveryBusiness')->ordered()->get();

        // موظفات كل حساب بزنس — للتحقّق البصري من صحّة الربط.
        $staff = User::whereNotNull('delivery_business_id')
            ->orderBy('name')
            ->get(['id', 'name', 'delivery_business_id'])
            ->groupBy('delivery_business_id')
            ->map(fn ($users) => $users->pluck('name')->all());

        return view('admin.settings.ad_channels', [
            'channels' => $channels,
            'businesses' => DeliveryBusiness::orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active']),
            'staff' => $staff,
        ]);
    }

    public function store(AdChannelRequest $request): RedirectResponse
    {
        AdChannel::create($this->payload($request));

        return back()->with('success', __('أُضيفت القناة.'));
    }

    public function update(AdChannelRequest $request, AdChannel $adChannel): RedirectResponse
    {
        $adChannel->update($this->payload($request));

        return back()->with('success', __('حُدِّثت القناة.'));
    }

    /**
     * الحذف ممنوع متى ارتبطت بها طلبات: اللقطة على الطلب تُفرَّغ بالحذف، فيضيع
     * إسناد مبيعات ماضية بلا رجعة. تُعطَّل القناة بدل ذلك.
     */
    public function destroy(AdChannel $adChannel): RedirectResponse
    {
        if ($adChannel->orders()->exists()) {
            return back()->with('error', __('لا يمكن حذف قناة مرتبطة بطلبات — عطّلها بدل ذلك.'));
        }

        $adChannel->delete();

        return back()->with('success', __('حُذفت القناة.'));
    }

    private function payload(AdChannelRequest $request): array
    {
        $data = $request->validated();

        // حقل ترتيبٍ فارغ يصل `null`، والعمود لا يقبله — يُترك للافتراضي بدل الانفجار.
        if (array_key_exists('sort_order', $data) && $data['sort_order'] === null) {
            unset($data['sort_order']);
        }

        // مربّع الاختيار الغائب يعني «معطّلة» — و`validated()` لا يحمله أصلًا حينها.
        return array_merge($data, ['is_active' => $request->boolean('is_active')]);
    }
}
