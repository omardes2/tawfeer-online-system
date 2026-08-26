<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بطاقة «عمولات مستحقّة» في لوحة التحكّم.
 *
 * المستحقّ هو **ما على الشركة الآن**: عمولةٌ استُحقّت بتحصيل مال طلبها ولم
 * تُصرف بعد — أي `eligible` و`approved`.
 *
 * و`pending` ليست منها: طلبٌ سُلّم ولم يصل مالُه من شركة التوصيل بعد. وعدُّها
 * ديْنًا قائمًا يُظهر على الشركة ما لا تدين به اليوم.
 */
class DashboardCommissionsCardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $affiliate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->affiliate = User::factory()->create(['branch_id' => Branch::default()->id]);
        $this->affiliate->assignRole('affiliate');
    }

    private function entry(string $state, float $amount): CommissionEntry
    {
        $order = Order::factory()->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::firstOrFail()->id,
            'affiliate_id' => $this->affiliate->id,
            'status' => 'confirmed',
        ]);

        return CommissionEntry::create([
            'order_id' => $order->id,
            'earner_id' => $this->affiliate->id,
            'earner_type' => 'affiliate',
            'entry_type' => 'accrual',
            'state' => $state,
            'basis' => $amount * 10,
            'rate' => 10,
            'amount' => $amount,
        ]);
    }

    private function card(): float
    {
        return (float) $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()->viewData('pendingCommissions');
    }

    /** المستحقّ يجمع ما استُحقّ ولم يُصرف. */
    public function test_it_sums_eligible_and_approved(): void
    {
        $this->entry('eligible', 120);
        $this->entry('approved', 80);

        $this->assertEqualsWithDelta(200.0, $this->card(), 0.01);
    }

    /**
     * **وما لم يُحصَّل مالُه بعد ليس مستحقًّا.**
     *
     * `pending` تعني طلبًا سُلّم ولم يصل مالُه من شركة التوصيل — فعدُّها ديْنًا
     * يُظهر على الشركة ما لا تدين به اليوم.
     */
    public function test_pending_is_not_counted_as_due(): void
    {
        $this->entry('pending', 500);

        $this->assertEqualsWithDelta(0.0, $this->card(), 0.01);
    }

    /** لكنها تُعرَض بجانبها كي لا يظنّ القارئ أنها ضاعت. */
    public function test_pending_is_shown_beside_it(): void
    {
        $this->entry('pending', 500);
        $this->entry('eligible', 100);

        $response = $this->actingAs($this->admin)->get(route('admin.dashboard'));

        $this->assertEqualsWithDelta(500.0, (float) $response->viewData('notYetDueCommissions'), 0.01);
        $response->assertSee('لم تُستحقّ بعد');
    }

    /** والمصروفة خرجت — لم تعد على الشركة. */
    public function test_paid_entries_are_excluded(): void
    {
        $this->entry('paid', 900);

        $this->assertEqualsWithDelta(0.0, $this->card(), 0.01);
    }

    /** والمعكوسة والملغاة سقطت. */
    public function test_reversed_and_cancelled_are_excluded(): void
    {
        $this->entry('reversed', 300);
        $this->entry('cancelled', 200);
        $this->entry('eligible', 50);

        $this->assertEqualsWithDelta(50.0, $this->card(), 0.01);
    }

    /** ومن لا يرى أداء الفريق لا تُعرض له البطاقة أصلًا. */
    public function test_it_is_hidden_from_those_without_the_permission(): void
    {
        $keeper = User::factory()->create(['branch_id' => Branch::default()->id]);
        $keeper->assignRole('warehouse');

        $response = $this->actingAs($keeper)->get(route('admin.dashboard'));

        if ($response->status() === 200) {
            $this->assertNull($response->viewData('pendingCommissions'));
        }
    }
}
