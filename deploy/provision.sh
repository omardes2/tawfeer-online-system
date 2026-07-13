#!/usr/bin/env bash
# ============================================================================
# Tawfeer Online — server provisioning for a FRESH Ubuntu 24.04 VPS.
# Installs & configures: Nginx, PHP-FPM (+extensions), Composer, MariaDB,
# Redis, Supervisor, Node.js LTS, Git, UFW firewall.
#
# Idempotent: safe to re-run. Does NOT deploy the app (see deploy.sh).
# Run as root (or with sudo):  sudo bash deploy/provision.sh
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${HERE}/deploy.env"
[[ -f "$ENV_FILE" ]] || { echo "ERROR: ${ENV_FILE} not found. Copy deploy.env.example -> deploy.env and fill it in."; exit 1; }
# shellcheck disable=SC1090
source "$ENV_FILE"

: "${DOMAIN:?}"; : "${DEPLOY_USER:?}"; : "${APP_PATH:?}"; : "${PHP_VERSION:?}"
: "${DB_NAME:?}"; : "${DB_USER:?}"; : "${DB_PASSWORD:?}"; : "${NODE_MAJOR:?}"

[[ $EUID -eq 0 ]] || { echo "Run as root (sudo)."; exit 1; }
export DEBIAN_FRONTEND=noninteractive

log() { echo -e "\n\033[1;32m==> $*\033[0m"; }

# ---------------------------------------------------------------------------
log "Base packages + APT repos"
apt-get update -y
apt-get install -y software-properties-common curl ca-certificates gnupg lsb-release ufw git unzip acl
# ondrej/php — provides PHP 8.3 on Ubuntu 24.04
add-apt-repository -y ppa:ondrej/php
add-apt-repository -y ppa:ondrej/nginx || true
apt-get update -y

# ---------------------------------------------------------------------------
log "PHP ${PHP_VERSION} + FPM + extensions"
apt-get install -y \
  php${PHP_VERSION}-fpm php${PHP_VERSION}-cli php${PHP_VERSION}-common \
  php${PHP_VERSION}-mysql php${PHP_VERSION}-redis php${PHP_VERSION}-mbstring \
  php${PHP_VERSION}-xml php${PHP_VERSION}-curl php${PHP_VERSION}-bcmath \
  php${PHP_VERSION}-gd php${PHP_VERSION}-zip php${PHP_VERSION}-intl \
  php${PHP_VERSION}-gmp php${PHP_VERSION}-soap php${PHP_VERSION}-opcache

# Production php.ini hardening (FPM SAPI)
PHP_INI="/etc/php/${PHP_VERSION}/fpm/php.ini"
sed -i 's/^;\?display_errors\s*=.*/display_errors = Off/'              "$PHP_INI"
sed -i 's/^;\?expose_php\s*=.*/expose_php = Off/'                      "$PHP_INI"
sed -i 's/^;\?memory_limit\s*=.*/memory_limit = 512M/'                 "$PHP_INI"
sed -i 's/^;\?upload_max_filesize\s*=.*/upload_max_filesize = 25M/'    "$PHP_INI"
sed -i 's/^;\?post_max_size\s*=.*/post_max_size = 30M/'                "$PHP_INI"
sed -i 's/^;\?max_execution_time\s*=.*/max_execution_time = 60/'       "$PHP_INI"
# OPcache for production
cat > /etc/php/${PHP_VERSION}/fpm/conf.d/99-tawfeer-opcache.ini <<'OPC'
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.save_comments=1
OPC

# ---------------------------------------------------------------------------
log "Composer (global)"
if ! command -v composer >/dev/null 2>&1; then
  curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
composer --version

# ---------------------------------------------------------------------------
log "Node.js ${NODE_MAJOR} LTS + build tools"
if ! command -v node >/dev/null 2>&1 || [[ "$(node -v | grep -oE '[0-9]+' | head -1)" != "${NODE_MAJOR}" ]]; then
  curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash -
  apt-get install -y nodejs
fi
node -v; npm -v

# ---------------------------------------------------------------------------
log "MariaDB server"
apt-get install -y mariadb-server
systemctl enable --now mariadb
# Secure + set root password (idempotent; uses unix_socket first, then password)
MYSQL_ROOT_ARGS=()
if mysql -u root -e "SELECT 1" >/dev/null 2>&1; then MYSQL_ROOT_ARGS=(-u root); \
elif [[ -n "${DB_ROOT_PASSWORD:-}" ]] && mysql -u root -p"${DB_ROOT_PASSWORD}" -e "SELECT 1" >/dev/null 2>&1; then MYSQL_ROOT_ARGS=(-u root -p"${DB_ROOT_PASSWORD}"); fi
mysql "${MYSQL_ROOT_ARGS[@]}" <<SQL
DELETE FROM mysql.user WHERE User='';
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "MariaDB: database '${DB_NAME}' and user '${DB_USER}' ready."

# ---------------------------------------------------------------------------
log "Redis"
apt-get install -y redis-server
sed -i 's/^supervised .*/supervised systemd/' /etc/redis/redis.conf
sed -i 's/^# *maxmemory-policy .*/maxmemory-policy allkeys-lru/' /etc/redis/redis.conf
if [[ -n "${REDIS_PASSWORD:-}" ]]; then
  if grep -qE '^# *requirepass|^requirepass' /etc/redis/redis.conf; then
    sed -i "s/^# *requirepass .*/requirepass ${REDIS_PASSWORD}/; s/^requirepass .*/requirepass ${REDIS_PASSWORD}/" /etc/redis/redis.conf
  else
    echo "requirepass ${REDIS_PASSWORD}" >> /etc/redis/redis.conf
  fi
fi
systemctl enable --now redis-server
systemctl restart redis-server

# ---------------------------------------------------------------------------
log "Supervisor"
apt-get install -y supervisor
systemctl enable --now supervisor

# ---------------------------------------------------------------------------
log "Nginx"
apt-get install -y nginx
systemctl enable --now nginx

# ---------------------------------------------------------------------------
log "Deploy user '${DEPLOY_USER}' + app directory"
if ! id -u "${DEPLOY_USER}" >/dev/null 2>&1; then
  adduser --disabled-password --gecos "" "${DEPLOY_USER}"
  usermod -aG sudo "${DEPLOY_USER}"
fi
usermod -aG www-data "${DEPLOY_USER}"
mkdir -p "${APP_PATH}"
chown -R "${DEPLOY_USER}:www-data" "$(dirname "${APP_PATH}")/$(basename "${APP_PATH}")" || true
chown -R "${DEPLOY_USER}:www-data" "${APP_PATH}"

# ---------------------------------------------------------------------------
log "PHP-FPM pool (runs as ${DEPLOY_USER}, socket group www-data)"
POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/tawfeer.conf"
sed -e "s|<DEPLOY_USER>|${DEPLOY_USER}|g" \
    -e "s|<PHP_VERSION>|${PHP_VERSION}|g" \
    -e "s|<APP_PATH>|${APP_PATH}|g" \
    "${HERE}/php/tawfeer-fpm.conf" > "$POOL"
# Disable the default pool to avoid a stray www.sock
[[ -f "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" ]] && mv "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf.disabled" || true
systemctl enable --now "php${PHP_VERSION}-fpm"
systemctl restart "php${PHP_VERSION}-fpm"

# ---------------------------------------------------------------------------
log "Nginx virtual host"
VHOST="/etc/nginx/sites-available/tawfeer.conf"
sed -e "s|<DOMAIN>|${DOMAIN}|g" \
    -e "s|<DOMAIN_ALIASES>|${DOMAIN_ALIASES:-}|g" \
    -e "s|<APP_PATH>|${APP_PATH}|g" \
    -e "s|<PHP_VERSION>|${PHP_VERSION}|g" \
    "${HERE}/nginx/tawfeer.conf" > "$VHOST"
ln -sf "$VHOST" /etc/nginx/sites-enabled/tawfeer.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

# ---------------------------------------------------------------------------
log "Supervisor queue workers"
WORKER="/etc/supervisor/conf.d/tawfeer-worker.conf"
sed -e "s|<DEPLOY_USER>|${DEPLOY_USER}|g" \
    -e "s|<APP_PATH>|${APP_PATH}|g" \
    -e "s|<WORKER_PROCS>|${WORKER_PROCS:-2}|g" \
    "${HERE}/supervisor/tawfeer-worker.conf" > "$WORKER"
supervisorctl reread || true
supervisorctl update || true

# ---------------------------------------------------------------------------
log "Scheduler cron (for ${DEPLOY_USER})"
CRON_LINE="* * * * * cd ${APP_PATH} && php artisan schedule:run >> /dev/null 2>&1"
( crontab -u "${DEPLOY_USER}" -l 2>/dev/null | grep -v 'artisan schedule:run' ; echo "$CRON_LINE" ) | crontab -u "${DEPLOY_USER}" -

# ---------------------------------------------------------------------------
log "UFW firewall (SSH + HTTP + HTTPS)"
ufw allow OpenSSH
ufw allow 'Nginx Full'
yes | ufw enable || true
ufw status verbose

# ---------------------------------------------------------------------------
log "Certbot (Let's Encrypt) — installed, run after DNS points here"
apt-get install -y certbot python3-certbot-nginx

cat <<DONE

============================================================================
 Provisioning complete.

 Next:
   1) Switch to the deploy user:   sudo -iu ${DEPLOY_USER}
   2) Deploy the app:              cd ${APP_PATH} && bash <this-repo>/deploy/deploy.sh
      (or clone first — deploy.sh handles a fresh clone too)
   3) Enable HTTPS (DNS must resolve to this VPS):
        sudo certbot --nginx -d ${DOMAIN} ${DOMAIN_ALIASES:+-d ${DOMAIN_ALIASES// / -d }} \\
             --redirect --agree-tos -m ${LETSENCRYPT_EMAIL:-admin@${DOMAIN}} --no-eff-email
============================================================================
DONE
