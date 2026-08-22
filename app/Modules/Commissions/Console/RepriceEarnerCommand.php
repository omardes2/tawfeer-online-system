<?php

namespace App\Modules\Commissions\Console;

use App\Models\User;
use App\Modules\Catalog\Services\PriceListService;
use App\Modules\Commissions\Services\CommissionService;
use Illuminate\Console\Command;

/**
 * إعادة احتساب أرباح مسوّقٍ واحد على قائمة أسعاره.
 *
 * قائمة التاجر تُسند بعد أن يكون قد باع، فعمولاته القديمة محسوبةٌ على سعر
 * الجملة العام لا على ما يشتري به فعلًا. والفرق حقيقي: من يشتري بـ٦٥ لا يُحسب
 * ربحه كأنه اشترى بـ٨٠ — وهو سببُ وجود القائمة أصلًا.
 *
 * **يقرأ ولا يكتب افتراضيًّا.** الكتابة تحتاج `--apply` صراحةً: أمرٌ يحرّك
 * مستحقّات شخصٍ بعينه لا يجوز أن ينفّذ بالخطأ في سطرٍ واحد.
 *
 * ويُحصر بمسوّقٍ واحد لأن القائمة شخصيّة: الطلب الواحد قد يحمل عمولةً لغيره
 * لا تخضع لقائمته.
 */
class RepriceEarnerCommand extends Command
{
    protected $signature = 'commissions:reprice-earner
                            {user : بريد المسوّق أو معرّفه}
                            {--apply : تنفيذ التعديل فعلًا (بدونه عرضٌ فقط)}';

    protected $description = 'إعادة احتساب أرباح مسوّقٍ واحد على قائمة أسعاره المخصّصة';

    public function handle(CommissionService $commissions, PriceListService $prices): int
    {
        $key = (string) $this->argument('user');
        $earner = User::where('email', $key)->orWhere('id', is_numeric($key) ? (int) $key : 0)->first();

        if (! $earner) {
            $this->error("لا مستخدم بالبريد أو المعرّف «{$key}».");

            return self::FAILURE;
        }

        $list = $prices->listFor($earner);

        if (! $list) {
            $this->error("«{$earner->name}» لا قائمة أسعارٍ مفعّلة له — لا شيء يُعاد احتسابه.");
            $this->line('تُسند القائمة من: المستخدمون ← تعديل المستخدم ← قائمة الأسعار.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info("المسوّق: {$earner->name}  ·  القائمة: {$list->name}");
        $this->line('');

        $changes = $commissions->repriceForEarner($earner, $this->actor(), (bool) $this->option('apply'));

        if ($changes === []) {
            $this->info('✓ لا فروق — الأرباح محسوبة على القائمة أصلًا.');

            return self::SUCCESS;
        }

        $rows = [];
        $skipped = [];
        $total = 0.0;

        foreach ($changes as $change) {
            $entry = $change['entry'];
            $item = $change['item'];
            $label = $item->variant?->product?->name ?? $item->variant?->sku ?? '—';

            if (isset($change['skipped'])) {
                $skipped[] = [$item->order?->number ?? '—', $label, $this->reason($change['skipped'])];

                continue;
            }

            $total += $change['delta'];

            $rows[] = [
                $item->order?->number ?? '—',
                $label,
                number_format($change['cost'], 2),
                number_format($change['was'], 2),
                number_format($change['now'], 2),
                number_format($change['delta'], 2),
            ];
        }

        if ($skipped !== []) {
            $this->warn('بنودٌ متروكة:');
            $this->table(['الطلب', 'الصنف', 'السبب'], $skipped);
            $this->line('');
        }

        if ($rows !== []) {
            $this->table(['الطلب', 'الصنف', 'سعر شرائه', 'كانت', 'تصير', 'الفرق'], $rows);
            $this->line('');
            $this->line('عدد الحركات: '.count($rows).'  ·  صافي الفرق: '.number_format($total, 2));
            $this->line('');
        }

        if ($this->option('apply')) {
            $this->info('✓ نُفّذ التعديل على الحركات نفسها، وأُثبت في سجلّ التحوّلات.');
        } else {
            $this->warn('عرضٌ فقط — لم يتغيّر شيء. للتنفيذ: أضف --apply');
        }

        return self::SUCCESS;
    }

    private function reason(string $key): string
    {
        return match ($key) {
            'paid' => 'مدفوعة — سند الصرف يحمل مبلغها',
            'has_prior_adjustment' => 'عليها تعديل سابق (مرتجع) — تُراجَع يدويًّا',
            default => $key,
        };
    }

    private function actor(): ?User
    {
        return User::role('admin')->orderBy('id')->first();
    }
}
