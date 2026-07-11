<div dir="rtl">

# التنقّل وهندسة المعلومات للواجهة — Tawfeer Online (RTL)

هذا المستند يعرّف **بنية التنقّل (Navigation) وهندسة المعلومات (IA)** للواجهات، بالعربية أساسًا (RTL) والإنجليزية ثانويًا. ملزِم ومتوافق مع [`ARCHITECTURE.md`](../ARCHITECTURE.md)، و[`DECISIONS.md`](./DECISIONS.md)، وعقد الـ API في [`API_CONTRACT.md`](./API_CONTRACT.md).

> **مبادئ حاكمة:** كل عنصر قائمة يُكشف بصلاحية (المبدأ 12) · التكلفة/الهامش محجوبة بلا `pricing.view_cost` (ADR-013) · الواجهة تستهلك نفس `/api/v1` (المبدأ 11) · المسارات المفاهيمية تستخدم `uuid` لا `id` (المبدأ 4).
>
> **المرحلة:** كل عنصر مُعلَّم برقم مرحلته. المرحلة 2 = الكتالوج + المخزون + الموردون + الإعدادات. ما بعدها **محجوز/معطّل** في الواجهة.

---

## 1. القشرتان (Two Shells)

| القشرة | الجمهور | المصادقة | الدخول |
|--------|---------|----------|--------|
| **المتجر (Storefront)** | العميل النهائي / زائر | عام + تسجيل دخول عميل | `/` |
| **لوحة التحكم (Admin / Back-office)** | الموظفون والأدوار الداخلية | Sanctum + RBAC | `/admin` |

- **RTL افتراضيًا:** `dir="rtl" lang="ar"` (خط Tajawal، مطابق للأساس)؛ زر تبديل `ar/en` يضبط `Accept-Language` والاتجاه.
- المتجر في المرحلة 2 **عرض كتالوج فقط** (تصفّح المنتجات/الفئات)؛ السلة والطلب والحساب في المرحلة 3.

### 1.1 قشرة المتجر (Storefront) — المرحلة 2 محدودة
| القسم | المسار | المرحلة |
|------|--------|:-------:|
| الرئيسية | `/` | 2 (عرض) |
| تصفّح الفئات | `/c/{category-uuid}` | 2 (عرض) |
| صفحة منتج | `/p/{product-uuid}` | 2 (عرض) |
| بحث | `/search` | 2 (عرض) |
| السلة / الدفع | `/cart`, `/checkout` | 3 محجوز |
| حساب العميل / طلباتي | `/account`, `/account/orders` | 3 محجوز |

---

## 2. شجرة قائمة لوحة التحكم (Admin Sidebar Tree)

الشريط الجانبي (يمين الشاشة في RTL) مجمّع حسب الوحدة. كل عنصر: الصلاحية الكاشفة + المرحلة. العناصر المحجوزة تظهر **معطّلة برمز مرحلة** أو تُخفى كليًا (حسب إعداد العرض).

- **لوحة المعلومات (Dashboard)** — `dashboard.view` — المرحلة 2
- **الكتالوج (Catalog)** — المرحلة 2
  - المنتجات — `catalog.products.view`
  - الفئات — `catalog.categories.view`
  - العلامات التجارية — `catalog.brands.view`
  - الوحدات — `catalog.units.view`
  - السمات والقيم — `catalog.attributes.view`
  - الوسوم — `catalog.products.view`
- **المخزون (Inventory)** — المرحلة 2
  - الأرصدة (Stocks) — `inventory.stocks.view`
  - الحركات (Movements) — `inventory.movements.view`
  - دفتر المخزون (Ledger) — `inventory.ledger.view`
  - التحويلات (Transfers) — `inventory.transfers.view`
  - التسويات (Adjustments) — `inventory.adjustments.view`
  - الجرد (Counts) — `inventory.adjustments.view` — المرحلة 2 (عبر التسويات)
  - الحجوزات (Reservations) — `inventory.reservations.view` — المرحلة 2 (بنية Phase 3)
- **المشتريات (Purchasing)** — المرحلة 2/3
  - الموردون — `purchasing.suppliers.view` — المرحلة 2
  - أوامر الشراء (POs) — `purchasing.orders.view` — المرحلة 3 محجوز
  - الاستلام (Receiving) — `purchasing.receiving.view` — المرحلة 3 محجوز
- **المبيعات/الطلبات (Sales/Orders)** — `sales.orders.view` — المرحلة 3 محجوز
- **العملاء/CRM** — `crm.customers.view` — المرحلة 3 محجوز
- **المسوّقون (Marketers)** — `affiliate.marketers.view` — المرحلة 3 محجوز
- **الرسائل (Messaging/Omnichannel)** — `messaging.threads.view` — المرحلة 3 محجوز
- **المحاسبة (Accounting)** — `accounting.view` — المرحلة 5 محجوز
- **التقارير (Reports)** — `reports.view` — المرحلة 2 (كتالوج/مخزون) ثم تتوسّع
- **الإعدادات (Settings)** — المرحلة 2
  - الفروع — `settings.branches.view`
  - المستودعات والمواقع — `settings.warehouses.view`
  - الجغرافيا (محافظات/مدن/مناطق/مناطق شحن) — `settings.geography.view` — عرض
  - العملات — `settings.currencies.view`
  - الضرائب — `settings.taxes.view`
  - الحالات (طلب/دفع/شحن) — `settings.statuses.view` — عرض/إدارة (المبدأ 10)
  - الأدوار والصلاحيات — `settings.roles.view`
  - المستخدمون — `settings.users.view`
  - سجلّ التدقيق (Audit Log) — `audit.view`

---

## 3. اختلاف الواجهة حسب الدور (Per-Role Navigation)

الأدوار السبعة المزروعة (من `RolePermissionSeeder`): admin · manager · sales · accountant · warehouse · affiliate(marketer) · customer. الواجهة تُبنى ديناميكيًا من مصفوفة `permissions` المُعادة في `GET /api/v1/me`.

| الدور | صفحة الهبوط | ما يراه (المرحلة 2) | رؤية التكلفة (ADR-013) |
|------|-------------|----------------------|--------------------------|
| **admin** | Dashboard كامل | كل الوحدات والإعدادات وسجلّ التدقيق | يرى التكلفة والهامش |
| **manager** | Dashboard تشغيلي | الكتالوج، المخزون، الموردون، تقارير، إعدادات محدودة | يرى التكلفة (بالصلاحية) |
| **sales** | كتالوج/منتجات | تصفّح المنتجات وأسعار البيع، توفّر المخزون | **لا يرى** التكلفة/`min_price`/الهامش |
| **accountant** | تقارير مالية | التكلفة، تقييم المخزون، الموردون، (محاسبة لاحقًا) | يرى التكلفة والهامش |
| **warehouse** | المخزون | الأرصدة، الحركات، التحويلات، التسويات، الاستلام | يرى الكميات؛ التكلفة حسب الصلاحية |
| **marketer (affiliate)** | كتالوج المسوّق | المنتجات و`marketer_price` وأسعار البيع | **لا يرى** التكلفة/`average_cost` |
| **customer** | المتجر (Storefront) | تصفّح كتالوج فقط (المرحلة 2) | لا وصول للوحة التحكم |

- عناصر القائمة غير المصرّح بها **لا تُعرض** (تُقصى من الشجرة) لا مجرّد تعطيلها — تقليل التسرّب المعلوماتي.
- الأعمدة الحسّاسة (التكلفة/الهامش) تُحذف من الجداول والتصدير للأدوار بلا `pricing.view_cost`، اتساقًا مع حذفها في الـ Resource (ADR-013).

---

## 4. جرد الشاشات الأساسية للمرحلة 2 (Key Screens)

نمط موحّد لكل كيان: **قائمة (List) ← تفاصيل (Detail) ← إنشاء/تعديل (Create/Edit)**.

### 4.1 الكتالوج (Catalog)
| الشاشة | النمط | ملاحظات |
|--------|------|---------|
| المنتجات | قائمة + بحث/تصفية (فئة، علامة، فعّال) | جدول: الاسم، الفئة، عدد المتغيّرات، سعر التجزئة، الحالة |
| تفاصيل منتج | Detail + تبويبات (متغيّرات/صور/سمات/وسوم) | التكلفة في تبويب منفصل محجوب بلا `pricing.view_cost` |
| إنشاء/تعديل منتج | نموذج + محرّر متغيّرات متعدّد | SKU/باركود فريد (ADR-004)؛ أسعار الطبقات (ADR-006) |
| الفئات | شجرة قابلة للطي (Tree) | سحب/ترتيب، أب/ابن |
| العلامات/الوحدات/السمات/الوسوم | قوائم بسيطة + Modal إنشاء/تعديل | مرجعية |

### 4.2 المخزون (Inventory)
| الشاشة | النمط | ملاحظات |
|--------|------|---------|
| الأرصدة | قائمة (متغيّر × مستودع) | أعمدة الدلاء: on_hand / reserved / available / damaged (ADR-007)؛ التكلفة محجوبة |
| تفاصيل رصيد | Detail | الدلاء + آخر الحركات + WAC (بالصلاحية) |
| الحركات + الدفتر | قائمة للقراءة (append-only) | لا تعديل/حذف (ADR-008/020)؛ فلترة بالنوع القانوني |
| تسوية جرد | نموذج إنشاء (سطور) | `ADJ-YYYY-seq`؛ تأكيد داخل معاملة؛ منع السالب (ADR-007a) |
| تحويل مخزون | نموذج + شاشة استلام | `TRF-YYYY-seq`؛ `transfer_out` ثم `transfer_in` عند الاستلام |
| الحجوزات | قائمة للقراءة | بنية Phase 3؛ تحرير يدوي بالصلاحية |

### 4.3 المشتريات (Purchasing)
| الشاشة | النمط | ملاحظات |
|--------|------|---------|
| الموردون | قائمة + تفاصيل + إنشاء/تعديل | جهات الاتصال ضمن تبويب |
| أوامر الشراء / الاستلام | **محجوزة (المرحلة 3)** | حالات `draft→…→closed`؛ تظهر معطّلة |

### 4.4 الإعدادات (Settings)
| الشاشة | النمط | ملاحظات |
|--------|------|---------|
| الفروع | قائمة + تفاصيل + إعدادات فرع | الفرع الافتراضي غير قابل للحذف |
| المستودعات والمواقع | قائمة + مواقع متداخلة | مرتبط بفرع (ADR-003) |
| الجغرافيا | تصفّح هرمي (محافظة/مدينة/منطقة) + مناطق شحن | **عرض** في المرحلة 2 (ADR-014) |
| العملات/الضرائب | قوائم + إنشاء/تعديل | العملة الأساسية واحدة (ADR-001)؛ الضريبة إدارة الآن (ADR-015) |
| الحالات | قوائم قابلة للإدارة | key ثابت، name مترجم (المبدأ 10/ADR-017) |
| الأدوار/المستخدمون | قوائم + تعيين صلاحيات | RBAC (المبدأ 12) |
| سجلّ التدقيق | قائمة للقراءة (append-only) | `audit.view`؛ قبل/بعد (المبدأ 8) |

### 4.5 ملاحظات التخطيط والحالات (Layout & States)
- **RTL:** الشريط الجانبي يمينًا، المحتوى يسارًا، الأيقونات معكوسة اتجاهيًا؛ الأرقام والـ SKU/الباركود LTR داخل سياق RTL.
- **العربية أساس / الإنجليزية ثانوي:** التسميات عربية، والمصطلحات التقنية (SKU, WAC, UUID) تُعرض إنجليزية.
- **فتات المسار (Breadcrumbs):** مثال `لوحة التحكم / الكتالوج / المنتجات / تيشيرت قطن`.
- **حالة فارغة (Empty):** رسالة + دعوة إجراء (مثل «لا منتجات بعد — أضف أول منتج») مع احترام صلاحية الإنشاء.
- **حالة منع الصلاحية (Permission-denied):** إن وصل المستخدم لمسار محجوب ⇒ صفحة 403 ودّية دون كشف تفاصيل؛ العنصر أصلًا مُقصى من القائمة.
- **حالة القراءة فقط:** الدفاتر/الجغرافيا/التدقيق تُعرض بلا أزرار كتابة (تعطيل بصري + منع API).

---

## 5. خريطة التنقّل (Navigation Map)

| عنصر القائمة | المسار (مفاهيمي) | الصلاحية | المرحلة |
|--------------|-------------------|----------|:-------:|
| لوحة المعلومات | `/admin` | `dashboard.view` | 2 |
| المنتجات | `/admin/catalog/products` | `catalog.products.view` | 2 |
| تفاصيل منتج | `/admin/catalog/products/{uuid}` | `catalog.products.view` | 2 |
| الفئات | `/admin/catalog/categories` | `catalog.categories.view` | 2 |
| العلامات | `/admin/catalog/brands` | `catalog.brands.view` | 2 |
| الوحدات | `/admin/catalog/units` | `catalog.units.view` | 2 |
| السمات | `/admin/catalog/attributes` | `catalog.attributes.view` | 2 |
| الوسوم | `/admin/catalog/tags` | `catalog.products.view` | 2 |
| أرصدة المخزون | `/admin/inventory/stocks` | `inventory.stocks.view` | 2 |
| الحركات | `/admin/inventory/movements` | `inventory.movements.view` | 2 |
| دفتر المخزون | `/admin/inventory/ledger` | `inventory.ledger.view` | 2 |
| التحويلات | `/admin/inventory/transfers` | `inventory.transfers.view` | 2 |
| التسويات | `/admin/inventory/adjustments` | `inventory.adjustments.view` | 2 |
| الجرد | `/admin/inventory/counts` | `inventory.adjustments.view` | 2 |
| الحجوزات | `/admin/inventory/reservations` | `inventory.reservations.view` | 2 |
| الموردون | `/admin/purchasing/suppliers` | `purchasing.suppliers.view` | 2 |
| أوامر الشراء | `/admin/purchasing/orders` | `purchasing.orders.view` | 3 محجوز |
| الاستلام | `/admin/purchasing/receiving` | `purchasing.receiving.view` | 3 محجوز |
| الطلبات | `/admin/sales/orders` | `sales.orders.view` | 3 محجوز |
| العملاء/CRM | `/admin/crm/customers` | `crm.customers.view` | 3 محجوز |
| المسوّقون | `/admin/affiliate/marketers` | `affiliate.marketers.view` | 3 محجوز |
| الرسائل | `/admin/messaging` | `messaging.threads.view` | 3 محجوز |
| المحاسبة | `/admin/accounting` | `accounting.view` | 5 محجوز |
| التقارير | `/admin/reports` | `reports.view` | 2 |
| الفروع | `/admin/settings/branches` | `settings.branches.view` | 2 |
| المستودعات | `/admin/settings/warehouses` | `settings.warehouses.view` | 2 |
| الجغرافيا | `/admin/settings/geography` | `settings.geography.view` | 2 (عرض) |
| العملات | `/admin/settings/currencies` | `settings.currencies.view` | 2 |
| الضرائب | `/admin/settings/taxes` | `settings.taxes.view` | 2 |
| الحالات | `/admin/settings/statuses` | `settings.statuses.view` | 2 |
| الأدوار | `/admin/settings/roles` | `settings.roles.view` | 2 |
| المستخدمون | `/admin/settings/users` | `settings.users.view` | 2 |
| سجلّ التدقيق | `/admin/settings/audit-log` | `audit.view` | 2 |

> المسارات مفاهيمية للتنقّل فقط؛ المعرّفات في الروابط `uuid` (المبدأ 4). المتجر (Storefront) في القسم 1.1.

---

*يلتزم هذا المستند بقائمة كيانات المرحلة 2 (27) في `DECISIONS.md`، ويتطابق مع تجميعات نقاط النهاية في `API_CONTRACT.md`.*

</div>
