<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Services\ReviewImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * استيراد تقييمات زبائن قيلت فعلًا (آراء واتساب) من ملف CSV.
 *
 * خطوتان بلا حالة مخزَّنة كمستورد الأصناف: رفعٌ بوضع **معاينة** يعرض ما سيحدث
 * دون كتابة، ثم رفع الملف نفسه بلا معاينة للتنفيذ.
 *
 * الاعتماد المباشر يحتاج صلاحية النشر لا صلاحية التعديل: استيراد مئة رأي
 * ونشرُها فعلٌ تحريريّ، لا نقلُ بيانات.
 */
class ReviewImportController extends Controller
{
    public function __construct(private readonly ReviewImportService $service) {}

    public function form(Request $request): View
    {
        $this->guardModeration($request);

        return view('admin.catalog.reviews.import', ['result' => null]);
    }

    public function upload(Request $request): View
    {
        $this->guardModeration($request);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'preview' => ['sometimes', 'boolean'],
            'approve' => ['sometimes', 'boolean'],
            'source' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $parsed = $this->service->parse($data['file']->getRealPath());
        } catch (ValidationException $e) {
            return view('admin.catalog.reviews.import', [
                'result' => null,
                'fileError' => collect($e->errors())->flatten()->first(),
            ]);
        }

        $rows = $parsed['rows'];
        $preview = $request->boolean('preview');
        $imported = null;

        if (! $preview && $rows !== []) {
            $imported = $this->service->import(
                $rows,
                $request->user(),
                $request->boolean('approve'),
                $data['source'] ?: __('واتساب'),
            );
        }

        return view('admin.catalog.reviews.import', [
            'result' => [
                'rows' => $rows,
                'errors' => $parsed['errors'],
                'preview' => $preview,
                'imported' => $imported,
            ],
        ]);
    }

    /**
     * الاستيراد يحتاج صلاحية النشر لا الاطّلاع.
     *
     * تُفحَص الصلاحية مباشرةً لا عبر سياسة النموذج: سياسة `update` تطلب تقييمًا
     * بعينه، ولا تقييم بعدُ عند رفع الملف.
     */
    private function guardModeration(Request $request): void
    {
        abort_unless($request->user()?->can('catalog.reviews.update') ?? false, 403);
    }

    /** ملف نموذجي بالترويسات المطلوبة — أسرع من شرحها. */
    public function template(Request $request): StreamedResponse
    {
        $this->guardModeration($request);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM ليفتح Excel العربية سليمة.
            fputcsv($out, ['الصنف', 'الهاتف', 'الاسم', 'التقييم', 'الرأي', 'التاريخ']);
            fputcsv($out, ['P-DGQSPQDJ', '0599123456', 'أحمد م.', '5', 'المنتج ممتاز والتوصيل سريع', '2026-06-14']);
            fclose($out);
        }, 'reviews-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
