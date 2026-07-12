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
        // مفردات دورة حياة الطلب الكاملة (ADR-010) — قابلة للإدارة (ADR-017).
        $orderStatuses = [
            ['key' => 'draft', 'name' => 'مسودّة', 'color' => '#94a3b8', 'sort_order' => 1, 'is_default' => true],
            ['key' => 'new', 'name' => 'جديد', 'color' => '#f59e0b', 'sort_order' => 2],
            ['key' => 'awaiting_contact', 'name' => 'بانتظار التواصل', 'color' => '#f59e0b', 'sort_order' => 3],
            ['key' => 'awaiting_confirmation', 'name' => 'بانتظار التأكيد', 'color' => '#f59e0b', 'sort_order' => 4],
            ['key' => 'confirmed', 'name' => 'مؤكّد', 'color' => '#3b82f6', 'sort_order' => 5],
            ['key' => 'stock_reserved', 'name' => 'محجوز المخزون', 'color' => '#6366f1', 'sort_order' => 6],
            ['key' => 'preparing', 'name' => 'قيد التجهيز', 'color' => '#6366f1', 'sort_order' => 7],
            ['key' => 'ready_to_ship', 'name' => 'جاهز للشحن', 'color' => '#8b5cf6', 'sort_order' => 8],
            ['key' => 'shipped', 'name' => 'مُشحَن', 'color' => '#8b5cf6', 'sort_order' => 9],
            ['key' => 'out_for_delivery', 'name' => 'خرج للتوصيل', 'color' => '#8b5cf6', 'sort_order' => 10],
            ['key' => 'delivered', 'name' => 'مُسلَّم', 'color' => '#22c55e', 'sort_order' => 11, 'is_final' => true],
            ['key' => 'delayed', 'name' => 'مؤجّل', 'color' => '#f59e0b', 'sort_order' => 12],
            ['key' => 'customer_unavailable', 'name' => 'العميل غير متاح', 'color' => '#f59e0b', 'sort_order' => 13],
            ['key' => 'cancelled', 'name' => 'مُلغى', 'color' => '#ef4444', 'sort_order' => 14, 'is_final' => true],
            ['key' => 'delivery_failed', 'name' => 'فشل التسليم', 'color' => '#ef4444', 'sort_order' => 15],
            ['key' => 'returned', 'name' => 'مُرتجَع', 'color' => '#64748b', 'sort_order' => 16, 'is_final' => true],
            ['key' => 'partially_returned', 'name' => 'مُرتجَع جزئيًا', 'color' => '#64748b', 'sort_order' => 17],
            ['key' => 'exchanged', 'name' => 'مُستبدَل', 'color' => '#64748b', 'sort_order' => 18, 'is_final' => true],
        ];

        $paymentStatuses = [
            ['key' => 'unpaid', 'name' => 'غير مدفوع', 'color' => '#ef4444', 'sort_order' => 1, 'is_default' => true],
            ['key' => 'partially_paid', 'name' => 'مدفوع جزئيًا', 'color' => '#f59e0b', 'sort_order' => 2],
            ['key' => 'paid', 'name' => 'مدفوع', 'color' => '#22c55e', 'sort_order' => 3, 'is_final' => true],
            ['key' => 'refunded', 'name' => 'مُسترَد', 'color' => '#64748b', 'sort_order' => 4, 'is_final' => true],
        ];

        // مفردات حالة الشحن (ADR-027) — تغطّي جزء التوصيل من دورة حياة الطلب (ADR-010، BR-ORD-10).
        $shipmentStatuses = [
            ['key' => 'not_shipped', 'name' => 'لم يُشحَن', 'color' => '#64748b', 'sort_order' => 1, 'is_default' => true],
            ['key' => 'preparing', 'name' => 'قيد التجهيز', 'color' => '#6366f1', 'sort_order' => 2],
            ['key' => 'in_transit', 'name' => 'في الطريق', 'color' => '#3b82f6', 'sort_order' => 3],
            ['key' => 'out_for_delivery', 'name' => 'خرج للتوصيل', 'color' => '#8b5cf6', 'sort_order' => 4],
            ['key' => 'delayed', 'name' => 'مؤجّل', 'color' => '#f59e0b', 'sort_order' => 5],
            ['key' => 'customer_unavailable', 'name' => 'العميل غير متاح', 'color' => '#f59e0b', 'sort_order' => 6],
            ['key' => 'delivered', 'name' => 'تم التسليم', 'color' => '#22c55e', 'sort_order' => 7, 'is_final' => true],
            ['key' => 'failed', 'name' => 'فشل التسليم', 'color' => '#ef4444', 'sort_order' => 8, 'is_final' => true],
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
