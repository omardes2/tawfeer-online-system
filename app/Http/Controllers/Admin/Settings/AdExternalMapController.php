<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\AdExternalMap;
use App\Modules\Marketing\Services\AdSpendSyncService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * ربط حملات المنصّة ومجموعاتها الإعلانية بقنوات النظام وأصنافه.
 *
 * الشاشة موجودة لأن الربط قرارُ إنسان لا خوارزمية: مطابقةُ الاسم تقترح، والخطأ
 * فيها يَنسب صرفًا إلى صنفٍ لم يُعلَن عليه — خطأٌ لا يظهر في أي رقم، فيبدو كل
 * شيء سليمًا ويكون القرار مبنيًّا على وهم.
 */
class AdExternalMapController extends Controller
{
    public function index(): View
    {
        $maps = AdExternalMap::with(['channel', 'product', 'suggestedChannel', 'suggestedProduct'])
            ->orderByDesc('last_seen_at')
            ->get();

        return view('admin.settings.ad_maps', [
            'pending' => $maps->filter(fn (AdExternalMap $m) => ! $m->isLinked() && ! $m->is_ignored)->values(),
            'linked' => $maps->filter(fn (AdExternalMap $m) => $m->isLinked())->values(),
            'ignored' => $maps->filter(fn (AdExternalMap $m) => ! $m->isLinked() && $m->is_ignored)->values(),
            'channels' => AdChannel::ordered()->get(['id', 'name']),
            'products' => Product::orderBy('name')->get(['id', 'name', 'sku']),
            'driver' => (string) config('ads.driver'),
        ]);
    }

    public function update(Request $request, AdExternalMap $adExternalMap): RedirectResponse
    {
        $isCampaign = $adExternalMap->external_type === AdExternalMap::TYPE_CAMPAIGN;

        $data = $request->validate([
            'ad_channel_id' => [Rule::requiredIf($isCampaign), 'nullable', 'integer', 'exists:ad_channels,id'],
            'product_id' => [Rule::requiredIf(! $isCampaign), 'nullable', 'integer', 'exists:products,id'],
        ]);

        $adExternalMap->update([
            'ad_channel_id' => $isCampaign ? $data['ad_channel_id'] : null,
            'product_id' => $isCampaign ? null : $data['product_id'],
            'is_ignored' => false,
        ]);

        return back()->with('success', __('حُفظ الربط.'));
    }

    /** تأكيد كل المقترحات دفعةً واحدة — الاقتراح صحيحٌ في الغالب، والمراجعة تبقى ممكنة. */
    public function acceptSuggestions(): RedirectResponse
    {
        $count = 0;

        foreach (AdExternalMap::pendingLink()->get() as $map) {
            $target = $map->external_type === AdExternalMap::TYPE_CAMPAIGN
                ? ['ad_channel_id' => $map->suggested_ad_channel_id]
                : ['product_id' => $map->suggested_product_id];

            if (reset($target) === null) {
                continue;
            }

            $map->update($target);
            $count++;
        }

        return back()->with($count > 0 ? 'success' : 'error', $count > 0
            ? __('رُبط :n عنصرًا حسب الاقتراح — راجعها في قائمة المربوط.', ['n' => $count])
            : __('لا اقتراحات جاهزة للربط.'));
    }

    /** تجاهُل مجموعةٍ لا تخصّ صنفًا (اختبار، وعي بالعلامة) فلا تبقى تُقلق بلا سبب. */
    public function toggleIgnore(AdExternalMap $adExternalMap): RedirectResponse
    {
        $adExternalMap->update(['is_ignored' => ! $adExternalMap->is_ignored]);

        return back()->with('success', $adExternalMap->is_ignored ? __('تم التجاهل.') : __('أُعيد إلى قائمة الانتظار.'));
    }

    /** سحبٌ فوري بدل انتظار المهمّة الليلية — للتجربة بعد الربط مباشرةً. */
    public function sync(Request $request, AdSpendSyncService $sync): RedirectResponse
    {
        $days = max(1, (int) ($request->input('days') ?: config('ads.sync.lookback_days', 3)));
        $to = Carbon::yesterday();

        try {
            $summary = $sync->sync($to->copy()->subDays($days - 1), $to);
        } catch (Throwable $e) {
            return back()->with('error', __('فشلت المزامنة: :m', ['m' => $e->getMessage()]));
        }

        if (! $summary['configured']) {
            return back()->with('error', __('منصّة الإعلانات غير مربوطة — اضبط بيانات الاتصال في ملف البيئة أولًا.'));
        }

        return back()->with('success', __('مُستلَم :f · مكتوب :w · غير مربوط :u · تعارض :c · معرّفات جديدة :n', [
            'f' => $summary['fetched'], 'w' => $summary['written'],
            'u' => $summary['unmapped'], 'c' => $summary['conflicts'], 'n' => $summary['new_maps'],
        ]));
    }
}
