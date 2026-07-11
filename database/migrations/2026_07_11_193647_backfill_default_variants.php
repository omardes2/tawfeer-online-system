<?php

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Migrations\Migration;

// ينشئ متغيّرًا افتراضيًا للمنتجات القائمة بلا متغيّر (ADR-024). لا أثر على قاعدة نظيفة.
return new class extends Migration
{
    public function up(): void
    {
        Product::query()->whereDoesntHave('variants')->chunkById(200, function ($products) {
            foreach ($products as $product) {
                $product->variants()->create([
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'is_default' => true,
                    'is_active' => true,
                ]);
            }
        });
    }

    public function down(): void
    {
        // لا تراجع للبيانات الخلفية.
    }
};
