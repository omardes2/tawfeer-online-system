<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\PaymentMethod;
use Illuminate\Database\Seeder;

/**
 * طرق الدفع الافتراضية (ADR-028). COD/التحويل فعّالان (أوفلاين)؛ البوابات مُسجَّلة معطّلة
 * كـ placeholders (driver=null) تُفعَّل عند ربط Driver حقيقي — دون لمس منطق الأعمال.
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['code' => 'cod', 'name' => 'الدفع عند الاستلام', 'driver' => 'offline', 'type' => 'offline', 'is_active' => true, 'sort_order' => 1],
            ['code' => 'bank_transfer', 'name' => 'تحويل بنكي', 'driver' => 'offline', 'type' => 'offline', 'is_active' => true, 'sort_order' => 2],
            ['code' => 'hyperpay', 'name' => 'HyperPay', 'driver' => 'null', 'type' => 'online', 'is_active' => false, 'sort_order' => 3],
            ['code' => 'myfatoorah', 'name' => 'MyFatoorah', 'driver' => 'null', 'type' => 'online', 'is_active' => false, 'sort_order' => 4],
            ['code' => 'stripe', 'name' => 'Stripe', 'driver' => 'null', 'type' => 'online', 'is_active' => false, 'sort_order' => 5],
            ['code' => 'paypal', 'name' => 'PayPal', 'driver' => 'null', 'type' => 'online', 'is_active' => false, 'sort_order' => 6],
            ['code' => 'moyasar', 'name' => 'Moyasar', 'driver' => 'null', 'type' => 'online', 'is_active' => false, 'sort_order' => 7],
        ];

        foreach ($methods as $method) {
            PaymentMethod::query()->firstOrCreate(['code' => $method['code']], $method);
        }
    }
}
