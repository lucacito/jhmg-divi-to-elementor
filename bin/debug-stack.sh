#!/usr/bin/env bash
#
# Clean-install WP_DEBUG test harness for the wordpress.org review checklist.
#
#   bin/debug-stack.sh up      # fresh WP + Elementor + this plugin, debug on
#   bin/debug-stack.sh log     # tail wp-content/debug.log
#   bin/debug-stack.sh check   # assert debug.log has no notices/warnings/fatals
#   bin/debug-stack.sh cli ... # run an arbitrary wp-cli command
#   bin/debug-stack.sh down    # destroy the stack AND its volumes
#
# `up` always starts from an empty database, because "clean install" is the
# whole point of the exercise — a stack carrying yesterday's options and posts
# proves nothing about a first activation.

set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSE=(docker compose -p jhmgcofo-debug -f docker-compose.debug.yml)
SLUG="jhmg-converter-for-divi-to-elementor"
URL="http://localhost:8002"

wp() {
  "${COMPOSE[@]}" exec -T cli wp --path=/var/www/html --allow-root "$@"
}

cmd_up() {
  echo "==> Tearing down any previous debug stack (volumes included)"
  "${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1 || true

  echo "==> Starting containers"
  "${COMPOSE[@]}" up -d

  echo "==> Waiting for WordPress core files"
  for _ in $(seq 1 60); do
    if "${COMPOSE[@]}" exec -T cli test -f /var/www/html/wp-settings.php 2>/dev/null; then break; fi
    sleep 2
  done

  echo "==> Installing WordPress"
  wp core install \
    --url="$URL" \
    --title="D2E Review Test" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=admin@example.test \
    --skip-email

  echo "==> Installing Elementor (the plugin declares Requires Plugins: elementor)"
  wp plugin install elementor --activate

  echo "==> Activating $SLUG"
  wp plugin activate "$SLUG"

  echo "==> Confirming debug constants are live"
  wp eval 'foreach ( [ "WP_DEBUG", "WP_DEBUG_LOG", "WP_DEBUG_DISPLAY", "SCRIPT_DEBUG" ] as $c ) { printf( "%s=%s\n", $c, var_export( constant( $c ), true ) ); }'

  cat <<EOF

Ready.

  Admin:     $URL/wp-admin/  (admin / admin)
  Converter: $URL/wp-admin/tools.php?page=jhmgcofo-converter
  Fixture:   fixtures/divi-hardening-sample.json  (import this to exercise the sanitizers)

Next:
  bin/debug-stack.sh log     # watch debug.log while you click through
  bin/debug-stack.sh check   # fail if anything was logged
EOF
}

cmd_log() {
  "${COMPOSE[@]}" exec -T cli sh -c 'touch /var/www/html/wp-content/debug.log; tail -f /var/www/html/wp-content/debug.log'
}

cmd_check() {
  local out
  out="$("${COMPOSE[@]}" exec -T cli sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null || true')"

  if [ -z "$out" ]; then
    echo "debug.log is empty — no notices, warnings or fatals."
    return 0
  fi

  echo "$out"
  echo
  echo "--- lines mentioning this plugin ---"
  echo "$out" | grep -i "$SLUG" || echo "(none — the entries above come from core or Elementor)"
  return 1
}

cmd_cli() {
  wp "$@"
}

cmd_down() {
  "${COMPOSE[@]}" down -v --remove-orphans
}

case "${1:-up}" in
  up)    cmd_up ;;
  log)   cmd_log ;;
  check) cmd_check ;;
  cli)   shift; cmd_cli "$@" ;;
  down)  cmd_down ;;
  *)     echo "usage: $0 {up|log|check|cli <args>|down}" >&2; exit 2 ;;
esac
