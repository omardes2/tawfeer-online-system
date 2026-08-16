<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Support\AdminNavigation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ترحيل صلاحيات شحنات الاستيراد يُصلح قاعدة بيانات قائمة.
 *
 * البذرة تزرع الصلاحية في التثبيت الجديد، لكن الإنتاج القائم يشغّل `migrate`
 * وحده — فبقيت الصلاحية معدومة هناك، واختفى بند «شحنات الاستيراد» عن مدير
 * النظام نفسه. هذا الاختبار يحاكي تلك الحالة: يحذف الصلاحيات ثم يُشغّل
 * الترحيل ويتحقّق من عودة البند والوصول.
 */
class ImportShipmentPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'purchasing.shipments.view',
        'purchasing.shipments.manage',
        'purchasing.shipments.close',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** يعيد الحالة إلى ما كانت عليه على الإنتاج: لا صلاحية ولا منح. */
    private function forgetPermissions(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_08_16_130000_add_import_shipment_permissions.php');
        $migration->up();
    }

    public function test_the_migration_restores_the_link_for_the_admin(): void
    {
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');

        $this->forgetPermissions();
        $this->actingAs($admin->fresh());

        // قبل الترحيل: البند مخفيّ والصفحة محجوبة — وهي حالة الإنتاج المُبلَّغ عنها.
        $labels = collect(AdminNavigation::groups())->flatMap(fn ($g) => array_column($g['items'], 'label'));
        $this->assertNotContains('شحنات الاستيراد', $labels->all());

        $this->runMigration();
        $this->actingAs($admin->fresh());

        $labels = collect(AdminNavigation::groups())->flatMap(fn ($g) => array_column($g['items'], 'label'));
        $this->assertContains('شحنات الاستيراد', $labels->all());
        $this->get(route('admin.purchasing.shipments.index'))->assertOk();
    }

    /** التوزيع نسخةٌ من البذرة: المستودع يرى ولا يُدير ولا يُغلق. */
    public function test_the_migration_grants_the_same_split_as_the_seeder(): void
    {
        $this->forgetPermissions();
        $this->runMigration();

        foreach (['admin', 'manager', 'accountant'] as $name) {
            $role = Role::where('name', $name)->first();
            foreach (self::PERMISSIONS as $permission) {
                $this->assertTrue($role->hasPermissionTo($permission), "{$name} تنقصه {$permission}");
            }
        }

        $warehouse = Role::where('name', 'warehouse')->first();
        $this->assertTrue($warehouse->hasPermissionTo('purchasing.shipments.view'));
        $this->assertFalse($warehouse->hasPermissionTo('purchasing.shipments.close'));
    }

    /** تشغيله مرّتين لا يُكرّر صلاحية ولا يفشل — النشر قد يُعيده. */
    public function test_the_migration_is_idempotent(): void
    {
        $this->runMigration();
        $this->runMigration();

        $this->assertSame(1, Permission::where('name', 'purchasing.shipments.view')->count());
    }
}
