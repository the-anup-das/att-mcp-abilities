#!/usr/bin/env bash
# Tests the SEO and cache-plugin integrations against the real plugins from
# WordPress.org — Yoast SEO, Rank Math, All in One SEO, SEOPress, LiteSpeed Cache
# and Super Page Cache — one at a time. Needs the site from setup.sh and the test
# server (started here when it is not running yet).
#
#   bash tests/e2e/integrations.sh                         # all six
#   ATT_INTEGRATIONS="wordpress-seo" bash tests/e2e/integrations.sh
#   E2E_CACHE=~/.cache/att-e2e bash tests/e2e/integrations.sh   # reuse downloaded zips
set -euo pipefail
cd "$(dirname "$0")"
PHP_BIN="${PHP_BIN:-php}"
PLUGINS="${ATT_INTEGRATIONS:-wordpress-seo seo-by-rank-math all-in-one-seo-pack wp-seopress litespeed-cache wp-cloudflare-page-cache}"

fetch() {
  local url="$1" file="$2"
  if [ -n "${E2E_CACHE:-}" ] && [ -s "${E2E_CACHE}/${file}" ]; then
    cp "${E2E_CACHE}/${file}" "${file}"
    return
  fi
  if ! curl -fsSL --retry 3 --retry-delay 5 --connect-timeout 30 "${url}" -o "${file}.part"; then
    rm -f "${file}.part"
    echo "Download failed: ${url}" >&2
    exit 1
  fi
  mv "${file}.part" "${file}"
  if [ -n "${E2E_CACHE:-}" ]; then
    mkdir -p "${E2E_CACHE}" && cp "${file}" "${E2E_CACHE}/${file}"
  fi
}

if ! curl -fsS -o /dev/null http://127.0.0.1:8899/ 2>/dev/null; then
  "${PHP_BIN}" -S 127.0.0.1:8899 -t "${ATT_WP_DIR:-wordpress}" router.php > server-integrations.log 2>&1 &
  SERVER_PID=$!
  trap 'kill ${SERVER_PID} 2>/dev/null || true' EXIT
  for _ in $(seq 1 30); do
    curl -fsS -o /dev/null http://127.0.0.1:8899/ && break
    sleep 1
  done
fi

"${PHP_BIN}" setup-options.php
for slug in ${PLUGINS}; do
  echo; echo "######## ${slug}"
  fetch "https://downloads.wordpress.org/plugin/${slug}.latest-stable.zip" "${slug}.zip"
  rm -rf "wordpress/wp-content/plugins/${slug}"
  unzip -q "${slug}.zip" -d wordpress/wp-content/plugins && rm "${slug}.zip"
  "${PHP_BIN}" integrations.php "${slug}" activate
  "${PHP_BIN}" setup-options.php > /dev/null   # the plugin's abilities show up once it is active
  "${PHP_BIN}" integrations.php "${slug}" write
  "${PHP_BIN}" integrations.php "${slug}" verify
  "${PHP_BIN}" integrations.php "${slug}" deactivate
done
echo; echo "All integration suites passed."
