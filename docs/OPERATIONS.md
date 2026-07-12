# Operations & Data Integrity — Tawfeer Online

Backup/restore, database indexes, append-only ledgers, and the single-business /
single-main-warehouse invariants. Companion to `docs/PRODUCTION_DEPLOYMENT.md`.

---

## 1. Backup

### 1.1 Database (nightly)
```bash
#!/usr/bin/env bash
# /home/tawfeer/bin/backup-db.sh  — schedule via cron at 02:30 daily.
set -euo pipefail
APP=/home/tawfeer/app
cd "$APP"
DB=$(php -r '$e=parse_ini_file(".env"); echo $e["DB_DATABASE"];')
USER=$(php -r '$e=parse_ini_file(".env"); echo $e["DB_USERNAME"];')
PASS=$(php -r '$e=parse_ini_file(".env"); echo $e["DB_PASSWORD"];')
OUT=/home/tawfeer/backups
mkdir -p "$OUT"
STAMP=$(date +%Y%m%d-%H%M)
mysqldump --single-transaction --quick --routines --triggers \
  -u"$USER" -p"$PASS" "$DB" | gzip > "$OUT/db-$STAMP.sql.gz"
# retain 14 days
find "$OUT" -name 'db-*.sql.gz' -mtime +14 -delete
```
Cron: `30 2 * * * /home/tawfeer/bin/backup-db.sh >> /home/tawfeer/backups/backup.log 2>&1`

### 1.2 Uploads
Product/return images live on the `public` disk (`storage/app/public`). Back them up
with the DB:
```bash
tar czf /home/tawfeer/backups/uploads-$(date +%Y%m%d).tgz -C /home/tawfeer/app storage/app/public
```

### 1.3 Secrets
Back up `.env` **out of band** (encrypted, off-server). It holds every secret and is
git-ignored. Never store it with the repo backups.

### 1.4 Off-site
Copy `db-*.sql.gz` + `uploads-*.tgz` to Hostinger snapshots or object storage nightly.

---

## 2. Restore (test quarterly)

```bash
php artisan down
# DB
gunzip < /home/tawfeer/backups/db-YYYYMMDD-HHMM.sql.gz | \
  mysql -u"$USER" -p"$PASS" "$DB"
# uploads
tar xzf /home/tawfeer/backups/uploads-YYYYMMDD.tgz -C /home/tawfeer/app
php artisan config:cache && php artisan up
# verify
php artisan migrate:status        # all "Ran"
curl -s https://your-domain.com/api/v1/health
```
Restore drill: on a staging box, load the latest dump, run `migrate:status`, log in,
open the KPI dashboard, place a test order. Record the restore time.

---

## 3. Database indexes

Production hot-path indexes are added by
`database/migrations/2026_07_12_229001_add_production_reporting_indexes.php` (additive).

| Table | Index | Serves |
|---|---|---|
| `orders` | `created_at`, `delivered_at` | every report/KPI date-range filter (ADR-042/047) |
| `delivery_settlements` | `(status, posted_at)` | finance/collected-sales KPIs |
| `shipments` | `(delivery_status, created_at)`, `closed_at` | delivery success/exception KPIs |
| `return_requests` | `created_at` | return-rate KPI, returns report |
| `commission_entries` | `created_at` | commissions in reports (already has `(earner_type,earner_id,state)`) |
| `campaign_messages` | `created_at`, `(customer_id, status, created_at)` | campaign KPIs + frequency-cap check |
| `message_suppressions` | `(contact, channel)` | opt-out check on every send |
| `ai_generation_logs` | `created_at` | AI usage/cost KPI |
| `recommendation_events` | `created_at` | recommendation CTR/conversion KPI |
| `carts` | `(status, updated_at)` | abandoned-cart KPI + scheduler sweep |

Pre-existing indexes relied upon (not re-added): `order_items(order_id,variant_id)`,
`product_variants(product_id, is_default)`, `inventory_stocks(variant_id, warehouse_id)`
unique, `products(category_id, brand_id, status, visibility)`,
`product_recommendations(product_id, type, kind)` + unique, `orders(assigned_to,
affiliate_id, customer_id, status)`.

Verify on the server: `SHOW INDEX FROM orders;` etc., or
`php artisan db:table orders`.

---

## 4. Append-only ledgers — never mutate/rollback with data

These tables are written once and never updated in place; they are the audit/financial
source of truth. Do **not** `UPDATE`/`DELETE` rows operationally, and do not
`migrate:rollback` them once populated:

- `inventory_ledger` / `inventory_movements` — stock truth (every change via `InventoryService`).
- `commission_entries` — commission accrual/eligibility/payout history.
- `delivery_status_transitions` / `delivery_provider_events` — canonical delivery history.
- `audit_logs` — central `Auditable` trail.
- `ai_generation_logs`, `recommendation_events`, `campaign_messages`,
  `message_suppressions` — Phase 6 append-only logs (`UPDATED_AT = null`).

Corrections are made by **new offsetting entries** (e.g. a reverse stock movement, a
new settlement line), never by editing history.

---

## 5. Foreign keys & unique constraints (integrity)

- All relational columns use `foreignId(...)->constrained()` with
  `cascadeOnDelete`/`restrictOnDelete`/`nullOnDelete` chosen per relationship
  (restrict for financial refs, cascade for owned children).
- Idempotency/dedup uniques: `campaign_messages.idempotency_key` (unique),
  `product_recommendations(product_id, recommended_product_id, type)` (unique),
  `inventory_stocks(variant_id, warehouse_id)` (unique),
  `failed_jobs.uuid` (unique). Business numbers (`orders.number`, `PO-`, `CNT-`, …)
  are generated inside a locked transaction per (type × year).

---

## 6. Single-business / single-main-warehouse invariants

- One default branch (`Branch::default()`) and **one main warehouse** `WH-MAIN`
  (`is_default`) seeded by `DatabaseSeeder`. No UI or seeder creates additional
  branches/warehouses. Stock is held per `(variant × warehouse)` but production runs a
  single warehouse.
- Availability everywhere = `Σ(on_hand − reserved)` for the variant, read via the
  shared `CartService::availableQty` (now eager-loadable to avoid N+1).
- Multi-branch/tenant remain **design-ready only** (ADR-003/004) — not enabled.
- Explicitly **not built** (out of scope): loyalty points, smart coupons, Aliphia,
  multiple warehouses/branches, delivery-claims module.

---

## 7. Queue & scheduler health

- Worker: `supervisorctl status tawfeer-worker:*` → RUNNING; `queue:failed` empty.
- Failed job retry: `php artisan queue:retry all`.
- Scheduler: `php artisan schedule:list` shows birthdays (daily 09:00), abandoned-carts
  (hourly), and delivery sync/escalation (when enabled).
- Queued marketing sends are idempotent (`SendCampaignMessageJob` skips already-sent
  messages; message rows carry a unique `idempotency_key`).
