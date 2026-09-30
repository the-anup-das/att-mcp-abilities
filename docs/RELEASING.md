# Releasing to WordPress.org

This covers the one-time directory submission and every release after it. The plugin slug is **`att-mcp-abilities`**: the plugin folder, main file, text domain, and this repository all use that name.

## What goes where

| Where | What | Source in this repo |
|---|---|---|
| Plugin zip / SVN `trunk` | The plugin itself | `att-mcp-abilities/` |
| SVN `assets` | Directory icon, banner, screenshots | `.wordpress-org/` |
| SVN `tags/<version>` | A copy of `trunk` for each release | created by the deploy |

`.wordpress-org/` holds `icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png` and `screenshot-1..3.png`. The screenshots match the `== Screenshots ==` captions in `readme.txt`, in order. Regenerate the icons and banners with `cd brand && npm install && npm run build`, and the screenshots as described in [tests/e2e/README.md](../tests/e2e/README.md#screenshots).

## The two workflows

| Workflow | Runs when | Does |
|---|---|---|
| **CI** (`ci.yml`) | Every push and pull request, or on demand: **Actions › CI › Run workflow** | PHP 7.4/8.3 lint, Plugin Check, the end-to-end suite on WordPress 6.9 and the latest release, and builds the zip (download it from the run's **Artifacts**) |
| **Deploy to WordPress.org** (`deploy.yml`) | A version tag is pushed (e.g. `1.11.0`) | Runs CI, checks that the tag matches the version, publishes to WordPress.org SVN, and creates the GitHub release with the zip |

**Don't create a GitHub release or a version tag by hand before the plugin is approved.** A version tag starts the WordPress.org deploy, which fails until the SVN repository and secrets exist. The deploy workflow creates each release, zip included, for you.

## 1. One-time: get the plugin approved

1. **WordPress.org account.** Log in or register at <https://login.wordpress.org/>. Turn on **two-factor authentication** (Profile › Account & Security); it is required for accounts that commit to plugins.
2. **Contributors.** Put your WordPress.org username in `att-mcp-abilities/readme.txt`:
   `Contributors: your-username` (several are separated by commas). Commit and push.
3. **Build the zip.** Either run `powershell -ExecutionPolicy Bypass -File build.ps1`, which produces `dist/att-mcp-abilities-<version>.zip`, or download the `att-mcp-abilities-<version>.zip` artifact from the latest green CI run on GitHub (Actions › CI › the run › Artifacts).
4. **Submit.** Go to <https://wordpress.org/plugins/developers/add/>, upload the zip and accept the terms. The page runs Plugin Check on upload (this repo's CI runs the same checks) and shows the slug it will reserve (`att-mcp-abilities`).
5. **Review.** You get an automated email, then a volunteer reviewer writes from `plugins@wordpress.org` (add it to your contacts so replies don't land in spam). Reply in that same email thread. If they ask for changes, fix them here, rebuild, and upload the new zip from the same *Add your plugin* page. Reviews can take several weeks; the page shows the current queue.
   Questions this plugin may get, and where the answers already live:
   - **The name "ATT"** could be read as a trademark (AT&T). If the reviewer asks for a rename, do it **before** approval, because the slug can't change afterwards. It changes the Plugin Name, folder/main file, text domain and this repo's name.
   - **Installing plugins/themes and running PHP.** Both are admin opt-in only: the Advanced addon, and the Allow PHP switch that `DISALLOW_FILE_EDIT` locks off. Installs come from WordPress.org only, through core's REST API. See `readme.txt` › FAQ and `SECURITY.md`.
   - **Dependency on MCP Adapter,** which is hosted on GitHub rather than WordPress.org. It's documented in `readme.txt` › Description/FAQ, and the plugin shows a notice while it is missing.
6. **Approval.** The email gives you the SVN repository `https://plugins.svn.wordpress.org/att-mcp-abilities/`. The public page <https://wordpress.org/plugins/att-mcp-abilities/> appears after your first commit to it (step 2 below).

## 2. Publish a release (first release and every update)

### Recommended: GitHub Actions (`.github/workflows/deploy.yml`)

One-time setup:

1. On WordPress.org go to **Profile › Account & Security › SVN password** and generate a password.
2. On GitHub go to **Settings › Secrets and variables › Actions › New repository secret** and add:
   - `SVN_USERNAME`: your WordPress.org username
   - `SVN_PASSWORD`: the SVN password from step 1

Each release:

1. Bump the version in **three** places, which must be identical (the build and the deploy both refuse a mismatch):
   - `Version:` in the header of `att-mcp-abilities/att-mcp-abilities.php`
   - `define( 'ATT_MCP_VERSION', '…' )` in the same file
   - `Stable tag:` in `att-mcp-abilities/readme.txt`
2. Add a `= x.y.z =` entry under `== Changelog ==` (and an `== Upgrade Notice ==` line if users must act). Keep `Tested up to:` at the current WordPress major.
3. Commit and push to `main`, and wait for CI to go green.
4. Tag it with the plain version number, no `v`:
   ```bash
   git tag 1.11.0
   git push origin 1.11.0
   ```
   The **Deploy to WordPress.org** workflow runs the full CI first, checks that the tag matches the version, publishes `att-mcp-abilities/` to `trunk` and `tags/1.11.0`, uploads `.wordpress-org/` to `assets`, and creates a GitHub release with the zip. WordPress.org usually shows the new version within about 15 minutes.

### Alternative: manual SVN

```bash
svn checkout https://plugins.svn.wordpress.org/att-mcp-abilities svn-att
# replace trunk with the plugin folder, and assets with the directory images
rsync -a --delete att-mcp-abilities/ svn-att/trunk/     # or copy by hand on Windows
rsync -a --delete .wordpress-org/ svn-att/assets/
cd svn-att
svn add --force trunk assets
svn propset svn:mime-type image/png assets/*.png
svn propset svn:mime-type image/svg+xml assets/*.svg
svn copy trunk tags/1.11.0
svn commit -m "Release 1.11.0" --username your-username   # asks for the SVN password
```

On Windows, [TortoiseSVN](https://tortoisesvn.net/) does the same through Explorer.

## 3. Before every release

- [ ] CI is green on `main`: PHP 7.4/8.3 lint, Plugin Check, and end-to-end tests on WordPress 6.9 and the latest release.
- [ ] Locally, optionally: `bash tests/e2e/setup.sh && ATT_BROWSER="<Chrome or Edge path>" bash tests/e2e/run-all.sh --net`.
- [ ] Version, `ATT_MCP_VERSION` and `Stable tag` are equal; the changelog is updated; `Tested up to` is current.
- [ ] `readme.txt` › External services still describes every outbound request.
- [ ] Screenshots still match the UI (regenerate if the admin screens changed).
