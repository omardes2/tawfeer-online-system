<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * سعر جملة المتغيّر — الاحتياط إلى سعر المنتج، وتعبئة العمود.
 *
 * شاشة تعديل المنتج فيها حقلُ جملةٍ واحد على مستوى المنتج، وكان يُنسخ إلى
 * المتغيّر الافتراضي وحده. فمنتجٌ بمقاسات تولد بقيّة متغيّراته بعمودٍ فارغ
 * يُقرأ صفرًا — وصفرٌ هنا لا يعني «مجّانًا» بل **«لا قيد»**، فيسقط معه حارس
 * البيع بأقل من الجملة وأساسُ عمولة المسوّق.
 */
class VariantWholesaleFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** منتجٌ بسعر جملةٍ ومتغيّرٍ إضافيّ بعمودٍ فارغ. */
    private function productWithBlankVariant(float $productWholesale = 80): array
    {
        $product = Product::factory()->create([
            'name' => 'صنف بمقاسات',
            'retail_price' => 120,
            'wholesale_price' => $productWholesale,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'sku' => 'V-BLANK-1',
            'is_default' => false,
            'retail_price' => 120,
            'wholesale_price' => null,
            'average_cost' => 40,
        ]);

        return [$product, $variant];
    }

    // ────────── (أ) الاحتياط في القراءة ──────────

    /** المتغيّر الفارغ يقرأ سعر منتجه. */
    public function test_a_blank_variant_reads_its_product_wholesale(): void
    {
        [, $variant] = $this->productWithBlankVariant();

        $this->assertSame(80.0, $variant->fresh()->load('product')->effectiveWholesalePrice());
    }

    /** وسعرُ المتغيّر يفوز على سعر المنتج حين يوجد. */
    public function test_the_variant_price_wins_over_the_product_price(): void
    {
        [$product, $variant] = $this->productWithBlankVariant();
        $variant->update(['wholesale_price' => 95]);

        $this->assertSame(95.0, $variant->fresh()->load('product')->effectiveWholesalePrice());
        $this->assertSame('80.00', $product->fresh()->wholesale_price);
    }

    /**
     * وصفرٌ في الموضعين يبقى صفرًا.
     *
     * «لا قيد» كما يفهمه الحارس — لا سعرَ جملةٍ أصلًا لهذا الصنف.
     */
    public function test_no_price_anywhere_stays_zero(): void
    {
        $product = Product::factory()->create(['wholesale_price' => null]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'sku' => 'V-NONE-1',
            'is_default' => false, 'wholesale_price' => null,
        ]);

        $this->assertSame(0.0, $variant->fresh()->load('product')->effectiveWholesalePrice());
    }

    // ────────── (ب) تعبئة العمود ──────────

    /**
     * حفظ سعر جملة المنتج يملأ متغيّراته الفارغة.
     *
     * الاحتياط يُصلح القراءة، والتعبئة تُصلح ما يقرأ العمود مباشرةً: تقريرٌ
     * باستعلامٍ خام، أو تصديرٌ إلى ملف.
     */
    public function test_saving_the_product_fills_its_blank_variants(): void
    {
        [$product, $variant] = $this->productWithBlankVariant();

        app(ProductService::class)->update($product, ['wholesale_price' => 85]);

        $this->assertSame('85.00', $variant->fresh()->wholesale_price);
    }

    /**
     * ولا يسحق متغيّرًا سُعِّر بيدٍ صريحة.
     *
     * مقاسٌ أكبر أغلى قرارُ تسعيرٍ لا بيانات ناقصة، وإعادتُه إلى سعر المنتج
     * العام تمحو عملًا يدويًّا بلا أن يقصد أحد.
     */
    public function test_it_never_overwrites_a_deliberately_priced_variant(): void
    {
        [$product, $variant] = $this->productWithBlankVariant();
        $variant->update(['wholesale_price' => 95]);

        app(ProductService::class)->update($product, ['wholesale_price' => 85]);

        $this->assertSame('95.00', $variant->fresh()->wholesale_price);
    }

    /** والهجرة تملأ ما وُلد فارغًا قبل الإصلاح. */
    public function test_the_backfill_migration_fills_pre_existing_blanks(): void
    {
        [, $variant] = $this->productWithBlankVariant();

        // إعادةٌ إلى الحالة السابقة للإصلاح: العمود فارغ في قاعدة البيانات.
        DB::table('product_variants')->where('id', $variant->id)->update(['wholesale_price' => null]);
        $this->assertNull($variant->fresh()->wholesale_price);

        $this->runBackfill();

        $this->assertSame('80.00', $variant->fresh()->wholesale_price);
    }

    /** ولا تمسّ الهجرةُ المُسعَّر. */
    public function test_the_backfill_migration_leaves_priced_variants_alone(): void
    {
        [, $variant] = $this->productWithBlankVariant();
        DB::table('product_variants')->where('id', $variant->id)->update(['wholesale_price' => 95]);

        $this->runBackfill();

        $this->assertSame('95.00', $variant->fresh()->wholesale_price);
    }

    private function runBackfill(): void
    {
        (require base_path(
            'database/migrations/2026_08_22_140000_backfill_variant_wholesale_price_from_product.php'
        ))->up();
    }
}
