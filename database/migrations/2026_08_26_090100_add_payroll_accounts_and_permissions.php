<?php

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * حسابات الرواتب ونهاية الخدمة، وصلاحياتها.
 *
 * في الهجرة لا في البذرة: النشر يشغّل `migrate` وحده.
 *
 * ## لماذا أربعة حسابات لا اثنان
 *
 * الراتب والتزامُه شيئان: المصروف يقع في الشهر الذي عُمِل فيه، والدفع قد يقع
 * بعده. فترحيلُ الكشف يُثبت المصروف والالتزام معًا، ويُطفئ الدفعُ الالتزام
 * وحده — وبينهما يُقرأ في الميزانية ما على الشركة لموظفيها.
 *
 * ومخصّص نهاية الخدمة مثلُه ومنفصلٌ عنه: مصروفٌ يتراكم كل شهر والتزامٌ لا
 * يُدفَع حتى تنتهي الخدمة. خلطُه بالرواتب المستحقّة يجعل التزام السنوات يبدو
 * راتبًا مستحقًّا لهذا الشهر.
 */
return new class extends Migration
{
    /** [الرمز، الاسم، النوع، رمز الأب] */
    private const ACCOUNTS = [
        ['2200', 'رواتب مستحقة', 'liability', '2000'],
        ['2210', 'مخصّص مكافأة نهاية الخدمة', 'liability', '2000'],
        ['5200', 'مصروف الرواتب والأجور', 'expense', '5000'],
        ['5210', 'مصروف مكافأة نهاية الخدمة', 'expense', '5000'],
    ];

    private const PERMISSIONS = [
        'hr.employees.view',
        'hr.employees.manage',
        'hr.payroll.view',
        'hr.payroll.manage',
    ];

    public function up(): void
    {
        if (Schema::hasTable('accounts')) {
            foreach (self::ACCOUNTS as [$code, $name, $type, $parentCode]) {
                // بلا أبٍ لا يُنشأ الحساب: دليل الحسابات لم يُبذَر بعد، وإنشاء
                // حسابٍ يتيمٍ في جذر الشجرة أسوأ من تركه لهجرةٍ تالية.
                $parent = Account::where('code', $parentCode)->first();
                if (! $parent) {
                    continue;
                }

                Account::firstOrCreate(
                    ['code' => $code],
                    ['name' => $name, 'type' => $type, 'parent_id' => $parent->id, 'is_postable' => true],
                );
            }
        }

        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // مدير النظام وحده في مرحلة التجربة: الرواتب أرقامٌ شخصية، ومن يراها
        // يرى ما يتقاضاه كل زميلٍ له.
        Role::where('name', 'admin')->first()?->givePermissionTo(self::PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        // الحسابات لا تُحذف: قد تكون حملت قيودًا، وحذفُ حسابٍ مُرحَّلٍ عليه
        // يترك قيدًا بلا حساب.
    }
};
