<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * حالة حصر مرحلة التجربة — من يملك الميزات الجديدة فعلًا.
 *
 * الحصر يجري بالدور `admin` لا بالبريد (`CLAUDE.md` مبدأ ١١: RBAC فقط)، وهذا
 * يفتح فجوةً بين النيّة والواقع: صاحب النظام يظنّ حسابه «مدير النظام» بينما
 * يحمل دور «مدير» (`manager`) — فتسحب هجرةُ الحصر منه الصلاحياتِ بدل أن
 * تمنحها، فيرى اختفاءً حيث توقّع ظهورًا ولا شيء في الواجهة يفسّر ذلك.
 *
 * يقرأ الأمر بلا تعديل. و`--make-admin` وحده يكتب — ويمنح الدور، ولا يسحبه من
 * أحد: إسقاط آخر مدير نظام يقفل اللوحة على الجميع بلا طريق رجوع.
 */
class TrialAccessStatus extends Command
{
    protected $signature = 'access:trial-status
                            {--make-admin= : بريد حسابٍ يُمنح دور مدير النظام}';

    protected $description = 'عرض من يملك الميزات المحصورة بمرحلة التجربة، ومنح دور مدير النظام عند الحاجة';

    /**
     * ما حصرته هجرة `restrict_new_features_to_admin_during_trial`.
     *
     * @var array<int, string>
     */
    private const TRIAL_PERMISSIONS = [
        'sales.abandoned_checkouts.view',
        'sales.abandoned_checkouts.manage',
        'catalog.price_lists.view',
        'catalog.price_lists.manage',
        'reports.ad_budget.view',
        'reports.ad_budget.manage',
        'reports.product_decision.view',
        'reports.sales_by_location.view',
        'inbox.view',
        'inbox.reply',
        'inbox.assign',
        'ai_agent.handoff',
        'ai_agent.toggle',
        'ai_agent.runs.view',
        'ai_agent.knowledge.view',
        'ai_agent.knowledge.manage',
    ];

    public function handle(): int
    {
        // الأمر يقرأ الواقع لا المخزَّن مؤقّتًا: من يشغّله يشغّله تحديدًا لأن
        // اللوحة تُظهر خلاف المتوقَّع، فقراءةُ نفس الذاكرة التي ضلّلت اللوحة
        // تُعيد تأكيد الخطأ بدل كشفه.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($email = $this->option('make-admin')) {
            if (($code = $this->makeAdmin($email)) !== self::SUCCESS) {
                return $code;
            }
        }

        $this->showAdmins();
        $this->showHolders();
        $this->showCacheHint();

        return self::SUCCESS;
    }

    /** منح دور مدير النظام لحسابٍ قائم. */
    private function makeAdmin(string $email): int
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("لا حساب بالبريد {$email}.");

            return self::FAILURE;
        }

        if (! Role::where('name', 'admin')->exists()) {
            $this->error('دور «admin» غير موجود — شغّل php artisan db:seed --class=RolePermissionSeeder أوّلًا.');

            return self::FAILURE;
        }

        if ($user->hasRole('admin')) {
            $this->info("✓ {$email} يحمل دور مدير النظام أصلًا.");
        } else {
            $user->assignRole('admin');
            $this->info("✓ مُنح {$email} دور مدير النظام.");
        }

        // الصلاحيات مخزَّنة مؤقّتًا؛ بلا تفريغٍ يبقى المنح بلا أثر حتى انتهاء المهلة.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return self::SUCCESS;
    }

    /** من يحمل دور مدير النظام. */
    private function showAdmins(): void
    {
        $this->line('');
        $this->line('<comment>حاملو دور مدير النظام (admin):</comment>');

        $admins = User::role('admin')->orderBy('email')->get(['email', 'name']);

        if ($admins->isEmpty()) {
            $this->error('  لا أحد — اللوحة مقفلة على الجميع. استعمل --make-admin=البريد');

            return;
        }

        foreach ($admins as $admin) {
            $this->line("  • {$admin->email}  ({$admin->name})");
        }

        if ($admins->count() > 1) {
            $this->line('');
            $this->warn('  أكثر من حساب يحمل الدور. الحصر يشملهم جميعًا — أسقط الزائد من شاشة الأدوار.');
        }
    }

    /** أيّ دورٍ آخر ما زال يملك شيئًا من المحصور. */
    private function showHolders(): void
    {
        $this->line('');
        $this->line('<comment>أدوارٌ غير admin تملك صلاحيةً محصورة:</comment>');

        $leaks = [];

        foreach (Role::where('name', '!=', 'admin')->orderBy('name')->get() as $role) {
            $held = array_values(array_filter(
                self::TRIAL_PERMISSIONS,
                fn (string $permission) => Permission::where('name', $permission)->exists()
                    && $role->hasPermissionTo($permission, 'web')
            ));

            if ($held !== []) {
                $leaks[$role->name] = $held;
            }
        }

        if ($leaks === []) {
            $this->info('  ✓ لا شيء — الحصر مطبَّق.');

            return;
        }

        foreach ($leaks as $role => $held) {
            $this->line("  • {$role}: ".implode('، ', $held));
        }

        $this->line('');
        $this->warn('  أعد تشغيل الهجرة أو اسحبها من شاشة الأدوار.');
    }

    private function showCacheHint(): void
    {
        $this->line('');
        $this->line('إن لم يظهر الأثر في اللوحة: php artisan permission:cache-reset');
        $this->line('');
    }
}
