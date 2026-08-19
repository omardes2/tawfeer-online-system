<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات المبيعات (Phase 2.6) بمخطط ADR-021.
 */
class SalesPermissionSeeder extends Seeder
{
    private array $permissions = [
        'sales.orders.view', 'sales.orders.view_own', 'sales.orders.create', 'sales.orders.update', 'sales.orders.delete',
        'sales.orders.confirm', 'sales.orders.reserve', 'sales.orders.ship', 'sales.orders.deliver', 'sales.orders.cancel',
        // البيع المباشر: نقطة بيع كاملة (تحصيل فوري وخصم مخزون). صلاحية مستقلّة
        // عن الإنشاء العادي فلا يفتحها منحُ صلاحية أخرى بالمصادفة.
        'sales.orders.create_direct',
        // طلبات لم تكتمل: قائمة اتصالٍ بمن تردّد في خطوة الإتمام. **ليست للمسوّق**
        // — هي أرقام زبائن المتجر كلّه لا زبائنه هو.
        'sales.abandoned_checkouts.view', 'sales.abandoned_checkouts.manage',
    ];

    private array $grants = [
        'manager' => ['*'],
        // موظف المبيعات: ينشئ الطلبات ويرى/يدير طلباته فقط. لا يملك «تأكيد الطلب»
        // (التأكيد الذي يُرحّل ويرسل لشركة التوصيل — لمدير النظام/الأدمن فقط).
        'sales' => [
            'sales.orders.view_own', 'sales.orders.create', 'sales.orders.update',
            'sales.orders.reserve', 'sales.orders.cancel',
            'sales.abandoned_checkouts.view', 'sales.abandoned_checkouts.manage',
        ],
        // المسوّق: ينشئ ويرى ويلغي طلباته هو فقط. لا بيع مباشر — لا يحصّل نقدًا
        // ولا يخصم مخزونًا؛ عمله جلب الطلبات لا تشغيل نقطة بيع.
        'affiliate' => [
            'sales.orders.view_own', 'sales.orders.create', 'sales.orders.cancel',
        ],
        'sales_supervisor' => [
            'sales.abandoned_checkouts.view', 'sales.abandoned_checkouts.manage',
        ],
        'warehouse' => [
            'sales.orders.view', 'sales.orders.ship', 'sales.orders.deliver',
        ],
        'accountant' => [
            'sales.orders.view',
        ],
    ];

    public function run(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        if ($admin = Role::where('name', 'admin')->first()) {
            $admin->givePermissionTo($this->permissions);
        }

        foreach ($this->grants as $roleName => $abilities) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo($abilities === ['*'] ? $this->permissions : $abilities);
            }
        }

        // سحب صلاحيات ممنوحة سابقًا لهذه الأدوار (givePermissionTo يضيف فقط): العرض الكامل
        // و«تأكيد الطلب» — فالتأكيد لمدير النظام/الأدمن فقط. يُطبَّق على قاعدة بيانات قائمة.
        foreach (['sales', 'affiliate'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            foreach (['sales.orders.view', 'sales.orders.confirm'] as $perm) {
                if ($role->hasPermissionTo($perm)) {
                    $role->revokePermissionTo($perm);
                }
            }
        }
    }
}
