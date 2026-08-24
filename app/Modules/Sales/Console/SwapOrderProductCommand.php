<?php

namespace App\Modules\Sales\Console;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\PriceListService;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Sales\Services\SalesPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تصحيح صنفٍ أُدخل خطأً في فواتير منفَّذة.
 *
 * موظّفٌ سجّل «عطر ٢٥٠ ملم» والمُباع فعلًا «عطر سمارت». الخطأ ليس في اسمٍ يُعرض
 * بل في **أربعة دفاتر**: البند، والمخزون (خُصم من صنفٍ لم يخرج وبقي الآخر
 * زائدًا)، وتكلفة المبيعات المُقيَّدة بتكلفة الصنف الخطأ، وعمولة المسوّق المحسوبة
 * على سعر جملته.
 *
 * ## ثلاثة قرارات
 *
 * **١. البند يُعدَّل في مكانه ولا يُحذف.** مسار تعديل الطلب القائم
 * (`OrderService::editPostedOrder`) يحذف البنود ويُنشئها من جديد، و
 * `commission_entries.order_item_id` مضبوطٌ على `nullOnDelete` — فيفقد كشفُ
 * المسوّق ربطَه بالبند إلى الأبد، ولا يجده أمرُ إعادة التسعير بعدها (يبحث عن
 * البنود عبر `whereHas('commissionEntries')`). فيُحدَّث `variant_id` على البند
 * نفسه ويبقى معرّفه.
 *
 * **٢. المال لا يتغيّر.** `unit_price` و`discount` و`line_total` وإجمالي الطلب
 * تبقى كما دُفعت: الزبون دفع مبلغًا وسنده يحمله، وتغييرُه يجعل الدفتر يخالف
 * المقبوض. ما يتغيّر **هوية البضاعة وتكلفتها** لا ثمنُها.
 *
 * **٣. الشحنات لا تُمسّ** — Protected Delivery Integration — Do Not Modify.
 * الطرود أُنشئت عند شركة التوصيل بحمولتها، وقد سُلّمت. ولا يُعاد إرسال شيء ولا
 * يُعدَّل طرد.
 *
 * ويقرأ ولا يكتب افتراضيًّا: أمرٌ يحرّك مخزونًا وقيودًا ومستحقّات لا ينفّذ بسطرٍ
 * واحد بلا مراجعة.
 */
class SwapOrderProductCommand extends Command
{
    protected $signature = 'sales:swap-order-product
                            {from : الصنف الخطأ — معرّفه أو جزء من اسمه}
                            {to : الصنف الصحيح — معرّفه أو جزء من اسمه}
                            {--earner= : حصرُ التصحيح بفواتير تحمل عمولةً لهذا المسوّق (بريده أو معرّفه)}
                            {--apply : تنفيذ التصحيح فعلًا (بدونه عرضٌ فقط)}';

    protected $description = 'تصحيح صنفٍ أُدخل خطأً في فواتير منفَّذة — مع المخزون والقيد والعمولة';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly SalesPostingService $posting,
        private readonly CommissionService $commissions,
        private readonly PriceListService $prices,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = $this->resolveVariant((string) $this->argument('from'), 'الخطأ');
        $to = $this->resolveVariant((string) $this->argument('to'), 'الصحيح');

        if (! $from || ! $to) {
            return self::FAILURE;
        }

        if ($from->id === $to->id) {
            $this->error('الصنفان واحد — لا شيء يُصحَّح.');

            return self::FAILURE;
        }

        $earner = $this->resolveEarner();

        if ($earner === false) {
            return self::FAILURE;
        }

        $items = $this->itemsToFix($from, $earner);

        if ($items->isEmpty()) {
            $this->info('✓ لا فواتير تحمل هذا الصنف ضمن النطاق المحدّد.');

            return self::SUCCESS;
        }

        $this->report($from, $to, $items, $earner);

        if (! $this->option('apply')) {
            $this->warn('عرضٌ فقط — لم يتغيّر شيء. للتنفيذ: أضف --apply');

            return self::SUCCESS;
        }

        $fixed = $this->apply($items, $from, $to);

        $this->line('');
        $this->info("✓ صُحّح {$fixed} بندًا: البند والمخزون والقيد المحاسبي.");
        $this->line('');
        $this->warn('بقيت خطوةٌ واحدة — أرباح المسوّق تُحتسب على قائمة أسعاره:');
        $this->line('  php artisan commissions:reprice-earner '
            .($earner?->id ?? '<معرّف المسوّق>').'          # عرضٌ أولًا');
        $this->line('  php artisan commissions:reprice-earner '
            .($earner?->id ?? '<معرّف المسوّق>').' --apply   # ثم التنفيذ');

        return self::SUCCESS;
    }

    /** الصنف المطلوب — ويُرفض ما له أكثر من متغيّرٍ واحد بلا تحديد. */
    private function resolveVariant(string $key, string $label): ?ProductVariant
    {
        $product = Product::when(
            is_numeric($key),
            fn ($q) => $q->where('id', (int) $key),
            fn ($q) => $q->where('name', 'like', '%'.$key.'%'),
        )->with('variants')->get();

        if ($product->isEmpty()) {
            $this->error("لا صنف يطابق «{$key}» ({$label}).");

            return null;
        }

        if ($product->count() > 1) {
            $this->error("«{$key}» يطابق أكثر من صنف ({$label}) — استعمل المعرّف:");
            $this->table(['المعرّف', 'الاسم'], $product->map(fn ($p) => [$p->id, $p->name])->all());

            return null;
        }

        $variants = $product->first()->variants;

        if ($variants->count() !== 1) {
            // المطابقة بين المتغيّرات (مقاس ← مقاس) قرارٌ لا يُخمَّن.
            $this->error("«{$product->first()->name}» له {$variants->count()} متغيّرًا — "
                .'هذا الأمر للأصناف المفردة فقط. المتعدّد يُصحَّح بندًا بندًا من شاشة الطلب.');

            return null;
        }

        return $variants->first();
    }

    /** @return User|null|false  `false` عند بريدٍ لا يطابق أحدًا */
    private function resolveEarner(): User|null|false
    {
        $key = $this->option('earner');

        if (blank($key)) {
            return null;
        }

        $earner = User::where('email', $key)
            ->orWhere('id', is_numeric($key) ? (int) $key : 0)
            ->first();

        if (! $earner) {
            $this->error("لا مستخدم بالبريد أو المعرّف «{$key}».");

            return false;
        }

        return $earner;
    }

    /**
     * البنود المرشَّحة.
     *
     * المُلغاة والمُرتجعة تُستثنى: الأولى لا أثر لها، والثانية لها حركة مرتجعٍ
     * قائمة تُخالف تصحيحًا يجري فوقها.
     *
     * @return Collection<int, OrderItem>
     */
    private function itemsToFix(ProductVariant $from, ?User $earner)
    {
        return OrderItem::with(['order.warehouse', 'variant'])
            ->where('variant_id', $from->id)
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'draft']))
            ->when($earner, fn ($q) => $q->whereHas(
                'order',
                fn ($o) => $o->where('affiliate_id', $earner->id),
            ))
            ->get();
    }

    /** @param  Collection<int, OrderItem>  $items */
    private function report(ProductVariant $from, ProductVariant $to, $items, ?User $earner): void
    {
        $this->line('');
        $this->info('التصحيح: '.($from->product?->name ?? $from->sku).'  ←  '.($to->product?->name ?? $to->sku));

        if ($earner) {
            $this->line('محصورٌ بفواتير المسوّق: '.$earner->name);
        }

        $this->line('');

        $rows = $items->map(fn (OrderItem $item) => [
            $item->order?->number ?? '—',
            $item->order?->created_at?->toDateString() ?? '—',
            rtrim(rtrim((string) $item->qty, '0'), '.'),
            number_format((float) $item->line_total, 2),
            number_format((float) $item->wholesale_price_snapshot, 2),
            number_format($this->wholesaleFor($to, $item), 2),
        ])->all();

        $this->table(
            ['الطلب', 'التاريخ', 'الكمية', 'المبلغ (لا يتغيّر)', 'جملة كانت', 'جملة تصير'],
            $rows,
        );

        $this->line('عدد البنود: '.count($rows));
        $this->line('');
        $this->line('سيُنفَّذ لكل بند: تعديل الصنف · إرجاع الكمية للصنف الخطأ وصرفها من الصحيح'
            .' · إعادة ترحيل قيد التكلفة · إعادة احتساب العمولة.');
        $this->line('ولا يُمسّ: المبلغ المدفوع، ولا الطرد عند شركة التوصيل.');
        $this->line('');
    }

    /**
     * سعر الجملة الذي يُجمَّد على البند.
     *
     * قائمة أسعار المشتري تحلّ محلّ سعر الجملة العام — كما في `syncItems` تمامًا،
     * وإلّا صار البند المُصحَّح محسوبًا بقاعدةٍ تخالف بقيّة بنود الطلب.
     */
    private function wholesaleFor(ProductVariant $to, OrderItem $item): float
    {
        $buyer = $item->order?->affiliate_id ? User::find($item->order->affiliate_id) : null;

        $listPrices = $buyer ? $this->prices->pricesFor($buyer, [$to->id]) : [];

        return (float) ($listPrices[$to->id] ?? $to->effectiveWholesalePrice());
    }

    /** @param  Collection<int, OrderItem>  $items */
    private function apply($items, ProductVariant $from, ProductVariant $to): int
    {
        $actor = User::where('email', 'admin@tawfeer.online')->first();
        $fixed = 0;

        foreach ($items as $item) {
            DB::transaction(function () use ($item, $from, $to, $actor, &$fixed) {
                $order = $item->order;
                $warehouse = $order?->warehouse;
                $shipped = (float) $item->qty_shipped;

                // ١) المخزون — بالكمية المصروفة فعلًا لا بالمطلوبة.
                if ($warehouse && $shipped > 0) {
                    $this->inventory->returnToStock($from, $warehouse, $shipped,
                        $from->average_cost !== null ? (float) $from->average_cost : null, [
                            'reference_type' => Order::class,
                            'reference_id' => $order->id,
                            'reason' => 'swap_product_return:'.$order->number,
                        ]);

                    $this->inventory->issue($to, $warehouse, $shipped, [
                        'reference_type' => Order::class,
                        'reference_id' => $order->id,
                        'reason' => 'swap_product_issue:'.$order->number,
                    ]);
                }

                // ٢) البند في مكانه — المال كما هو، والهوية والتكلفة تتغيّران.
                $wholesale = $this->wholesaleFor($to, $item);

                $item->update([
                    'variant_id' => $to->id,
                    'wholesale_cost_snapshot' => round((float) ($to->average_cost ?? 0), 2),
                    'wholesale_price_snapshot' => round($wholesale, 2),
                ]);

                // ٣) قيد الإيراد والتكلفة يُحدَّثان في مكانهما (لا قيد جديد).
                if ($order) {
                    $this->posting->repost($order->fresh(['items', 'warehouse', 'customer']));
                }

                // ٤) العمولة على الأساس الجديد — في نفس الحركة، وبأثرٍ في سجلّ التحوّلات.
                $this->commissions->recomputeItemCommissions($item->fresh(), $wholesale, $actor, true);

                $fixed++;
            });
        }

        return $fixed;
    }
}
