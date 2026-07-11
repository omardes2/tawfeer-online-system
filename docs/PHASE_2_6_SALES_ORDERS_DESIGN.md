# تصميم المرحلة 2.6 — طلبات البيع (Sales Orders)

> **الحالة:** مسودّة للاعتماد (Design-first). لا كود قبل اعتماد هذه الوثيقة.
> **المرجعية المُلزِمة:** `ADR-009` (توقيت المخزون)، `ADR-010/010a` (دورة حياة الطلب والإيراد)،
> `BR-ORD-01…18`، `ADR-013` (رؤية التكلفة)، `ADR-017` (الحالات كبيانات)، `ADR-020` (تدقيق/حذف ناعم)،
> والمعمارية المعتمدة في `ARCHITECTURE.md` و`DECISIONS.md`. **لا إعادة تصميم لأي قرار معتمد.**

## 1. السياق والفجوة

وثائق التصميم المجمّدة (`PHASE_2_DESIGN.md`, `DATA_DICTIONARY.md`, `DESIGN_REVIEW.md §9`) تحصر
**Phase 2 في 6 دفعات تنتهي عند المخزون (2F)**، وتؤجّل الطلبات صراحةً إلى "Phase 3" دون مخطط جداول.
لكنّ **قواعد الطلب مجمّدة بالكامل**: دورة الحياة (ADR-010)، توقيت المخزون (ADR-009)،
والقواعد `BR-ORD-01…18`، ومفردات الحالات. هذه الوثيقة **تُنزِل تلك القواعد المجمّدة إلى مخطط جداول
وخدمات** ضمن ما تسمح به اللبنات القائمة (محرّك المخزون، الحجوزات، الكتالوج/المتغيّرات، الحالات القابلة للإدارة)،
**دون اختراع** ميزات خارج القواعد، ومع تأجيل واضح لما يعتمد على مراحل لاحقة.

## 2. النطاق (Phase 2.6)

**داخل النطاق:**
1. `orders` (رأس الطلب) — ترقيم `SO-{YYYY}-{seq}`، uuid، soft-delete، Auditable.
2. `order_items` (بنود الطلب) — **تجميد سعر البند** (BR-ORD-18).
3. `order_status_history` (سجلّ الانتقالات append-only — BR-ORD-09).
4. خدمة `OrderService` بآلة حالات (State Machine) تقرأ الحالات من جدول `order_statuses` القابل للإدارة.
5. تكامل المخزون **حصريًا عبر `ReservationService`/`InventoryService`** (لا مسّ مباشر لجداول المخزون):
   - **حجز** عند `stock_reserved` (ADR-009).
   - **استهلاك + خصم نهائي (COGS بـ WAC)** عند `shipped`.
   - **تحرير** عند الإلغاء قبل الشحن.
6. API تحت `/api/v1/sales/*` + واجهة إدارة عربية RTL.
7. صلاحيات دقيقة `sales.orders.*` + Policies + Seeder.
8. اختبارات Feature كاملة + مراجعة جاهزية الإنتاج.

**خارج النطاق (مؤجّل لمراحله، مع خُطّافات موثّقة):**
| البند | يؤجَّل إلى | الخُطّاف المُجهَّز الآن |
|-------|-----------|------------------------|
| محرّك التسعير/طبقات الأسعار/حدّ min_price (ADR-006/006a) | مرحلة التسعير | البند يخزّن `unit_price` مُدخلًا يدويًا الآن (تجميد سعر — BR-ORD-18)؛ لا اشتقاق تلقائي بعد |
| حساب الضريبة الفعلي (ADR-015) | Phase لاحقة | أعمدة `tax_rate`/`tax_amount`/`tax_total` موجودة، الافتراضي 0 |
| الشحن وتكلفته والحالات التشغيلية `out_for_delivery`/`delayed`/`customer_unavailable`/`delivery_failed` (ADR-014) | **2.7 الشحن** | عمود `shipping_total` (افتراضي 0)، ومسار `shipped→delivered` فقط الآن |
| المدفوعات/حالة الدفع (الأساس) | **2.8 المدفوعات** | لا ربط دفع الآن؛ `payment_statuses` مزروعة مسبقًا |
| الاعتراف بالإيراد وCOGS محاسبيًا (ADR-010a/016) | **2.9 المحاسبة** | حدث دومين عند `delivered` (خُطّاف EVENTS) — دون قيود مزدوجة الآن |
| العمولة والمسوّقون (ADR-012) | مرحلة المسوّقين | لا حقول عمولة على الطلب الآن؛ تُضاف بجداول العمولة لاحقًا |
| المرتجعات/الاستبدال `returned`/`partially_returned`/`exchanged` (ADR-011) | مرحلة المرتجعات | حالات مُعرّفة في القاموس؛ لا مسار تنفيذي الآن (تُمنع الانتقالات إليها) |
| كيان العملاء الكامل / CRM (BR-ORD-15/17) | **2.10 CRM** | لقطة عميل مضمّنة على الطلب + عمود `customer_id` **مؤجّل بلا FK** (يُملأ لاحقًا) |
| قنوات `web`/`marketer`/`pos` ومرحلتا `awaiting_contact`/`awaiting_confirmation` | Phase 3 (المتجر) | عمود `channel` موجود؛ الآن `manual` فقط عمليًا |

## 3. قرار العميل (Customer) — لقطة مضمّنة + معرّف مؤجّل

لتفادي بناء وحدة CRM قبل أوانها (2.10) مع تمكين إنشاء الطلبات الآن، يعتمد التصميم **لقطة عميل مضمّنة**
على الطلب (`customer_name`, `customer_phone`, `customer_email`, `shipping_address`) — وهو متّسق مع فلسفة
**اللقطة الثابتة** في BR-ORD-18، ويكفي لكشف التكرار بالهاتف (BR-ORD-15). ويُضاف عمود `customer_id`
**nullable بلا FK** (نفس نمط `stock_reservations.order_id` المؤجّل) ليربطه كيان `customers` في 2.10 دون إعادة تصميم.

## 4. مخطط الجداول

### 4.1 `orders`
| العمود | النوع | ملاحظات |
|--------|------|---------|
| id | BIGINT PK | داخلي |
| uuid | uuid unique | خارجي (ADR-002) |
| number | string(30) unique | `SO-{YYYY}-{seq}` عبر `NumberGenerator` |
| branch_id | FK branches RESTRICT | متعدّد الفروع (المبدأ 1) |
| warehouse_id | FK warehouses RESTRICT | مستودع التنفيذ (حجز/خصم منه) |
| customer_id | BIGINT nullable **بلا FK** | مؤجّل — يُربط في 2.10 |
| customer_name | string(180) | لقطة |
| customer_phone | string(40) index | لقطة + كشف تكرار (BR-ORD-15) |
| customer_email | string(180) nullable | لقطة |
| shipping_address | text nullable | لقطة عنوان |
| channel | string(20) default `manual` | web/manual/marketer/pos (عمليًا manual الآن) |
| status | string(30) default `draft` | مفتاح من `order_statuses` (ADR-017) |
| assigned_to | FK users nullable SET NULL | مندوب المبيعات (BR-ORD-17) |
| subtotal | decimal(15,2) default 0 | مجموع البنود قبل الخصم |
| discount_total | decimal(15,2) default 0 | |
| tax_total | decimal(15,2) default 0 | مؤجّل الحساب (ADR-015) |
| shipping_total | decimal(15,2) default 0 | مؤجّل (2.7) |
| total | decimal(15,2) default 0 | subtotal − discount + tax + shipping |
| notes | text nullable | |
| cancel_reason | string(255) nullable | يلزم عند الإلغاء (BR-ORD-11) |
| confirmed_at / reserved_at / shipped_at / delivered_at / cancelled_at | timestamp nullable | معالم دورة الحياة |
| created_by | FK users nullable SET NULL | |
| timestamps + softDeletes | | ADR-020 |

**فهارس:** `branch_id`, `warehouse_id`, `status`, `customer_phone`, `assigned_to`, `number`.
**FK:** `branch_id`/`warehouse_id` RESTRICT؛ `assigned_to`/`created_by` SET NULL؛ `customer_id` **بلا FK** (مؤجّل).

### 4.2 `order_items` (بلا soft-delete — تابعة للرأس، كنمط `purchase_order_items`)
| العمود | النوع | ملاحظات |
|--------|------|---------|
| id | BIGINT PK | |
| order_id | FK orders CASCADE | |
| variant_id | FK product_variants RESTRICT | |
| qty | decimal(15,3) | الكمية المطلوبة |
| unit_price | decimal(15,2) | **لقطة سعر** (BR-ORD-18) — مُدخل يدويًا (لا محرّك تسعير بعد) |
| discount | decimal(15,2) default 0 | خصم البند |
| tax_rate | decimal(5,2) default 0 | مؤجّل (ADR-015) |
| tax_amount | decimal(15,2) default 0 | مؤجّل |
| line_total | decimal(15,2) | `(qty × unit_price) − discount + tax_amount` |
| qty_reserved | decimal(15,3) default 0 | يُحدَّث عند الحجز |
| qty_shipped | decimal(15,3) default 0 | يُحدَّث عند الشحن |
| timestamps | | |

**فهارس/قيود:** index `order_id`, `variant_id`؛ unique `(order_id, variant_id)` (بند واحد لكل متغيّر في الطلب).
> **التكلفة/COGS لا تُخزَّن على البند** — يحتسبها محرّك المخزون بـ WAC لحظة `sale_out` (ADR-005/013).

### 4.3 `order_status_history` (append-only — بلا updated_at/soft-delete، كنمط الدفتر)
| العمود | النوع | ملاحظات |
|--------|------|---------|
| id | BIGINT PK | |
| order_id | FK orders CASCADE | |
| from_status | string(30) nullable | الحالة السابقة (null عند الإنشاء) |
| to_status | string(30) | الحالة الجديدة |
| note | string(255) nullable | سبب/ملاحظة (يلزم عند الإلغاء) |
| changed_by | FK users nullable SET NULL | من نفّذ الانتقال (BR-ORD-09) |
| created_at | timestamp | لا `updated_at` |

**فهرس:** `order_id`.

## 5. آلة الحالات (State Machine) — `OrderService`

الحالات القانونية من `order_statuses` (تُوسَّع بذرة لتشمل مفردات ADR-010 الكاملة). المنفَّذ في 2.6:

```
draft ─confirm→ confirmed ─reserveStock→ stock_reserved ─startPreparing→ preparing
      ─markReady→ ready_to_ship ─ship→ shipped ─deliver→ delivered
(إلغاء قبل الشحن) draft|new|confirmed|stock_reserved|preparing|ready_to_ship ─cancel→ cancelled
```

| الانتقال | من → إلى | الأثر (حصريًا عبر خدمات المخزون) | القاعدة |
|---------|----------|-----------------------------------|---------|
| `create` | — → `draft` | لا أثر مخزوني/مالي؛ تعديل/حذف كامل | BR-ORD-02 |
| `confirm` | draft → confirmed | لا أثر بعد؛ تُقفل الأسعار (لقطة) | BR-ORD-03/07 |
| `reserveStock` | confirmed → stock_reserved | لكل بند: `ReservationService::reserve` (reserved += qty، مرجع = Order/OrderItem)؛ يضبط `qty_reserved`، `reserved_at` | ADR-009، BR-ORD-06 |
| `startPreparing` | stock_reserved → preparing | يبقى محجوزًا | ADR-010 |
| `markReady` | preparing → ready_to_ship | يبقى محجوزًا | ADR-010 |
| `ship` | ready_to_ship → shipped | لكل بند: **استهلاك الحجز** (`ReservationService::consume` → reserved −= qty، الحجز `consumed`) ثم **`InventoryService::issue`** (on_hand −= qty، COGS بـ WAC، حركة `sale_out`)؛ يضبط `qty_shipped`، `shipped_at` | ADR-009، BR-ORD-06 |
| `deliver` | shipped → delivered | لا تغيّر مخزوني؛ **حدث دومين `OrderDelivered`** (خُطّاف اعتراف الإيراد — يُنفَّذ محاسبيًا في 2.9) | ADR-010a، ADR-016/018 |
| `cancel` | draft/new/confirmed/stock_reserved/preparing/ready_to_ship → cancelled | إن وُجدت حجوزات نشطة: `ReservationService::release` لكلٍّ (reserved −= qty)؛ يلزم `cancel_reason` | BR-ORD-11 |

- **كل انتقال داخل `DB::transaction`**، ويكتب صفًّا في `order_status_history` (from/to/by/note) — BR-ORD-09.
- **منع الانتقالات غير القانونية:** أي انتقال خارج الجدول أعلاه يُرفض بـ `ValidationException` (ADR-017).
- **الانتقالات المؤجّلة** (`out_for_delivery`, `delayed`, `customer_unavailable`, `delivery_failed`, `returned`, `partially_returned`, `exchanged`): **مُعرّفة في القاموس، ممنوعة تنفيذيًا الآن** — تُفتح في مراحل الشحن/المرتجعات.
- **قابلية التعديل (BR-ORD-07):** `update`/`delete` مسموح فقط في `draft`/`new` (قبل الحجز). بعد ذلك يُرفض تعديل البنود.

### 5.1 إضافة صغيرة مُبرَّرة: `ReservationService::consume`
مفردات حجز المخزون المجمّدة تتضمّن `active → consumed` ("عند الشحن — خصم نهائي"). لا توجد دالة `consume`
بعد (بُنيت `reserve`/`release` في 2.4). تُضاف `consume(reservation)`: تخفّض دلو `reserved` (حركة `release`
بسبب `consume`) وتضبط الحجز `consumed`. **هذا تنفيذٌ لمفردة مجمّدة لا اختراعُ ميزة.** ثم يُجري `OrderService`
خصم `on_hand` عبر `issue` (COGS). الصافي: `on_hand −= qty` و`reserved −= qty` مطابقًا لـ ADR-009.

## 6. الحالات القابلة للإدارة (Statuses)

يُوسّع `StatusSeeder` مجموعة `order_statuses` من 7 حالات خشنة إلى **مفردات ADR-010 الكاملة (18)**
مع `is_final` صحيحة (`delivered`, `cancelled`, `returned`, `exchanged`). الكود يعتمد على `key` الثابت،
والاسم/اللون قابلان للإدارة (ADR-017). لا Enum مغلق.

## 7. الطبقات والملفات (اتّساقًا مع 2.5)

- **الوحدة:** `app/Modules/Sales/{Models,Services,Policies,Providers}` (نطاق البيع؛ يستوعب لاحقًا مرتجعات/فواتير البيع).
- **النماذج:** `Order`, `OrderItem`, `OrderStatusHistory` (+ factories للطلب).
- **الخدمات:** `OrderService` (إنشاء/تعديل/حذف + انتقالات الحالة + إعادة احتساب المجاميع).
- **الطلبات (Form Requests):** `StoreOrderRequest`, `UpdateOrderRequest`, `TransitionRequest` (سبب الإلغاء).
- **الموارد (Resources):** `OrderResource` (+بنود؛ حقول التكلفة/COGS غير مكشوفة أصلًا على الطلب — لا تسريب ADR-013), `OrderStatusHistoryResource`.
- **المتحكمات API:** `OrderController` (CRUD) + `OrderTransitionController` (confirm/reserve/prepare/ready/ship/deliver/cancel) تحت `/api/v1/sales/orders`.
- **واجهة الإدارة:** قائمة الطلبات، نموذج إنشاء/تعديل (بنود ديناميكية Alpine)، صفحة عرض بأزرار الانتقالات وسجلّ الحالات.
- **السياسات:** `OrderPolicy` (view/create/update/delete + confirm/reserve/ship/deliver/cancel).
- **المزوّد:** `SalesServiceProvider` يسجّل `OrderPolicy`.

## 8. الصلاحيات (ADR-021)

`sales.orders.{view, create, update, delete, confirm, reserve, ship, deliver, cancel}` (9 صلاحيات).
**التوزيع (Seeder `SalesPermissionSeeder`):**
- **admin / manager:** الكل.
- **sales:** view, create, update, confirm, reserve, cancel (عمليات المبيعات).
- **warehouse:** view, ship, deliver (التنفيذ من المستودع).
- **accountant:** view فقط.

> **BR-ORD-17 (نطاق رؤية المندوب):** يُضاف عمود `assigned_to` الآن؛ **التقييد الصارم لرؤية المندوب لطلباته فقط
> يُطبَّق مبسّطًا** (المندوب يرى الكل ضمن صلاحيته في 2.6) ويُشدّد في مرحلة إدارة الموظفين — موثّق كتأجيل.

## 9. الأحداث (Hooks — ADR-016/018)

- `OrderConfirmed`, `OrderStockReserved`, `OrderShipped`, `OrderDelivered`, `OrderCancelled` — تُطلق كأحداث دومين
  ليستهلكها لاحقًا: المحاسبة (اعتراف الإيراد/COGS)، الإشعارات، العمولة. **في 2.6 تُعرّف نقاط الإطلاق فقط**
  (أو تُؤجَّل الأحداث نفسها لو رغبت، إبقاءً للـMVP نظيفًا) — القرار: **تأجيل إطلاق الأحداث** إلى مراحلها لتجنّب مستمعين فارغين،
  مع إبقاء المعالم الزمنية (`*_at`) كمصدر للاعتراف لاحقًا.

## 10. الاختبارات (معايير القبول)

- دورة حياة كاملة: create(draft) → confirm → reserveStock (**reserved يزيد عبر المحرّك**) → ship
  (**on_hand ينقص وreserved ينقص، COGS بـ WAC، الحجز consumed**) → deliver.
- الإلغاء قبل الشحن **يحرّر الحجز** (reserved يعود).
- منع الانتقالات غير القانونية (مثل ship قبل reserve، أو الانتقال لحالة مؤجّلة).
- منع تعديل البنود بعد الحجز (BR-ORD-07).
- تجميد سعر البند (BR-ORD-18): تغيّر لاحق لا يؤثّر على طلب قائم.
- سجلّ الحالات يُكتب لكل انتقال (BR-ORD-09).
- التفويض: warehouse لا يستطيع confirm؛ sales لا يستطيع ship؛ accountant قراءة فقط.
- منع الحجز فوق المتاح (يرفضه المحرّك).
- واجهة إدارة RTL تُصيَّر.

## 11. ما لا تفعله هذه المرحلة (تأكيد)

لا محرّك تسعير، لا ضريبة فعلية، لا شحن/تكلفة شحن، لا مدفوعات، لا قيود محاسبية، لا عمولة، لا مرتجعات،
لا كيان CRM، لا قنوات ويب/POS. كلٌّ في مرحلته، بخُطّافات مُجهَّزة أعلاه.

---

### مقترح إضافة إلى `DECISIONS.md`: ADR-026 — مخطط طلبات البيع (Phase 2.6)
يلخّص هذا القرار ما ورد أعلاه: كيانات `orders`(+items)+`order_status_history`؛ لقطة عميل مضمّنة + `customer_id`
مؤجّل بلا FK؛ ترقيم `SO-{YYYY}-{seq}`؛ آلة حالات تقرأ من `order_statuses`؛ الحجز/الاستهلاك/التحرير حصريًا
عبر خدمات المخزون بتوقيت ADR-009؛ إضافة `ReservationService::consume` كتنفيذ لمفردة `consumed` المجمّدة؛
تأجيل التسعير/الضريبة/الشحن/الدفع/المحاسبة/العمولة/المرتجعات/CRM بخُطّافات موثّقة.
