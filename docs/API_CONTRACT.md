<div dir="rtl">

# عقد الـ API — Tawfeer Online (`/api/v1`)

هذا المستند يعرّف **عقد REST API** للمشروع: القواعد الموحّدة (Conventions) ونقاط النهاية (Endpoints) لكيانات **المرحلة 2 (27 كيانًا)**. هو ملزِم لكل الوحدات ويتوافق مع [`ARCHITECTURE.md`](../ARCHITECTURE.md) (المبادئ 14) و[`DECISIONS.md`](./DECISIONS.md) (المصدر الوحيد للحقيقة).

> **مبادئ حاكمة:** API-First عبر Sanctum + Resources (المبدأ 11، ADR-019) · المعرّفات الخارجية `uuid` لا `id` (المبدأ 4، ADR-002) · الصلاحيات بالأدوار (المبدأ 12) · حجب التكلفة بلا `pricing.view_cost` (ADR-013).
>
> **الحالة:** مسودة اعتماد للمرحلة 2. المسارات الحالية `GET /api/v1/health` و`GET /api/v1/me` (في [`routes/api.php`](../routes/api.php)) هي المرجع الأسلوبي.

---

## 1. القواعد العامة (Conventions)

### 1.1 القاعدة والإصدار (Base & Versioning)
- كل المسارات تحت **`/api/v1`**. الإصدار في المسار (URI Versioning).
- سياسة الإصدار: تغيير **كاسر (breaking)** ⇒ إصدار جديد `/api/v2` مع فترة إهمال (Deprecation) مُعلنة عبر ترويسة `Deprecation` و`Sunset`. الإضافات غير الكاسرة (حقول/نقاط جديدة) تبقى في `v1`.
- كل الطلبات والاستجابات `application/json; charset=utf-8`.

### 1.2 المصادقة (Authentication — Sanctum)
- توكن حامل (Bearer) في الترويسة: `Authorization: Bearer {token}`.
- إصدار التوكن: `POST /api/v1/auth/login` (بريد/هاتف + كلمة مرور) ⇒ يعيد التوكن وبيانات المستخدم. الإبطال: `POST /api/v1/auth/logout`. المستخدم الحالي: `GET /api/v1/me` (قائم).
- التوكنات تحمل **قدرات (abilities)** تُشتق من صلاحيات المستخدم (spatie) — الطبقة النهائية للتحقّق هي Policies (المبدأ 12).
- بلا توكن على مسار محمي ⇒ `401 Unauthenticated`. توكن صالح بلا صلاحية ⇒ `403 Forbidden`.

### 1.3 غلاف الاستجابة الموحّد (Response Envelope)
كل استجابة ناجحة تتبع الشكل التالي (تُنتَج عبر API Resources):

```json
{
  "data": { },
  "meta": { },
  "links": { }
}
```

- **مورد مفرد:** `data` كائن. **قائمة:** `data` مصفوفة + `meta` (ترقيم) + `links` (تنقّل).
- الحقول الزمنية بصيغة **ISO-8601 UTC** (`2026-07-11T09:30:00Z`).
- المعرّف المكشوف دائمًا `id` **بقيمة uuid** (كما في `UserResource`: `'id' => $this->uuid`)؛ لا يُكشف المفتاح الداخلي BIGINT أبدًا (المبدأ 4).

### 1.4 الترقيم (Pagination)
- ترقيم بالصفحات: `?page={n}&per_page={m}` (افتراضي `per_page=20`، أقصى `100`).

```json
{
  "data": [ ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 137,
    "last_page": 7
  },
  "links": {
    "first": "/api/v1/products?page=1",
    "prev": null,
    "next": "/api/v1/products?page=2",
    "last": "/api/v1/products?page=7"
  }
}
```

### 1.5 التصفية والفرز والبحث (Filtering / Sorting / Search)
| النمط | المثال | المعنى |
|------|--------|--------|
| تصفية | `?filter[is_active]=true` | حقل = قيمة |
| تصفية بمعامل | `?filter[price][gte]=100` | معاملات: `eq,ne,gte,lte,gt,lt,in` |
| علاقة | `?filter[category_id]={uuid}` | تصفية بـ uuid مرجعي |
| فرز | `?sort=-created_at,name` | `-` تنازلي، متعدد بفاصلة |
| بحث حر | `?search=هاتف` | بحث نصّي عبر حقول مُعرَّفة للمورد |
| تضمين | `?include=variants,images` | تحميل علاقات مسموحة (Eager) |
| اختيار حقول | `?fields=id,name,retail_price` | تقليص الحقول (Sparse) |
| المحذوف ناعمًا | `?with_trashed=true` | يتطلب صلاحية + دعم المورد (المبدأ 5) |

- القيم المرجعية في مُعاملات التصفية بالـ `uuid` لا `id` (ADR-002).

### 1.6 صيغة الأخطاء ورموز الحالة (Errors & HTTP Status)
شكل خطأ موحّد:

```json
{
  "message": "بيانات غير صالحة",
  "errors": {
    "sku": ["حقل SKU مستخدم مسبقًا."],
    "retail_price": ["يجب أن يكون رقمًا موجبًا."]
  },
  "error_code": "VALIDATION_FAILED"
}
```

| الرمز | المعنى | متى |
|:----:|--------|-----|
| 200 | OK | قراءة/تحديث ناجح |
| 201 | Created | إنشاء ناجح (`store`) |
| 202 | Accepted | عملية غير متزامنة (طابور) قُبلت |
| 204 | No Content | حذف ناجح (`destroy`) |
| 400 | Bad Request | طلب مشوّه |
| 401 | Unauthenticated | بلا توكن/توكن منتهٍ |
| 403 | Forbidden | بلا صلاحية (Policy/المبدأ 12) |
| 404 | Not Found | uuid غير موجود |
| 409 | Conflict | تعارض حالة (مثل خصم يتجاوز المتاح، ADR-007a) |
| 422 | Unprocessable Entity | فشل تحقّق (Validation) |
| 423 | Locked | صف مخزون مقفول أثناء معاملة (المبدأ 7) |
| 429 | Too Many Requests | تجاوز حدّ المعدّل |
| 500 | Server Error | خطأ داخلي |

- `error_code` مفتاح ثابت للآلة (`VALIDATION_FAILED`, `FORBIDDEN`, `NOT_FOUND`, `INSUFFICIENT_STOCK`, `MIN_PRICE_VIOLATION`, `IDEMPOTENCY_CONFLICT`, `RATE_LIMITED`).

### 1.7 حدّ المعدّل (Rate Limiting)
- افتراضي: **60 طلب/دقيقة** للمستخدم المُصادَق، **20/دقيقة** للمسارات العامة (`/health`, `/auth/login`).
- ترويسات: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` (عند 429).

### 1.8 التوطين (Localization — Accept-Language)
- ترويسة `Accept-Language: ar` (افتراضي) أو `en`. تؤثّر في رسائل الأخطاء والحقول القابلة للترجمة (أسماء الفئات/الحالات — المبدأ 10، ADR-017).
- الحقول المُترجمة تُعاد باللغة المطلوبة؛ عند غيابها ترجّع اللغة الافتراضية (fallback).

### 1.9 الطلبات المتكرّرة (Idempotency للكتابة)
- عمليات **الكتابة الحسّاسة** (إنشاء حركة مخزون، تحويل، تسوية، لاحقًا الطلبات/الدفع) تقبل ترويسة `Idempotency-Key: {uuid}`.
- المفتاح يُخزَّن مع بصمة الطلب؛ تكرار نفس المفتاح ⇒ يعيد **نفس الاستجابة الأصلية** دون تنفيذ مزدوج. تعارض بصمة مختلفة لنفس المفتاح ⇒ `409 IDEMPOTENCY_CONFLICT`.
- كل عملية مالية/مخزونية داخل `DB::transaction()` مع قفل الصف (المبدأ 7، ADR-007a).

---

## 2. الصلاحيات ورؤية التكلفة (RBAC & Cost Visibility)

### 2.1 بوّابة الصلاحيات (المبدأ 12)
- كل نقطة نهاية محميّة بصلاحية مُسمّاة (Policy + spatie). الجدول في كل قسم يذكر الصلاحية المطلوبة.
- تسمية الصلاحيات: `{module}.{action}` — مثال: `catalog.products.view`, `catalog.products.create`, `inventory.movements.create`, `purchasing.suppliers.update`.
- **يُمنع** أي تحقّق بالاسم/البريد (المبدأ 12): لا `if email === ...`.

### 2.2 حجب حقول التكلفة (ADR-013)
- الحقول `cost_price`, `average_cost`, `min_price`, `target_margin`, وأي حقل ربحية **تُحذف من الـ Resource** ما لم يملك المُنادي صلاحية `pricing.view_cost`.
- السلوك: الحذف الصامت (الحقل غير موجود في `data`) لا الإرجاع بـ `null` — كي لا يُستنتج وجود قيمة.

```json
// مورد متغيّر لمستخدم يملك pricing.view_cost
{ "data": { "id": "9b1c...", "sku": "TSHIRT-RED-M",
  "retail_price": "79.00", "wholesale_price": "65.00",
  "average_cost": "41.2500", "cost_price": "40.0000", "min_price": "55.00" } }
```
```json
// نفس المتغيّر لمستخدم مبيعات بلا pricing.view_cost — حقول التكلفة محذوفة
{ "data": { "id": "9b1c...", "sku": "TSHIRT-RED-M",
  "retail_price": "79.00", "wholesale_price": "65.00" } }
```

---

## 3. نقاط نهاية المرحلة 2 (Phase-2 Endpoints)

اصطلاحات الجداول: العمود **CRUD** يوضّح `R` = قراءة فقط في المرحلة 2، `RW` = كتابة كاملة (index/show/store/update/destroy). الحذف `destroy` = Soft Delete (المبدأ 5) ما لم يُذكر append-only.

> **حالة التنفيذ:** ✅ مُنفَّذ ومُختبَر: الفروع والمستودعات والمواقع (2.1)؛ الكتالوج المرجعي (2.2)؛ المنتجات ووسائطها (2.3)؛ **المخزون (2.4)** — `/api/v1/inventory/*` (stocks / movements / ledger / receive / issue / transfer / reservations / adjustments) بمحرّك WAC ومنع السالب داخل معاملات، وحجب حقول التكلفة بـ `pricing.view_cost` (ADR-005/007/008/013/024)؛ **المشتريات (2.5)** — `/api/v1/purchasing/*` (`suppliers` CRUD؛ `orders` CRUD + submit/approve/cancel/close؛ `receipts` index/show/store/post؛ `returns` index/show/store/approve/post) مع مرور الاستلام/المرتجع حصريًا عبر محرّك المخزون وتوزيع التكلفة المحمّلة (ADR-025)؛ **المبيعات (2.6)** — `/api/v1/sales/orders` (CRUD + confirm/reserve/prepare/ready/ship/deliver/cancel) بآلة حالات ADR-010، والحجز/الاستهلاك/التحرير حصريًا عبر محرّك المخزون بتوقيت ADR-009، وتجميد سعر البند (ADR-026)؛ **الشحن (2.7)** — `/api/v1/geo/*` (قراءة الجغرافيا) و`/api/v1/shipping/shipments` (CRUD + dispatch/out-for-delivery/delay/customer-unavailable/deliver/fail/override-cost) بآلة حالات تُكمل جزء التوصيل وتُزامن الطلب، وتعيين مزوّدين متعدد، ولقطة تكلفة، وطبقة تكامل توصيل بعقود + Null Drivers (ADR-027)؛ **المدفوعات (2.8)** — `/api/v1/payments/*` (methods + CRUD + capture/refund/callback) بطبقة تكامل مزوّدين (عقد موحّد `PaymentProviderInterface` + مدير مزوّدين، COD/تحويل فعّالان)، وبنية معاملات عامة، وحالة دفع الطلب المشتقّة (ADR-028)؛ **المحاسبة (2.9)** — `/api/v1/accounting/*` (accounts + balance، journal-entries CRUD + post/reverse، reports/trial-balance) بمحرّك قيد مزدوج، قيود ودفتر غير قابلة للتعديل (عكس لا حذف)، أرصدة مشتقّة من الدفتر، وعزل عبر AccountingService والأحداث (ADR-029). البقية مُصمَّمة وتُنفَّذ في مراحلها.
> **ملاحظة صلاحيات (ADR-021):** الكتالوج يستخدم `catalog.{resource}.{action}` (مثل `catalog.categories.create`). قيم السمات محكومة بصلاحيات `catalog.attributes.*` كمورد فرعي.

### 3.1 الفروع والإعدادات (Branches & Branch Settings) — ADR-003
| Method | Path | Purpose | Permission | CRUD |
|:------:|------|---------|------------|:----:|
| GET | `/branches` | قائمة الفروع | `settings.branches.view` | R |
| GET | `/branches/{uuid}` | تفاصيل فرع | `settings.branches.view` | R |
| POST | `/branches` | إنشاء فرع | `settings.branches.create` | RW |
| PUT/PATCH | `/branches/{uuid}` | تعديل فرع | `settings.branches.update` | RW |
| DELETE | `/branches/{uuid}` | حذف ناعم | `settings.branches.delete` | RW |
| GET | `/branches/{uuid}/settings` | إعدادات الفرع | `settings.branches.view` | R |
| PUT | `/branches/{uuid}/settings` | تحديث إعدادات الفرع | `settings.branches.update` | RW |

```json
// GET /branches/{uuid}
{ "data": { "id": "b7e2...", "code": "MAIN", "name": "الفرع الرئيسي",
  "is_active": true, "is_default": true,
  "settings": { "default_currency": "SAR", "allow_negative_stock": false },
  "created_at": "2026-07-01T08:00:00Z" } }
```

### 3.2 الجغرافيا (Geography) — ADR-014 — **قراءة فقط في المرحلة 2**
التسلسل: governorate → city → area؛ و`shipping_zone` تجميعة تسعير مستقلة (كثير-لكثير).

| Method | Path | Purpose | Permission | CRUD |
|:------:|------|---------|------------|:----:|
| GET | `/geo/governorates` | قائمة المحافظات | `settings.geography.view` | R |
| GET | `/geo/governorates/{uuid}` | محافظة + مدنها | `settings.geography.view` | R |
| GET | `/geo/cities` | قائمة المدن (`?filter[governorate_id]=`) | `settings.geography.view` | R |
| GET | `/geo/cities/{uuid}` | مدينة + مناطقها | `settings.geography.view` | R |
| GET | `/geo/areas` | قائمة المناطق (`?filter[city_id]=`) | `settings.geography.view` | R |
| GET | `/geo/shipping-zones` | مناطق الشحن | `settings.geography.view` | R |
| GET | `/geo/shipping-zones/{uuid}` | منطقة شحن + تغطيتها | `settings.geography.view` | R |

> الكتابة الكاملة (إدارة الجغرافيا ومناطق الشحن) تُفعَّل مع تسعير الشحن في **المرحلة 3**. البذور تُزرع إداريًا الآن.

```json
// GET /geo/cities/{uuid}
{ "data": { "id": "c1a4...", "name": "الرياض", "name_en": "Riyadh",
  "governorate": { "id": "g55f...", "name": "منطقة الرياض" },
  "is_active": true, "areas_count": 42 } }
```

### 3.3 المستودعات والمواقع (Warehouses & Locations) — المبدأ 2، ADR-003
| Method | Path | Purpose | Permission | CRUD |
|:------:|------|---------|------------|:----:|
| GET | `/warehouses` | قائمة المستودعات | `settings.warehouses.view` | R |
| GET | `/warehouses/{uuid}` | تفاصيل مستودع | `settings.warehouses.view` | R |
| POST | `/warehouses` | إنشاء مستودع | `settings.warehouses.create` | RW |
| PUT/PATCH | `/warehouses/{uuid}` | تعديل | `settings.warehouses.update` | RW |
| DELETE | `/warehouses/{uuid}` | حذف ناعم | `settings.warehouses.delete` | RW |
| GET | `/warehouses/{uuid}/locations` | مواقع داخل مستودع | `settings.warehouses.view` | R |
| POST | `/warehouses/{uuid}/locations` | إضافة موقع (رفّ/ممر) | `settings.warehouses.update` | RW |
| PUT/PATCH | `/warehouse-locations/{uuid}` | تعديل موقع | `settings.warehouses.update` | RW |
| DELETE | `/warehouse-locations/{uuid}` | حذف موقع | `settings.warehouses.update` | RW |

```json
// POST /warehouses
{ "branch_id": "b7e2...", "code": "WH-RUH-01", "name": "مستودع الرياض",
  "allow_negative": false, "is_active": true }
```
```json
// 201 Created
{ "data": { "id": "w9d0...", "code": "WH-RUH-01", "name": "مستودع الرياض",
  "branch": { "id": "b7e2...", "name": "الفرع الرئيسي" },
  "allow_negative": false, "is_active": true } }
```

### 3.4 الموردون وجهات الاتصال (Suppliers & Contacts)
| Method | Path | Purpose | Permission | CRUD |
|:------:|------|---------|------------|:----:|
| GET | `/suppliers` | قائمة الموردين | `purchasing.suppliers.view` | R |
| GET | `/suppliers/{uuid}` | تفاصيل مورد | `purchasing.suppliers.view` | R |
| POST | `/suppliers` | إنشاء مورد | `purchasing.suppliers.create` | RW |
| PUT/PATCH | `/suppliers/{uuid}` | تعديل | `purchasing.suppliers.update` | RW |
| DELETE | `/suppliers/{uuid}` | حذف ناعم | `purchasing.suppliers.delete` | RW |
| GET | `/suppliers/{uuid}/contacts` | جهات اتصال المورد | `purchasing.suppliers.view` | R |
| POST | `/suppliers/{uuid}/contacts` | إضافة جهة اتصال | `purchasing.suppliers.update` | RW |
| PUT/PATCH | `/supplier-contacts/{uuid}` | تعديل جهة | `purchasing.suppliers.update` | RW |
| DELETE | `/supplier-contacts/{uuid}` | حذف جهة | `purchasing.suppliers.update` | RW |

```json
// GET /suppliers/{uuid}
{ "data": { "id": "s3f1...", "code": "SUP-000012", "name": "مؤسسة الإمداد",
  "tax_number": "3001...", "currency": "SAR", "is_active": true,
  "contacts": [ { "id": "sc10...", "name": "أحمد", "phone": "+9665...", "role": "purchasing" } ] } }
```

### 3.5 الكتالوج المرجعي (Categories / Brands / Units / Currencies / Taxes)
| Method | Path | Purpose | Permission | CRUD |
|:------:|------|---------|------------|:----:|
| GET | `/categories` | قائمة/شجرة الفئات | `catalog.categories.view` | R |
| GET | `/categories/{uuid}` | تفاصيل فئة | `catalog.categories.view` | R |
| POST/PUT/DELETE | `/categories`, `/categories/{uuid}` | إدارة الفئات | `catalog.categories.{create\|update\|delete}` | RW |
| GET | `/brands` (+`{uuid}`) | العلامات التجارية | `catalog.brands.view` | R |
| POST/PUT/DELETE | `/brands`... | إدارة العلامات | `catalog.brands.{create\|update\|delete}` | RW |
| GET | `/units` (+`{uuid}`) | وحدات القياس | `catalog.units.view` | R |
| POST/PUT/DELETE | `/units`... | إدارة الوحدات | `catalog.units.{create\|update\|delete}` | RW |
| GET | `/currencies` (+`{uuid}`) | العملات وأسعار الصرف | `settings.currencies.view` | R |
| POST/PUT/DELETE | `/currencies`... | إدارة العملات | `settings.currencies.{create\|update\|delete}` | RW |
| GET | `/taxes` (+`{uuid}`) | الضرائب | `settings.taxes.view` | R |
| POST/PUT/DELETE | `/taxes`... | إدارة الضرائب | `settings.taxes.{create\|update\|delete}` | RW |

- **العملات (ADR-001):** الدفاتر بالعملة الأساسية الواحدة؛ `currencies` للعرض/المستقبل.
- **الضرائب (ADR-015):** الحساب الفعلي في المرحلة 3؛ الإدارة متاحة الآن.

```json
// GET /categories?include=children  (شجرة)
{ "data": [ { "id": "cat1...", "name": "إلكترونيات", "parent_id": null, "sort": 1,
  "children": [ { "id": "cat2...", "name": "هواتف", "parent_id": "cat1...", "sort": 1 } ] } ] }
```

### 3.6 المنتجات والمتغيّرات والسمات والصور والوسوم (Products & related)
| Method | Path | Purpose | Permission | CRUD |
|:------:|------|---------|------------|:----:|
| GET | `/products` | قائمة المنتجات (بحث/تصفية) | `catalog.products.view` | R |
| GET | `/products/{uuid}` | تفاصيل منتج + متغيّرات | `catalog.products.view` | R |
| POST | `/products` | إنشاء منتج | `catalog.products.create` | RW |
| PUT/PATCH | `/products/{uuid}` | تعديل | `catalog.products.update` | RW |
| DELETE | `/products/{uuid}` | حذف ناعم | `catalog.products.delete` | RW |
| GET | `/products/{uuid}/variants` | متغيّرات المنتج | `catalog.products.view` | R |
| POST | `/products/{uuid}/variants` | إضافة متغيّر (SKU/باركود) | `catalog.products.update` | RW |
| PUT/PATCH | `/variants/{uuid}` | تعديل متغيّر | `catalog.products.update` | RW |
| DELETE | `/variants/{uuid}` | حذف متغيّر | `catalog.products.update` | RW |
| GET | `/attributes` (+`{uuid}`) | السمات وقيمها | `catalog.attributes.view` | R |
| POST/PUT/DELETE | `/attributes`, `/attribute-values` | إدارة السمات/القيم | `catalog.attributes.{create\|update\|delete}` | RW |
| GET | `/products/{uuid}/images` | صور المنتج | `catalog.products.view` | R |
| POST | `/products/{uuid}/images` | رفع صورة | `catalog.products.update` | RW |
| DELETE | `/product-images/{uuid}` | حذف صورة | `catalog.products.update` | RW |
| GET | `/tags` (+`{uuid}`) | الوسوم | `catalog.products.view` | R |
| POST/PUT/DELETE | `/tags`... | إدارة الوسوم | `catalog.products.update` | RW |

- **فرادة SKU/الباركود (ADR-004):** فريدة عامة الآن مع جاهزية الترقية لنطاق الشركة.
- **طبقات الأسعار (ADR-006):** على مستوى المتغيّر؛ حقول التكلفة محجوبة بلا `pricing.view_cost` (ADR-013).

```json
// POST /products
{ "name": "تيشيرت قطن", "category_id": "cat2...", "brand_id": "br10...",
  "unit_id": "un01...", "tax_id": null, "is_active": true,
  "variants": [
    { "sku": "TSHIRT-RED-M", "barcode": "6291000000017",
      "attributes": { "color": "أحمر", "size": "M" },
      "retail_price": "79.00", "wholesale_price": "65.00",
      "marketer_price": "60.00", "min_price": "55.00", "cost_price": "40.0000" }
  ] }
```
```json
// 201 Created  (المُنادي يملك pricing.view_cost)
{ "data": { "id": "p8a1...", "name": "تيشيرت قطن",
  "category": { "id": "cat2...", "name": "هواتف" },
  "variants": [ { "id": "9b1c...", "sku": "TSHIRT-RED-M", "barcode": "6291000000017",
    "retail_price": "79.00", "min_price": "55.00", "average_cost": "40.0000" } ],
  "is_active": true, "created_at": "2026-07-11T09:30:00Z" } }
```

### 3.7 المخزون (Inventory) — ADR-005/007/008
المخزون لكل (متغيّر × مستودع)؛ الدلاء حسب ADR-007؛ الدفتر append-only حسب ADR-008.

| Method | Path | Purpose | Permission | CRUD |
|:------:|------|---------|------------|:----:|
| GET | `/inventory/stocks` | أرصدة المخزون (دلاء) | `inventory.stocks.view` | R |
| GET | `/inventory/stocks/{uuid}` | رصيد متغيّر في مستودع | `inventory.stocks.view` | R |
| GET | `/inventory/ledger` | دفتر المخزون (append-only) | `inventory.ledger.view` | R |
| GET | `/inventory/movements` | سجلّ الحركات | `inventory.movements.view` | R |
| POST | `/inventory/movements` | تسجيل حركة (وارد/صادر) | `inventory.movements.create` | RW |
| GET | `/inventory/reservations` | الحجوزات القائمة | `inventory.reservations.view` | R |
| POST | `/inventory/reservations` | حجز يدوي (بنية Phase 3) | `inventory.reservations.create` | RW |
| DELETE | `/inventory/reservations/{uuid}` | تحرير حجز | `inventory.reservations.delete` | RW |
| GET | `/inventory/adjustments` (+`{uuid}`) | تسويات الجرد | `inventory.adjustments.view` | R |
| POST | `/inventory/adjustments` | إنشاء تسوية (`ADJ-YYYY-seq`) | `inventory.adjustments.create` | RW |
| GET | `/inventory/transfers` (+`{uuid}`) | التحويلات بين المستودعات | `inventory.transfers.view` | R |
| POST | `/inventory/transfers` | إنشاء تحويل (`TRF-YYYY-seq`) | `inventory.transfers.create` | RW |
| POST | `/inventory/transfers/{uuid}/receive` | استلام تحويل | `inventory.transfers.receive` | RW |

- **الدفتر والحركات append-only (ADR-008/020):** لا `PUT`/`DELETE` — التصحيح بحركة عكسية.
- **أنواع الحركة القانونية (Canonical):** `purchase_in · sale_out · transfer_out · transfer_in · adjustment_in · adjustment_out · reserve · release · return_in · damage_out`.
- **منع السالب (ADR-007a):** خصم يتجاوز المتاح ⇒ `409 INSUFFICIENT_STOCK` ما لم يُفعَّل `inventory.allow_negative` للمستودع. كل حركة داخل معاملة + قفل صف (المبدأ 7).

```json
// GET /inventory/stocks/{uuid}   (المُنادي يملك pricing.view_cost)
{ "data": { "id": "st77...",
  "variant": { "id": "9b1c...", "sku": "TSHIRT-RED-M" },
  "warehouse": { "id": "w9d0...", "name": "مستودع الرياض" },
  "on_hand": "120.000", "reserved": "15.000", "available": "105.000",
  "damaged": "2.000", "returned_pending": "0.000", "in_transit": "0.000",
  "average_cost": "41.2500", "cost_price": "40.0000" } }
```
```json
// POST /inventory/adjustments   (Idempotency-Key مُوصى بها)
{ "warehouse_id": "w9d0...", "reason": "جرد دوري", "note": "فرق عدّ",
  "lines": [ { "variant_id": "9b1c...", "type": "adjustment_out", "qty": "3.000" } ] }
```
```json
// 201 Created
{ "data": { "id": "adj5...", "number": "ADJ-2026-000007", "status": "approved",
  "warehouse": { "id": "w9d0...", "name": "مستودع الرياض" },
  "lines": [ { "variant_id": "9b1c...", "type": "adjustment_out", "qty": "3.000" } ],
  "created_at": "2026-07-11T10:05:00Z" } }
```
```json
// POST /inventory/transfers
{ "from_warehouse_id": "w9d0...", "to_warehouse_id": "w1aa...",
  "lines": [ { "variant_id": "9b1c...", "qty": "20.000" } ] }
// 201 → { "data": { "id": "trf9...", "number": "TRF-2026-000019",
//   "status": "in_transit", "from_warehouse": {...}, "to_warehouse": {...} } }
```

### 3.8 أوامر الشراء والاستلام (Purchasing) — ADR-002/005
> **مصمّمة الآن، تُنفَّذ في المرحلة 3+.** المسارات محجوزة؛ الأجسام تُفصَّل مع تنفيذ الاستلام والتكلفة المُحمّلة (Landed Cost, ADR-005).

| Method | Path | Purpose | Permission | Phase |
|:------:|------|---------|------------|:-----:|
| GET/POST | `/purchase-orders` | أوامر الشراء (`PO-YYYY-seq`) | `purchasing.orders.*` | 3+ محجوز |
| POST | `/purchase-orders/{uuid}/approve` | اعتماد أمر شراء | `purchasing.orders.approve` | 3+ محجوز |
| POST | `/purchase-orders/{uuid}/receive` | استلام (كلي/جزئي) | `purchasing.receiving.create` | 3+ محجوز |

- **حالات أمر الشراء القانونية:** `draft → pending_approval → approved → partially_received → received → closed` + `cancelled`.

### 3.9 مسارات مستقبلية محجوزة (Phase 3+ — Reserved)
تُذكر للتوثيق فقط؛ **بلا أجسام** في هذا العقد:

| المجال | المسار الجذر | المرحلة |
|--------|--------------|:-------:|
| الطلبات | `/orders`, `/orders/{uuid}/transitions` | 3 |
| الفواتير | `/invoices/{uuid}` (uuid، المبدأ 4) | 3–4 |
| الشحنات | `/shipments/{uuid}` | 3 |
| العملاء/CRM | `/customers`, `/customers/{uuid}` | 3 |
| المسوّقون والعمولات | `/marketers`, `/commissions` | 3 |
| الرسائل/Omnichannel | `/messaging/*` | 3 |
| المحاسبة (قيد مزدوج) | `/accounting/*` (ADR-016) | 5 |

---

## 4. استهلاك تطبيق الموبايل المستقبلي (Mobile Consumption)

- **نفس العقد بلا اختلاف:** الموبايل يستهلك `/api/v1` عبر Sanctum (المبدأ 11) — التوكن يُصدَر من `POST /api/v1/auth/login` ويُخزَّن بأمان على الجهاز (Keychain/Keystore).
- **الهوية والأخطاء والترقيم:** موحّدة (غلاف `data/meta/links`، معرّفات `uuid`) فلا حاجة لطبقة تحويل خاصة بالموبايل.
- **التوطين:** يرسل `Accept-Language` حسب لغة الجهاز (ar/en).
- **الكفاءة:** يستخدم `?fields=` و`?include=` لتقليل الحمولة على الشبكات الخلوية؛ و`?per_page` أصغر للقوائم.
- **الكتابة الآمنة دون تكرار:** يرسل `Idempotency-Key` مع كل عملية إنشاء عبر شبكة غير مستقرة (تفادي ازدواج الحركات/الطلبات).
- **الصلاحيات:** نفس بوّابة RBAC وحجب التكلفة (ADR-013) تنطبق؛ واجهة الموبايل تُخفي ما لا يُصرَّح به اعتمادًا على `permissions` المُعادة في `/me`.
- **الإصدار:** كسر متوافق يُدار عبر `/api/v2` مع ترويسات `Deprecation`/`Sunset` لإتاحة تحديث التطبيق تدريجيًا.

---

*يلتزم هذا العقد بقائمة كيانات المرحلة 2 (27) في `DECISIONS.md` والمفردات القانونية للحالات. أي مفتاح حالة/نقطة نهاية جديدة تُحدَّث في `DECISIONS.md` أولًا.*

</div>
