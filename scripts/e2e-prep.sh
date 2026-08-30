#!/usr/bin/env bash
# Prepare the shared Nextcloud Docker stack for EinkaufCheck Playwright E2E.
# Does not reset the existing admin password.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
NC_DIR="$(cd "$SCRIPT_DIR/../../.." && pwd)"
USER="${E2E_USER:-ekctest}"
PASS="${E2E_PASS:-Ekctest-24149}"

cd "$NC_DIR"

if ! sg docker -c 'docker compose ps --status running --services' 2>/dev/null | grep -qx nextcloud; then
	echo "error: nextcloud service is not running (cd nextcloud && docker compose up -d)" >&2
	exit 1
fi

echo "Clearing bruteforce for loopback…"
sg docker -c 'docker compose exec -T -u www-data nextcloud php occ security:bruteforce:reset 127.0.0.1' || true
sg docker -c 'docker compose exec -T -u www-data nextcloud php occ security:bruteforce:reset ::1' || true

GATEWAY="$(sg docker -c "docker compose exec -T nextcloud sh -c \"ip route 2>/dev/null | awk '/default/ {print \\\$3; exit}'\"" || true)"
if [ -n "${GATEWAY}" ]; then
	echo "Clearing bruteforce for compose gateway ${GATEWAY}…"
	sg docker -c "docker compose exec -T -u www-data nextcloud php occ security:bruteforce:reset ${GATEWAY}" || true
fi

echo "Ensuring E2E user ${USER}…"
if sg docker -c "docker compose exec -T -u www-data nextcloud php occ user:info ${USER}" >/dev/null 2>&1; then
	sg docker -c "docker compose exec -T -u www-data -e OC_PASS='${PASS}' nextcloud php occ user:resetpassword ${USER} --password-from-env"
else
	sg docker -c "docker compose exec -T -u www-data -e OC_PASS='${PASS}' nextcloud php occ user:add --password-from-env --display-name 'EinkaufCheck tester' ${USER}" || true
fi
sg docker -c "docker compose exec -T -u www-data nextcloud php occ group:adduser admin ${USER}" >/dev/null 2>&1 || true

echo "Enabling einkaufcheck…"
sg docker -c 'docker compose exec -T -u www-data nextcloud php occ app:enable einkaufcheck' >/dev/null

echo "Opening EinkaufCheck access for local E2E…"
sg docker -c 'docker compose exec -T -u www-data nextcloud php occ config:app:set einkaufcheck access_mode --value=open'

echo "Resetting pictures preference for ${USER}…"
sg docker -c "docker compose exec -T -u www-data nextcloud php occ user:setting ${USER} einkaufcheck show_images --delete" >/dev/null 2>&1 || true
sg docker -c "docker compose exec -T -u www-data nextcloud php occ user:setting ${USER} einkaufcheck show_images 0" >/dev/null 2>&1 || true
for key in offers_refresh offers_fetch offers_read list_write watch_write settings_write access_write directory_search list_export; do
	sg docker -c "docker compose exec -T -u www-data nextcloud php occ user:setting ${USER} einkaufcheck rate_limit:${key} --delete" >/dev/null 2>&1 || true
done

echo "E2E prep done. Login as ${USER}."
