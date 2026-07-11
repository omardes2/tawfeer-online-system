<div dir="rtl">

# قرارات التصميم (Architecture Decision Records) — Tawfeer Online

هذا المستند هو **المصدر الوحيد للحقيقة (Single Source of Truth)** لكل القرارات المتقاطعة بين الوحدات. كل الوثائق الأخرى (`BUSINESS_RULES`, `PHASE_2_DESIGN`, `EVENTS`, ...) يجب أن تلتزم بالمفردات والقواعد المعرّفة هنا. أي تعارض يُحسم لصالح هذا الملف.

> يكمّل [`ARCHITECTURE.md`](../ARCHITECTURE.md) (المبادئ الـ14) ولا يلغيه. الحالة: **✅ مُعتمد ومُجمَّد (Frozen) — 2026-07-11**. كل الأسئلة محسومة (انظر "قرارات محسومة" في نهاية الملف). أي تغيير يتطلب ADR جديدًا.

---

## ADR-000 — تنسيق القرارات
كل قرار: **السياق → القرار → البدائل المرفوضة → الأثر**. القرارات المرقّمة نهائية ما لم تُراجَع صراحةً.

---

## ADR-001 — العملة والمبالغ (Money)
- **القرار:** كل الأسعار وقيم المبيعات `decimal(15,2)`. التكاليف وأوزان المتوسط المرجّح `decimal(15,4)` (دقّة أعلى تفاديًا لتراكم أخطاء التقريب). الكميات `decimal(15,3)`.
- **يُمنع** `float`/`double` (المبدأ 6). الحساب في PHP عبر `bcmath`.
- **عملة أساسية واحدة** للنظام (من الإعدادات، الافتراضي `SAR`). جدول `currencies` يُخزّن العملات وأسعار الصرف للعرض/المستقبل، لكن **الدفاتر تُمسك بالعملة الأساسية** في Phase 2/3.

## ADR-002 — المعرّفات (Identifiers)
- مفتاح داخلي `id` BIGINT، ومعرّف خارجي `uuid` لكل كيان **يُكشف عبر API/روابط** (المبدأ 4): المنتجات، الطلبات (لاحقًا)، الموردون، المستودعات، أوامر الشراء.
- الكيانات المرجعية الداخلية البحتة (المدن، الوحدات، السمات) **قد تكتفي بـ id** إن لم تُكشف خارجيًا؛ لكن الافتراضي إضافة `uuid` لأي كيان قد يظهر في API عام.
- **أرقام الأعمال المقروءة (Human numbers):** تُولّد بنمط مُهيّأ من الإعدادات:
  - أمر شراء: `PO-{YYYY}-{seq}` — مثال `PO-2026-000042`.
  - طلب (Phase 3): `ORD-{YYYY}-{seq}`.
  - تحويل مخزون: `TRF-{YYYY}-{seq}`. تسوية جرد: `ADJ-{YYYY}-{seq}`.
  - التسلسل لكل (نوع × سنة)، ويُولّد داخل معاملة مع قفل لتفادي التكرار.

## ADR-003 — تعدد الفروع/المستودعات (Multi-Branch / Multi-Warehouse)
- **الفرع (Branch)** وحدة تنظيمية؛ **المستودع (Warehouse)** موقع مادّي للمخزون. مستودع واحد يتبع فرعًا واحدًا، والفرع قد يملك عدة مستودعات.
- المخزون يُمسك دائمًا **لكل (متغيّر × مستودع)** — لا رصيد عام (المبدأ 2).
- كل كيان تشغيلي يحمل `branch_id` (يُملأ بالفرع الافتراضي الآن). لا قيم ثابتة `branch_id=1`.

## ADR-004 — جاهزية تعدد الشركات (Multi-Company / Multi-Tenant)
- **القرار:** لا `company_id`/`tenant_id` فعلي في Phase 2 (المبدأ 3). لكن:
  - لا مفاتيح فريدة **عامة** تمنع إضافته لاحقًا؛ نستخدم **فرادة مركّبة** حيثما لزم (مثال: SKU فريد ضمن نطاق الشركة مستقبلًا → الآن فريد عام مع ملاحظة الترقية).
  - كل الاستعلامات تمرّ عبر طبقات (Models/Services) تسمح بحقن Scope لاحقًا في مكان واحد.
- **الأثر:** ترقية تعدد الشركات = إضافة عمود + Global Scope، دون إعادة تصميم.

## ADR-005 — نموذج التكلفة (Costing) = المتوسط المرجّح (Weighted Average Cost)
- **القرار:** تقييم المخزون بطريقة **المتوسط المرجّح المتحرّك (Moving Weighted Average, WAC)** لكل (متغيّر × مستودع) — أو على مستوى الشركة حسب إعداد `inventory.costing_scope` (افتراضي: لكل مستودع).
- عند كل استلام: `WAC_جديد = (قيمة المخزون الحالية + التكلفة المُحمّلة للوارد) / (الكمية الحالية + الكمية الواردة)`.
- **التكلفة المُحمّلة (Landed Cost):** تكلفة الشراء + توزيع مصاريف الشحن/الاستيراد/الجمارك على الأصناف (حسب القيمة أو الوزن أو الكمية — `purchasing.landed_allocation`).
- **تكلفة البضاعة المباعة (COGS):** تُحتسب بـ WAC وقت **خصم المخزون النهائي** (الشحن).
- **البدائل المرفوضة:** FIFO/LIFO (أعقد ولا يطلبه العمل الآن)، سعر ثابت (غير دقيق للربحية).
- **الأثر:** حقول `cost_price` (آخر شراء) و`average_cost` (WAC) على مستوى المخزون؛ التغيّرات تُدوّن في دفتر المخزون.

## ADR-006 — طبقات الأسعار (Price Tiers)
لكل منتج/متغيّر مجموعة أسعار مُدارة:
| الحقل | المعنى | ملاحظة |
|------|--------|--------|
| `cost_price` | تكلفة آخر شراء | مرجعي |
| `average_cost` | المتوسط المرجّح | يُحتسب آليًا |
| `retail_price` | سعر التجزئة | البيع للعميل النهائي |
| `wholesale_price` | سعر الجملة | للعملاء بالجملة |
| `marketer_price` | سعر أساس المسوّق | أساس عمولة الفرق |
| `min_price` | أدنى سعر بيع مسموح | **حد صارم** |
| `promo_price` | سعر ترويجي مؤقت | ضمن فترة |
- **الحد الأدنى (ADR-006a):** يُمنع البيع تحت `min_price` إلا بصلاحية `pricing.override_min_price` **وبموافقة** (APPROVAL_WORKFLOWS). أي محاولة تُسجَّل في التدقيق.
- **هامش الربح المستهدف (`target_margin`)** يُخزّن للتنبيه فقط، لا يمنع.

## ADR-007 — دلاء المخزون (Stock Buckets)
كمية المخزون تُفكَّك إلى دلاء منفصلة لكل (متغيّر × مستودع):
| الدلو | المعنى |
|------|--------|
| `on_hand` | الموجود ماديًا |
| `reserved` | محجوز لطلبات مؤكّدة |
| `available` | `on_hand − reserved` (محسوب) |
| `damaged` | تالف (خارج البيع) |
| `returned_pending` | مرتجع بانتظار الفحص |
| `in_transit` | قيد التحويل بين المستودعات |
- **المتاح للبيع = `on_hand − reserved`**. الدلاء `damaged`/`returned_pending`/`in_transit` **لا** تُحتسب متاحة.
- **منع السالب (ADR-007a):** يُمنع أن يصبح `available` سالبًا إلا إذا فُعِّل `inventory.allow_negative` (لكل مستودع). كل حركة تُتحقّق داخل معاملة مع قفل صف المخزون (المبدأ 7).

## ADR-008 — دفتر المخزون وحركاته (Inventory Ledger vs Movements)
- **`inventory_movements`:** سجلّ الأحداث الخام (وارد/صادر/تحويل/تسوية/حجز/تحرير) — نيّة الحركة.
- **`inventory_ledger`:** دفتر **append-only** لكل تغيّر في الكمية والتكلفة (رصيد جارٍ + WAC بعد كل حركة) — للتتبّع والتدقيق والتقارير. لا Soft Delete، لا تعديل.
- كل حركة مخزون تُنتج قيدًا/أكثر في الدفتر داخل نفس المعاملة.

## ADR-009 — توقيت المخزون في دورة الطلب (Phase 3 — يُصمَّم الآن)
هذه قرارات تُلزم تصميم Phase 2 (بنية الحجز) وتُنفَّذ في Phase 3:
| اللحظة | الأثر على المخزون |
|--------|-------------------|
| تأكيد الطلب (`confirmed`) → `stock_reserved` | **حجز**: `reserved += qty` (لا يمسّ `on_hand`). عبر `stock_reservations`. |
| الشحن (`shipped`) | **خصم نهائي**: `on_hand -= qty`, `reserved -= qty`، وتُحتسب COGS بـ WAC. |
| الإلغاء قبل الشحن | **تحرير**: `reserved -= qty`. |
| الإلغاء/الفشل بعد الشحن | يعالَج كإرجاع (ADR-011). |
| الإرجاع المقبول والمفحوص | إمّا `on_hand += qty` (صالح) أو `damaged += qty` (تالف). |
- الحجز **قابل للإعداد** (`inventory.reserve_on`: `confirmed`|`paid`)، الافتراضي `confirmed`.

## ADR-010 — دورة حياة الطلب (Order Lifecycle — Phase 3، مُعرّفة الآن)
الحالات القانونية (تمتد الحالات المزروعة في الأساس):
`draft → new → awaiting_contact → awaiting_confirmation → confirmed → stock_reserved → preparing → ready_to_ship → shipped → out_for_delivery → delivered`
وحالات فرعية/نهائية: `delayed`, `customer_unavailable`, `cancelled`, `delivery_failed`, `returned`, `partially_returned`, `exchanged`.
- **نهائية (is_final):** `delivered`(قبل نافذة الإرجاع)، `cancelled`، `returned`، `exchanged`.
- **الاعتراف بالإيراد (ADR-010a):** عند `delivered` (أساس الاستحقاق) — لا عند الإنشاء ولا الدفع.
- **قابلية التعديل:** الطلب قابل للتعديل الكامل حتى `confirmed`؛ بعد الحجز تُقيَّد التعديلات (الكميات تتطلب إعادة حجز)؛ بعد `shipped` تُمنع تعديلات البنود (إلا عبر إرجاع/استبدال).
- **من يغيّر ماذا:** مصفوفة الانتقالات في `APPROVAL_WORKFLOWS.md` (ملكية كل انتقال حسب الدور/الصلاحية — المبدأ 12).
- **مصدر الطلب (`channel`/`source`):** `web` | `manual` (موظف) | `marketer` | `pos`(لاحقًا). طلبات الموظف اليدوية تبدأ من `new`/`draft` وقد تتجاوز `awaiting_contact`.

## ADR-011 — المرتجعات والاستبدال (Returns)
- تدفّق: `return_request → approved → received → inspected → (restock | to_damaged) → (refund | exchange | credit_note)`.
- **الأثر العكسي (Reversal):** الإرجاع **يعكس** الإيراد وCOGS والمخزون والعمولة (نسبيًا للكمية المرتجعة). المرتجع الجزئي يعالَج على مستوى البند/الكمية.
- **لا حذف — بل عكس (المبدأ + ADR-016):** لا تُحذف حركة مالية/مخزونية معتمدة؛ تُنشأ حركة عكسية.

## ADR-012 — نموذج العمولة (Commission Model)
طرق مدعومة (لكل منتج/حملة/مسوّق حسب قاعدة `commission_rules`):
1. مبلغ ثابت لكل منتج. 2. نسبة من المبيعات. 3. نسبة من الربح. 4. **فرق السعر** (سعر البيع − `marketer_price`). 5. متدرّجة شهريًا (Tiers). 6. حسب المنتج. 7. حسب الحملة. 8. **بعد التسليم فقط**.
- **حالات العمولة (ADR-012a):** `expected → pending → earned → approved → payable → paid` + `cancelled`, `reversed`.
  - `expected`: عند إنشاء الطلب (تقديري). `earned`: عند `delivered`. `approved`: موافقة إدارية. `payable`: مستحقة السحب. `paid`: بعد الدفع.
- **الأثر عند الإلغاء/الإرجاع (ADR-012b):** إلغاء قبل التسليم → `cancelled`. إرجاع كامل بعد الاستحقاق → `reversed`. إرجاع جزئي → تخفيض تناسبي.
- **الأساس (ADR-012c):** العمولة تُحتسب على **صافي قيمة البند بعد الخصم**، و**قبل** رسوم الشحن/الدفع (قابل للإعداد `commission.base`: `net_item`|`gross`|`profit`). رسوم التوصيل والدفع **لا** تُدرج افتراضيًا.

## ADR-013 — رؤية التكلفة للموظفين (Cost Visibility)
- سعر التكلفة و`average_cost` و`min_price` **لا تظهر** لموظف المبيعات إلا بصلاحية صريحة `pricing.view_cost` (المبدأ 12). التقارير الربحية محجوبة بلا الصلاحية.

## ADR-014 — الجغرافيا والشحن (Geography)
- تسلسل: **المحافظة (governorate) → المدينة (city) → المنطقة (area)**. **منطقة الشحن (shipping_zone)** تجميعة تسعير/تغطية تُربط بمدن/مناطق (علاقة كثير-لكثير)، مستقلة عن التسلسل الإداري.
- عنوان العميل (Phase 3) يشير إلى area/city/governorate. تكلفة الشحن تُشتق من `shipping_zone` (Phase 3).

## ADR-015 — الضرائب (Taxes)
- جدول `taxes` (اسم، نسبة، نوع شامل/مضاف، فعّال). المنتج قد يشير إلى ضريبة، وإلا الافتراضي من الإعدادات (`tax.rate=15%`, `tax.enabled`). حساب الضريبة فعلي في Phase 3.

## ADR-016 — الأثر المحاسبي (Accounting Hooks — لا تنفيذ الآن)
- المحاسبة **قيد مزدوج** (Double-Entry) تُنفَّذ في Phase 5، لكن كل حدث مالي في Phase 2/3 يُصمَّم لينتج **قيدًا لاحقًا** عبر أحداث (EVENTS): استلام بضاعة، تسوية مخزون، فاتورة مبيعات، تحصيل، دفع مورد، عمولة، إرجاع.
- **العكس لا الحذف:** كل معاملة معتمدة تُعكَس بقيد عكسي.

## ADR-017 — الحالات كبيانات لا Enum (Statuses)
- كل الحالات (طلب/دفع/شحن/عمولة/إرجاع/أمر شراء/استلام) في **جداول قابلة للإدارة** (المبدأ 10)، ذات `key` ثابت يعتمد عليه الكود، و`name` قابل للترجمة. المنطق (State Machine) يقرأ الانتقالات المسموحة من قاعدة البيانات/التهيئة.

## ADR-018 — الأحداث والطوابير (Events & Queues)
- كل عملية جوهرية تُطلق **حدث دومين (Domain Event)** (EVENTS.md). المستمعون الثقيلون (إشعارات، تقارير، فهرسة) يعملون في **طوابير** (المبدأ 11/13). لا منطق أعمال حرج داخل مستمع غير متزامن (الحرج داخل المعاملة).

## ADR-019 — API-First والعقود (Contracts)
- كل قدرة تُكشف عبر **REST API مُصدَّر `/api/v1`** باستجابات Resources موحّدة (المبدأ 11). أي مزوّد خارجي (شحن، دفع، رسائل) خلف **عقد (Interface)** + Driver (المبدأ 13).

## ADR-020 — التدقيق والحذف الناعم (Audit & Soft Delete)
- الكيانات الرئيسية: **Soft Delete** (المبدأ 5). الدفاتر (`inventory_ledger`, `audit_logs`, journal lines لاحقًا): **append-only بلا Soft Delete**.
- الكيانات الحسّاسة تحمل تريتة `Auditable` (المبدأ 8): المنتجات، الأسعار، المخزون، الموردون، أوامر الشراء، التحويلات، التسويات.

---

## المفردات القانونية (Canonical Vocabularies)

> **قاعدة إلزامية:** لا يجوز لأي وثيقة أو كود اختراع مفتاح حالة خارج هذه القوائم دون تحديثها هنا أولًا.

### حالات أمر الشراء (Purchase Order)
`draft → pending_approval → approved → partially_received → received → closed` + `cancelled`.

### حالات فاتورة المورد (Supplier Invoice — Phase 4)
`draft → pending_approval → approved → partially_paid → paid` + `cancelled` (يُعكَس لا يُحذف).

### حالات المخزون-الحركة (Movement Types)
`purchase_in` · `sale_out` · `transfer_out` · `transfer_in` · `adjustment_in` · `adjustment_out` · `reserve` · `release` · `return_in` · `damage_out`.

### حالات الطلب / الدفع / الشحن / العمولة / الإرجاع
معرّفة في ADR-010 / الأساس / ADR-009 / ADR-012 / ADR-011 على التوالي.

### حالات تحويل المستودعات (Warehouse Transfer) — مُرسّمة في مراجعة التصميم
`draft → pending_approval → approved → dispatched(in_transit) → received` + `cancelled`.
> `dispatched` يحرّك الكمية إلى دلو `in_transit` (ADR-007)؛ `received` يودعها في مستودع الوجهة. توقيعان منفصلان (إرسال/استلام).

### حالات تسوية الجرد (Stock Adjustment) — مُرسّمة في مراجعة التصميم
`draft → pending_approval → approved(applied)` + `cancelled`.
> التطبيق (`applied`) ينتج حركة `adjustment_in`/`adjustment_out` وقيد دفتر، داخل معاملة.

### حالات حجز المخزون (Stock Reservation) — مُرسّمة في مراجعة التصميم
`active → released` | `active → fulfilled` | `active → expired`.
> `active` = محجوز (`reserved`)؛ `fulfilled` عند الشحن (خصم نهائي)؛ `released`/`expired` يعيدان الكمية للمتاح (ADR-009).

---

## ADR-021 — تسمية الصلاحيات (Permission Naming) [مُعتمد في مراجعة التصميم]
- **السياق:** أنتجت وثائق التصميم مخططًا دقيقًا `{module}.{resource}.{action}` (مثل `catalog.products.view`, `inventory.transfer.approve`) بينما زرعت Phase 1 مجموعة **أخشن** من 27 صلاحية (`catalog.view`, `inventory.manage`...).
- **القرار:** المخطط الدقيق **`{module}.{resource}.{action}`** هو **القانوني** للنظام. صلاحيات Phase 1 الأخشن تبقى كـ **مجموعات/أسماء مستعارة انتقالية** تُوسَّع إلى الصلاحيات الدقيقة **عند بناء كل وحدة** (تُضاف عبر seed لكل مرحلة). لا يُعتمد على أي صلاحية أخشن في كود جديد.
- **صلاحيات حسّاسة مُرسّمة:** `pricing.view_cost` (رؤية التكلفة/الربح — ADR-013)، `pricing.override_min_price` (تجاوز أدنى سعر — ADR-006a)، `reports.view_financial` (تقارير الربحية). تُزرع مع وحداتها.
- **الأثر:** تحديث `RolePermissionSeeder` تدريجيًا لكل وحدة؛ الأدوار السبعة تبقى ثابتة (المبدأ 12).

## ADR-023 — توسعة حقول المنتج الكتالوجي (Product Catalog Fields) [مُعتمد في Phase 2.3]
- **السياق:** يتطلّب المالك في Phase 2.3 حقولًا تتجاوز `products` §16: تسمية/وصف **ثنائيا اللغة**، **حالة تحريرية** (draft/active/archived)، **ظهور** (visible/hidden)، **مميّز (featured)**، **ترتيب**، **حقول SEO**، و**بيانات بحث**.
- **القرار:** إضافة هذه الأعمدة إلى `products` دون المساس بأعمدة التصميم §16:
  - `name_en`, `short_description_en`, `description_en` (إنجليزي ثانوي؛ العربي أساسي).
  - `status` (`draft` افتراضي / `active` / `archived`) — دورة الحياة التحريرية.
  - `visibility` (`visible` افتراضي / `hidden`) — ظهور المتجر.
  - `is_featured` (bool)، `sort_order` (int)، `meta_title`/`meta_description`/`meta_keywords` (SEO)، `search_keywords` (بيانات بحث).
- **`is_active` (من §16) يبقى** ويُشتقّ آليًا من الحالة: `is_active = (status === 'active')` — تحافظ عليه `ProductService` ليخدم فهرس `(is_active, type)` واستعلامات المتجر السريعة.
- **الأسعار (§16):** الأعمدة موجودة للوفاء بالتصميم، لكن **لا محرّك تسعير في Phase 2.3** (تُترك بقيمها الافتراضية ولا تُدار في نماذج هذه المرحلة). حقول التكلفة محجوبة بـ `pricing.view_cost` (ADR-013).
- **FK مؤجّلة:** `tax_id` (جدول taxes غير مُنشأ)، و`product_images.variant_id` (المتغيّرات غير مُنشأة) — أعمدة nullable بلا قيد الآن، تُربط في مرحلتها.
- **الأثر:** لا إعادة تصميم؛ إضافة أعمدة فقط تتبع كل الأعراف (uuid، soft-delete، auditable، RBAC).

## ADR-022 — الجداول الداعمة والكيانات المؤجلة [مُعتمد في مراجعة التصميم]
- **الجداول الداعمة (Pivots/Children)** خارج الـ27 لكنها جزء طبيعي من Phase 2: `shipping_zone_city`, `shipping_zone_area`, `variant_attribute_value`, `product_tag_links`, `stock_adjustment_items`, `warehouse_transfer_items`. مقبولة ولا تُعدّ توسّعًا للنطاق.
- **كيان `approval_requests`** (طلبات الاعتماد العامة) و**مفاتيح إعدادات مقترحة** (`pricing.discount_approval_threshold`, `purchasing.po_approval_threshold`, `inventory.adjustment_value_threshold`, `affiliate.min_payout`, `notifications.quiet_hours`): **مقبولة كتصميم**، تُنشأ في مرحلتها المناسبة (الاعتمادات في Phase 3+، لا في Phase 2). لا حاجة لتعديل نطاق Phase 2.

---

## قائمة كيانات Phase 2 القانونية (27)
Branches · BranchSettings · Governorates · Cities · Areas · ShippingZones · Warehouses · WarehouseLocations · Suppliers · SupplierContacts · Categories · Brands · Units · Currencies · Taxes · Products · ProductVariants · ProductAttributes · ProductAttributeValues · ProductImages · ProductTags · InventoryStocks · InventoryLedger · InventoryMovements · StockReservations · StockAdjustments · WarehouseTransfers.

> `Branches` و`AuditLog`/`Settings`/`*Status` موجودة من Phase 1؛ في Phase 2 نوسّعها لا نعيد إنشاءها.

---

## قرارات محسومة (Resolved — مُجمَّدة 2026-07-11)
> اعتُمدت كل القيم المقترحة نهائيًا بموافقة المالك. أي تغيير لاحق يتطلب ADR جديدًا.

| # | القرار المحسوم | القيمة النهائية | المرجع |
|:-:|----------------|-----------------|--------|
| 1 | نطاق المتوسط المرجّح (WAC) | **لكل (متغيّر × مستودع)** | ADR-005 |
| 2 | توقيت حجز المخزون | **عند التأكيد (`confirmed`)** — `inventory.reserve_on=confirmed` | ADR-009 |
| 3 | أساس احتساب العمولة | **صافي البند بعد الخصم** — `commission.base=net_item`؛ الشحن/رسوم الدفع مستثناة | ADR-012c |
| 4 | فرادة SKU/الباركود | **عامة الآن** مع جاهزية الترقية لنطاق شركة (فرادة مركّبة لاحقًا) | ADR-004 |
| 5 | العملة | **أحادية العملة في الدفاتر** (`SAR`)؛ `currencies` مرجعي للعرض/المستقبل | ADR-001 |
| 6 | منع المخزون السالب | **ممنوع افتراضيًا**؛ يُسمح لكل مستودع عبر `inventory.allow_negative` | ADR-007a |
| 7 | حدّ اعتماد الخصم | **قابل للإعداد، الافتراضي 10%** — `pricing.discount_approval_threshold=10` | ADR-006a |
| 8 | نافذة الإرجاع بعد التسليم | **14 يومًا** قابلة للإعداد — `orders.return_window_days=14` | ADR-011 |
| 9 | `reports.view_financial` مقابل `pricing.view_cost` | **صلاحيتان منفصلتان** (رؤية التكلفة ≠ التقارير المالية) | ADR-013/021 |

</div>
