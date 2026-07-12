<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات العمولات/الأرباح (Phase 4.2 / ADR-037) بمخطط ADR-021.
 */
class CommissionsPermissionSeeder extends Seeder
{
    private array $permissions = [
        'commissions.view_own',
        'commissions.view_team',
        'commissions.rules.manage',
        'commissions.approve',
        'commissions.payout',
        'commissions.audit.view',
    ];

    private array $grants = [
        'manager' => ['*'],
        'finance' => ['commissions.view_team', 'commissions.rules.manage', 'commissions.approve', 'commissions.payout', 'commissions.audit.view'],
        'sales_supervisor' => ['commissions.view_team', 'commissions.approve', 'commissions.audit.view'],
        'sales' => ['commissions.view_own'],
        'affiliate' => ['commissions.view_own'],
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
    }
}
