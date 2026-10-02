=== ATT MCP Abilities ===
Contributors: your-wordpress-org-username
Tags: mcp, ai, abilities api, seo, performance
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.12.1
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
* **Performance** — find what slows the site down (server, database, autoloaded options, cron, cache plugins and a real page load, with prioritised fixes), run Google PageSpeed Insights, clean up the database, stop large options from autoloading, and create missing image sizes.
* **Cache plugins** — read and change the settings of LiteSpeed Cache (including its presets) and Super Page Cache through each plugin's own settings API, with recommendations that take your server into account. Credentials are never exposed.
* **SEO** — analyse a post on its rendered page (title, meta description, focus keyword, headings, images, links, readability) with concrete fixes and internal-link suggestions, audit many posts at once, and set the SEO title, description, focus keyword, canonical URL and indexing in Yoast SEO, Rank Math, All in One SEO or SEOPress — one post at a time or up to 50 per call.
* **Rank Math** — Rank Math's own MCP tools (site audit and automatic fixes, post analysis, SEO scores, settings, sitemaps, links, redirections and 404 log, Search Console keywords, AI Visibility) follow this plugin's controls, can be switched on one by one, are logged, and their changes can be undone. Adds the fixes Rank Math's tools lack: create, edit and delete redirections, and clear the 404 log.
* **QuickCal (appointment booking)** — set up booking from scratch: calendars, weekly hours, special dates and closed days, blocked time slots, the booking form, settings and the emails customers get; see which slots are free; book, reschedule, approve and cancel appointments (QuickCal emails the customer, as its own screens do).
* **Design** — Additional CSS, theme mods, plugin/theme options, rendering a page (drafts too), studying a public reference site, and purging caches.
* **Block themes** — templates, template parts (header, footer), and global styles (colors, typography, spacing).
* **GeneratePress** — settings with automatic CSS regeneration, and GeneratePress Elements.
* **Elementor** — read and edit page structures, write whole layouts, and change the global kit.
* **Code Snippets** — CSS/JS/HTML snippets; PHP only when you explicitly allow it.
* **Advanced (opt-in)** — full REST API access as the connected user, and installing/activating plugins and themes from WordPress.org.

**Safety built in**

* **Master switch** and **read-only mode** — turn everything off, or pause all writes, in one click.
* **Undo** — options, theme mods, Additional CSS, site settings, menu locations, builder layouts, post meta, SEO fields, cache-plugin settings, Rank Math redirections, QuickCal calendars, time slots, forms, settings and appointments, and option autoloading are snapshotted before each change, and so is everything other plugins' own MCP tools change; content, templates and global styles keep WordPress revisions. Agents can list and undo changes.
* **Audit log** — every call is recorded (inputs redacted) under MCP › Activity, with an optional daily email summary.
* **Rate limit** — a per-user cap on write calls per minute stops a runaway agent.
* **Secret redaction** — secret-looking values are masked in every response and log entry, and a masked placeholder can never be written back over the real secret; secret-like and core options are blocked.
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

= My AI client cannot connect: "No route was found matching the URL and request method" (404). =

The address clients connect to (`/wp-json/mcp/mcp-adapter-default-server`) is MCP Adapter's default server. Make sure the MCP Adapter plugin is active and up to date (0.6 or newer) and that "MCP enabled" is on under MCP › Settings. Some plugins switch that server off for the whole site: Elementor 4.3 does while its own MCP feature is off. Since 1.12.1 this plugin keeps it on while MCP is enabled, and MCP › Connect tells you when the address does not exist.

= The Connect screen says Application Passwords are unavailable. =

WordPress only offers Application Passwords over HTTPS. Enable HTTPS on the site, or — for a local test site only — set `WP_ENVIRONMENT_TYPE` to `local` in wp-config.php. Some security plugins also disable Application Passwords.

= Can an agent run PHP on my server? =

Not unless an administrator turns on **Allow PHP** under MCP › Settings › MCP Controls. The setting cannot be changed by an agent, and it is locked off whenever `DISALLOW_FILE_EDIT` (or `ATT_MCP_DISALLOW_PHP`) is defined.

= How do I undo something an agent did? =

Enable the **History & undo** abilities and ask the agent to "undo the last change", or to list changes and undo a specific one. Content edits can also be rolled back from the normal WordPress revisions screen.

= Can an agent lock me out or change who has access? =

The REST tools refuse user, application-password, plugin and settings routes, the option tools refuse core and security options, and this plugin and MCP Adapter cannot be deactivated through MCP. Beyond that an agent can only do what the connected WordPress user may do.

= Other plugins add their own MCP tools. How do they work with this plugin? =

Some plugins register MCP tools of their own — Rank Math (29 in 1.0.279), All in One SEO, Yoast SEO — and so does WordPress 7.1. MCP Adapter offers them over the same connection as this plugin's. When an agent calls them over MCP, the kill switch, read-only mode, write limit, activity log and undo in MCP › Settings apply to them as well; a plugin using its own tools in its own screens is not affected. To choose them one by one, enable the **Rank Math SEO** addon (for Rank Math's) or the **Other MCP tools** addon (for everyone else's): each tool then gets its own switch (reads on, writes off by default), and a tool you switch off is hidden from agents and refuses to run.

The Rank Math addon also adds tools to save and delete redirections and to clear the 404 log, so an agent can fix what Rank Math's audit reports. Per-post fixes (SEO title, description, focus keyword) use "Update SEO Meta" or "Bulk Update SEO Meta".

= Can an agent set up appointment booking? =

Yes, with **QuickCal**. Enable the QuickCal addon and its abilities, then ask for what you want, for example "one calendar per service, open Monday to Friday 9 to 5 in one-hour slots, closed on public holidays, guests can book without an account, and email them a confirmation". The agent creates the calendars, time slots, closed days, booking form, settings and emails, puts the calendar on a page, and checks which slots customers can book. Everything is written in QuickCal's own formats, so you can keep working in QuickCal's screens, and every change can be undone.

The agent can also book appointments for customers, reschedule, approve and cancel them. QuickCal then emails the customer as its own screens would; "notify": false skips that. Appointment tools show customers' names, email addresses and booking-form answers to the agent, so enable them only for agents you trust with that data. QuickCal's private calendar-feed key is never readable by agents.

= Which cache and SEO plugins are supported? =

Cache settings can be read and changed for **LiteSpeed Cache** and **Super Page Cache**; other cache plugins are detected and purged. SEO fields can be written to **Yoast SEO**, **Rank Math**, **All in One SEO** (4.9.8 or later) and **SEOPress**. The performance audit and the post analysis work without any of them.

= Does PageSpeed Insights need an API key? =

No, but Google's shared quota for keyless requests is small. Create a free key for the PageSpeed Insights API in the Google Cloud console and add `define( 'ATT_MCP_PSI_API_KEY', 'your-key' );` to wp-config.php. The site must be reachable from the internet for Google to test it.

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
* **Google PageSpeed Insights** (www.googleapis.com) — only when the "PageSpeed Insights" ability is used. The URL of the page being tested (a page of this site), the chosen strategy (mobile or desktop) and categories, and your API key if you set one are sent to Google, which then loads that page to measure it. [Google Privacy Policy](https://policies.google.com/privacy), [Google APIs Terms of Service](https://developers.google.com/terms).
* **Your own site** — "Render Page HTML", "Audit Performance" and "Analyze Post SEO" request pages from this same site to check the result.
* **Cache plugin services** — changing LiteSpeed Cache or Super Page Cache settings runs that plugin's own save routine, which may contact the plugin's own services (QUIC.cloud, Cloudflare) exactly as saving the settings in its admin screen would, if you have connected them.
* **Rank Math's services** — Rank Math's own MCP tools are Rank Math's code: some of them (Search Console keywords, AI Visibility, the site audit) contact Rank Math's or Google's services exactly as they do without this plugin. This plugin only decides whether they may run, and logs them.

The connection snippets on the Connect screen run the `@automattic/mcp-wordpress-remote` package from npm on your own computer; this plugin never contacts npm.

== Privacy ==

The audit log (MCP › Activity) stores, in your own database, the time, ability name, user ID, status, duration, and the call inputs with secret-looking values redacted — the newest 500 entries. Inputs can contain personal data an agent sent, such as a customer's name and email address when it books an appointment. The change history stores the previous values of settings an agent changed — the newest 50 changes. If you enable the daily summary, a list of write calls is emailed to the site's admin address. Everything is deleted when you uninstall the plugin.

== Changelog ==

= 1.12.1 =
* Fix: AI clients could not connect ("No route was found", 404) on sites where another plugin switches off MCP Adapter's default server, as Elementor 4.3 does while its own MCP feature is off. The server is now kept on while MCP is enabled.
* New: MCP Adapter is started when it is present only as a library inside another plugin.
* New: MCP › Connect warns when the address AI clients connect to does not exist on the site.

= 1.12.0 =
* New: QuickCal addon. Agents can set up appointment booking from scratch: calendars, weekly time slots, special dates and closed days, blocked slots, the booking form, settings and emails. They can also see which slots are free, and book, reschedule, approve and cancel appointments, with QuickCal's own emails to the customer. It is written in QuickCal's own formats and checked against QuickCal's own functions. Every change is undoable, including cancelled appointments and deleted calendars (restored under the same ids).
* New: "Other MCP tools" addon. The MCP tools of any other plugin, and of WordPress 7.1 itself (All in One SEO, Yoast SEO, core…), now follow the kill switch, read-only mode, write limit, activity log and undo, and can be switched on one by one.
* Change: Other plugins' tools are governed only when an MCP client calls them; a plugin using its own tools in its own screens is never logged, limited or refused.
* New: All in One SEO's post SEO tool is undoable, including All in One SEO's own table.
* Fix: Undo restores every value of a post meta key that has several.
* Fix: Redirect destinations are checked without a DNS lookup.
* Security: QuickCal's private calendar-feed key is protected like other secrets.

= 1.11.0 =
* New: Rank Math SEO addon. Rank Math's own MCP tools (site audit and automatic fixes, post analysis, SEO scores, settings, sitemaps, links, redirections and 404 log reads, Search Console keywords, AI Visibility) can be switched on one by one; a tool that is off is hidden from agents and refuses to run.
* New: Other plugins' MCP tools that this plugin governs (Rank Math's) always follow the kill switch, read-only mode and write limit, are logged in MCP › Activity, and the option and post meta changes their write tools make can be undone — including Rank Math's bulk focus-keyword fix.
* New: "Save Redirection", "Delete Redirections" (trash or permanent, undoable) and "Clear 404 Log" for Rank Math, so agents can fix 404s and changed URLs; redirect loops are refused.
* New: "Bulk Update SEO Meta" — SEO title, description, focus keyword, canonical and indexing for up to 50 posts per call, as one undoable change (Yoast SEO, Rank Math, All in One SEO, SEOPress).
* Fix: Undoing a permalink change now also updates WordPress's rewrite rules.
* Fix: Redaction no longer turns booleans and numbers under secret-looking names into strings.

= 1.10.0 =
* New: Performance — "Audit Performance" finds what slows the site down (server, database, autoloaded options, cron, cache plugins and a real page load: response times, page-cache hits, compression, render-blocking scripts and styles, image weight and formats, lazy-loading, third-party hosts) and returns prioritised fixes.
* New: "PageSpeed Insights" — Lighthouse scores, lab metrics, real-user Core Web Vitals, the LCP element and the biggest opportunities from Google PageSpeed Insights.
* New: Cache configuration — read and change LiteSpeed Cache settings (and apply its presets) and Super Page Cache settings through each plugin's own API, with recommendations; credentials are never exposed. Undoable.
* New: "Optimize Database" (revisions, auto-drafts, spam/trash, expired transients, orphaned meta; dry run by default), "Set Option Autoload" (undoable) and "Regenerate Thumbnails".
* New: SEO — "Analyze Post SEO" (on-page SEO and readability checks on the rendered page, with a score, fixes and internal-link suggestions), "SEO Audit" (many posts plus site-wide checks) and "Update SEO Meta" for Yoast SEO, Rank Math, All in One SEO and SEOPress (undoable).
* New: The SEO tools warn when an SEO plugin is installed but not outputting anything (Rank Math before its setup wizard, SEOPress with Titles & Metas off).
* Security: A redacted placeholder ("***redacted***") sent back by an agent can no longer overwrite the real secret — the stored value is kept, or the write is refused.
* Security: Secret detection now also covers Cloudflare API tokens, object-cache passwords, the QUIC.cloud private key and "credential" names.

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

= 1.12.1 =
Fixes AI clients failing to connect (404 "No route was found") on sites running Elementor 4.3.

= 1.12.0 =
New QuickCal addon for setting up appointment booking, and every other plugin's MCP tools now follow your MCP Controls.

= 1.11.0 =
Rank Math's own MCP tools now follow your MCP Controls (and can be chosen one by one with the new Rank Math SEO addon), plus redirection, 404 and bulk SEO fix tools.

= 1.10.0 =
New performance, cache-configuration and SEO abilities (all off by default) and safer handling of redacted secrets.

= 1.9.0 =
Security hardening and many new site-building abilities. New abilities are off by default. PHP snippets now require the new "Allow PHP" control in MCP › Settings.
