#!/usr/bin/env bash
# Run EinkaufCheck critical mutation gate inside Docker.
# Mutants rewrite PHP sources briefly; use root in the container so www-data
# ownership of the bind-mount does not block writes on host-owned trees.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
sg docker -c "docker compose exec -u root nextcloud php -d opcache.enable_cli=0 /var/www/html/custom_apps/einkaufcheck/tests/Mutation/run-critical-mutations.php"
