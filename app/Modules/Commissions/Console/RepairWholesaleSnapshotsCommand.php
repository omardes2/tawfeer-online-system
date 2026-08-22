<?php

namespace App\Modules\Commissions\Console;

use App\Models\User;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Sales\Models\OrderItem;
use Illuminate\Console\Command;

/**
 * تصحيح عمولات المسوّقين المحسوبة على لقطة جملةٍ صفر.
 *
 * منتجٌ ذو مقاسات كان يولد بعمود سعر جملةٍ فارغ على متغيّراته، فتُجمَّد لقطة
 * البند صفرًا ويهبط أساس العمولة إلى **التكلفة** — والتكلفة أدنى من الجملة،
 * فالهامش أوسع والعمولة أعلى مما تستحقّ.
 *
 * **يقرأ ولا يكتب افتراضيًّا.** الكتابة تحتاج `--apply` صراحةً: أمرٌ يحرّك
 * مستحقّات أشخاص لا يجوز أن ينفّذ بالخطأ في سطرٍ واحد، ومن يصحّح يجب أن يرى
 * الأرقام قبل أن يعتمدها.
 *
 * ويُصحَّح المسوّق وحده: عمولة موظف المبيعات أساسها قيمة المبيعات لا الهامش.
 */
class RepairWholesaleSnapshotsCommand extends Command
{
    protected $signature = 'commissions:repair-wholesale-snapshots
                            {--apply : تنفيذ التصحيح فعلًا (بدونه عرضٌ فقط)}';

    protected $description = 'عرض/تصحيح عمولات المسوّقين المحسوبة على لقطة سعر جملةٍ صفر';

    public function handle(CommissionService $commissions): int
    {
        $apply = (bool) $this->option('apply');

        $items = OrderItem::with(['variant.product:id,wholesale_price', 'order:id,number,created_at'])
            ->whereHas('commissionEntries', fn ($q) => $q->where('earner_type', 'affiliate')
                ->where('entry_type', 'accrual')->whereNotIn('state', ['reversed', 'cancelled']))
            ->where(fn ($q) => $q
                // بندٌ لقطته صفر — الحالة الأصليّة.
                ->whereNull('wholesale_price_snapshot')
                ->orWhere('wholesale_price_snapshot', '<=', 0)
                // أو بندٌ عليه حركة تعديلٍ من تشغيلٍ سابق للأمر: النسخة الأولى
                // كانت تصحّح اللقطة **وتكتب تعديلًا**، فصار البند خارج الشرط
                // الأوّل بينما تعديلُه باقٍ. ولولا هذا الفرع لتُركت تلك
                // الحركات في الدفتر إلى الأبد ولا يجدها الأمر أبدًا.
                ->orWhereHas('commissionEntries', fn ($a) => $a
                    ->where('entry_type', 'adjustment')
                    ->where('rule_snapshot', 'like', '%wholesale_snapshot%')))
            ->get();

        if ($items->isEmpty()) {
            $this->info('✓ لا بنود متأثّرة — لا شيء يُصحَّح.');

            return self::SUCCESS;
        }

        $rows = [];
        $total = 0.0;
        $paidTotal = 0.0;

        $skipped = [];

        foreach ($items as $item) {
            foreach ($commissions->correctWholesaleSnapshot($item, $this->actor(), $apply) as $change) {
                $entry = $change['entry'];

                // متروكةٌ للمراجعة اليدوية — تُعرَض ولا تُحسب في الإجمالي.
                if (isset($change['skipped'])) {
                    $skipped[] = [
                        $item->order?->number ?? '—',
                        $entry->earner?->name ?? $entry->earner_id,
                        number_format($change['was'], 2),
                    ];

                    continue;
                }

                $total += $change['delta'];

                if ($entry->state === 'paid') {
                    $paidTotal += $change['delta'];
                }

                $rows[] = [
                    $item->order?->number ?? '—',
                    $entry->earner?->name ?? $entry->earner_id,
                    $entry->state,
                    number_format($change['was'], 2),
                    number_format($change['now'], 2),
                    number_format($change['delta'], 2),
                ];
            }
        }

        if ($skipped !== []) {
            $this->warn('بنودٌ عليها تعديلٌ سابق (مرتجع مثلًا) — تُركت للمراجعة اليدوية:');
            $this->table(['الطلب', 'المسوّق', 'العمولة المسجّلة'], $skipped);
            $this->line('');
        }

        if ($rows === []) {
            $this->info('✓ لا فروق تُذكر — العمولات مطابقة أصلًا.');

            return self::SUCCESS;
        }

        $this->table(['الطلب', 'المسوّق', 'الحالة', 'كانت', 'تصير', 'الفرق'], $rows);

        $this->line('');
        $this->line('عدد الحركات: '.count($rows));
        $this->line('صافي الفرق: '.number_format($total, 2));

        // المدفوع يُفصل لأنه وحده يعني مالًا خرج فعلًا — والباقي تصحيحُ رصيدٍ
        // لم يُصرف بعد.
        if (abs($paidTotal) >= 0.01) {
            $this->warn('منها على حركاتٍ **مدفوعة**: '.number_format($paidTotal, 2)
                .' — تُضاف كاسترداد بحالة eligible، ولا تُسحب من أحدٍ تلقائيًّا.');
        }

        $this->line('');

        if ($apply) {
            $this->info('✓ نُفّذ التصحيح. الحركات الأصلية باقية، والفروق مضافة كحركات تعديل منسوبةٍ إليها.');
        } else {
            $this->warn('عرضٌ فقط — لم يتغيّر شيء. للتنفيذ: php artisan commissions:repair-wholesale-snapshots --apply');
        }

        return self::SUCCESS;
    }

    /** منفّذ التصحيح للتدوين — أول مدير نظام حين يُشغَّل الأمر من الطرفية. */
    private function actor(): ?User
    {
        return User::role('admin')->orderBy('id')->first();
    }
}
