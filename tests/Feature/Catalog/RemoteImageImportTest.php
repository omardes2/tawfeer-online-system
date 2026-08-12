<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\RemoteImageImportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * سحب صور المنتجات من الموقع القديم عبر ملف ربط (اسم الصنف + رابط الصورة):
 * مطابقة متسامحة مع اختلاف رسم الحروف العربية، وتحويل إلى WebP كالرفع اليدوي.
 */
class RemoteImageImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    /** ملف CSV مؤقّت بترويسة عربية. */
    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'map').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF"."اسم الصنف,رابط الصورة\n".$body);

        return $path;
    }

    /** استجابة صورة JPEG حقيقية. */
    private function fakeJpeg(int $w = 900, int $h = 600): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 90));
        ob_start();
        imagejpeg($img, null, 90);
        imagedestroy($img);

        return ob_get_clean();
    }

    public function test_downloads_and_attaches_image_as_webp(): void
    {
        $product = Product::factory()->create(['name' => 'حامل الهاتف']);
        Http::fake(['*' => Http::response($this->fakeJpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

        $svc = app(RemoteImageImportService::class);
        $parsed = $svc->parse($this->csv("حامل الهاتف,https://tawfeer-online.com/img/1.jpg\n"));

        $this->assertCount(1, $parsed['rows']);
        $this->assertSame([], $parsed['errors']);

        $result = $svc->import($parsed['rows']);

        $this->assertSame(1, $result['attached']);
        $image = $product->images()->firstOrFail();
        $this->assertTrue($image->is_primary);
        $this->assertStringEndsWith('.webp', $image->path);
        Storage::disk('public')->assertExists($image->path);
    }

    /** المطابقة تتسامح مع «أ/ا» و«ة/ه» والمسافات الزائدة بين الموقعين. */
    public function test_matching_tolerates_arabic_spelling_variants(): void
    {
        Product::factory()->create(['name' => 'أحذية رياضية']);
        Http::fake(['*' => Http::response($this->fakeJpeg(), 200)]);

        $parsed = app(RemoteImageImportService::class)
            ->parse($this->csv("احذيه   رياضيه,https://old.example.com/a.jpg\n"));

        $this->assertCount(1, $parsed['rows']);
        $this->assertSame('أحذية رياضية', $parsed['rows'][0]['product']->name);
    }

    /** المطابقة بالكود (SKU) مدعومة أيضًا. */
    public function test_matching_by_sku(): void
    {
        $product = Product::factory()->create(['name' => 'صنف', 'sku' => 'P-ABC123']);
        Http::fake(['*' => Http::response($this->fakeJpeg(), 200)]);

        $parsed = app(RemoteImageImportService::class)
            ->parse($this->csv("P-ABC123,https://old.example.com/b.jpg\n"));

        $this->assertSame($product->id, $parsed['rows'][0]['product']->id);
    }

    /** الصنف غير الموجود والرابط الفاسد يُتخطّيان بسبب واضح بدل إسقاط العملية. */
    public function test_unmatched_and_invalid_rows_are_reported(): void
    {
        Product::factory()->create(['name' => 'موجود']);

        $parsed = app(RemoteImageImportService::class)->parse($this->csv(
            "موجود,https://old.example.com/ok.jpg\nغير موجود,https://old.example.com/x.jpg\nموجود,ليس رابطًا\n"
        ));

        $this->assertCount(1, $parsed['rows']);
        $this->assertCount(2, $parsed['errors']);
    }

    /** محتوى ليس صورة (صفحة خطأ) لا يُربط بالمنتج. */
    public function test_non_image_response_is_rejected(): void
    {
        Product::factory()->create(['name' => 'صنف']);
        Http::fake(['*' => Http::response('<html>404</html>', 200, ['Content-Type' => 'text/html'])]);

        $svc = app(RemoteImageImportService::class);
        $parsed = $svc->parse($this->csv("صنف,https://old.example.com/missing.jpg\n"));
        $result = $svc->import($parsed['rows']);

        $this->assertSame(0, $result['attached']);
        $this->assertCount(1, $result['failed']);
    }

    /** الصنف الذي له صور مسبقًا يُتخطّى افتراضيًا (لا استبدال بغير قصد). */
    public function test_products_with_existing_images_are_skipped_by_default(): void
    {
        $product = Product::factory()->create(['name' => 'له صورة']);
        $product->images()->create(['path' => 'products/old.webp', 'is_primary' => true, 'sort_order' => 0]);
        Http::fake(['*' => Http::response($this->fakeJpeg(), 200)]);

        $svc = app(RemoteImageImportService::class);
        $parsed = $svc->parse($this->csv("له صورة,https://old.example.com/c.jpg\n"));

        $this->assertSame(0, $svc->import($parsed['rows'])['attached']);
        $this->assertSame(1, $svc->import($parsed['rows'], skipIfHasImages: false)['attached']);
    }

    /** الأمر بلا --force معاينة لا تُنزّل شيئًا. */
    public function test_command_preview_downloads_nothing(): void
    {
        Product::factory()->create(['name' => 'معاينة']);
        Http::fake(function (Request $r) {
            $this->fail('لا يجوز أي طلب شبكة في وضع المعاينة.');
        });

        $this->artisan('catalog:import-images', ['file' => $this->csv("معاينة,https://old.example.com/d.jpg\n")])
            ->assertSuccessful();

        $this->assertSame(0, Product::where('name', 'معاينة')->firstOrFail()->images()->count());
    }
}
