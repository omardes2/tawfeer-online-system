<div dir="rtl">

# مصفوفة الإشعارات — Notification Matrix

**المشروع:** Tawfeer Online — ERP + CRM + E‑Commerce + Inventory + Purchasing + Accounting + Affiliate + Omnichannel
**الحالة:** تصميم — القنوات الأساسية في Phase 2، القنوات الخارجية (WhatsApp/SMS/Push) في Phase 3+
**المصادر الملزِمة:** [`ARCHITECTURE.md`](../ARCHITECTURE.md) · [`DECISIONS.md`](./DECISIONS.md) · [`FOUNDATION_REPORT.md`](./FOUNDATION_REPORT.md) · [`APPROVAL_WORKFLOWS.md`](./APPROVAL_WORKFLOWS.md)

> هذا المستند يعرّف **من يتلقّى أي إشعار، عبر أي قناة، بأي أولوية، وبأي قالب**. متسق مع مفاتيح الحالات القانونية في `DECISIONS.md`، ومع سير الموافقات في `APPROVAL_WORKFLOWS.md`، ومع الأدوار في `FOUNDATION_REPORT.md`. كل إشعار مرتبط بحدث دومين (ADR-018)، ويُرسَل من مستمع في **طابور** (المبدأ 11/13).

---

## 1. القنوات (Channels)

| القناة (key) | الوصف | خلف عقد تكامل؟ (المبدأ 13) | المرحلة |
|-------------|-------|:--------------------------:|:------:|
| `in_app` | إشعار داخل النظام (جرس/قائمة) عبر Laravel Notifications | — (داخلي) | **Phase 2** |
| `email` | بريد إلكتروني (قوالب Blade/Markdown) | Mailer Driver | **Phase 2** |
| `sms` | رسالة نصية قصيرة | `MessagingContract` + SMS Driver | Phase 3 |
| `whatsapp` | واتساب/Omnichannel | `MessagingContract` + `WhatsAppCloudDriver` | Phase 3+ |
| `push` | إشعار دفع (تطبيق موبايل) | Push Driver | مستقبلي (بعد الموبايل) |

- كل مزوّد خارجي خلف **عقد (Interface) + Driver** يُحقَن عبر Container (المبدأ 13، ADR-019)؛ تبديل المزوّد = Driver جديد + تغيير إعداد، دون لمس منطق الإشعار.
- تفضيلات القناة لكل مستخدم/دور تُقرأ من الإعدادات (المبدأ 9): `notifications.channels.*`.

---

## 2. الأولويات (Priorities)

| الأولوية | المعنى | سلوك التسليم |
|---------|--------|--------------|
| `critical` | حرِج/مالي/أمني | فوري، يتجاوز ساعات الهدوء، كل القنوات المفعّلة |
| `high` | يتطلّب إجراءً قريبًا | فوري، يحترم ساعات الهدوء عبر القنوات المزعجة |
| `normal` | معلوماتي مهم | فوري in‑app، بريد مجمّع محتمل |
| `low` | معلوماتي/ملخّصات | مجمّع (batched)، ضمن أوقات العمل |

---

## 3. المصفوفة الرئيسية (Master Matrix)

الأعمدة: الحدث/المُطلِق · المستلمون (أدوار) · القنوات · الأولوية · مفتاح القالب · المرحلة.
مفاتيح الحالات مطابقة لـ `DECISIONS.md`. استلام كل دور محكوم بقواعد النطاق في §4.

### 3.1 دورة حياة الطلب (Order Lifecycle — ADR-010)

| الحدث/المُطلِق | المستلمون | القنوات | الأولوية | مفتاح القالب | المرحلة |
|----------------|-----------|---------|:-------:|--------------|:------:|
| طلب جديد (`new`) | `sales` (المُسنَد), `manager` | in_app, email | high | `order.created` | 2 |
| `awaiting_confirmation` | `customer`, `sales` | in_app, sms, whatsapp | normal | `order.awaiting_confirmation` | 3 |
| `confirmed` | `customer`, `warehouse` | in_app, email, whatsapp | normal | `order.confirmed` | 3 |
| `stock_reserved` | `warehouse` | in_app | low | `order.stock_reserved` | 3 |
| `ready_to_ship` | `warehouse`, `sales` | in_app | normal | `order.ready_to_ship` | 3 |
| `shipped` (خصم نهائي) | `customer`, `sales` | in_app, sms, whatsapp | high | `order.shipped` | 3 |
| `out_for_delivery` | `customer` | sms, whatsapp | high | `order.out_for_delivery` | 3 |
| `delivered` (اعتراف إيراد ADR-010a) | `customer`, `sales`, `affiliate` | in_app, whatsapp | normal | `order.delivered` | 3 |
| `delayed` / `customer_unavailable` | `sales` (المُسنَد), `manager` | in_app, email | high | `order.delayed` | 3 |
| `delivery_failed` | `sales`, `manager`, `warehouse` | in_app, email | high | `order.delivery_failed` | 3 |
| `cancelled` | `customer`, `sales`, `manager` | in_app, email | high | `order.cancelled` | 3 |

### 3.2 المرتجعات والمبالغ المستردة (Returns / Refunds — ADR-011)

| الحدث | المستلمون | القنوات | الأولوية | مفتاح القالب | المرحلة |
|------|-----------|---------|:-------:|--------------|:------:|
| طلب إرجاع (`return_request`) | `manager`, `sales` (المُسنَد) | in_app, email | high | `return.requested` | 3 |
| اعتماد/رفض الإرجاع (`approved`/`rejected`) | `customer`, `sales` | in_app, whatsapp | normal | `return.decision` | 3 |
| استلام/فحص المرتجع (`received`/`inspected`) | `warehouse`, `manager` | in_app | normal | `return.inspected` | 3 |
| استرداد/إشعار دائن (`refund`/`credit_note`) | `customer`, `accountant` | in_app, email, whatsapp | high | `refund.issued` | 3 |
| `returned` / `partially_returned` / `exchanged` | `customer`, `sales`, `affiliate` | in_app | normal | `return.completed` | 3 |

### 3.3 المخزون والشراء (Inventory & Purchasing)

| الحدث | المستلمون | القنوات | الأولوية | مفتاح القالب | المرحلة |
|------|-----------|---------|:-------:|--------------|:------:|
| مخزون منخفض (حد إعادة الطلب) | `warehouse`, `manager` | in_app, email | high | `inventory.low_stock` | 2 |
| نفاد المخزون (`available = 0`) | `warehouse`, `manager`, `sales` | in_app, email | critical | `inventory.out_of_stock` | 2 |
| طلب اعتماد أمر شراء (`pending_approval`) | `manager`, `admin` | in_app, email | high | `po.approval_requested` | 2 |
| اعتماد/رفض PO (`approved`/`cancelled`) | مقدِّم الطلب (`warehouse`), `manager` | in_app, email | normal | `po.decision` | 2 |
| استلام بضاعة (`purchase_in`, جزئي/كامل) | `warehouse`, `accountant`, `manager` | in_app | normal | `po.goods_received` | 2 |
| اقتراب/تجاوز استحقاق مورد (due date) | `accountant`, `manager` | in_app, email | high | `supplier.payment_due` | 4 |
| اعتماد فاتورة مورد (`approved`) | `accountant`, `manager` | in_app | normal | `supplier.invoice_approved` | 4 |
| تسوية مخزون/جرد تحتاج اعتماد | `manager`, `admin` | in_app, email | high | `inventory.adjustment_requested` | 2 |
| تحويل مستودعي (dispatch/receive) | `warehouse` (الطرفان), `manager` | in_app | normal | `inventory.transfer_update` | 2 |

### 3.4 العمولات والمسوّقون (Commissions & Affiliate — ADR-012)

| الحدث | المستلمون | القنوات | الأولوية | مفتاح القالب | المرحلة |
|------|-----------|---------|:-------:|--------------|:------:|
| عمولة مُستحقّة (`earned`, عند `delivered`) | `affiliate` | in_app, whatsapp | normal | `commission.earned` | 3 |
| اعتماد العمولة (`approved`) | `affiliate` | in_app | normal | `commission.approved` | 3 |
| عكس عمولة (`reversed`, إرجاع) | `affiliate`, `accountant` | in_app, email | high | `commission.reversed` | 3 |
| طلب سحب (`payout_request`) | `manager`, `accountant` | in_app, email | high | `payout.requested` | 3 |
| اعتماد/رفض السحب | `affiliate` | in_app, whatsapp | high | `payout.decision` | 3 |
| صرف العمولة (`paid`) | `affiliate` | in_app, email, whatsapp | high | `payout.paid` | 3 |

### 3.5 الموافقات العامة (Approval Framework — APPROVAL_WORKFLOWS §1)

| الحدث | المستلمون | القنوات | الأولوية | مفتاح القالب | المرحلة |
|------|-----------|---------|:-------:|--------------|:------:|
| طلب اعتماد جديد (أي نوع) | المعتمِد المختص (`manager`/`admin`/`accountant`) | in_app, email | high | `approval.requested` | 2 |
| اعتماد مُنِح (`approved`) | مقدِّم الطلب | in_app | normal | `approval.granted` | 2 |
| اعتماد مرفوض (`rejected`) | مقدِّم الطلب | in_app, email | high | `approval.rejected` | 2 |
| طلب خصم/تجاوز أدنى سعر (ADR-006a) | `manager`, `admin` | in_app, email | high | `approval.discount_requested` | 2 |

### 3.6 CRM والمخاطر والأداء (CRM, Risk & Performance)

| الحدث | المستلمون | القنوات | الأولوية | مفتاح القالب | المرحلة |
|------|-----------|---------|:-------:|--------------|:------:|
| تحذير عميل عالي المخاطر (رفض متكرر/ديون) | `sales` (المُسنَد), `manager` | in_app, email | high | `customer.high_risk` | 3 |
| تجاوز زمن استجابة الموظف (SLA) | الموظف, `manager` | in_app, email | high | `employee.response_sla` | 3 |
| إسناد عميل/طلب جديد لموظف | الموظف المُسنَد (`sales`) | in_app | normal | `assignment.new` | 3 |

### 3.7 الملخّصات الدورية (Digests / Summaries)

| الحدث | المستلمون | القنوات | الأولوية | مفتاح القالب | المرحلة |
|------|-----------|---------|:-------:|--------------|:------:|
| ملخّص يومي (مبيعات/طلبات/مخزون منخفض) | `manager`, `admin` | email, in_app | low | `digest.daily` | 2 |
| ملخّص أسبوعي (أداء الفريق/العمولات) | `manager`, `admin` | email | low | `digest.weekly` | 3 |
| ملخّص شهري (ربحية — محجوب بلا `pricing.view_cost`) | `admin`, `manager`, `accountant` | email | low | `digest.monthly` | 3 |
| كشف عمولة شهري للمسوّق | `affiliate` | email, in_app | low | `digest.affiliate_monthly` | 3 |

---

## 4. قواعد المستلمين (Recipient Rules) — RBAC & Data Scope

كل توجيه إشعار يحترم الصلاحيات (المبدأ 12) ونطاق البيانات (FOUNDATION_REPORT §0 من `APPROVAL_WORKFLOWS.md`):

| الدور | يتلقّى إشعارات عن |
|------|-------------------|
| `admin` | كل الأحداث عبر كل الفروع (قابل للكتم اختياريًا) |
| `manager` | أحداث فرعه/فروعه: اعتمادات، تنبيهات مخزون/مالية، تصعيدات |
| `sales` | **فقط** طلباته/عملاؤه المُسنَدون (لا يرى طلبات غيره) |
| `warehouse` | **فقط** مستودعاته المُسنَدة: استلام، تحويل، مخزون، تجهيز |
| `accountant` | الفواتير، المدفوعات، الاستردادات، العمولات، استحقاقات الموردين |
| `affiliate` | **فقط** عمولاته وطلباته المُحالة وسحوباته |
| `customer` | **فقط** طلباته ومرتجعاته واستردادات حسابه |

- إشعارات التكلفة/الربحية (ADR-013) لا تُرسَل لمن لا يملك `pricing.view_cost`؛ الملخّص الشهري الربحي محجوب بلا الصلاحية.
- «المُسنَد» يعني علاقة إسناد فعلية (order.assigned_to / customer.owner / warehouse staff) لا مجرد الدور.

---

## 5. القوالب والتوطين (Templates & Localization)

- كل إشعار له **مفتاح قالب ثابت** (`template key`) يعتمد عليه الكود؛ النصوص **قابلة للترجمة**: **عربي أساسي (ar)**، إنجليزي ثانوي (en) — عبر `lang/{ar,en}` (FOUNDATION_REPORT §3).
- القوالب لا تحمل حالة مضمّنة؛ اسم الحالة يُقرأ من جداول الحالات القابلة للإدارة (المبدأ 10، ADR-017) ويُترجم عبر `name` القابل للترجمة، بينما المنطق يعتمد `key` الثابت.
- المتغيّرات (اسم العميل، رقم الطلب `ORD-{YYYY}-{seq}`، المبلغ) تُمرَّر للقالب؛ المبالغ `decimal` منسّقة بالعملة الأساسية من الإعدادات (ADR-001).
- تفضيل لغة المستلم من ملفه الشخصي؛ الافتراضي عربي RTL.

---

## 6. ساعات الهدوء والتجميع وإزالة التكرار (Quiet Hours / Batching / De‑duplication)

> نيّة تصميمية، لا تفاصيل تنفيذ.

| الاعتبار | التصميم |
|---------|--------|
| **ساعات الهدوء (Quiet Hours)** | نافذة من الإعدادات (`notifications.quiet_hours`) تؤجّل `low`/`normal` على القنوات المزعجة (sms/whatsapp/push)؛ `critical` يتجاوزها |
| **التجميع (Batching)** | إشعارات `low` (الملخّصات، المخزون المنخفض المتكرر) تُجمَّع دوريًا بدل إرسال فوري لكل حدث |
| **إزالة التكرار (De‑duplication)** | مفتاح تكرار (event + entity + recipient + window) يمنع تكرار نفس الإشعار خلال نافذة زمنية (مثلاً تنبيه مخزون منخفض واحد/يوم للصنف) |
| **التفضيلات (Preferences)** | لكل مستخدم تفعيل/تعطيل قناة لكل فئة (`notifications.channels.*`)؛ `critical` لا يُعطَّل |
| **التسليم غير المتزامن** | كل الإشعارات عبر **طوابير** (ADR-018، المبدأ 11)؛ لا منطق أعمال حرج داخل مستمع الإشعار |
| **التتبّع** | تسليم الإشعارات الحسّاسة (مالية) قابل للتدقيق (المبدأ 8) — سُجِّل ماذا أُرسل ولمن ومتى |

---

## 7. الاتساق مع المستندات الأخرى (Consistency)

- مفاتيح الحالات (`confirmed`, `shipped`, `delivered`, `returned`, `earned`, `paid`, `pending_approval`, `approved`...) مطابقة حرفيًا لـ `DECISIONS.md`.
- كل حدث اعتماد/انتقال هنا يقابله صف في `APPROVAL_WORKFLOWS.md` (نفس الأدوار والصلاحيات).
- الأدوار السبعة مطابقة لـ `FOUNDATION_REPORT.md §5`.
- كل حدث مصدره **حدث دومين** سيُوثَّق في `EVENTS.md` (مخطّط، ADR-018) — هذا المستند يستهلكه لا يعرّفه.

</div>
