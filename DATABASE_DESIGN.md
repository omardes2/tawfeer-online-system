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
`category_id` → categories · `name` · `slug` · `sku` · `description` · `type` (simple/variable) · `is_active`

### `product_variants`
`product_id` → products · `sku` (فريد) · `barcode` · `attributes (json)` · `cost_price` · `sale_price`

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

---

## 5. المحاسبة (مزدوجة القيد)

### `accounts` (دليل الحسابات)
`parent_id` → accounts (شجري) · `code` · `name` · `type` (asset/liability/equity/revenue/expense) · `is_active`

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
