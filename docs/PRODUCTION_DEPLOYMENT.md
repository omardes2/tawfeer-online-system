# Production Deployment — Tawfeer Online (Hostinger VPS)

Authoritative guide for deploying **Tawfeer Online** to a Hostinger VPS.
Target host: **AlmaLinux 9 + cPanel/WHM**, **2 vCPU / 8 GB RAM / 100 GB disk**,
Apache (cPanel EA4). Do **not** put secrets in git — all secrets live only in the
server `.env` (copied from `.env.production.example`).

> Companion files: `.env.production.example`, `deploy/supervisor/tawfeer-worker.conf`,
> `deploy/cron/tawfeer-crontab.txt`. Backup/restore + index notes: `docs/OPERATIONS.md`.

---

## 0. Requirements summary

| Component | Requirement |
|---|---|
| PHP | **8.3+** (8.4 supported). Set as the domain's PHP version in cPanel (MultiPHP). |
| PHP extensions | `bcmath, ctype, curl, dom, fileinfo, gd` (or `imagick`), `intl, mbstring, openssl, pdo_mysql, redis, tokenizer, xml, zip` |
| Database | **MySQL 8.0** or **MariaDB 10.6+**, `utf8mb4` |
| Cache/Queue/Session | **Redis 6+** (phpredis extension) |
| Node (build only) | Node 18+ / npm — used once to build assets (can build locally and upload `public/build`) |
| Composer | 2.x |
| Web server | Apache (cPanel). Document root must point to `public/`. |
| SSL | Let's Encrypt (cPanel AutoSSL) or Cloudflare Origin cert |

Verify extensions after selecting the PHP version:
```bash
php -m | grep -Ei 'bcmath|curl|gd|intl|mbstring|pdo_mysql|redis|zip|openssl|tokenizer'
```

---

## 1. Provision the server

1. In WHM, create a cPanel account for the domain (or use an existing one). Note the
   account user, e.g. `tawfeer`, and its home `/home/tawfeer`.
2. Install **Redis** (root/WHM SSH):
   ```bash
   dnf install -y redis
   systemctl enable --now redis
   # set a password:
   sed -i 's/^# requirepass .*/requirepass YOUR_STRONG_REDIS_PASSWORD/' /etc/redis/redis.conf
   systemctl restart redis
   ```
   Install the PHP redis extension for the domain's PHP version (WHM → EasyApache 4
   → select `ea-php83-php-redis` (or `-pecl-redis`) → provision), or `pecl install redis`.
3. Install **Supervisor** for queue workers (root):
   ```bash
   dnf install -y supervisor
   systemctl enable --now supervisord
   ```
4. Ensure **Composer** and **Node** are available (Node only needed if building on the server).

---

## 2. Get the code

SSH as the cPanel user. Deploy **outside** the web root and point Apache's document
root at `app/public` — OR deploy into `~/tawfeer` and symlink. Recommended layout:

```bash
cd ~
git clone https://github.com/omardes2/tawfeer-online-system.git app
cd app
git checkout main        # or the released tag
```

Point the domain's **Document Root** (cPanel → Domains) to `/home/tawfeer/app/public`.

> If the panel forces docroot to `public_html`, either symlink
> `ln -s /home/tawfeer/app/public /home/tawfeer/public_html` (after emptying it) or
> place the app in `public_html`'s parent and set docroot to `.../public`.

---

## 3. Configure environment

```bash
cp .env.production.example .env
# edit .env — fill DB, Redis password, APP_URL, mail, OAuth, and (optionally)
# OPENAI_API_KEY / OPOST_* / messaging credentials. Keep AI_PROVIDER=null and
# MESSAGING_*=null until real credentials exist.
nano .env
```

Then:
```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate --force
```

---

## 4. Database

```bash
# In cPanel → MySQL Databases: create DB `tawfeer_prod` + user, grant ALL.
# Put those in .env (DB_DATABASE/DB_USERNAME/DB_PASSWORD), then:
php artisan migrate --force --seed
```

`--seed` runs `DatabaseSeeder`: branch, roles/permissions (incl. Phase 6
`ai.*`, `recommendations.*`, `marketing.*`, `kpis.view`), statuses, settings,
chart of accounts, the single main warehouse (`WH-MAIN`), payment methods, and the
admin user. **Single business, single main warehouse** — no branch/warehouse
multiplicity is created.

> First-run only. On later deploys run `php artisan migrate --force` (no `--seed`).

---

## 5. Build front-end assets

Option A — build on the server (needs Node):
```bash
npm ci
npm run build      # emits public/build (hashed, minified)
```
Option B — build locally and upload `public/build/` to the server (no Node on host).

---

## 6. Storage, permissions, caches

```bash
php artisan storage:link                     # public/storage -> storage/app/public
chmod -R ug+rw storage bootstrap/cache
# cPanel runs PHP as the account user, so ownership is already correct.

# Production optimizations (run on every deploy AFTER pulling code):
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

> Never cache config in a way that bakes secrets into git — `config:cache` writes to
> `bootstrap/cache/` (git-ignored) on the server only.

---

## 7. Queue worker (Supervisor)

Queued marketing sends (`SendCampaignMessageJob`) run on a worker.

```bash
# Copy and edit deploy/supervisor/tawfeer-worker.conf, replacing <USER>/<APP_PATH>:
cp deploy/supervisor/tawfeer-worker.conf /etc/supervisord.d/tawfeer-worker.ini
# edit /etc/supervisord.d/tawfeer-worker.ini  (USER=tawfeer, APP_PATH=/home/tawfeer/app)
supervisorctl reread && supervisorctl update && supervisorctl start tawfeer-worker:*
```

If Supervisor is unavailable, use the cron fallback in `deploy/cron/tawfeer-crontab.txt`.

**After every code deploy:** `php artisan queue:restart` (workers reload code).

---

## 8. Scheduler (cron)

Add ONE cron entry (cPanel → Cron Jobs, or `crontab -e`):
```
* * * * * cd /home/tawfeer/app && php artisan schedule:run >> /dev/null 2>&1
```
This drives `marketing:run-birthdays` (daily 09:00), `marketing:run-abandoned-carts`
(hourly), and — when enabled in `.env` — `delivery:sync` / `delivery:escalate-exceptions`.

---

## 9. SSL & Cloudflare

- **cPanel AutoSSL** (Let's Encrypt): SSL/TLS Status → Run AutoSSL. Force HTTPS
  (cPanel → Domains → Force HTTPS Redirect).
- **Cloudflare**: set SSL mode to **Full (strict)** with an Origin Certificate on the
  server. Keep `SESSION_SECURE_COOKIE=true` and `APP_URL=https://…`. If behind
  Cloudflare, trust proxies (Laravel `TrustProxies` already trusts all by default via
  the framework middleware) so `https` and client IP are detected — verify
  `request()->isSecure()` is true and rate-limit keys use the real client IP
  (`CF-Connecting-IP` via Cloudflare's `X-Forwarded-For`).
- HSTS: enable in cPanel or Cloudflare once HTTPS is confirmed.

---

## 10. Health checks & monitoring

- **Health endpoint:** `GET /up` (Laravel default) and `GET /api/v1/health`
  (returns `{status:ok, service, time}`). Point Hostinger/UptimeRobot at
  `https://your-domain.com/api/v1/health`.
- **Queue health:** `php artisan queue:failed` should stay empty; alert if
  `failed_jobs` grows. `supervisorctl status tawfeer-worker:*` = RUNNING.
- **Scheduler health:** confirm the cron runs (`storage/logs/` activity; or
  `php artisan schedule:list`).
- **Logs:** `storage/logs/laravel-YYYY-MM-DD.log` (daily channel, `LOG_LEVEL=warning`).

---

## 11. Log rotation

`LOG_CHANNEL=daily` keeps 14 files by default. Optionally add OS-level rotation:
```
# /etc/logrotate.d/tawfeer
/home/tawfeer/app/storage/logs/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    copytruncate
}
```

---

## 12. Backups

- **Database:** nightly `mysqldump` (see `docs/OPERATIONS.md` for the script), retain 7–14 days off-server (Hostinger backups or object storage).
- **Uploads:** back up `storage/app/public` (product/return images) with the DB.
- **`.env`:** back up securely **out of band** (it holds all secrets) — never in git.
- Test **restore** quarterly (documented in `docs/OPERATIONS.md`).

---

## 13. Maintenance mode & deploy

```bash
php artisan down --render="errors::503" --retry=60
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci && npm run build            # or upload prebuilt public/build
php artisan migrate --force
php artisan config:cache route:cache view:cache event:cache
php artisan queue:restart
php artisan up
```

---

## 14. Rollback

1. `php artisan down`
2. `git checkout <previous-tag>` (or previous commit).
3. `composer install --no-dev --optimize-autoloader`
4. **Migrations:** prefer roll-forward. Only `php artisan migrate:rollback --step=N`
   if the new migrations are safely reversible AND no data depends on them. Phase 6
   migrations are additive (tables + indexes) and safe to roll back. **Never** roll
   back append-only ledgers (`inventory_ledger`, `commission_entries`,
   `ai_generation_logs`, `audit_logs`) with data.
5. Rebuild assets to the matching version (redeploy `public/build` or `npm run build`).
6. `php artisan config:cache route:cache view:cache && php artisan queue:restart`
7. `php artisan up` and re-check `/api/v1/health`.

Keep the previous release's `public/build` and a fresh DB backup before every deploy
so rollback is fast.
