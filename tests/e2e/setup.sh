#!/usr/bin/env bash
# Builds a throwaway WordPress test site in tests/e2e/wordpress:
#   WordPress (WP_ZIP, default "latest") + SQLite Database Integration (no MySQL needed)
#   + this plugin linked in live + the MCP Adapter plugin (latest GitHub release,
#   left inactive: only used to test it running next to the bundled copy).
#
#   WP_ZIP=latest            bash tests/e2e/setup.sh   # current WordPress
#   WP_ZIP=wordpress-6.9     bash tests/e2e/setup.sh   # minimum supported version
#   E2E_CACHE=~/.cache/att-e2e bash tests/e2e/setup.sh # reuse downloaded zips between runs
set -euo pipefail
cd "$(dirname "$0")"
HERE="$(pwd)"
PHP_BIN="${PHP_BIN:-php}"
WP_ZIP="${WP_ZIP:-latest}"

# fetch URL FILE: download with retries, or reuse "$E2E_CACHE/FILE" when a cache dir is set.
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

# The plugin is linked into the test site. Remove that LINK on its own first so
# deleting the old test site can never reach into the plugin source through it.
LINK="wordpress/wp-content/plugins/att-mcp-abilities"
if [ -L "${LINK}" ] || [ -d "${LINK}" ]; then
  case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*) cmd //c rmdir "$(cygpath -w "${HERE}/${LINK}")" ;;  # removes a junction, never its target
    *) [ -L "${LINK}" ] && rm -f "${LINK}" ;;
  esac
fi
if [ -e "${LINK}" ]; then
  echo "Refusing to continue: ${LINK} still exists and is not a link." >&2
  exit 1
fi
rm -rf wordpress

echo "WordPress (${WP_ZIP})..."
fetch "https://wordpress.org/${WP_ZIP}.zip" "wp-${WP_ZIP}.zip"
unzip -q "wp-${WP_ZIP}.zip" && rm "wp-${WP_ZIP}.zip"

echo "SQLite Database Integration..."
fetch "https://downloads.wordpress.org/plugin/sqlite-database-integration.zip" sqlite-database-integration.zip
unzip -q sqlite-database-integration.zip -d wordpress/wp-content/plugins && rm sqlite-database-integration.zip
SQ="${HERE}/wordpress/wp-content/plugins/sqlite-database-integration"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#${SQ}#g" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" \
    "${SQ}/db.copy" > wordpress/wp-content/db.php

echo "MCP Adapter..."
fetch "https://github.com/WordPress/mcp-adapter/releases/latest/download/mcp-adapter.zip" mcp-adapter.zip
unzip -q mcp-adapter.zip -d wordpress/wp-content/plugins && rm mcp-adapter.zip

cp wp-config.php wordpress/wp-config.php
mkdir -p wordpress/wp-content/mu-plugins
cp mu-plugin.php wordpress/wp-content/mu-plugins/att-e2e.php

PLUGIN_SRC="$(cd ../../att-mcp-abilities && pwd)"
case "$(uname -s)" in
  # Git Bash rewrites "/J" as a path, so the switch is written "//J".
  MINGW*|MSYS*|CYGWIN*) cmd //c mklink //J "$(cygpath -w "${HERE}/wordpress/wp-content/plugins/att-mcp-abilities")" "$(cygpath -w "${PLUGIN_SRC}")" > /dev/null ;;
  *) ln -s "${PLUGIN_SRC}" wordpress/wp-content/plugins/att-mcp-abilities ;;
esac

"${PHP_BIN}" install.php
