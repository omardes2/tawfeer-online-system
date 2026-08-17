<?php

namespace App\Console\Commands;

use App\Support\PermissionLabel;
use App\Support\PermissionUsage;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * مطابقة صلاحيات قاعدة البيانات بما يفحصه الكود فعلًا.
 *
 * تكشف خللين متقابلين:
 * - **ميتة:** موجودة ولا أثر لها في الكود — تُعرَض في شاشة الأدوار فيُظنّ أنها
 *   تفتح شيئًا، فتُمنح بلا أثر أو تُمنع بلا فائدة.
 * - **ناقصة:** يفحصها الكود ولا وجود لها — الفحص يفشل دائمًا فتُغلق شاشةٌ في وجه
 *   الجميع بلا رسالة، ولا يلاحظها مديرُ النظام إن كان يتخطّى الفحص.
 */
class AuditPermissions extends Command
{
    protected $signature = 'permissions:audit
                            {--prune : حذف الصلاحيات الميتة نهائيًا (يسأل قبل التنفيذ)}';

    protected $description = 'مطابقة الصلاحيات المخزَّنة بما يستعمله الكود، وكشف الميت والناقص';

    public function handle(): int
    {
        PermissionUsage::flush();

        $existing = Permission::orderBy('name')->pluck('name');
        $unused = PermissionUsage::unused($existing);
        $missing = PermissionUsage::missing($existing);

        $this->info("الصلاحيات المخزَّنة: {$existing->count()}");

        $this->report('ناقصة — يفحصها الكود ولا وجود لها', $missing, 'error');
        $this->report('ميتة — موجودة ولا أثر لها في الكود', $unused, 'warn');

        if ($unused !== [] && $this->option('prune')) {
            $this->pruneUnused($unused);
        } elseif ($unused !== []) {
            $this->line('');
            $this->line('للحذف: php artisan permissions:audit --prune');
        }

        // الناقص خلل حقيقي يجب أن يُفشل الفحص الآلي؛ الميت تنظيفٌ مؤجَّل.
        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<int, string>  $keys */
    private function report(string $title, array $keys, string $style): void
    {
        $this->line('');

        if ($keys === []) {
            $this->info("✓ لا شيء: {$title}");

            return;
        }

        $this->{$style}(sprintf('%s (%d)', $title, count($keys)));

        foreach ($keys as $key) {
            $this->line(sprintf('  %-42s %s', $key, PermissionLabel::for($key)));
        }
    }

    /** @param  array<int, string>  $unused */
    private function pruneUnused(array $unused): void
    {
        // الحذف يسحبها من كل دورٍ يحملها — لا تراجع، فيُذكر العدد قبل السؤال.
        $held = Permission::whereIn('name', $unused)->withCount('roles')->get()
            ->filter(fn (Permission $p) => $p->roles_count > 0);

        if ($held->isNotEmpty()) {
            $this->warn('منها '.$held->count().' صلاحية مُسنَدة لأدوار قائمة، وستُسحب منها بالحذف:');
            foreach ($held as $p) {
                $this->line("  {$p->name} ({$p->roles_count} دورًا)");
            }
        }

        if (! $this->confirm('حذف '.count($unused).' صلاحية نهائيًّا؟', false)) {
            $this->line('أُلغي الحذف.');

            return;
        }

        Permission::whereIn('name', $unused)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('حُذفت '.count($unused).' صلاحية.');
    }
}
