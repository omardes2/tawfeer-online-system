<?php

namespace App\Modules\Catalog\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductReview;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Sales\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * استيراد تقييمات زبائن **قيلت فعلًا** من ملف CSV (آراء واتساب وما شابهها).
 *
 * ثلاثة قيود تحكم هذا المستورد، وكلّها تخدم معنًى واحدًا: أن يبقى ما يُنشر صادقًا.
 *
 * 1. **الصنف يُطابَق ولا يُخمَّن.** سطرٌ لا يدلّ على صنفٍ موجود يُرفض ويُذكر سببه.
 *    رأيٌ حقيقي عن صنفٍ مُعلَّق على صنفٍ آخر يصير مضلِّلًا رغم صدقه.
 * 2. **لكل رأي صاحبٌ حقيقي.** الزبون يُطابَق برقم هاتفه، ويُنشأ إن لم يكن مسجّلًا
 *    — فهو زبونٌ اشترى فعلًا وإن كان شراؤه سابقًا للنظام.
 * 3. **مصدر الرأي يُسجَّل.** ما لا يُربَط بطلبٍ في النظام يحمل في ملاحظة المراجعة
 *    أنه مستورد ومن أين — فيبقى أثرٌ صادق لكيفية وصوله، ولا يُقرَأ لاحقًا على
 *    أنه تقييمٌ موثَّق الشراء داخل النظام.
 *
 * والاستيراد كلّه أو لا شيء، ووضع المعاينة يحسب النتيجة دون أي كتابة — كمستورد
 * الأصناف تمامًا.
 */
class ReviewImportService
{
    public function __construct(private readonly CustomerService $customers) {}

    /** ترويسات الملف المقبولة لكل حقل (عربية أو إنجليزية، بعد تنظيف المسافات). */
    private const HEADERS = [
        'product' => ['الصنف', 'المنتج', 'رمز الصنف', 'sku', 'product', 'code'],
        'phone' => ['الهاتف', 'الجوال', 'رقم الهاتف', 'رقم الزبون', 'phone', 'mobile'],
        'name' => ['الاسم', 'اسم الزبون', 'الزبون', 'name', 'customer'],
        'rating' => ['التقييم', 'النجوم', 'rating', 'stars'],
        'title' => ['العنوان', 'title'],
        'body' => ['الرأي', 'التعليق', 'النص', 'body', 'review', 'comment'],
        'date' => ['التاريخ', 'تاريخ الرأي', 'date'],
    ];

    /**
     * تحليل الملف إلى صفوف صالحة وأخطاء مفهومة لكل سطر.
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
        if (! isset($map['product'], $map['phone'], $map['rating'])) {
            fclose($handle);
            throw ValidationException::withMessages([
                'file' => __('الملف يجب أن يحوي أعمدة «الصنف» و«الهاتف» و«التقييم» على الأقل.'),
            ]);
        }

        $rows = [];
        $errors = [];
        $seen = [];
        $line = 1;

        while (($raw = fgetcsv($handle)) !== false) {
            $line++;
            $productKey = trim((string) ($raw[$map['product']] ?? ''));
            $phone = $this->normalizePhone((string) ($raw[$map['phone']] ?? ''));

            if ($productKey === '' && $phone === '') {
                continue; // سطر فارغ — يُتجاهل بصمت.
            }

            $product = $this->findProduct($productKey);
            if (! $product) {
                $errors[] = __('سطر :l: لا صنف بالرمز أو الاسم «:p».', ['l' => $line, 'p' => $productKey]);

                continue;
            }

            if ($phone === '') {
                $errors[] = __('سطر :l: رقم هاتف الزبون مفقود — بلا صاحبٍ لا يُستورد الرأي.', ['l' => $line]);

                continue;
            }

            $rating = (int) $this->number($raw[$map['rating']] ?? null);
            if ($rating < 1 || $rating > 5) {
                $errors[] = __('سطر :l: التقييم :r خارج المدى (1 إلى 5).', ['l' => $line, 'r' => $rating]);

                continue;
            }

            // رأي واحد لكل زبون في كل منتج — القيد قائم في قاعدة البيانات،
            // ويُكشف هنا برسالة مفهومة بدل أن ينفجر الاستيراد عند الكتابة.
            $key = $product->id.':'.$phone;
            if (isset($seen[$key])) {
                $errors[] = __('سطر :l: رأي مكرّر لنفس الزبون على «:p» داخل الملف.', ['l' => $line, 'p' => $product->name]);

                continue;
            }

            $customer = $this->findCustomer($phone);
            if ($customer && ProductReview::withTrashed()
                ->where('product_id', $product->id)->where('customer_id', $customer->id)->exists()) {
                $errors[] = __('سطر :l: لهذا الزبون رأي مسجَّل على «:p» مسبقًا.', ['l' => $line, 'p' => $product->name]);

                continue;
            }

            $seen[$key] = true;
            $rows[] = [
                'line' => $line,
                'product_id' => $product->id,
                'product' => $product->name,
                'phone' => $phone,
                'name' => trim((string) ($raw[$map['name'] ?? -1] ?? '')),
                'rating' => $rating,
                'title' => trim((string) ($raw[$map['title'] ?? -1] ?? '')) ?: null,
                'body' => trim((string) ($raw[$map['body'] ?? -1] ?? '')) ?: null,
                'date' => $this->parseDate((string) ($raw[$map['date'] ?? -1] ?? '')),
                'known_customer' => $customer !== null,
            ];
        }

        fclose($handle);

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * تنفيذ الاستيراد.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{imported: int, customers_created: int, linked_to_orders: int}
     */
    public function import(array $rows, User $actor, bool $approve = false, string $source = 'واتساب'): array
    {
        $summary = ['imported' => 0, 'customers_created' => 0, 'linked_to_orders' => 0];

        DB::transaction(function () use ($rows, $actor, $approve, $source, &$summary) {
            foreach ($rows as $row) {
                $customer = $this->findCustomer($row['phone']);

                if (! $customer) {
                    // عبر الخدمة لا بإنشاءٍ مباشر: هي التي تُنشئ حساب الأستاذ
                    // للزبون وتُطبّع رقمه — وزبونٌ بلا حساب يكسر ذمّته لاحقًا.
                    $customer = $this->customers->create([
                        'name' => $row['name'] !== '' ? $row['name'] : __('زبون :p', ['p' => $row['phone']]),
                        'primary_phone' => $row['phone'],
                        'branch_id' => Branch::default()->id,
                        'source' => 'manual',
                    ]);
                    $summary['customers_created']++;
                }

                // إثبات الشراء إن وُجد: أحدث طلبٍ لهذا الزبون يضمّ هذا الصنف.
                $orderId = $this->findPurchase($customer->id, (int) $row['product_id']);
                if ($orderId) {
                    $summary['linked_to_orders']++;
                }

                $at = $row['date'] ?? now();

                $review = ProductReview::create([
                    'product_id' => $row['product_id'],
                    'customer_id' => $customer->id,
                    'order_id' => $orderId,
                    'rating' => $row['rating'],
                    'title' => $row['title'],
                    'body' => $row['body'],
                    'status' => $approve ? ProductReview::APPROVED : ProductReview::PENDING,
                    'moderated_by' => $approve ? $actor->id : null,
                    'moderated_at' => $approve ? now() : null,
                    // أثرٌ صادق للمصدر: هذا رأيٌ نُقل، لا رأيٌ كُتب في المتجر.
                    'moderation_note' => __('مستورد من :s', ['s' => $source])
                        .($orderId ? '' : ' — '.__('بلا طلب مطابق في النظام')),
                ]);

                // بعد الإنشاء لا داخله: `created_at` خارج `fillable` فيُهمَل، ويحمل
                // الرأيُ تاريخَ الاستيراد — فتظهر مئة رأي قديم دفعةً واحدة اليوم.
                $review->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

                $summary['imported']++;
            }
        });

        return $summary;
    }

    /** الصنف بالرمز أولًا ثم بالاسم الحرفي — الرمز ثابت والاسم يتغيّر. */
    private function findProduct(string $key): ?Product
    {
        if ($key === '') {
            return null;
        }

        return Product::where('sku', $key)->first() ?? Product::where('name', $key)->first();
    }

    /** أحدث طلبٍ لهذا الزبون يضمّ هذا الصنف — إثبات الشراء داخل النظام. */
    private function findPurchase(int $customerId, int $productId): ?int
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->whereHas('items.variant', fn ($q) => $q->where('product_id', $productId))
            ->latest('created_at')
            ->value('id');
    }

    /** تطبيع التخزين كما يفعل النظام في كل مكان: أرقام فقط. */
    private function normalizePhone(string $phone): string
    {
        return $this->customers->normalizePhone($phone);
    }

    /**
     * البحث عن الزبون بكل صيغ رقمه المحتملة.
     *
     * النظام يخزّن الأرقام كما أُدخلت (أرقامًا فقط)، وزبائن اللوحة مسجّلون
     * محليًّا (`0599…`) بينما تصدير واتساب يعطي الصيغة الدولية (`970599…`).
     * البحث بصيغةٍ واحدة كان سيُخفق فيُنشأ زبونٌ ثانٍ للشخص نفسه، فتتفرّق آراؤه
     * وطلباتُه على حسابين ويضيع ربطُ التقييم بشرائه.
     */
    private function findCustomer(string $digits): ?Customer
    {
        return Customer::whereIn('primary_phone', $this->phoneVariants($digits))->first();
    }

    /** @return array<int, string> */
    private function phoneVariants(string $digits): array
    {
        $variants = [$digits];
        $local = $digits;

        foreach (['00970', '00972', '970', '972'] as $prefix) {
            if (str_starts_with($local, $prefix) && strlen($local) > strlen($prefix)) {
                $local = substr($local, strlen($prefix));
                break;
            }
        }

        if ($local !== $digits) {
            $variants[] = $local;
            $variants[] = str_starts_with($local, '0') ? $local : '0'.$local;
        } elseif (str_starts_with($digits, '0')) {
            // والعكس: المخزَّن دوليًّا والملف محليّ.
            $bare = ltrim($digits, '0');
            $variants[] = '970'.$bare;
            $variants[] = '972'.$bare;
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /** تاريخ الرأي الأصلي — وإلا ظهرت المئة كلّها بتاريخ اليوم فبدت مفتعلة. */
    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        // تاريخٌ في المستقبل خطأُ إدخال — يُهمَل ويُستعمل وقت الاستيراد.
        return $date->isFuture() ? null : $date;
    }

    /**
     * مطابقة ترويسات الملف بالحقول المعروفة.
     *
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    private function mapColumns(array $header): array
    {
        $map = [];

        foreach ($header as $index => $label) {
            $clean = mb_strtolower(trim((string) $label));

            foreach (self::HEADERS as $field => $aliases) {
                if (isset($map[$field])) {
                    continue;
                }

                foreach ($aliases as $alias) {
                    if ($clean === mb_strtolower($alias)) {
                        $map[$field] = $index;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    private function number(mixed $value): float
    {
        return (float) str_replace([',', '٬', ' '], '', trim((string) $value));
    }
}
