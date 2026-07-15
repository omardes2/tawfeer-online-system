<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تفريغ كل البيانات التشغيلية/التجريبية (منتجات، طلبات، عملاء، موردون، فواتير، سندات،
 * شحنات، مدفوعات، قيود، حملات...) للانتقال إلى بداية نظيفة — مع الإبقاء على البنية الأساسية:
 * دليل الحسابات، خرائط الترحيل، الخزائن، الفروع/المستودعات، الوحدات، الجغرافيا، طرق الدفع،
 * الإعدادات، الأدوار/الصلاحيات، وحساب المدير. لا يمسّ إعدادات المستخدم (رسوم التوصيل، إلخ).
 *
 * الاستخدام:
 *   php artisan demo:clear --dry-run   # عرض ما سيُحذف دون تنفيذ
 *   php artisan demo:clear             # تنفيذ (يطلب تأكيدًا)
 *   php artisan demo:clear --force     # تنفيذ دون تأكيد
 */
class ClearDemoData extends Command
{
    protected $signature = 'demo:clear {--dry-run : عرض ما سيُحذف دون تنفيذ} {--force : تنفيذ دون تأكيد}';

    protected $description = 'تفريغ البيانات التشغيلية/التجريبية والإبقاء على البنية الأساسية (بداية نظيفة).';

    /**
     * الجداول التي تُفرَّغ بالكامل (محتوى/معاملات) — مرتّبة من الأبناء إلى الآباء بحيث تُحترم
     * قيود المفاتيح الأجنبية حتى عند تفعيلها (SQLite داخل معاملة الاختبار)، مع تعطيلها
     * إضافيًا للأمان على MySQL.
     */
    private const CLEAR_TABLES = [
        // ── أبناء/سجلّات/روابط (تُشير لغيرها ولا يُشار إليها ضمن المجموعة) ──
        'recommendation_events', 'product_recommendations',
        'product_attribute_links', 'product_attribute_values',
        'product_tag_links', 'product_images',
        'return_request_photos', 'return_request_events', 'return_request_items',
        'commission_payout_entries', 'commission_transitions', 'commission_entries',
        'settlement_lines',
        'shipment_events', 'shipment_fee_components',
        'delivery_exception_notes', 'delivery_provider_events', 'delivery_provider_transitions',
        'payment_transactions', 'payments',
        'order_price_changes', 'order_status_history', 'order_items',
        'goods_receipt_items', 'purchase_invoice_items', 'purchase_order_items', 'supplier_return_items',
        'inventory_ledger', 'inventory_movements', 'inventory_count_items',
        'stock_adjustment_items', 'stock_reservations', 'inventory_stocks',
        'cart_items', 'wishlist_items', 'checkout_sessions', 'social_identities',
        'customer_notes', 'customer_addresses', 'customer_contacts', 'customer_phones',
        'campaign_messages',
        'journal_lines', 'financial_vouchers',
        'message_suppressions', 'ai_generation_logs', 'audit_logs', 'notifications',
        'product_attributes',
        // ── آباء وسطى ──
        'return_requests', 'commission_payouts', 'delivery_settlements',
        'delivery_exceptions', 'shipments',
        'goods_receipts', 'purchase_invoices', 'supplier_returns', 'supplier_contacts',
        'inventory_counts', 'stock_adjustments', 'carts', 'campaigns', 'journal_entries',
        // ── جذور (يُشار إليها من الأبناء أعلاه) ──
        'purchase_orders', 'orders', 'product_variants', 'suppliers', 'customers',
        'products', 'campaign_templates', 'categories', 'brands', 'product_tags',
    ];

    /** إيميلات موظفي الديمو (تُحذف)؛ المدير وأي مستخدم آخر يبقى. */
    private const DEMO_EMPLOYEE_EMAILS = [
        'manager@tawfeer.online', 'sales@tawfeer.online', 'accountant@tawfeer.online',
        'warehouse@tawfeer.online', 'delivery@tawfeer.online',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->line($dry ? '— وضع المعاينة (لن يُحذف شيء) —' : '— تفريغ البيانات التشغيلية —');
        $this->newLine();

        // إحصاء ما سيُحذف.
        $counts = [];
        foreach (self::CLEAR_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $n = DB::table($table)->count();
                if ($n > 0) {
                    $counts[$table] = $n;
                }
            }
        }
        $subAccounts = Schema::hasTable('accounts')
            ? DB::table('accounts')->where('code', 'like', '1100-%')->orWhere('code', 'like', '2010-%')->count() : 0;
        $demoUsers = Schema::hasTable('users')
            ? DB::table('users')->whereIn('email', self::DEMO_EMPLOYEE_EMAILS)->count() : 0;

        foreach ($counts as $table => $n) {
            $this->line(sprintf('  %-32s %d', $table, $n));
        }
        $this->line(sprintf('  %-32s %d', 'accounts (حسابات عملاء/موردين فرعية)', $subAccounts));
        $this->line(sprintf('  %-32s %d', 'users (موظفو ديمو)', $demoUsers));
        $this->newLine();

        $total = array_sum($counts) + $subAccounts + $demoUsers;
        if ($total === 0) {
            $this->info('لا توجد بيانات تشغيلية للحذف — النظام نظيف بالفعل.');

            return self::SUCCESS;
        }

        if ($dry) {
            $this->info("سيُحذف {$total} سجلّ. أعد التشغيل دون --dry-run للتنفيذ.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("سيُحذف {$total} سجلّ نهائيًا. هل تريد المتابعة؟")) {
            $this->warn('أُلغيت العملية.');

            return self::SUCCESS;
        }

        // تعطيل قيود المفاتيح الأجنبية أثناء الحذف (SET FOREIGN_KEY_CHECKS=0 على MySQL،
        // PRAGMA foreign_keys=OFF على SQLite) حتى لا يهمّ ترتيب الحذف. بلا معاملة صريحة:
        // على SQLite لا يمكن تبديل القيود داخل معاملة، والحذف بلا قيود لا يفشل.
        Schema::disableForeignKeyConstraints();
        try {
            foreach (array_keys($counts) as $table) {
                DB::table($table)->delete();
            }
            if (Schema::hasTable('accounts')) {
                DB::table('accounts')->where('code', 'like', '1100-%')->orWhere('code', 'like', '2010-%')->delete();
            }
            if (Schema::hasTable('users')) {
                DB::table('users')->whereIn('email', self::DEMO_EMPLOYEE_EMAILS)->delete();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->newLine();
        $this->info("تم — حُذف {$total} سجلّ. النظام الآن على بداية نظيفة (البنية الأساسية محفوظة).");

        return self::SUCCESS;
    }
}
