<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Sales\Models\Order;
use Illuminate\Console\Command;

/**
 * لماذا يرى هذا المستخدم ما يراه من الطلبات؟
 *
 * حين يتصرّف النظام على الإنتاج بغير ما يُفترض، يكون السبب في البيانات لا في
 * الكود غالبًا: دورٌ مخصَّص، أو صلاحية مُنحت للمستخدم مباشرةً. هذا الأمر يقرأ
 * الحالة الفعلية ويطبع الحكم وسببه بدل التخمين.
 */
class DiagnoseOrderVisibility extends Command
{
    protected $signature = 'sales:visibility {email : بريد المستخدم}';

    protected $description = 'يشرح نطاق رؤية الطلبات لمستخدم معيّن وسببه';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('لا مستخدم بهذا البريد: '.$this->argument('email'));

            return self::FAILURE;
        }

        $roles = $user->getRoleNames();
        $restricted = $user->restrictedToOwnOrders();

        $this->newLine();
        $this->line('<comment>المستخدم:</comment> '.$user->name.' &lt;'.$user->email.'&gt;');
        $this->line('<comment>الأدوار:</comment> '.($roles->isEmpty() ? '— بلا دور —' : $roles->implode('، ')));

        $this->newLine();
        $this->line('<comment>صلاحيات الطلبات:</comment>');
        foreach (['sales.orders.view' => 'العرض الكامل', 'sales.orders.view_own' => 'عرض الخاص'] as $permission => $label) {
            $viaRole = $user->getPermissionsViaRoles()->contains('name', $permission);
            $direct = $user->permissions->contains('name', $permission);
            $has = $user->can($permission);

            $source = match (true) {
                $direct && $viaRole => 'مباشرةً + عبر الدور',
                $direct => 'مباشرةً على المستخدم',
                $viaRole => 'عبر الدور',
                default => '—',
            };
            $this->line(sprintf('  %s %-14s (%s) : %s', $has ? '✔' : '✘', $permission, $label, $source));
        }

        $this->newLine();
        if ($restricted) {
            $own = Order::where(fn ($q) => $q->where('created_by', $user->id)
                ->orWhere('assigned_to', $user->id)
                ->orWhere('affiliate_id', $user->id))->count();

            $this->info('الحكم: يرى طلباته فقط — '.$own.' من أصل '.Order::count().'.');
            $this->line($this->restrictionReason($user));

            return self::SUCCESS;
        }

        $this->warn('الحكم: يرى **كل** الطلبات ('.Order::count().').');
        $this->line($this->fullViewReason($user));

        return self::SUCCESS;
    }

    private function restrictionReason(User $user): string
    {
        if ($user->can('sales.orders.view_own')) {
            return 'السبب: يحمل «عرض الخاص» — وهو قيدٌ يسبق العرض الكامل.';
        }
        if ($user->hasAnyRole(User::OWN_ORDERS_ONLY_ROLES)) {
            return 'السبب: دوره من الأدوار المقيَّدة ('.implode('، ', User::OWN_ORDERS_ONLY_ROLES).').';
        }

        return 'السبب: لا يملك «العرض الكامل».';
    }

    private function fullViewReason(User $user): string
    {
        if ($user->hasAnyRole(User::FULL_ORDER_VIEW_ROLES)) {
            return 'السبب: دورٌ إداري ('.implode('، ', User::FULL_ORDER_VIEW_ROLES).') — وهذا متوقَّع.';
        }

        return 'السبب: يملك «العرض الكامل» ولا يحمل «عرض الخاص» ولا دورًا مقيَّدًا.'.PHP_EOL
            .'  <fg=yellow>إن كان هذا الحساب مسوّقًا أو موظف مبيعات فالدور مُعدٌّ خطأً:</>'.PHP_EOL
            .'  امنحه «sales.orders.view_own» بدل «sales.orders.view» ليُقيَّد على طلباته.';
    }
}
