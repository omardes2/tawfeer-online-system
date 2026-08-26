<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * ترتيب مقصود: الفرع أولًا، ثم الأدوار/الصلاحيات، ثم الحالات والإعدادات،
     * ثم مستخدم المدير (يعتمد على الفرع والدور)، ثم أسس Phase 2.1.
     */
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,
            RolePermissionSeeder::class,
            StatusSeeder::class,
            SettingsSeeder::class,
            AdminUserSeeder::class,
            // Phase 2.1 — البنية التنظيمية
            StructurePermissionSeeder::class,
            WarehouseSeeder::class,
            // Phase 2.2 — الكتالوج
            CatalogPermissionSeeder::class,
            UnitSeeder::class,
            // Phase 2.3 — المنتجات
            ProductPermissionSeeder::class,
            // Phase 2.4 — المخزون
            InventoryPermissionSeeder::class,
            // Phase 2.5 — المشتريات
            PurchasingPermissionSeeder::class,
            PurchaseInvoicePermissionSeeder::class,
            // Phase 2.6 — المبيعات
            SalesPermissionSeeder::class,
            // Phase 2.7 — الشحن والجغرافيا
            GeographySeeder::class,
            ShippingPermissionSeeder::class,
            // Phase 2.8 — المدفوعات
            PaymentMethodSeeder::class,
            PaymentPermissionSeeder::class,
            // Phase 2.9 — المحاسبة
            ChartOfAccountsSeeder::class,
            AccountMappingSeeder::class,
            AccountingPermissionSeeder::class,
            // Phase 7.1 — المحاسبة التشغيلية (خزائن/بنوك/سندات)
            AccountingOperationsPermissionSeeder::class,
            TreasurySeeder::class,
            // Phase 2.10 — CRM/العملاء
            CrmPermissionSeeder::class,
            // Phase 4.1 — عمليات المبيعات (البيع المُساعد)
            SalesOperationsPermissionSeeder::class,
            // Phase 4.2 — العمولات/الأرباح
            CommissionsPermissionSeeder::class,
            // Phase 4.3 — محرّك حالة التوصيل القانوني + عمليات التوصيل
            DeliveryStatusPermissionSeeder::class,
            DeliveryExceptionCategorySeeder::class,
            // Phase 4.4 — المرتجعات والاستبدال (RMA)
            ReturnsPermissionSeeder::class,
            // Phase 4.6 — التسويات المالية
            SettlementsPermissionSeeder::class,
            // Phase 6 — الذكاء الاصطناعي والتوصيات والتسويق ولوحات المؤشّرات
            AiPermissionSeeder::class,
            AiAgentPermissionSeeder::class,
            RecommendationsPermissionSeeder::class,
            MarketingPermissionSeeder::class,
            // الإدارة — المستخدمون/الأدوار/الإعدادات/لوحة التحكّم (Production)
            AdministrationPermissionSeeder::class,
            // وحدة التقارير والتحليلات الموسّعة — صلاحية لكل فئة
            AnalyticsReportPermissionSeeder::class,
            // نظام التقارير الجديد (المبيعات + الذمم)
            BusinessReportsPermissionSeeder::class,
            HrPermissionSeeder::class,
        ]);
    }
}
