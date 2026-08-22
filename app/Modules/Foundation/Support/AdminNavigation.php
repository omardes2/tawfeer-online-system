<?php

namespace App\Modules\Foundation\Support;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * شجرة تنقّل لوحة التحكّم — التعريف والتصفية حسب الصلاحيات وتحديد العنصر النشط.
 *
 * كان هذا كلّه داخل `<x-admin.sidebar>` كـ`@php` طويل؛ نقله إلى هنا يجعله قابلًا
 * للاختبار ويترك ملف العرض للعرض وحده.
 *
 * كل مستخدم يرى الوجهات المسموح له بها فقط، فالقائمة تتقلّص مع الدور: مدير النظام
 * يرى كل الأقسام، وموظف المبيعات ثلاثة، والمسوّق أقلّ.
 */
final class AdminNavigation
{
    /**
     * تعريف الأقسام: [العنوان، الأيقونة، العناصر].
     *
     * العنصر: [اسم المسار، العنوان، الصلاحية، نمط النشاط، معاملات المسار].
     * «الصلاحية» إمّا اسم صلاحية، أو ['ability', Model::class] لفحص Policy، أو null للجميع.
     *
     * @return array<int, array{0:string, 1:string, 2:array<int, array>}>
     */
    private static function definition(): array
    {
        $m = fn (string $module) => 'App\\Modules\\'.$module;

        return [
            ['المبيعات', 'cart', [
                ['admin.sales.orders.index', 'الطلبات', ['viewAny', $m('Sales\\Models\\Order')], 'admin.sales.orders.index'],
                ['admin.sales.orders.create', 'طلب بيع جديد', ['create', $m('Sales\\Models\\Order')], 'admin.sales.orders.create'],
                ['admin.sales.orders.direct.create', 'مبيعات مباشرة', ['createDirect', $m('Sales\\Models\\Order')], 'admin.sales.orders.direct.*'],
                ['admin.sales.abandoned_checkouts.index', 'طلبات لم تكتمل', 'sales.abandoned_checkouts.view', 'admin.sales.abandoned_checkouts.*'],
                ['admin.returns.index', 'مرتجعات المبيعات', 'returns.view', 'admin.returns.*'],
            ]],
            ['المنتجات', 'box', [
                ['admin.products.index', 'المنتجات', ['viewAny', $m('Catalog\\Models\\Product')], 'admin.products.index'],
                ['admin.products.import', 'استيراد أصناف', ['create', $m('Catalog\\Models\\Product')], 'admin.products.import*'],
                ['admin.categories.index', 'الفئات', ['viewAny', $m('Catalog\\Models\\Category')], 'admin.categories.*'],
                ['admin.brands.index', 'العلامات التجارية', ['viewAny', $m('Catalog\\Models\\Brand')], 'admin.brands.*'],
                ['admin.tags.index', 'الوسوم', ['viewAny', $m('Catalog\\Models\\ProductTag')], 'admin.tags.*'],
                ['admin.units.index', 'وحدات القياس', ['viewAny', $m('Catalog\\Models\\Unit')], 'admin.units.*'],
                ['admin.attributes.index', 'الخيارات والمتغيّرات', ['viewAny', $m('Catalog\\Models\\ProductAttribute')], 'admin.attributes.*'],
                // شاشةٌ قائمة منذ بنائها ولم يكن لها بندٌ يقود إليها: التقييم
                // المعلّق لا يُنشر حتى يُعتمد، ولا سبيل لاعتماده بلا رابط.
                ['admin.reviews.index', 'تقييمات الزبائن', ['viewAny', $m('Catalog\\Models\\ProductReview')], 'admin.reviews.*'],
                ['admin.price_lists.index', 'قوائم أسعار التجّار', 'catalog.price_lists.view', 'admin.price_lists.*'],
            ]],
            ['المخزون والمشتريات', 'inventory', [
                ['admin.inventory.stocks', 'أرصدة المخزون', 'inventory.stocks.view', 'admin.inventory.stocks'],
                // نفس صلاحية المسار (`inventory.alerts.view`) لا صلاحية الأرصدة، وإلا
                // ظهر الرابط لمن لا يملكه فأوصله إلى صفحة 403.
                ['admin.inventory.low_stock', 'تنبيهات النقص', 'inventory.alerts.view', 'admin.inventory.low_stock'],
                ['admin.purchasing.suppliers.index', 'الموردون', 'purchasing.suppliers.view', 'admin.purchasing.suppliers.*'],
                ['admin.purchasing.invoices.index', 'فواتير الموردين', 'purchasing.invoices.view', 'admin.purchasing.invoices.*'],
                ['admin.purchasing.shipments.index', 'شحنات الاستيراد', 'purchasing.shipments.view', 'admin.purchasing.shipments.*'],
                ['admin.purchasing.returns.index', 'مرتجعات الموردين', 'purchasing.returns.view', 'admin.purchasing.returns.*'],
            ]],
            // قسمٌ مستقلّ لا بندٌ تحت «المنتجات»: المسوّق لا يملك صلاحيات الكتالوج
            // فيختفي ذلك القسم عنه بالكامل — ومعه كانت ستختفي قائمة الأسعار.
            ['الأصناف والأسعار', 'box', [
                ['admin.price_list', 'الأصناف والأسعار', 'catalog.price_list.view', 'admin.price_list'],
            ]],
            ['العملاء', 'users', [
                ['admin.crm.customers.index', 'العملاء', 'crm.customers.view', 'admin.crm.customers.*'],
            ]],
            ['الشحن والتوصيل', 'truck', [
                ['admin.shipping.shipments.index', 'الشحنات', 'shipping.shipments.view', 'admin.shipping.shipments.*'],
                ['admin.shipping.delivery.index', 'محرّك التوصيل', 'shipping.delivery.view', 'admin.shipping.delivery.*'],
                ['admin.shipping.delivery_rates.index', 'المدن وأسعار التوصيل', 'settings.geography.view', 'admin.shipping.delivery_rates.*'],
                ['admin.shipping.areas.index', 'مناطق الشحن', 'settings.geography.view', 'admin.shipping.areas.*'],
            ]],
            ['المالية والمحاسبة', 'wallet', [
                ['admin.accounting.vouchers.index', 'سندات القبض', 'accounting.receipts.view', 'admin.accounting.vouchers.*', ['receipt']],
                ['admin.accounting.vouchers.index', 'سندات الصرف', 'accounting.payments.view', 'admin.accounting.vouchers.*', ['payment']],
                ['admin.accounting.vouchers.index', 'المصروفات', 'accounting.expenses.view', 'admin.accounting.vouchers.*', ['expense']],
                ['admin.accounting.vouchers.index', 'إيرادات أخرى', 'accounting.income.view', 'admin.accounting.vouchers.*', ['income']],
                ['admin.accounting.expense_categories.index', 'تصنيفات المصروفات', 'accounting.expense_categories.view', 'admin.accounting.expense_categories.*'],
                ['admin.accounting.cashboxes.index', 'الخزائن النقدية', 'accounting.cashboxes.view', 'admin.accounting.cashboxes.*'],
                ['admin.accounting.banks.index', 'الحسابات البنكية', 'accounting.banks.view', 'admin.accounting.banks.*'],
                ['admin.accounting.transfers.index', 'التحويلات', 'accounting.transfers.view', 'admin.accounting.transfers.*'],
                ['admin.payments.index', 'المدفوعات', 'payments.view', 'admin.payments.*'],
                ['admin.settlements.index', 'التسويات المالية', 'settlements.view', 'admin.settlements.*'],
                ['admin.accounting.journal.index', 'القيود اليومية', 'accounting.journal.view', 'admin.accounting.journal.*'],
                ['admin.accounting.accounts.index', 'دليل الحسابات', 'accounting.journal.view', 'admin.accounting.accounts.*'],
                ['admin.accounting.posting_setup.index', 'إعدادات الترحيل', 'accounting.accounts.manage', 'admin.accounting.posting_setup.*'],
                ['admin.accounting.finance_reports.index', 'التقارير المالية', 'accounting.reports.view', 'admin.accounting.finance_reports.*'],
            ]],
            ['الموظفون والعمولات', 'badge', [
                ['admin.commissions.index', 'العمولات والأرباح', 'commissions.view_team', 'admin.commissions.*'],
                ['admin.users.index', 'المستخدمون', 'settings.users.view', 'admin.users.*'],
                ['admin.roles.index', 'الأدوار والصلاحيات', 'settings.roles.view', 'admin.roles.*'],
            ]],
            ['التسويق', 'sparkles', [
                ['admin.marketing.contacts.index', 'جهات الاتصال التسويقية', 'marketing.contacts.view', 'admin.marketing.contacts.*'],
                ['admin.marketing.campaigns.index', 'الحملات التسويقية', 'marketing.campaigns.view', 'admin.marketing.campaigns.*'],
                ['admin.marketing.templates.index', 'قوالب الرسائل', 'marketing.templates.manage', 'admin.marketing.templates.*'],
                ['admin.recommendations.index', 'توصيات المنتجات', 'recommendations.manage', 'admin.recommendations.*'],
            ]],
            ['التقارير', 'chart', [
                ['admin.reports.sales.by_customer', 'المبيعات حسب الزبون', 'reports.sales_summary.view', 'admin.reports.sales.by_customer'],
                ['admin.reports.sales.by_product', 'المبيعات حسب المنتج', 'reports.sales_summary.view', 'admin.reports.sales.by_product'],
                ['admin.reports.sales.by_employee', 'المبيعات حسب موظف المبيعات', 'reports.sales_summary.view', 'admin.reports.sales.by_employee'],
                ['admin.reports.sales.by_affiliate', 'المبيعات حسب المسوّقين', 'reports.sales_summary.view', 'admin.reports.sales.by_affiliate'],
                ['admin.reports.sales.by_location', 'المبيعات حسب المدن والمناطق', 'reports.sales_by_location.view', 'admin.reports.sales.by_location'],
                ['admin.reports.receivables.customers', 'كشف حساب العملاء', 'reports.statements.view', 'admin.reports.receivables.customers'],
                ['admin.reports.receivables.suppliers', 'كشف حساب الموردين', 'reports.statements.view', 'admin.reports.receivables.suppliers'],
                ['admin.reports.product_decision', 'لوحة قرار الصنف', 'reports.product_decision.view', 'admin.reports.product_decision*'],
                ['admin.reports.ad_budget', 'الميزانية اليومية', 'reports.ad_budget.view', 'admin.reports.ad_budget*'],
            ]],
            ['الإعدادات', 'cog', [
                ['admin.settings.edit', 'إعدادات النظام', 'settings.system.view', 'admin.settings.edit'],
                ['admin.settings.ad_channels.index', 'قنوات الإعلان', 'reports.ad_budget.manage', 'admin.settings.ad_channels.*'],
                ['admin.settings.ad_maps.index', 'ربط الحملات', 'reports.ad_budget.manage', 'admin.settings.ad_maps.*'],
            ]],
        ];
    }

    /**
     * الأقسام المسموح بها للمستخدم الحالي، مع تحديد النشط منها.
     * القسم الخالي من عناصر مرئية يُحذف بالكامل.
     *
     * @return array<int, array{label:string, icon:string, active:bool, items:array<int, array{label:string, url:string, active:bool}>}>
     */
    public static function groups(): array
    {
        $groups = [];

        foreach (self::definition() as [$label, $icon, $items]) {
            $visible = [];

            foreach ($items as $item) {
                [$route, $itemLabel, $can, $activePattern] = $item;
                $params = $item[4] ?? [];

                if (! Route::has($route) || ! self::allows($can)) {
                    continue;
                }

                $visible[] = [
                    'label' => __($itemLabel),
                    'url' => route($route, $params),
                    'active' => self::isActive($activePattern, $params),
                ];
            }

            if ($visible === []) {
                continue;
            }

            $groups[] = [
                'label' => __($label),
                'icon' => $icon,
                'active' => collect($visible)->contains('active', true),
                'items' => $visible,
            ];
        }

        return $groups;
    }

    /** فهرس القسم المفتوح عند فتح الصفحة — القسم الذي يقع فيه العنصر النشط. */
    public static function activeGroupIndex(array $groups): int
    {
        foreach ($groups as $i => $group) {
            if ($group['active']) {
                return $i;
            }
        }

        return -1;
    }

    /** فحص الصلاحية: اسم صلاحية، أو ['ability', Model::class]، أو null للجميع. */
    private static function allows(string|array|null $can): bool
    {
        if ($can === null) {
            return true;
        }

        return is_array($can) ? Gate::allows($can[0], $can[1]) : Gate::allows($can);
    }

    /**
     * العنصر نشط إذا طابق المسار الحالي نمطه. الروابط التي تتقاسم مسارًا واحدًا
     * وتفترق بمعامل (سندات القبض/الصرف/المصروفات) تُطابَق بالمعامل أيضًا حتى لا
     * تُضاء كلّها معًا.
     */
    private static function isActive(?string $pattern, array $params): bool
    {
        if (! $pattern || ! request()->routeIs($pattern)) {
            return false;
        }

        if ($params !== [] && request()->route('kind') !== null) {
            return request()->route('kind') === ($params[0] ?? null);
        }

        return true;
    }
}
