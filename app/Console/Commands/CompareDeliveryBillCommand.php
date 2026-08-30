<?php

namespace App\Console\Commands;

use App\Modules\Sales\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * مطابقة فاتورة شركة التوصيل بطلبات النظام — **قراءة فقط**.
 *
 * ## لماذا أمرٌ لا شاشة
 *
 * الفاتورة تصل ملفًّا فيه مئات الشحنات، والمطابقة تُجرى مرّةً كل دفعة. وأخطر ما
 * فيها ليس المجموع بل **السطر الشاذّ**: شحنةٌ في الفاتورة لا طلبَ لها عندنا،
 * أو طلبٌ استلمنا مالَه ولم يُعلَّم مدفوعًا. والمجموع وحده يُخفي هذين لأنهما قد
 * يتعادلان.
 *
 * ## الأعمدة
 *
 * ```
 * COD   = المبلغ المُحصَّل من الزبون شاملًا التوصيل  ⟵ يقابل orders.total
 * Fees  = أجرة التوصيل                              ⟵ يقابل orders.shipping_total
 * Extra = رسوم إضافية (إرجاع، تأخير…)                ⟵ خارج المقارنة
 * ```
 *
 * فأساس المقارنة **COD − Fees** لأنه ما يدخل دفاترنا: `bookableTotal`. والرسوم
 * الإضافية تُطرح من الصافي المُستلم ولا تُقارَن ببندٍ عندنا — ليس لها مقابل في
 * قيمة البضاعة.
 *
 * ## Protected Delivery Integration — Do Not Modify
 *
 * لا يمسّ هذا شيئًا من مسار التوصيل: لا حمولةَ إرسالٍ ولا webhook ولا حالات ولا
 * أيّ كتابة. قراءةٌ ومقارنةٌ وطباعة.
 */
class CompareDeliveryBillCommand extends Command
{
    protected $signature = 'delivery:compare-bill
                            {file : مسار ملف CSV بأعمدة tracking,cod,fees[,extra]}
                            {--limit=40 : أقصى عدد سطورٍ تُعرض في كل جدول}
                            {--tolerance=0.01 : الفرق المُتسامَح به بالشيكل}';

    protected $description = 'يقارن فاتورة شركة التوصيل بطلبات النظام ويُظهر الفروق (قراءة فقط).';

    public function handle(): int
    {
        $path = (string) $this->argument('file');

        if (! is_readable($path)) {
            $this->error("تعذّر قراءة الملف: {$path}");

            return self::FAILURE;
        }

        $rows = $this->readCsv($path);

        if ($rows->isEmpty()) {
            $this->error('الملف فارغ أو لا يحوي أعمدة tracking/cod/fees.');

            return self::FAILURE;
        }

        $tolerance = (float) $this->option('tolerance');
        $orders = $this->ordersByTracking($rows->pluck('tracking')->all());

        $matched = collect();
        $missing = collect();
        $differing = collect();
        $unpaid = collect();

        foreach ($rows as $row) {
            $order = $orders->get($row['tracking']);

            if (! $order) {
                $missing->push($row);

                continue;
            }

            // أساس المقارنة: قيمة البضاعة بلا توصيل، من الطرفين.
            $billGoods = round($row['cod'] - $row['fees'], 2);
            $ourGoods = round((float) $order->total - (float) $order->shipping_total, 2);
            $delta = round($billGoods - $ourGoods, 2);

            $matched->push($row + ['order' => $order, 'bill_goods' => $billGoods, 'our_goods' => $ourGoods, 'delta' => $delta]);

            if (abs($delta) > $tolerance) {
                $differing->push($matched->last());
            }

            // استلمنا مالَه ولم يُعلَّم مدفوعًا: خللٌ في الحالة لا في المبلغ،
            // ولا يظهر في أيّ مجموع.
            if ($order->payment_status !== 'paid') {
                $unpaid->push($matched->last());
            }
        }

        $this->totals($rows, $matched);
        $this->table2('سطورٌ في الفاتورة بلا طلبٍ عندنا', $missing, fn ($r) => [
            $r['tracking'], number_format($r['cod'], 2), number_format($r['fees'], 2),
        ], ['رقم التتبّع', 'COD', 'Fees']);

        $this->table2('فروقٌ في قيمة البضاعة', $differing, fn ($r) => [
            $r['tracking'], $r['order']->number,
            number_format($r['bill_goods'], 2), number_format($r['our_goods'], 2),
            number_format($r['delta'], 2),
        ], ['رقم التتبّع', 'الطلب', 'الفاتورة', 'عندنا', 'الفرق']);

        $this->table2('طلباتٌ استلمنا مالها ولم تُعلَّم مدفوعة', $unpaid, fn ($r) => [
            $r['tracking'], $r['order']->number, $r['order']->payment_status,
            number_format($r['our_goods'], 2),
        ], ['رقم التتبّع', 'الطلب', 'الحالة', 'القيمة']);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, array<string, mixed>>  $matched
     */
    private function totals(Collection $rows, Collection $matched): void
    {
        $cod = round((float) $rows->sum('cod'), 2);
        $fees = round((float) $rows->sum('fees'), 2);
        $extra = round((float) $rows->sum('extra'), 2);

        $this->newLine();
        $this->info('إجماليات الفاتورة:');
        $this->table(['البند', 'المبلغ'], [
            ['عدد الشحنات', number_format($rows->count())],
            ['COD (مع التوصيل)', number_format($cod, 2)],
            ['أجرة التوصيل (Fees)', '('.number_format($fees, 2).')'],
            ['قيمة البضاعة = COD − Fees', number_format($cod - $fees, 2)],
            ['رسوم إضافية (خارج المقارنة)', '('.number_format($extra, 2).')'],
            ['الصافي المُستلم', number_format($cod - $fees - $extra, 2)],
        ]);

        $ourGoods = round((float) $matched->sum('our_goods'), 2);
        $billGoods = round((float) $matched->sum('bill_goods'), 2);

        $this->info('المطابقة على قيمة البضاعة:');
        $this->table(['البند', 'المبلغ'], [
            ['شحنات وُجد طلبها', number_format($matched->count()).' / '.number_format($rows->count())],
            ['قيمتها في الفاتورة', number_format($billGoods, 2)],
            ['قيمتها عندنا', number_format($ourGoods, 2)],
            ['الفرق', number_format($billGoods - $ourGoods, 2)],
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): array<int, string>  $map
     * @param  array<int, string>  $headers
     */
    private function table2(string $title, Collection $rows, callable $map, array $headers): void
    {
        $this->newLine();

        if ($rows->isEmpty()) {
            $this->line("✓ {$title}: لا شيء.");

            return;
        }

        $limit = (int) $this->option('limit');
        $this->warn("{$title}: {$rows->count()}");
        $this->table($headers, $rows->take($limit)->map($map)->all());

        if ($rows->count() > $limit) {
            $this->line('… و'.($rows->count() - $limit).' سطرًا أخرى (زد --limit لعرضها).');
        }
    }

    /**
     * الطلبات مفهرسةً برقم التتبّع، بجلبةٍ واحدة لا استعلامٍ لكل سطر.
     *
     * @param  array<int, string>  $trackings
     * @return Collection<string, Order>
     */
    private function ordersByTracking(array $trackings): Collection
    {
        return Order::query()
            ->whereIn('tracking_number', $trackings)
            ->get(['id', 'number', 'tracking_number', 'total', 'shipping_total', 'payment_status', 'status'])
            ->keyBy(fn (Order $o) => $this->normalize((string) $o->tracking_number));
    }

    /**
     * أرقام التتبّع تُكتب بصيغٍ مختلفة (`OmmaR-48-007441552`، `007441552`،
     * `7441552`)، فتُجرَّد إلى أرقامها بلا أصفارٍ بادئة قبل المقارنة.
     */
    private function normalize(string $value): string
    {
        preg_match('/(\d{5,})/', $value, $m);

        return ltrim($m[1] ?? preg_replace('/\D/', '', $value), '0');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function readCsv(string $path): Collection
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return collect();
        }

        $index = array_flip(array_map(fn ($h) => strtolower(trim((string) $h)), $header));
        $rows = collect();

        while (($line = fgetcsv($handle)) !== false) {
            $get = fn (string $key) => $index[$key] !== null && isset($line[$index[$key]]) ? $line[$index[$key]] : null;

            if (! isset($index['tracking'], $index['cod'], $index['fees'])) {
                break;
            }

            $tracking = $this->normalize((string) $get('tracking'));

            if ($tracking === '') {
                continue;
            }

            $rows->push([
                'tracking' => $tracking,
                'cod' => (float) $get('cod'),
                'fees' => (float) $get('fees'),
                'extra' => isset($index['extra']) ? (float) $get('extra') : 0.0,
            ]);
        }

        fclose($handle);

        return $rows;
    }
}
