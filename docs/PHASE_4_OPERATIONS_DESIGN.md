<div dir="rtl">

# تصميم المرحلة 4 — العمليات (Assisted Sales · Affiliate · Delivery Ops · Returns · Claims · Settlements)

> **الحالة:** وثيقة تصميم مُعتمدة (ADR-037). تلتزم بـ[`ARCHITECTURE.md`](../ARCHITECTURE.md) و[`DECISIONS.md`](DECISIONS.md) و[`BUSINESS_RULES.md`](BUSINESS_RULES.md) (BR-ORD/PRICE/MKT/RET/EMP/ACC) و[`EVENTS.md`](EVENTS.md). أي تعارض يُحسم لصالح `DECISIONS.md`.
>
> **المبدأ:** إعادة استخدام Orders/CRM/Inventory/Shipping/Payments/Accounting/Permissions/Events وطبقات المزوّدين دون إعادة تصميم؛ متحكمات رفيعة والمنطق في خدمات/إجراءات؛ **دفاتر غير قابلة للتعديل** (لا حذف — عكس)، قيود قاعدة بيانات، تدقيق، مسارات موافقة، ومعالجة Webhook **idempotent**؛ عربي RTL + إنجليزي.

## لماذا مراحل فرعية

المرحلة 4 ضخمة (12 مجالًا). تُقسَّم — كما قُسّمت المرحلة 3 — إلى دفعات مستقلة قابلة للاختبار والاعتماد، كل دفعة تُبنى وتُختبر وتُعتمد قبل التالية:

| الدفعة | النطاق | الحالة |
|-------|--------|--------|
| 4.1 | البيع المُساعد + الأدوار + تعديل السعر اليدوي بالموافقة واللقطات | ✅ مكتملة |
| **4.2** | عمولات الموظفين + أرباح المسوّقين (دفاتر غير قابلة للتعديل + حالات) | ✅ مكتملة |
| 4.3 | عمليات التوصيل + تكامل المزوّد (تقديم/webhook/مزامنة) + الاستثناءات | ⬜ |
| 4.4 | المرتجعات والاستبدال (RMA) + الفحص وتوجيه المخزون | ⬜ |
| 4.5 | مطالبات التوصيل (تلف/كسر/فقد/نقص/تسرّب) | ⬜ |
| 4.6 | تسويات ومطابقة التوصيل → تفعيل استحقاق العمولات/الأرباح + قيود محاسبية | ⬜ |
| 4.7 | تقارير العمليات | ⬜ |

**تسلسل التبعيات:** 4.1 (بيع/إسناد) → 4.2 (دفاتر تُنشأ عند الطلب/التسليم) → 4.3/4.4/4.5 (تشغيل) → **4.6 (تسوية تُفعّل الاستحقاق)** → 4.7 (تقارير). استحقاق العمولة/الربح **لا يُفعَّل إلا بعد التسوية الناجحة** (المتطلّب 11) — لذا تبقى الدفاتر `pending/earned` حتى 4.6.

---

## الأدوار والصلاحيات (المتطلّب 1)

الأدوار القائمة: `admin, manager, sales, accountant, warehouse, affiliate, customer`. تُضاف أدوار العمليات:

| دور جديد | يقابل | يُضاف في |
|---------|-------|---------|
| `sales_supervisor` | مشرف مبيعات (يعتمد تعديلات السعر/الخصم) | 4.1 |
| `delivery_ops` | موظف عمليات التوصيل | 4.3 |
| `warehouse_manager` | مدير المستودع (فحص المرتجع) | 4.4 |
| `finance` | موظف مالية (تسويات/مطالبات/اعتماد صرف) | 4.6 |

الصلاحيات بنمط `{module}.{resource}.{action}` (ADR-021). كل دفعة تزرع صلاحياتها عبر Seeder مخصّص وتوزّعها على الأدوار.

---

## الدفعة 4.1 — البيع المُساعد وإدخال الطلبات اليدوي (هذه الدفعة)

**الهدف:** تمكين موظف المبيعات من إنشاء طلب من قناة (واتساب/إنستغرام/ماسنجر/هاتف/أخرى) مع البحث/الإنشاء بالهاتف، اختيار المنتجات/المتغيّرات/الكميات/الفرع/المستودع، **تعديل سعر بيع يدوي مضبوط**، وحفظ لقطات كاملة — **بنفس ضوابط الحجز/التسعير/الحد الأدنى** (BR-ORD-16، BR-EMP-08). لا مسار مختصر يتجاوز الضوابط.

### القناة والإسناد
- **القناة (channel):** توسعة المفردات (نصّية، لا enum — ADR-017): `web · manual · marketer · pos · whatsapp · instagram · messenger · phone · other`. لا هجرة تغيّر النوع (العمود `string(20)` قائم).
- **الإسناد:** `orders.assigned_to` = موظف المبيعات (قائم). يُضاف `orders.affiliate_id` (FK users، nullable) = المسوّق المُحيل (كيان المسوّق/المحفظة يأتي في 4.2).

### تعديل السعر اليدوي واللقطات (المتطلّب 2)
لكل بند، تُحفظ **لقطات ثابتة** على `order_items`:
- `retail_price_snapshot` — سعر التجزئة الأصلي وقت الإنشاء.
- `wholesale_cost_snapshot` — لقطة تكلفة الجملة (`average_cost` الحالية — WAC).
- `unit_price` — سعر البيع الفعلي (قائم، BR-ORD-18).
- `discount` — الخصم (قائم).
- `price_change_reason` + `price_approved_by` — سبب التعديل والمُعتمِد.

**سجلّ تغييرات السعر (غير قابل للتعديل)** — جدول `order_price_changes` (append-only): `order_id`, `order_item_id`, `variant_id`, `original_retail`, `wholesale_cost`, `min_price`, `new_price`, `discount`, `reason`, `requires_approval`, `status` (`auto_approved`/`pending`/`approved`/`rejected`), `requested_by`, `approved_by`, `decided_at`. لا حذف — كل تعديل حركة.

**قاعدة الموافقة (BR-EMP-05، BR-PRICE-06):** يتطلب اعتماد مشرف عندما `new_price < min_price` **أو** `new_price < wholesale_cost`، **ما لم** يملك المنفّذ صلاحية `sales.orders.override_price` (حينها `auto_approved` مع تدقيق). التعديل ضمن الحدود = `auto_approved`. طلب معلّق `pending` يظهر للمشرف لاعتماده/رفضه.

### الخدمات (المنطق كلّه هنا)
- `AssistedOrderService`:
  - `resolveCustomer(phone, data)` — بحث بالهاتف المطبّع (CustomerService — BR-CUST-03/05) أو إنشاء عميل جديد. عميل محظور يُحذَّر (BR-CUST-11) ولا يُنشئ (BR-CUST-12، عبر OrderService القائم).
  - `create(actor, data, items)` — يبني بنودًا بلقطات (retail/wholesale/min)، يحدّد ما يتطلّب اعتمادًا، يستدعي **`OrderService::create` القائم** (لا تكرار منطق الطلب/الحجز)، ثم يسجّل `order_price_changes`. القناة `whatsapp/…`، الإسناد assigned_to/affiliate_id.
  - `approvePriceChange` / `rejectPriceChange` (مشرف) — يحدّث الحالة + `price_approved_by` + تدقيق.
- تُعاد قيمة العمولة `expected` **تصميمًا فقط** الآن (الدفاتر في 4.2)؛ لا حساب هنا.

### API والواجهة
- `POST /api/v1/sales/assisted-orders` (إنشاء)، `GET .../price-changes` (المعلّقة)، `POST .../price-changes/{id}/approve|reject`.
- واجهة إدارة RTL: شاشة «طلب مُساعد» (بحث عميل بالهاتف، بنود بأسعار قابلة للتعديل مع تحذير تحت الحد/التكلفة)، وصندوق اعتماد تعديلات السعر للمشرف.

### الصلاحيات (4.1)
`sales.orders.assist` (إنشاء طلب مُساعد) · `sales.orders.override_price` (تجاوز الحد/التكلفة دون اعتماد، واعتماد طلبات التعديل). التوزيع: sales → assist؛ sales_supervisor/manager → assist + override.

### الأحداث
`AssistedOrderCreated` · `OrderPriceChangeRequested` · `OrderPriceChangeApproved`/`Rejected` (تُوثَّق في EVENTS؛ نقاط امتداد، تُستهلك محاسبيًا/عموليًا لاحقًا).

### الاختبارات (4.1)
إنشاء طلب مُساعد بقناة وإسناد؛ لقطات محفوظة؛ تعديل ضمن الحدود = auto؛ تحت min/التكلفة بلا صلاحية = pending (يمنع؟ لا — يُنشأ الطلب draft مع تعديل معلّق)؛ مع صلاحية = auto؛ اعتماد/رفض المشرف؛ منع غير المخوّل؛ البحث/الإنشاء بالهاتف؛ عميل محظور.

---

## الدفعات اللاحقة (تصميم مرجعي — تُفصَّل عند اعتمادها)

### ✅ 4.2 — عمولات الموظفين وأرباح المسوّقين (المتطلّبان 3، 4) — مكتملة
> **مُنفَّذ (as-built):** وحدة `app/Modules/Commissions`. اعتُمد **دفتر موحّد واحد** `commission_entries` (بحقل `earner_type` = `sales`/`affiliate`) بدلًا من جدولين منفصلين — يبسّط الأسبقية والاعتماد/الصرف والاستعلام مع الحفاظ على كل الضمانات.
- **الجداول (المُنفَّذة):**
  - `commission_rules` — نطاقات: `user_id`/`campaign`/`product_id`/`category_id`/`branch_id`/`role` + `period_start/end`؛ طرق `percent`/`fixed`/`margin`؛ `rate` decimal(8,6)/`amount` decimal(15,2)؛ `priority`؛ `is_active`؛ softDeletes. الافتراضي **1%** ثابتًا في الخدمة عند غياب قاعدة.
  - `commission_entries` — **دفتر append-only غير قابل للتعديل**: `entry_type` (`accrual`/`adjustment`/`reversal`)، `basis`، `rate`، `amount` **موقّع**، `wholesale_cost_snapshot`، `rule_snapshot` (json)، `state`، `reverses_entry_id`/`adjusts_entry_id`، `settlement_reference`. حارس نموذجي يرمي `RuntimeException` عند تعديل حقل مالي. **بلا soft-delete** (عكس لا حذف).
  - `commission_transitions` — سجل انتقالات الحالة (append-only: from/to/actor/reference/note).
  - `commission_payouts` + `commission_payout_entries` — مع **قيد فريد `uniq_entry_paid_once`** يمنع دفع البند مرتين على مستوى قاعدة البيانات.
  - **الرصيد مُشتقّ من الدفتر** (`statement()`) لا عمود مصدر.
- **الحالات (المُنفَّذة):** `pending → eligible → approved → paid` (+ `reversed`/`cancelled`؛ التسوية تُمثَّل بقيد `adjustment` جديد) بانتقالات مُتحقَّقة (`TRANSITIONS`).
- **القواعد (المُنفَّذة):** أسبقية حتمية عبر `ruleScorePriority` (موظف>حملة>منتج/فئة>فرع>دور>عام)؛ أرباح المسوّق = `max((unit_price − wholesale_cost_snapshot) × qty, 0)`؛ الاستحقاق `eligible` **فقط بعد التسوية** (4.6) عبر `markEligibleForOrder`؛ إعادة الحساب للمرتجع الجزئي (`adjustForReturn` تناسبيًا) والعكس الكامل (`reverseForOrder`) بقيود جديدة دون مسّ التاريخ.
- **الأحداث (المُنفَّذة):** الاستحقاق عبر مستمع `AccrueCommissionsOnDelivery` على `OrderDelivered` (idempotent). أحداث `…BecameEligible`/`Approved`/`Paid`/`Reversed` نقاط امتداد تُفصَّل عند ربطها بالتسوية (4.6) والإشعارات.
- **الصلاحيات/الأدوار:** `commissions.{view_own,view_team,rules.manage,approve,payout,audit.view}`؛ `finance`/`sales_supervisor`.
- **الواجهات:** API `commissions[/statement|approve|payout]` + `apiResource commissions/rules`؛ لوحة إدارة RTL. **15 اختبارًا ناجحًا.**

### 4.3 — عمليات التوصيل وتكامل المزوّد (المتطلّبان 5، 6)
- **إعادة استخدام** طبقة الشحن (2.7): `DeliveryProviderInterface` + جدول ربط المزوّدين. **لا مزوّد مثبّت**.
- **الجداول:** توسعة `shipments` بـ external ref/حالة مزوّد؛ `shipment_provider_events` (payloads + سجلّ مزامنة — تخزين آمن)؛ `delivery_exceptions` (نوع من القائمة؛ owner, attempts, last_action, next_follow_up, sla/escalation, employee_note, provider_note, resolution). **حالات قانونية داخلية + خريطة حالة المزوّد**. عمليات: delivery/exchange/return-pickup/replacement.
- **Webhook idempotent:** مفتاح فريد `(provider, external_id, event_id)` يمنع التكرار؛ مزامنة مجدولة عبر مهمّة.

### 4.4 — المرتجعات والاستبدال RMA (المتطلّبان 7، 8)
- **الجداول:** `return_requests` (مصدر: customer/sales/warehouse/provider؛ نوع: return/exchange؛ حالة `return_request → approved → received → inspected → completed` + `rejected`)؛ `return_request_items` (بند/كمية/نوع الاستبدال/فرق السعر/مالك الرسوم)؛ الفحص (`inspection`): تصنيفات (sellable/open_box/repackage/damaged/broken/missing_parts/wrong_item/quarantine) → توجيه المخزون (on_hand/damaged/quarantine/provider_claim/internal). **لا تعديل غير رسمي للطلب الأصلي** (BR-RET-01). موافقات (مشرف/مستودع/مالية/عمليات). أنواع الاستبدال (نفس/أعلى بتحصيل/أقل باسترداد أو رصيد، جزئي، pickup+replacement متزامن/لاحق). الأثر العكسي عبر Inventory/Accounting القائمين (BR-RET-05).

### 4.5 — مطالبات التوصيل (المتطلّب 10)
- **الجداول:** `delivery_claims` (provider, shipment ref, order, item/qty, cost, claimed, accepted, evidence/attachments, status)؛ حالات: `draft → submitted → under_review → accepted`/`partially_accepted`/`rejected → paid → closed`؛ `delivery_claim_payments`.

### 4.6 — التسويات والمطابقة (المتطلّب 11)
- **الجداول:** `delivery_settlements` (بيان تسوية المزوّد: COD محصّل، رسوم، surcharges، مرتجعات، مطالبات مدفوعة، خصومات، محتجز، صافي)؛ `settlement_lines` (لكل طلب/شحنة)؛ مطابقة + كشف تباين. **عند نجاح المطابقة:** تعليم الطلبات `settled` → تفعيل استحقاق العمولات/الأرباح (`eligible`) → **قيود محاسبية عبر AccountingService القائم** (ADR-029). تسويات جزئية وأرصدة غير محلولة.
- **رسوم التوصيل (المتطلّب 9):** مكوّنات منفصلة (base/remote/oversize/overweight/COD/exchange/return/retry/discount/total) + (provider_quoted/customer_charged/provider_actual/owner) — تُخزَّن على الشحنة/التسوية.

### 4.7 — التقارير (المتطلّب 12)
مبيعات/محصّل الموظف، حالات العمولة، نجاح/رفض/استثناء التوصيل، مبيعات/أرباح/إرجاع المسوّق، أداء القنوات والمزوّدين، زمن التوصيل، تقادم الاستثناءات، قيمة التالف/المفقود، المطالبات، تباينات التسوية، المرتجعات حسب السبب/الموظف/المنتج/الفرع/المزوّد — كلّها **مشتقّة من الدفاتر/الجداول** (لا إدخال يدوي).

---

## التناقضات المانعة والمتطلّبات المسبقة

**لا يوجد تناقض مانع.** ملاحظات:
1. **كيان المسوّق/المحفظة** غير مُنفّذ بعد (لا وحدة Affiliate). 4.1 يكتفي بإسناد `affiliate_id` (FK users بدور `affiliate`)؛ كيان المحفظة/الدفتر في 4.2 — لا يعيق 4.1.
2. **`orders.customer_id` بلا FK صارم** (مؤجّل منذ 2.6/2.10) — متوافق مع لقطات الطلب؛ لا تغيير.
3. **نافذة الإرجاع النهائية وأثر الاستبدال على العمولة ورصيد المسوّق السالب** (أسئلة مفتوحة في BUSINESS_RULES §297-300) — تخصّ 4.2/4.4، تُحسم عند اعتماد تلك الدفعات؛ لا تعيق 4.1.
4. لا إعادة تصميم لأي وحدة مكتملة؛ كل التغييرات **إضافية** (أعمدة/جداول جديدة + توسعة مفردات نصّية).

</div>
