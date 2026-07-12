<?php

namespace Database\Seeders;

use App\Modules\Shipping\Models\DeliveryExceptionCategory;
use Illuminate\Database\Seeder;

/**
 * فئات استثناءات التوصيل الافتراضية (Phase 4.3 / ADR-039) — قابلة للضبط لاحقًا.
 */
class DeliveryExceptionCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'address_issue', 'name' => 'مشكلة عنوان', 'sla_hours' => 24, 'escalate_after_hours' => 48, 'escalate_to_role' => 'delivery_ops'],
            ['code' => 'contact_issue', 'name' => 'تعذّر الاتصال بالعميل', 'sla_hours' => 24, 'escalate_after_hours' => 48, 'escalate_to_role' => 'delivery_ops'],
            ['code' => 'customer_delay', 'name' => 'تأجيل بطلب العميل', 'sla_hours' => 72, 'escalate_after_hours' => null, 'escalate_to_role' => null],
            ['code' => 'refused', 'name' => 'رفض الاستلام', 'sla_hours' => 12, 'escalate_after_hours' => 24, 'escalate_to_role' => 'manager'],
            ['code' => 'courier_issue', 'name' => 'مشكلة لدى المندوب', 'sla_hours' => 12, 'escalate_after_hours' => 24, 'escalate_to_role' => 'manager'],
            ['code' => 'damaged', 'name' => 'تلف/كسر', 'sla_hours' => 24, 'escalate_after_hours' => 24, 'escalate_to_role' => 'manager'],
            ['code' => 'lost', 'name' => 'فقد', 'sla_hours' => 24, 'escalate_after_hours' => 24, 'escalate_to_role' => 'manager'],
            ['code' => 'other', 'name' => 'أخرى', 'sla_hours' => null, 'escalate_after_hours' => null, 'escalate_to_role' => null],
        ];

        foreach ($categories as $c) {
            DeliveryExceptionCategory::firstOrCreate(['code' => $c['code']], $c);
        }
    }
}
