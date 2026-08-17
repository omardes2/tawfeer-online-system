<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductReview;
use App\Modules\Catalog\Services\ReviewImportService;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * استيراد آراء زبائن قيلت فعلًا خارج المتجر.
 *
 * الغاية نقلُ رأيٍ قيل، لا إنشاؤه. ولذلك كل ما يلي يحرس صدقَ ما يُنشر: الرأي
 * يلزم صنفه المحدَّد، ويلزم صاحبه، ويحمل أثر مصدره.
 */
class ReviewImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
    }

    /** @param array<int, array<int, string>> $rows */
    private function csv(array $rows, array $header = ['الصنف', 'الهاتف', 'الاسم', 'التقييم', 'الرأي', 'التاريخ']): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rev').'.csv';
        $out = fopen($path, 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);

        return $path;
    }

    private function service(): ReviewImportService
    {
        return app(ReviewImportService::class);
    }

    private function product(string $name = 'جهاز تعطير'): Product
    {
        return Product::factory()->create(['name' => $name]);
    }

    // ────────── المطابقة ──────────

    public function test_it_imports_a_review_against_its_own_product(): void
    {
        $product = $this->product();
        $path = $this->csv([[$product->sku, '0599123456', 'أحمد', '5', 'ممتاز', '2026-06-14']]);

        $parsed = $this->service()->parse($path);
        $this->assertSame([], $parsed['errors']);

        $summary = $this->service()->import($parsed['rows'], $this->admin);

        $review = ProductReview::firstOrFail();
        $this->assertSame($product->id, $review->product_id);
        $this->assertSame(5, $review->rating);
        $this->assertSame('ممتاز', $review->body);
        $this->assertSame(1, $summary['customers_created']);
        // التاريخ الأصلي لا تاريخ الاستيراد.
        $this->assertSame('2026-06-14', $review->created_at->toDateString());
    }

    /** ويُطابَق بالاسم الحرفي أيضًا حين لا يُكتب الرمز. */
    public function test_a_product_can_be_matched_by_its_exact_name(): void
    {
        $product = $this->product('شواية متنقلة');
        $path = $this->csv([['شواية متنقلة', '0599123456', '', '4', 'جيدة', '']]);

        $parsed = $this->service()->parse($path);

        $this->assertSame($product->id, $parsed['rows'][0]['product_id']);
    }

    /**
     * وصنفٌ لا يُطابِق شيئًا يُرفض ولا يُخمَّن.
     *
     * رأيٌ حقيقي مُعلَّق على صنفٍ لم يُقَل فيه يصير مضلِّلًا رغم صدقه.
     */
    public function test_an_unmatched_product_is_rejected_not_guessed(): void
    {
        $this->product();
        $path = $this->csv([['صنف لا وجود له', '0599123456', '', '5', 'ممتاز', '']]);

        $parsed = $this->service()->parse($path);

        $this->assertSame([], $parsed['rows']);
        $this->assertStringContainsString('لا صنف', $parsed['errors'][0]);
    }

    // ────────── صاحب الرأي ──────────

    /** الزبون المسجَّل يُطابَق ولا يُكرَّر. */
    public function test_an_existing_customer_is_matched_not_duplicated(): void
    {
        $product = $this->product();
        $customer = Customer::factory()->create(['primary_phone' => '0599123456']);
        $before = Customer::count();

        $path = $this->csv([[$product->sku, '0599123456', 'أحمد', '5', 'ممتاز', '']]);
        $summary = $this->service()->import($this->service()->parse($path)['rows'], $this->admin);

        $this->assertSame(0, $summary['customers_created']);
        $this->assertSame($before, Customer::count());
        $this->assertSame($customer->id, ProductReview::firstOrFail()->customer_id);
    }

    /**
     * والصيغة الدولية تُطابق المحلية.
     *
     * زبائن اللوحة مسجّلون `0599…` وتصدير واتساب يعطي `970599…` — والبحث بصيغةٍ
     * واحدة كان يُنشئ زبونًا ثانيًا للشخص نفسه فتتفرّق آراؤه وطلباتُه.
     */
    public function test_an_international_number_matches_the_local_one(): void
    {
        $product = $this->product();
        $customer = Customer::factory()->create(['primary_phone' => '0599123456']);

        $path = $this->csv([[$product->sku, '+970599123456', 'أحمد', '5', 'ممتاز', '']]);
        $summary = $this->service()->import($this->service()->parse($path)['rows'], $this->admin);

        $this->assertSame(0, $summary['customers_created']);
        $this->assertSame($customer->id, ProductReview::firstOrFail()->customer_id);
    }

    /** وبلا هاتف لا صاحب، فلا يُستورد الرأي. */
    public function test_a_review_without_a_phone_is_rejected(): void
    {
        $product = $this->product();
        $path = $this->csv([[$product->sku, '', 'أحمد', '5', 'ممتاز', '']]);

        $parsed = $this->service()->parse($path);

        $this->assertSame([], $parsed['rows']);
        $this->assertStringContainsString('هاتف', $parsed['errors'][0]);
    }

    /** والزبون الجديد يُنشأ بحساب أستاذٍ كامل لا بإدراجٍ مباشر. */
    public function test_a_new_customer_gets_a_ledger_account(): void
    {
        $product = $this->product();
        $path = $this->csv([[$product->sku, '0599999999', 'زبون واتساب', '5', 'ممتاز', '']]);

        $this->service()->import($this->service()->parse($path)['rows'], $this->admin);

        $customer = Customer::where('primary_phone', '0599999999')->firstOrFail();
        $this->assertNotNull($customer->gl_account_id, 'زبونٌ بلا حساب أستاذ تنكسر ذمّته لاحقًا.');
    }

    // ────────── الحماية من التكرار ──────────

    public function test_a_duplicate_row_inside_the_file_is_skipped(): void
    {
        $product = $this->product();
        $path = $this->csv([
            [$product->sku, '0599123456', 'أحمد', '5', 'ممتاز', ''],
            [$product->sku, '0599123456', 'أحمد', '4', 'مرة أخرى', ''],
        ]);

        $parsed = $this->service()->parse($path);

        $this->assertCount(1, $parsed['rows']);
        $this->assertStringContainsString('مكرّر', $parsed['errors'][0]);
    }

    /** ورأيٌ مسجَّل سابقًا لا يُستورد ثانيةً — القيد قائم في قاعدة البيانات. */
    public function test_an_already_reviewed_pair_is_skipped(): void
    {
        $product = $this->product();
        $customer = Customer::factory()->create(['primary_phone' => '0599123456']);
        ProductReview::create([
            'product_id' => $product->id, 'customer_id' => $customer->id, 'rating' => 4,
        ]);

        $path = $this->csv([[$product->sku, '0599123456', 'أحمد', '5', 'ممتاز', '']]);
        $parsed = $this->service()->parse($path);

        $this->assertSame([], $parsed['rows']);
        $this->assertStringContainsString('مسبقًا', $parsed['errors'][0]);
    }

    public function test_a_rating_outside_one_to_five_is_rejected(): void
    {
        $product = $this->product();
        $path = $this->csv([[$product->sku, '0599123456', '', '9', 'ممتاز', '']]);

        $this->assertStringContainsString('خارج المدى', $this->service()->parse($path)['errors'][0]);
    }

    // ────────── الحالة والأثر ──────────

    /** تصل معلّقة افتراضيًّا — النشر قرارٌ مستقلّ عن النقل. */
    public function test_reviews_arrive_pending_by_default(): void
    {
        $product = $this->product();
        $path = $this->csv([[$product->sku, '0599123456', '', '5', 'ممتاز', '']]);

        $this->service()->import($this->service()->parse($path)['rows'], $this->admin);

        $this->assertSame(ProductReview::PENDING, ProductReview::firstOrFail()->status);
    }

    /** ويُسجَّل مصدرها فيبقى أثرٌ صادق لكيفية وصولها. */
    public function test_the_source_is_recorded_on_every_imported_review(): void
    {
        $product = $this->product();
        $path = $this->csv([[$product->sku, '0599123456', '', '5', 'ممتاز', '']]);

        $this->service()->import($this->service()->parse($path)['rows'], $this->admin, approve: true, source: 'واتساب');

        $review = ProductReview::firstOrFail();
        $this->assertSame(ProductReview::APPROVED, $review->status);
        $this->assertStringContainsString('مستورد من واتساب', $review->moderation_note);
        // ولا طلب مطابقًا هنا، فيُقال ذلك صراحةً بدل أن يُقرأ كشراءٍ موثَّق.
        $this->assertStringContainsString('بلا طلب مطابق', $review->moderation_note);
    }

    // ────────── الشاشة ──────────

    public function test_the_import_screen_previews_without_writing(): void
    {
        $product = $this->product();
        $path = $this->csv([[$product->sku, '0599123456', 'أحمد', '5', 'ممتاز', '']]);

        $this->actingAs($this->admin)
            ->post(route('admin.reviews.import.upload'), [
                'file' => new UploadedFile($path, 'reviews.csv', 'text/csv', null, true),
                'preview' => '1',
            ])
            ->assertOk()
            ->assertSee('أحمد', false);

        $this->assertSame(0, ProductReview::count(), 'كتبت المعاينة في قاعدة البيانات.');
    }

    public function test_only_a_review_moderator_may_import(): void
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole('warehouse'); // يرى التقييمات ولا ينشرها

        $this->actingAs($user)->get(route('admin.reviews.import'))->assertForbidden();
    }
}
