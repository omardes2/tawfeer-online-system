<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * تسمية عربية مقروءة لأي مفتاح صلاحية (module.resource.action) دون قائمة يدوية طويلة:
 * تُترجم أجزاء المفتاح (المورد + الإجراء) وتُركّب "إجراء + مورد".
 */
class PermissionLabel
{
    /** الإجراء (آخر مقطع). */
    private const ACTIONS = [
        'view' => 'عرض',
        'create' => 'إنشاء',
        'update' => 'تعديل',
        'delete' => 'حذف',
        'manage' => 'إدارة',
        'approve' => 'اعتماد',
        'post' => 'ترحيل',
        'reverse' => 'عكس',
        'pay' => 'دفع',
        'payout' => 'صرف',
        'cancel' => 'إلغاء',
        'close' => 'إغلاق',
        'ship' => 'شحن',
        'dispatch' => 'إرسال',
        'deliver' => 'تسليم',
        'confirm' => 'تأكيد',
        'reserve' => 'حجز',
        'release' => 'تحرير حجز',
        'receive' => 'استلام',
        'issue' => 'صرف',
        'transfer' => 'تحويل',
        'capture' => 'تحصيل',
        'refund' => 'ردّ مبلغ',
        'reconcile' => 'تسوية',
        'finalize' => 'إنهاء',
        'inspect' => 'فحص',
        'block' => 'حظر',
        'merge' => 'دمج',
        'batch' => 'دفعات',
        'sync' => 'مزامنة',
        'fees' => 'رسوم',
        'assist' => 'مساعدة',
        'fail' => 'تعليم كفاشل',
        'use' => 'استخدام',
        'audit' => 'تدقيق',
        'override_price' => 'تجاوز السعر',
        'override_cost' => 'تجاوز التكلفة',
        'view_cost' => 'عرض التكلفة',
        'view_own' => 'عرض الخاص',
        'view_team' => 'عرض الفريق',
    ];

    /** المورد/الوحدة (المقطع الأوّل أو الأوسط). */
    private const RESOURCES = [
        'accounting' => 'المحاسبة',
        'accounts' => 'دليل الحسابات',
        'banks' => 'البنوك',
        'cashboxes' => 'الصناديق',
        'journal' => 'القيود اليومية',
        'payments' => 'المدفوعات',
        'receipts' => 'سندات القبض',
        'expenses' => 'المصروفات',
        'income' => 'الإيرادات',
        'transfers' => 'التحويلات',
        'reports' => 'التقارير',
        'affiliate' => 'المسوّقون',
        'ai' => 'الذكاء الاصطناعي',
        'config' => 'إعدادات الذكاء الاصطناعي',
        'content' => 'محتوى الذكاء الاصطناعي',
        'logs' => 'سجلات الذكاء الاصطناعي',
        'audit' => 'سجل التدقيق',
        'branches' => 'الفروع',
        'catalog' => 'الكتالوج',
        'products' => 'المنتجات',
        'commissions' => 'العمولات',
        'crm' => 'العملاء',
        'customers' => 'العملاء',
        'dashboard' => 'لوحة التحكم',
        'inventory' => 'المخزون',
        'adjustments' => 'تسويات المخزون',
        'alerts' => 'تنبيهات المخزون',
        'counts' => 'الجرد',
        'movements' => 'حركات المخزون',
        'operations' => 'عمليات المخزون',
        'reservations' => 'الحجوزات',
        'stocks' => 'أرصدة المخزون',
        'kpis' => 'مؤشرات الأداء',
        'marketing' => 'التسويق',
        'campaigns' => 'الحملات',
        'templates' => 'القوالب',
        'messaging' => 'المراسلة',
        'orders' => 'الطلبات',
        'methods' => 'طرق الدفع',
        'pricing' => 'التسعير',
        'purchasing' => 'المشتريات',
        'invoices' => 'فواتير الموردين',
        'suppliers' => 'الموردون',
        'recommendations' => 'التوصيات',
        'returns' => 'المرتجعات',
        'roles' => 'الأدوار',
        'sales' => 'المبيعات',
        'settings' => 'الإعدادات',
        'geography' => 'الجغرافيا',
        'system' => 'النظام',
        'users' => 'المستخدمون',
        'warehouses' => 'المستودعات',
        'warehouse_locations' => 'مواقع المستودعات',
        'settlements' => 'التسويات المالية',
        'shipping' => 'الشحن',
        'delivery' => 'التوصيل',
        'shipments' => 'الشحنات',
        'statuses' => 'الحالات',
        'rules' => 'القواعد',
    ];

    public static function for(string $key): string
    {
        $parts = explode('.', $key);
        $action = self::ACTIONS[end($parts)] ?? null;

        // مورد = المقطع الأوسط إن وُجد، وإلا المقطع الأوّل.
        $resourceKey = count($parts) >= 3 ? $parts[1] : $parts[0];
        $resource = self::RESOURCES[$resourceKey] ?? null;

        if ($action !== null && $resource !== null) {
            return $action.' — '.$resource;
        }
        if ($resource !== null) {
            return $resource;
        }
        if ($action !== null) {
            return $action;
        }

        return Str::headline($key);
    }
}
