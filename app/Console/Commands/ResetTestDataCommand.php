<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تفريغ بيانات التجربة قبل التشغيل الفعلي — بثلاثة مستويات متدرّجة، مع **الحفاظ على
 * الإعدادات والبنية** (المستخدمون، الأدوار، دليل الحسابات، الخزائن، الفروع، المستودعات،
 * المدن/المناطق وأسعار التوصيل، مزوّدو التوصيل، طرق الدفع، قواعد العمولات).
 *
 * أرقام المستندات المتسلسلة (SO/PO/…) تُشتقّ من أعلى رقم في الجدول (NumberGenerator)،
 * فتعود تلقائيًا إلى 1 بعد التفريغ — لا عدّادات منفصلة تحتاج تصفيرًا.
 *
 * الأمر **يعرض فقط** ما لم يُمرَّر `--force`، فلا حذف بالخطأ.
 */
class ResetTestDataCommand extends Command
{
    protected $signature = 'system:reset-test-data
        {--scope=transactions : transactions | catalog | all}
        {--force : تنفيذ الحذف فعليًا (بدونه عرض فقط)}';

    protected $description = 'تفريغ بيانات التجربة (حركات/أصناف/أطراف) مع الحفاظ على الإعدادات والبنية';

    /** حركات: الطلبات والمشتريات والمخزون والقيود والعمولات وكل ما يتفرّع عنها. */
    private const TRANSACTIONS = [
        // مبيعات
        'order_price_changes', 'order_status_history', 'order_items', 'orders',
        'checkout_sessions', 'cart_items', 'carts',
        // مدفوعات
        'payment_transactions', 'payments',
        // شحن وتوصيل
        'delivery_status_transitions', 'delivery_provider_transitions', 'delivery_provider_events',
        'delivery_exception_notes', 'delivery_exceptions',
        'shipment_fee_components', 'shipment_events', 'shipments',
        'settlement_lines', 'delivery_settlements',
        // مرتجعات
        'return_request_photos', 'return_request_events', 'return_request_items', 'return_requests',
        // عمولات (القواعد تبقى)
        'commission_payout_entries', 'commission_payouts', 'commission_transitions', 'commission_entries',
        // مشتريات
        'supplier_return_items', 'supplier_returns',
        'goods_receipt_items', 'goods_receipts',
        'purchase_invoice_items', 'purchase_invoices',
        'purchase_order_items', 'purchase_orders',
        // مخزون
        'stock_reservations', 'inventory_count_items', 'inventory_counts',
        'stock_adjustment_items', 'stock_adjustments',
        'inventory_ledger', 'inventory_movements', 'inventory_stocks',
        // محاسبة
        'journal_lines', 'journal_entries', 'financial_vouchers',
        // سجلّات ورسائل
        'campaign_messages', 'recommendation_events', 'ai_generation_logs', 'notifications', 'audit_logs',
    ];

    /** أصناف: المنتجات ومتغيّراتها وصورها وروابطها (الفئات/العلامات/الوحدات تبقى). */
    private const CATALOG = [
        'wishlist_items', 'product_recommendations',
        'product_variant_attribute_values', 'product_attribute_links', 'product_tag_links',
        'product_images', 'product_variants', 'products',
    ];

    /** أطراف: العملاء والموردون وما يتبعهم (حساباتهم في الدليل تبقى). */
    private const PARTIES = [
        'customer_notes', 'customer_addresses', 'customer_phones', 'customer_contacts', 'customers',
        'supplier_contacts', 'suppliers',
    ];

    public function handle(): int
    {
        $scope = (string) $this->option('scope');
        if (! in_array($scope, ['transactions', 'catalog', 'all'], true)) {
            $this->error('scope غير معروف. استخدم: transactions | catalog | all');

            return self::FAILURE;
        }

        $tables = array_values(array_filter($this->tablesFor($scope), fn ($t) => Schema::hasTable($t)));

        $this->line('');
        $this->info("النطاق: {$scope}");
        $this->table(['الجدول', 'عدد الصفوف'], collect($tables)
            ->map(fn ($t) => [$t, DB::table($t)->count()])
            ->filter(fn ($row) => $row[1] > 0)
            ->values()->all() ?: [['— لا صفوف —', 0]]);

        $this->line('');
        $this->comment('يبقى دون مساس: المستخدمون والأدوار، الإعدادات، دليل الحسابات والسنوات المالية،');
        $this->comment('الخزائن، الفروع والمستودعات، المدن/المناطق وأسعار التوصيل، مزوّدو التوصيل،');
        $this->comment('طرق الدفع، قواعد العمولات، الفئات والعلامات والوحدات وخصائص المنتجات.');

        if (! $this->option('force')) {
            $this->line('');
            $this->warn('عرض فقط — لم يُحذف شيء. أضف ‎--force‎ للتنفيذ (خُذ نسخة احتياطية أولًا).');

            return self::SUCCESS;
        }

        $this->line('');
        if (! $this->confirm('تأكيد الحذف النهائي لهذه البيانات؟ لا يمكن التراجع.', false)) {
            $this->line('أُلغي.');

            return self::SUCCESS;
        }

        $this->truncate($tables);

        $this->line('');
        $this->info('اكتمل التفريغ. أرصدة الحسابات والخزائن تعود صفرًا تلقائيًا (تُشتقّ من القيود).');

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function tablesFor(string $scope): array
    {
        return match ($scope) {
            'transactions' => self::TRANSACTIONS,
            'catalog' => [...self::TRANSACTIONS, ...self::CATALOG],
            default => [...self::TRANSACTIONS, ...self::CATALOG, ...self::PARTIES],
        };
    }

    /** تفريغ مع تعطيل فحص المفاتيح الأجنبية مؤقتًا (الترتيب لا يكفي مع الحلقات المتبادلة). */
    private function truncate(array $tables): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            foreach ($tables as $table) {
                DB::table($table)->truncate();
                $this->line("  ✔ {$table}");
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }
}
