<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * صلاحيات الرواتب والموظفين.
 *
 * لها نظيرٌ في migration (`add_payroll_accounts_and_permissions`) لأن النشر
 * يشغّل `migrate` لا `seed`. المساران يتفقان عمدًا.
 *
 * ومحصورةٌ بمدير النظام: الرواتب أرقامٌ شخصية، ومن يراها يرى ما يتقاضاه كل
 * زميلٍ له. فلا تُمنح لدورٍ عامٍّ ولو كان محاسبيًّا.
 */
class HrPermissionSeeder extends Seeder
{
    private array $permissions = [
        'hr.employees.view',
        'hr.employees.manage',
        'hr.payroll.view',
        'hr.payroll.manage',
    ];

    public function run(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::where('name', 'admin')->first()?->givePermissionTo($this->permissions);
    }
}
