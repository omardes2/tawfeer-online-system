<?php

namespace App\Modules\Purchasing\Console;

use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Services\InvoiceVariantSplitService;
use Illuminate\Console\Command;

/**
 * توزيع بنود فاتورة شراء من «الصنف المجرَّد» على مقاساته — بلا لمس المخزون.
 *
 * تُدخَل الفاتورة على منتجٍ بلا مقاسات ثم تُنشأ له مقاسات، فتُصفّر مصفوفةُ
 * المتغيّرات رصيدَ المجرَّد وتنقله إليها — ويبقى بند الفاتورة مشيرًا إلى متغيّرٍ
 * رصيدُه صفر. فلا يُعدَّل، ولا يُقرأ في التقارير بالمقاس.
 *
 * **يقرأ ولا يكتب افتراضيًّا.** الكتابة تحتاج `--apply` صراحةً.
 *
 * والمخزون لا يُلمَس: الكمية موزَّعة أصلًا وصحيحة، وأي حركةٍ هنا تُضاعفها.
 */
class SplitInvoiceVariantsCommand extends Command
{
    protected $signature = 'purchasing:split-invoice-variants
                            {invoice : رقم الفاتورة أو معرّفها أو UUID}
                            {--split=* : توزيع صريح لبندٍ بعينه — itemId=variantId:qty,variantId:qty}
                            {--apply : تنفيذ التوزيع فعلًا (بدونه عرضٌ فقط)}';

    protected $description = 'توزيع بنود فاتورة الشراء من الصنف المجرَّد على مقاساته بلا تحريك المخزون';

    public function handle(InvoiceVariantSplitService $splitter): int
    {
        $invoice = $this->resolveInvoice((string) $this->argument('invoice'));

        if (! $invoice) {
            $this->error('لا فاتورة بالرقم أو المعرّف «'.$this->argument('invoice').'».');

            return self::FAILURE;
        }

        $overrides = $this->overrides();

        if ($overrides === null) {
            return self::FAILURE;
        }

        $plan = $splitter->plan($invoice, $overrides);

        if ($plan === []) {
            $this->info('لا بنود مجرَّدة في الفاتورة '.$invoice->number.' — لا شيء يُوزَّع.');

            return self::SUCCESS;
        }

        $this->render($invoice, $plan);

        $ready = array_values(array_filter($plan, fn (array $p) => $p['rows'] !== []));

        if ($ready === []) {
            $this->error('لا بند جاهز للتوزيع — راجع الملاحظات أعلاه.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->line('');
            $this->warn('عرضٌ فقط — أضف --apply للتنفيذ.');

            return self::SUCCESS;
        }

        // التوزيع المظنون لا يُكتب بلا إقرار: أرقامٌ نسبيّة تُمرَّر صامتةً تُقرأ
        // بعد شهرٍ على أنها محسوبة.
        if (array_filter($ready, fn (array $p) => ! $p['exact']) !== []
            && ! $this->confirm('بعض التوزيعات نسبيّة لا مطابِقة — أتابع؟', false)) {
            $this->line('أُلغي.');

            return self::SUCCESS;
        }

        $result = $splitter->apply($invoice, $ready);

        $this->line('');
        $this->info('تمّ: '.$result['items'].' بندًا ← '.$result['rows'].' سطرًا. المخزون لم يُلمَس، والإجماليّات كما هي.');

        return self::SUCCESS;
    }

    /** @param  array<int, array<string, mixed>>  $plan */
    private function render(PurchaseInvoice $invoice, array $plan): void
    {
        $this->line('');
        $this->info('الفاتورة: '.$invoice->number.'  ·  المورد: '.($invoice->supplier?->name ?? '—')
            .'  ·  الحالة: '.$invoice->status);

        foreach ($plan as $entry) {
            $this->line('');
            $this->line('▸ البند #'.$entry['item']->id.' — '.$entry['product']
                .'  (كمية '.$this->number($entry['qty']).'، قيمة '.number_format($entry['line_total'], 2).')');

            if ($entry['note']) {
                $entry['rows'] === [] ? $this->error('  '.$entry['note']) : $this->warn('  '.$entry['note']);
            }

            if ($entry['rows'] === []) {
                continue;
            }

            $this->table(
                ['المتغيّر', 'المقاس', 'رصيده', 'الكمية', 'القيمة', 'الضريبة', 'الواصلة'],
                array_map(fn (array $row) => [
                    $row['variant']->id,
                    $row['label'],
                    $this->number($row['stock']),
                    $this->number($row['qty']),
                    number_format($row['line_total'], 2),
                    number_format($row['tax_amount'], 2),
                    number_format($row['landed_line_total'], 2),
                ], $entry['rows']),
            );

            // المجموع مطبوعٌ صراحةً: القارئ يتحقّق بعينه أن المال لم يتغيّر بدل
            // أن يُصدّق أنه لم يتغيّر.
            $this->line('  المجموع: كمية '.$this->number(array_sum(array_column($entry['rows'], 'qty')))
                .' من '.$this->number($entry['qty'])
                .'  ·  قيمة '.number_format(array_sum(array_column($entry['rows'], 'line_total')), 2)
                .' من '.number_format($entry['line_total'], 2)
                .($entry['exact'] ? '  ✓ مطابق' : ''));
        }
    }

    /**
     * `--split=itemId=variantId:qty,variantId:qty`
     *
     * @return array<int, array<int, float>>|null
     */
    private function overrides(): ?array
    {
        $overrides = [];

        foreach ((array) $this->option('split') as $raw) {
            if (! str_contains((string) $raw, '=')) {
                $this->error('صيغة --split غير صحيحة: «'.$raw.'». المتوقّع itemId=variantId:qty,variantId:qty');

                return null;
            }

            [$itemId, $pairs] = explode('=', (string) $raw, 2);
            $distribution = [];

            foreach (explode(',', $pairs) as $pair) {
                $parts = explode(':', trim($pair));

                if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
                    $this->error('جزءٌ غير صحيح في --split: «'.$pair.'». المتوقّع variantId:qty');

                    return null;
                }

                $distribution[(int) $parts[0]] = (float) $parts[1];
            }

            $overrides[(int) trim($itemId)] = $distribution;
        }

        return $overrides;
    }

    private function resolveInvoice(string $key): ?PurchaseInvoice
    {
        return PurchaseInvoice::query()
            ->when(is_numeric($key), fn ($q) => $q->orWhere('id', (int) $key))
            ->orWhere('number', $key)
            ->orWhere('uuid', $key)
            ->with('supplier:id,name')
            ->first();
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
