<?php

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductReview;
use App\Modules\Catalog\Models\ProductTag;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Policies\BrandPolicy;
use App\Modules\Catalog\Policies\CategoryPolicy;
use App\Modules\Catalog\Policies\ProductAttributePolicy;
use App\Modules\Catalog\Policies\ProductPolicy;
use App\Modules\Catalog\Policies\ProductReviewPolicy;
use App\Modules\Catalog\Policies\ProductTagPolicy;
use App\Modules\Catalog\Policies\UnitPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * مزوّد خدمة وحدة الكتالوج (المبدأ 14: وحدات مستقلة).
 * يسجّل سياسات الصلاحيات صراحةً (النماذج خارج App\Models فلا تُكتشف تلقائيًا).
 */
class CatalogServiceProvider extends ServiceProvider
{
    private array $policies = [
        Category::class => CategoryPolicy::class,
        Brand::class => BrandPolicy::class,
        Unit::class => UnitPolicy::class,
        ProductAttribute::class => ProductAttributePolicy::class,
        ProductTag::class => ProductTagPolicy::class,
        Product::class => ProductPolicy::class,
        ProductReview::class => ProductReviewPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
