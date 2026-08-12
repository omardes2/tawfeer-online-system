<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * استيراد الأصناف من ملف CSV مع **رصيد افتتاحي** للمخزون.
 *
 * لكل سطر: يُنشأ المنتج (أو يُتخطّى إن كان اسمه موجودًا) بأسعاره وفئته، وتُدخل كميته
 * إلى المستودع بتكلفة الشراء. وللاستيراد كلّه يُرحَّل **قيد واحد**:
 *   مدين «المخزون 1200» بقيمة (الكمية × سعر الشراء) / دائن «رأس المال 3010» (نفس حساب
 *   الأرصدة الافتتاحية المستخدَم لافتتاح الخزائن).
 * فيظهر المخزون أصلًا في الميزانية مقابل حقوق ملكية افتتاحية — لا كمشترياتٍ من مورد.
 *
 * الاستيراد إمّا أن ينجح كاملًا أو لا شيء (معاملة واحدة)، ووضع «المعاينة» يحسب النتيجة
 * ويعرضها دون أي كتابة.
 */
class ProductImportService
{
    /** ترويسات الملف المقبولة لكل حقل (تُطابَق بعد تنظيف المسافات). */
    private const HEADERS = [
        'name' => ['اسم الصنف', 'الصنف', 'اسم المنتج', 'name'],
        'qty' => ['الكمية', 'الكميه', 'كمية', 'qty', 'quantity'],
        'retail_price' => ['سعر البيع', 'البيع', 'retail', 'retail_price'],
        'wholesale_price' => ['سعر الجملة', 'الجملة', 'wholesale', 'wholesale_price'],
        'cost_price' => ['سعر الشراء', 'الشراء', 'التكلفة', 'cost', 'cost_price'],
        'category' => ['الفئات', 'الفئة', 'التصنيف', 'category'],
    ];

    public function __construct(
        private readonly ProductService $products,
        private readonly InventoryService $inventory,
        private readonly AccountingService $accounting,
    ) {}

    /**
     * تحليل الملف إلى صفوف نظيفة + أخطاء مفهومة لكل سطر.
     *
     * @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    public function parse(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => __('تعذّر فتح الملف.')]);
        }

        // تخطّي BOM الذي تضيفه Excel لملفات UTF-8 (وإلا تشوّهت أول ترويسة).
        if (fgets($handle, 4) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => __('الملف فارغ.')]);
        }

        $map = $this->mapColumns($header);
        if (! isset($map['name'], $map['qty'])) {
            fclose($handle);
            throw ValidationException::withMessages([
                'file' => __('الملف يجب أن يحوي عمودَي «اسم الصنف» و«الكمية» على الأقل.'),
            ]);
        }

        $rows = [];
        $errors = [];
        $seen = [];
        $line = 1;

        while (($raw = fgetcsv($handle)) !== false) {
            $line++;
            $name = trim((string) ($raw[$map['name']] ?? ''));
            if ($name === '') {
                continue; // سطر فارغ — يُتجاهل بصمت.
            }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                $errors[] = __('سطر :l: «:n» مكرّر في الملف.', ['l' => $line, 'n' => $name]);

                continue;
            }
            if (Product::where('name', $name)->exists()) {
                $errors[] = __('سطر :l: «:n» موجود مسبقًا في النظام.', ['l' => $line, 'n' => $name]);

                continue;
            }

            $qty = $this->number($raw[$map['qty']] ?? null);
            if ($qty < 0) {
                $errors[] = __('سطر :l: الكمية سالبة.', ['l' => $line]);

                continue;
            }

            $seen[$key] = true;
            $cost = $this->number($raw[$map['cost_price'] ?? -1] ?? null);
            $rows[] = [
                'line' => $line,
                'name' => $name,
                'qty' => $qty,
                'cost_price' => $cost,
                'retail_price' => $this->number($raw[$map['retail_price'] ?? -1] ?? null),
                'wholesale_price' => $this->number($raw[$map['wholesale_price'] ?? -1] ?? null),
                'category' => trim((string) ($raw[$map['category'] ?? -1] ?? '')),
                'value' => round($qty * $cost, 2),
            ];
        }

        fclose($handle);

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * تنفيذ الاستيراد: إنشاء الأصناف، إدخال الكميات، وترحيل قيد الرصيد الافتتاحي.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, value: float, entry: ?JournalEntry}
     */
    public function import(array $rows, ?Warehouse $warehouse = null): array
    {
        $warehouse ??= Warehouse::where('is_default', true)->first() ?? Warehouse::first();
        if ($warehouse === null) {
            throw ValidationException::withMessages(['file' => __('لا يوجد مستودع مُهيّأ.')]);
        }

        return DB::transaction(function () use ($rows, $warehouse) {
            $created = 0;
            $value = 0.0;

            foreach ($rows as $row) {
                $product = $this->products->create([
                    'name' => $row['name'],
                    'category_id' => $this->categoryId($row['category']),
                    'unit_id' => $this->defaultUnitId(),
                    'retail_price' => $row['retail_price'],
                    'wholesale_price' => $row['wholesale_price'],
                    'cost_price' => $row['cost_price'],
                    'status' => 'active',
                ]);

                if ($row['qty'] > 0) {
                    $this->inventory->openingStock(
                        $product->defaultVariant()->firstOrFail(),
                        $warehouse,
                        $row['qty'],
                        $row['cost_price'],
                        ['reason' => __('رصيد افتتاحي (استيراد)')],
                    );
                }

                $created++;
                $value = round($value + $row['value'], 2);
            }

            return ['created' => $created, 'value' => $value, 'entry' => $this->postOpeningEntry($value)];
        });
    }

    /** قيد الرصيد الافتتاحي: مدين المخزون / دائن الأرصدة الافتتاحية. null عند قيمة صفرية. */
    private function postOpeningEntry(float $value): ?JournalEntry
    {
        if ($value <= 0) {
            return null;
        }

        $inventory = config('accounting.purchasing.inventory_account', '1200');
        // نفس حساب الأرصدة الافتتاحية المستخدَم لافتتاح الخزائن (رأس المال) — مصدر واحد.
        $equity = config('accounting.treasury.opening_equity', '3010');

        return $this->accounting->postEntry([
            'entry_date' => now()->toDateString(),
            'description' => __('رصيد افتتاحي للمخزون (استيراد أصناف)'),
            'source' => 'system',
        ], [
            ['account_code' => $inventory, 'debit' => $value, 'credit' => 0],
            ['account_code' => $equity, 'debit' => 0, 'credit' => $value],
        ]);
    }

    /** وحدة القياس الافتراضية (العمود إلزامي على المنتجات) — تُستحدث «قطعة» عند غيابها. */
    private function defaultUnitId(): int
    {
        return Unit::orderBy('id')->value('id')
            ?? Unit::firstOrCreate(['code' => 'PCS'], ['name' => __('قطعة'), 'is_active' => true])->id;
    }

    /** الفئة بالاسم (تُنشأ إن غابت) — الملف يحمل أسماء لا معرّفات. */
    private function categoryId(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return Category::orderBy('id')->value('id')
                ?? Category::firstOrCreate(['slug' => 'uncategorized'], ['name' => __('غير مصنّف'), 'is_active' => true])->id;
        }

        return Category::firstOrCreate(
            ['name' => $name],
            ['slug' => Str::slug($name) ?: 'cat-'.uniqid(), 'is_active' => true],
        )->id;
    }

    /**
     * مطابقة ترويسات الملف بالحقول — تتحمّل اختلاف الترتيب والمسميات الشائعة.
     *
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function mapColumns(array $header): array
    {
        $map = [];
        foreach ($header as $index => $title) {
            $title = trim(preg_replace('/\s+/u', ' ', (string) $title));
            foreach (self::HEADERS as $field => $aliases) {
                if (isset($map[$field])) {
                    continue;
                }
                if (in_array($title, $aliases, true) || in_array(mb_strtolower($title), $aliases, true)) {
                    $map[$field] = $index;
                }
            }
        }

        return $map;
    }

    /** رقم من خلية قد تحوي فواصل آلاف أو مسافات أو أرقامًا عربية. */
    private function number(mixed $raw): float
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return 0.0;
        }
        $s = strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']);
        $s = str_replace([',', ' ', '&nbsp;'], '', $s);

        return is_numeric($s) ? round((float) $s, 4) : 0.0;
    }
}
