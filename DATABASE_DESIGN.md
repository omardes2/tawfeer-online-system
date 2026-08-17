<div dir="rtl">

# تصميم قاعدة البيانات — Tawfeer Online

> قاعدة البيانات: **MySQL 8** — محرك InnoDB، ترميز `utf8mb4_unicode_ci`.
> جميع الجداول تحوي `id` (BIGINT، مفتاح أساسي داخلي)، و`created_at` / `updated_at`، و`deleted_at` عند الحاجة للحذف الناعم (Soft Delete).

هذا تصميم مبدئي عالي المستوى يُفصَّل جدولًا-جدولًا داخل الترحيلات (migrations) في كل مرحلة.

> **ملاحظة معمارية:** هذا المخطط يلتزم بمبادئ `ARCHITECTURE.md`:
> - **`branch_id`** على الكيانات التشغيلية (جاهزية تعدد الفروع).
> - **`uuid`** (فريد، مفهرس) على الكيانات المكشوفة خارجيًا (`orders`, `invoices`, `shipments`...).
> - **جاهزية Multi-Tenant:** الجداول مصمّمة بحيث يمكن إضافة `tenant_id` لاحقًا دون كسر العلاقات (غير مُضاف الآن).
> - **المبالغ** `decimal(15,2)` والكميات `decimal(15,3)` — لا `float`.
> - **الحالات** (طلب/دفع/شحن) في جداول مستقلة قابلة للإدارة، لا `enum` مغلق.

---

## 0. الأساس (Foundation)

### `branches` (الفروع)
`uuid` · `name` · `code` (فريد) · `address` · `phone` · `is_default` · `is_active`
> يُنشأ فرع افتراضي واحد عند التنصيب. كل كيان تشغيلي يشير إلى `branch_id`.

### `settings` (الإعدادات الديناميكية)
`key` (فريد) · `value (json)` · `group` · `type`
> تُقرأ عبر طبقة `Settings` مع تخزين مؤقت. لا قيم ثابتة في الكود.

### `audit_logs` (سجلّ التدقيق المركزي — append-only)
`user_id` → users · `action` · `auditable_type` · `auditable_id` · `old_values (json)` · `new_values (json)` · `ip` · `user_agent`
> غير قابل للتعديل/الحذف. يُملأ آليًا عبر تريتة `Auditable`.

### جداول الحالات القابلة للإدارة
- `order_statuses` · `payment_statuses` · `shipment_statuses`
- كل منها: `key` (فريد) · `name` · `color` · `sort_order` · `is_final` · `is_active`
> تُزرع حالات افتراضية وتبقى قابلة للإدارة من لوحة التحكم.

---

## 1. المستخدمون والصلاحيات

### `users`
| العمود | النوع | ملاحظات |
|--------|------|---------|
| name | string | |
| email | string | فريد |
| phone | string | فريد، nullable |
| password | string | مُشفّر |
| is_active | boolean | |
| last_login_at | timestamp | nullable |

### جداول الصلاحيات (من spatie/laravel-permission)
`roles` · `permissions` · `model_has_roles` · `model_has_permissions` · `role_has_permissions`

> سجلّ التدقيق `audit_logs` مُعرّف مركزيًا في **قسم الأساس (0)** لكل الوحدات.

---

## 2. الكتالوج والمخزون

### `categories`
`parent_id` → categories (شجري) · `name` · `slug` · `is_active`

### `products`
`category_id` → categories · `name` · `slug` · `sku` · `description` · `type` (simple/variable) · `is_active` · `weight` · `cbm`

### `product_variants`
`product_id` → products · `sku` (فريد) · `barcode` · `attributes (json)` · `cost_price` · `sale_price` · `weight` · `cbm`

> `cbm` حجم الوحدة بالمتر المكعّب — أساس توزيع الشحن البحري على الأصناف في فواتير
> الاستيراد. يُقرأ حجم المتغيّر أولًا ثم حجم المنتج، ويُزامَن الحقلان للمتغيّر الافتراضي.

### `product_offers` (عروض الكمّية — ADR-056)
`product_id` → products · `min_qty` · `total_price` · `label` · `is_active` · `sort_order`. فريد على (`product_id`, `min_qty`).
**على الصنف لا على المتغيّر:** الزبون يشتري خمس قطعٍ بمقاساتٍ مختلفة ويعدّها عرضًا واحدًا، فالكمّية تُجمع عبر متغيّرات الصنف والسعر يطالها كلَّها. ويُخزَّن **السعر الإجمالي** لا سعر القطعة — التاجر يعلن بـ«خمس بمئة»، وسعر القطعة مشتقٌّ للعرض؛ والعكس يُدخل كسورًا لا تُعلَن. والفريد يمنع عرضين بالكمّية نفسها، وإلّا صار السعر رهنَ ترتيبٍ عشوائي.
> التسعير يجري في `CartService` وحده: `cart_items.unit_price` يحمل سعر العرض، ويَنسخه الإتمام إلى بند الطلب كما ينسخ أي سعر. فلا أثر للعروض في مخطّط الطلبات ولا في مسار التوصيل.

### `product_reviews`
`product_id` → products · `customer_id` → customers · `order_id` → orders (nullable) · `rating` (1..5) · `title` · `body` · `status` (pending/approved/rejected) · `moderated_by` → users · `moderated_at` · `moderation_note`
> يكتبه الزبون في المتجر ولا يُعرض إلا بعد اعتماد إداري (`status = approved`).
> `order_id` هو دليل الشراء: لا يُقبل تقييم إلا من صاحب طلب **مستلَم** يحوي المنتج.
> فريد على (`product_id`, `customer_id`) — رأي واحد لكل زبون فلا يُرفع المعدّل بالتكرار.

### `units`
`name` · `symbol` (قطعة، كرتون، كجم...)

### `warehouses`
`name` · `code` · `branch_id` → branches · `is_active`
> كل مستودع مرتبط بفرع (جاهزية Multi-Warehouse/Multi-Branch).

### `inventory_stocks`
`variant_id` → product_variants · `warehouse_id` → warehouses · `quantity` · `reorder_level`

### `inventory_movements`
`variant_id` · `warehouse_id` · `type` (in/out/transfer/adjustment) · `quantity` · `reference_type` · `reference_id` · `note`

---

## 3. العملاء والطلبات

### `customers`
`user_id` → users (nullable) · `name` · `email` · `phone` · `loyalty_points` · `default_address_id`

### `addresses`
`customer_id` → customers · `line1` · `line2` · `city` · `region` · `country` · `is_default`

### `orders`
`order_number` (فريد) · `customer_id` · `sales_rep_id` → users (nullable) · `affiliate_id` → affiliates (nullable) · `status` · `subtotal` · `discount_total` · `tax_total` · `shipping_total` · `grand_total` · `payment_status` · `channel` (web/manual)

### `order_items`
`order_id` → orders · `variant_id` → product_variants · `quantity` · `unit_price` · `discount` · `line_total`

### `order_status_history`
`order_id` · `from_status` · `to_status` · `changed_by` → users · `note`

### `shipments`
`order_id` · `carrier` · `tracking_number` · `status` · `shipped_at` · `delivered_at`

### `returns`
`order_id` · `reason` · `status` · `refund_amount`

---

## 4. المشتريات والموردون

### `suppliers`
`name` · `contact_name` · `email` · `phone` · `address` · `terms`

### `purchase_orders`
`po_number` (فريد) · `supplier_id` → suppliers · `warehouse_id` · `status` (draft/approved/received/closed) · `total`

### `purchase_order_items`
`purchase_order_id` · `variant_id` · `quantity` · `unit_cost` · `received_quantity`

### `goods_receipts` (GRN)
`purchase_order_id` · `received_by` → users · `received_at`

### `supplier_invoices`
`supplier_id` · `purchase_order_id` · `invoice_number` · `amount` · `due_date` · `status`

### `import_shipments` (شحنات الاستيراد / الكونتينرات)
`number` (CNTR-{YYYY}-{seq}) · `reference` (رقم الكونتينر) · `supplier_id` ·
`status` (open/closed) · `shipped_at` · `arrived_at` · `variance_amount` ·
`variance_entry_id` → journal_entries · `closed_at` · `closed_by` → users

> **وعاء التكلفة.** فاتورة البضاعة تُحمّل الحساب الوسيط بتقديرها، وفواتير المصاريف
> التي تصل بعدها بأشهر تُطفئه بالفعلي، وما يتبقّى فرقُ تقدير يُقفل عند الإغلاق
> في `5050`. الاسم `import_shipments` لا `shipments` — الأخير لشحنات التوصيل للعملاء.
>
> **الإغلاق يدوي** ويعرض الأرقام قبل التأكيد. وله **إعادة فتح** تعكس قيد الفرق:
> الإغلاق قرارُ بشرٍ يُخطئ، والفواتير قد تتأخّر شهرًا آخر. ولا مفتاح idempotency
> على قيد الفرق — الحارس هو الحالة نفسها، ومفتاحٌ ثابت كان سيمنع قيدًا جديدًا
> بعد إعادة فتحٍ وإغلاق.

### `purchase_invoices` — حقول الاستيراد
`currency` (عملة المورد) · `fx_rate_to_usd` (كم وحدة من عملة الفاتورة = 1 $) ·
`usd_rate` (كم من العملة الأساسية = 1 $) · `commission_rate` (٪ عمولة المشتريات) ·
`cbm_rate_usd` (تكلفة المتر المكعّب بالدولار) · `foreign_subtotal` · `landed_subtotal` · `total_cbm` ·
`import_shipment_id` → import_shipments · `kind` (goods/expenses)

> `kind = expenses` فاتورةُ مصاريف شحنة: تُدين الحساب الوسيط لا المخزون، ولا تُدخل
> بضاعة ولا تُنشئ أصنافًا، ولا تُحمَّل عليها عمولةٌ ولا شحن (وإلا حُمّلا مرّتين).
> ولا تُقبَل بلا شحنة — بغيرها لا يُعرف أيّ تقديرٍ تُطفئ، فتُعامَل فاتورةَ بضاعة.

### `purchase_invoice_items` — حقول الاستيراد
`unit_price_foreign` (سعر الوحدة بعملة المورد) · `cbm_per_unit` · `unit_cost` (السعر
الحقيقي بالعملة الأساسية — ذمّة المورد) · `landed_unit_cost` (التكلفة الشاملة) ·
`landed_line_total` · `landed_is_manual`

> **سعران لكل بند.** السعر الحقيقي هو ما يُذمّ للمورد؛ التكلفة الشاملة تُضيف عليه
> نصيبه من عمولة المشتريات ومن الشحن البحري (حسب حجمه). المعادلة في
> `ImportCostCalculator` وحده، وتُعاد في الخلفية عند كل حفظ.
>
> تُترك أسعار الصرف `null` في الفاتورة المحلية — وحالتُها الصريحة أسلمُ من صفرٍ
> يُقسَم عليه — فتبقى الحسابات كما كانت (`unit_cost` كما يُكتب، والتكلفة الشاملة تساويه).
>
> `unit_cost` و`landed_unit_cost` بأربع منازل: التحويل يُنتج كسورًا طويلة
> (45 ¥ ⇒ 22.9720 ₪) ومنزلتان تُضيّعان فروقًا تتراكم على مئات القطع. المبالغ
> الإجمالية تبقى بمنزلتين.
>
> **الترحيل (المرحلة ٢):** مدين المخزون بالتكلفة **الشاملة** [+ ضريبة] / دائن ذمم
> المورد بالسعر **الحقيقي** / دائن «مصاريف استيراد مستحقة» (`2110`) بالفرق. وتدخل
> البضاعةُ المخزونَ بالتكلفة الشاملة أيضًا، فيحمل متوسطُ التكلفة مصاريفَه ويصحّ ربحُ
> ما يُباع لاحقًا. التكلفة اليدوية الأقلّ من سعر المورد تقلب الفرق مدينًا — الاتجاه
> يُشتقّ من الإشارة. ولا يُضاف سطرُ الاستحقاق بفرق أصغر من قرش (القيد يرفض سطرًا صفريًا).

---

## 5. المحاسبة (مزدوجة القيد)

### `accounts` (دليل الحسابات)
`parent_id` → accounts (شجري) · `code` · `name` · `type` (asset/liability/equity/revenue/expense) · `is_active`

> **`2110` مصاريف استيراد مستحقة** (خصم): الطرف الذي يوازن قيدَ فاتورة الاستيراد
> حين يُدان المخزون بالتكلفة الشاملة وتُدان ذمّة المورد بسعرها الحقيقي. تُطفئه
> فاتورةُ الشحن والمصاريف حين تصل؛ وما يتبقّى فرقُ تقدير. **رصيدٌ باقٍ = شحنة لم
> تصل فواتيرها أو لم تُغلق** — فالحساب جرسُ إنذار لا رقمٌ صامت.
>
> **`5060` فروق أسعار الصرف** (مصروف): الفرق بين قيمة الدَّين يوم الفاتورة وقيمته
> يوم السداد. السداد بالعملة الأجنبية يُخرج من الخزينة `usd × سعر اليوم` ويُطفئ من
> ذمّة المورد `usd × سعر الفاتورة`، والفارق يُقيَّد هنا — وإلا بقيت على المورد
> قروشٌ لا تُسدَّد أبدًا. و`amount_paid` يُقاس بما أُطفئ من الذمّة لا بما خرج من
> الخزينة، وإلا لم يصل المتبقّي إلى الصفر.
>
> **`5050` فروق تقدير تكاليف الاستيراد** (مصروف): مقصدُ ما يتبقّى في `2110` عند
> إغلاق الشحنة. التقدير لا يطابق الفعلي أبدًا، والفرق نتيجةُ فترة لا يُعاد به
> تسعيرُ بضاعةٍ بِيعت — وهذا المتَّبع في التكلفة المعيارية. ميلُ الرصيد جهةً واحدة
> عبر السنة يعني أن تكلفة الـCBM المستخدَمة تحتاج تحديثًا.

### `journal_entries`
`entry_number` · `date` · `description` · `reference_type` · `reference_id` · `posted_by` → users

### `journal_lines`
`journal_entry_id` → journal_entries · `account_id` → accounts · `debit` · `credit`
> قاعدة: مجموع المدين = مجموع الدائن لكل قيد.

### `invoices` (فواتير المبيعات)
`order_id` → orders · `invoice_number` · `amount` · `tax` · `total` · `status` · `due_date`

### `payments`
`payable_type` · `payable_id` · `direction` (in/out) · `method` · `amount` · `account_id` → accounts · `paid_at`

### `treasuries` (خزائن/بنوك)
`name` · `type` (cash/bank) · `account_id` → accounts · `balance`

---

## 6. CRM

### `leads`
`name` · `phone` · `email` · `source` · `status` · `assigned_to` → users

### `opportunities`
`lead_id` → leads (nullable) · `customer_id` → customers (nullable) · `title` · `stage` · `value` · `assigned_to`

### `activities`
`subject_type` (lead/opportunity/customer) · `subject_id` · `type` (call/meeting/task/note) · `due_at` · `done` · `assigned_to`

---

## 7. المسوّقون والعمولات

### `affiliates`
`user_id` → users · `code` (فريد) · `status` · `wallet_balance`

### `referrals`
`affiliate_id` → affiliates · `customer_id` → customers (nullable) · `order_id` → orders (nullable) · `type` (click/signup/order)

### `commission_rules`
`scope_type` (global/category/product) · `scope_id` · `rate_type` (percent/fixed/tiered) · `value`

### `commissions`
`affiliate_id` · `order_id` · `amount` · `status` (pending/approved/paid)

### `payout_requests`
`affiliate_id` · `amount` · `status` · `method` · `processed_at`

---

## 8. الصندوق الموحّد (Messaging)

### `channels`
`type` (whatsapp/messenger/instagram) · `config (json)` · `is_active`

### `conversations`
`channel_id` → channels · `customer_id` → customers (nullable) · `external_id` · `status` · `assigned_to` → users

### `messages`
`conversation_id` → conversations · `direction` (in/out) · `body` · `type` (text/image/file) · `sent_at` · `is_read`

### `message_templates`
`channel_type` · `name` · `body`

---

## 9. العروض والولاء

### `coupons`
`code` (فريد) · `type` (percent/fixed/free_shipping) · `value` · `min_order` · `usage_limit` · `used_count` · `starts_at` · `ends_at` · `is_active`

### `promotions`
`name` · `rules (json)` · `starts_at` · `ends_at` · `is_active`

### `loyalty_transactions`
`customer_id` → customers · `type` (earn/redeem) · `points` · `reference_type` · `reference_id`

---

## 10. الإعدادات

### `settings`
`key` (فريد) · `value (json)` · `group`

---

## 11. النمو والذكاء (Phase 6 — مُنفَّذ)

> إضافية بالكامل (ADR-044/045/046/047). الأسرار في `.env` — لا مفاتيح في الجداول.

### `ai_generation_logs` (append-only)
`user_id` → users · `product_id` → products · `type` · `action` · `locale` · `provider` · `model` · `prompt_tokens` · `completion_tokens` · `total_tokens` · `status` · `error` — **بلا أسرار**. (ADR-044)

### `product_recommendations`
`product_id` → products · `recommended_product_id` → products · `type` (related/cross_sell/upsell/complementary/fbt) · `kind` (include/exclude) · `position` · `is_active` · `created_by`. فريد (product, recommended, type). (ADR-045)

### `recommendation_events` (append-only)
`source_product_id` · `recommended_product_id` · `type` · `event` (impression/click/conversion) · `source` · `placement` · `user_id` · `session_token`. (ADR-045)

### `campaign_templates`
`name` · `channel` · `subject` · `body_ar` · `body_en` · `is_active` · `created_by`. (ADR-046)

### `campaigns` (uuid · soft-delete · auditable)
`name` · `use_case` · `channel` · `status` (draft→pending_approval→approved→active→paused→completed→archived) · `trigger_type` (event/scheduled/manual) · `trigger_event` · `audience (json)` · `delay_minutes` · `scheduled_at` · `template_id` → campaign_templates · `body_ar/body_en/subject` · `frequency_cap_days` · `quiet_start/quiet_end` · `approved_by/approved_at`. (ADR-046)

### `campaign_messages`
`campaign_id` → campaigns · `customer_id` → customers · `channel` · `recipient` · `body` · `status` · `provider_reference` · `error` · `idempotency_key` (فريد) · `attempts` · `is_test` · `sent_at`. (ADR-046)

### `message_suppressions` (opt-out · append-only)
`customer_id` → customers · `contact` · `channel` · `reason`. (ADR-046)

> **KPIs (ADR-047):** بلا جداول جديدة — `ReportingService::kpis()` يقرأ الجداول أعلاه مع جداول الأعمال القائمة.

---

## 12. الميزانية اليومية (الصرف الإعلاني)

> وحدة القرار **(صنف × قناة)** لا الصنف وحده: الصنف نفسه قد يربح على صفحة ويخسر
> على أخرى، وجمعُهما يُخفي الخسارة داخل متوسّط. والبنية تطابق مدير إعلانات Meta:
> الحملة صفحة، والمجموعة الإعلانية صنفٌ بميزانيته.

### `ad_channels`
`name` · `platform` (facebook/instagram/whatsapp/other) · `delivery_business_id` → delivery_businesses (**فريد**) · `is_active` · `sort_order`.
الربط بحساب البزنس هو ما يجعل إسناد الطلب آليًّا: الطلب ← منشئُه ← حسابُ بزنسه ← الصفحة. والفريد شرطٌ للصحّة — حسابٌ واحد لصفحتين يَنسب مبيعات إحداهما للأخرى.

### `orders.ad_channel_id` (لقطة)
تُثبَّت لحظة الإنشاء في `Order::booted()` ولا تُشتقّ وقت العرض: نقلُ موظفةٍ إلى صفحة أخرى كان سينقل معها كل طلباتها السابقة فيتغيّر تقرير الشهر الماضي بصمت. طلب الويب لا منشئ له فتبقى فارغة.

### `ad_daily_spends`
`spend_date` · `ad_channel_id` → ad_channels · `product_id` → products · `amount_usd` · `fx_rate` · `conversations` · `entered_by` → users. **فريد (spend_date, ad_channel_id, product_id)** — الإدخال يُعاد حتى يستقرّ رقم Meta فيجب أن يُحدِّث لا أن يتراكم.
`fx_rate` مخزَّن لا محسوب: الإدخال يجري في اليوم التالي، فلو حُوِّل بسعر يوم الإدخال لتحرّك ربحُ الأمس مع سعر الصرف. و`conversations` هو ما يفصل فشل الإعلان عن فشل البيع.

### `operating_daily_costs`
`effective_from` (فريد) · `amount` · `note` · `created_by`. المصروف التشغيلي الثابت **لا يُوزَّع على الأصناف** — هو لا يتغيّر بإيقاف إعلانٍ أو زيادته، ومكانُه بطاقة اليوم وحدها. وتاريخ السريان يمنع تغيّرَ الرواتب اليوم من إعادة كتابة ربح الشهر الماضي.

### `ad_external_maps` (ADR-052)
`provider` · `external_type` (campaign/adset) · `external_id` · `external_name` · `ad_channel_id` → ad_channels · `product_id` → products · `suggested_ad_channel_id` · `suggested_product_id` · `is_ignored` · `last_seen_at`. فريد (provider, external_type, external_id).
الربط **بالمعرّف لا بالاسم**: المطابقة النصّية تنكسر عند أول إعادة تسمية فيضيع صرفُ يومٍ بصمت. والاسم يبقى للعرض، والمقترح للتأكيد بنقرة — الربط الخاطئ يَنسب صرفًا إلى صنفٍ لم يُعلَن عليه، وهو خطأ لا يظهر في أي رقم.

### `ad_daily_spends` — أعمدة المزامنة (ADR-052)
`source` (manual/meta) · `synced_amount_usd` · `synced_conversations` · `synced_at`. المزامنة لا تدهس ما أُدخل باليد ولا تُخفي ما تقوله المنصّة: تُكتب قيمتها في `synced_*` ويُعرَض الاختلاف ليقرّر المستخدم.

### `orders` — نسبة الطلب الإلكتروني (ADR-054)
`ad_click_id` · `ad_source` · `ad_campaign_ref` · `ad_set_ref` (مفهرس).
`ad_channel_id` القائمة تُشتقّ من **الموظفة التي أدخلت الطلب** — وهي السلسلة الصحيحة لطلبات الرسائل. أمّا طلب الموقع فلا موظّف له فكان يسقط بلا قناة. والمفتاح العملي `ad_set_ref`: المنصّة لا تُخبرك بالحملة من `fbclid`، لكنها تسمح بمعاملاتٍ ديناميكية في رابط الإعلان — فيصل معرّف المجموعة الإعلانية في `utm_content`، ومنه الصنف والصفحة عبر `ad_external_maps` (الربط نفسه الذي يقوم عليه سحب الصرف، فلا مصدر ثانٍ للحقيقة). و`ad_click_id` يُحفَظ بصيغة `fb.1.{ms}.{fbclid}` لأن Conversions API تحتاجه للمطابقة — وهي أدقّ من البريد والهاتف.

### `ad_external_maps.parent_external_id`
الحملة الأمّ لكل مجموعة إعلانية. بدونها يعرف الجدول «المجموعة ← صنف» و«الحملة ← صفحة» ولا يعرف أن تلك المجموعة داخل تلك الحملة — وعليها تقوم نسبة الطلب الإلكتروني (ADR-054): من المجموعة إلى حملتها إلى صفحتها. والقيمة موجودة في كل صفّ نتائج بجانب `adset_id`، فتُلتقط أثناء المزامنة بلا نداءٍ إضافي.

> **أساس الربح هنا يفترق عن تقارير المبيعات الثلاثة عمدًا:** تلك تُبقي المرتجع مبيعًا، وهذه تستبعد حالة «مُرتجَع» وتخصم `returned_qty` بالتناسب — فالاحتساب على الطلبات المُدخَلة لا المسلَّمة، والـ5% تُلتقط حين تُسجَّل بلا انتظار. الفارق محصورٌ في `AdBudgetService`.

---

## مخطّط العلاقات (مبسّط)

```
users ──< roles/permissions
users ──1─ customers ──< addresses
customers ──< orders ──< order_items >── product_variants ──1─ products ──> categories
orders ──< shipments / returns / invoices
product_variants ──< inventory_stocks >── warehouses
inventory_movements >── product_variants / warehouses
suppliers ──< purchase_orders ──< purchase_order_items >── product_variants
purchase_orders ──< goods_receipts / supplier_invoices
accounts ──< journal_lines >── journal_entries
payments >── accounts
leads ──< opportunities ──< activities
affiliates ──< referrals / commissions / payout_requests
channels ──< conversations ──< messages
customers ──< loyalty_transactions
```

---

## مبادئ التصميم (متوافقة مع `ARCHITECTURE.md`)

- **مفاتيح داخلية** `BIGINT auto-increment` + **`uuid`** فريد مفهرس للكيانات المكشوفة خارجيًا.
- **مفاتيح أجنبية صريحة** مع قيود سلامة مرجعية.
- **فهارس** على الأعمدة كثيرة الاستعلام (`uuid`, `slug`, `sku`, `order_number`, حقول الحالة، المفاتيح الأجنبية، `branch_id`).
- **حذف ناعم** للكيانات المهمة؛ السجلّات الثابتة (`audit_logs`, `inventory_movements`, `journal_lines`) بلا Soft Delete.
- **مبالغ مالية** `decimal(15,2)` والكميات `decimal(15,3)` — **يُمنع `float`**.
- **حقول JSON** للبيانات المرنة (attributes، config، rules، settings).
- **جاهزية تعدد الفروع** عبر `branch_id`، وتعدد المستودعات عبر رصيد لكل مستودع.
- **جاهزية Multi-Tenant:** لا `tenant_id` الآن، لكن لا مفاتيح فريدة عامة تمنع إضافته لاحقًا (نستخدم فرادة مركّبة عند الحاجة).
- **الحالات** في جداول قابلة للإدارة، لا `enum` مغلق.
- التفاصيل الدقيقة (أطوال، قيم افتراضية، فهارس مركّبة) تُحسم داخل ملفات الترحيل في كل مرحلة.

</div>
