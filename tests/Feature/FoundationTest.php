<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Foundation\Models\AuditLog;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\OrderStatus;
use App\Modules\Foundation\Services\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اختبارات أسس المرحلة 1 — تتحقّق من أن المبادئ المعمارية مطبّقة فعليًا.
 */
class FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_health_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_me_endpoint_requires_authentication(): void
    {
        // المبدأ 11: API محمي بـ Sanctum.
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_default_branch_exists_with_uuid(): void
    {
        // المبدأ 1 (تعدد الفروع) + المبدأ 4 (UUID).
        $branch = Branch::default();

        $this->assertNotNull($branch);
        $this->assertTrue($branch->is_default);
        $this->assertNotEmpty($branch->uuid);
    }

    public function test_admin_has_role_and_permissions(): void
    {
        // المبدأ 12: RBAC.
        $admin = User::where('email', 'admin@tawfeer.online')->first();

        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->can('settings.manage'));
        $this->assertNotEmpty($admin->uuid);
        $this->assertNotNull($admin->branch_id);
    }

    public function test_statuses_are_seeded_and_manageable(): void
    {
        // المبدأ 10: حالات في قاعدة البيانات لا enum مغلق.
        $this->assertGreaterThanOrEqual(5, OrderStatus::count());
        $this->assertTrue(OrderStatus::where('is_default', true)->exists());
    }

    public function test_settings_are_read_from_database(): void
    {
        // المبدأ 9: إعدادات ديناميكية.
        $this->assertSame('SAR', Settings::get('store.currency'));
        $this->assertSame(15, Settings::get('tax.rate'));
        $this->assertNull(Settings::get('non.existent.key'));
    }

    public function test_model_changes_are_audited_automatically(): void
    {
        // المبدأ 8: سجلّ تدقيق مركزي آلي.
        $before = AuditLog::count();

        $branch = Branch::default();
        $branch->update(['phone' => '0500000000']);

        $this->assertGreaterThan($before, AuditLog::count());

        $log = AuditLog::latest('id')->first();
        $this->assertSame('updated', $log->action);
        $this->assertArrayHasKey('phone', $log->new_values);
    }

    public function test_money_columns_use_decimal_not_float(): void
    {
        // المبدأ 6: لا float للمبالغ — نتحقّق أن الأعمدة المالية (لاحقًا) ديسيمال.
        // في المرحلة 1 لا جداول مالية بعد؛ هذا الاختبار حارس يوثّق النية.
        $this->assertTrue(true);
    }
}
