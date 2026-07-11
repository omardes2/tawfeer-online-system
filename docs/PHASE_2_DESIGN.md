<div dir="rtl">

# تصميم قاعدة البيانات — المرحلة 2 (Catalog + Inventory + Geography)

**المشروع:** Tawfeer Online — Laravel 13.19 / PHP 8.4 / MySQL 8 (InnoDB, `utf8mb4_unicode_ci`)
**الحالة:** تصميم تفصيلي للمرحلة 2 فقط (27 كيانًا).
**المرجعية المُلزِمة:** [`ARCHITECTURE.md`](../ARCHITECTURE.md) (المبادئ 1–14) + [`docs/DECISIONS.md`](DECISIONS.md) (ADR-001 → ADR-020). أي تعارض يُحسم لصالح `DECISIONS.md`.
**المكمِّل:** [`docs/DATA_DICTIONARY.md`](DATA_DICTIONARY.md) — قاموس بيانات مسطّح لكل الأعمدة (نفس الأسماء والأنواع).

> **هذا مستند تصميم فقط — لا كود ولا هجرات.** التفاصيل الدقيقة تُثبَّت في ملفات الترحيل عند التنفيذ.

---

## 0. الاصطلاحات العامة (Conventions) — مُلزِمة لكل الجداول

| البند | القاعدة | المرجع |
|------|--------|--------|
| المفتاح الداخلي | `id` = `BIGINT UNSIGNED auto-increment` | المبدأ 4 / ADR-002 |
| المعرّف الخارجي | `uuid` = `CHAR(36)` فريد مفهرس — فقط للكيانات المكشوفة عبر API/روابط | المبدأ 4 / ADR-002 |
| المبالغ المالية | `decimal(15,2)` | المبدأ 6 / ADR-001 |
| التكلفة والمتوسط المرجّح (WAC) | `decimal(15,4)` | ADR-001 / ADR-005 |
| الكميات | `decimal(15,3)` (وحدات كسرية) | المبدأ 6 / ADR-001 |
| النِّسب (ضريبة/هامش/عمولة) | `decimal(8,4)` | ADR-006 / ADR-015 |
| سعر الصرف | `decimal(15,6)` | ADR-001 |
| الطوابع الزمنية | `created_at` / `updated_at` على كل جدول (عدا الدفاتر append-only: `created_at` فقط) | — |
| الحذف الناعم | `deleted_at` على الكيانات الرئيسية؛ **لا** على الدفاتر/الحركات | المبدأ 5 / ADR-020 |
| `branch_id` | على كل كيان تشغيلي (nullable الآن، يُملأ بالفرع الافتراضي) — لا قيم ثابتة `=1` | المبدأ 1 / ADR-003 |
| `tenant_id` | **لا يُضاف الآن**؛ لا مفاتيح فريدة تمنع إضافته لاحقًا (فرادة مركّبة عند الحاجة) | المبدأ 3 / ADR-004 |
| ممنوع `float`/`double` لأي مبلغ | إلزامي | المبدأ 6 |

**رموز جدول الأعمدة:** R = مطلوب (NOT NULL)، N = يقبل NULL. FK = مفتاح أجنبي. **Auditable** = يحمل تريتة التدقيق (ADR-020). **UUID** = يحمل معرّفًا خارجيًا.

> **الجداول المساعدة (pivot/children):** بعض الكيانات الـ27 تتطلّب جداول ربط أو بنود تابعة (مثل `shipping_zone_area`, `variant_attribute_value`, `stock_adjustment_items`). تُوثَّق تحت كيانها الأب وتُعلَّم بوضوح؛ وهي **ليست** ضمن العدّ الرسمي (27).

---

# القسم أ — الأساس والجغرافيا

## 1. `branches` — الفروع *(موجود من المرحلة 1 — امتدادات فقط)*
**الغرض:** الوحدة التنظيمية العليا؛ كل كيان تشغيلي يتبع فرعًا.
الأعمدة القائمة من المرحلة 1: `id, uuid, name, code(unique), address, phone, is_default, is_active, timestamps, deleted_at`.

**امتدادات المرحلة 2 (أعمدة تُضاف):**

| العمود | النوع | R/N | افتراضي | ملاحظة |
|--------|------|:---:|--------|--------|
| email | string(150) | N | NULL | بريد الفرع |
| tax_number | string(50) | N | NULL | الرقم الضريبي |
| default_currency_id | BIGINT FK→currencies | N | NULL | عملة عرض الفرع (الدفاتر بالعملة الأساسية — ADR-001) |
| default_warehouse_id | BIGINT FK→warehouses | N | NULL | مستودع افتراضي للفرع |
| timezone | string(40) | N | `'Asia/Riyadh'` | — |

- **FK:** `default_currency_id`→currencies (ON DELETE SET NULL)، `default_warehouse_id`→warehouses (ON DELETE SET NULL).
- **Soft delete:** نعم (قائم). **Auditable:** نعم. **UUID:** نعم (قائم).
- **علاقات:** has-many warehouses, suppliers, products, branch_settings.
- **ملاحظة اعتمادية:** `default_warehouse_id` يشير إلى جدول يُنشأ لاحقًا في نفس المرحلة → تُضاف قيوده بعد إنشاء `warehouses` (انظر ترتيب الهجرات).

---

## 2. `branch_settings` — إعدادات الفرع
**الغرض:** تجاوزات إعدادات على مستوى الفرع تكمّل جدول `settings` العام (المبدأ 9).

| العمود | النوع | R/N | افتراضي | ملاحظة |
|--------|------|:---:|--------|--------|
| id | BIGINT PK | R | — | |
| branch_id | BIGINT FK→branches | R | — | |
| key | string(150) | R | — | مفتاح الإعداد |
| value | json | N | NULL | القيمة |
| group | string(60) | N | `'general'` | تجميع منطقي |
| type | string(30) | N | `'string'` | string/bool/int/json |
| timestamps | | | | |

- **FK:** `branch_id`→branches (ON DELETE CASCADE).
- **Unique:** (`branch_id`,`key`) — فرادة مركّبة (لا مفتاح عام يمنع Multi-Tenant لاحقًا — ADR-004).
- **Index:** (`branch_id`,`group`).
- **Soft delete:** لا. **Auditable:** نعم. **UUID:** لا.
- **علاقات:** belongs-to branch.
- **تحقّق:** `key` غير فارغ؛ القراءة عبر طبقة `Settings` مع cache وإبطاله عند التحديث.

---

## 3. `governorates` — المحافظات
**الغرض:** أعلى مستوى في تسلسل الجغرافيا (المحافظة → المدينة → المنطقة — ADR-014).

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| name | string(120) | R | — |
| name_en | string(120) | N | NULL |
| code | string(20) | N | NULL |
| country_code | char(2) | R | `'SA'` |
| sort_order | int | R | `0` |
| is_active | boolean | R | `true` |
| timestamps | | | |

- **Unique:** (`country_code`,`code`) عند وجود `code`.
- **Index:** (`is_active`,`sort_order`).
- **Soft delete:** لا (بيانات مرجعية). **Auditable:** لا. **UUID:** لا (مرجعي داخلي — ADR-002).
- **علاقات:** has-many cities.

---

## 4. `cities` — المدن
**الغرض:** المستوى الثاني في التسلسل الجغرافي.

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| governorate_id | BIGINT FK→governorates | R | — |
| name | string(120) | R | — |
| name_en | string(120) | N | NULL |
| code | string(20) | N | NULL |
| sort_order | int | R | `0` |
| is_active | boolean | R | `true` |
| timestamps | | | |

- **FK:** `governorate_id`→governorates (ON DELETE RESTRICT).
- **Unique:** (`governorate_id`,`name`).
- **Index:** `governorate_id`, (`is_active`,`sort_order`).
- **Soft delete:** لا. **Auditable:** لا. **UUID:** لا.
- **علاقات:** belongs-to governorate; has-many areas; many-to-many shipping_zones (عبر `shipping_zone_city`).

---

## 5. `areas` — المناطق
**الغرض:** أدقّ مستوى جغرافي؛ عنوان العميل (Phase 3) يشير إليه.

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| city_id | BIGINT FK→cities | R | — |
| name | string(120) | R | — |
| name_en | string(120) | N | NULL |
| code | string(20) | N | NULL |
| postal_code | string(15) | N | NULL |
| sort_order | int | R | `0` |
| is_active | boolean | R | `true` |
| timestamps | | | |

- **FK:** `city_id`→cities (ON DELETE RESTRICT).
- **Unique:** (`city_id`,`name`).
- **Index:** `city_id`, (`is_active`,`sort_order`).
- **Soft delete:** لا. **Auditable:** لا. **UUID:** لا.
- **علاقات:** belongs-to city; many-to-many shipping_zones (عبر `shipping_zone_area`).

---

## 6. `shipping_zones` — مناطق الشحن
**الغرض:** تجميعة تسعير/تغطية مستقلة عن التسلسل الإداري، تُربط بمدن/مناطق كثير-لكثير (ADR-014). التسعير الفعلي في Phase 3.

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| uuid | char(36) | R | مُولّد |
| branch_id | BIGINT FK→branches | N | NULL |
| name | string(120) | R | — |
| code | string(30) | R | — |
| description | text | N | NULL |
| is_active | boolean | R | `true` |
| timestamps + deleted_at | | | |

- **FK:** `branch_id`→branches (ON DELETE SET NULL).
- **Unique:** `uuid`؛ `code`.
- **Index:** `is_active`.
- **Soft delete:** نعم. **Auditable:** نعم. **UUID:** نعم (قد تُكشف في API الشحن).
- **علاقات:** many-to-many cities/areas.

**جدولا الربط (مساعدان — خارج الـ27):**
- `shipping_zone_city`: `id, shipping_zone_id FK→shipping_zones (CASCADE), city_id FK→cities (CASCADE)`, Unique(`shipping_zone_id`,`city_id`).
- `shipping_zone_area`: `id, shipping_zone_id FK→shipping_zones (CASCADE), area_id FK→areas (CASCADE)`, Unique(`shipping_zone_id`,`area_id`).

---

# القسم ب — المستودعات والموردون

## 7. `warehouses` — المستودعات
**الغرض:** موقع مادّي للمخزون؛ مستودع واحد يتبع فرعًا واحدًا (ADR-003، المبدأ 2).

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| uuid | char(36) | R | مُولّد |
| branch_id | BIGINT FK→branches | R | — |
| name | string(120) | R | — |
| code | string(30) | R | — |
| type | string(20) | R | `'main'` (main/transit/virtual/damaged) |
| governorate_id | BIGINT FK→governorates | N | NULL |
| city_id | BIGINT FK→cities | N | NULL |
| address | string(255) | N | NULL |
| phone | string(30) | N | NULL |
| allow_negative | boolean | R | `false` |
| is_default | boolean | R | `false` |
| is_active | boolean | R | `true` |
| timestamps + deleted_at | | | |

- **FK:** `branch_id`→branches (RESTRICT)، `governorate_id`/`city_id`→(SET NULL).
- **Unique:** `uuid`؛ (`branch_id`,`code`) — فرادة مركّبة (ADR-004).
- **Index:** `branch_id`, `is_active`.
- **Soft delete:** نعم. **Auditable:** نعم. **UUID:** نعم (يُكشف في API — ADR-002).
- **علاقات:** belongs-to branch; has-many warehouse_locations, inventory_stocks, inventory_movements.
- **تحقّق:** `allow_negative` يحكم منع الرصيد السالب لكل مستودع (ADR-007a).

---

## 8. `warehouse_locations` — مواقع التخزين داخل المستودع
**الغرض:** تقسيم داخلي (رفّ/صفّ/صندوق) لتحديد موضع الصنف.

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| warehouse_id | BIGINT FK→warehouses | R | — |
| parent_id | BIGINT FK→warehouse_locations | N | NULL |
| code | string(40) | R | — |
| name | string(120) | N | NULL |
| type | string(20) | R | `'bin'` (zone/rack/shelf/bin) |
| is_active | boolean | R | `true` |
| timestamps | | | |

- **FK:** `warehouse_id`→warehouses (CASCADE)، `parent_id`→self (SET NULL).
- **Unique:** (`warehouse_id`,`code`).
- **Index:** `warehouse_id`, `parent_id`.
- **Soft delete:** لا. **Auditable:** لا. **UUID:** لا.
- **علاقات:** belongs-to warehouse; self-parent (شجري).

---

## 9. `suppliers` — الموردون
**الغرض:** جهات التوريد؛ مرجع لأوامر الشراء (Phase 4).

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| uuid | char(36) | R | مُولّد |
| branch_id | BIGINT FK→branches | N | NULL |
| name | string(150) | R | — |
| code | string(30) | R | — |
| legal_name | string(200) | N | NULL |
| tax_number | string(50) | N | NULL |
| email | string(150) | N | NULL |
| phone | string(30) | N | NULL |
| address | string(255) | N | NULL |
| governorate_id | BIGINT FK→governorates | N | NULL |
| city_id | BIGINT FK→cities | N | NULL |
| currency_id | BIGINT FK→currencies | N | NULL |
| payment_terms_days | int | R | `0` |
| credit_limit | decimal(15,2) | R | `0.00` |
| opening_balance | decimal(15,2) | R | `0.00` |
| notes | text | N | NULL |
| is_active | boolean | R | `true` |
| timestamps + deleted_at | | | |

- **FK:** `branch_id`(SET NULL)، `governorate_id`/`city_id`(SET NULL)، `currency_id`→currencies (SET NULL).
- **Unique:** `uuid`؛ `code`.
- **Index:** `branch_id`, `is_active`, `name`.
- **Soft delete:** نعم. **Auditable:** نعم (ADR-020). **UUID:** نعم (ADR-002).
- **علاقات:** has-many supplier_contacts; (Phase 4) has-many purchase_orders, supplier_invoices.

---

## 10. `supplier_contacts` — جهات اتصال المورد
**الغرض:** أشخاص التواصل لدى المورد.

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| supplier_id | BIGINT FK→suppliers | R | — |
| name | string(150) | R | — |
| position | string(100) | N | NULL |
| email | string(150) | N | NULL |
| phone | string(30) | N | NULL |
| is_primary | boolean | R | `false` |
| notes | text | N | NULL |
| timestamps | | | |

- **FK:** `supplier_id`→suppliers (CASCADE).
- **Index:** `supplier_id`, (`supplier_id`,`is_primary`).
- **Soft delete:** لا. **Auditable:** لا. **UUID:** لا.
- **علاقات:** belongs-to supplier.
- **تحقّق:** جهة أساسية واحدة كحدّ أقصى (يُفرض على مستوى الخدمة).

---

# القسم ج — الكتالوج

## 11. `categories` — الفئات
**الغرض:** تصنيف شجري للمنتجات.

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| uuid | char(36) | R | مُولّد |
| parent_id | BIGINT FK→categories | N | NULL |
| name | string(150) | R | — |
| slug | string(170) | R | — |
| description | text | N | NULL |
| image | string(255) | N | NULL |
| sort_order | int | R | `0` |
| is_active | boolean | R | `true` |
| timestamps + deleted_at | | | |

- **FK:** `parent_id`→self (SET NULL).
- **Unique:** `uuid`؛ `slug`.
- **Index:** `parent_id`, (`is_active`,`sort_order`).
- **Soft delete:** نعم. **Auditable:** نعم. **UUID:** نعم.
- **علاقات:** self-parent (شجري)؛ has-many products.

---

## 12. `brands` — العلامات التجارية
**الغرض:** العلامة/الماركة المصنّعة للمنتج.

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| uuid | char(36) | R | مُولّد |
| name | string(150) | R | — |
| slug | string(170) | R | — |
| logo | string(255) | N | NULL |
| description | text | N | NULL |
| is_active | boolean | R | `true` |
| timestamps + deleted_at | | | |

- **Unique:** `uuid`؛ `slug`.
- **Soft delete:** نعم. **Auditable:** نعم. **UUID:** نعم.
- **علاقات:** has-many products.

---

## 13. `units` — وحدات القياس
**الغرض:** وحدة بيع/تخزين المنتج (قطعة، كرتون، كجم...).

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| name | string(60) | R | — |
| name_en | string(60) | N | NULL |
| code | string(20) | R | — |
| symbol | string(20) | N | NULL |
| base_unit_id | BIGINT FK→units | N | NULL |
| conversion_factor | decimal(15,6) | R | `1.000000` |
| is_active | boolean | R | `true` |
| timestamps | | | |

- **FK:** `base_unit_id`→self (SET NULL).
- **Unique:** `code`.
- **Soft delete:** لا. **Auditable:** لا. **UUID:** لا (مرجعي).
- **علاقات:** self (تحويل الوحدات)؛ has-many products.

---

## 14. `currencies` — العملات
**الغرض:** تخزين العملات وأسعار الصرف للعرض/المستقبل؛ **الدفاتر بالعملة الأساسية** (ADR-001).

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| code | char(3) | R | — (ISO 4217) |
| name | string(80) | R | — |
| symbol | string(10) | N | NULL |
| exchange_rate | decimal(15,6) | R | `1.000000` |
| is_base | boolean | R | `false` |
| is_active | boolean | R | `true` |
| timestamps | | | |

- **Unique:** `code`.
- **Index:** `is_active`, `is_base`.
- **Soft delete:** لا. **Auditable:** نعم (تغيّر أسعار الصرف حسّاس). **UUID:** لا.
- **علاقات:** referenced-by branches, suppliers, products (عرض).
- **تحقّق:** عملة أساسية واحدة فقط (`is_base=true`) — يُفرض على مستوى الخدمة؛ العملة الأساسية الافتراضية `SAR`.

---

## 15. `taxes` — الضرائب
**الغرض:** تعريفات الضريبة؛ الحساب الفعلي في Phase 3 (ADR-015).

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| name | string(80) | R | — |
| code | string(30) | N | NULL |
| rate | decimal(8,4) | R | `15.0000` (%) |
| type | string(20) | R | `'exclusive'` (inclusive/exclusive) |
| is_default | boolean | R | `false` |
| is_active | boolean | R | `true` |
| timestamps | | | |

- **Unique:** `code` عند وجوده.
- **Soft delete:** لا. **Auditable:** نعم. **UUID:** لا.
- **علاقات:** has-many products.
- **تحقّق:** إن لم يُشِر المنتج إلى ضريبة → الافتراضي من الإعدادات (`tax.rate`, `tax.enabled`).

---

## 16. `products` — المنتجات
**الغرض:** الصنف الكتالوجي الأساسي؛ نوع `simple` (أسعار على المنتج) أو `variable` (أسعار على المتغيّرات).

| العمود | النوع | R/N | افتراضي | ملاحظة |
|--------|------|:---:|--------|--------|
| id | BIGINT PK | R | — | |
| uuid | char(36) | R | مُولّد | ADR-002 |
| branch_id | BIGINT FK→branches | N | NULL | |
| category_id | BIGINT FK→categories | R | — | |
| brand_id | BIGINT FK→brands | N | NULL | |
| unit_id | BIGINT FK→units | R | — | |
| tax_id | BIGINT FK→taxes | N | NULL | وإلا افتراضي الإعدادات |
| name | string(200) | R | — | |
| slug | string(220) | R | — | |
| sku | string(60) | R | — | فريد عام (جاهزية نطاق لاحقًا — ADR-004) |
| barcode | string(60) | N | NULL | |
| type | string(20) | R | `'simple'` | simple/variable |
| short_description | string(500) | N | NULL | |
| description | text | N | NULL | |
| cost_price | decimal(15,4) | R | `0.0000` | تكلفة آخر شراء (ADR-006) |
| average_cost | decimal(15,4) | R | `0.0000` | WAC — يُحتسب آليًا (ADR-005) |
| retail_price | decimal(15,2) | R | `0.00` | تجزئة |
| wholesale_price | decimal(15,2) | N | NULL | جملة |
| marketer_price | decimal(15,2) | N | NULL | أساس عمولة المسوّق |
| min_price | decimal(15,2) | N | NULL | أدنى بيع مسموح (حدّ صارم — ADR-006a) |
| promo_price | decimal(15,2) | N | NULL | ترويجي مؤقت |
| promo_starts_at | timestamp | N | NULL | |
| promo_ends_at | timestamp | N | NULL | |
| target_margin | decimal(8,4) | N | NULL | للتنبيه فقط لا يمنع (ADR-006) |
| weight | decimal(15,3) | N | NULL | |
| reorder_level | decimal(15,3) | N | NULL | حدّ إعادة الطلب (منتج simple) |
| track_inventory | boolean | R | `true` | |
| is_active | boolean | R | `true` | |
| timestamps + deleted_at | | | | |

- **FK:** `category_id`(RESTRICT)، `brand_id`(SET NULL)، `unit_id`(RESTRICT)، `tax_id`(SET NULL)، `branch_id`(SET NULL).
- **Unique:** `uuid`؛ `slug`؛ `sku`.
- **Index:** `category_id`, `brand_id`, `barcode`, (`is_active`,`type`).
- **Soft delete:** نعم. **Auditable:** نعم (الأسعار حسّاسة — ADR-020). **UUID:** نعم.
- **علاقات:** belongs-to category/brand/unit/tax; has-many product_variants, product_images; many-to-many product_tags، product_attributes.
- **تحقّق:** لمنتج `variable` تُحمل الأسعار/المخزون على `product_variants`؛ `min_price ≤ retail_price` (تنبيه)؛ رؤية `cost_price`/`average_cost`/`min_price` محكومة بصلاحية `pricing.view_cost` (ADR-013).

---

## 17. `product_variants` — متغيّرات المنتج
**الغرض:** التركيبة القابلة للبيع/التخزين (مقاس/لون...)؛ المخزون يُمسك لكل متغيّر × مستودع.

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| uuid | char(36) | R | مُولّد |
| product_id | BIGINT FK→products | R | — |
| sku | string(60) | R | — |
| barcode | string(60) | N | NULL |
| name | string(200) | N | NULL |
| cost_price | decimal(15,4) | R | `0.0000` |
| average_cost | decimal(15,4) | R | `0.0000` |
| retail_price | decimal(15,2) | R | `0.00` |
| wholesale_price | decimal(15,2) | N | NULL |
| marketer_price | decimal(15,2) | N | NULL |
| min_price | decimal(15,2) | N | NULL |
| promo_price | decimal(15,2) | N | NULL |
| weight | decimal(15,3) | N | NULL |
| reorder_level | decimal(15,3) | N | NULL |
| is_default | boolean | R | `false` |
| is_active | boolean | R | `true` |
| timestamps + deleted_at | | | |

- **FK:** `product_id`→products (CASCADE).
- **Unique:** `uuid`؛ `sku`؛ `barcode` عند وجوده.
- **Index:** `product_id`, (`product_id`,`is_default`).
- **Soft delete:** نعم. **Auditable:** نعم. **UUID:** نعم.
- **علاقات:** belongs-to product; has-many inventory_stocks, inventory_movements, inventory_ledger, stock_reservations; many-to-many product_attribute_values (عبر `variant_attribute_value`).
- **تحقّق:** نفس قواعد الأسعار في `products` (ADR-006/006a/013).

**جدول الربط (مساعد — خارج الـ27):** `variant_attribute_value`: `id, variant_id FK→product_variants (CASCADE), attribute_value_id FK→product_attribute_values (CASCADE)`, Unique(`variant_id`,`attribute_value_id`).

---

## 18. `product_attributes` — سمات المنتج
**الغرض:** محاور التنويع (مثل: اللون، المقاس) — تعريف السمة نفسها.

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| name | string(100) | R | — |
| slug | string(120) | R | — |
| type | string(20) | R | `'select'` (select/color/text/number) |
| sort_order | int | R | `0` |
| is_active | boolean | R | `true` |
| timestamps | | | |

- **Unique:** `slug`.
- **Soft delete:** لا. **Auditable:** لا. **UUID:** لا.
- **علاقات:** has-many product_attribute_values; many-to-many products (عبر `product_attribute_links`، اختياري لتحديد سمات المنتج).

---

## 19. `product_attribute_values` — قيم السمات
**الغرض:** القيم الممكنة لكل سمة (أحمر/أزرق، S/M/L).

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| attribute_id | BIGINT FK→product_attributes | R | — |
| value | string(120) | R | — |
| label | string(120) | N | NULL |
| color_hex | char(7) | N | NULL |
| sort_order | int | R | `0` |
| is_active | boolean | R | `true` |
| timestamps | | | |

- **FK:** `attribute_id`→product_attributes (CASCADE).
- **Unique:** (`attribute_id`,`value`).
- **Index:** `attribute_id`.
- **Soft delete:** لا. **Auditable:** لا. **UUID:** لا.
- **علاقات:** belongs-to attribute; many-to-many product_variants (عبر `variant_attribute_value`).

---

## 20. `product_images` — صور المنتج
**الغرض:** وسائط المنتج/المتغيّر.

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| product_id | BIGINT FK→products | R | — |
| variant_id | BIGINT FK→product_variants | N | NULL |
| path | string(255) | R | — |
| alt | string(200) | N | NULL |
| sort_order | int | R | `0` |
| is_primary | boolean | R | `false` |
| timestamps | | | |

- **FK:** `product_id`(CASCADE)، `variant_id`(CASCADE).
- **Index:** `product_id`, `variant_id`, (`product_id`,`is_primary`).
- **Soft delete:** لا. **Auditable:** لا. **UUID:** لا.
- **علاقات:** belongs-to product/variant.
- **تحقّق:** صورة أساسية واحدة لكل منتج (يُفرض على مستوى الخدمة).

---

## 21. `product_tags` — وسوم المنتج
**الغرض:** وسوم حرّة للتصنيف العرضي/البحث (تختلف عن السمة: الوسم تسمية تسويقية بلا محور تنويع).

| العمود | النوع | R/N | افتراضي |
|--------|------|:---:|--------|
| id | BIGINT PK | R | — |
| name | string(100) | R | — |
| slug | string(120) | R | — |
| is_active | boolean | R | `true` |
| timestamps | | | |

- **Unique:** `slug`.
- **Soft delete:** لا. **Auditable:** لا. **UUID:** لا.
- **علاقات:** many-to-many products (عبر `product_tag_links`).

**جدول الربط (مساعد — خارج الـ27):** `product_tag_links`: `id, product_id FK→products (CASCADE), product_tag_id FK→product_tags (CASCADE)`, Unique(`product_id`,`product_tag_id`).

---

# القسم د — المخزون (Inventory)

## 22. `inventory_stocks` — أرصدة المخزون (الدلاء)
**الغرض:** الرصيد الحيّ لكل (متغيّر × مستودع) مفكّكًا إلى دلاء (ADR-007). لا رصيد عام (المبدأ 2).

| العمود | النوع | R/N | افتراضي | ملاحظة |
|--------|------|:---:|--------|--------|
| id | BIGINT PK | R | — | |
| variant_id | BIGINT FK→product_variants | R | — | |
| warehouse_id | BIGINT FK→warehouses | R | — | |
| on_hand | decimal(15,3) | R | `0.000` | الموجود ماديًا |
| reserved | decimal(15,3) | R | `0.000` | محجوز لطلبات مؤكّدة |
| damaged | decimal(15,3) | R | `0.000` | تالف خارج البيع |
| returned_pending | decimal(15,3) | R | `0.000` | مرتجع بانتظار الفحص |
| in_transit | decimal(15,3) | R | `0.000` | قيد التحويل |
| average_cost | decimal(15,4) | R | `0.0000` | WAC الحالي (ADR-005) |
| cost_price | decimal(15,4) | R | `0.0000` | تكلفة آخر شراء |
| reorder_level | decimal(15,3) | N | NULL | حدّ إعادة الطلب لهذا المستودع |
| reorder_qty | decimal(15,3) | N | NULL | كمية إعادة الطلب المقترحة |
| last_movement_at | timestamp | N | NULL | |
| timestamps | | | | |

- **`available`** = `on_hand − reserved` — **محسوب، لا يُخزَّن** (ADR-007). الدلاء `damaged`/`returned_pending`/`in_transit` **لا** تُحتسب متاحة.
- **FK:** `variant_id`(CASCADE)، `warehouse_id`(RESTRICT).
- **Unique:** (`variant_id`,`warehouse_id`).
- **Index:** `warehouse_id`, (`warehouse_id`,`on_hand`).
- **Soft delete:** لا (رصيد حيّ يُحدَّث upsert). **Auditable:** نعم. **UUID:** لا.
- **علاقات:** belongs-to variant/warehouse.
- **تحقّق:** كل تعديل داخل `DB::transaction` مع `lockForUpdate` (المبدأ 7)؛ يُمنع `available < 0` إلا إذا `warehouses.allow_negative=true` (ADR-007a).

---

## 23. `inventory_ledger` — دفتر المخزون (append-only)
**الغرض:** دفتر ثابت لكل تغيّر في الكمية والقيمة مع رصيد جارٍ وWAC بعد كل حركة — للتتبّع والتدقيق والتقارير (ADR-008).

| العمود | النوع | R/N | افتراضي | ملاحظة |
|--------|------|:---:|--------|--------|
| id | BIGINT PK | R | — | |
| variant_id | BIGINT FK→product_variants | R | — | |
| warehouse_id | BIGINT FK→warehouses | R | — | |
| movement_id | BIGINT FK→inventory_movements | N | NULL | الحركة المولّدة |
| branch_id | BIGINT FK→branches | N | NULL | |
| movement_type | string(30) | R | — | مفردات ADR-008 |
| bucket | string(20) | R | `'on_hand'` | الدلو المتأثّر |
| reference_type | string(60) | N | NULL | مرجع polymorphic |
| reference_id | BIGINT | N | NULL | |
| qty_change | decimal(15,3) | R | — | مُوقّع (+/−) |
| balance_after | decimal(15,3) | R | — | رصيد الدلو الجاري |
| unit_cost | decimal(15,4) | N | NULL | تكلفة الوحدة للحركة |
| wac_after | decimal(15,4) | R | `0.0000` | WAC بعد الحركة |
| value_change | decimal(15,4) | R | `0.0000` | تغيّر القيمة |
| balance_value_after | decimal(15,4) | R | `0.0000` | قيمة المخزون الجارية |
| created_by | BIGINT FK→users | N | NULL | |
| created_at | timestamp | R | — | **لا `updated_at`** |

- **FK:** `variant_id`(RESTRICT)، `warehouse_id`(RESTRICT)، `movement_id`(SET NULL)، `branch_id`(SET NULL)، `created_by`→users(SET NULL).
- **Index:** (`variant_id`,`warehouse_id`,`created_at`)، `movement_id`، (`reference_type`,`reference_id`)، `movement_type`.
- **Soft delete:** **لا** (append-only — ADR-020). **Auditable:** لا (الدفتر ذاته هو التدقيق). **UUID:** لا.
- **تحقّق:** يُكتب **فقط** داخل نفس معاملة الحركة؛ **لا تعديل ولا حذف**؛ التصحيح بقيد عكسي جديد (ADR-016).

---

## 24. `inventory_movements` — حركات المخزون
**الغرض:** سجلّ الأحداث الخام (نيّة الحركة) الذي يولّد قيود الدفتر (ADR-008).

| العمود | النوع | R/N | افتراضي | ملاحظة |
|--------|------|:---:|--------|--------|
| id | BIGINT PK | R | — | |
| uuid | char(36) | R | مُولّد | |
| branch_id | BIGINT FK→branches | N | NULL | |
| variant_id | BIGINT FK→product_variants | R | — | |
| warehouse_id | BIGINT FK→warehouses | R | — | المستودع المصدر/المتأثّر |
| to_warehouse_id | BIGINT FK→warehouses | N | NULL | وجهة التحويل |
| type | string(30) | R | — | مفردات الحركة (أدناه) |
| bucket | string(20) | R | `'on_hand'` | الدلو المتأثّر |
| qty | decimal(15,3) | R | — | موجب دائمًا؛ الاتجاه من `type` |
| unit_cost | decimal(15,4) | N | NULL | |
| total_cost | decimal(15,4) | N | NULL | |
| reference_type | string(60) | N | NULL | polymorphic |
| reference_id | BIGINT | N | NULL | |
| reason | string(150) | N | NULL | |
| note | text | N | NULL | |
| created_by | BIGINT FK→users | N | NULL | |
| timestamps | | | | (لا `deleted_at`) |

**مفردات `type` القانونية (ADR-008 — لا اختراع خارجها):**
`purchase_in` · `sale_out` · `transfer_out` · `transfer_in` · `adjustment_in` · `adjustment_out` · `reserve` · `release` · `return_in` · `damage_out`.

- **FK:** `variant_id`(RESTRICT)، `warehouse_id`(RESTRICT)، `to_warehouse_id`(RESTRICT)، `branch_id`(SET NULL)، `created_by`(SET NULL).
- **Unique:** `uuid`.
- **Index:** (`variant_id`,`warehouse_id`)، `type`، (`reference_type`,`reference_id`)، `created_at`.
- **Soft delete:** **لا** (سجلّ ثابت — المبدأ 5 / ADR-020). **Auditable:** لا. **UUID:** نعم.
- **علاقات:** belongs-to variant/warehouse; has-many inventory_ledger.
- **تحقّق:** كل حركة داخل معاملة ذرّية تُحدّث `inventory_stocks` وتكتب قيد/قيود دفتر؛ التصحيح بحركة عكسية لا حذف.

---

## 25. `stock_reservations` — حجوزات المخزون
**الغرض:** حجز كمية من دلو `reserved` لطلب مؤكّد (بنية Phase 2، تُستهلك في Phase 3 — ADR-009).

| العمود | النوع | R/N | افتراضي | ملاحظة |
|--------|------|:---:|--------|--------|
| id | BIGINT PK | R | — | |
| uuid | char(36) | R | مُولّد | |
| variant_id | BIGINT FK→product_variants | R | — | |
| warehouse_id | BIGINT FK→warehouses | R | — | |
| order_id | BIGINT | N | NULL | **اعتمادية مؤجّلة (Phase 3)** — بلا FK الآن |
| order_item_id | BIGINT | N | NULL | مؤجّل (Phase 3) |
| reference_type | string(60) | N | NULL | polymorphic (بديل عام للحجز غير الطلبي) |
| reference_id | BIGINT | N | NULL | |
| qty | decimal(15,3) | R | — | الكمية المحجوزة |
| status | string(20) | R | `'active'` | active/released/consumed/expired |
| reserved_at | timestamp | R | — | |
| expires_at | timestamp | N | NULL | |
| released_at | timestamp | N | NULL | |
| created_by | BIGINT FK→users | N | NULL | |
| timestamps | | | | |

- **FK:** `variant_id`(RESTRICT)، `warehouse_id`(RESTRICT)، `created_by`(SET NULL). **`order_id`/`order_item_id` بلا FK الآن** — يُضاف قيدهما في Phase 3 عند وجود `orders` (اعتمادية أمامية موثّقة).
- **Unique:** `uuid`.
- **Index:** (`variant_id`,`warehouse_id`,`status`)، `status`، `order_id`، `expires_at`.
- **Soft delete:** لا (دورة حياة عبر `status`). **Auditable:** نعم. **UUID:** نعم.
- **علاقات:** belongs-to variant/warehouse; (Phase 3) belongs-to order/order_item.
- **تحقّق:** إنشاء الحجز يزيد `inventory_stocks.reserved` ويكتب حركة `reserve` + قيد دفتر؛ التحرير يكتب `release`؛ الاستهلاك (الشحن) يخصم نهائيًا (ADR-009). الحجز قابل للإعداد `inventory.reserve_on` (الافتراضي `confirmed`).

---

## 26. `stock_adjustments` — تسويات الجرد (رأس)
**الغرض:** رأس مستند تسوية مخزون (جرد/تلف/فروقات) بترقيم `ADJ-{YYYY}-{seq}` (ADR-002).

| العمود | النوع | R/N | افتراضي | ملاحظة |
|--------|------|:---:|--------|--------|
| id | BIGINT PK | R | — | |
| uuid | char(36) | R | مُولّد | |
| number | string(30) | R | — | `ADJ-{YYYY}-{seq}` مُولّد في معاملة مع قفل |
| branch_id | BIGINT FK→branches | N | NULL | |
| warehouse_id | BIGINT FK→warehouses | R | — | |
| type | string(20) | R | `'recount'` | increase/decrease/recount |
| reason | string(150) | N | NULL | |
| status | string(25) | R | `'draft'` | (انظر ملاحظة الحالات) |
| notes | text | N | NULL | |
| created_by | BIGINT FK→users | N | NULL | |
| approved_by | BIGINT FK→users | N | NULL | |
| approved_at | timestamp | N | NULL | |
| posted_at | timestamp | N | NULL | لحظة تطبيق الأثر على المخزون |
| timestamps + deleted_at | | | | |

- **FK:** `warehouse_id`(RESTRICT)، `branch_id`(SET NULL)، `created_by`/`approved_by`→users(SET NULL).
- **Unique:** `uuid`؛ (`number`) — أو (`number`,`year`) للفرادة لكل نوع×سنة.
- **Index:** `warehouse_id`, `status`, `number`.
- **Soft delete:** نعم (الرأس). **Auditable:** نعم (ADR-020). **UUID:** نعم.
- **علاقات:** belongs-to warehouse; has-many stock_adjustment_items.
- **ملاحظة حالات:** مفاتيح رأس التسوية غير مُعرّفة صراحةً في المفردات القانونية → **سؤال مفتوح** (انظر النهاية). المقترح المؤقّت: `draft → pending_approval → approved → posted` + `cancelled`. عند `posted` تُكتب حركات `adjustment_in`/`adjustment_out` + قيود دفتر.

**جدول البنود (مساعد — خارج الـ27):** `stock_adjustment_items`:
`id, stock_adjustment_id FK→stock_adjustments (CASCADE), variant_id FK→product_variants (RESTRICT), qty_before decimal(15,3), qty_counted decimal(15,3), qty_diff decimal(15,3), unit_cost decimal(15,4), note`. Index (`stock_adjustment_id`,`variant_id`).

---

## 27. `warehouse_transfers` — التحويلات بين المستودعات (رأس)
**الغرض:** رأس مستند نقل مخزون بين مستودعين بترقيم `TRF-{YYYY}-{seq}` (ADR-002، المبدأ 2).

| العمود | النوع | R/N | افتراضي | ملاحظة |
|--------|------|:---:|--------|--------|
| id | BIGINT PK | R | — | |
| uuid | char(36) | R | مُولّد | |
| number | string(30) | R | — | `TRF-{YYYY}-{seq}` مُولّد في معاملة مع قفل |
| branch_id | BIGINT FK→branches | N | NULL | |
| from_warehouse_id | BIGINT FK→warehouses | R | — | المصدر |
| to_warehouse_id | BIGINT FK→warehouses | R | — | الوجهة |
| status | string(25) | R | `'draft'` | (انظر ملاحظة الحالات) |
| notes | text | N | NULL | |
| created_by | BIGINT FK→users | N | NULL | |
| approved_by | BIGINT FK→users | N | NULL | |
| shipped_at | timestamp | N | NULL | |
| received_at | timestamp | N | NULL | |
| timestamps + deleted_at | | | | |

- **FK:** `from_warehouse_id`/`to_warehouse_id`→warehouses(RESTRICT)، `branch_id`(SET NULL)، `created_by`/`approved_by`→users(SET NULL).
- **Unique:** `uuid`؛ `number`.
- **Index:** `from_warehouse_id`, `to_warehouse_id`, `status`, `number`.
- **Soft delete:** نعم (الرأس). **Auditable:** نعم (ADR-020). **UUID:** نعم.
- **علاقات:** belongs-to from/to warehouse; has-many warehouse_transfer_items.
- **تحقّق:** `from_warehouse_id ≠ to_warehouse_id`. عند الشحن تُكتب `transfer_out` وتزيد `in_transit` بالوجهة؛ عند الاستلام تُكتب `transfer_in` وتنقل من `in_transit` إلى `on_hand` (ADR-007/008). التكلفة تُنقل بـ WAC المصدر.
- **ملاحظة حالات:** مفاتيح رأس التحويل غير مُعرّفة صراحةً في المفردات القانونية → **سؤال مفتوح**. المقترح المؤقّت: `draft → pending_approval → approved → in_transit → partially_received → received` + `cancelled`.

**جدول البنود (مساعد — خارج الـ27):** `warehouse_transfer_items`:
`id, warehouse_transfer_id FK→warehouse_transfers (CASCADE), variant_id FK→product_variants (RESTRICT), qty_requested decimal(15,3), qty_shipped decimal(15,3) default 0, qty_received decimal(15,3) default 0, unit_cost decimal(15,4)`. Index (`warehouse_transfer_id`,`variant_id`).

---

# حدود Phase 2 والاعتماديات المؤجلة

المرحلة 2 تُصمَّم بحيث ترتبط بها وحدات Phase 3+ **دون إعادة تصميم**، عبر ثلاث آليات:

| الوحدة اللاحقة | نقطة الارتباط في Phase 2 | كيف تُوصَل لاحقًا دون كسر |
|----------------|--------------------------|--------------------------|
| **الطلبات (Phase 3)** | `stock_reservations.order_id` / `order_item_id` مؤجّلة بلا FK | تُضاف قيود FK في Phase 3 عند إنشاء `orders`؛ العمود موجود مسبقًا |
| **خصم/إرجاع المخزون (Phase 3)** | `inventory_movements`/`inventory_ledger` عبر `reference_type`+`reference_id` polymorphic | الطلب/الشحنة/المرتجع يُشيرون كمرجع عام دون تعديل بنية الدفتر |
| **أوامر الشراء والاستلام (Phase 4)** | `suppliers` جاهز؛ الاستلام يُنتج `purchase_in` بمرجع polymorphic | جدول `purchase_orders` يشير إلى `suppliers`/`warehouses` القائمة |
| **فواتير المورد (Phase 4)** | `suppliers` + مراجع polymorphic | تُضاف جداول جديدة تشير للقائم |
| **المحاسبة مزدوجة القيد (Phase 5)** | كل حركة مخزون/تكلفة حدث مُصمَّم لينتج قيدًا (ADR-016) | مستمعو أحداث (EVENTS) يقرؤون الدفتر ويولّدون قيودًا؛ لا تعديل جداول Phase 2 |
| **العمولات (Phase 6)** | `products/variants.marketer_price` + `min_price` موجودة | قواعد العمولة تقرأ الأسعار القائمة (ADR-012) |
| **CRM/العناوين (Phase 3)** | `governorates/cities/areas/shipping_zones` جاهزة | عنوان العميل يشير إلى `area_id`؛ الشحن يُشتق من `shipping_zone` |

**مبادئ عدم الكسر:**
1. **مراجع polymorphic** (`reference_type`+`reference_id`) في الدفتر/الحركات/الحجوزات — تستوعب أي مصدر مستقبلي.
2. **أعمدة مؤجّلة nullable بلا FK** (`order_id`) — تُفعَّل بـ migration واحدة لاحقًا.
3. **فرادة مركّبة لا عامة** (SKU/code) — تسمح بإضافة `tenant_id`/نطاق فرع لاحقًا (ADR-004).
4. **العملة الأساسية في الدفاتر** — دعم متعدد العملات يُضاف كطبقة عرض دون تغيير القيم المخزّنة (ADR-001).

---

# ملخّص العلاقات (ERD نصّي — 27 كيانًا)

```
branches ──1─< branch_settings
branches ──1─< warehouses ──1─< warehouse_locations (self-parent شجري)
branches ──1─< suppliers ──1─< supplier_contacts
branches ──1─< products / shipping_zones / inventory_movements

governorates ──1─< cities ──1─< areas
shipping_zones >──M:N── cities        (shipping_zone_city)
shipping_zones >──M:N── areas         (shipping_zone_area)
warehouses ──> governorates / cities

categories (self-parent شجري) ──1─< products
brands ──1─< products
units (self base_unit) ──1─< products
taxes ──1─< products
currencies ──> branches / suppliers / products (عرض)

products ──1─< product_variants
products ──1─< product_images >── product_variants
products >──M:N── product_tags       (product_tag_links)
products >──M:N── product_attributes (اختياري)
product_attributes ──1─< product_attribute_values
product_variants >──M:N── product_attribute_values (variant_attribute_value)

product_variants ──1─< inventory_stocks >── warehouses   (Unique variant+warehouse)
product_variants ──1─< inventory_movements >── warehouses ──1─< inventory_ledger
product_variants ──1─< stock_reservations >── warehouses
                        stock_reservations ┄┄> orders (Phase 3، مؤجّل)

warehouses ──1─< stock_adjustments ──1─< stock_adjustment_items >── product_variants
warehouses ──1─< warehouse_transfers (from/to) ──1─< warehouse_transfer_items >── product_variants

inventory_movements ──1─< inventory_ledger   (كل حركة → قيد/أكثر، append-only)
```

---

# ترتيب الهجرات المقترح (آمن للاعتماديات)

> `branches`, `settings`, `audit_logs`, `users`, `*_statuses` قائمة من المرحلة 1. الترتيب أدناه للمرحلة 2.

1. `currencies`
2. `governorates`
3. `cities` (→ governorates)
4. `areas` (→ cities)
5. **امتداد** `branches` (إضافة أعمدة email/tax_number/default_currency_id/timezone — دون `default_warehouse_id`)
6. `branch_settings` (→ branches)
7. `warehouses` (→ branches, governorates, cities)
8. **امتداد** `branches` (إضافة `default_warehouse_id` → warehouses) — بعد إنشاء المستودعات
9. `warehouse_locations` (→ warehouses, self)
10. `shipping_zones` (→ branches) + `shipping_zone_city` + `shipping_zone_area`
11. `suppliers` (→ branches, governorates, cities, currencies)
12. `supplier_contacts` (→ suppliers)
13. `units` (self base_unit)
14. `taxes`
15. `categories` (self)
16. `brands`
17. `products` (→ categories, brands, units, taxes, branches)
18. `product_variants` (→ products)
19. `product_attributes`
20. `product_attribute_values` (→ product_attributes)
21. `variant_attribute_value` (→ product_variants, product_attribute_values)
22. `product_images` (→ products, product_variants)
23. `product_tags` + `product_tag_links` (→ products)
24. `inventory_stocks` (→ product_variants, warehouses)
25. `inventory_movements` (→ product_variants, warehouses, branches, users)
26. `inventory_ledger` (→ product_variants, warehouses, inventory_movements, branches, users)
27. `stock_reservations` (→ product_variants, warehouses, users؛ `order_id` مؤجّل بلا FK)
28. `stock_adjustments` (→ warehouses, branches, users) + `stock_adjustment_items`
29. `warehouse_transfers` (→ warehouses, branches, users) + `warehouse_transfer_items`

---

# الأسئلة المفتوحة المُضافة (خاصة بهذا التصميم)

بالإضافة إلى الأسئلة المفتوحة الستة في `DECISIONS.md`:

- **OQ-P2-1 — حالات رأس التحويل (`warehouse_transfers.status`):** غير مُعرّفة في المفردات القانونية. المقترح: `draft → pending_approval → approved → in_transit → partially_received → received` + `cancelled`. يلزم اعتمادها في `DECISIONS.md` (ADR-017) وزرعها كجدول حالات قابل للإدارة أو تثبيتها كـ keys.
- **OQ-P2-2 — حالات رأس التسوية (`stock_adjustments.status`):** غير مُعرّفة. المقترح: `draft → pending_approval → approved → posted` + `cancelled`.
- **OQ-P2-3 — حالات الحجز (`stock_reservations.status`):** المقترح `active/released/consumed/expired` — يلزم إدراجها ضمن المفردات القانونية لاتساق Phase 3.
- **OQ-P2-4 — نطاق WAC:** `inventory_stocks.average_cost` مُصمَّم لكل (متغيّر×مستودع). إن اعتُمد النطاق «على مستوى الشركة» (السؤال 1 في DECISIONS) يلزم جدول تجميع WAC إضافي — يُؤجَّل حتى الحسم.
- **OQ-P2-5 — فرادة الترقيم:** هل `number` فريد عام أم فريد لكل (نوع×سنة)؟ المقترح فرادة مركّبة تحسّبًا لتعدّد الشركات (ADR-004).

</div>
