<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات المحاسبة التشغيلية (Phase 7.1): خزائن/بنوك/سندات قبض وصرف/مصروفات/إيرادات/تحويلات.
 * مخطط ADR-021: `{module}.{resource}.{action}`.
 */
class AccountingOperationsPermissionSeeder extends Seeder
{
    private array $permissions = [
        'accounting.cashboxes.view', 'accounting.cashboxes.manage',
        'accounting.banks.view', 'accounting.banks.manage',
        'accounting.receipts.view', 'accounting.receipts.create', 'accounting.receipts.approve', 'accounting.receipts.post',
        'accounting.payments.view', 'accounting.payments.create', 'accounting.payments.approve', 'accounting.payments.post',
        'accounting.expenses.view', 'accounting.expenses.create', 'accounting.expenses.approve', 'accounting.expenses.post',
        'accounting.income.view', 'accounting.income.create', 'accounting.income.approve', 'accounting.income.post',
        'accounting.transfers.view', 'accounting.transfers.create', 'accounting.transfers.approve', 'accounting.transfers.post',
    ];

    private array $grants = [
        'manager' => ['*'],
        'accountant' => ['*'],
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
