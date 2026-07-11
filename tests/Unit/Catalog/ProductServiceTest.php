<?php

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductServiceTest extends TestCase
{
    use RefreshDatabase;

    private function base(array $o = []): array
    {
        return array_merge([
            'category_id' => Category::factory()->create()->id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'اختبار',
            'sku' => 'T-'.uniqid(),
        ], $o);
    }

    public function test_create_derives_is_active_from_status(): void
    {
        $svc = app(ProductService::class);

        $draft = $svc->create($this->base(['status' => 'draft']));
        $active = $svc->create($this->base(['status' => 'active']));

        $this->assertFalse($draft->is_active);
        $this->assertTrue($active->is_active);
    }

    public function test_empty_slug_is_derived_from_name(): void
    {
        $svc = app(ProductService::class);
        $product = $svc->create($this->base(['name' => 'حذاء', 'slug' => '']));

        $this->assertNotSame('item', $product->slug);
        $this->assertNotEmpty($product->slug);
    }

    public function test_update_status_resyncs_is_active(): void
    {
        $svc = app(ProductService::class);
        $product = $svc->create($this->base(['status' => 'active']));

        $svc->update($product, ['status' => 'archived']);

        $this->assertFalse($product->fresh()->is_active);
    }
}
