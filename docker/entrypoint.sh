#!/usr/bin/env bash
#
# Container entrypoint for the BBB Drupal site.
#
# Behaviour is controlled by environment variables:
#   RUN_SITE_INSTALL=true   Install Drupal from the committed config the first
#                           time (drush site:install --existing-config). Safe to
#                           leave on: it is skipped if the site is already
#                           installed.
#   RUN_DRUSH_DEPLOY=true    On every start, run database updates + config
#                           import + cache rebuild (drush deploy). Recommended
#                           for normal deploys.
#
# If neither is set, the container just serves the current codebase.
set -euo pipefail
cd /var/www/html

DRUSH="vendor/bin/drush"

# Ensure the files directory is writable (also covers a mounted volume).
mkdir -p web/sites/default/files
chown -R www-data:www-data web/sites/default/files || true

wait_for_db() {
  echo "Waiting for the database..."
  for i in $(seq 1 30); do
    if $DRUSH sql:query "SELECT 1;" >/dev/null 2>&1; then
      echo "Database is reachable."
      return 0
    fi
    echo "  ...not ready yet (attempt ${i}/30)"
    sleep 2
  done
  echo "ERROR: database did not become reachable in time." >&2
  return 1
}

is_installed() {
  # Bootstrapped to 'full' means Drupal is installed and the DB has a schema.
  [ "$($DRUSH status --field=bootstrap 2>/dev/null || true)" = "Successful" ]
}

if [ "${RUN_SITE_INSTALL:-false}" = "true" ] || [ "${RUN_DRUSH_DEPLOY:-false}" = "true" ]; then
  wait_for_db

  if [ "${RUN_SITE_INSTALL:-false}" = "true" ] && ! is_installed; then
    echo "Installing Drupal from existing config..."
    $DRUSH site:install --existing-config -y
  fi

  if [ "${RUN_DRUSH_DEPLOY:-false}" = "true" ]; then
    echo "Running drush deploy (updatedb + config:import + cache:rebuild)..."
    $DRUSH deploy -y
  fi
fi

echo "Starting web server: $*"
exec "$@"
