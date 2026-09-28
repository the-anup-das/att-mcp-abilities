# Security policy

ATT MCP Abilities gives AI agents controlled access to a WordPress site, so security reports are taken seriously.

## Reporting a vulnerability

Please **do not open a public issue**. Report privately through GitHub:
**[Security › Report a vulnerability](https://github.com/the-anup-das/att-mcp-abilities/security/advisories/new)**.

Include the plugin version, WordPress and PHP versions, the abilities and MCP Controls that were enabled, and steps to reproduce. You should get a reply within a few days. Once a fix is released, the advisory is published with credit to you (unless you prefer otherwise).

## Supported versions

Only the latest release receives security fixes. Update through **Dashboard › Updates** or from the [WordPress.org plugin page](https://wordpress.org/plugins/att-mcp-abilities/).

## Scope

In scope: anything that lets an agent, a site user, or a visitor go beyond what the plugin's settings and the connected user's WordPress capabilities allow. For example: bypassing an ability toggle, read-only mode, the kill switch, the Allow PHP switch, the protected-option list, the REST route blocklist or the SSRF guard; exposing secrets; or stored XSS through agent-written content.

Out of scope: actions that the enabled abilities and the connected user's capabilities legitimately allow. An administrator who enables the Advanced addon has chosen to give agents administrator-level power.
