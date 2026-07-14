<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Setting;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Foundation\Services\SystemSettingsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('admin');

        return $u;
    }

    public function test_admin_can_open_settings(): void
    {
        $this->actingAs($this->admin())->get(route('admin.settings.edit'))->assertOk();
    }

    public function test_persists_non_secret_settings_to_database(): void
    {
        $this->actingAs($this->admin())->put(route('admin.settings.update'), [
            'store_name' => 'توفير الجديد',
            'system_timezone' => 'Asia/Riyadh',
            'system_currency' => 'SAR',
            'system_locale' => 'ar',
            'openai_model' => 'gpt-4o',
        ])->assertSessionHas('success');

        $this->assertSame('توفير الجديد', Settings::get('store.name'));
        $this->assertSame('Asia/Riyadh', Settings::get('system.timezone'));
        $this->assertSame('gpt-4o', Settings::get('openai.model'));
    }

    public function test_secret_is_stored_encrypted_and_blank_preserves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'openai_key' => 'sk-secret-value-123',
        ])->assertSessionHas('success');

        // القيمة المخزّنة ليست نصًّا صريحًا (مُشفَّرة).
        $stored = Setting::where('key', 'openai.key')->value('value');
        $this->assertNotSame('sk-secret-value-123', $stored);
        // لكنها تُفكّ عبر الخدمة.
        $this->assertSame('sk-secret-value-123', app(SystemSettingsService::class)->secret('openai.key'));

        // إرسال فارغ يُبقي القيمة.
        $this->actingAs($admin)->put(route('admin.settings.update'), ['openai_key' => ''])->assertSessionHas('success');
        $this->assertSame('sk-secret-value-123', app(SystemSettingsService::class)->secret('openai.key'));
    }

    public function test_requires_manage_permission_to_update(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('sales');
        $this->actingAs($u)->put(route('admin.settings.update'), ['store_name' => 'x'])->assertForbidden();
    }

    public function test_admin_can_clear_cache_from_panel(): void
    {
        $this->actingAs($this->admin())->post(route('admin.system.clear-cache'))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_clear_cache_requires_manage_permission(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('sales');
        $this->actingAs($u)->post(route('admin.system.clear-cache'))->assertForbidden();
    }
}
