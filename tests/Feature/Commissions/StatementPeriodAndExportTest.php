<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * كشف حساب المسوّق: الفترة، والتصدير، ورقم التتبّع.
 *
 * الفلتر كان يُصفّي على **تاريخ تسجيل الحركة** بينما الجدول يعرض **تاريخ
 * الطلب**. يتطابقان في الحالة العادية ويفترقان عند التصحيح: حركةٌ لطلبِ تمّوز
 * صُحّحت في آب كانت تظهر في فلتر آب وتغيب عن تمّوز — والسطر نفسه يقول «تمّوز».
 */
class StatementPeriodAndExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $earner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->earner = User::factory()->create([
            'name' => 'سائد شاهين', 'branch_id' => Branch::default()->id,
        ]);
        $this->earner->assignRole('affiliate');
    }

    /** حركةٌ لطلبٍ بتاريخٍ محدّد، ورقم تتبّعٍ اختياري. */
    private function entry(string $orderDate, float $amount = 100, ?string $tracking = null): CommissionEntry
    {
        $product = Product::factory()->create(['name' => 'عطر سمارت']);

        $order = Order::factory()->create([
            'branch_id' => Branch::default()->id,
            'affiliate_id' => $this->earner->id,
            'created_at' => Carbon::parse($orderDate),
            'tracking_number' => $tracking,
        ]);

        return CommissionEntry::create([
            'earner_type' => 'affiliate', 'earner_id' => $this->earner->id,
            'order_id' => $order->id, 'variant_id' => $product->defaultVariant->id,
            'entry_type' => 'accrual', 'basis' => $amount, 'rate' => 1.0, 'amount' => $amount,
            'rule_snapshot' => ['method' => 'margin', 'rate' => 1.0],
            'state' => 'eligible',
            // تُسجَّل في آب — كما يحدث بعد أمر التصحيح.
            'created_at' => Carbon::parse('2026-08-24'),
        ]);
    }

    private function statement(array $query = [])
    {
        // `array_merge` لا `+`: اتّحاد المصفوفات يُبقي مفاتيح اليسار، فكان
        // تجاوز التاريخ في الاختبارات لا يصل أبدًا وتُقرأ آب دائمًا.
        return $this->actingAs($this->admin)->get(route('admin.commissions.statement', array_merge([
            'earnerId' => $this->earner->id,
            'earner_type' => 'affiliate', 'from' => '2026-08-01', 'to' => '2026-08-31',
        ], $query)));
    }

    // ────────── الفترة ──────────

    /** طلبٌ داخل الفترة يظهر. */
    public function test_an_order_inside_the_period_is_listed(): void
    {
        $this->entry('2026-08-20');

        $this->statement()->assertOk()->assertSee('100.00');
    }

    /**
     * **وطلبٌ خارجها لا يظهر في الجدول ولو سُجّلت حركته داخلها.**
     *
     * هذا هو الخلل بعينه: الحركة أُنشئت في ٢٤ آب لطلبِ ١٠ تمّوز. كانت تُحسب في
     * آب لأن الفلتر يقرأ تاريخها هي، والسطر يقول «تاريخ الطلب: ١٠ تمّوز».
     *
     * والمؤشّر رقمُ الطلب لا المبلغ: بطاقتا «إجمالي المستحق» و«الرصيد المتبقّي»
     * تعرضان أرقام العمر كلّه عمدًا — وهما خارج الفلتر.
     */
    public function test_an_order_outside_the_period_is_excluded(): void
    {
        $july = $this->entry('2026-07-10', amount: 555);

        $this->statement()->assertOk()->assertDontSee($july->order->number);
    }

    /** ويظهر في فترته الحقيقية. */
    public function test_it_appears_in_its_real_period(): void
    {
        $july = $this->entry('2026-07-10', amount: 555);

        $this->statement(['from' => '2026-07-01', 'to' => '2026-07-31'])
            ->assertOk()->assertSee($july->order->number);
    }

    /** ومستحقّ الفترة يتبع تاريخ الطلب لا تاريخ الحركة. */
    public function test_the_period_total_follows_the_order_date(): void
    {
        $this->entry('2026-08-20', amount: 100);
        $this->entry('2026-07-10', amount: 555);

        // بطاقة «مستحقّ الفترة» وحدها: الإجماليّات الأخرى للعمر كلّه (٦٥٥)
        // وهي خارج الفلتر عمدًا.
        $html = $this->statement()->assertOk()->getContent();
        $this->assertStringContainsString('text-gray-900">100.00</span>', $html);
    }

    // ────────── رقم التتبّع ──────────

    /** رقم التتبّع يظهر بجانب الطلب. */
    public function test_the_tracking_number_is_shown(): void
    {
        $this->entry('2026-08-20', tracking: 'OP-99881122');

        $this->statement()->assertOk()->assertSee('OP-99881122');
    }

    /** وطلبٌ بلا رقم تتبّع لا يكسر الجدول. */
    public function test_an_order_without_tracking_renders(): void
    {
        $this->entry('2026-08-20');

        $this->statement()->assertOk();
    }

    // ────────── التصدير ──────────

    /**
     * قراءة ملفّ التصدير صفًّا صفًّا.
     *
     * الـxlsx أرشيفُ zip لا نصّ، والسلاسل المكرّرة تُخزَّن مرّةً واحدة بفهرس —
     * فالبحث في بايتات الملفّ يعدّ الاسم الواحد مرّةً مهما تكرّر في الصفوف.
     * فيُقرأ بقارئٍ حقيقيّ.
     *
     * @return array<int, array<int, mixed>>
     */
    private function readSheet(BinaryFileResponse $response): array
    {
        $reader = new Reader;
        $reader->open($response->getFile()->getPathname());

        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }

        $reader->close();

        return $rows;
    }

    /** نصُّ الورقة كاملًا — لتأكيد وجود قيمةٍ بصرف النظر عن موضعها. */
    private function sheetText(BinaryFileResponse $response): string
    {
        return collect($this->readSheet($response))
            ->map(fn (array $row) => implode('|', array_map(
                static fn ($c) => $c instanceof \DateTimeInterface ? $c->format('Y-m-d') : (string) $c,
                $row,
            )))
            ->implode("\n");
    }

    /** التصدير يُنزّل ملفَّ xlsx لا CSV بامتدادٍ مُضلِّل. */
    public function test_the_export_downloads_an_xlsx(): void
    {
        $this->entry('2026-08-20');

        $response = $this->statement(['export' => 'xlsx'])->assertOk()->baseResponse;

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        // الاسم الذي يصل المتصفّح في الترويسة لا اسم الملفّ المؤقّت على القرص.
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
        // توقيع أرشيف zip — وهو ما يجعل Excel يفتحه بلا حيلةِ BOM.
        $this->assertSame('PK', substr(file_get_contents($response->getFile()->getPathname()), 0, 2));
    }

    /** ويحمل الأعمدة والمجموع ورقم التتبّع. */
    public function test_the_export_carries_the_rows_and_total(): void
    {
        $this->entry('2026-08-20', amount: 100, tracking: 'OP-77');
        $this->entry('2026-08-21', amount: 50);

        $text = $this->sheetText($this->statement(['export' => 'xlsx'])->baseResponse);

        $this->assertStringContainsString('سائد شاهين', $text);
        $this->assertStringContainsString('OP-77', $text);
        $this->assertStringContainsString('150', $text);   // المجموع
    }

    /**
     * **ويُصدّر كل الفترة لا الصفحة المعروضة.**
     *
     * الشاشة ثلاثون صفًّا في الصفحة، والملفّ يُبنى عليه صرف. فكشفٌ ناقص أسوأ من
     * لا كشف.
     */
    public function test_the_export_is_not_limited_to_one_page(): void
    {
        for ($i = 0; $i < 35; $i++) {
            $this->entry('2026-08-20', amount: 10);
        }

        $rows = $this->readSheet($this->statement(['export' => 'xlsx'])->baseResponse);

        $named = collect($rows)->filter(
            fn (array $row) => in_array('عطر سمارت', array_map(static fn ($c) => (string) $c, $row), true),
        );

        $this->assertCount(35, $named);
        $this->assertStringContainsString('350', $this->sheetText($this->statement(['export' => 'xlsx'])->baseResponse));
    }

    /** وما خرج عن الفترة لا يدخل الملفّ. */
    public function test_the_export_respects_the_period(): void
    {
        $this->entry('2026-08-20', amount: 100);
        $this->entry('2026-07-10', amount: 555);

        $this->assertStringNotContainsString(
            '555',
            $this->sheetText($this->statement(['export' => 'xlsx'])->baseResponse),
        );
    }
}
