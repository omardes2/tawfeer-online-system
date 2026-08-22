<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Modules\Catalog\Models\PriceList;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إسناد قائمة أسعارٍ إلى مستخدم من شاشة المستخدمين.
 *
 * `UserAdminService` يبني قائمةً بيضاء صريحة بالحقول التي يكتبها، وحقلٌ ساقطٌ
 * منها يُتحقَّق ثم يُهمَل بصمت: الشاشة تقول «حُدّث المستخدم» والقيمة لم تُخزَّن.
 * وأثرُه هنا ليس تجميليًّا — تاجرٌ يبقى على سعر الجملة العام ويُحسب ربحه خطأً،
 * والمدير لا يرى في الشاشة ما يدلّه على السبب.
 *
 * ولذلك يُفحص **ما استقرّ في قاعدة البيانات** بعد الطلب، لا استجابة الصفحة.
 */
class UserPriceListAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->firstOrFail();
    }

    private function list(string $name): PriceList
    {
        return PriceList::create(['name' => $name, 'is_active' => true]);
    }

    /** @return array<string, mixed> */
    private function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'branch_id' => $user->branch_id,
            'role' => 'affiliate',
            'is_active' => 1,
        ], $overrides);
    }

    private function marketer(): User
    {
        $user = User::factory()->create([
            'branch_id' => Branch::default()->id,
            'phone' => '0599'.random_int(100000, 999999),
        ]);
        $user->assignRole('affiliate');

        return $user;
    }

    /** الإسناد يستقرّ في قاعدة البيانات لا في الرسالة وحدها. */
    public function test_assigning_a_price_list_actually_persists(): void
    {
        $user = $this->marketer();
        $list = $this->list('أسعار مخصّصة');

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $user), $this->payload($user, ['price_list_id' => $list->id]))
            ->assertRedirect();

        $this->assertSame($list->id, $user->fresh()->price_list_id);
    }

    /** ويظهر مختارًا حين يُفتح النموذج ثانيةً. */
    public function test_the_saved_list_comes_back_selected_in_the_form(): void
    {
        $list = $this->list('أسعار مخصّصة');
        $user = $this->marketer();
        $user->update(['price_list_id' => $list->id]);

        $this->actingAs($this->admin())
            ->get(route('admin.users.edit', $user))
            ->assertOk()
            ->assertSee('value="'.$list->id.'" selected', false);
    }

    /** والإفراغ يعيده إلى سعر الجملة العام. */
    public function test_clearing_the_list_returns_the_user_to_general_wholesale(): void
    {
        $list = $this->list('أسعار مخصّصة');
        $user = $this->marketer();
        $user->update(['price_list_id' => $list->id]);

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $user), $this->payload($user, ['price_list_id' => '']))
            ->assertRedirect();

        $this->assertNull($user->fresh()->price_list_id);
    }

    /** والإنشاء يحفظها كذلك — لا التعديل وحده. */
    public function test_creating_a_user_with_a_price_list_persists_it(): void
    {
        $list = $this->list('أسعار مخصّصة');

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'تاجر جديد',
                'email' => 'dealer@example.test',
                'phone' => '0599000111',
                'branch_id' => Branch::default()->id,
                'role' => 'affiliate',
                'is_active' => 1,
                'price_list_id' => $list->id,
                'password' => 'secret-pass-12',
                'password_confirmation' => 'secret-pass-12',
            ])
            ->assertRedirect();

        $this->assertSame($list->id, User::where('email', 'dealer@example.test')->value('price_list_id'));
    }

    /**
     * ولا يمسّ الحفظُ بقيّة الحقول.
     *
     * إضافة حقلٍ إلى قائمةٍ بيضاء بابٌ لإسقاط غيره سهوًا، فيُفحص الجوار معه.
     */
    public function test_saving_the_list_leaves_the_other_fields_intact(): void
    {
        $user = $this->marketer();
        $list = $this->list('أسعار مخصّصة');

        $this->actingAs($this->admin())->put(route('admin.users.update', $user), $this->payload($user, [
            'price_list_id' => $list->id,
            'department' => 'قسم تجريبي',
            'job_title' => 'مسوّق تجريبي',
        ]))->assertRedirect();

        $fresh = $user->fresh();

        $this->assertSame($list->id, $fresh->price_list_id);
        $this->assertSame('قسم تجريبي', $fresh->department);
        $this->assertSame('مسوّق تجريبي', $fresh->job_title);
        $this->assertSame($user->branch_id, $fresh->branch_id);
    }
}
