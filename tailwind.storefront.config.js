import base from './tailwind.config.js';

/**
 * إعداد Tailwind الخاصّ بواجهة المتجر.
 *
 * الإعداد الموحّد كان يمسح `resources/views/**` كلّه، فتحمل حزمة المتجر كلّ صنف
 * تستعمله لوحة الإدارة والعكس. القوالب هنا محصورة بما يُرسَم فعلًا في المتجر،
 * والسمة (الألوان والخطوط والمسافات) تُورَّث كما هي فلا تتفرّع الهوية بين ملفّين.
 *
 * كل قالب جديد يُعرَض داخل المتجر يجب أن يقع تحت أحد هذه المسارات، وإلّا لم
 * تُولَّد أصنافه.
 */
export default {
    ...base,
    content: [
        './resources/views/storefront/**/*.blade.php',
        './resources/views/components/storefront/**/*.blade.php',
        './resources/views/vendor/pagination/storefront.blade.php',
        // شارات الحالة مشتركة مع لوحة الإدارة وتظهر في صفحات الطلبات
        './resources/views/components/sales/*.blade.php',
        './resources/views/components/payment/*.blade.php',
        './resources/views/components/shipping/*.blade.php',
        './resources/js/storefront.js',
        // ملاحظة: `storage/framework/views` مستثنى عمدًا — إدراجه يجعل حجم الحزمة
        // يتغيّر بحسب ما جرى تصريفه في ذاكرة القوالب (قد يُدخل أصناف الإدارة).
    ],
};
