#!/usr/bin/env bash
# ============================================================================
# Tawfeer Online — routine update / redeploy.
# Run as the DEPLOY_USER from APP_PATH:  bash deploy/update.sh
#
# Wraps the exact update flow:
#   git pull
#   composer install --no-dev
#   php artisan migrate --force
#   npm run build
#   php artisan optimize
# ...plus the production-safe extras (maintenance window, autoloader
# optimization, storage perms, opcache reload, graceful worker restart).
# ============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${HERE}/deploy.env"
[[ -f "$ENV_FILE" ]] || { echo "ERROR: ${ENV_FILE} not found."; exit 1; }
# shellcheck disable=SC1090
source "$ENV_FILE"
: "${APP_PATH:?}"; : "${REPO_BRANCH:?}"; : "${PHP_VERSION:?}"; : "${DEPLOY_USER:?}"

cd "${APP_PATH}"
# يسمح بتشغيل composer عند النشر بمستخدم root دون توقّف على سؤال تأكيد.
export COMPOSER_ALLOW_SUPERUSER=1
log() { echo -e "\n\033[1;34m==> $*\033[0m"; }

log "Maintenance mode ON"
php artisan down --retry=15 || true
trap 'php artisan up || true' EXIT   # always lift maintenance, even on failure

log "git pull"
git fetch --all --prune
git checkout "${REPO_BRANCH}"
git pull --ff-only origin "${REPO_BRANCH}"

log "composer install --no-dev"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

log "php artisan migrate --force"
php artisan migrate --force

log "npm run build"
npm ci
npm run build

log "Storage permissions"
sudo find "${APP_PATH}/storage" "${APP_PATH}/bootstrap/cache" -type d -exec chmod 2775 {} \; || true

log "php artisan optimize"
php artisan optimize:clear
php artisan optimize

log "Reload opcache + restart workers"
sudo systemctl reload "php${PHP_VERSION}-fpm" || true
php artisan queue:restart || true

log "Maintenance mode OFF"
php artisan up
trap - EXIT

log "Update complete."
