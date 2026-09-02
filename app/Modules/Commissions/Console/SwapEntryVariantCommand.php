<?php

namespace App\Modules\Commissions\Console;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commissions\Services\CommissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * تبديل صنف حركات عمولة مسوّقٍ واحد من صنفٍ إلى آخر — وإعادة الاحتساب عليه.
 *
 * صنفٌ بِيع باسمٍ ثم صار يُباع باسمٍ آخر: كشف المسوّق يبقى على الاسم القديم
 * فيُقرأ صنفين وهو صنفٌ واحد، ويُحسب ربحه على سعر جملةٍ لم يعد قائمًا.
 *
 * **يقرأ ولا يكتب افتراضيًّا.** الكتابة تحتاج `--apply` صراحةً: أمرٌ يحرّك
 * مستحقّات شخصٍ بعينه لا يجوز أن ينفّذ بالخطأ في سطرٍ واحد.
 *
 * ولا يمسّ إلا `commission_entries`: الفاتورة والمخزون والإيراد وتكلفة
 * المبيعات وقيود اليومية تبقى كما هي — البضاعة خرجت على الصنف القديم فعلًا.
 */
class SwapEntryVariantCommand extends Command
{
    protected $signature = 'commissions:swap-entry-variant
                            {earner : بريد المسوّق أو معرّفه}
                            {from : الصنف القديم — معرّف منتج أو متغيّر أو SKU أو جزء من الاسم}
                            {to : الصنف الجديد — مثلها، ويجب أن يُطابق متغيّرًا واحدًا}
                            {--apply : تنفيذ التعديل فعلًا (بدونه عرضٌ فقط)}';

    protected $description = 'تبديل صنف حركات عمولة مسوّق وإعادة احتسابها على سعر جملة الصنف الجديد';

    public function handle(CommissionService $commissions): int
    {
        $earner = $this->resolveEarner((string) $this->argument('earner'));

        if (! $earner) {
            $this->error('لا مستخدم بالبريد أو المعرّف «'.$this->argument('earner').'».');

            return self::FAILURE;
        }

        $from = $this->resolveVariants((string) $this->argument('from'));
        $to = $this->resolveVariants((string) $this->argument('to'));

        if ($from->isEmpty() || $to->isEmpty()) {
            $this->error('لم يُطابَق صنف — راجع المعرّف أو الاسم.');

            return self::FAILURE;
        }

        // الوجهة واحدة قطعًا: منتجٌ بمقاسات يعطي متغيّراتٍ عدّة، واختيارُ أحدها
        // آليًّا يُسند الحركات إلى مقاسٍ لم يُبَع.
        if ($to->count() > 1) {
            $this->error('«'.$this->argument('to').'» يطابق '.$to->count().' متغيّرات — حدّد واحدًا بمعرّفه أو SKU:');
            $this->table(['المعرّف', 'SKU', 'المنتج'], $to->map(fn ($v) => [
                $v->id, $v->sku, $v->product?->name ?? '—',
            ])->all());

            return self::FAILURE;
        }

        $target = $to->first();
        $fromIds = $from->pluck('id')->all();

        if (in_array($target->id, $fromIds, true)) {
            $this->error('الصنف القديم والجديد واحد — لا شيء يُبدَّل.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('المسوّق: '.$earner->name);
        $this->line('من: '.$from->map(fn ($v) => ($v->product?->name ?? $v->sku).' #'.$v->id)->implode('، '));
        $this->line('إلى: '.($target->product?->name ?? $target->sku).' #'.$target->id
            .'  ·  سعر جملته: '.number_format($target->effectiveWholesalePrice(), 2));
        $this->line('');

        try {
            $changes = $commissions->swapEntryVariant($earner, $fromIds, $target, $this->actor(), (bool) $this->option('apply'));
        } catch (ValidationException $e) {
            $this->error($e->validator->errors()->first());

            return self::FAILURE;
        }

        if ($changes === []) {
            $this->info('✓ لا حركات لهذا المسوّق على الصنف القديم.');

            return self::SUCCESS;
        }

        $this->render($changes);

        if ($this->option('apply')) {
            $this->info('✓ نُفّذ التبديل، وأُثبت في سجلّ التحوّلات. الفواتير والمخزون والقيود لم تُمَسّ.');
        } else {
            $this->warn('عرضٌ فقط — لم يتغيّر شيء. للتنفيذ: أضف --apply');
        }

        return self::SUCCESS;
    }

    /** @param  array<int, array<string, mixed>>  $changes */
    private function render(array $changes): void
    {
        $rows = [];
        $relabelled = [];
        $total = 0.0;

        foreach ($changes as $change) {
            $entry = $change['entry'];
            $order = $entry->order?->number ?? '—';

            if ($change['relabel_only']) {
                $relabelled[] = [$order, number_format($change['was'], 2), $this->reason($change['reason'] ?? null)];

                continue;
            }

            $total += $change['delta'];
            $rows[] = [
                $order,
                number_format($change['basis'], 2),
                number_format($change['was'], 2),
                number_format($change['now'], 2),
                number_format($change['delta'], 2),
            ];
        }

        if ($relabelled !== []) {
            $this->warn('حركاتٌ يُبدَّل وسمُها ولا يتغيّر مبلغها:');
            $this->table(['الطلب', 'المبلغ كما هو', 'السبب'], $relabelled);
            $this->line('');
        }

        if ($rows !== []) {
            $this->table(['الطلب', 'الهامش الجديد', 'كانت', 'تصير', 'الفرق'], $rows);
            $this->line('');
            $this->line('عدد الحركات المُعاد احتسابها: '.count($rows).'  ·  صافي الفرق: '.number_format($total, 2));
            $this->line('');
        }
    }

    private function reason(?string $key): string
    {
        return match ($key) {
            'paid' => 'مدفوعة — سند الصرف يحمل مبلغها',
            'fixed' => 'عمولة ثابتة — لا تتعلّق بالهامش',
            'has_prior_adjustment' => 'عليها تعديل سابق (مرتجع) — تُراجع يدويًّا',
            'no_order_item' => 'لا بند طلبٍ مرتبط',
            default => 'الوسم فقط',
        };
    }

    private function resolveEarner(string $key): ?User
    {
        return User::where('email', $key)
            ->orWhere('id', is_numeric($key) ? (int) $key : 0)
            ->first();
    }

    /**
     * معرّف متغيّر، أو SKU، أو معرّف منتج، أو جزءٌ من اسمه.
     *
     * الاسم مقبولٌ لأن الصنف يُعرَف به في الشاشة لا بمعرّفه، والعرض التجريبي
     * يطبع ما طابقه قبل أي كتابة — فالتوسيع هنا لا يُخفي شيئًا.
     *
     * @return Collection<int, ProductVariant>
     */
    private function resolveVariants(string $key): Collection
    {
        // `wholesale_price` في التحميل لا الاسم وحده: بدونه يقرأ
        // `effectiveWholesalePrice()` صفرًا من علاقةٍ منقوصة، فيُرفض التبديل
        // بحجّة «سعر جملةٍ صفر» والكرت سليم.
        $q = ProductVariant::with('product:id,name,wholesale_price');

        if (is_numeric($key)) {
            $id = (int) $key;
            $found = (clone $q)->where('id', $id)->get();

            return $found->isNotEmpty() ? $found : (clone $q)->where('product_id', $id)->get();
        }

        $bySku = (clone $q)->where('sku', $key)->get();

        return $bySku->isNotEmpty()
            ? $bySku
            : (clone $q)->whereHas('product', fn ($p) => $p->where('name', 'like', '%'.$key.'%'))->get();
    }

    private function actor(): ?User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }
}
