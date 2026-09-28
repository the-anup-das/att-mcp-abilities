=== ATT MCP Abilities ===
Contributors: your-wordpress-org-username
Tags: mcp, ai, abilities api, automation, site builder
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI agents (Claude, Cursor, Codex) to WordPress over MCP — with per-ability toggles, read-only mode, undo, rate limits and an audit log.

== Description ==

ATT MCP Abilities lets AI agents such as Claude, Cursor, Codex, and Antigravity read your site and — only where you allow it — build and edit it, through the WordPress Abilities API and the MCP Adapter plugin.

You decide exactly what an agent may do. Every ability is a separate toggle, all write abilities are off by default, and integrations with other plugins and themes are opt-in addons.

**What agents can do (when you enable it)**

* **Content** — read and write posts, pages, reusable patterns, and any custom post type: full block markup, status, templates, parents, terms, featured images, and post meta.
* **Site building** — site title, tagline, homepage and blog page, permalinks, site icon, logo, timezone, and formats.
* **Menus** — create, edit (add, remove, reorder, nest), and assign navigation menus.
* **Media** — upload from a URL, raw SVG (sanitized), or base64, and edit alt text and captions.
* **Design** — Additional CSS, theme mods, plugin/theme options, rendering a page (drafts too), studying a public reference site, and purging caches.
* **Block themes** — templates, template parts (header, footer), and global styles (colors, typography, spacing).
* **GeneratePress** — settings with automatic CSS regeneration, and GeneratePress Elements.
* **Elementor** — read and edit page structures, write whole layouts, and change the global kit.
* **Code Snippets** — CSS/JS/HTML snippets; PHP only when you explicitly allow it.
* **Advanced (opt-in)** — full REST API access as the connected user, and installing/activating plugins and themes from WordPress.org.

**Safety built in**

* **Master switch** and **read-only mode** — turn everything off, or pause all writes, in one click.
* **Undo** — options, theme mods, Additional CSS, site settings, menu locations, builder layouts and post meta are snapshotted before each change; content, templates and global styles keep WordPress revisions. Agents can list and undo changes.
* **Audit log** — every call is recorded (inputs redacted) under MCP › Activity, with an optional daily email summary.
* **Rate limit** — a per-user cap on write calls per minute stops a runaway agent.
* **Secret redaction** — secret-looking values are masked in every response and log entry; secret-like and core options are blocked.
* **PHP is off by default** — PHP snippets and PHP-executing GeneratePress hooks need an administrator-only switch, and are always off when `DISALLOW_FILE_EDIT` is set.
* **Per-object permissions** — every write checks the WordPress capability for that specific post, term, comment, or file.
* **SSRF protection** — URL fetches only reach public addresses, re-checked on every redirect.

**Requirements**

* WordPress 6.9 or later (the Abilities API is part of core).
* The [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin, which exposes abilities to MCP clients.
* An MCP client (Claude Desktop, Claude Code, Cursor, Codex, Antigravity, …). The generated connection uses the `@automattic/mcp-wordpress-remote` package, which runs on your own computer via Node.js 18+.

== Installation ==

1. Install and activate **MCP Adapter** and this plugin.
2. Open **MCP › Settings**, enable the addons and abilities you want, and save.
3. Open **MCP › Connect**, click **Generate password** to create an Application Password, and copy the configuration for your AI client.
4. Paste the configuration into your client (for example `claude_desktop_config.json`) and restart it.
5. Ask your agent to do something — and watch **MCP › Activity**.

For the least privilege, create a dedicated Editor user for AI agents and generate the Application Password while logged in as that user.

== Frequently Asked Questions ==

= Do I need the MCP Adapter plugin? =

Yes. This plugin registers the abilities; MCP Adapter exposes them to MCP clients. A notice appears on the Plugins screen while it is missing.

= The Connect screen says Application Passwords are unavailable. =

WordPress only offers Application Passwords over HTTPS. Enable HTTPS on the site, or — for a local test site only — set `WP_ENVIRONMENT_TYPE` to `local` in wp-config.php. Some security plugins also disable Application Passwords.

= Can an agent run PHP on my server? =

Not unless an administrator turns on **Allow PHP** under MCP › Settings › MCP Controls. The setting cannot be changed by an agent, and it is locked off whenever `DISALLOW_FILE_EDIT` (or `ATT_MCP_DISALLOW_PHP`) is defined.

= How do I undo something an agent did? =

Enable the **History & undo** abilities and ask the agent to "undo the last change", or to list changes and undo a specific one. Content edits can also be rolled back from the normal WordPress revisions screen.

= Can an agent lock me out or change who has access? =

The REST tools refuse user, application-password, plugin and settings routes, the option tools refuse core and security options, and this plugin and MCP Adapter cannot be deactivated through MCP. Beyond that an agent can only do what the connected WordPress user may do.

= Is anything sent to third parties? =

Only what you trigger — see "External services" below. The plugin has no tracking and no phone-home.

= Where can I report a bug or contribute? =

Development happens on GitHub: [github.com/the-anup-das/att-mcp-abilities](https://github.com/the-anup-das/att-mcp-abilities). Please report security issues privately through the repository's Security tab rather than in a public issue.

== Screenshots ==

1. MCP › Settings — choose exactly which abilities AI agents get, with a kill switch, read-only mode, an administrator-only PHP switch and a write rate limit.
2. MCP › Connect — generate an Application Password and copy a ready-made configuration for Claude Desktop, Claude Code, Cursor, Codex or Antigravity.
3. MCP › Activity — every agent call is recorded, and recent changes can be undone.

== External services ==

This plugin does not contact any service by itself. Outbound requests happen only when an ability you enabled is used:

* **WordPress.org plugin and theme directories** (api.wordpress.org, downloads.wordpress.org) — only when the "Install / Activate Plugins" or "Install / Activate Themes" ability is used, to look up and download the requested plugin or theme. The request contains the plugin/theme slug and the standard WordPress user agent (WordPress version and your site URL). [WordPress.org privacy policy](https://wordpress.org/about/privacy/).
* **Any public URL an agent asks for** — the "Upload Media" ability (URL source) downloads the file at that URL, and "Fetch Reference URL" downloads the page (and optionally its stylesheets) so the agent can study it. The request contains the standard WordPress user agent (WordPress version and your site URL). Requests to private, loopback, and reserved addresses are blocked. The privacy policy of whichever site you point it at applies.
* **Your own site** — "Render Page HTML" requests pages from this same site so the agent can check its work.

The connection snippets on the Connect screen run the `@automattic/mcp-wordpress-remote` package from npm on your own computer; this plugin never contacts npm.

== Privacy ==

The audit log (MCP › Activity) stores, in your own database, the time, ability name, user ID, status, duration, and the call inputs with secret-looking values redacted — the newest 500 entries. The change history stores the previous values of settings an agent changed — the newest 50 changes. If you enable the daily summary, a list of write calls is emailed to the site's admin address. Everything is deleted when you uninstall the plugin.

== Changelog ==

= 1.9.0 =
* New: Generate and revoke Application Passwords from MCP › Connect; the password is filled into every client configuration automatically. Added a Claude Code configuration.
* New: Content tools for any post type — list (incl. drafts), read full content and meta, create, update, delete.
* New: Site settings (homepage, posts page, permalinks, title, tagline, icon, logo, timezone, formats), themes list, full plugin list.
* New: Menu updates (rename, add/edit/remove/reorder, nested items, locations).
* New: Block-theme Site Editor tools — templates, template parts, and global styles.
* New: Taxonomy update/delete for any taxonomy, media details editing, reference-site fetching, draft-aware page rendering.
* New: GeneratePress Element create/update/delete; Elementor whole-layout save and global kit read/update.
* New: Undo — change history for options, theme mods, CSS, settings, builder data and meta; revision listing and restore.
* New: Advanced addon — REST API passthrough (sensitive routes blocked) and plugin/theme install/activate from WordPress.org.
* New: Write rate limit, optional daily email summary, and MCP tool annotations (read-only/destructive hints).
* Security: SSRF protection now resolves hostnames and re-checks every redirect (previously only literal IPs were blocked).
* Security: Per-object capability checks on every write (edit_post/delete_post/edit_term/edit_comment) and per-post-type publish rights.
* Security: Agents can no longer change this plugin's own controls through the option tools; transients and more core options are protected.
* Security: PHP snippets now require an administrator-only switch (an agent-supplied flag is no longer enough); code-storing post types and REST routes are gated the same way.
* Security: Elementor settings, meta and options from users without unfiltered_html are KSES-filtered; Additional CSS requires edit_css.
* Fix: Block markup containing backslashes is no longer corrupted when saving content.
* Fix: Failed calls are logged as errors, and exceptions are caught and logged.
* Fix: Settings for an addon whose plugin is temporarily inactive are no longer lost on save.
* Compliance: Translation-ready admin, enqueued scripts, unique prefixes, uninstall cleanup on multisite.
* New: Plugin logo and an admin-menu icon that follows your admin colour scheme.

= 1.8.2 =
* Addon framework, MCP Controls (kill switch, read-only mode, audit log), secret redaction, and the Activity screen.

== Upgrade Notice ==

= 1.9.0 =
Security hardening and many new site-building abilities. New abilities are off by default. PHP snippets now require the new "Allow PHP" control in MCP › Settings.
