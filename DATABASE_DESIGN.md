<div dir="rtl">

# تصميم قاعدة البيانات — Tawfeer Online

> قاعدة البيانات: **MySQL 8** — محرك InnoDB، ترميز `utf8mb4_unicode_ci`.
> جميع الجداول تحوي `id` (BIGINT، مفتاح أساسي)، و`created_at` / `updated_at`، و`deleted_at` عند الحاجة للحذف الناعم (Soft Delete).

هذا تصميم مبدئي عالي المستوى يُفصَّل جدولًا-جدولًا داخل الترحيلات (migrations) في كل مرحلة.

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

### `audit_logs`
`user_id` → users · `action` · `model_type` · `model_id` · `changes (json)` · `ip`

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
`name` · `code` · `branch_id` (nullable — تعدد الفروع مستقبلًا) · `is_active`

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

## مبادئ التصميم

- **مفاتيح أجنبية صريحة** مع قيود سلامة مرجعية.
- **فهارس** على الأعمدة كثيرة الاستعلام (`slug`, `sku`, `order_number`, حقول الحالة، المفاتيح الأجنبية).
- **حذف ناعم** للكيانات المهمة (المنتجات، الطلبات، العملاء).
- **مبالغ مالية** بنوع `decimal(15,2)`.
- **حقول JSON** للبيانات المرنة (attributes، config، rules).
- **جاهزية تعدد الفروع** عبر `branch_id` في الجداول ذات الصلة.
- التفاصيل الدقيقة (أطوال، قيم افتراضية، فهارس مركّبة) تُحسم داخل ملفات الترحيل في كل مرحلة.

</div>
