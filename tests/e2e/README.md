# End-to-end tests

These scripts run the plugin inside a real, throwaway WordPress site (on SQLite, so no MySQL is needed). They exercise every ability through core's `WP_Ability::execute()`, the same path MCP Adapter uses, and then test the admin screens in a real browser and a real MCP session over HTTP.

## Requirements

- PHP 7.4+ with `pdo_sqlite`, `curl`, `mbstring`, `openssl`, `gd` and `fileinfo`. SQLite must be 3.37 or newer (the SQLite integration plugin's minimum).
- `curl` and `unzip` on the command line, and Bash (on Windows use Git Bash).
- Optional, for the browser test: Node.js 18+ and Chrome or Edge.

## Run

```bash
bash tests/e2e/setup.sh                        # builds tests/e2e/wordpress (latest WordPress)
bash tests/e2e/run-all.sh                      # all suites; add --net for the internet-dependent checks (incl. the plugin integrations)
bash tests/e2e/integrations.sh                 # only the SEO + cache plugin integrations (ATT_INTEGRATIONS="wordpress-seo litespeed-cache" to pick)
ATT_QUICKCAL_ZIP=~/Downloads/quickcal_v1.0.24.zip bash tests/e2e/run-all.sh --net   # also QuickCal (paid — bring your own copy; never committed)
ATT_BROWSER="/path/to/chrome" bash tests/e2e/run-all.sh --net   # also test the admin UI in a real browser
WP_ZIP=wordpress-6.9 bash tests/e2e/setup.sh   # test the minimum supported WordPress
PHP_BIN=/path/to/php bash tests/e2e/run-all.sh # use a specific PHP
E2E_CACHE=~/.cache/att-e2e bash tests/e2e/setup.sh   # keep downloaded zips between runs (faster, works offline)
```

`setup.sh` needs a fresh site each time `run-all.sh` has run, because the ability tests create users and content.

On Windows, Edge is at `C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe`. CI runs everything with the runner's Chrome.

## What is covered

| Script | Covers |
|---|---|
| `run-tests.php` | Every ability: content (byte-exact block markup, templates, terms, meta), revisions, taxonomy, site settings, options/theme mods/CSS, menus, media, page rendering (drafts included), Site Editor, REST passthrough, plugins/themes, the performance audit, PageSpeed Insights (Google's response mocked), database clean-up, autoload, thumbnails, the SEO analysis/audit, the HTML scanner. Every guard: redacted placeholders never overwrite secrets, per-object permissions as Author and Contributor, protected options, SSRF, the PHP gate, blocked REST routes, read-only mode, the kill switch, the rate limit, undo/redo, audit status, sanitizers, the digest |
| `admin-render.php` | The Settings, Connect and Activity screens render cleanly with no inline `<script>`; assets load only on MCP screens; the Plugins-screen link and notice work |
| `browser.mjs` | In headless Chrome/Edge: login, the admin-menu icon is recoloured by WordPress, the header logo loads, write/PHP confirmations, Toggle All, addon dimming; generating a password fills all five client configs; tabs, copy to clipboard, revoke; no JavaScript errors |
| `mcp-setup.php`, `mcp-client-test.php`, `mcp-revoke.php` | Creates an Application Password through core's endpoint (as the Connect screen does), runs a JSON-RPC MCP session through MCP Adapter, then revokes the password and expects HTTP 401 |
| `integrations.sh`, `integrations.php`, `mcp-rank-math-test.php` | The real plugins from WordPress.org, one at a time. Rank Math also: its own 29 MCP tools follow the MCP Controls (activity log, read-only mode, kill switch, redaction guard) and their changes are undoable (tagline, bulk focus keywords, modules); with the Rank Math addon on, a tool switched off is hidden from MCP and REST and refuses to run (also checked over a real MCP session); redirections are created, edited, trashed, deleted and restored by undo, and the site really answers 301/410; a real 404 is logged by Rank Math and cleared; MCP › Settings renders the Rank Math card. Bulk SEO meta for every SEO plugin. Yoast SEO, Rank Math, All in One SEO, SEOPress: SEO fields are written, the rendered page shows the new title, description and noindex, the analysis and audit read them back, undo restores them, an Author cannot change another user's post. LiteSpeed Cache: settings and presets through its API, credentials refused, undo. Super Page Cache: switching the page cache on installs the drop-in and the second visit is a cache hit, then undo switches it off |
| `quickcal-test.php` | QuickCal, with your own copy (`ATT_QUICKCAL_ZIP`; it is a paid plugin, so CI skips it). An agent sets booking up from scratch: calendars, weekly and special-date slots, closed days, blocked slots, the form, settings, emails and a page. QuickCal's own functions must then see exactly that: availability, form rendering and the stored formats. A visitor books through QuickCal's own booking handler over HTTP and gets the emails the agent wrote. The agent approves, books (full, closed, blocked and past slots are refused), reschedules, cancels and deletes a calendar, and undo restores all of it under the same ids. Booking Agents manage only their own calendars, and the calendar-feed key stays private |
| `uninstall-test.php` | `uninstall.php` removes every option, both tables and the cron event |

Any PHP warning or `_doing_it_wrong()` raised from plugin code fails the run. Mail never leaves the test site: `mu-plugin.php` answers `wp_mail()` and keeps the last 20 messages for the tests.

`php -S` handles one request at a time, so `att/render-page` is tested from the CLI runner rather than inside the MCP session: a request can't fetch a page from the same server while that server is busy serving it. Real hosting runs multiple workers.

## Screenshots

`.wordpress-org/screenshot-1..3.png` come from a demo site. Regenerate them after UI changes:

```bash
bash tests/e2e/setup.sh                        # fresh site (do not run run-all.sh first)
php tests/e2e/demo-data.php                    # realistic settings + activity history
php -S 127.0.0.1:8899 -t tests/e2e/wordpress tests/e2e/router.php &
cd tests/e2e && npm ci && ATT_BROWSER="/path/to/chrome" node browser.mjs --screenshots ../../.wordpress-org
```
