<!-- Ubuntu 24.04 production deployment runbook for Tawfeer Online. -->

# Deployment Runbook — Ubuntu 24.04 VPS (Hostinger)

Complete, production-grade deployment of **Tawfeer Online** onto a fresh Ubuntu 24.04 server, driven by the automation in `deploy/`. Everything (secrets, domain, DB creds) is parameterized in a single **server-side** file `deploy/deploy.env` that is never committed.

> **Stack installed:** Nginx · PHP 8.3-FPM (+ mysql, redis, mbstring, xml, curl, bcmath, gd, zip, intl, gmp, soap, opcache) · Composer · MariaDB 10.11 · Redis (auth + LRU) · Supervisor · Node.js 22 LTS · Git · UFW firewall · Certbot.

---

## 0. Prerequisites
- Fresh Ubuntu 24.04 VPS with root/sudo SSH access.
- A domain with a **DNS A-record pointing at the VPS public IP** (needed for HTTPS).
- The repo reachable from the server (public, or a deploy key / token for private).

---

## 1. Get the deploy kit onto the server
SSH in as root (or a sudo user) and pull the repo once to get `deploy/`:

```bash
sudo apt-get update && sudo apt-get install -y git
sudo git clone https://github.com/omardes2/tawfeer-online-system.git /var/www/tawfeer
cd /var/www/tawfeer
```

## 2. Configure parameters (secrets stay on the server)
```bash
cp deploy/deploy.env.example deploy/deploy.env
nano deploy/deploy.env   # set DOMAIN, DB/Redis passwords, DEPLOY_USER, REPO_BRANCH, LETSENCRYPT_EMAIL...
```
`deploy/deploy.env` is git-ignored — it never leaves the box.

## 3. Provision the server (one command, idempotent)
```bash
sudo bash deploy/provision.sh
```
This installs and configures **everything in section 0**: PHP-FPM pool, Nginx vhost, MariaDB database+user, Redis (with password + `allkeys-lru`), Supervisor queue workers, the scheduler cron, the UFW firewall (SSH/80/443), OPcache, and a hardened `php.ini`. Safe to re-run.

## 4. Deploy the application
Switch to the deploy user and run the first-deploy script:
```bash
sudo -iu deployer          # the DEPLOY_USER from deploy.env
cd /var/www/tawfeer
bash deploy/deploy.sh
```
On the **first run** it creates `.env` from `deploy/env/.env.production.example` (with DB/Redis/URL pre-filled), generates `APP_KEY`, then **stops** so you can add the remaining secrets:
```bash
nano /var/www/tawfeer/.env   # MAIL_*, OPENAI_API_KEY, GOOGLE_*/FACEBOOK_*, payment/messaging tokens
```
Then run it again to finish (composer, `npm ci && npm run build`, migrate, storage link, permissions, optimize, start workers):
```bash
bash deploy/deploy.sh            # existing DB
# or, for a brand-new empty database, seed roles/permissions/settings/chart-of-accounts:
bash deploy/deploy.sh --seed
```

## 5. Enable HTTPS (Let's Encrypt)
DNS must already resolve to the VPS. Certbot edits the Nginx vhost in place (adds the 443 block + HTTP→HTTPS redirect):
```bash
sudo certbot --nginx -d example.com -d www.example.com --redirect \
     --agree-tos -m admin@example.com --no-eff-email
```
Auto-renewal is installed by default (`systemctl status certbot.timer`).

## 6. Verify
```bash
sudo systemctl status nginx php8.3-fpm mariadb redis-server supervisor --no-pager
sudo supervisorctl status tawfeer-worker:*        # workers RUNNING
crontab -u deployer -l | grep schedule:run        # scheduler present
curl -I https://example.com                        # 200/301, HSTS after TLS
php artisan about                                  # env=production, debug=false, cache=redis
```

---

## 7. Routine updates (the only commands you need)
From `/var/www/tawfeer` as the deploy user:
```bash
bash deploy/update.sh
```
which runs exactly your intended flow inside a maintenance window, with production-safe extras:
```
php artisan down            → git pull → composer install --no-dev --optimize-autoloader
→ php artisan migrate --force → npm ci && npm run build → php artisan optimize
→ reload php-fpm (opcache) → php artisan queue:restart → php artisan up
```
Prefer the script (it also restarts workers and lifts maintenance on failure). The raw sequence still works if you ever need it manually:
```bash
git pull
composer install --no-dev
php artisan migrate --force
npm run build
php artisan optimize
```

---

## 8. What each file does
| File | Role |
|---|---|
| `deploy/deploy.env(.example)` | All parameters/secrets. **Server-only**, git-ignored. |
| `deploy/provision.sh` | Idempotent server provisioning (packages, firewall, services, configs). |
| `deploy/deploy.sh` | First app deploy (clone, env, build, migrate, optimize, workers). |
| `deploy/update.sh` | Routine redeploy wrapping `git pull → … → optimize`. |
| `deploy/nginx/tawfeer.conf` | Nginx vhost (gzip, static caching, security headers, PHP-FPM, hidden-file deny, SSL-ready). |
| `deploy/php/tawfeer-fpm.conf` | Dedicated PHP-FPM pool (runs as deploy user, socket group `www-data`, OPcache, tuned `pm`). |
| `deploy/supervisor/tawfeer-worker.conf` | Redis queue workers under Supervisor. |
| `deploy/cron/tawfeer-crontab.txt` | Reference for the scheduler cron (installed automatically by provision.sh). |
| `deploy/env/.env.production.example` | Production `.env` template (APP_ENV=production, debug off, secure cookies, Redis cache/queue). |

---

## 9. Production notes & best practices applied
- **`APP_DEBUG=false`, `APP_ENV=production`, `expose_php=Off`, `display_errors=Off`** — no stack traces or version leakage.
- **OPcache** with `validate_timestamps=0` — recompilation avoided; `deploy.sh`/`update.sh` reload FPM so new code is picked up.
- **Secure session cookies** (`SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`) — set only after HTTPS is live.
- **Redis** bound to `127.0.0.1` with `requirepass` and `allkeys-lru` eviction.
- **Least-privilege DB user** scoped to the app database only; no `test` DB, no anonymous users.
- **Dedicated FPM pool** as the deploy user with a group-`www-data` socket (Nginx reads it; app writes `storage/`).
- **Firewall** default-deny inbound except SSH/HTTP/HTTPS.
- **Queue workers** gracefully reload on deploy via `queue:restart`; capped `--max-time` recycles memory.
- **Maintenance window** (`artisan down`/`up`) around every update, lifted even on failure.
- **Cache serialization** hardened via an allow-list (`config/cache.php` `serializable_classes`) — blocks gadget-chain classes while allowing the app's own cached collections.
- **Storage permissions**: code owned by deploy user, `storage/` + `bootstrap/cache/` group-writable with setgid.

## 10. Rollback
Code is a git checkout, so roll back to a previous tag/commit and re-run the tail of the update:
```bash
git checkout <previous-tag>
composer install --no-dev --optimize-autoloader
php artisan migrate --force        # only if the newer migration is backward-compatible; otherwise restore a DB backup
npm ci && npm run build
php artisan optimize && php artisan queue:restart
```
Always take a DB dump before a release: `mysqldump -u tawfeer -p tawfeer > backup-$(date +%F).sql`.
