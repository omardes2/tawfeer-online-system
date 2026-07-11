<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\OrderStatus;
use App\Modules\Foundation\Models\PaymentStatus;
use App\Modules\Foundation\Models\ShipmentStatus;
use Illuminate\Database\Seeder;

/**
 * الحالات الافتراضية القابلة للإدارة (المبدأ 10).
 *
 * تُزرع قيم معقولة، وتبقى الإدارة الكاملة (إضافة/تعديل/تعطيل) من لوحة التحكم.
 */
class StatusSeeder extends Seeder
{
    public function run(): void
    {
        $orderStatuses = [
            ['key' => 'pending', 'name' => 'قيد الانتظار', 'color' => '#f59e0b', 'sort_order' => 1, 'is_default' => true],
            ['key' => 'confirmed', 'name' => 'مؤكّد', 'color' => '#3b82f6', 'sort_order' => 2],
            ['key' => 'processing', 'name' => 'قيد التجهيز', 'color' => '#6366f1', 'sort_order' => 3],
            ['key' => 'shipped', 'name' => 'مُشحَن', 'color' => '#8b5cf6', 'sort_order' => 4],
            ['key' => 'delivered', 'name' => 'مُسلَّم', 'color' => '#22c55e', 'sort_order' => 5, 'is_final' => true],
            ['key' => 'cancelled', 'name' => 'مُلغى', 'color' => '#ef4444', 'sort_order' => 6, 'is_final' => true],
            ['key' => 'returned', 'name' => 'مُرتجَع', 'color' => '#64748b', 'sort_order' => 7, 'is_final' => true],
        ];

        $paymentStatuses = [
            ['key' => 'unpaid', 'name' => 'غير مدفوع', 'color' => '#ef4444', 'sort_order' => 1, 'is_default' => true],
            ['key' => 'partially_paid', 'name' => 'مدفوع جزئيًا', 'color' => '#f59e0b', 'sort_order' => 2],
            ['key' => 'paid', 'name' => 'مدفوع', 'color' => '#22c55e', 'sort_order' => 3, 'is_final' => true],
            ['key' => 'refunded', 'name' => 'مُسترَد', 'color' => '#64748b', 'sort_order' => 4, 'is_final' => true],
        ];

        $shipmentStatuses = [
            ['key' => 'not_shipped', 'name' => 'لم يُشحَن', 'color' => '#64748b', 'sort_order' => 1, 'is_default' => true],
            ['key' => 'preparing', 'name' => 'قيد التجهيز', 'color' => '#6366f1', 'sort_order' => 2],
            ['key' => 'in_transit', 'name' => 'في الطريق', 'color' => '#3b82f6', 'sort_order' => 3],
            ['key' => 'delivered', 'name' => 'تم التسليم', 'color' => '#22c55e', 'sort_order' => 4, 'is_final' => true],
            ['key' => 'failed', 'name' => 'فشل التسليم', 'color' => '#ef4444', 'sort_order' => 5, 'is_final' => true],
        ];

        foreach ($orderStatuses as $status) {
            OrderStatus::query()->updateOrCreate(['key' => $status['key']], $status);
        }

        foreach ($paymentStatuses as $status) {
            PaymentStatus::query()->updateOrCreate(['key' => $status['key']], $status);
        }

        foreach ($shipmentStatuses as $status) {
            ShipmentStatus::query()->updateOrCreate(['key' => $status['key']], $status);
        }
    }
}
