<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductImageService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * الرفع لا يترك سجلّ صورة بلا ملف.
 *
 * قرص `public` مضبوط على `throw => false`، فالكتابة الفاشلة تعود بـfalse بصمت.
 * كان السجلّ يُنشأ رغم ذلك، فتظهر صورة مكسورة في الصفحة بدل رسالة خطأ مفهومة.
 */
class ProductImageStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function jpeg(): UploadedFile
    {
        $img = imagecreatetruecolor(400, 300);
        imagefill($img, 0, 0, imagecolorallocate($img, 20, 120, 80));
        $path = tempnam(sys_get_temp_dir(), 'img').'.jpg';
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    public function test_successful_upload_writes_the_file(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();

        $image = app(ProductImageService::class)->store($product, $this->jpeg());

        Storage::disk('public')->assertExists($image->path);
        $this->assertSame(1, $product->images()->count());
    }

    /** فشل الكتابة يُوقف العملية ولا يُبقي سجلًّا يتيمًا. */
    public function test_a_failed_write_leaves_no_orphan_record(): void
    {
        $product = Product::factory()->create();

        $disk = \Mockery::mock(Storage::disk('public'))->makePartial();
        $disk->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        try {
            app(ProductImageService::class)->store($product, $this->jpeg());
            $this->fail('كان يجب أن تفشل العملية بوضوح.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('تعذّر حفظ الصورة', $e->getMessage());
        }

        $this->assertSame(0, $product->images()->count(), 'بقي سجلّ صورة بلا ملف.');
    }
}
