<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Support\AdminNavigation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الوصول إلى شاشة تقييمات الزبائن.
 *
 * كانت الشاشة قائمة منذ بنائها بلا بندٍ يقود إليها في القائمة الجانبية —
 * والتقييم المعلَّق لا يُنشر حتى يُعتمد، فبقيت التقييمات كلّها محجوبة عن المتجر
 * بلا سببٍ ظاهر: لا خطأ، ولا رابط.
 */
class ReviewModerationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    private function labels(): array
    {
        return collect(AdminNavigation::groups())
            ->flatMap(fn ($g) => array_column($g['items'], 'label'))->all();
    }

    public function test_the_sidebar_leads_to_review_moderation(): void
    {
        $this->actingAs($this->withRole('admin'));

        $this->assertContains('تقييمات الزبائن', $this->labels());
    }

    public function test_the_moderation_screen_opens(): void
    {
        $this->actingAs($this->withRole('admin'))
            ->get(route('admin.reviews.index'))
            ->assertOk();
    }

    /** ومن لا يملك الصلاحية لا يراها ولا يفتحها. */
    public function test_it_is_closed_to_those_without_the_permission(): void
    {
        $this->actingAs($this->withRole('affiliate'));

        $this->assertNotContains('تقييمات الزبائن', $this->labels());
        $this->get(route('admin.reviews.index'))->assertForbidden();
    }

    /**
     * والاطّلاع لا يعني النشر: المستودع يقرأ التقييمات ولا يعتمدها.
     *
     * صلاحيتان منفصلتان عمدًا — نشرُ رأيٍ عن منتج قرارٌ تحريري لا عمليّاتي.
     */
    public function test_viewing_does_not_grant_publishing(): void
    {
        $warehouse = $this->withRole('warehouse');

        $this->assertTrue($warehouse->can('catalog.reviews.view'));
        $this->assertFalse($warehouse->can('catalog.reviews.update'));
        $this->assertFalse($warehouse->can('catalog.reviews.delete'));
    }
}
