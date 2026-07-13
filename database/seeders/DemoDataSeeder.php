<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Sales\Models\Order;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * بيانات تجريبية واقعية للمراجعة البصرية فقط (ليست للإنتاج).
 * تشغيل: php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::default() ?? Branch::first();
        $warehouse = Warehouse::first();
        $unit = \App\Modules\Catalog\Models\Unit::first();

        // ─── الفئات ───────────────────────────────────────────────
        $categoryNames = [
            'إلكترونيات' => 'أحدث الأجهزة الإلكترونية والملحقات',
            'ملابس رجالية' => 'أزياء رجالية عصرية',
            'مستلزمات منزلية' => 'كل ما يحتاجه منزلك',
            'العناية والجمال' => 'منتجات العناية الشخصية',
            'أجهزة رياضية' => 'معدات اللياقة والرياضة',
        ];
        $categories = [];
        $sort = 0;
        foreach ($categoryNames as $name => $desc) {
            $categories[] = Category::create([
                'name' => $name,
                'slug' => Str::slug($name, '-', 'ar').'-'.(++$sort),
                'description' => $desc,
                'sort_order' => $sort,
                'is_active' => true,
            ]);
        }

        // ─── المنتجات ─────────────────────────────────────────────
        $productSeed = [
            ['سماعة بلوتوث لاسلكية', 'Wireless Bluetooth Headphones', 349.00, 299.00],
            ['ساعة ذكية رياضية', 'Smart Sports Watch', 899.00, null],
            ['شاحن سريع 65 واط', 'Fast Charger 65W', 129.00, 99.00],
            ['قميص قطني كلاسيكي', 'Classic Cotton Shirt', 159.00, null],
            ['بنطال جينز أزرق', 'Blue Denim Jeans', 249.00, 199.00],
            ['طقم أواني طهي', 'Cookware Set', 599.00, null],
            ['مكنسة كهربائية', 'Vacuum Cleaner', 749.00, 649.00],
            ['كريم مرطب للبشرة', 'Moisturizing Cream', 89.00, null],
            ['عطر فاخر للرجال', 'Luxury Men Perfume', 459.00, 399.00],
            ['دمبل قابل للتعديل', 'Adjustable Dumbbell', 329.00, null],
            ['حصيرة يوغا', 'Yoga Mat', 119.00, 95.00],
            ['لوح تزلج كهربائي', 'Electric Skateboard', 1899.00, null],
        ];
        $i = 0;
        foreach ($productSeed as [$ar, $en, $price, $promo]) {
            $cat = $categories[$i % count($categories)];
            $product = Product::create([
                'category_id' => $cat->id,
                'unit_id' => $unit?->id,
                'name' => $ar,
                'name_en' => $en,
                'slug' => Str::slug($en).'-'.(++$i),
                'sku' => 'SKU-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'type' => 'simple',
                'short_description' => 'منتج عالي الجودة بأفضل سعر في السوق.',
                'description' => 'وصف تفصيلي للمنتج يشرح المزايا والمواصفات الكاملة. مناسب للاستخدام اليومي ويأتي بضمان.',
                'status' => 'active',
                'visibility' => 'visible',
                'is_featured' => $i <= 4,
                'is_active' => true,
            ]);
            $variant = $product->variants()->firstOrCreate(
                ['is_default' => true],
                ['sku' => $product->sku, 'name' => $product->name, 'is_active' => true],
            );
            $variant->update([
                'retail_price' => $price,
                'wholesale_price' => round($price * 0.8, 2),
                'cost_price' => round($price * 0.6, 4),
                'average_cost' => round($price * 0.6, 4),
                'promo_price' => $promo,
            ]);
            InventoryStock::updateOrCreate(
                ['variant_id' => $variant->id, 'warehouse_id' => $warehouse?->id],
                ['on_hand' => random_int(0, 120), 'average_cost' => round($price * 0.6, 4), 'cost_price' => round($price * 0.6, 4)],
            );
        }

        // ─── الموظفون ─────────────────────────────────────────────
        $employees = [
            ['أحمد المدير', 'manager@tawfeer.online', 'manager'],
            ['سارة المبيعات', 'sales@tawfeer.online', 'sales'],
            ['خالد المحاسب', 'accountant@tawfeer.online', 'accountant'],
            ['ليلى المستودع', 'warehouse@tawfeer.online', 'warehouse'],
            ['عمر التوصيل', 'delivery@tawfeer.online', 'delivery_ops'],
        ];
        foreach ($employees as [$name, $email, $role]) {
            $u = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password'), 'branch_id' => $branch?->id, 'is_active' => true],
            );
            if (! $u->hasRole($role)) {
                $u->assignRole($role);
            }
        }

        // ─── العملاء ──────────────────────────────────────────────
        $customerNames = ['محمد عبدالله', 'فاطمة الزهراء', 'يوسف الحسن', 'نورة السالم', 'عبدالرحمن الخالد', 'ريم الفهد', 'ماجد العتيبي', 'هند القحطاني'];
        $customers = [];
        foreach ($customerNames as $n => $name) {
            $customers[] = Customer::create([
                'branch_id' => $branch?->id,
                'name' => $name,
                'primary_phone' => '96650'.str_pad((string) ($n + 1), 7, '0', STR_PAD_LEFT),
                'email' => 'customer'.($n + 1).'@example.com',
                'source' => 'storefront',
            ]);
        }

        // ─── الطلبات ──────────────────────────────────────────────
        $statuses = ['draft', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];
        $variants = \App\Modules\Catalog\Models\ProductVariant::with('product')->get();
        for ($o = 1; $o <= 14; $o++) {
            $customer = $customers[array_rand($customers)];
            $status = $statuses[$o % count($statuses)];
            $order = Order::create([
                'number' => 'SO-'.str_pad((string) (100000 + $o), 6, '0', STR_PAD_LEFT),
                'branch_id' => $branch?->id,
                'warehouse_id' => $warehouse?->id,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->primary_phone,
                'channel' => $o % 2 ? 'storefront' : 'manual',
                'status' => $status,
                'subtotal' => 0,
                'total' => 0,
                'payment_status' => in_array($status, ['delivered', 'shipped']) ? 'paid' : 'pending',
            ]);
            $subtotal = 0;
            $lines = random_int(1, 3);
            $used = [];
            for ($l = 0; $l < $lines; $l++) {
                $v = $variants[array_rand($variants->all())];
                if (in_array($v->id, $used)) {
                    continue;
                }
                $used[] = $v->id;
                $qty = random_int(1, 4);
                $price = (float) ($v->promo_price ?: $v->retail_price);
                $lineTotal = $qty * $price;
                $subtotal += $lineTotal;
                $order->items()->create([
                    'variant_id' => $v->id,
                    'qty' => $qty,
                    'unit_price' => $price,
                    'line_total' => $lineTotal,
                    'retail_price_snapshot' => $v->retail_price,
                ]);
            }
            $order->update(['subtotal' => $subtotal, 'total' => $subtotal]);
        }

        $this->command?->info('Demo data seeded: '.Product::count().' products, '.Customer::count().' customers, '.Order::count().' orders.');
    }
}
