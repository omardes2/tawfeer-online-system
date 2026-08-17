<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Models\MarketingContact;
use App\Modules\Marketing\Services\ContactImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * جهات الاتصال التسويقية — الاستيراد والقائمة.
 *
 * **الاستيراد لا يمنح موافقة.** ما يختاره المستورِد يُسجَّل بوصفه إقرارَ تاجرٍ
 * بأساسٍ نصّي، لا موافقةَ زبون. والافتراض «غير معروفة»، ومن كان كذلك لا يُراسَل
 * — فالحارس في البيانات لا في نيّة من يضغط الزرّ.
 */
class ContactController extends Controller
{
    public function __construct(private readonly ContactImportService $importer) {}

    public function index(Request $request): View
    {
        $contacts = MarketingContact::with('customer')
            ->when($request->query('state'), fn ($q, $s) => $q->where('consent_state', $s))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('phone', 'like', '%'.$term.'%')
                ->orWhere('name', 'like', '%'.$term.'%')))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.marketing.contacts', [
            'contacts' => $contacts,
            'state' => (string) $request->query('state', ''),
            'stats' => [
                'total' => MarketingContact::count(),
                'sendable' => MarketingContact::sendable()->count(),
                'matched' => MarketingContact::whereNotNull('customer_id')->count(),
                'blocked' => MarketingContact::whereNotNull('blocked_at')->count(),
            ],
            // القناة معطّلة يُقال صراحةً: قائمةٌ جاهزة بلا ذراعٍ ترسل بها.
            'channelReady' => config('messaging.channels.whatsapp') !== 'null',
        ]);
    }

    /**
     * استيراد ملف CSV.
     *
     * ربط الأعمدة يدويّ بأرقامها: ملفّات التجّار بلا ترويسةٍ موحّدة، والتخمين
     * يضع عمود التاريخ في خانة الهاتف فيُستورَد خمسة عشر ألف صفٍّ خاطئ.
     */
    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.contacts.manage'), 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'phone_column' => ['required', 'integer', 'min:1', 'max:50'],
            'name_column' => ['nullable', 'integer', 'min:1', 'max:50'],
            'city_column' => ['nullable', 'integer', 'min:1', 'max:50'],
            'has_header' => ['nullable', 'boolean'],
            'consent_state' => ['required', Rule::in([
                MarketingContact::CONSENT_UNKNOWN,
                MarketingContact::CONSENT_IMPLIED,
                MarketingContact::CONSENT_EXPLICIT,
            ])],
            'consent_basis' => ['nullable', 'string', 'max:60'],
        ]);

        $summary = $this->importer->import(
            rows: $this->rows($request->file('file')->getRealPath(), $data),
            sourceRef: $request->file('file')->getClientOriginalName(),
            consentState: $data['consent_state'],
            consentBasis: $data['consent_basis'] ?? null,
            userId: $request->user()->id,
        );

        $message = __('استُورد :i · حُدِّث :u · مطابق لعملاء :m · مكرّر :d · مرفوض :x', [
            'i' => $summary['imported'], 'u' => $summary['updated'],
            'm' => $summary['matched'], 'd' => $summary['duplicates'], 'x' => $summary['invalid'],
        ]);

        if ($summary['samples'] !== []) {
            $message .= ' — '.__('عيّنة من المرفوض: :s', ['s' => implode('، ', $summary['samples'])]);
        }

        return back()->with($summary['imported'] + $summary['updated'] > 0 ? 'success' : 'error', $message);
    }

    /** وسمُ انسحابٍ يدوي — من طلب ألّا يُراسَل. */
    public function optOut(Request $request, MarketingContact $contact): RedirectResponse
    {
        abort_unless($request->user()->can('marketing.contacts.manage'), 403);

        $contact->update([
            'consent_state' => MarketingContact::CONSENT_OPTED_OUT,
            'consent_basis' => __('طلب الانسحاب'),
            'consent_at' => now(),
        ]);

        return back()->with('success', __('لن يُراسَل هذا الرقم بعد الآن.'));
    }

    /**
     * قراءة الملف صفًّا صفًّا.
     *
     * مولِّد لا مصفوفة: خمسة عشر ألف صفٍّ في الذاكرة دفعةً واحدة تُنهك العملية،
     * والقراءة المتدفّقة تُبقيها ثابتة مهما كبر الملف.
     *
     * @param  array<string, mixed>  $map
     * @return \Generator<int, array<string, mixed>>
     */
    private function rows(string $path, array $map): \Generator
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return;
        }

        // BOM من إكسل يلتصق بأول خلية فيُفسد أول رقم في الملف.
        $first = fgets($handle);
        if ($first !== false) {
            rewind($handle);
            if (str_starts_with($first, "\xEF\xBB\xBF")) {
                fseek($handle, 3);
            }
        }

        $skipHeader = ! empty($map['has_header']);

        while (($row = fgetcsv($handle)) !== false) {
            if ($skipHeader) {
                $skipHeader = false;

                continue;
            }

            yield [
                'phone' => $row[$map['phone_column'] - 1] ?? null,
                'name' => isset($map['name_column']) ? ($row[$map['name_column'] - 1] ?? null) : null,
                'city' => isset($map['city_column']) ? ($row[$map['city_column'] - 1] ?? null) : null,
            ];
        }

        fclose($handle);
    }
}
