<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Foundation\Services\SystemSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * إعدادات النظام (Production) — عام/OpenAI/بريد/واتساب/توصيل/SEO/نظام.
 * ديناميكية من قاعدة البيانات؛ الأسرار مُشفَّرة. RTL + عربي/إنجليزي.
 *
 * ملاحظة: أسماء الحقول بشرطة سفلية (PHP يحوّل النقاط في مفاتيح POST) ثم تُربط بمفاتيح
 * الإعدادات المنقّطة عبر FIELD_MAP.
 */
class SettingsController extends Controller
{
    /** حقل النموذج (underscore) => مفتاح الإعداد (dotted). */
    private const FIELD_MAP = [
        'store_name' => 'store.name',
        'store_company_info' => 'store.company_info',
        'openai_enabled' => 'openai.enabled',
        'openai_model' => 'openai.model',
        'openai_key' => 'openai.key',
        'mail_host' => 'mail.host',
        'mail_port' => 'mail.port',
        'mail_username' => 'mail.username',
        'mail_encryption' => 'mail.encryption',
        'mail_from_address' => 'mail.from_address',
        'mail_from_name' => 'mail.from_name',
        'mail_password' => 'mail.password',
        'whatsapp_enabled' => 'whatsapp.enabled',
        'whatsapp_endpoint' => 'whatsapp.endpoint',
        'whatsapp_token' => 'whatsapp.token',
        'delivery_default_fee' => 'delivery.default_fee',
        'delivery_free_threshold' => 'delivery.free_threshold',
        'delivery_cod_treasury_id' => 'delivery.cod_treasury_id',
        'opost_base_url' => 'opost.base_url',
        'opost_api_key' => 'opost.api_key',
        'opost_webhook_secret' => 'opost.webhook_secret',
        'seo_meta_title' => 'seo.meta_title',
        'seo_meta_description' => 'seo.meta_description',
        'system_maintenance' => 'system.maintenance',
        'system_timezone' => 'system.timezone',
        'system_currency' => 'system.currency',
        'system_locale' => 'system.locale',
    ];

    private const BOOLEANS = ['openai_enabled', 'whatsapp_enabled', 'system_maintenance'];

    public function __construct(private readonly SystemSettingsService $service) {}

    public function edit(): View
    {
        // القيم للعرض مفترَسة بمفاتيح النموذج (underscore).
        $dotted = $this->service->values(array_merge(array_values(self::FIELD_MAP), SystemSettingsService::FILES));
        $values = collect(self::FIELD_MAP)->mapWithKeys(fn ($dot, $field) => [$field => $dotted[$dot] ?? null])->all();
        $values['store_logo'] = $dotted['store.logo'] ?? null;
        $values['store_favicon'] = $dotted['store.favicon'] ?? null;

        return view('admin.settings.edit', [
            'values' => $values,
            'timezones' => timezone_identifiers_list(),
            // خزائن نشطة لاختيار وجهة تحصيلات COD (عند in_accounting).
            'treasuries' => Treasury::active()
                ->whereNotNull('gl_account_id')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'store_name' => ['nullable', 'string', 'max:150'],
            'store_company_info' => ['nullable', 'string', 'max:2000'],
            'store_logo' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'store_favicon' => ['nullable', 'file', 'mimes:png,ico', 'max:512'],
            'openai_enabled' => ['nullable', 'boolean'],
            'openai_model' => ['nullable', 'string', 'max:60'],
            'openai_key' => ['nullable', 'string', 'max:200'],
            'mail_host' => ['nullable', 'string', 'max:150'],
            'mail_port' => ['nullable', 'integer', 'between:1,65535'],
            'mail_username' => ['nullable', 'string', 'max:150'],
            'mail_encryption' => ['nullable', Rule::in(['tls', 'ssl', 'null'])],
            'mail_from_address' => ['nullable', 'email', 'max:150'],
            'mail_from_name' => ['nullable', 'string', 'max:150'],
            'mail_password' => ['nullable', 'string', 'max:200'],
            'whatsapp_enabled' => ['nullable', 'boolean'],
            'whatsapp_endpoint' => ['nullable', 'url', 'max:250'],
            'whatsapp_token' => ['nullable', 'string', 'max:300'],
            'delivery_default_fee' => ['nullable', 'numeric', 'min:0'],
            'delivery_free_threshold' => ['nullable', 'numeric', 'min:0'],
            'delivery_cod_treasury_id' => ['nullable', 'integer', 'exists:treasuries,id'],
            'opost_base_url' => ['nullable', 'url', 'max:250'],
            'opost_api_key' => ['nullable', 'string', 'max:200'],
            'opost_webhook_secret' => ['nullable', 'string', 'max:200'],
            'seo_meta_title' => ['nullable', 'string', 'max:70'],
            'seo_meta_description' => ['nullable', 'string', 'max:180'],
            'system_maintenance' => ['nullable', 'boolean'],
            'system_timezone' => ['nullable', 'timezone'],
            'system_currency' => ['nullable', 'string', 'size:3'],
            'system_locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);

        // بناء خريطة (مفتاح الإعداد المنقّط => القيمة) مع توحيد القيم المنطقية.
        $data = [];
        foreach (self::FIELD_MAP as $field => $dot) {
            $data[$dot] = in_array($field, self::BOOLEANS, true)
                ? $request->boolean($field)
                : ($validated[$field] ?? null);
        }

        $files = [
            'store.logo' => $request->file('store_logo'),
            'store.favicon' => $request->file('store_favicon'),
        ];

        $this->service->save($data, $files);

        return back()->with('success', __('حُفظت الإعدادات.'));
    }
}
