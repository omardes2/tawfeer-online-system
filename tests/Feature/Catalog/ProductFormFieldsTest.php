<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductTag;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حقول نموذج المنتج بعد تنظيفه.
 *
 * أُزيلت الحقول الإنجليزية والحجم (CBM) والوسوم من الشاشة. الخطر في الإزالة
 * ليس المظهر بل الصمت: الحقل الغائب لا يُرسَل، وما كان يُفرَض له افتراضٌ فارغ
 * في المتحكّم يمسح بياناتٍ قائمة عند كل حفظ.
 */
class ProductFormFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    public function test_the_removed_fields_are_gone_from_the_form(): void
    {
        $product = Product::factory()->create();

        $html = $this->get(route('admin.products.edit', $product))->assertOk()->getContent();

        // على عناصر النموذج لا على أي ذكرٍ في الصفحة: مساعد الذكاء الاصطناعي
        // يذكر أسماء الحقول داخل سكربته، وهو يتخطّاها بصمت حين لا يجدها.
        foreach (['name_en', 'short_description_en', 'description_en', 'cbm', 'tag_ids\[\]'] as $field) {
            $this->assertDoesNotMatchRegularExpression(
                '/<(?:input|textarea|select)[^>]*name="'.$field.'"/',
                $html,
                "الحقل {$field} ما زال في النموذج.",
            );
        }
    }

    /** وما بقي باقٍ: الحقول العربية والتسعير والسمات وSEO. */
    public function test_the_kept_fields_are_still_there(): void
    {
        $product = Product::factory()->create();
        // بلا سمةٍ واحدة على الأقلّ يعرض القسم «لا توجد سمات» بلا مربّعات.
        ProductAttribute::create(['name' => 'مقاس', 'slug' => 'size-kept', 'type' => 'select']);

        $html = $this->get(route('admin.products.edit', $product))->assertOk()->getContent();

        foreach ([
            'name="name"', 'name="barcode"', 'name="slug"', 'name="category_id"', 'name="brand_id"',
            'name="unit_id"', 'name="short_description"', 'name="description"', 'name="retail_price"',
            'name="promo_price"', 'name="reorder_level"', 'name="attribute_ids[]"', 'name="meta_title"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    /**
     * الحفظ بلا حقل الوسوم لا يمسح وسوم الصنف.
     *
     * المتحكّم كان يفرض `tag_ids = []` حين تغيب، فيفصل كل وسمٍ عند أول حفظ.
     */
    public function test_saving_without_the_tags_field_keeps_existing_tags(): void
    {
        $tag = ProductTag::create(['name' => 'وسم قائم', 'slug' => 'kept-tag']);
        $product = Product::factory()->create(['name' => 'صنف موسوم']);
        $product->tags()->sync([$tag->id]);

        $this->put(route('admin.products.update', $product), [
            'name' => 'صنف موسوم (معدّل)',
            'category_id' => $product->category_id ?: Category::factory()->create()->id,
            'unit_id' => $product->unit_id ?: Unit::first()?->id,
            'status' => $product->status,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('صنف موسوم (معدّل)', $product->fresh()->name);
        $this->assertSame([$tag->id], $product->fresh()->tags->pluck('id')->all());
    }

    /** والحجم (CBM) يبقى في البيانات وإن غاب عن الشاشة — تستعمله فواتير الاستيراد. */
    public function test_saving_without_the_cbm_field_keeps_the_stored_volume(): void
    {
        $product = Product::factory()->create(['cbm' => 0.00531]);

        $this->put(route('admin.products.update', $product), [
            'name' => $product->name,
            'category_id' => $product->category_id ?: Category::factory()->create()->id,
            'unit_id' => $product->unit_id ?: Unit::first()?->id,
            'status' => $product->status,
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.00531, (float) $product->fresh()->cbm);
    }

    /** وتفريغ السمات ما زال ممكنًا: حقلها في النموذج، فغيابه يعني «لا شيء مختار». */
    public function test_attributes_can_still_be_cleared(): void
    {
        $product = Product::factory()->create();
        $attribute = ProductAttribute::create([
            'name' => 'مقاس', 'slug' => 'size-form', 'type' => 'select',
        ]);
        $product->attributes()->sync([$attribute->id]);

        $this->put(route('admin.products.update', $product), [
            'name' => $product->name,
            'category_id' => $product->category_id ?: Category::factory()->create()->id,
            'unit_id' => $product->unit_id ?: Unit::first()?->id,
            'status' => $product->status,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($product->fresh()->attributes->isEmpty());
    }
}
