<?php

namespace Tests\Feature\Admin;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * يضمن وجود صلاحيات تعديل/حذف سندات الاستلام ومرتجعات الموردين (التي تفرضها السياسات)
 * ضمن الصلاحيات المزروعة — فتظهر في صفحة الأدوار وتكون قابلة للإسناد.
 */
class PurchasingPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_and_return_update_delete_permissions_are_seeded(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([
            'purchasing.receipts.update', 'purchasing.receipts.delete',
            'purchasing.returns.update', 'purchasing.returns.delete',
        ] as $name) {
            $this->assertTrue(
                Permission::where('name', $name)->where('guard_name', 'web')->exists(),
                "الصلاحية المفقودة: {$name}"
            );
        }
    }
}
