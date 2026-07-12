<div dir="rtl">

# كتالوج أحداث الدومين (Domain Events Catalog) — Tawfeer Online

> **الحالة:** وثيقة تصميم. لا يوجد **أي** مستمع (Listener) أو مهمة مطابورة (Queued Job) منفّذ فعليًا الآن؛ هذا الكتالوج يُلزم التنفيذ في المراحل 2–5.
>
> **المصادر المُلزِمة:** يلتزم هذا المستند بـ [`../ARCHITECTURE.md`](../ARCHITECTURE.md) (المبادئ الـ14) و[`DECISIONS.md`](DECISIONS.md) (المصدر الوحيد للحقيقة). أي تعارض يُحسم لصالح `DECISIONS.md`.
>
> **قواعد أساسية محكومة بالمبادئ/القرارات:**
> - **الذرّية (المبدأ 7):** كل تغييرات قاعدة البيانات لأي حدث تتم داخل `DB::transaction()` واحدة مع قفل صفوف المخزون/الخزائن (`lockForUpdate`) عند اللزوم.
> - **الأحداث والطوابير (ADR-018 / المبدأ 11، 13):** الحدث يُطلَق **بعد نجاح المعاملة** (`afterCommit`). المنطق الحرج (المخزون/المالية) يقع **داخل** المعاملة، لا داخل مستمع غير متزامن. المستمعون الثقيلون (إشعارات، تقارير، فهرسة، محاسبة) **async** عبر الطوابير.
> - **التدقيق (المبدأ 8 / ADR-020):** كل حدث حسّاس يُدوَّن آليًا في `audit_logs` عبر تريتة `Auditable` (من فعل، ماذا، متى، من أين، القيمة قبل/بعد). الدفاتر (`inventory_ledger`) append-only.
> - **العكس لا الحذف (ADR-011 / ADR-016):** لا تُحذف حركة مالية/مخزونية معتمدة؛ يُنشأ قيد/حركة عكسية.
> - **الحالات كبيانات (ADR-017 / المبدأ 10):** كل مفاتيح الحالات أدناه من المفردات القانونية في `DECISIONS.md` حصرًا.
> - **الأثر المحاسبي (ADR-016):** «Hook محاسبي» أدناه يعني أن الحدث مُصمَّم لينتج قيد قيد مزدوج في Phase 5؛ لا تنفيذ محاسبي قبلها.
> - **الإشعارات:** المراجع إلى `NOTIFICATION_MATRIX` و`APPROVAL_WORKFLOWS` هي **مراجع أمامية** (وثائق مُخطَّطة لم تُكتب بعد)؛ القنوات عبر عقد `MessagingContract` (المبدأ 13): WhatsApp / SMS / Email / إشعار داخل التطبيق (database).

---

## اصطلاح التوثيق لكل حدث

لكل حدث: **المُطلِق** · **الشروط المسبقة** · **تغييرات قاعدة البيانات** · **الآثار الجانبية** · **الإشعارات** · **متطلبات التدقيق** · **المستمعون والمهام المطابورة (Sync/Async)**.

**دلالة الطور (Phase):** المرحلة التي يُفعَّل فيها الحدث فعليًا (Phase 2 = كتالوج/مخزون · Phase 3 = طلبات · Phase 4 = فواتير/مدفوعات مورّدين · Phase 5 = محاسبة قيد مزدوج).

---

# 1) وحدة CRM (العملاء)

## 1.1 `CustomerCreated`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | إنشاء سجل عميل جديد (تسجيل ذاتي من المتجر، أو إنشاء يدوي من موظف Sales، أو تحويل Lead ← عميل). |
| **الشروط المسبقة** | بيانات صالحة (هاتف/بريد فريد حيث يلزم)؛ صلاحية الإنشاء (المبدأ 12) للإنشاء اليدوي. |
| **تغييرات قاعدة البيانات** | `INSERT` في `customers` (+ ربط `user_id` إن وُجد حساب). قد يُنشأ عنوان افتراضي في `addresses`. داخل معاملة (المبدأ 7). |
| **الآثار الجانبية** | تهيئة `loyalty_points=0`؛ إسناد `branch_id` (الفرع الافتراضي — المبدأ 1)؛ تعليم مصدر العميل (channel/source). |
| **الإشعارات** | ترحيب للعميل (Email/WhatsApp حسب `NOTIFICATION_MATRIX`)؛ إشعار داخلي لموظف Sales المسند. |
| **متطلبات التدقيق** | `audit_logs`: action=`created`, auditable=Customer, new_values، user_id، ip، user_agent (المبدأ 8). |
| **المستمعون والمهام (Sync/Async)** | **Async:** `SendWelcomeMessageJob`، `SyncToMessagingInboxJob` (ربط بالصندوق الموحّد)، `IndexCustomerForSearchJob`. لا منطق حرج غير متزامن (ADR-018). |
| **الطور** | Phase 3 (تُستهلك مبكرًا في CRM). |

## 1.2 `CustomerUpdated`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | تعديل بيانات عميل (هاتف/بريد/عنوان/تصنيف/حالة نشاط). |
| **الشروط المسبقة** | صلاحية التعديل؛ العميل غير محذوف ناعمًا (المبدأ 5). |
| **تغييرات قاعدة البيانات** | `UPDATE customers` و/أو `addresses` داخل معاملة. |
| **الآثار الجانبية** | إبطال أي كاش مشتق للعميل؛ إعادة مزامنة جهة الاتصال في الصندوق الموحّد عند تغيّر الهاتف. |
| **الإشعارات** | تأكيد تغيير بيانات حسّاسة (بريد/هاتف) للعميل عند اللزوم. |
| **متطلبات التدقيق** | `audit_logs`: action=`updated`، `old_values`/`new_values` بالحقول المتغيّرة فقط (المبدأ 8). |
| **المستمعون والمهام** | **Async:** `ReindexCustomerJob`، `ResyncInboxContactJob`. |
| **الطور** | Phase 3. |

---

# 2) وحدة Catalog (الكتالوج)

## 2.1 `ProductCreated`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | إنشاء منتج (simple/variable) مع متغيّراته. |
| **الشروط المسبقة** | صلاحية `catalog.*`؛ `sku` فريد (ADR-004 — فرادة عامة الآن مع جاهزية الترقية)؛ الفئة موجودة. |
| **تغييرات قاعدة البيانات** | `INSERT` في `products`، `product_variants`، وربما `product_images`/`product_tags`/`product_attribute_values`. تُنشأ صفوف `inventory_stocks` (رصيد 0) لكل (متغيّر × مستودع) عند اللزوم. معاملة واحدة. |
| **الآثار الجانبية** | توليد `uuid` (المبدأ 4)؛ تهيئة طبقات الأسعار (ADR-006)؛ `branch_id` افتراضي. |
| **الإشعارات** | لا إشعار خارجي افتراضي؛ إشعار داخلي اختياري لفريق الكتالوج/المخزون. |
| **متطلبات التدقيق** | `audit_logs`: created على Product والمتغيّرات (ADR-020 — المنتجات ضمن الكيانات الحسّاسة). |
| **المستمعون والمهام** | **Async:** `IndexProductForSearchJob`، `GenerateThumbnailsJob`، `WarmCatalogCacheJob`. |
| **الطور** | Phase 2. |

## 2.2 `ProductPriceChanged`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | تغيير أي حقل سعر مُدار (ADR-006): `retail_price`, `wholesale_price`, `marketer_price`, `min_price`, `promo_price`. |
| **الشروط المسبقة** | صلاحية التسعير؛ **البيع تحت `min_price` ممنوع** إلا بصلاحية `pricing.override_min_price` **وبموافقة** (ADR-006a) — أي محاولة تجاوز تُسجَّل. |
| **تغييرات قاعدة البيانات** | `UPDATE` حقول السعر في `product_variants` (أو جدول أسعار)؛ معاملة واحدة. لا يمسّ التكلفة/WAC (تُدار بالمخزون فقط). |
| **الآثار الجانبية** | إبطال كاش الأسعار/الكتالوج؛ تنبيه إن نزل السعر تحت `target_margin` (تنبيه لا منع — ADR-006). |
| **الإشعارات** | تنبيه إداري عند تجاوز `min_price` أو هبوط الهامش تحت المستهدف؛ تنبيه المسوّقين عند تغيّر `marketer_price` (يؤثّر على أساس العمولة — ADR-012). |
| **متطلبات التدقيق** | **إلزامي دقيق:** `old_values`/`new_values` لكل حقل سعر + من غيّر ومتى؛ ومحاولات تجاوز `min_price` تُسجَّل صراحةً (ADR-006a / ADR-020). |
| **المستمعون والمهام** | **Sync (داخل المعاملة):** فحص حد `min_price` والموافقة. **Async:** `InvalidatePriceCacheJob`، `NotifyMarketersPriceChangeJob`، `MarginAlertJob`. |
| **الطور** | Phase 2. |

---

# 3) وحدة Inventory (المخزون والمستودعات)

> **مرجع دلاء المخزون (ADR-007):** `on_hand`, `reserved`, `available = on_hand − reserved`, `damaged`, `returned_pending`, `in_transit`. **منع السالب (ADR-007a).** كل حركة تُنتج قيدًا في `inventory_ledger` (append-only، ADR-008) وسطرًا في `inventory_movements` بأحد أنواع الحركة القانونية (`purchase_in`, `sale_out`, `transfer_out`, `transfer_in`, `adjustment_in`, `adjustment_out`, `reserve`, `release`, `return_in`, `damage_out`).

## 3.1 `LowStockDetected`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | هبوط `available` لـ(متغيّر × مستودع) إلى/تحت `reorder_level` عقب أي حركة صادرة/حجز (`sale_out`, `reserve`)، أو عبر فحص مجدول (يرتبط بأتمتة *low stock alerts* في `AUTOMATIONS.md`). |
| **الشروط المسبقة** | `reorder_level` مُعرّف > 0؛ المتغيّر نشط. |
| **تغييرات قاعدة البيانات** | لا تغيير بيانات مباشر (حدث اشتقاقي). قد يُسجَّل «تنبيه» في جدول تنبيهات/إشعارات. |
| **الآثار الجانبية** | ترشيح اقتراح إعادة طلب (يغذّي أتمتة *inventory reorder suggestions*). منع تكرار الإشعار خلال نافذة (debounce). |
| **الإشعارات** | تنبيه لأمين المستودع (Warehouse) والمدير (Manager) عبر إشعار داخلي/Email (`NOTIFICATION_MATRIX`). |
| **متطلبات التدقيق** | تسجيل خفيف (لقطة الكمية والحد وقت الكشف) — لا يُعدّل دفاتر. |
| **المستمعون والمهام** | **Async:** `NotifyLowStockJob`، `SuggestReorderJob`. |
| **الطور** | Phase 2. |

## 3.2 `InventoryReserved`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | تأكيد الطلب والانتقال إلى `stock_reserved` (ADR-009، الحجز الافتراضي `inventory.reserve_on=confirmed`). يُصمَّم في Phase 2، يُنفَّذ في Phase 3. |
| **الشروط المسبقة** | `available ≥ qty` لكل بند (إلا إذا فُعّل `inventory.allow_negative` — ADR-007a)؛ قفل صف المخزون (المبدأ 7). |
| **تغييرات قاعدة البيانات** | `reserved += qty` في `inventory_stocks` (لا يمسّ `on_hand`)؛ `INSERT` في `stock_reservations`؛ `inventory_movements` نوع `reserve`؛ قيد في `inventory_ledger`. معاملة واحدة مع `lockForUpdate`. |
| **الآثار الجانبية** | إعادة حساب `available` المشتق؛ قد يُطلق `LowStockDetected`. |
| **الإشعارات** | داخلية للمستودع عند حجز يقارب النفاد؛ لا إشعار مالي (لا COGS بعد — ADR-005). |
| **متطلبات التدقيق** | `inventory_ledger` (append-only) + `audit_logs` على تغيّر الدلاء (ADR-020). |
| **المستمعون والمهام** | **Sync (داخل المعاملة):** التحقق من التوفّر والقفل والحجز. **Async:** `NotifyReservationJob`. |
| **الطور** | Phase 3 (بنيته في Phase 2). |

## 3.3 `InventoryReleased`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | إلغاء الطلب **قبل الشحن**، أو انتهاء صلاحية الحجز، أو تعديل كمية بند محجوز (ADR-009). |
| **الشروط المسبقة** | وجود حجز فعّال في `stock_reservations`. |
| **تغييرات قاعدة البيانات** | `reserved -= qty`؛ إغلاق/حذف منطقي لصف `stock_reservations`؛ `inventory_movements` نوع `release`؛ قيد `inventory_ledger`. معاملة واحدة مع قفل. |
| **الآثار الجانبية** | ارتفاع `available`؛ لا أثر على `on_hand`. |
| **الإشعارات** | داخلية عند اللزوم؛ لا إشعار عميل افتراضي. |
| **متطلبات التدقيق** | قيد `inventory_ledger` + `audit_logs` مع السبب (إلغاء/انتهاء/تعديل). |
| **المستمعون والمهام** | **Sync:** تحرير الحجز. **Async:** `NotifyReleaseJob` (اختياري). |
| **الطور** | Phase 3. |

## 3.4 `InventoryAdjusted`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | تسوية جرد يدوية (`StockAdjustments`): زيادة/نقص، أو نقل إلى/من `damaged`. رقم `ADJ-{YYYY}-{seq}` (ADR-002). |
| **الشروط المسبقة** | صلاحية الجرد؛ منع سالب (ADR-007a)؛ قفل الصف. قد تتطلب موافقة (`APPROVAL_WORKFLOWS`). |
| **تغييرات قاعدة البيانات** | تعديل الدلو المعني في `inventory_stocks` (`on_hand`/`damaged`)؛ `inventory_movements` نوع `adjustment_in` أو `adjustment_out`؛ قيد `inventory_ledger` (يُحدِّث الرصيد الجاري و**WAC** عند تغيّر الكمية — ADR-005/008). معاملة واحدة. |
| **الآثار الجانبية** | **إعادة حساب WAC** عند زيادة بتكلفة؛ **Hook محاسبي (ADR-016):** فرق الجرد ← قيد لاحق (خسارة/ربح جرد). |
| **الإشعارات** | تنبيه المدير/المحاسب على تسويات ذات قيمة أعلى من عتبة. |
| **متطلبات التدقيق** | **إلزامي:** `inventory_ledger` + `audit_logs` (السبب، الكمية قبل/بعد، التكلفة). ADR-020. |
| **المستمعون والمهام** | **Sync:** تطبيق التسوية + WAC. **Async:** `PostAdjustmentAccountingJob` (Phase 5)، `NotifyAdjustmentJob`. |
| **الطور** | Phase 2. |

## 3.5 `StockTransferred`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | تحويل بين مستودعين (`WarehouseTransfers`) — إصدار ثم استلام. رقم `TRF-{YYYY}-{seq}` (ADR-002). |
| **الشروط المسبقة** | `available ≥ qty` في المصدر (ADR-007a)؛ المستودعان نشطان؛ قفل صفوف الطرفين. |
| **تغييرات قاعدة البيانات** | عند الإصدار: `on_hand -= qty` بالمصدر و`in_transit += qty`؛ حركة `transfer_out`. عند الاستلام: `in_transit -= qty`، `on_hand += qty` بالوجهة؛ حركة `transfer_in`. قيود `inventory_ledger` للطرفين. معاملة لكل خطوة. |
| **الآثار الجانبية** | **نقل التكلفة/WAC** إلى مستودع الوجهة (ADR-005، حسب `inventory.costing_scope`)؛ قد يُطلق `LowStockDetected` بالمصدر. |
| **الإشعارات** | تنبيه أمين مستودع الوجهة بوصول تحويل؛ تنبيه المصدر عند تأخّر الاستلام. |
| **متطلبات التدقيق** | قيود `inventory_ledger` للطرفين + `audit_logs` (ADR-020 — التحويلات كيان حسّاس). |
| **المستمعون والمهام** | **Sync:** تحديث الدلاء وWAC. **Async:** `NotifyTransferInJob`، `TransferDelayCheckJob`. |
| **الطور** | Phase 2. |

---

# 4) وحدة Purchasing (المشتريات والموردون)

> **حالات أمر الشراء (قانونية):** `draft → pending_approval → approved → partially_received → received → closed` + `cancelled`. **فاتورة المورد:** `draft → pending_approval → approved → partially_paid → paid` + `cancelled`.

## 4.1 `PurchaseOrderCreated`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | إنشاء أمر شراء (`draft`). رقم `PO-{YYYY}-{seq}` مولّد داخل معاملة مع قفل (ADR-002). |
| **الشروط المسبقة** | صلاحية الشراء؛ مورد نشط؛ مستودع وجهة محدد؛ بنود بكميات/تكاليف صالحة (Decimal — ADR-001). |
| **تغييرات قاعدة البيانات** | `INSERT` في `purchase_orders` + `purchase_order_items`؛ حالة `draft`. **لا أثر على المخزون بعد** (الأثر عند الاستلام). |
| **الآثار الجانبية** | حساب إجمالي مبدئي؛ توليد `uuid` + الرقم المقروء. |
| **الإشعارات** | إشعار المعتمِد (Manager/Admin) عند رفعه إلى `pending_approval`. |
| **متطلبات التدقيق** | `audit_logs`: created على PurchaseOrder (ADR-020). |
| **المستمعون والمهام** | **Async:** `NotifyApproverJob` (عند طلب الاعتماد)، `RecalcPoTotalsJob`. |
| **الطور** | Phase 2 (اعتماد/استلام)، الفواتير Phase 4. |

## 4.2 `PurchaseOrderApproved` — *(حدث مُضاف، واضح الحاجة)*
| البند | التفصيل |
|------|---------|
| **المُطلِق** | انتقال أمر الشراء `pending_approval → approved` (`APPROVAL_WORKFLOWS`). |
| **الشروط المسبقة** | صلاحية الاعتماد؛ الحالة `pending_approval`. |
| **تغييرات قاعدة البيانات** | `UPDATE purchase_orders.status = approved` + وقت/معتمِد. معاملة واحدة. |
| **الآثار الجانبية** | فتح إمكانية الاستلام (GRN)؛ تجميد تعديلات البنود الجوهرية. |
| **الإشعارات** | إشعار المورد (اختياري عبر عقد الرسائل) وأمين المستودع لتوقّع الاستلام. |
| **متطلبات التدقيق** | `audit_logs`: تغيّر الحالة + المعتمِد (ADR-020). |
| **المستمعون والمهام** | **Async:** `NotifyWarehouseExpectedGoodsJob`. |
| **الطور** | Phase 2. |

## 4.3 `GoodsReceived`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | استلام بضاعة مقابل أمر شراء (`goods_receipts`/GRN)؛ يحرّك PO إلى `partially_received` أو `received`. |
| **الشروط المسبقة** | PO بحالة `approved` أو `partially_received`؛ كميات مستلمة ≤ المتبقّي؛ صلاحية الاستلام؛ قفل صفوف المخزون. |
| **تغييرات قاعدة البيانات** | `INSERT goods_receipts`؛ تحديث `received_quantity` في بنود PO؛ `on_hand += qty` بمستودع الوجهة؛ `inventory_movements` نوع `purchase_in`؛ قيد `inventory_ledger`؛ تحديث حالة PO. معاملة واحدة مع قفل (المبدأ 7). |
| **الآثار الجانبية** | **إعادة حساب WAC (ADR-005):** `WAC = (قيمة الحالي + التكلفة المُحمّلة للوارد) / (كمية الحالي + الوارد)`، مع توزيع **Landed Cost** (ADR-005). تحديث `cost_price` (آخر شراء). **Hook محاسبي (ADR-016):** استلام بضاعة ← قيد لاحق (مخزون مدين / ذمم موردين دائن). |
| **الإشعارات** | تأكيد استلام للمشتريات؛ تنبيه المحاسب لمطابقة الفاتورة (Phase 4). |
| **متطلبات التدقيق** | **إلزامي:** `inventory_ledger` (كمية + WAC بعد) + `audit_logs` على PO والمخزون (ADR-008/020). |
| **المستمعون والمهام** | **Sync (داخل المعاملة):** زيادة المخزون + WAC + تحديث PO. **Async:** `PostGoodsReceiptAccountingJob` (Phase 5)، `NotifyReceiptJob`، `CheckPoClosureJob`. |
| **الطور** | Phase 2 (المخزون/WAC)، المحاسبة Phase 5. |

## 4.4 `PurchaseInvoiceApproved`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | اعتماد فاتورة مورد `pending_approval → approved` (`supplier_invoices`). |
| **الشروط المسبقة** | صلاحية الاعتماد؛ مطابقة الفاتورة مع GRN/PO (three-way match موصى)؛ الحالة `pending_approval`. |
| **تغييرات قاعدة البيانات** | `UPDATE supplier_invoices.status = approved`؛ ربط بـ PO/GRN. معاملة واحدة. |
| **الآثار الجانبية** | إثبات التزام مالي تجاه المورد؛ تحديث تاريخ الاستحقاق (يغذّي أتمتة *supplier due-date reminders*). **Hook محاسبي (ADR-016):** إثبات ذمم دائنة (Phase 5). |
| **الإشعارات** | إشعار المحاسب/الخزينة بجدولة الدفع؛ تنبيه قرب الاستحقاق لاحقًا. |
| **متطلبات التدقيق** | `audit_logs`: تغيّر الحالة + المعتمِد + المبلغ (ADR-020). |
| **المستمعون والمهام** | **Async:** `PostSupplierInvoiceAccountingJob` (Phase 5)، `ScheduleDueDateReminderJob`. |
| **الطور** | Phase 4 (المحاسبة Phase 5). |

---

# 5) وحدة Orders (الطلبات)

> **دورة الحياة القانونية (ADR-010):** `draft → new → awaiting_contact → awaiting_confirmation → confirmed → stock_reserved → preparing → ready_to_ship → shipped → out_for_delivery → delivered` + فرعية/نهائية: `delayed`, `customer_unavailable`, `cancelled`, `delivery_failed`, `returned`, `partially_returned`, `exchanged`. **الاعتراف بالإيراد عند `delivered` فقط (ADR-010a).**

## 5.1 `OrderCreated`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | إنشاء طلب من `web`/`manual`/`marketer`/`pos` (ADR-010). يبدأ من `draft`/`new`. رقم `ORD-{YYYY}-{seq}` (ADR-002). |
| **الشروط المسبقة** | بنود صالحة؛ أسعار ضمن الحدود (لا تحت `min_price` بلا موافقة — ADR-006a)؛ عميل/عنوان (لطلب web). |
| **تغييرات قاعدة البيانات** | `INSERT orders` + `order_items` + `order_status_history`؛ حساب الإجماليات (Decimal). **لا حجز مخزون بعد** (الحجز عند `confirmed` — ADR-009). معاملة واحدة. |
| **الآثار الجانبية** | **عمولة تقديرية (ADR-012a): إنشاء صف عمولة بحالة `expected`** إن كان الطلب عبر مسوّق. توليد `uuid`. |
| **الإشعارات** | تأكيد استلام الطلب للعميل (Email/WhatsApp)؛ إشعار Sales لطلب يحتاج تواصل (`awaiting_contact`). |
| **متطلبات التدقيق** | `audit_logs`: created على Order + سجل الحالة الابتدائية. |
| **المستمعون والمهام** | **Sync:** إنشاء العمولة `expected`. **Async:** `SendOrderConfirmationJob`، `AssignSalesRepJob`، `AbandonedCartCloseJob` (إن تحوّلت سلة). |
| **الطور** | Phase 3. |

## 5.2 `OrderConfirmed`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | انتقال الطلب إلى `confirmed` ثم `stock_reserved` (تأكيد يدوي/آلي حسب `APPROVAL_WORKFLOWS`). |
| **الشروط المسبقة** | الطلب `awaiting_confirmation`/سابقة قانونية؛ توفّر المخزون للحجز (ADR-007a/009). |
| **تغييرات قاعدة البيانات** | `UPDATE orders.status`؛ `order_status_history`؛ **يُطلق `InventoryReserved`** (حجز داخل نفس المعاملة — ADR-009). معاملة واحدة مع قفل المخزون. |
| **الآثار الجانبية** | تجميد تعديلات الكميات (تتطلب إعادة حجز — ADR-010)؛ لا إيراد ولا COGS بعد (ADR-010a/005). |
| **الإشعارات** | إشعار العميل بتأكيد الطلب؛ إشعار المستودع لبدء التجهيز. |
| **متطلبات التدقيق** | `audit_logs`: تغيّر الحالة + من أكّد؛ قيد `inventory_ledger` عبر الحجز. |
| **المستمعون والمهام** | **Sync:** الحجز (`InventoryReserved`). **Async:** `NotifyOrderConfirmedJob`، `NotifyWarehousePrepareJob`. |
| **الطور** | Phase 3. |

## 5.3 `OrderCancelled`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | انتقال إلى `cancelled` (من العميل/Sales/آليًا). |
| **الشروط المسبقة** | الحالة ليست نهائية بعد الشحن؛ الإلغاء بعد الشحن يُعالَج كإرجاع (ADR-009/011). |
| **تغييرات قاعدة البيانات** | `UPDATE orders.status = cancelled` + `order_status_history`. إن كان محجوزًا: **يُطلق `InventoryReleased`** (تحرير) داخل نفس المعاملة. |
| **الآثار الجانبية** | **عمولة (ADR-012b): إلغاء قبل التسليم ← `cancelled`.** إن سبق أي تحصيل جزئي: بدء استرداد (RefundCompleted). |
| **الإشعارات** | إشعار العميل بالإلغاء وسببه؛ إشعار Sales/المستودع لإيقاف التجهيز. |
| **متطلبات التدقيق** | `audit_logs`: الحالة + السبب + المُلغي؛ قيد `inventory_ledger` عبر التحرير. |
| **المستمعون والمهام** | **Sync:** التحرير + تحديث العمولة. **Async:** `NotifyCancellationJob`، `InitiateRefundJob` (عند اللزوم). |
| **الطور** | Phase 3. |

## 5.4 `OrderPrepared`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | اكتمال التجهيز: `preparing → ready_to_ship`. |
| **الشروط المسبقة** | الطلب `preparing`؛ كل البنود مجهّزة؛ الحجز قائم. |
| **تغييرات قاعدة البيانات** | `UPDATE orders.status = ready_to_ship` + سجل الحالة. **لا خصم مخزون بعد** (الخصم عند الشحن — ADR-009). |
| **الآثار الجانبية** | تجهيز بيانات الشحنة (`shipments` مسودّة)؛ توليد مستندات التجهيز. |
| **الإشعارات** | إشعار داخلي لفريق الشحن؛ تحديث حالة للعميل (اختياري). |
| **متطلبات التدقيق** | `audit_logs`: تغيّر الحالة + المُجهِّز. |
| **المستمعون والمهام** | **Async:** `PrepareShipmentDraftJob`، `NotifyReadyToShipJob`. |
| **الطور** | Phase 3. |

## 5.5 `OrderShipped`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | الشحن الفعلي: `ready_to_ship → shipped` وإنشاء/تفعيل `shipments`. |
| **الشروط المسبقة** | الطلب `ready_to_ship`؛ وجود حجز مطابق؛ قفل صفوف المخزون. |
| **تغييرات قاعدة البيانات** | **الخصم النهائي (ADR-009):** `on_hand -= qty`, `reserved -= qty`؛ `inventory_movements` نوع `sale_out`؛ قيد `inventory_ledger` مع **احتساب COGS بـ WAC (ADR-005)**؛ `UPDATE orders.status=shipped` + `shipments`. معاملة واحدة مع قفل. |
| **الآثار الجانبية** | إثبات COGS؛ إغلاق الحجز؛ قد يُطلق `LowStockDetected`. **Hook محاسبي (ADR-016):** COGS مدين / المخزون دائن (Phase 5). **لا اعتراف بالإيراد بعد** (عند التسليم — ADR-010a). |
| **الإشعارات** | إشعار العميل برقم التتبّع (WhatsApp/SMS/Email)؛ يبدأ عدّاد أتمتة *delivery delay alerts*. |
| **متطلبات التدقيق** | **إلزامي:** `inventory_ledger` (خصم + COGS) + `audit_logs` على الطلب/الشحنة (ADR-008/020). |
| **المستمعون والمهام** | **Sync (داخل المعاملة):** الخصم + COGS. **Async:** `SendTrackingInfoJob`، `PostCogsAccountingJob` (Phase 5)، `StartDeliverySlaTimerJob`. |
| **الطور** | Phase 3. |

## 5.6 `OrderDelivered`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | تأكيد التسليم: `out_for_delivery → delivered`. |
| **الشروط المسبقة** | الطلب `shipped`/`out_for_delivery`؛ إثبات تسليم. |
| **تغييرات قاعدة البيانات** | `UPDATE orders.status=delivered` + `shipments.delivered_at` + سجل الحالة. **إنشاء فاتورة مبيعات (`invoices`) واعتراف الإيراد** (ADR-010a). معاملة واحدة. |
| **الآثار الجانبية** | **الاعتراف بالإيراد (ADR-010a) — نقطة الحقيقة المالية.** **عمولة (ADR-012a): `expected → earned`** (تُصبح مستحقة بعد التسليم — ADR-012 طريقة «بعد التسليم فقط»). بدء نافذة الإرجاع. **Hook محاسبي (ADR-016):** ذمم عملاء/نقد مدين / إيراد دائن + ضريبة (Phase 5). |
| **الإشعارات** | شكر/طلب تقييم للعميل؛ إشعار المسوّق باستحقاق العمولة؛ تغذية أتمتة *customer follow-up reminders*. |
| **متطلبات التدقيق** | `audit_logs`: الحالة + إنشاء الفاتورة + تحوّل العمولة (ADR-020). |
| **المستمعون والمهام** | **Sync:** إنشاء الفاتورة + تحديث العمولة `earned`. **Async:** `RecognizeRevenueJob` (Phase 5)، `RequestReviewJob`، `MarkCommissionEarnedJob`. |
| **مُنفَّذ (4.2):** | يُطلَق `\App\Modules\Sales\Events\OrderDelivered` عند التسليم؛ يستمع له `AccrueCommissionsOnDelivery` فيستحقّ عمولة المبيعات/أرباح المسوّق عبر `CommissionService::accrueForOrder` (idempotent). **يُنشأ القيد بحالة `pending` فقط — لا يُصبح `eligible` إلا بالتسوية في 4.6** (المتطلّب 11)؛ الاعتراف بالإيراد والقيود المحاسبية النهائية مؤجّلة لطورها. |
| **الطور** | Phase 3 (المحاسبة Phase 5؛ استحقاق العمولة Phase 4.2). |

## 5.7 `OrderReturned`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | اكتمال دورة إرجاع (ADR-011): `return_request → approved → received → inspected → (restock \| to_damaged)`، وانتقال الطلب إلى `returned` أو `partially_returned`. |
| **الشروط المسبقة** | الطلب `delivered` (ضمن نافذة الإرجاع) أو فشل تسليم؛ فحص المرتجع؛ صلاحية. |
| **تغييرات قاعدة البيانات** | `UPDATE orders.status`؛ `returns`؛ إرجاع المخزون: `on_hand += qty` (صالح) أو `damaged += qty` (تالف)؛ `inventory_movements` نوع `return_in` (أو `damage_out` للتالف)؛ قيد `inventory_ledger`. معاملة واحدة مع قفل. |
| **الآثار الجانبية** | **العكس النسبي (ADR-011):** عكس الإيراد وCOGS والمخزون **والعمولة** بنسبة الكمية المرتجعة. **عمولة (ADR-012b):** إرجاع كامل بعد الاستحقاق ← `reversed`؛ جزئي ← تخفيض تناسبي. بدء استرداد (RefundCompleted) أو استبدال/إشعار دائن. **Hook محاسبي (ADR-016):** قيود عكسية (لا حذف). |
| **الإشعارات** | إشعار العميل بحالة الإرجاع/الاسترداد؛ إشعار المستودع لاستلام/فحص؛ إشعار المسوّق بعكس العمولة. |
| **متطلبات التدقيق** | **إلزامي:** قيود `inventory_ledger` عكسية + `audit_logs` (السبب، الكميات، حالة الفحص) — عكس لا حذف (ADR-011/016). |
| **المستمعون والمهام** | **Sync:** إرجاع المخزون + عكس العمولة. **Async:** `ReverseRevenueCogsJob` (Phase 5)، `ProcessRefundJob`، `NotifyReturnJob`. |
| **الطور** | Phase 3. |

## 5.8 `DeliveryStatusChanged` — *(مُنفَّذ Phase 4.3 / ADR-038)*
| البند | التفصيل |
|------|---------|
| **المُطلِق** | تغيّر الحالة القانونية للتوصيل عبر `DeliveryStatusService::transitionTo` (يدوي/نظام/مزوّد). |
| **الحمولة** | `shipment`, `fromStatus`, `toStatus`, `actorType` (user/system/provider), `reasonCode?`. |
| **تغييرات قاعدة البيانات** | `shipments.delivery_status` (+`on_hold_reason`/`closed_at` عند اللزوم) + صفّ في `delivery_status_transitions` (append-only). حالة المزوّد الخام في `delivery_provider_transitions` منفصلة. |
| **الآثار الجانبية** | نقطة امتداد (إشعارات/تحليلات) دون تسريب منطق مزوّد. **لا منطق نمو/تسويق.** |
| **الطور** | Phase 4.3. |

## 5.9 `ShipmentClosed` — *(مُنفَّذ Phase 4.3 / ADR-038)*
| البند | التفصيل |
|------|---------|
| **المُطلِق** | بلوغ الحالة القانونية `closed` (الاكتمال المالي الوحيد). |
| **الحمولة** | `shipment`, `order`. |
| **تغييرات قاعدة البيانات** | `orders.settled_at` + عمولات الطلب `pending → eligible` (عبر `markEligibleForOrder`). **لا دفع تلقائي.** |
| **الآثار الجانبية** | نقطة امتداد **للترحيل المحاسبي النهائي في 4.6** (لا محاسبة نهائية هنا). الاعتماد/الصرف يبقيان مسؤولية المالية منفصلين (4.2). |
| **الطور** | Phase 4.3 (المحاسبة النهائية 4.6/Phase 5). |

---

# 6) وحدة Payments / Accounting (المدفوعات)

## 6.1 `PaymentReceived`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | تحصيل مبلغ من عميل (بوابة دفع عبر `PaymentGatewayContract` — المبدأ 13، أو تحصيل يدوي/COD). |
| **الشروط المسبقة** | مبلغ > 0 (Decimal — ADR-001)؛ ربط بطلب/فاتورة؛ خزينة/حساب وجهة؛ قفل صف الخزينة (المبدأ 7). |
| **تغييرات قاعدة البيانات** | `INSERT payments` (direction=in)؛ تحديث رصيد `treasuries`/`payment_status` للطلب؛ ربط بـ `invoices` عند وجودها. معاملة واحدة مع قفل. |
| **الآثار الجانبية** | تحديث حالة الدفع للطلب (قد يمكّن انتقالات إن كان `inventory.reserve_on=paid`). **لا اعتراف إيراد هنا** (عند التسليم — ADR-010a). **Hook محاسبي (ADR-016):** نقد/خزينة مدين / ذمم عملاء دائن (Phase 5). |
| **الإشعارات** | إيصال دفع للعميل (Email/WhatsApp)؛ إشعار المحاسب. |
| **متطلبات التدقيق** | **إلزامي:** `audit_logs` على الدفعة (المبلغ، الطريقة، المرجع، الخزينة) — كيان مالي حسّاس (المبدأ 8). |
| **المستمعون والمهام** | **Sync (داخل المعاملة):** إثبات الدفعة + رصيد الخزينة. **Async:** `SendPaymentReceiptJob`، `PostPaymentAccountingJob` (Phase 5). |
| **الطور** | Phase 3 (المحاسبة Phase 5). |

## 6.2 `RefundCompleted`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | إتمام استرداد للعميل (عقب إلغاء/إرجاع) عبر البوابة أو نقدًا. |
| **الشروط المسبقة** | وجود دفعة/التزام قابل للاسترداد؛ مبلغ الاسترداد ≤ المحصّل؛ صلاحية؛ قفل الخزينة. |
| **تغييرات قاعدة البيانات** | `INSERT payments` (direction=out) كحركة استرداد؛ تحديث رصيد `treasuries` وحالة الدفع/الطلب. **عكس لا حذف** (ADR-011/016). معاملة واحدة مع قفل. |
| **الآثار الجانبية** | إغلاق مالي للإرجاع/الإلغاء؛ اتساق مع عكس الإيراد/العمولة. **Hook محاسبي (ADR-016):** قيد عكسي (ذمم/إيراد دائن معكوس / نقد دائن). |
| **الإشعارات** | إشعار العميل بإتمام الاسترداد ومدّته؛ إشعار المحاسب. |
| **متطلبات التدقيق** | **إلزامي:** `audit_logs` (المبلغ، السبب، المرجع، من نفّذ) — مالي حسّاس. |
| **المستمعون والمهام** | **Sync:** إثبات الاسترداد + رصيد الخزينة. **Async:** `SendRefundConfirmationJob`، `PostRefundAccountingJob` (Phase 5). |
| **الطور** | Phase 3 (المحاسبة Phase 5). |

---

# 7) وحدة Affiliate (العمولات)

> **حالات العمولة القانونية (ADR-012a):** `expected → pending → earned → approved → payable → paid` + `cancelled`, `reversed`. **الأساس (ADR-012c):** صافي قيمة البند بعد الخصم، قبل الشحن/الدفع (`commission.base` افتراضي `net_item`).

## 7.1 `CommissionCalculated`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | حساب/إعادة حساب قيمة عمولة وفق `commission_rules` (ADR-012): عند `OrderCreated` (`expected`)، وتثبيتها عند `OrderDelivered` (`earned`)، أو تشغيل مجدول (أتمتة *commission calculations*). |
| **الشروط المسبقة** | طلب مرتبط بمسوّق؛ قاعدة عمولة سارية؛ توفّر الأساس (`marketer_price`/صافي البند — ADR-006/012c). |
| **تغييرات قاعدة البيانات** | `INSERT`/`UPDATE` في `commissions` (المبلغ، الحالة، الطلب، المسوّق). معاملة واحدة. |
| **الآثار الجانبية** | ضبط الحالة حسب طور الطلب (`expected`/`earned`)؛ تناسق مع عكس/تخفيض عند الإرجاع (ADR-012b). |
| **الإشعارات** | تحديث لوحة المسوّق (إشعار داخلي)؛ إشعار عند تحوّل `expected → earned`. |
| **متطلبات التدقيق** | `audit_logs`: الأساس، النسبة/المبلغ، القاعدة المطبّقة، الحالة (شفافية احتساب — المبدأ 8). |
| **المستمعون والمهام** | **Sync:** الاحتساب ضمن معاملة الطلب عند اللزوم. **Async:** `NotifyMarketerCommissionJob`، `RecalcCommissionOnPriceChangeJob`. |
| **الطور** | Phase 3 (اكتمال دفع العمولات لاحقًا). |

## 7.2 `CommissionApproved`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | موافقة إدارية على عمولة مكتسبة: `earned → approved` ثم `payable` (ADR-012a، `APPROVAL_WORKFLOWS`). |
| **الشروط المسبقة** | العمولة `earned`؛ الطلب `delivered` وخارج نافذة الإرجاع (أو سياسة الشركة)؛ صلاحية الاعتماد. |
| **تغييرات قاعدة البيانات** | `UPDATE commissions.status = approved` (ثم `payable`) + المعتمِد/الوقت. معاملة واحدة. |
| **الآثار الجانبية** | إتاحة العمولة للسحب (تغذّي `payout_requests`)؛ تثبيت المبلغ. **Hook محاسبي (ADR-016):** إثبات مصروف/التزام عمولة (Phase 5). |
| **الإشعارات** | إشعار المسوّق بجاهزية العمولة للسحب. |
| **متطلبات التدقيق** | `audit_logs`: تغيّر الحالة + المعتمِد + المبلغ. |
| **المستمعون والمهام** | **Async:** `NotifyCommissionPayableJob`، `PostCommissionAccrualJob` (Phase 5). |
| **الطور** | Phase 3/4. |

## 7.3 `CommissionPaid`
| البند | التفصيل |
|------|---------|
| **المُطلِق** | صرف العمولة للمسوّق: `payable → paid` (عبر `payout_requests`). |
| **الشروط المسبقة** | العمولة `payable`؛ طلب سحب معتمد؛ رصيد خزينة كافٍ؛ قفل الخزينة/المحفظة. |
| **تغييرات قاعدة البيانات** | `UPDATE commissions.status = paid`؛ `UPDATE payout_requests`؛ `INSERT payments` (direction=out)؛ تحديث `affiliates.wallet_balance` ورصيد `treasuries`. معاملة واحدة مع قفل. |
| **الآثار الجانبية** | تسوية محفظة المسوّق. **Hook محاسبي (ADR-016):** التزام العمولة مدين / نقد دائن (Phase 5). |
| **الإشعارات** | إشعار المسوّق بإتمام الصرف؛ إشعار المحاسب. |
| **متطلبات التدقيق** | **إلزامي:** `audit_logs` (المبلغ، الطريقة، المرجع، من صرف) — مالي حسّاس. |
| **المستمعون والمهام** | **Sync:** الصرف + تحديث المحفظة/الخزينة. **Async:** `SendPayoutConfirmationJob`، `PostCommissionPaymentJob` (Phase 5). |
| **الطور** | Phase 4. |

---

# جدول ملخّص الأحداث (Summary)

| الحدث (Event) | الوحدة (Module) | Sync/Async | الطور (Phase) |
|--------------|----------------|:----------:|:-------------:|
| `CustomerCreated` | CRM | Async (بعد commit) | 3 |
| `CustomerUpdated` | CRM | Async | 3 |
| `ProductCreated` | Catalog | Async | 2 |
| `ProductPriceChanged` | Catalog | Sync حدّ+موافقة / Async إشعار | 2 |
| `LowStockDetected` | Inventory | Async | 2 |
| `InventoryReserved` | Inventory | Sync (داخل المعاملة) | 3 (بنية 2) |
| `InventoryReleased` | Inventory | Sync | 3 |
| `InventoryAdjusted` | Inventory | Sync + Async محاسبة | 2 |
| `StockTransferred` | Inventory | Sync + Async | 2 |
| `PurchaseOrderCreated` | Purchasing | Async | 2 |
| `PurchaseOrderApproved` *(مُضاف)* | Purchasing | Async | 2 |
| `GoodsReceived` | Purchasing | Sync (WAC) + Async | 2 (محاسبة 5) |
| `PurchaseInvoiceApproved` | Purchasing | Async | 4 (محاسبة 5) |
| `OrderCreated` | Orders | Sync (عمولة expected) + Async | 3 |
| `OrderConfirmed` | Orders | Sync (حجز) + Async | 3 |
| `OrderCancelled` | Orders | Sync (تحرير) + Async | 3 |
| `OrderPrepared` | Orders | Async | 3 |
| `OrderShipped` | Orders | Sync (خصم+COGS) + Async | 3 |
| `OrderDelivered` | Orders | Sync (إيراد+عمولة) + Async | 3 (محاسبة 5) |
| `OrderReturned` | Orders | Sync (عكس) + Async | 3 |
| `PaymentReceived` | Payments/Accounting | Sync + Async | 3 (محاسبة 5) |
| `RefundCompleted` | Payments/Accounting | Sync + Async | 3 (محاسبة 5) |
| `CommissionCalculated` | Affiliate | Sync/Async | 3 |
| `CommissionApproved` | Affiliate | Async | 3/4 |
| `CommissionPaid` | Affiliate | Sync + Async | 4 |

**الإجمالي: 25 حدثًا** (23 مطلوبة + 1 مُضاف `PurchaseOrderApproved` + `InventoryReserved`/`InventoryReleased` مصمّمة في Phase 2 ومنفّذة في Phase 3).

---

## الأحداث المستقرّة المواجِهة للنمو (Growth-facing Stable Events — ADR-032)

> عقد الأسماء الذي يشترك فيه سياق **Growth & Commerce Intelligence** مستقبلًا (بلا تنفيذ لأي وحدة نمو). المرجع: [`GROWTH_COMMERCE_ARCHITECTURE.md`](GROWTH_COMMERCE_ARCHITECTURE.md). حدث بلا مستمع = نقطة امتداد مشروعة. الأحداث تُطلَق `afterCommit`؛ مستمعو النمو **إضافيون** ولا يُفشلون عملية التجارة.

| الاسم المستقر (Growth) | يُعادِل / يُشتقّ من | الحالة الحالية |
|-----------------------|--------------------|----------------|
| `CustomerRegistered` | `CustomerCreated` (§1.1) | مُخطَّط (كتالوج) |
| `CustomerUpdated` | `CustomerUpdated` (§1.2) | مُخطَّط |
| `CustomerBlocked` | (حظر العميل — BR-CUST-12) | مُخطَّط |
| `CustomerMerged` | (دمج العميل — BR-CUST-14) | مُخطَّط |
| `CartCreated` | سلة نشطة تُنشأ (ADR-031) | مُخطَّط |
| `CartUpdated` | تغيّر بنود السلة | مُخطَّط |
| `CartItemAdded` | إضافة بند للسلة | مُخطَّط |
| `CartItemRemoved` | حذف بند من السلة | مُخطَّط |
| `CartAbandoned` | ≈ `AbandonedCartDetected` (مرشّح) | مُخطَّط |
| `CheckoutStarted` | بدء إتمام الشراء (3.2) | يُصدَر في 3.2 |
| `CheckoutCompleted` | إتمام ناجح → طلب (3.2) | يُصدَر في 3.2 |
| `OrderCreated` | `OrderCreated` (§5.1) | مُخطَّط |
| `OrderConfirmed` | `OrderConfirmed` (§5.2) | مُخطَّط |
| `OrderPaid` | ≈ `PaymentReceived`→ حالة دفع الطلب `paid` (§6.1) | مُخطَّط |
| `OrderCancelled` | `OrderCancelled` (§5.3) | مُخطَّط |
| `OrderReturned` | `OrderReturned` (§5.7) | مُخطَّط |
| `ShipmentCreated` | إنشاء شحنة (ADR-027) | مُخطَّط |
| `ShipmentDelivered` | ≈ `OrderDelivered`/تسليم الشحنة (§5.6) | مُخطَّط |
| `PaymentCaptured` | ≈ `PaymentReceived` (§6.1) | مُخطَّط |
| `PaymentRefunded` | `RefundCompleted` (§6.2) | مُخطَّط |
| `ReviewSubmitted` | (مراجعات المنتج — مستقبلي) | مُخطَّط |
| `CouponApplied` | (محرّك الكوبونات — سياق النمو) | مُخطَّط |
| `LoyaltyPointsEarned` | (محرّك الولاء — سياق النمو) | مُخطَّط |

**قواعد التوافق:** حيث يوجد اسم دومين محلي سابق (مثل `CustomerCreated`/`PaymentReceived`)، يبقى **حدثًا واحدًا** ويُنشر باسمه المحلي؛ عند بناء النمو يُوفَّر جسر (bridge) يعيد النشر بالاسم المستقر إن لزم — دون ازدواج منطق أو تعديل الخدمة المُصدِرة (ADR-032). الأسماء الجديدة تمامًا (`Cart*`, `Checkout*`, `ReviewSubmitted`, `CouponApplied`, `LoyaltyPointsEarned`) تُضاف للمفردات عند تفعيلها.

---

## أحداث العمليات (Phase 4 — ADR-037)

**Phase 4.1 (مُنفَّذ):** `AssistedOrderCreated` (إنشاء طلب مُساعد من قناة)، `OrderPriceChangeRequested` (تعديل سعر تحت الحد يتطلّب اعتمادًا)، `OrderPriceChangeApproved`/`OrderPriceChangeRejected` (قرار المشرف) — نقاط امتداد تُستهلك محاسبيًا/عموليًا في 4.2/4.6.

**Phase 4.2–4.7 (مُخطَّط):** `CommissionAccrued`/`AffiliateEarningAccrued` (عند التسليم)، `CommissionBecameEligible`/`AffiliateEarningBecameEligible` (عند التسوية)، `…Approved`/`…Paid`/`…Reversed`؛ `DeliveryExceptionRaised`/`…Resolved`؛ `ReturnRequested`/`ReturnApproved`/`ReturnInspected`؛ `DeliveryClaim{Submitted,Decided,Paid}`؛ `SettlementReconciled`/`OrderSettled`.

---

## أحداث مرشّحة لاحقًا (خارج نطاق هذا الإصدار — للتتبّع فقط)
`LeadCreated` · `OpportunityStageChanged` · `AbandonedCartDetected` · `PayoutRequested` · `ReturnInspected` · `SupplierInvoicePaid` · `ConversationAssigned`. تُوثَّق عند تفعيلها وتُضاف للمفردات إن لزم (قاعدة `DECISIONS.md`).

</div>
