<div dir="rtl">

# مراجعة التصميم — Design Review (مرحلة التحليل والتصميم)

**المشروع:** توفير أونلاين (Tawfeer Online)
**المرحلة:** تحليل الأعمال وتصميم Phase 2 (قبل التنفيذ)
**التاريخ:** 2026-07-11
**الحالة:** ✅ حزمة التحليل والتصميم مكتملة — بانتظار موافقة المالك قبل بدء تنفيذ Phase 2

> هذا المستند هو **الخلاصة التنفيذية** لحزمة 13 وثيقة تصميم. لا يحتوي أي كود. لم يُكتب أي Migration/Model/Controller/API/UI.

---

## 1. الملخّص التنفيذي (Executive Summary)

أُنجزت مرحلة **تحليل الأعمال والتصميم** بالكامل قبل كتابة أي كود لـ Phase 2. تُرجمت رؤية "توفير أونلاين" (منصة ERP + CRM + متجر + مخزون + مشتريات + محاسبة + عمولات + تواصل متعدد القنوات) إلى:

- **قرارات معمارية مركزية** (`DECISIONS.md`) هي المصدر الوحيد للحقيقة (22 ADR + مفردات قانونية).
- **قواعد عمل مرقّمة** تغطّي كل الوحدات (`BUSINESS_RULES.md`).
- **تصميم قاعدة بيانات مفصّل لـ Phase 2** (27 كيانًا + 6 جداول داعمة) مع قاموس بيانات كامل.
- **رحلات مستخدم، أحداث، أتمتة، تقارير، عقد API، تنقّل واجهة، اعتمادات، ومصفوفة إشعارات.**

**النتيجة:** كل قرار متقاطع (التكلفة، الحجز، الاعتراف بالإيراد، العمولة، الصلاحيات، الحالات) مُوحَّد عبر كل الوثائق، وأي تعارض حُلَّ مركزيًا. النظام مصمَّم بحيث تُضاف مراحل 3–11 دون إعادة تصميم Phase 2.

---

## 2. الوثائق المُنشأة/المحدَّثة

| # | الوثيقة | الأسطر | الغرض | الحالة |
|:-:|---------|:-----:|-------|:------:|
| 1 | `docs/DECISIONS.md` | 201 | قرارات معمارية مركزية (SSOT) + المفردات القانونية | ✅ جديد |
| 2 | `docs/BUSINESS_RULES.md` | 304 | قواعد العمل المرقّمة لكل الوحدات | ✅ جديد |
| 3 | `docs/USER_JOURNEYS.md` | 544 | 11 رحلة مستخدم كاملة | ✅ جديد |
| 4 | `docs/EVENTS.md` | 401 | كتالوج 25 حدث دومين | ✅ جديد |
| 5 | `docs/AUTOMATIONS.md` | 191 | 13 أتمتة مستقبلية | ✅ جديد |
| 6 | `docs/REPORTS.md` | 411 | تصميم 25 تقريرًا | ✅ جديد |
| 7 | `docs/API_CONTRACT.md` | 397 | عقد REST API لـ Phase 2 | ✅ جديد |
| 8 | `docs/UI_NAVIGATION.md` | 191 | معمارية التنقّل والواجهة (RTL) | ✅ جديد |
| 9 | `docs/APPROVAL_WORKFLOWS.md` | 281 | مسارات الاعتماد + مصفوفة انتقال الحالات | ✅ جديد |
| 10 | `docs/NOTIFICATION_MATRIX.md` | 173 | مصفوفة الإشعارات | ✅ جديد |
| 11 | `docs/PHASE_2_DESIGN.md` | 853 | تصميم قاعدة بيانات Phase 2 المفصّل | ✅ جديد |
| 12 | `docs/DATA_DICTIONARY.md` | 489 | قاموس البيانات لكل عمود | ✅ جديد |
| 13 | `docs/DESIGN_REVIEW.md` | — | هذه الخلاصة | ✅ جديد |

**محدَّثة للاتساق:** `PROJECT_PLAN.md`، `README.md` (فهرس الوثائق).
**متّسقة (بلا تعديل):** `ARCHITECTURE.md`، `REQUIREMENTS.md`، `DATABASE_DESIGN.md`، `FOUNDATION_REPORT.md`.

---

## 3. قائمة كيانات Phase 2 (27 + 6 داعمة)

**الكيانات القانونية (27):**
الأساس/الجغرافيا: `branches`(توسعة) · `branch_settings` · `governorates` · `cities` · `areas` · `shipping_zones` · `warehouses` · `warehouse_locations`.
المورّدون: `suppliers` · `supplier_contacts`.
الكتالوج: `categories` · `brands` · `units` · `currencies` · `taxes` · `products` · `product_variants` · `product_attributes` · `product_attribute_values` · `product_images` · `product_tags`.
المخزون: `inventory_stocks` · `inventory_ledger` · `inventory_movements` · `stock_reservations` · `stock_adjustments` · `warehouse_transfers`.

**الجداول الداعمة (6 — ADR-022):** `shipping_zone_city` · `shipping_zone_area` · `variant_attribute_value` · `product_tag_links` · `stock_adjustment_items` · `warehouse_transfer_items`.

---

## 4. ملخّص العلاقات (Entity Relationship Summary)

```
Branch 1─* Warehouse 1─* WarehouseLocation
Branch 1─1 BranchSettings          Branch *─1 (default) Warehouse [FK مؤجّل خطوة]
Governorate 1─* City 1─* Area
ShippingZone *─* City   |   ShippingZone *─* Area        (عبر جداول داعمة)
Supplier 1─* SupplierContact
Category 1─* Category (شجري)   Brand 1─* Product   Category 1─* Product
Unit 1─* Product     Tax 1─* Product     Currency (مرجعي)
Product 1─* ProductVariant       Product *─* ProductTag (product_tag_links)
Product 1─* ProductImage         Product 1─* ProductAttribute 1─* ProductAttributeValue
ProductVariant *─* ProductAttributeValue (variant_attribute_value)
ProductVariant 1─* InventoryStock *─1 Warehouse       (المخزون لكل متغيّر×مستودع)
InventoryStock/Variant/Warehouse ─* InventoryMovement ─* InventoryLedger (append-only)
StockReservation *─1 Variant, *─1 Warehouse  (order_id مؤجّل — Phase 3)
StockAdjustment 1─* StockAdjustmentItem       WarehouseTransfer 1─* WarehouseTransferItem
WarehouseTransfer: from Warehouse ─→ to Warehouse
```

**مفاتيح متعددة الأشكال (Polymorphic):** `inventory_ledger`/`inventory_movements`/`stock_reservations` تحمل `reference_type`+`reference_id` ليتّصل بها Phase 3+ (طلبات، إيصالات شراء، مرتجعات، قيود) دون إعادة تصميم.

---

## 5. القرارات الجوهرية (Major Business Decisions)

| ADR | القرار | الأثر |
|-----|--------|-------|
| ADR-005 | تقييم المخزون = **المتوسط المرجّح المتحرّك (WAC)** مع تحميل التكاليف (Landed) | حقول `average_cost`؛ COGS بـ WAC |
| ADR-007 | **دلاء مخزون** (on_hand/reserved/available/damaged/returned_pending/in_transit) | لا رصيد مسطّح؛ available محسوب |
| ADR-008 | **دفتر مخزون append-only** منفصل عن الحركات | تتبّع وتدقيق كامل |
| ADR-009 | **حجز عند التأكيد، خصم عند الشحن، تحرير عند الإلغاء** | تصميم `stock_reservations` الآن |
| ADR-010a | **الاعتراف بالإيراد عند التسليم** (استحقاق) | يربط المحاسبة لاحقًا |
| ADR-012 | **8 طرق عمولة** و**8 حالات**؛ الاستحقاق بعد التسليم | عكس عند الإرجاع |
| ADR-006/013 | **طبقات أسعار** + **حد أدنى صارم** + **إخفاء التكلفة** عن المبيعات | حماية الهامش والبيانات |
| ADR-011/016 | **العكس لا الحذف** لأي معاملة معتمدة | سلامة محاسبية |
| ADR-021 | **تسمية صلاحيات دقيقة** `{module}.{resource}.{action}` | توسّع الأدوار تدريجيًا |
| ADR-003/004 | **جاهزية تعدد الفروع/المستودعات/الشركات** | بلا إعادة تصميم مستقبلًا |

---

## 6. حلّ التعارضات والازدواجية (Consistency Resolution)

راجعتُ الوثائق الـ12 وطابقتها مع `DECISIONS.md`. النتائج:

| الفحص | النتيجة |
|-------|---------|
| استخدام `float` للمبالغ | ✅ لا شيء (كل المبالغ decimal) |
| مفاتيح حالات الطلب (18) | ✅ متطابقة مع ADR-010 عبر كل الوثائق |
| حالات العمولة (8) | ✅ متطابقة مع ADR-012a |
| أنواع حركة المخزون (10) | ✅ متطابقة مع ADR-008 |
| تغليف RTL | ✅ كل الوثائق |

**تعارضان حُلّا مركزيًا في `DECISIONS.md`:**
1. **تسمية الصلاحيات:** وثائق التصميم استخدمت مخططًا دقيقًا `{module}.{resource}.{action}` أوسع من 27 صلاحية Phase 1 الأخشن → **حُسم بـ ADR-021:** المخطط الدقيق قانوني، والأخشن أسماء انتقالية تُوسَّع لكل وحدة.
2. **حالات غير مُرسّمة** (تحويل/تسوية/حجز) → **حُسمت:** أُضيفت مفرداتها القانونية إلى `DECISIONS.md` (قسم المفردات).

**قرارات مقبولة (ADR-022):** الجداول الداعمة الـ6، وكيان `approval_requests`، ومفاتيح الإعدادات المقترحة — كلها ضمن التصميم دون توسيع نطاق Phase 2.

**لا ازدواجية قرار:** كل قرار متقاطع مُعرّف مرة واحدة في `DECISIONS.md` وتُشير إليه بقية الوثائق برقم ADR.

---

## 7. أسئلة مفتوحة تحتاج موافقتك (Open Questions)

هذه قرارات **على مستوى المالك** — تركتُ لها قيمًا مقترحة افتراضية، وأحتاج تأكيدك قبل تثبيتها في الكود:

| # | السؤال | المقترح | المرجع |
|:-:|--------|---------|--------|
| 1 | نطاق المتوسط المرجّح (WAC) | لكل مستودع | ADR-005 |
| 2 | توقيت حجز المخزون | عند التأكيد (`confirmed`) | ADR-009 |
| 3 | أساس احتساب العمولة | صافي البند بعد الخصم | ADR-012c |
| 4 | فرادة SKU/الباركود | عامة الآن (جاهزة للترقية) | ADR-004 |
| 5 | العملة في الدفاتر | أحادية العملة الآن | ADR-001 |
| 6 | منع المخزون السالب | ممنوع افتراضيًا | ADR-007a |
| 7 | حدود اعتماد الخصم | نسبة قابلة للإعداد (مثلًا 10%) | BR-EMP / APPROVAL |
| 8 | نافذة الإرجاع بعد التسليم | مثلًا 14 يومًا | BR-RET / ADR-011 |
| 9 | دمج `reports.view_financial` مع `pricing.view_cost` | صلاحيتان منفصلتان | ADR-013/021 |

> بقية أسئلة الوحدات التفصيلية موثّقة داخل كل وثيقة تحت "أسئلة مفتوحة".

---

## 8. المخاطر والتوصيات (Risks & Recommendations)

| المخاطرة | الأثر | التوصية |
|----------|------|---------|
| **دقّة WAC والتزامن** | خطأ تكلفة/ربحية | أقفال صفوف (`lockForUpdate`) + معاملات ذرّية إلزامية (المبدأ 7)، واختبارات تزامن |
| **تصميم الحجز قبل وجود الطلبات** | إعادة عمل في Phase 3 | مفاتيح `reference_*` متعددة الأشكال + `order_id` مؤجّل بلا FK (مُطبّق في التصميم) |
| **توسّع الصلاحيات الدقيقة** | فجوة بين seed والوثائق | تنفيذ ADR-021 تدريجيًا: كل وحدة تزرع صلاحياتها + تحديث الأدوار |
| **لا خادم MySQL في بيئة الحاوية** | فجوة تحقّق | التطوير على MySQL فعلي مبكرًا؛ الهجرات محايدة قدر الإمكان |
| **حجم Phase 2 (33 جدولًا)** | تأخّر التسليم | تقسيم Phase 2 إلى دفعات (أدناه) واعتماد كل دفعة |
| **الحالات كبيانات** | منطق State Machine معقّد | جدول انتقالات + اختبارات لكل انتقال (APPROVAL_WORKFLOWS) |

---

## 9. تسلسل تنفيذ Phase 2 المقترح (Implementation Sequence)

تُقسَّم Phase 2 إلى **6 دفعات (Batches)** تُبنى وتُختبر وتُعتمد بالتتابع:

| الدفعة | المحتوى | يعتمد على |
|:------:|---------|-----------|
| **2A — الأساس والجغرافيا** | توسعة `branches`, `branch_settings`, governorates→cities→areas, shipping_zones (+داعمة) | Phase 1 |
| **2B — المستودعات** | `warehouses`, `warehouse_locations`, ربط الفرع بالمستودع الافتراضي | 2A |
| **2C — المراجع** | `currencies`, `taxes`, `units`, `categories`, `brands` | 2A |
| **2D — المورّدون** | `suppliers`, `supplier_contacts` | 2A |
| **2E — الكتالوج** | `products`, `product_variants`, `attributes`, `attribute_values`, `images`, `tags` (+داعمة) | 2C |
| **2F — المخزون** | `inventory_stocks`, `inventory_ledger`, `inventory_movements`, `stock_reservations`, `stock_adjustments`, `warehouse_transfers` (+داعمة) + خدمات WAC/الحجز/التحويل | 2B, 2E |

**ترتيب الهجرات الآمن** مفصّل في `PHASE_2_DESIGN.md` (29 خطوة مرتّبة حسب الاعتماديات).

---

## 10. تقديرات الحجم (Estimated Effort)

تقديرات لـ Phase 2 كاملة (33 جدولًا)، للتخطيط لا للإلزام:

| العنصر | التقدير | ملاحظات |
|--------|:-------:|---------|
| **Migrations** | ~32–35 | 27 كيان + 6 داعمة + خطوات alter (توسعة branches، FK المستودع الافتراضي) |
| **Models** | ~30 | 27 نموذج + نماذج للجداول ذات المنطق؛ بعض الـpivot بلا نموذج |
| **Services** | ~10–12 | Costing(WAC), Reservation, Transfer, Adjustment, Numbering, Catalog, Geography, Supplier, Product, InventoryLedger |
| **Contracts/Interfaces** | ~4–6 | CostingStrategy, StockService, NumberGenerator, (وريبوزيتوري حسب الحاجة) |
| **API Endpoints (Controllers + Resources)** | ~18–22 مورد | index/show/CRUD حسب `API_CONTRACT.md` |
| **Seeders** | ~6–8 | عملات/ضرائب/وحدات افتراضية، صلاحيات الوحدات (ADR-021)، بيانات جغرافيا عيّنة |
| **Events + Listeners** | ~8–10 | أحداث المخزون/الكتالوج/الشراء من `EVENTS.md` (النشطة في Phase 2) |
| **Tests (Feature + Unit)** | ~50–65 | تغطية القواعد الحرجة: WAC، منع السالب، الحجز، التحويل، الحد الأدنى، الصلاحيات، التدقيق |

---

## 11. الخلاصة

حزمة التحليل والتصميم **مكتملة ومتّسقة ومُلتزمة ومدفوعة**. لم يُكتب أي كود تطبيقي. Phase 2 جاهزة للتنفيذ فور:
1. موافقتك على **الأسئلة المفتوحة (القسم 7)**.
2. اختيارك لنقطة البدء (المقترح: **الدفعة 2A**).

> **بانتظار موافقتك — لن أبدأ تنفيذ Phase 2 قبل إذنك.**

</div>
