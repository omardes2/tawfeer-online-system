<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductImportService;
use App\Modules\Foundation\Models\Warehouse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * استيراد الأصناف من ملف CSV مع رصيد افتتاحي للمخزون.
 *
 * خطوتان بلا حالة مخزَّنة: رفع بوضع **معاينة** يعرض ما سيحدث دون كتابة، ثم رفع الملف
 * نفسه بلا معاينة لتنفيذ الاستيراد. أبسط وأأمن من تخزين آلاف الصفوف في الجلسة.
 */
class ProductImportController extends Controller
{
    public function __construct(private readonly ProductImportService $service) {}

    public function form(): View
    {
        $this->authorize('create', Product::class);

        return view('admin.catalog.products.import', [
            'result' => null,
            'warehouse' => Warehouse::where('is_default', true)->first() ?? Warehouse::first(),
        ]);
    }

    public function upload(Request $request): View
    {
        $this->authorize('create', Product::class);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'preview' => ['sometimes', 'boolean'],
        ]);

        try {
            $parsed = $this->service->parse($data['file']->getRealPath());
        } catch (ValidationException $e) {
            return view('admin.catalog.products.import', [
                'result' => null,
                'warehouse' => Warehouse::where('is_default', true)->first(),
                'fileError' => collect($e->errors())->flatten()->first(),
            ]);
        }

        $rows = $parsed['rows'];
        $preview = $request->boolean('preview');
        $imported = null;

        if (! $preview && $rows !== []) {
            $imported = $this->service->import($rows);
        }

        return view('admin.catalog.products.import', [
            'warehouse' => Warehouse::where('is_default', true)->first(),
            'result' => [
                'rows' => $rows,
                'errors' => $parsed['errors'],
                'preview' => $preview,
                'value' => round(array_sum(array_column($rows, 'value')), 2),
                'imported' => $imported,
            ],
        ]);
    }
}
