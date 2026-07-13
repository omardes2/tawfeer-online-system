<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTag;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\PaymentMethod;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignTemplate;
use App\Modules\Payment\Models\Payment;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Models\Shipment;
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
        $unit = Unit::first();

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
        $variants = ProductVariant::with('product')->get();
        $orders = [];
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
            $orders[] = $order;
        }

        // Each module below is guarded so a failure in one never aborts the whole seed.
        $safe = function (string $label, callable $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $this->command?->warn("  ~ skipped {$label}: ".$e->getMessage());
            }
        };

        // ─── العلامات التجارية + الوسوم ───────────────────────────
        $safe('brands+tags', function () {
            $brands = Brand::factory()->count(5)->create();
            foreach (Product::all() as $i => $p) {
                $p->update(['brand_id' => $brands[$i % $brands->count()]->id]);
            }
            ProductTag::factory()->count(8)->create();
        });

        // ─── الموردون + أوامر الشراء + الاستلام ────────────────────
        $safe('purchasing', function () use ($warehouse, $variants) {
            $suppliers = Supplier::factory()->count(6)->create();
            $poStatuses = ['draft', 'approved', 'received', 'closed', 'cancelled'];
            for ($i = 0; $i < 8; $i++) {
                $po = PurchaseOrder::create([
                    'number' => 'PO-'.str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT),
                    'supplier_id' => $suppliers[$i % $suppliers->count()]->id,
                    'warehouse_id' => $warehouse?->id,
                    'status' => $poStatuses[$i % count($poStatuses)],
                    'order_date' => now()->subDays(random_int(1, 40))->toDateString(),
                    'subtotal' => 0, 'total' => 0,
                ]);
                $sub = 0;
                foreach ($variants->random(min(3, $variants->count())) as $v) {
                    $qty = random_int(5, 30);
                    $cost = (float) ($v->cost_price ?: 10);
                    $line = $qty * $cost;
                    $sub += $line;
                    $po->items()->create(['variant_id' => $v->id, 'qty_ordered' => $qty, 'unit_cost' => $cost, 'line_total' => $line]);
                }
                $po->update(['subtotal' => $sub, 'total' => $sub]);
                if (in_array($po->status, ['received', 'closed'])) {
                    GoodsReceipt::create([
                        'number' => 'GRN-'.str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT),
                        'purchase_order_id' => $po->id, 'warehouse_id' => $warehouse?->id,
                        'status' => 'posted', 'additional_cost' => 0,
                    ]);
                }
            }
        });

        // ─── الشحنات + حالات التوصيل ───────────────────────────────
        $safe('shipments', function () use ($branch, $warehouse, $orders) {
            $dstatuses = ['not_shipped', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'delivered', 'in_transit'];
            $shippable = collect($orders)->whereIn('status', ['shipped', 'delivered', 'processing', 'confirmed'])->values();
            foreach ($shippable as $i => $order) {
                Shipment::create([
                    'number' => 'SHP-'.str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT),
                    'order_id' => $order->id, 'branch_id' => $branch?->id, 'warehouse_id' => $warehouse?->id,
                    'status' => 'shipped', 'delivery_status' => $dstatuses[$i % count($dstatuses)],
                    'recipient_name' => $order->customer_name, 'recipient_phone' => $order->customer_phone,
                    'shipping_cost' => random_int(10, 40), 'cost_source' => 'manual',
                ]);
            }
        });

        // ─── المدفوعات ─────────────────────────────────────────────
        $safe('payments', function () use ($branch, $orders) {
            $method = PaymentMethod::query()->first();
            $pstatuses = ['pending', 'captured', 'captured', 'refunded'];
            foreach (collect($orders)->take(10) as $i => $order) {
                Payment::create([
                    'number' => 'PAY-'.str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT),
                    'order_id' => $order->id, 'branch_id' => $branch?->id,
                    'payment_method_id' => $method?->id, 'amount' => (float) $order->total,
                    'status' => $pstatuses[$i % count($pstatuses)],
                ]);
            }
        });

        // ─── تسويات المخزون ────────────────────────────────────────
        $safe('stock-adjustments', function () use ($warehouse) {
            foreach (['recount', 'damage', 'recount'] as $i => $type) {
                StockAdjustment::create([
                    'number' => 'ADJ-'.str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT),
                    'warehouse_id' => $warehouse?->id, 'type' => $type, 'status' => 'draft',
                ]);
            }
        });

        // ─── السندات المحاسبية المُرحّلة (رصيد الخزائن + القسم المالي) ──
        $safe('accounting-vouchers', function () {
            $svc = app(VoucherService::class);
            $cb = Treasury::where('code', 'CB-MAIN')->first();
            $bnk = Treasury::where('code', 'BNK-MAIN')->first();
            $admin = User::where('email', 'admin@tawfeer.online')->first();
            if (! $cb || ! $admin) {
                return;
            }
            auth()->login($admin);
            $rev = Account::where('code', '4010')->first();
            $exp = Account::where('code', '5010')->first();
            $post = function (string $kind, float $amount, $treasury, ?int $counter, string $party) use ($svc) {
                $v = $svc->create($kind, array_filter([
                    'treasury_id' => $treasury->id, 'amount' => $amount, 'party_name' => $party,
                    'counter_account_id' => $counter, 'description' => 'بيانات تجريبية',
                    'voucher_date' => now()->subDays(random_int(0, 20))->toDateString(),
                ], fn ($x) => $x !== null));
                $svc->approve($v);
                $svc->post($v);
            };
            foreach ([1500, 2750, 3200, 900] as $a) {
                $post('receipt', $a, $cb, $rev?->id, 'عميل نقدي');
            }
            foreach ([800, 450] as $a) {
                $post('payment', $a, $cb, $exp?->id, 'مورد');
            }
            foreach ([450, 275, 620] as $a) {
                $post('expense', $a, $cb, $exp?->id, 'مصروف تشغيلي');
            }
            foreach ([600, 350] as $a) {
                $post('income', $a, $bnk ?? $cb, $rev?->id, 'إيراد آخر');
            }
            // بعض السندات غير المُرحّلة (مسودّة) لعرض دورة الاعتماد
            $draft = $svc->create('receipt', ['treasury_id' => $cb->id, 'amount' => 500, 'party_name' => 'مسودّة', 'counter_account_id' => $rev?->id, 'voucher_date' => now()->toDateString()]);
        });

        // ─── التسويق: القوالب + الحملات ────────────────────────────
        $safe('marketing', function () {
            $tpl = CampaignTemplate::create([
                'name' => 'ترحيب بالعميل', 'channel' => 'whatsapp',
                'body_ar' => 'مرحبًا بك في توفير أونلاين!', 'body_en' => 'Welcome to Tawfeer Online!', 'is_active' => true,
            ]);
            CampaignTemplate::create([
                'name' => 'عربة متروكة', 'channel' => 'whatsapp',
                'body_ar' => 'لديك منتجات في سلّتك 🛒', 'body_en' => 'You left items in your cart 🛒', 'is_active' => true,
            ]);
            foreach ([['حملة الترحيب', 'welcome', 'active'], ['استرجاع العربات', 'abandoned_cart', 'active'], ['عروض نهاية الأسبوع', 'broadcast', 'draft']] as [$name, $useCase, $status]) {
                Campaign::create([
                    'name' => $name, 'use_case' => $useCase, 'channel' => 'whatsapp', 'status' => $status,
                    'trigger_type' => 'event', 'body_ar' => 'رسالة تجريبية', 'template_id' => $tpl->id,
                ]);
            }
        });

        // ─── توصيات المنتجات ───────────────────────────────────────
        $safe('recommendations', function () {
            $products = Product::limit(6)->get();
            $types = ['related', 'cross_sell', 'upsell', 'complementary', 'fbt'];
            foreach ($products as $i => $p) {
                $target = $products[($i + 1) % $products->count()];
                if ($p->id === $target->id) {
                    continue;
                }
                \DB::table('product_recommendations')->insertOrIgnore([
                    'product_id' => $p->id, 'recommended_product_id' => $target->id,
                    'type' => $types[$i % count($types)], 'kind' => 'include', 'position' => $i,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->command?->info('Demo data seeded: '.Product::count().' products, '.Customer::count().' customers, '.Order::count().' orders, '
            .Supplier::count().' suppliers, '.Shipment::count().' shipments, '
            .FinancialVoucher::where('status', 'posted')->count().' posted vouchers.');
    }
}
