# Changelog

All notable changes to **Tawfeer Online** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
