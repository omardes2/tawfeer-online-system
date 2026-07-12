<?php

return [
    'title' => 'المرتجعات',
    'rma' => 'المرتجعات والاستبدال',
    'number' => 'رقم الطلب',
    'order' => 'الطلب',
    'type' => 'النوع',
    'reason' => 'السبب',
    'resolution' => 'التسوية',
    'scope' => 'النطاق',
    'status' => 'الحالة',
    'requested_by' => 'مقدّم الطلب',
    'refund_amount' => 'مبلغ الاسترداد',
    'price_difference' => 'فرق السعر',
    'notes' => 'ملاحظات',
    'items' => 'البنود',
    'qty' => 'الكمية',
    'variant' => 'المنتج',
    'photos' => 'الصور',
    'add_photo' => 'إضافة صورة',
    'timeline' => 'الخطّ الزمني',
    'new' => 'طلب مرتجع جديد',
    'create' => 'إنشاء الطلب',
    'lookup_order' => 'ابحث برقم الطلب',
    'no_returns' => 'لا توجد طلبات مرتجع.',
    'all_statuses' => 'كل الحالات',
    'linked_shipment' => 'شحنة مرتبطة',
    'inspection_result' => 'نتيجة الفحص',
    'inventory_route' => 'توجيه المخزون',
    'restocked' => 'أُعيدت للمخزون',

    // إجراءات المراحل
    'approve' => 'اعتماد (مشرف)',
    'reject' => 'رفض',
    'receive' => 'استلام (مستودع)',
    'inspect' => 'فحص وتوجيه',
    'complete' => 'قرار نهائي وإكمال',
    'cancel' => 'إلغاء',
    'reject_reason' => 'سبب الرفض',
    'create_linked_shipment' => 'إنشاء شحنة مرتجع مرتبطة',
    'carrier' => 'شركة التوصيل',
    'apply_inspection' => 'حفظ الفحص',

    // رسائل
    'created' => 'أُنشئ طلب المرتجع.',
    'approved' => 'اعتُمد الطلب.',
    'rejected' => 'رُفض الطلب.',
    'received' => 'استُلمت البضاعة.',
    'inspected' => 'تمّ الفحص وتوجيه المخزون.',
    'completed' => 'اكتمل: عُكست العمولة/المخزون وحُدّثت حالة الطلب.',
    'cancelled' => 'أُلغي الطلب.',
    'photo_added' => 'أُضيفت الصورة.',

    'type_label' => [
        'return' => 'إرجاع',
        'exchange' => 'استبدال',
        'replacement' => 'إبدال (بديل)',
    ],

    'resolution_label' => [
        'refund' => 'استرداد مبلغ',
        'no_refund' => 'بلا استرداد',
        'store_credit' => 'رصيد دائن',
        'replacement' => 'إبدال بديل',
    ],

    'reason_label' => [
        'wrong_item' => 'صنف خاطئ',
        'damaged' => 'تالف',
        'missing_item' => 'ناقص/مفقود',
        'customer_refused' => 'رفض العميل',
        'delivery_company_issue' => 'مشكلة شركة التوصيل',
        'internal_warehouse_mistake' => 'خطأ مستودع داخلي',
        'changed_mind' => 'عدل العميل رأيه',
        'other' => 'أخرى',
    ],

    'status_label' => [
        'return_request' => 'طلب مقدّم',
        'approved' => 'معتمد',
        'received' => 'مستلَم',
        'inspected' => 'مفحوص',
        'completed' => 'مكتمل',
        'rejected' => 'مرفوض',
        'cancelled' => 'ملغى',
    ],

    'result_label' => [
        'sellable' => 'صالح للبيع',
        'damaged' => 'تالف',
        'wrong_item' => 'صنف خاطئ',
        'missing_parts' => 'أجزاء ناقصة',
        'quarantine' => 'حجر',
    ],

    'route_label' => [
        'restock' => 'إعادة للمخزون',
        'damaged' => 'إلى التالف',
        'none' => 'بلا حركة',
    ],
];
