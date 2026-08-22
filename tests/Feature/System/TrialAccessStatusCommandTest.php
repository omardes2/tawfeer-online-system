<?php

namespace Tests\Feature\System;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * أمر `access:trial-status` — الجسر بين نيّة الحصر وواقعه على الخادم.
 *
 * الحصر بالدور لا بالبريد، فصاحب النظام لا يرى من اللوحة سببَ اختفاء الشاشات
 * عنه: حسابه يحمل «مدير» لا «مدير النظام»، والفرق غير ظاهر في أيّ شاشة.
 */
class TrialAccessStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $email, ?string $role = null): User
    {
        $user = User::factory()->create(['email' => $email, 'branch_id' => Branch::default()->id]);
        $role && $user->assignRole($role);

        return $user;
    }

    /** يسرد حاملي الدور فعلًا — لا من يُظنّ أنه يحمله. */
    public function test_it_lists_who_actually_holds_the_admin_role(): void
    {
        $this->user('owner@example.test', 'admin');
        $this->user('other@example.test', 'manager');

        $this->artisan('access:trial-status')
            ->expectsOutputToContain('owner@example.test')
            ->doesntExpectOutputToContain('other@example.test')
            ->assertSuccessful();
    }

    /** ويمنح الدور لحسابٍ قائم. */
    public function test_it_grants_the_admin_role_to_an_existing_account(): void
    {
        $user = $this->user('owner@example.test', 'manager');

        $this->artisan('access:trial-status', ['--make-admin' => 'owner@example.test'])
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->hasRole('admin'));
    }

    /**
     * ولا يمنحه لبريدٍ لا حساب له.
     *
     * خطأ مطبعيّ في البريد ينشئ حسابًا صامتًا بصلاحياتٍ كاملة لو أنشأه الأمر —
     * فيُبلَّغ بالفشل ولا يُنشأ شيء.
     */
    public function test_it_refuses_an_email_with_no_account(): void
    {
        $this->artisan('access:trial-status', ['--make-admin' => 'typo@example.test'])
            ->assertFailed();

        $this->assertNull(User::where('email', 'typo@example.test')->first());
    }

    /**
     * ولا يسحب الدور ممّن يحمله.
     *
     * الأمر يمنح فقط: إسقاط آخر مدير نظام يقفل اللوحة على الجميع بلا طريق رجوع
     * من داخل النظام.
     */
    public function test_it_never_strips_an_existing_admin(): void
    {
        $incumbent = $this->user('first@example.test', 'admin');

        $this->artisan('access:trial-status', ['--make-admin' => 'second@example.test'])
            ->assertFailed();

        $this->user('second@example.test', 'manager');

        $this->artisan('access:trial-status', ['--make-admin' => 'second@example.test'])
            ->assertSuccessful();

        $this->assertTrue($incumbent->fresh()->hasRole('admin'));
    }

    /** ويسكت عن التسريب حين لا تسريب — الحصر مطبَّق بعد الهجرة. */
    public function test_it_reports_a_clean_lockdown_after_the_migration(): void
    {
        $this->artisan('access:trial-status')
            ->expectsOutputToContain('لا شيء')
            ->assertSuccessful();
    }

    /** ويكشف الدور المسرِّب حين يُمنح يدويًّا من شاشة الأدوار. */
    public function test_it_names_the_role_that_leaks_a_trial_permission(): void
    {
        Role::where('name', 'manager')->firstOrFail()->givePermissionTo('ai_agent.toggle');

        // سطرٌ واحد بالدور وصلاحيته: `expectsOutputToContain` مرّتين على السطر
        // نفسه يستهلك أوّلُهما المطابقةَ فيسقط ثانيهما بلا سبب حقيقي.
        $this->artisan('access:trial-status')
            ->expectsOutputToContain('manager: ai_agent.toggle')
            ->assertSuccessful();
    }
}
