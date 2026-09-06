<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductService;
use App\Support\SlugGenerator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الـslug يتفادى المحذوف ناعمًا.
 *
 * ## العطب
 *
 * قيد التفرّد في قاعدة البيانات يشمل الصفّ المحذوف ناعمًا — الحذف الناعم لا
 * يُفرّغ العمود. وكان مولّد الـslug يفحص الأحياء وحدهم، فيرى الاسم متاحًا
 * ويُعطيه، ثم ترفضه قاعدة البيانات:
 *
 *     SQLSTATE[23000]: 1062 Duplicate entry 'lask' for key 'products_slug_unique'
 *
 * وهو خطأ ٥٠٠ لا رسالةَ تحقّق — يسقط عليه **حفظ فاتورة الشراء** حين تُنشئ
 * صنفًا جديدًا اسمُه يطابق صنفًا محذوفًا.
 *
 * ## وسببه سطرٌ يقول عكس ما يفعل
 *
 * الشرط كان `method_exists($modelClass, 'withTrashed')`، و`withTrashed()` ليست
 * دالّةً على النموذج: يُضيفها نطاقُ الحذف الناعم إلى **بانِي الاستعلام**. فكان
 * الشرط false دائمًا ولا تُشمل المحذوفة قطّ — والتعليق فوقه يقول إنها تُشمل.
 */
class SlugAvoidsTrashedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** الحارس نفسه: النموذج يستعمل الحذف الناعم ولا يحمل الدالّة. */
    public function test_the_model_has_soft_deletes_but_not_the_method(): void
    {
        $this->assertFalse(method_exists(Product::class, 'withTrashed'));
        $this->assertContains(SoftDeletes::class, class_uses_recursive(Product::class));
    }

    /** **slug محذوفٍ ناعمًا لا يُعاد استعماله.** */
    public function test_a_trashed_slug_is_not_reused(): void
    {
        $first = Product::factory()->create(['name' => 'لاسك', 'slug' => 'lask']);
        $first->delete();

        $this->assertSoftDeleted('products', ['id' => $first->id]);

        $this->assertNotSame('lask', SlugGenerator::make(Product::class, 'لاسك'));
    }

    /**
     * **وإنشاء صنفٍ باسم محذوف ينجح** — وهو ما كان يُسقط حفظ فاتورة الشراء.
     */
    public function test_creating_a_product_named_like_a_trashed_one_succeeds(): void
    {
        Product::factory()->create(['name' => 'لاسك', 'slug' => 'lask'])->delete();

        $template = Product::factory()->create();

        $product = app(ProductService::class)->create([
            'name' => 'لاسك',
            'category_id' => $template->category_id,
            'unit_id' => $template->unit_id,
            'retail_price' => 10,
            'cost_price' => 5,
        ]);

        $this->assertNotSame('lask', $product->slug);
        $this->assertSame(1, Product::where('slug', $product->slug)->count());
    }

    /** والحيّ كما كان — الفرادة تشمل الاثنين. */
    public function test_a_live_slug_is_still_avoided(): void
    {
        Product::factory()->create(['name' => 'لاسك', 'slug' => 'lask']);

        $this->assertNotSame('lask', SlugGenerator::make(Product::class, 'لاسك'));
    }

    /** وتعديلُ السجلّ نفسه يُبقي slug‑ه — لا يُزاحم نفسه. */
    public function test_a_record_does_not_collide_with_itself(): void
    {
        $product = Product::factory()->create(['name' => 'لاسك', 'slug' => 'lask']);

        $this->assertSame('lask', SlugGenerator::make(Product::class, 'لاسك', $product->id));
    }
}
