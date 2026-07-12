# Changelog

All notable changes to **Tawfeer Online** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed — Phase 3.2 revised to multi-step + guest checkout (ADR-033, owner decision)
- Checkout now supports **both authenticated users and guests**, and replaces the
  single atomic endpoint with a **multi-step checkout session** — while
  **preserving the exact final atomic order-creation transaction and payment
  initiation** from the first 3.2 implementation. Minimum additive changes; no
  completed module redesigned.
- Dual identity: new `store.identity` middleware accepts a Sanctum user or a guest
  cart token (`X-Cart-Token`, UUID), and returns 401 when neither is present
  (preserves the 3.1 cart's unauthenticated behavior). Guest carts use the
  existing `carts.session_token` column (no schema change); added
  `CartService::resolveActive`/`forGuest` (existing `forUser` unchanged).
- New additive table `checkout_sessions` (uuid, cart_id, nullable user_id/
  session_token, status pending/placed/abandoned, accumulated shipping snapshot +
  payment_method_code, order_id on placement). No change to `orders`/`payments`/
  inventory.
- `CheckoutService` steps: `start` (rejects empty cart, dispatches
  `CheckoutStarted`) → `update` (progressive shipping/payment details) → `place`
  (completeness + availability re-check, then the preserved `placeOrder`
  transaction: create → confirm → reserveStock → payment initiate → cart
  converted, then `CheckoutCompleted`). `CheckoutStarted.user` is now nullable
  (guests).
- API: `POST /store/checkout` (start), `GET|PUT|PATCH /store/checkout/{session}`,
  `POST /store/checkout/{session}/place`. Per-session ownership enforced (403 on
  mismatch). `UpdateCheckoutSessionRequest` (all-optional, validated) replaces the
  old `CheckoutRequest`.
- Tests: checkout suite grew 9 → 13 (added full guest checkout + cross-identity
  access denial; migrated the existing 9 behaviours to the multi-step flow); the
  9 cart tests pass unchanged under the new middleware → **312 passing total**.
- Deferred extension point added: guest-cart merge into the account on login.

### Added — Phase 3.2 (Checkout)
- `CheckoutService` (ADR-033): pure orchestration converting the active cart into
  a sales Order via existing services — no new business logic, no new tables.
  Authenticated-only (self-scoped, consistent with the 3.1 cart), single atomic
  `POST /api/v1/store/checkout`, depth "order + payment initiation".
- Flow (one transaction): read cart + reject empty → re-validate each line is
  sellable/available (stock may change after add) → derive branch/warehouse
  (branch default warehouse) → `OrderService::create` (customer snapshot + cart
  lines, `channel=web`) → `confirm` → `reserveStock` (**reflects on inventory —
  Phase 3 acceptance criterion**) → `PaymentService::initiate` (COD stays pending
  until collection at delivery) → mark cart `converted`.
- Stable domain events (ADR-032): `CheckoutStarted` (before the transaction) and
  `CheckoutCompleted` (after successful commit) — Growth extension points, no
  listeners today.
- `CheckoutRequest` (shipping snapshot + `payment_method` exists/active),
  `CheckoutResource` (order + payment summary). Added a public
  `CartService::assertPurchasable` for reuse (no behavior change to 3.1).
- Tests: 9 new (guest denied, empty-cart rejected, successful checkout creates a
  reserved order + payment, inventory reservation reflected, cart converted +
  fresh cart next, inactive/unknown payment method rejected, stock-dropped
  rejection, events dispatched) → 308 passing total.

### Architecture — Growth & Commerce Intelligence readiness (docs only, ADR-032)
- Documented a future isolated bounded context "Growth & Commerce Intelligence"
  (smart cart, recommendations, promotions, loyalty/rewards/cashback, coupons,
  referrals, marketing automation, campaigns, segmentation, analytics) fully
  decoupled from the Commerce Engine. **No Growth feature is implemented; this is
  architecture/documentation only.**
- New `docs/GROWTH_COMMERCE_ARCHITECTURE.md` (canonical reference) + ADR-032 in
  `docs/DECISIONS.md`; note added to `ARCHITECTURE.md` principle 14.
- `docs/EVENTS.md`: added the Growth-facing stable domain-event contract
  (`Cart*`, `Checkout*`, `Order*`, `Shipment*`, `Payment*`, `ReviewSubmitted`,
  `CouponApplied`, `LoyaltyPointsEarned`) with equivalences to existing local
  event names — Growth modules subscribe without modifying existing services.
- Directives captured for future readiness without schema redesign: customer
  marketing fields via additive migrations only (no change to completed CRM
  schema); smart-cart/promotion/analytics/checkout extension points; all Growth
  messaging must go through the existing provider-agnostic Messaging layer.
- No code, migration, or business-logic change; completed modules untouched.

### Added — Phase 3.1 (Storefront Cart)
- New `Store` module (ADR-031): all logic in `CartService`; cart is self-scoped
  to the authenticated user (no policy/route-binding needed — a user only ever
  touches their own cart).
- Tables (2): `carts` (uuid/soft-delete; `user_id`/`customer_id`/`branch_id`/
  `session_token`/`status`) + `cart_items` (`variant_id`, `qty` decimal(15,3),
  `unit_price` decimal(15,2), unique per (cart, variant)).
- `CartService`: active cart created on demand (`forUser`); add/set/remove/clear
  items inside DB transactions; price is a catalog snapshot (promo price when
  present, else retail — no pricing engine); availability check
  Σ(on_hand − reserved) across warehouses; rejects non-sellable variants
  (inactive/hidden product); **no stock reservation in the cart** — reservation
  happens at order confirmation (ADR-009).
- API `/api/v1/store/cart`: GET cart; POST items; PUT/PATCH + DELETE
  items/{variant}; DELETE cart. Sanctum-authenticated; Form Requests for
  validation; `CartResource`.
- Production-readiness fix: reload a fresh cart instance before serializing so a
  first-access GET returns 200 (not the auto-201 JsonResource emits for a
  just-created model).
- Tests: 9 new (guest denied, empty cart on first access, promo pricing,
  accumulate, update/remove, clear, over-stock rejected, non-sellable rejected,
  per-user scoping) → 299 passing total.

### Added — Phase 2.10 (CRM / Customers)
- New `Crm` module (ADR-030): all logic in `CustomerService`; integration-ready.
- Tables (5): `customers` (uuid/soft-delete/audited; optional `user_id`;
  `credit_limit`/`loyalty_points` present for future linkage without schema
  changes; high-risk/blocked flags, cancel/return counters, `merged_into_id`) +
  `customer_phones` (multiple + primary), `customer_addresses` (multiple +
  default, referencing geography), `customer_contacts`, `customer_notes`
  (append-only timeline).
- `CustomerService`: create/update with child sync (single primary/default),
  phone normalization + phone-based duplicate detection across all numbers
  (BR-CUST-03/05), notes, block/unblock (BR-CUST-12), and merge (BR-CUST-14 —
  moves children + reassigns orders, marks + soft-deletes the merged record).
- Order linkage + immutable snapshot: order creation optionally links a customer
  (populates `orders.customer_id`, derives the snapshot from the customer); the
  historical customer snapshot on the order never changes when the customer is
  later edited; a blocked customer cannot place orders. `orders.customer_id`
  kept FK-less (as deferred in 2.6) with an added index.
- Integration-ready messaging layer: provider-agnostic
  `MessagingProviderInterface` + `MessagingManager` + Null driver +
  `config/messaging.php` (whatsapp/email/sms/marketing channels) — ready to wire
  real providers without touching CRM logic.
- API `/api/v1/crm/customers`: CRUD + notes, block/unblock, merge, duplicates.
  RBAC: 6 `crm.customers.*` permissions + Policy; `CrmPermissionSeeder`.
- Admin UI (Arabic RTL): customers list, create/edit (dynamic phones +
  addresses), detail with notes, block/unblock, and order history; customers
  nav link.
- Production-readiness fixes: consistent digits-only phone normalization so
  dedup matches regardless of `+`/separators; added the CRM model factory.
- Tests: 22 new (create-with-children, single primary/default, dedup,
  block/unblock, merge, order link + snapshot immutability, blocked-order guard,
  messaging layer, authorization, admin RTL) → 290 passing total.

### Added — Phase 2.9 (Accounting: Double-Entry Engine)
- Double-entry accounting engine (ADR-029): immutable journal entries + journal
  lines as the source of truth; balances always derived from the ledger, never
  stored.
- New `Accounting` module: `accounts` (tree chart of accounts, asset/liability/
  equity/revenue/expense), `fiscal_years` + `accounting_periods` (multi-year
  ready), `journal_entries` (JE-{YYYY}-{seq}, draft→posted), `journal_lines`
  (append-only, no updated_at/soft-delete). Chart of accounts + FY 2026 seeded.
- `AccountingService` holds all logic: createEntry (balanced, each line debit XOR
  credit, postable leaf accounts), post (draft→posted, open FY/period), reverse
  (reverse-not-delete — a new mirrored entry linking the original; "reversed" is
  derived, original never modified), recordEvent (config-driven account map),
  accountBalance + trialBalance derived from posted lines.
- Immutability enforced at the model level: posted entries and journal lines
  cannot be updated or deleted; corrections are made only via reversing entries
  (BR-ACC-08/09).
- Isolation via events (BR-ACC-11): business modules dispatch
  `FinancialEventOccurred`; a synchronous `PostFinancialEventToLedger` listener
  generates the journal entry through `AccountingService`. Accounting knows
  nothing about business modules — future integration with Sales/Purchasing/
  Inventory/Payments/Refunds/Taxes/Shipping is a config event-map entry, no code
  change.
- API `/api/v1/accounting/*`: accounts (+ balance), journal-entries CRUD +
  post/reverse, reports/trial-balance. RBAC: 7 `accounting.*` permissions +
  Policy; chart-of-accounts + permission seeders.
- Admin UI (Arabic RTL): journal entries (list, create with dynamic lines +
  live balance check, detail with post/reverse + immutability notice), chart of
  accounts, trial-balance report; accounting nav link.
- Production-readiness review fix: removed an N+1 in the journal index
  (is_reversed now uses an eager-loaded relation).
- Tests: 24 new (balanced entries, unbalanced/both-sided/non-postable rejects,
  posted + line immutability, reverse-not-delete, ledger-derived balances,
  event-listener posting, balanced trial balance, authorization, admin RTL) →
  270 passing total.

### Added — Phase 2.8 (Payments: Provider Integration Layer, COD, Refunds)
- Provider/Integration architecture (ADR-028): a single `PaymentProviderInterface`
  (charge/capture/verify/refund) with all providers behind it + a
  `PaymentProviderManager` as the only place a provider is instantiated (maps
  `payment_methods.driver` to a class in `config/payments.php`). Providers are
  reached exclusively through this layer — never from controllers/services.
- Adding a provider needs only a `payment_methods` row + a driver map entry, with
  no changes to order/checkout logic. Drivers: `OfflinePaymentProvider`
  (COD/bank transfer — manual capture, active) and `NullPaymentProvider`
  (unconfigured online gateways — safe placeholder). HyperPay/MyFatoorah/Stripe/
  PayPal/Moyasar seeded as disabled placeholders.
- Tables: `payment_methods` (registry), `payments` (uuid/soft-delete/audited,
  PAY-{YYYY}-{seq}), `payment_transactions` (append-only generic structure for
  provider references, statuses, callbacks, and metadata). Orders gain derived
  `payment_status` (payment_statuses vocab) + `amount_paid`.
- `PaymentService`: initiate (via the method's provider), capture (COD collection
  / gateway capture), handleCallback (webhook verify), refund (full/partial), and
  order payment-status recomputation — supports partial, cumulative, and refunded
  payments. All business logic in the service; DB transactions throughout.
- API `/api/v1/payments/*`: methods list, payments CRUD, capture, refund,
  callback. RBAC: 5 `payments.*` permissions + Policy; PaymentMethod +
  PaymentPermission seeders.
- Admin UI (Arabic RTL): payments list, create-from-order, detail with capture/
  refund actions + transaction log; payments nav link + status badge.
- Deferred with hooks: specific gateway integrations, accounting/treasury impact
  (2.9), Idempotency-Key, credit notes.
- Tests: 19 new (COD capture updates order, partial/cumulative payments,
  full/partial refunds, double-capture + cancelled-order + over-refund guards,
  null-gateway stays pending, manager driver resolution, authorization, admin
  RTL) → 246 passing total.

### Added — Phase 2.7 (Shipping: Geography, Shipments, Delivery-Provider Integration)
- Design-first (ADR-027, `docs/PHASE_2_7_SHIPPING_DESIGN.md`): lowers the frozen
  shipping rules into a schema, since no frozen shipments table existed and the
  frozen geography tables were never built.
- Geography (built exactly per PHASE_2_DESIGN §3-6): `governorates`, `cities`,
  `areas` (reference tables) + `shipping_zones` (uuid/soft-delete/audited) with
  `shipping_zone_city`/`shipping_zone_area` pivots; basic geography seed.
- Multi-provider integration: `delivery_providers` registry + polymorphic
  `geo_provider_mappings` (delivery_provider_id, external_id, external_code,
  provider_metadata JSON, last_synced_at, sync_status, is_active) — the same
  local city/area maps to multiple providers simultaneously; local tables stay
  the source of truth; disabling/replacing a provider loses no local data.
- New `Shipping` module: `shipments` (+ append-only `shipment_events`) linked to
  orders — address snapshot with mapped area/city IDs (not name-based), single
  `delivery_provider_id`, and a frozen shipping-cost snapshot.
- `ShipmentService` state machine (not_shipped → in_transit → out_for_delivery →
  delivered, + failed and operational delayed/customer_unavailable) completes
  the delivery sub-states Phase 2.6 deferred and syncs the parent order; does
  not re-run inventory deduction (stays in OrderService::ship). Shipment
  statuses extended (ADR-017).
- Delivery-provider integration layer (ARCHITECTURE §13 / ADR-019):
  `DeliveryProviderInterface`, `GeographySyncProviderInterface`,
  `ShippingQuoteProviderInterface` + Null drivers, bound via `config/shipping.php`;
  providers reached only through the layer. No specific provider implemented.
- `ShippingCostResolver` with priority/fallback (live quote → last synced →
  local zone price → manual override → manual review); manual/live-null branches
  active, pricing engine deferred. Result snapshotted onto shipment + order.
- API `/api/v1/geo/*` (read) and `/api/v1/shipping/shipments` (CRUD + dispatch/
  out-for-delivery/delay/customer-unavailable/deliver/fail/override-cost).
- RBAC: 9 `shipping.*` / `settings.geography.*` permissions + Policy;
  `ShippingPermissionSeeder`.
- Admin UI (Arabic RTL): shipments list, create-from-shipped-order, detail page
  with delivery-transition buttons + event log, and a geography view; shipping
  nav link + status-badge component.
- Deferred with hooks: shipping-cost engine, specific provider integration, live
  sync, payments/COD (2.8), revenue accounting (2.9), customer/address CRM (2.10).
- Tests: 19 new (shipment lifecycle + order sync, one-shipment-per-order guard,
  cost override permission, multi-provider mapping, transition guards,
  authorization, admin RTL) → 227 passing total.

### Added — Phase 2.6 (Sales Orders)
- Design-first: `docs/PHASE_2_6_SALES_ORDERS_DESIGN.md` + ADR-026 lower the
  frozen order rules (ADR-009/010/010a, BR-ORD-01..18, status vocabulary) into
  a concrete schema — orders had no frozen table design (deferred to "Phase 3").
- New `Sales` module (`app/Modules/Sales`): 3 models, `OrderService`, policy,
  service provider.
- Tables (3): `orders` (uuid, soft-delete, audited; inline customer snapshot +
  deferred `customer_id` with no FK), `order_items` (frozen unit_price snapshot
  per BR-ORD-18; `qty_reserved`/`qty_shipped`), `order_status_history`
  (append-only transition log per BR-ORD-09). FKs, indexes, composite unique.
- `OrderService` state machine: draft → confirmed → stock_reserved → preparing
  → ready_to_ship → shipped → delivered (+cancelled); illegal transitions
  rejected; every transition recorded, inside `DB::transaction`.
- Inventory timing per ADR-009, routed **exclusively** through the existing
  Reservation/Inventory services: reserve on `stock_reserved`, consume + issue
  (COGS at WAC) on `shipped`, release on cancel-before-ship. Added
  `ReservationService::consume` implementing the frozen `active → consumed`
  reservation state.
- Order statuses extended (`StatusSeeder`) to the full ADR-010 18-state
  vocabulary (manageable, ADR-017).
- API `/api/v1/sales/orders`: CRUD + confirm/reserve/prepare/ready/ship/
  deliver/cancel.
- RBAC: 9 granular `sales.orders.*` permissions + Policy; `SalesPermissionSeeder`
  (manager full, sales up to reserve/cancel, warehouse ship/deliver, accountant
  read-only).
- Admin UI (Arabic RTL): orders list, create form (dynamic line items), and a
  detail page with lifecycle action buttons + status history; sales nav link and
  a shared order status-badge component.
- Deferred with hooks (no code now): pricing engine, tax, shipping (2.7),
  payments (2.8), revenue/COGS accounting (2.9), commission, returns, full CRM
  (2.10), web/POS channels; domain-event emission deferred to its phases.
- Tests: 17 new (lifecycle inventory effects per ADR-009, cancel releases
  reservation, illegal-transition guards, price snapshot, authorization, admin
  RTL render) → 206 passing total.

### Added — Phase 2.5 (Purchasing: Suppliers, Purchase Orders, Goods Receipts, Supplier Returns)
- New `Purchasing` module (`app/Modules/Purchasing`): 8 models, 4 services,
  4 policies, and a service provider registering the policies.
- Tables (8): `suppliers` (uuid, soft-delete, audited), `supplier_contacts`,
  `purchase_orders` (+ `purchase_order_items`), `goods_receipts`
  (+ `goods_receipt_items`), `supplier_returns` (+ `supplier_return_items`);
  FKs, composite uniques, indexes; geography/currency FKs deferred (ADR-025).
- ADR-025 (Purchasing schema): PO/GRN/PRET-{YYYY}-{seq} numbering; GRN
  draft→posted, return draft→approved→posted; new movement type
  `purchase_return_out`; landed cost allocated by line value before WAC.
- `PurchaseOrderService`: draft → pending_approval → approved →
  partially_received → received → closed (+cancelled); editable until
  approval (BR-PUR-02); cancel blocked once any receipt is posted (BR-PUR-12).
- `GoodsReceiptService`: no receipt before PO approval (BR-PUR-03); **posting
  raises stock exclusively through `InventoryService::receive`**, allocating
  landed cost (`additional_cost`) by line value (BR-PUR-06), incrementing PO
  line `qty_received` and recomputing PO status (BR-PUR-05).
- `SupplierReturnService`: **posting reduces stock exclusively through
  `InventoryService::purchaseReturn`** (`purchase_return_out`, BR-PUR-11);
  returns exceeding available stock are rejected by the engine.
- `SupplierService`: single-primary-contact enforcement; contact sync.
- API `/api/v1/purchasing/*`: suppliers (CRUD), orders (CRUD + submit/approve/
  cancel/close), receipts (index/show/store/post), returns (index/show/store/
  approve/post); cost fields gated by `pricing.view_cost` (ADR-013).
- RBAC: 18 granular `purchasing.*` permissions (ADR-021) + Policies;
  `PurchasingPermissionSeeder` (admin/manager full, warehouse operational,
  accountant read-only).
- Admin UI (Arabic RTL): suppliers list/form (with contacts), purchase orders
  list/form (dynamic line items)/show (full lifecycle), goods receipts
  (pick order → receive → post), supplier returns (create/approve/post);
  purchasing nav link and a shared status-badge component.
- Tests: 24 new (supplier CRUD/authz, PO lifecycle + state guards, receipt
  posting raises inventory with landed cost, return posting reduces inventory,
  cost gating, admin RTL render) → 188 passing total.

### Added — Phase 2.4 (Inventory, Movements, Reservations, Adjustments)
- Prerequisite (ADR-024): `product_variants` (§17) as the inventory
  substrate; `ProductService` auto-creates one default variant per product.
- Tables: `inventory_stocks` (buckets on_hand/reserved/damaged/
  returned_pending/in_transit + WAC), `inventory_movements` (append-only,
  10 canonical types), `inventory_ledger` (append-only, running balance +
  WAC, no updated_at/deleted_at), `stock_reservations`, `stock_adjustments`
  (+ items). FKs, composite uniques, indexes; order_id deferred (Phase 3).
- New `Inventory` module (models, services, policies, service provider).
- `InventoryService` engine: receive/issue/transfer with weighted-average
  cost recompute (ADR-005), negative-stock prevention per
  `warehouse.allow_negative` (ADR-007a), every op inside `DB::transaction`
  with `lockForUpdate`, writing a movement + ledger entry (ADR-008).
- `ReservationService` (reserve/release on the reserved bucket, blocks
  reserving beyond available); `StockAdjustmentService` (draft →
  pending_approval → approved → posted, `ADJ-{YYYY}-{seq}` numbering via
  `NumberGenerator`, post applies per-item diffs). Full auditability.
- API `/api/v1/inventory/*`: stocks, movements, ledger, receive, issue,
  transfer, reservations (create/release), adjustments (CRUD/approve/post);
  cost fields gated by `pricing.view_cost` (ADR-013).
- RBAC: 14 granular `inventory.*` permissions (ADR-021) + Policies;
  `InventoryPermissionSeeder`.
- Admin UI (Arabic RTL): stock levels, movement log, reservations, an
  operations page (receive/issue/transfer), and adjustments
  (create/approve/post); inventory nav link.
- Tests: 27 new (operations, reservations, adjustments, service unit,
  admin web) → 164 passing total.

### Added — Phase 2.3 (Products & Media)
- Tables: `products` (design §16 + ADR-023 extensions), `product_images`,
  `product_tag_links`, `product_attribute_links` — FKs, composite uniques,
  indexes; `tax_id`/`variant_id` FKs deferred per the dependency plan.
- ADR-023: product catalog fields extension — bilingual name/description,
  editorial `status` (draft/active/archived), `visibility`, `is_featured`,
  `sort_order`, SEO fields, and search metadata; `is_active` derived from
  status by the service. Price columns exist per design but no pricing
  engine is implemented in 2.3 (cost fields read-gated by pricing.view_cost).
- Models `Product` (uuid, soft-delete, audited) and `ProductImage`, with
  category/brand/unit belongs-to, tags/attributes many-to-many, images
  has-many; factories.
- Services: `ProductService` (slug, is_active sync, tag/attribute sync,
  transactions) and `ProductImageService` (upload to public disk, single
  primary enforcement, promote-on-delete).
- API under `/api/v1/products` + nested images (upload / set-primary /
  delete, scoped binding); filter/search/sort/paginate; ProductResource
  with ADR-013 cost-field gating.
- RBAC: `catalog.products.*` + `pricing.view_cost` via Policies;
  `ProductPermissionSeeder`.
- Admin UI (Arabic RTL, responsive): product list + full bilingual form
  (classification, tags/attributes, status/visibility/featured, SEO) and
  image gallery with upload/set-primary/delete; products nav link.
- Tests: 33 new (product API, image API, admin web, ProductService unit) →
  136 passing total.

### Added — Phase 2.2 (Catalog: Categories, Brands, Units, Attributes, Values, Tags)
- New `Catalog` module (`app/Modules/Catalog`): 6 models, 6 services,
  policies, and a service provider registering the policies.
- Migrations: `categories` (tree, uuid, soft-delete, audited), `brands`
  (uuid, soft-delete, audited), `units` (self base-unit + conversion),
  `product_attributes`, `product_attribute_values` (unique per attribute),
  `product_tags` — with FKs, composite uniques, and indexes per design.
- Business logic in services: unique slug generation, category tree
  cycle-prevention, and safe deletes (block deleting a category with
  children or a unit referenced as a base).
- REST API under `/api/v1` for all six (filter/search/sort/paginate,
  nested attribute values with scoped binding); categories/brands by uuid,
  reference entities by id (ADR-002). Cost-free reference data.
- RBAC: 20 granular `catalog.{resource}.{action}` permissions (ADR-021)
  plus authorization Policies; `CatalogPermissionSeeder` + `UnitSeeder`.
- Admin UI (Arabic RTL, responsive, English-localizable): list + create/edit
  pages for all entities, inline attribute-value management, catalog nav.
- Tests: 43 new (API + admin web incl. RTL render) → 103 passing total.

### Added — Phase 2.1 (Branches, Warehouses, Storage Locations)
- Migrations: extend `branches` (email, tax_number, default_currency_id,
  default_warehouse_id, timezone); new `branch_settings`, `warehouses`,
  `warehouse_locations` (deferred FKs for geography/currency per design).
- Models: `Warehouse`, `WarehouseLocation`, `BranchSetting` (Foundation
  module) + `Branch` relations; UUID, SoftDeletes, Auditable as applicable.
- API (`/api/v1`): full CRUD for branches, warehouses, and nested
  warehouse locations — UUID route binding, scoped nested binding, atomic
  transactions, single-default enforcement per scope.
- RBAC: 12 granular `settings.{branches,warehouses,warehouse_locations}.*`
  permissions (ADR-021), seeded and granted to admin/manager/warehouse.
- Seeders: `StructurePermissionSeeder`, `WarehouseSeeder` (default warehouse
  linked to the default branch).
- Tests: 25 feature tests (58 total passing); verified live via the server.

### Added — Phase 1.5 (Business Analysis & Design)
- `docs/DECISIONS.md`: 22 architecture decision records + canonical status
  vocabularies (single source of truth) — WAC costing, stock buckets,
  reservation/revenue timing, commission model, permission scheme, etc.
- `docs/BUSINESS_RULES.md`, `USER_JOURNEYS.md` (11 roles), `EVENTS.md`
  (25 domain events), `AUTOMATIONS.md`, `REPORTS.md` (25 reports),
  `API_CONTRACT.md`, `UI_NAVIGATION.md`, `APPROVAL_WORKFLOWS.md`,
  `NOTIFICATION_MATRIX.md`.
- `docs/PHASE_2_DESIGN.md` + `docs/DATA_DICTIONARY.md`: detailed schema for
  27 Phase 2 entities (+6 supporting tables) with a dependency-safe order.
- `docs/DESIGN_REVIEW.md`: consistency review, resolved conflicts, open
  questions, risks, Phase 2 batch sequence, and effort estimates.
- No application code, migrations, models, controllers, APIs, or UI created.

### Planned — Phase 2 (Catalog & Inventory)
- 27 entities across 6 batches (2A geography → 2F inventory)
- Multi-warehouse stock (buckets), WAC costing, ledger, reservations,
  transfers, adjustments — all atomic and audited
- Reorder-level / low-stock alerts

## [0.1.0-foundation] — 2026-07-11

Foundation freeze. Establishes the technical base and all 14 binding
architecture principles before feature development begins.

### Added
- **Framework**: Laravel 13.19 on PHP 8.4, Tailwind CSS 3 + Alpine.js.
- **Auth**: Laravel Breeze (Blade) — register, login, password reset, profile.
- **API (API-First)**: Laravel Sanctum; versioned `/api/v1` routes
  (`/health` public, `/me` protected); `UserResource` exposing UUID,
  roles, and permissions.
- **RBAC**: `spatie/laravel-permission` with 7 roles (admin, manager,
  sales, accountant, warehouse, affiliate, customer) and 27 grouped
  permissions; role-aware dashboard.
- **Localization**: Arabic RTL-first UI (Tajawal font, `dir=rtl`) with
  `ar`/`en` language files.
- **Architecture foundations**:
  - `Branch` model + default branch (Multi-Branch ready).
  - `HasUuid` trait — external UUIDs alongside internal BIGINT keys.
  - `Auditable` trait + central append-only `audit_logs` (auto-captured).
  - Dynamic DB-backed `Settings` with cache + facade.
  - Manageable order/payment/shipment statuses (seeded).
  - `SoftDeletes` and Decimal-only money conventions.
  - `RunsInTransaction` trait for atomic financial/inventory operations.
  - Integration layer: `PaymentGateway` contract + `NullPaymentGateway` driver.
  - Independent module structure under `app/Modules` (Foundation module).
- **Database**: 10 migrations, 6 seeders.
- **Docs**: `ARCHITECTURE.md`, `REQUIREMENTS.md`, `DATABASE_DESIGN.md`,
  `PROJECT_PLAN.md`, `DEPLOYMENT.md`, `CLAUDE.md`, `docs/FOUNDATION_REPORT.md`.
- **CI**: project test workflow (PHP 8.4, SQLite in-memory).

### Changed
- Account deletion uses soft delete; Breeze profile test aligned accordingly.

### Tests
- 33 passing tests, 81 assertions (PHPUnit 12), incl. `FoundationTest`
  verifying each architecture principle.

[Unreleased]: https://github.com/omardes2/tawfeer-online-system/compare/v0.1.0-foundation...HEAD
[0.1.0-foundation]: https://github.com/omardes2/tawfeer-online-system/releases/tag/v0.1.0-foundation
