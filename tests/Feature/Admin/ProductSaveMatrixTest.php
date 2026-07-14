<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Unit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSaveMatrixTest extends TestCase
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

    private function fields(Product $p, array $o = []): array
    {
        return array_merge([
            'category_id' => Category::factory()->create()->id,
            'unit_id' => Unit::factory()->create()->id,
            'name' => 'صنف',
            'sku' => $p->sku,
            'status' => 'active',
        ], $o);
    }

    /** الحفظ بحقول أسعار فارغة يجب ألا يُسبّب خطأ 500 (أعمدة NOT NULL). */
    public function test_saving_with_empty_prices_does_not_500(): void
    {
        $p = Product::factory()->create();

        $this->actingAs($this->admin())->put('/admin/products/'.$p->uuid, $this->fields($p, [
            'retail_price' => '',
            'promo_price' => '',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(0, (float) $p->fresh()->retail_price, 0.01);
    }

    /** الحفظ بسعر بيع فقط دون سعر عرض. */
    public function test_saving_retail_only(): void
    {
        $p = Product::factory()->create();

        $this->actingAs($this->admin())->put('/admin/products/'.$p->uuid, $this->fields($p, [
            'retail_price' => '150',
            'promo_price' => '',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(150, (float) $p->fresh()->defaultVariant->retail_price, 0.01);
    }
}
