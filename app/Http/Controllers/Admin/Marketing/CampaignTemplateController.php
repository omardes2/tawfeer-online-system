<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Models\CampaignTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * إدارة قوالب رسائل التسويق (Phase 6 / ADR-046) — عربي/إنجليزي.
 */
class CampaignTemplateController extends Controller
{
    public function index(): View
    {
        return view('admin.marketing.templates.index', [
            'templates' => CampaignTemplate::latest('id')->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'channel' => ['required', Rule::in(['whatsapp', 'email', 'sms', 'internal'])],
            'subject' => ['nullable', 'string', 'max:200'],
            'body_ar' => ['required', 'string', 'max:5000'],
            'body_en' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['is_active'] = true;
        $data['created_by'] = $request->user()->id;

        CampaignTemplate::create($data);

        return back()->with('success', __('تم حفظ القالب.'));
    }

    public function destroy(CampaignTemplate $template): RedirectResponse
    {
        $template->delete();

        return back()->with('success', __('تم حذف القالب.'));
    }
}
