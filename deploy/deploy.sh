#!/usr/bin/env bash
# ============================================================================
# Tawfeer Online — FIRST deploy of the app onto a provisioned VPS.
# Run as the DEPLOY_USER (not root):  bash deploy/deploy.sh
#
# - Clones the repo into APP_PATH if empty (or uses the existing checkout)
# - Installs prod dependencies, builds assets
# - Creates .env from the production template if missing (then STOPS for you
#   to fill secrets before the first migrate, unless .env already exists)
# - Generates APP_KEY, runs migrations, links storage, sets permissions,
#   caches config/routes/views, starts workers.
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${HERE}/deploy.env"
[[ -f "$ENV_FILE" ]] || { echo "ERROR: ${ENV_FILE} not found (copy deploy.env.example -> deploy.env)."; exit 1; }
# shellcheck disable=SC1090
source "$ENV_FILE"
: "${APP_PATH:?}"; : "${REPO_URL:?}"; : "${REPO_BRANCH:?}"; : "${DEPLOY_USER:?}"

log() { echo -e "\n\033[1;34m==> $*\033[0m"; }

[[ "$(whoami)" == "root" ]] && { echo "Run as ${DEPLOY_USER}, not root."; exit 1; }

# ---------------------------------------------------------------------------
log "Fetch code into ${APP_PATH}"
if [[ ! -d "${APP_PATH}/.git" ]]; then
  # APP_PATH may already contain this kit if you cloned there; handle both.
  if [[ -z "$(ls -A "${APP_PATH}" 2>/dev/null)" ]]; then
    git clone --branch "${REPO_BRANCH}" "${REPO_URL}" "${APP_PATH}"
  else
    echo "NOTE: ${APP_PATH} is non-empty and not a git repo; assuming code is already here."
  fi
else
  git -C "${APP_PATH}" fetch --all --prune
  git -C "${APP_PATH}" checkout "${REPO_BRANCH}"
  git -C "${APP_PATH}" pull --ff-only origin "${REPO_BRANCH}"
fi
cd "${APP_PATH}"

# ---------------------------------------------------------------------------
log "Composer (production)"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# ---------------------------------------------------------------------------
log "Environment file"
if [[ ! -f "${APP_PATH}/.env" ]]; then
  cp "${HERE}/env/.env.production.example" "${APP_PATH}/.env"
  # Pre-fill DB/Redis/URL from deploy.env for convenience (secrets you still verify).
  sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN}|" .env
  sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
  sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" .env
  sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env
  [[ -n "${REDIS_PASSWORD:-}" ]] && sed -i "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=${REDIS_PASSWORD}|" .env
  php artisan key:generate --force
  echo
  echo "  >>> .env created from template with DB/Redis/URL filled in."
  echo "  >>> Fill remaining secrets (mail, OpenAI, OAuth, payment/messaging) in ${APP_PATH}/.env,"
  echo "  >>> then re-run: bash deploy/deploy.sh"
  exit 0
fi

# ---------------------------------------------------------------------------
log "Frontend assets (production build)"
npm ci
npm run build

# ---------------------------------------------------------------------------
log "Storage + permissions"
php artisan storage:link || true
# Code owned by deploy user, group www-data; storage/cache group-writable + setgid.
sudo chown -R "${DEPLOY_USER}:www-data" "${APP_PATH}"
sudo find "${APP_PATH}/storage" "${APP_PATH}/bootstrap/cache" -type d -exec chmod 2775 {} \;
sudo find "${APP_PATH}/storage" "${APP_PATH}/bootstrap/cache" -type f -exec chmod 0664 {} \;

# ---------------------------------------------------------------------------
log "Database migrations (force)"
php artisan migrate --force
# Seed roles/permissions/settings/chart-of-accounts on a FRESH database only.
if [[ "${1:-}" == "--seed" ]]; then
  php artisan db:seed --force
fi

# ---------------------------------------------------------------------------
log "Optimize (config/route/view/event cache)"
php artisan optimize:clear
php artisan optimize

# ---------------------------------------------------------------------------
log "Reload PHP-FPM opcache + restart queue workers"
sudo systemctl reload "php${PHP_VERSION}-fpm" || true
php artisan queue:restart || true

log "First deploy complete. Visit https://${DOMAIN}"
