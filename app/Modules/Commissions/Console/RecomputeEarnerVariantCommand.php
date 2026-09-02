<?php

namespace App\Modules\Commissions\Console;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commissions\Services\CommissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * إعادة احتساب حركات مسوّقٍ على صنفٍ بعينه، على سعر جملته **الحاليّ**.
 *
 * صنفٌ بِيع وكرتُه بلا سعر جملة: `itemCost` يرجع حينها إلى **متوسّط التكلفة**،
 * والتكلفة أدنى من الجملة — فالهامش أوسع والعمولة أعلى مما تستحقّ، واللقطة
 * تُجمَّد على ذلك. فإذا صُحّح الكرت لاحقًا، استفادت منه الطلبات الجديدة وحدها
 * وبقيت القديمة على الرقم الخاطئ.
 *
 * وهذا الأمر يُنزل القديمة على السعر المصحَّح، فيصير الكشف كلّه على أساسٍ واحد.
 *
 * **يقرأ ولا يكتب افتراضيًّا.** الكتابة تحتاج `--apply` صراحةً.
 *
 * ولا يمسّ إلا `commission_entries`: الفاتورة والمخزون والإيراد وتكلفة
 * المبيعات وقيود اليومية تبقى كما هي.
 */
class RecomputeEarnerVariantCommand extends Command
{
    protected $signature = 'commissions:recompute-earner-variant
                            {earner : بريد المسوّق أو معرّفه}
                            {variant : الصنف — معرّف منتج أو متغيّر أو SKU أو جزء من الاسم}
                            {--apply : تنفيذ التعديل فعلًا (بدونه عرضٌ فقط)}
                            {--allow-zero-wholesale : اقبل سعر جملةٍ صفر لهذا الصنف وحده — الربح يصير سعر البيع كاملًا}';

    protected $description = 'إعادة احتساب حركات مسوّق على صنفٍ بعينه على سعر جملته الحاليّ';

    public function handle(CommissionService $commissions): int
    {
        $earner = User::where('email', $this->argument('earner'))
            ->orWhere('id', is_numeric($this->argument('earner')) ? (int) $this->argument('earner') : 0)
            ->first();

        if (! $earner) {
            $this->error('لا مستخدم بالبريد أو المعرّف «'.$this->argument('earner').'».');

            return self::FAILURE;
        }

        $matches = $this->resolveVariants((string) $this->argument('variant'));

        if ($matches->isEmpty()) {
            $this->error('لم يُطابَق صنف — راجع المعرّف أو الاسم.');

            return self::FAILURE;
        }

        // متغيّرٌ واحد قطعًا: لكل متغيّرٍ سعر جملته، وإعادةُ الاحتساب على سعر
        // أحدهم تُنزل حركاتِ مقاسٍ على سعر مقاسٍ آخر.
        if ($matches->count() > 1) {
            $this->error('«'.$this->argument('variant').'» يطابق '.$matches->count().' متغيّرات — حدّد واحدًا بمعرّفه أو SKU:');
            $this->table(['المعرّف', 'SKU', 'المنتج'], $matches->map(fn ($v) => [
                $v->id, $v->sku, $v->product?->name ?? '—',
            ])->all());

            return self::FAILURE;
        }

        $variant = $matches->first();

        $this->line('');
        $this->info('المسوّق: '.$earner->name);
        $this->line('الصنف: '.($variant->product?->name ?? $variant->sku).' #'.$variant->id
            .'  ·  سعر جملته الحاليّ: '.number_format($variant->effectiveWholesalePrice(), 2));
        $this->line('');

        if ($this->option('allow-zero-wholesale')) {
            // تحذيرٌ ظاهر لا سطرٌ في التوثيق: من يمرّر المفتاح يجب أن يرى أثره
            // قبل `--apply` لا بعده.
            $this->warn('⚠ سعر جملةٍ صفر مقبولٌ لهذا الصنف وحده — الربح يصير سعر البيع كاملًا،');
            $this->warn('  ولا يتأثّر صنفٌ آخر ولا يُغيَّر أي إعداد.');
            $this->line('');
        }

        try {
            $changes = $commissions->swapEntryVariant(
                $earner, [$variant->id], $variant, $this->actor(),
                (bool) $this->option('apply'), (bool) $this->option('allow-zero-wholesale'),
            );
        } catch (ValidationException $e) {
            $this->error($e->validator->errors()->first());

            return self::FAILURE;
        }

        if ($changes === []) {
            $this->info('✓ لا شيء يتغيّر — الحركات محسوبة على سعر الجملة الحاليّ أصلًا.');

            return self::SUCCESS;
        }

        $this->render($changes);

        if ($this->option('apply')) {
            $this->info('✓ نُفّذت إعادة الاحتساب، وأُثبتت في سجلّ التحوّلات. الفواتير والمخزون والقيود لم تُمَسّ.');
        } else {
            $this->warn('عرضٌ فقط — لم يتغيّر شيء. للتنفيذ: أضف --apply');
        }

        return self::SUCCESS;
    }

    /** @param  array<int, array<string, mixed>>  $changes */
    private function render(array $changes): void
    {
        $rows = [];
        $kept = [];
        $total = 0.0;

        foreach ($changes as $change) {
            $entry = $change['entry'];
            $order = $entry->order?->number ?? '—';

            if ($change['relabel_only']) {
                $kept[] = [$order, number_format($change['was'], 2), $this->reason($change['reason'] ?? null)];

                continue;
            }

            $total += $change['delta'];
            $rows[] = [
                $order,
                number_format((float) $entry->wholesale_cost_snapshot, 2),
                number_format($change['basis'], 2),
                number_format($change['was'], 2),
                number_format($change['now'], 2),
                number_format($change['delta'], 2),
            ];
        }

        if ($kept !== []) {
            $this->warn('حركاتٌ لا يتغيّر مبلغها:');
            $this->table(['الطلب', 'المبلغ كما هو', 'السبب'], $kept);
            $this->line('');
        }

        if ($rows !== []) {
            $this->table(['الطلب', 'الأساس القديم', 'الهامش الجديد', 'كانت', 'تصير', 'الفرق'], $rows);
            $this->line('');
            $this->line('عدد الحركات: '.count($rows).'  ·  صافي الفرق: '.number_format($total, 2));
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
            default => 'تُترك كما هي',
        };
    }

    /**
     * @return Collection<int, ProductVariant>
     */
    private function resolveVariants(string $key): Collection
    {
        // `wholesale_price` في التحميل: بدونه يُقرأ سعر الجملة صفرًا من علاقةٍ
        // منقوصة، فيُرفض العمل والكرت سليم.
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
