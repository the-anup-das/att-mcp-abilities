// Real-browser test of the MCP admin screens (Chrome or Edge, headless), and the
// WordPress.org screenshot generator.
//
//   ATT_BROWSER=/path/to/chrome node browser.mjs                      test only
//   ATT_BROWSER=/path/to/chrome node browser.mjs --screenshots <dir>  also save screenshot-1..3.png
//
// Expects the e2e site served on ATT_SITE_URL (default http://127.0.0.1:8899) with the
// admin/password account from install.php. Makes no persistent changes: toggles are not
// saved and the Application Password it creates is revoked again.
import puppeteer from 'puppeteer-core';

const BASE = process.env.ATT_SITE_URL || 'http://127.0.0.1:8899';
const EXE = process.env.ATT_BROWSER;
if (!EXE) {
	console.error('Set ATT_BROWSER to a Chrome or Edge executable.');
	process.exit(2);
}
const shotsArg = process.argv.indexOf('--screenshots');
const SHOTS = shotsArg > -1 ? process.argv[shotsArg + 1] : null;

const results = [];
const check = (label, ok, detail = '') => {
	results.push(ok);
	console.log(`  ${ok ? 'ok  ' : 'FAIL'}  ${label}${ok || !detail ? '' : `  -> ${detail}`}`);
};

// Windows: some shells (e.g. Git Bash here) export __COMPAT_LAYER=RunAsInvoker, which makes
// Chrome/Edge exit at startup with code 0. Launch the browser without it.
const { __COMPAT_LAYER, ...browserEnv } = process.env;
const browser = await puppeteer.launch({
	executablePath: EXE,
	headless: true,
	env: browserEnv,
	args: ['--no-first-run', '--disable-extensions', '--hide-scrollbars', ...(process.platform === 'linux' ? ['--no-sandbox'] : [])],
});
await browser.defaultBrowserContext().overridePermissions(BASE, ['clipboard-read', 'clipboard-write', 'clipboard-sanitized-write']);
const page = await browser.newPage();
await page.setViewport({ width: 1280, height: 860, deviceScaleFactor: 1 });

const jsErrors = [];
page.on('pageerror', (e) => jsErrors.push(String(e)));
page.on('console', (m) => { if (m.type() === 'error') jsErrors.push(m.text()); });
const dialogs = [];
page.on('dialog', async (d) => { dialogs.push(d.message()); await d.accept(); });

// The SQLite test-database indicator in the admin bar belongs to the test environment, not this plugin.
const hideTestBadge = () => page.addStyleTag({ content: '#wp-admin-bar-sqlite-db-integration{display:none!important}' }).catch(() => {});
// php -S is single-threaded: never open the Dashboard (its Site Health loopback request stalls
// the server) and wait for `load` rather than network idle.
const go = async (url) => {
	await page.goto(url, { waitUntil: 'load', timeout: 60000 });
	await hideTestBadge();
};
const shot = async (name) => { if (SHOTS) await page.screenshot({ path: `${SHOTS}/${name}` }); };
const SETTINGS = `${BASE}/wp-admin/admin.php?page=att-mcp-abilities`;

console.log('== Login');
await go(`${BASE}/wp-login.php?redirect_to=${encodeURIComponent(SETTINGS)}`);
await page.type('#user_login', 'admin');
await page.type('#user_pass', 'password');
await Promise.all([page.waitForNavigation({ waitUntil: 'load', timeout: 60000 }), page.click('#wp-submit')]);
await hideTestBadge();
check('logged in to wp-admin', page.url().includes('page=att-mcp-abilities'), page.url());

console.log('== MCP › Settings');
await page.waitForFunction(() => {
	const el = document.querySelector('#toplevel_page_att-mcp-abilities .wp-menu-image');
	const m = el && getComputedStyle(el).backgroundImage.match(/base64,([^")]+)/);
	return m && !atob(m[1]).includes('fill="black"');
}, { timeout: 15000 }).catch(() => {});
const menuIcon = await page.$eval('#toplevel_page_att-mcp-abilities .wp-menu-image', (el) => ({ cls: el.className, bg: getComputedStyle(el).backgroundImage }));
check('menu icon is the SVG data URI (class "svg")', menuIcon.cls.includes('svg') && menuIcon.bg.includes('data:image/svg+xml;base64'), menuIcon.cls);
const painted = Buffer.from((menuIcon.bg.match(/base64,([^")]+)/) || ['', ''])[1], 'base64').toString();
check('WordPress recoloured the icon to the admin scheme', !painted.includes('fill="black"') && /fill="(#[0-9a-f]{3,6}|rgb)/i.test(painted), painted.slice(0, 120));
check('header logo image loads', await page.$eval('.att-topbar img.att-logo', (img) => img.complete && img.naturalWidth > 0));
await shot('screenshot-1.png');

dialogs.length = 0;
await page.$eval('input[name="att_mcp_abilities[att/delete-page]"]', (el) => { el.checked = false; el.click(); });
check('enabling a write ability asks for confirmation', dialogs.length === 1 && /MODIFY/.test(dialogs[0]), dialogs.join(' | '));
check('... and stays on once confirmed', await page.$eval('input[name="att_mcp_abilities[att/delete-page]"]', (el) => el.checked));
await page.$$eval('input[data-group="Comments"]', (els) => els.forEach((e) => { e.checked = false; }));
await page.$eval('.att-toggle-all[data-group="Comments"]', (el) => el.click());
const comments = await page.$$eval('input[data-group="Comments"]', (els) => els.map((e) => e.checked));
check('Toggle All switches a whole group', comments.length > 0 && comments.every(Boolean), JSON.stringify(comments));
await page.$eval('.att-addon-master[data-addon="design"]', (el) => { el.checked = true; el.click(); });
check('turning an addon off dims its abilities', await page.$eval('[data-addon-body="design"]', (el) => el.classList.contains('att-dim')));
dialogs.length = 0;
await page.$eval('input[name="att_mcp_controls[allow_php]"]', (el) => { el.checked = false; el.click(); });
check('Allow PHP asks for its own confirmation', dialogs.length === 1 && /PHP/.test(dialogs[0]), dialogs.join(' | '));

console.log('== MCP › Connect');
await go(`${BASE}/wp-admin/admin.php?page=att-mcp-config`);
await page.click('#att-mcp-pw-generate');
await page.waitForSelector('#att-mcp-pw-result:not([hidden])', { timeout: 20000 });
const pw = (await page.$eval('#att-mcp-pw-value', (el) => el.textContent)).trim();
check('Generate password returns a password', /^[A-Za-z0-9]{4}( [A-Za-z0-9]{4}){5}$/.test(pw), pw ? 'unexpected format' : 'empty');
const configs = await page.$$eval('pre[data-att-config]', (els) => els.map((e) => e.textContent));
check('password filled into all 5 client configs', configs.length === 5 && configs.every((c) => c.includes(pw) && !c.includes('replace-with-your-application-password')));
const rowText = await page.$eval('#att-mcp-pw-rows tr:first-child', (el) => el.textContent);
check('new password listed in the table', rowText.includes('AI agent (MCP)'), rowText);
await page.click('.att-tab-btn[data-tab="cursor"]');
check('client tabs switch panels', await page.$eval('#att-tab-cursor', (el) => getComputedStyle(el).display !== 'none'));
await page.click('.att-tab-btn[data-tab="claude"]');
await page.click('#att-tab-claude .att-copy-btn');
await new Promise((r) => setTimeout(r, 300));
const clip = await page.evaluate(() => navigator.clipboard.readText().catch(() => ''));
check('copy button puts the config on the clipboard', clip.includes(pw) && clip.includes('mcpServers'), clip.slice(0, 60));
await page.evaluate(() => window.scrollTo(0, 0));
await shot('screenshot-2.png');
dialogs.length = 0;
await page.click('#att-mcp-pw-rows tr:first-child .att-pw-revoke');
await page.waitForFunction(() => document.querySelector('#att-mcp-pw-rows tr:first-child').classList.contains('att-pw-revoked'), { timeout: 20000 });
check('Revoke asks, then revokes', dialogs.length === 1 && /Revoke/.test(dialogs[0]));

console.log('== MCP › Activity');
await go(`${BASE}/wp-admin/admin.php?page=att-mcp-activity`);
check('activity table has rows', (await page.$$eval('.att-table tbody tr', (els) => els.length)) > 5);
await shot('screenshot-3.png');

check('no JavaScript errors on any MCP screen', jsErrors.length === 0, jsErrors.join(' | '));
await browser.close();
const passed = results.filter(Boolean).length;
console.log(`\nRESULT: ${passed} passed, ${results.length - passed} failed`);
process.exit(passed === results.length ? 0 : 1);
