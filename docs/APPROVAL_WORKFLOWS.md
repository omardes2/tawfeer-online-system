<div dir="rtl">

# مصفوفة الاعتمادات وسير الموافقات — Approval Workflows

**المشروع:** Tawfeer Online — ERP + CRM + E‑Commerce + Inventory + Purchasing + Accounting + Affiliate + Omnichannel
**الحالة:** تصميم مُعتمَد (Phase 2 يؤسِّس البنية، Phase 3+ تُنفِّذ سير الطلبات/المرتجعات/العمولات)
**المصادر الملزِمة:** [`ARCHITECTURE.md`](../ARCHITECTURE.md) (المبادئ الـ14) · [`DECISIONS.md`](./DECISIONS.md) (ADRs) · [`FOUNDATION_REPORT.md`](./FOUNDATION_REPORT.md) (الأدوار)

> هذا المستند يعرّف **كل سير موافقة** ومصفوفة **ملكية انتقالات الحالة (State‑Transition Ownership)**. يلتزم حرفيًا بمفاتيح الحالات القانونية في `DECISIONS.md`، وبنظام الأدوار/الصلاحيات (المبدأ 12)، وبسجلّ التدقيق (المبدأ 8)، ومبدأ **العكس لا الحذف** (ADR-016). أي تعارض يُحسم لصالح `DECISIONS.md`.

---

## 0. الأدوار المرجعية (Roles) — المصدر: FOUNDATION_REPORT §5

| الدور (key) | العربية | نطاق البيانات (Data Scope) |
|------------|--------|---------------------------|
| `admin` | مدير النظام | كل الفروع، كل الوحدات، كل الصلاحيات |
| `manager` | مدير | فرعه/فروعه — يعتمد أغلب الطلبات |
| `sales` | موظف مبيعات | طلباته/عملاؤه المُسنَدون فقط |
| `accountant` | محاسب | الفواتير، المدفوعات، العمولات، القيود |
| `warehouse` | أمين مستودع | مستودعاته: مخزون، استلام، تحويل، جرد |
| `affiliate` | مسوّق | عمولاته وطلباته المُحالة فقط |
| `customer` | عميل | طلباته الخاصة فقط |

> الصلاحيات تُدار عبر `spatie/laravel-permission` + Policies (المبدأ 12). **يُمنع** ربط أي اعتماد باسم مستخدم أو `id` مضمّن.

---

## 1. الإطار العام للموافقات (General Approval Framework)

### 1.1 دورة حياة أي طلب اعتماد

```
تقديم (request)  →  مراجعة (review)  →  اعتماد/رفض (approve | reject)  →  الأثر (effect)
       │                                        │
       └────────── قابل للسحب (withdraw) قبل الاعتماد ──────────┘
```

- **request:** ينشئ المستخدم صاحب صلاحية `*.request` طلبًا (كيان `approval_requests` مقترح: نوع، مرجع polymorphic، قيمة/عتبة، حالة، مقدِّم، سبب).
- **review:** المعتمِد ذو صلاحية `*.approve` يفحص الطلب (قد يطلب تعديلًا → `changes_requested`).
- **approve / reject:** قرار نهائي مع **سبب إلزامي عند الرفض**.
- **effect:** الأثر (خصم، انتقال حالة، صرف...) يقع **فقط** بعد الاعتماد، داخل `DB::transaction()` (المبدأ 7)، وينتج حدث دومين (ADR-018) للإشعارات (`NOTIFICATION_MATRIX.md`).

### 1.2 حالات طلب الاعتماد (Approval Request Statuses — قابلة للإدارة، ADR-017)

`pending → approved` | `pending → rejected` | `pending → changes_requested → pending` | `pending → withdrawn`

### 1.3 قواعد حاكمة

| القاعدة | المرجع |
|--------|--------|
| كل قرار (اعتماد/رفض/سحب) يُسجَّل في `audit_logs`: من، ماذا، متى، من أين، قبل/بعد | المبدأ 8 |
| **فصل الواجبات (SoD):** مقدِّم الطلب **لا يعتمد** طلبه (منع `requester_id == approver_id`) إلا لدور `admin` صراحةً | المبدأ 12 |
| كل عتبة (threshold) تُقرأ من **الإعدادات الديناميكية** لا من ثابت في الكود | المبدأ 9 |
| الإجراءات المالية المعتمَدة **تُعكَس ولا تُحذف** (قيد/حركة عكسية) | ADR-016، ADR-011 |
| الأثر المالي/المخزوني ذرّي داخل معاملة واحدة، مع قفل الصفوف الحسّاسة | المبدأ 7، ADR-007a |
| الحالات كلها بيانات قابلة للإدارة، لا `enum` مغلق | المبدأ 10، ADR-017 |

---

## 2. موافقات الخصومات والتسعير (Discount & Pricing Approvals)

### 2.1 اعتماد الخصم فوق العتبة

- عتبة الخصم تُقرأ من الإعدادات: `pricing.discount_approval_threshold_percent` و/أو `pricing.discount_approval_threshold_amount` (المبدأ 9).
- خصم **دون** العتبة: يطبّقه `sales`/`manager` مباشرة (مُدقَّق، بلا اعتماد).
- خصم **فوق** العتبة: يتطلّب اعتماد `manager` (أو `admin`).

### 2.2 تجاوز أدنى سعر (Min‑Price Override) — ADR-006a

- البيع تحت `min_price` **ممنوع** إلا بصلاحية `pricing.override_min_price` **و** اعتماد صريح.
- أي **محاولة** بيع تحت الحد (حتى المرفوضة) تُسجَّل في التدقيق (ADR-006a، المبدأ 8).
- `min_price`/`cost`/`average_cost` لا تظهر لـ `sales` بلا صلاحية `pricing.view_cost` (ADR-013).

| الحدث | مقدِّم | معتمِد | الشرط/العتبة | صلاحية مطلوبة | مُدقَّق | قابل للعكس |
|------|-------|-------|-------------|--------------|:-----:|:---------:|
| خصم على بند/طلب | `sales`,`manager` | `manager`,`admin` | > عتبة الإعدادات | `pricing.apply_discount` | نعم | نعم (قبل التسليم) |
| تجاوز `min_price` | `sales`,`manager` | `manager`,`admin` | سعر < `min_price` | `pricing.override_min_price` | نعم (حتى المحاولة) | نعم |
| تعديل قائمة الأسعار (tiers) | `manager` | `admin` | تغيير `retail/wholesale/min/promo` | `pricing.manage` | نعم | نعم (نسخة سابقة) |

---

## 3. سير الشراء (Purchasing Workflows)

### 3.1 اعتماد أمر الشراء (PO) — حالات قانونية: `draft → pending_approval → approved → partially_received → received → closed` + `cancelled`

| الانتقال (from → to) | من يُنفّذ | صلاحية | اعتماد؟ | ملاحظة |
|---------------------|---------|--------|:------:|--------|
| `draft → pending_approval` | `warehouse`,`manager` | `purchasing.po.create` | — | تقديم للاعتماد |
| `pending_approval → approved` | `manager`,`admin` | `purchasing.po.approve` | ✅ | فوق عتبة `purchasing.po_approval_threshold` قد تتطلب `admin` |
| `pending_approval → draft` | `manager` | `purchasing.po.approve` | — | إعادة للتعديل (`changes_requested`) |
| `pending_approval / approved → cancelled` | `manager`,`admin` | `purchasing.po.cancel` | ✅ | بسبب إلزامي؛ يُعكَس لا يُحذف |
| `approved → partially_received` / `received` | `warehouse` | `purchasing.receive` | توقيع استلام | يحدّث WAC (ADR-005) |
| `received → closed` | `manager` | `purchasing.po.close` | — | إقفال نهائي |

### 3.2 اعتماد فاتورة المورد — حالات: `draft → pending_approval → approved → partially_paid → paid` + `cancelled`

- **منع اعتماد فاتورة مكرّرة:** قبل `approved` يتحقق النظام من فرادة (المورد × رقم الفاتورة) وعدم تجاوز إجمالي الفواتير قيمة الـ PO المستلَم. أي تكرار → رفض + تدقيق.
- الدفع لا يبدأ إلا بعد `approved`.

| الانتقال | من يُنفّذ | صلاحية | اعتماد؟ | ملاحظة |
|---------|---------|--------|:------:|--------|
| `draft → pending_approval` | `accountant` | `purchasing.invoice.create` | — | ربط بـ PO مستلَم |
| `pending_approval → approved` | `accountant`,`manager` | `purchasing.invoice.approve` | ✅ | فحص التكرار + مطابقة الاستلام (3‑way match) |
| `approved → partially_paid`/`paid` | `accountant` | `purchasing.invoice.pay` | حسب عتبة الصرف | ينتج قيد دفع مورد (ADR-016) |
| `* → cancelled` | `manager`,`admin` | `purchasing.invoice.cancel` | ✅ | يُعكَس بقيد عكسي، لا يُحذف |

### 3.3 توقيع الاستلام (Receiving / Partial Receiving Sign‑off)

- الاستلام (كامل/جزئي) يتطلّب توقيع `warehouse` وينتج حركة `purchase_in` + قيد في `inventory_ledger` ويحدّث WAC، كله داخل معاملة (ADR-005، ADR-008).
- الاستلام الجزئي ينقل الـ PO إلى `partially_received` ويترك المتبقّي مفتوحًا.

---

## 4. سير المخزون (Inventory Workflows)

أنواع الحركات القانونية (ADR movements): `purchase_in · sale_out · transfer_out · transfer_in · adjustment_in · adjustment_out · reserve · release · return_in · damage_out`.

### 4.1 اعتماد تسوية المخزون (Stock Adjustment) — `StockAdjustments`

| الانتقال | من يُنفّذ | صلاحية | اعتماد؟ | عتبة |
|---------|---------|--------|:------:|-----|
| `draft → pending_approval` | `warehouse` | `inventory.adjust.request` | — | — |
| `pending_approval → approved` | `manager`,`admin` | `inventory.adjust.approve` | ✅ | فوق `inventory.adjustment_value_threshold` → `admin` |
| `approved → applied` | `warehouse` | `inventory.adjust.apply` | — | ينتج `adjustment_in/out` + قيد دفتر |
| `* → cancelled/reversed` | `manager` | `inventory.adjust.cancel` | ✅ | عكس لا حذف (ADR-016) |

### 4.2 اعتماد التحويل بين المستودعات (Warehouse Transfer) — `WarehouseTransfers`

توقيعان منفصلان (فصل الواجبات): **إرسال (dispatch)** و**استلام (receive)**.

| الانتقال | من يُنفّذ | صلاحية | اعتماد؟ | أثر المخزون |
|---------|---------|--------|:------:|-------------|
| `draft → pending_approval` | `warehouse`,`manager` | `inventory.transfer.request` | — | — |
| `pending_approval → approved` | `manager` | `inventory.transfer.approve` | ✅ | — |
| `approved → dispatched` | `warehouse` (المصدر) | `inventory.transfer.dispatch` | توقيع إرسال | `transfer_out` + `in_transit +=` |
| `dispatched → received` | `warehouse` (الوجهة) | `inventory.transfer.receive` | توقيع استلام | `transfer_in` + `on_hand +=`, `in_transit -=` |
| `* → cancelled` | `manager` | `inventory.transfer.cancel` | ✅ | عكس الحركات المرحّلة |

### 4.3 اعتماد تسوية الجرد الفعلي (Physical Count Reconciliation)

| الانتقال | من يُنفّذ | صلاحية | اعتماد؟ | ملاحظة |
|---------|---------|--------|:------:|--------|
| `count_open → counted` | `warehouse` | `inventory.count.perform` | — | إدخال الكميات المعدودة |
| `counted → pending_approval` | `warehouse` | `inventory.count.submit` | — | يُظهر الفروقات |
| `pending_approval → reconciled` | `manager`,`admin` | `inventory.count.approve` | ✅ | الفروقات تُرحّل كتسويات (§4.1) بعد الاعتماد |

---

## 5. سير الطلبات — مصفوفة انتقالات الحالة (Order Lifecycle State‑Transition Matrix) — ADR-010

الحالات القانونية (ADR-010): `draft → new → awaiting_contact → awaiting_confirmation → confirmed → stock_reserved → preparing → ready_to_ship → shipped → out_for_delivery → delivered`
فرعية/نهائية: `delayed`, `customer_unavailable`, `cancelled`, `delivery_failed`, `returned`, `partially_returned`, `exchanged`.
**نهائية (is_final):** `delivered` (بعد نافذة الإرجاع)، `cancelled`، `returned`، `exchanged`.

> ملكية كل انتقال حسب الدور/الصلاحية (المبدأ 12). أثر المخزون عند كل حالة من ADR-009. الاعتراف بالإيراد عند `delivered` (ADR-010a).

### 5.1 المصفوفة الكاملة (from‑status → to‑status | الدور/الصلاحية | اعتماد | أثر)

| من (from) | إلى (to) | من يُنفّذ + صلاحية | اعتماد؟ | أثر المخزون/المالي (ADR-009) |
|-----------|----------|-------------------|:------:|------------------------------|
| `draft` | `new` | `sales`,`system` · `orders.create` | — | — |
| `new` | `awaiting_contact` | `sales` · `orders.update` | — | — |
| `new` | `cancelled` | `sales`,`manager` · `orders.cancel` | — | لا حجز بعد |
| `awaiting_contact` | `awaiting_confirmation` | `sales` · `orders.update` | — | — |
| `awaiting_contact` | `customer_unavailable` | `sales` · `orders.update` | — | حالة فرعية قابلة للعودة |
| `awaiting_confirmation` | `confirmed` | `sales`,`manager` · `orders.confirm` | — | لا شيء حتى الحجز |
| `awaiting_confirmation` | `cancelled` | `sales`,`manager` · `orders.cancel` | — | — |
| `confirmed` | `stock_reserved` | `system`,`warehouse` · `orders.reserve` | — | `reserve`: `reserved += qty` (ADR-009) |
| `confirmed` | `cancelled` | `manager` · `orders.cancel` | ✅ إن فيه خصم مُعتمَد | — |
| `stock_reserved` | `preparing` | `warehouse` · `orders.prepare` | — | يبقى الحجز |
| `stock_reserved` | `cancelled` | `manager` · `orders.cancel` | ✅ | `release`: `reserved -= qty` |
| `preparing` | `ready_to_ship` | `warehouse` · `orders.prepare` | — | — |
| `preparing` | `delayed` | `warehouse`,`sales` · `orders.update` | — | حالة فرعية |
| `ready_to_ship` | `shipped` | `warehouse` · `orders.ship` | — | **خصم نهائي**: `sale_out` `on_hand -= qty`, `reserved -= qty`, COGS بـ WAC |
| `shipped` | `out_for_delivery` | `warehouse`,`system` · `orders.ship` | — | — |
| `shipped` | `delivery_failed` | `sales`,`warehouse` · `orders.update` | — | يعالَج كإرجاع لاحقًا (ADR-011) |
| `out_for_delivery` | `delivered` | `sales`,`warehouse`,`system` · `orders.deliver` | — | **اعتراف بالإيراد** (ADR-010a) + `earned` للعمولة (ADR-012a) |
| `out_for_delivery` | `customer_unavailable`/`delayed` | `sales` · `orders.update` | — | حالة فرعية |
| `out_for_delivery` | `delivery_failed` | `sales`,`warehouse` · `orders.update` | — | إرجاع (ADR-011) |
| `delivered` | `returned` | `manager` · `returns.approve` | ✅ | عكس كامل (§6) |
| `delivered` | `partially_returned` | `manager` · `returns.approve` | ✅ | عكس جزئي (§6) |
| `delivered` | `exchanged` | `manager` · `returns.approve` | ✅ | عكس + طلب بديل |
| `delayed`/`customer_unavailable` | (العودة للحالة السابقة) | `sales` · `orders.update` | — | استئناف المسار |

**قواعد قابلية التعديل (ADR-010):** تعديل كامل حتى `confirmed`؛ بعد `stock_reserved` تعديل الكميات يتطلّب إعادة حجز؛ بعد `shipped` **تُمنع** تعديلات البنود إلا عبر إرجاع/استبدال.

### 5.2 الإلغاء (Cancellations)

- قبل `stock_reserved`: `sales`/`manager` بلا اعتماد (لا أثر مخزوني).
- بعد `stock_reserved` وقبل `shipped`: `manager` + اعتماد، مع `release` للحجز.
- بعد `shipped`: لا إلغاء مباشر — يُعالَج كإرجاع (ADR-011).

### 5.3 المرتجعات والاستبدال (Returns / Partial / Exchange) — ADR-011

سيتناول التفصيل في §6.

---

## 6. سير المرتجعات والاستبدال (Returns & Exchange) — ADR-011

التدفّق القانوني: `return_request → approved → received → inspected → (restock | to_damaged) → (refund | exchange | credit_note)`.

| المرحلة (from → to) | من يُنفّذ | صلاحية | اعتماد؟ | الأثر (ADR-011/012) |
|---------------------|---------|--------|:------:|---------------------|
| `— → return_request` | `customer`,`sales` · `returns.request` | — | — | نيّة إرجاع (بند/كمية) |
| `return_request → approved` | `manager` · `returns.approve` | ✅ | — | بسبب؛ يفتح استلام المرتجع |
| `return_request → rejected` | `manager` · `returns.approve` | ✅ | — | بسبب إلزامي، مُدقَّق |
| `approved → received` | `warehouse` · `returns.receive` | توقيع | `return_in` → `returned_pending +=` |
| `received → inspected` | `warehouse` · `returns.inspect` | — | فرز صالح/تالف |
| `inspected → restock` | `warehouse` · `returns.inspect` | — | `on_hand += qty`, `returned_pending -=` |
| `inspected → to_damaged` | `warehouse` · `returns.inspect` | — | `damaged += qty`, `returned_pending -=` |
| `→ refund` | `accountant` · `returns.refund.approve` | ✅ | **عكس** الإيراد+COGS+العمولة نسبيًا (ADR-011، ADR-012b) — قيد عكسي لا حذف |
| `→ credit_note` | `accountant` · `returns.credit` | ✅ | إشعار دائن (عكس) |
| `→ exchange` | `manager` · `returns.approve` | ✅ | ينشئ طلبًا بديلًا (`exchanged`) |

- **المرتجع الجزئي:** على مستوى البند/الكمية؛ العكس نسبي (ADR-011).
- **أثر العمولة:** إرجاع كامل بعد الاستحقاق → `reversed`؛ جزئي → تخفيض تناسبي (ADR-012b).

---

## 7. سير العمولات والسحب (Commission & Payout Workflows) — ADR-012

حالات العمولة (ADR-012a): `expected → pending → earned → approved → payable → paid` + `cancelled`, `reversed`.

### 7.1 اعتماد العمولة

| الانتقال | من يُنفّذ | صلاحية | اعتماد؟ | متى/ملاحظة |
|---------|---------|--------|:------:|-----------|
| `expected → pending` | `system` | — | — | عند إنشاء الطلب (تقديري) |
| `pending → earned` | `system` | — | — | عند `delivered` (ADR-012a) |
| `earned → approved` | `manager`,`accountant` · `commission.approve` | ✅ | مراجعة إدارية |
| `approved → payable` | `accountant` · `commission.mark_payable` | — | مستحقة السحب |
| `* → cancelled` | `system`,`manager` · `commission.cancel` | — | إلغاء قبل التسليم (ADR-012b) |
| `earned/approved/payable → reversed` | `system`,`accountant` · `commission.reverse` | ✅ | إرجاع بعد الاستحقاق — عكس لا حذف (ADR-016) |

الأساس: صافي البند بعد الخصم، قبل الشحن/الدفع (`commission.base`, ADR-012c).

### 7.2 طلب السحب/الصرف (Marketer Withdrawal / Payout)

تدفّق: `payout_request → approved → paid` (+ `rejected`).

| الانتقال | من يُنفّذ | صلاحية | اعتماد؟ | الشرط |
|---------|---------|--------|:------:|-------|
| `— → payout_request` | `affiliate` · `payout.request` | — | رصيد `payable` ≥ `affiliate.min_payout` (الإعدادات) |
| `payout_request → approved` | `manager`,`accountant` · `payout.approve` | ✅ | تحقّق الرصيد + بيانات الدفع |
| `payout_request → rejected` | `manager`,`accountant` · `payout.approve` | ✅ | سبب إلزامي، مُدقَّق |
| `approved → paid` | `accountant` · `payout.pay` | حسب عتبة الصرف | ينقل العمولات إلى `paid`، ينتج قيد صرف (ADR-016) |

---

## 8. الجدول الموحّد — «من يعتمد ماذا» (Consolidated Approval Matrix)

| الإجراء (Action) | أدوار مقدِّم الطلب | أدوار المعتمِد | العتبة/الشرط | مُدقَّق؟ | قابل للعكس؟ |
|------------------|-------------------|----------------|--------------|:------:|:----------:|
| خصم فوق العتبة | `sales`,`manager` | `manager`,`admin` | > `pricing.discount_approval_threshold_*` | نعم | نعم (قبل التسليم) |
| تجاوز أدنى سعر (ADR-006a) | `sales`,`manager` | `manager`,`admin` | سعر < `min_price` + `pricing.override_min_price` | نعم (حتى المحاولة) | نعم |
| تعديل قائمة الأسعار | `manager` | `admin` | أي تعديل tiers | نعم | نعم (نسخة) |
| اعتماد أمر شراء (PO) | `warehouse`,`manager` | `manager`,`admin` | > `purchasing.po_approval_threshold` | نعم | نعم (cancel=عكس) |
| اعتماد فاتورة مورد | `accountant` | `accountant`,`manager` | لا تكرار + مطابقة استلام | نعم | نعم (عكس) |
| توقيع استلام (PO) | `warehouse` | `warehouse` | مطابقة الوارد | نعم | نعم |
| تسوية مخزون | `warehouse` | `manager`,`admin` | > `inventory.adjustment_value_threshold` | نعم | نعم |
| تحويل مستودعي | `warehouse`,`manager` | `manager` | dispatch+receive منفصلان | نعم | نعم |
| تسوية جرد فعلي | `warehouse` | `manager`,`admin` | فروقات الجرد | نعم | نعم |
| إلغاء طلب بعد الحجز | `sales`,`manager` | `manager` | بعد `stock_reserved` | نعم | نعم (release) |
| اعتماد مرتجع/جزئي/استبدال | `customer`,`sales` | `manager` | ضمن نافذة الإرجاع | نعم | نعم (عكس نسبي) |
| اعتماد استرداد/إشعار دائن | `sales`,`accountant` | `accountant` | بعد فحص المرتجع | نعم | نعم (قيد عكسي) |
| اعتماد العمولة | `system` | `manager`,`accountant` | حالة `earned` | نعم | نعم (`reversed`) |
| اعتماد سحب المسوّق | `affiliate` | `manager`,`accountant` | رصيد ≥ `affiliate.min_payout` | نعم | نعم (قبل `paid`) |

---

## 9. ملاحظات التطبيق (Implementation Notes)

- كل صف في هذه المصفوفات يقابله **صلاحية `spatie`** + **Policy** (المبدأ 12)؛ لا فحص بالاسم/الـ id.
- كل انتقال حالة يمرّ عبر State Machine تقرأ الحالات والانتقالات من قاعدة البيانات (المبدأ 10، ADR-017).
- كل قرار اعتماد/انتقال ينتج **حدث دومين** (ADR-018) تلتقطه طبقة الإشعارات في `NOTIFICATION_MATRIX.md`.
- كل أثر مالي/مخزوني ذرّي (المبدأ 7) وقابل للعكس لا الحذف (ADR-016)، ومُدوَّن append‑only في الدفاتر (ADR-008/020).
- العتبات ومعايير الاعتماد كلها من **الإعدادات الديناميكية** (المبدأ 9) — لا ثوابت في الكود.

</div>
