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
        'create_direct' => 'بيع مباشر',
    ];

    /**
     * شرح الإجراء بجملة — لأن الفعل وحده لا يميّز.
     *
     * «اعتماد» و«ترحيل» و«عكس» تبدو متقاربة، والفرق بينها هو الفرق بين موافقةٍ
     * قابلة للتراجع وتثبيتٍ في الدفاتر لا يُحذف. من لا يعرف الفرق يمنح الثلاثة
     * معًا لأنها «تبدو متشابهة».
     */
    private const ACTION_HINTS = [
        'view' => 'الاطّلاع على :r دون أي تعديل.',
        'create' => 'إنشاء سجلّات جديدة في :r.',
        'update' => 'تعديل :r القائمة.',
        'delete' => 'حذف من :r — لا تراجع عنه.',
        'manage' => 'صلاحية كاملة على :r: إضافة وتعديل وحذف.',
        'approve' => 'اعتماد :r — الموافقة التي تفتح باب التنفيذ.',
        'post' => 'ترحيل :r إلى الدفاتر — بعده لا تُحذف، والتصحيح بقيدٍ عاكس.',
        'reverse' => 'إلغاء أثر :r بقيدٍ عاكس بدل حذفها.',
        'cancel' => 'إلغاء :r بعد إنشائها.',
        'close' => 'إغلاق :r نهائيًّا ومنع تعديلها.',
        'payout' => 'صرف المستحقّات فعليًّا — يُخرج مالًا من الخزينة.',
        'pay' => 'تسجيل دفعٍ فعليّ على :r.',
        'capture' => 'تحصيل المبلغ فعليًّا.',
        'refund' => 'ردّ مبلغ إلى العميل.',
        'reconcile' => 'مطابقة :r مع كشف المزوّد وإقرار الفروق.',
        'finalize' => 'إنهاء :r وإقفالها.',
        'view_cost' => 'رؤية تكلفة الشراء وهامش الربح — بيانات لا يراها البائع عادةً.',
        'view_own' => 'رؤية سجلّاته هو فقط، لا سجلّات غيره.',
        'view_team' => 'رؤية سجلّات الفريق كلّه لا سجلّاته وحده.',
        'override_price' => 'البيع بسعرٍ خارج المسموح — يمسّ الهامش مباشرةً.',
        'override_cost' => 'تجاوز التكلفة المحسوبة — يمسّ تقييم المخزون.',
        'confirm' => 'تأكيد :r ونقلها إلى المرحلة التالية.',
        'ship' => 'تسليم الشحنة لشركة التوصيل.',
        'dispatch' => 'إرسال بيانات الشحنة إلى شركة التوصيل.',
        'deliver' => 'تعليم الطلب مُسلَّمًا.',
        'receive' => 'استلام بضاعة وإدخالها للمخزون.',
        'issue' => 'صرف بضاعة من المخزون.',
        'transfer' => 'نقل بضاعة بين المستودعات.',
        'sync' => 'مزامنة :r مع النظام الخارجي.',
        'use' => 'استخدام :r.',
        'audit' => 'الاطّلاع على سجلّ التدقيق.',
        'block' => 'حظر عميل من الشراء.',
        'merge' => 'دمج سجلّين في واحد.',
        'fees' => 'إضافة رسوم على الشحنة.',
        'inspect' => 'فحص المرتجع وتقرير مصيره.',
        'fail' => 'تعليم المحاولة فاشلة.',
        'create_direct' => 'إنشاء طلب بيع مباشر (نقطة بيع) لا يمرّ بشركة التوصيل.',
    ];

    /**
     * شرحٌ صريح لصلاحيات لا يكفيها التركيب الآلي.
     *
     * تُكتَب هنا حين يكون **أثر** الصلاحية غير ظاهر من اسمها: ماذا تفتح، وما
     * الذي تكشفه، ومن الذي يجب ألّا يملكها.
     */
    private const DESCRIPTIONS = [
        'reports.ad_budget.view' => 'فتح صفحة «الميزانية اليومية»: ربح كل صنف على كل صفحة وتكلفة الطلب — تكشف التكلفة والهامش، فلا تُمنح لمن يبيع.',
        'reports.ad_budget.manage' => 'إدخال الصرف الإعلاني وعدد المحادثات، وضبط المصروف التشغيلي اليومي، وربط الحملات بالقنوات والأصناف. الأرقام هنا يُبنى عليها قرار إيقاف الصرف.',
        'marketing.contacts.view' => 'الاطّلاع على قائمة أرقام الزبائن التسويقية وحالات موافقتهم.',
        'marketing.contacts.manage' => 'استيراد أرقام الزبائن ووسم الانسحاب. **القائمة بيانٌ شخصيّ يُصدَّر** — من يملكها يستطيع أخذها معه.',
        'catalog.price_list.view' => 'صفحة «الأصناف والأسعار» للمسوّقين: سعر البيع وسعر الجملة والتوفّر — بلا تكلفة وبلا كميات.',
        'pricing.view_cost' => 'إظهار تكلفة الشراء وهامش الربح في شاشات الأصناف والطلبات.',
        'purchasing.shipments.view' => 'الاطّلاع على شحنات الاستيراد (الكونتينرات) ومصاريفها وتكلفتها المُحمّلة.',
        'purchasing.shipments.manage' => 'إنشاء شحنة استيراد وربط فواتير البضاعة والمصاريف بها.',
        'purchasing.shipments.close' => 'إغلاق الشحنة وترحيل فرق التقدير — لا يُتراجع عنه، ويُثبّت تكلفة البضاعة نهائيًّا.',
        'inventory.alerts.view' => 'رؤية تنبيهات نقص المخزون وحدود إعادة الطلب.',
        'reports.sales_summary.view' => 'تقارير المبيعات المجمّعة (حسب الزبون/المنتج/الموظف/المسوّق) — تكشف الأرباح.',
        'reports.statements.view' => 'كشوف حسابات العملاء والموردين والمستحقّ على كلٍّ منهم.',
        'reports.executive.view' => 'التقارير التنفيذية المجمّعة لكامل النشاط.',
        'reports.financial.view' => 'التقارير المالية المبنيّة على القيود المُرحّلة.',
        'reports.employees.view' => 'تقارير أداء الموظفين ومبيعاتهم.',
        'reports.marketers.view' => 'تقارير أداء المسوّقين وأرباحهم.',
        'reports.support.view' => 'تقارير خدمة العملاء والدعم.',
        'commissions.view_own' => 'رؤية عمولاته هو فقط — للموظف والمسوّق.',
        'commissions.view_team' => 'رؤية عمولات الفريق كلّه — للمدير.',
        'commissions.payout' => 'صرف العمولات فعليًّا: يُخرج مالًا من الخزينة ويُنشئ قيدًا.',
        'settings.roles.view' => 'الاطّلاع على الأدوار وصلاحياتها.',
        'settings.roles.manage' => 'تعديل صلاحيات الأدوار — **من يملكها يستطيع منح نفسه أي صلاحية أخرى.**',
        'settings.system.manage' => 'تعديل إعدادات النظام كاملةً بما فيها مفاتيح التكامل ووضع الصيانة.',
        'audit.view' => 'الاطّلاع على سجلّ التدقيق: من غيّر ماذا ومتى.',
        'dashboard.view' => 'فتح لوحة التحكّم الرئيسية.',
    ];

    /**
     * إجراءات حسّاسة: تُخرج مالًا، أو لا يُتراجَع عنها، أو تكشف التكلفة.
     *
     * تُوسَم في الواجهة كي لا تُمنح ضمن «تحديد الكل» بلا انتباه.
     */
    private const SENSITIVE_ACTIONS = [
        'delete', 'post', 'reverse', 'approve', 'payout', 'pay', 'refund', 'capture',
        'close', 'finalize', 'override_price', 'override_cost', 'view_cost', 'block', 'merge',
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
        'contacts' => 'جهات الاتصال التسويقية',
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
        // موارد كانت تسقط من الجدول فتظهر صلاحيتها بفعلٍ مجرّد («عرض») بلا ما
        // يُعرَض — وهي أكثر ما يُربك عند توزيع الأدوار.
        'price_list' => 'قائمة الأسعار',
        'reviews' => 'تقييمات المنتجات',
        'attributes' => 'سمات المنتجات',
        'brands' => 'العلامات التجارية',
        'categories' => 'الفئات',
        'tags' => 'الوسوم',
        'units' => 'وحدات القياس',
        'ad_budget' => 'الميزانية اليومية',
        'sales_summary' => 'ملخّص المبيعات',
        'statements' => 'كشوف الحسابات',
        'executive' => 'التقارير التنفيذية',
        'financial' => 'التقارير المالية',
        'employees' => 'تقارير الموظفين',
        'marketers' => 'تقارير المسوّقين',
        'support' => 'تقارير الدعم',
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

    /**
     * جملةٌ تشرح ما تفتحه الصلاحية فعلًا.
     *
     * الاسم وحده لا يكفي: «اعتماد» و«ترحيل» و«عكس» تبدو مترادفة لمن لا يعرف
     * الدفاتر، فتُمنح الثلاثة معًا. الشرح الصريح أسبق، ثم التركيب من الفعل
     * والمورد — فلا تبقى صلاحيةٌ بلا تفسير.
     */
    public static function describe(string $key): string
    {
        if (isset(self::DESCRIPTIONS[$key])) {
            return self::DESCRIPTIONS[$key];
        }

        $parts = explode('.', $key);
        $hint = self::ACTION_HINTS[end($parts)] ?? null;

        $resourceKey = count($parts) >= 3 ? $parts[1] : $parts[0];
        $resource = self::RESOURCES[$resourceKey] ?? null;

        if ($hint !== null) {
            return $resource !== null
                ? str_replace(':r', $resource, $hint)
                // بلا موردٍ معروف تبقى الجملة عامّة لكنها لا تُترك فارغة.
                : str_replace(':r', __('هذا القسم'), $hint);
        }

        return $resource !== null
            ? __('صلاحية على :r.', ['r' => $resource])
            : self::for($key);
    }

    /** إجراءٌ يُخرج مالًا أو لا يُتراجَع عنه أو يكشف التكلفة. */
    public static function isSensitive(string $key): bool
    {
        $parts = explode('.', $key);

        return in_array(end($parts), self::SENSITIVE_ACTIONS, true)
            // تعديل الأدوار يمنح صاحبَه كلَّ شيء بالتبعية.
            || $key === 'settings.roles.manage';
    }
}
