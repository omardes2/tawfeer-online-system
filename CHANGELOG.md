# Changelog

All notable changes to **Tawfeer Online** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
