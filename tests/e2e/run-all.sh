#!/usr/bin/env bash
# Runs the whole end-to-end suite against the site built by setup.sh:
#   ability tests, admin screens, a real MCP session over HTTP, password revoke,
#   and uninstall cleanup. Exits non-zero on the first failing step.
#
#   bash tests/e2e/setup.sh && bash tests/e2e/run-all.sh [--net]
#   (--net also runs the checks that need internet access; set ATT_BROWSER to a
#    Chrome/Edge executable to also test the admin UI in a real browser)
set -euo pipefail
cd "$(dirname "$0")"
PHP_BIN="${PHP_BIN:-php}"

"${PHP_BIN}" -S 127.0.0.1:8899 -t "${ATT_WP_DIR:-wordpress}" router.php > server.log 2>&1 &
SERVER_PID=$!
trap 'kill ${SERVER_PID} 2>/dev/null || true; rm -f app-password.txt' EXIT
for _ in $(seq 1 30); do
  curl -fsS -o /dev/null http://127.0.0.1:8899/ && break
  sleep 1
done

LOG=wordpress/wp-content/debug.log
rm -f "${LOG}"
step() { echo; echo "######## $1"; }
step "Ability tests";        "${PHP_BIN}" setup-options.php && "${PHP_BIN}" run-tests.php "$@"
step "Admin screens";        "${PHP_BIN}" admin-render.php
if [ -n "${ATT_BROWSER:-}" ]; then
  step "Admin UI in a real browser"
  [ -d node_modules ] || npm ci --no-audit --no-fund --silent
  node browser.mjs
else
  echo; echo "(Skipping the browser test — set ATT_BROWSER to a Chrome or Edge executable to run it.)"
fi
step "Real MCP session";     "${PHP_BIN}" setup-options.php && "${PHP_BIN}" mcp-setup.php && "${PHP_BIN}" mcp-client-test.php

# Up to here nothing at all may be logged.
if [ -s "${LOG}" ]; then
  echo; echo "debug.log is not empty:"; cat "${LOG}"; exit 1
fi

# The revoked password is expected to make MCP Adapter log "Permission denied".
step "Revoke password";      "${PHP_BIN}" mcp-revoke.php && "${PHP_BIN}" mcp-client-test.php --expect-unauthorized
step "Uninstall cleanup";    "${PHP_BIN}" uninstall-test.php

if [ -f "${LOG}" ] && grep -E "PHP (Fatal|Warning|Notice|Deprecated|Parse)" "${LOG}"; then
  echo "PHP errors were logged."; exit 1
fi
echo; echo "All end-to-end suites passed."
