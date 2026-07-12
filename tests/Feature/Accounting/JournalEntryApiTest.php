<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JournalEntryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function withRole(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private function balancedPayload(float $amount = 300, bool $post = false): array
    {
        return [
            'entry_date' => '2026-07-12',
            'description' => 'قيد اختبار',
            'post' => $post,
            'lines' => [
                ['account_code' => '1010', 'debit' => $amount, 'credit' => 0],
                ['account_code' => '1100', 'debit' => 0, 'credit' => $amount],
            ],
        ];
    }

    public function test_create_balanced_draft_entry(): void
    {
        Sanctum::actingAs($this->admin());

        $res = $this->postJson('/api/v1/accounting/journal-entries', $this->balancedPayload())->assertCreated();
        $this->assertStringStartsWith('JE-', $res->json('data.number'));
        $res->assertJsonPath('data.status', 'draft');
        $this->assertCount(2, $res->json('data.lines'));
    }

    public function test_index_lists_entries_with_reversal_state(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/v1/accounting/journal-entries', $this->balancedPayload(300, true))->json('data.id');
        $this->postJson("/api/v1/accounting/journal-entries/{$id}/reverse");

        $res = $this->getJson('/api/v1/accounting/journal-entries')->assertOk();
        $this->assertCount(2, $res->json('data')); // الأصل + العاكس
        $original = collect($res->json('data'))->firstWhere('reverses_entry_id', null);
        $this->assertTrue($original['is_reversed']);
    }

    public function test_unbalanced_entry_rejected(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/accounting/journal-entries', [
            'lines' => [
                ['account_code' => '1010', 'debit' => 100],
                ['account_code' => '1100', 'credit' => 50],
            ],
        ])->assertUnprocessable();
    }

    public function test_line_cannot_be_both_debit_and_credit(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/v1/accounting/journal-entries', [
            'lines' => [
                ['account_code' => '1010', 'debit' => 100, 'credit' => 100],
                ['account_code' => '1100', 'credit' => 100],
            ],
        ])->assertUnprocessable();
    }

    public function test_cannot_post_to_a_non_postable_group_account(): void
    {
        Sanctum::actingAs($this->admin());

        // 1000 هو مجموعة رئيسية غير قابلة للترحيل.
        $this->postJson('/api/v1/accounting/journal-entries', [
            'lines' => [
                ['account_code' => '1000', 'debit' => 100],
                ['account_code' => '1100', 'credit' => 100],
            ],
        ])->assertUnprocessable();
    }

    public function test_create_and_post_then_immutable(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/v1/accounting/journal-entries', $this->balancedPayload(300, true))->assertCreated()->json('data.id');

        $this->getJson("/api/v1/accounting/journal-entries/{$id}")->assertJsonPath('data.status', 'posted');
        // ترحيل مُجدّد مرفوض (لا حالة draft).
        $this->postJson("/api/v1/accounting/journal-entries/{$id}/post")->assertUnprocessable();
    }

    public function test_reverse_creates_balanced_reversing_entry(): void
    {
        Sanctum::actingAs($this->admin());
        $id = $this->postJson('/api/v1/accounting/journal-entries', $this->balancedPayload(300, true))->json('data.id');

        $rev = $this->postJson("/api/v1/accounting/journal-entries/{$id}/reverse")->assertCreated();
        $rev->assertJsonPath('data.status', 'posted');
        // القيد العاكس يقلب المدين/الدائن.
        $lines = collect($rev->json('data.lines'));
        $this->assertEquals(300, (float) $lines->firstWhere('account_code', '1100')['debit']);
        $this->assertEquals(300, (float) $lines->firstWhere('account_code', '1010')['credit']);

        // لا يمكن عكس نفس القيد مرّتين.
        $this->postJson("/api/v1/accounting/journal-entries/{$id}/reverse")->assertUnprocessable();
    }

    public function test_trial_balance_is_balanced(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/accounting/journal-entries', $this->balancedPayload(500, true));

        $res = $this->getJson('/api/v1/accounting/reports/trial-balance')->assertOk();
        $this->assertEquals($res->json('totals.debit'), $res->json('totals.credit'));
        $this->assertGreaterThan(0, $res->json('totals.debit'));
    }

    public function test_account_balance_derived_from_ledger(): void
    {
        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/accounting/journal-entries', $this->balancedPayload(250, true));

        $cash = Account::where('code', '1010')->first();
        $this->getJson("/api/v1/accounting/accounts/{$cash->uuid}/balance")
            ->assertOk()->assertJsonPath('balance', 250);
    }

    public function test_sales_role_cannot_access_accounting(): void
    {
        Sanctum::actingAs($this->withRole('sales'));

        $this->getJson('/api/v1/accounting/journal-entries')->assertForbidden();
        $this->postJson('/api/v1/accounting/journal-entries', $this->balancedPayload())->assertForbidden();
    }

    public function test_accountant_can_manage_journal(): void
    {
        Sanctum::actingAs($this->withRole('accountant'));

        $id = $this->postJson('/api/v1/accounting/journal-entries', $this->balancedPayload(100, true))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/accounting/journal-entries/{$id}/reverse")->assertCreated();
    }
}
