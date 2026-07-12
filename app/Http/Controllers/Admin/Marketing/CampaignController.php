<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\StoreCampaignRequest;
use App\Modules\Crm\Models\Customer;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignTemplate;
use App\Modules\Marketing\Services\CampaignService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * إدارة حملات التسويق (Phase 6 / ADR-046) — متحكّم رفيع فوق CampaignService.
 * كل انتقالات الحالة والإرسال عبر الخدمة (مستقلّة عن المزوّد). لا أسرار.
 */
class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $service) {}

    public function index(): View
    {
        return view('admin.marketing.campaigns.index', [
            'campaigns' => Campaign::query()->withCount('messages')->latest('id')->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.marketing.campaigns.form', [
            'templates' => CampaignTemplate::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(StoreCampaignRequest $request): RedirectResponse
    {
        $campaign = $this->service->create($request->validated(), $request->user());

        return redirect()->route('admin.marketing.campaigns.show', $campaign)
            ->with('success', __('تم إنشاء الحملة كمسودّة.'));
    }

    public function show(Campaign $campaign): View
    {
        return view('admin.marketing.campaigns.show', [
            'campaign' => $campaign->load('template'),
            'messages' => $campaign->messages()->latest('id')->limit(50)->get(),
            'stats' => $campaign->messages()
                ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function submit(Campaign $campaign, Request $request): RedirectResponse
    {
        $this->service->transition($campaign, 'pending_approval', $request->user());

        return back()->with('success', __('أُرسلت الحملة للاعتماد.'));
    }

    public function approve(Campaign $campaign, Request $request): RedirectResponse
    {
        $this->service->transition($campaign, 'approved', $request->user());

        return back()->with('success', __('اعتُمدت الحملة.'));
    }

    public function activate(Campaign $campaign, Request $request): RedirectResponse
    {
        $this->service->transition($campaign, 'active', $request->user());

        return back()->with('success', __('فُعّلت الحملة.'));
    }

    public function pause(Campaign $campaign, Request $request): RedirectResponse
    {
        $this->service->transition($campaign, 'paused', $request->user());

        return back()->with('success', __('أُوقفت الحملة مؤقتًا.'));
    }

    public function test(Campaign $campaign, Request $request): RedirectResponse
    {
        $data = $request->validate(['customer_id' => ['required', 'integer', 'exists:customers,id']]);
        $message = $this->service->test($campaign, Customer::findOrFail($data['customer_id']));

        return back()->with('success', __('إرسال تجريبي: :status', ['status' => $message->status]));
    }
}
