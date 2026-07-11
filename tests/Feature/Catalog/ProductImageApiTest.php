<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImageApiTest extends TestCase
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
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    public function test_first_uploaded_image_becomes_primary_and_is_stored(): void
    {
        Sanctum::actingAs($this->admin());
        $product = Product::factory()->create();

        $res = $this->postJson("/api/v1/products/{$product->uuid}/images", [
            'image' => UploadedFile::fake()->image('a.jpg'),
        ])->assertCreated();

        $res->assertJsonPath('data.is_primary', true);
        $path = ProductImage::first()->path;
        Storage::disk('public')->assertExists($path);
    }

    public function test_only_one_primary_image(): void
    {
        Sanctum::actingAs($this->admin());
        $product = Product::factory()->create();

        $this->postJson("/api/v1/products/{$product->uuid}/images", ['image' => UploadedFile::fake()->image('a.jpg')])->assertCreated();
        $this->postJson("/api/v1/products/{$product->uuid}/images", ['image' => UploadedFile::fake()->image('b.jpg'), 'is_primary' => true])->assertCreated();

        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    public function test_set_primary_switches(): void
    {
        Sanctum::actingAs($this->admin());
        $product = Product::factory()->create();
        $img1 = ProductImage::factory()->primary()->create(['product_id' => $product->id]);
        $img2 = ProductImage::factory()->create(['product_id' => $product->id]);

        $this->postJson("/api/v1/products/{$product->uuid}/images/{$img2->id}/primary")->assertOk();

        $this->assertFalse($img1->fresh()->is_primary);
        $this->assertTrue($img2->fresh()->is_primary);
    }

    public function test_non_image_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $product = Product::factory()->create();
        $this->postJson("/api/v1/products/{$product->uuid}/images", [
            'image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_delete_image_removes_file_and_promotes_next(): void
    {
        Sanctum::actingAs($this->admin());
        $product = Product::factory()->create();
        $this->postJson("/api/v1/products/{$product->uuid}/images", ['image' => UploadedFile::fake()->image('a.jpg')])->assertCreated();
        $this->postJson("/api/v1/products/{$product->uuid}/images", ['image' => UploadedFile::fake()->image('b.jpg')])->assertCreated();
        $primary = $product->images()->where('is_primary', true)->first();

        $this->deleteJson("/api/v1/products/{$product->uuid}/images/{$primary->id}")->assertOk();

        Storage::disk('public')->assertMissing($primary->path);
        $this->assertSame(1, $product->images()->count());
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    public function test_scoped_binding_rejects_image_from_other_product(): void
    {
        Sanctum::actingAs($this->admin());
        $p1 = Product::factory()->create();
        $p2 = Product::factory()->create();
        $img = ProductImage::factory()->create(['product_id' => $p2->id]);

        $this->deleteJson("/api/v1/products/{$p1->uuid}/images/{$img->id}")->assertNotFound();
    }

    public function test_sales_cannot_upload(): void
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('sales');
        Sanctum::actingAs($u);
        $product = Product::factory()->create();

        $this->postJson("/api/v1/products/{$product->uuid}/images", ['image' => UploadedFile::fake()->image('a.jpg')])->assertForbidden();
    }
}
