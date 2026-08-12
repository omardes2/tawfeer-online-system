<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * صور المنتج تُحوَّل إلى WebP عند الرفع وتُصغَّر بحدٍّ يناسب دورها (المصغّرة أصغر من
 * صور الألبوم) — أخفّ حجمًا مع بقاء الوضوح، بلا تكبير يفسد الجودة.
 */
class ProductImageWebpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('admin');

        return $u;
    }

    /** ملف JPEG حقيقي بأبعاد محدّدة (الملفات الوهمية بلا محتوى صورة لا تُحوَّل). */
    private function jpeg(int $w, int $h): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        $path = tempnam(sys_get_temp_dir(), 'img').'.jpg';
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    /** أبعاد الصورة المحفوظة على القرص الوهمي. */
    private function dimensions(string $path): array
    {
        $binary = Storage::disk('public')->get($path);
        $img = imagecreatefromstring($binary);
        $dims = [imagesx($img), imagesy($img)];
        imagedestroy($img);

        return $dims;
    }

    public function test_thumbnail_is_converted_to_webp_and_capped_at_600(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.products.images.store', $product), [
                'is_primary' => 1,
                'image' => $this->jpeg(2400, 1200),
            ])->assertRedirect();

        $image = $product->images()->firstOrFail();
        $this->assertStringEndsWith('.webp', $image->path);
        Storage::disk('public')->assertExists($image->path);
        $this->assertTrue($image->is_primary);

        // أطول ضلع 600 مع الحفاظ على النسبة (2:1).
        $this->assertSame([600, 300], $this->dimensions($image->path));

        // المحتوى فعلًا WebP لا مجرّد امتداد.
        $this->assertSame('image/webp', Storage::disk('public')->mimeType($image->path));
    }

    public function test_gallery_images_are_converted_and_capped_at_1600(): void
    {
        $product = Product::factory()->create();
        $product->images()->create(['path' => 'products/existing.webp', 'is_primary' => true, 'sort_order' => 0]);

        $this->actingAs($this->admin())
            ->post(route('admin.products.images.store', $product), [
                'images' => [$this->jpeg(3000, 3000), $this->jpeg(1000, 500)],
            ])->assertRedirect();

        $uploaded = $product->images()->where('is_primary', false)->get();
        $this->assertCount(2, $uploaded);

        foreach ($uploaded as $image) {
            $this->assertStringEndsWith('.webp', $image->path);
        }

        // الكبيرة صُغِّرت إلى 1600، والأصغر من الحدّ بقيت كما هي (لا تكبير).
        $this->assertSame([1600, 1600], $this->dimensions($uploaded[0]->path));
        $this->assertSame([1000, 500], $this->dimensions($uploaded[1]->path));
    }

    /** الحجم الناتج أصغر من الأصل بوضوح — الغرض من التحويل. */
    public function test_webp_output_is_smaller_than_source(): void
    {
        $product = Product::factory()->create();
        $source = $this->jpeg(2000, 2000);
        $sourceSize = filesize($source->getRealPath());

        $this->actingAs($this->admin())
            ->post(route('admin.products.images.store', $product), ['is_primary' => 1, 'image' => $source])
            ->assertRedirect();

        $path = $product->images()->firstOrFail()->path;
        $this->assertLessThan($sourceSize, Storage::disk('public')->size($path));
    }
}
