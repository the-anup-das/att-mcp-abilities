<?php
// Integration tests against the real SEO and cache plugins from WordPress.org,
// one plugin active at a time (integrations.sh installs them). Each phase runs
// in its own process: plugins load at startup, and some finish work at
// shutdown (Yoast rebuilds its indexables then).
//
//   php integrations.php <plugin-slug> activate|write|verify|deactivate
//   php integrations.php seo-by-rank-math rm-tools|rm-admin|rm-govern   (Rank Math's own MCP tools)
//
// A page-cache drop-in (advanced-cache.php) runs in this CLI process too; as a
// "GET /" it could serve the cached home page and exit. Not a GET = not cacheable.
$_SERVER['REQUEST_METHOD'] = 'POST';
// Some plugins include files by relative path (Super Page Cache: require_once
// 'bootstrap.php'), which PHP resolves against the working directory first —
// this folder has its own bootstrap.php. Run from the site root, as the server does.
chdir( getenv( 'ATT_WP_DIR' ) ? getenv( 'ATT_WP_DIR' ) : __DIR__ . '/wordpress' );
$att_admin = isset( $argv[2] ) && 'rm-admin' === $argv[2]; // renders MCP > Settings
require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( 1 );

$slug  = isset( $argv[1] ) ? $argv[1] : '';
$phase = isset( $argv[2] ) ? $argv[2] : '';
$files = array(
	'wordpress-seo'            => 'wordpress-seo/wp-seo.php',
	'seo-by-rank-math'         => 'seo-by-rank-math/rank-math.php',
	'all-in-one-seo-pack'      => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
	'wp-seopress'              => 'wp-seopress/seopress.php',
	'litespeed-cache'          => 'litespeed-cache/litespeed-cache.php',
	'wp-cloudflare-page-cache' => 'wp-cloudflare-page-cache/wp-cloudflare-super-page-cache.php',
);
$seo = array( 'wordpress-seo' => 'yoast', 'seo-by-rank-math' => 'rank_math', 'all-in-one-seo-pack' => 'aioseo', 'wp-seopress' => 'seopress' );
if ( ! isset( $files[ $slug ] ) ) {
	fwrite( STDERR, "Unknown plugin {$slug}\n" );
	exit( 2 );
}
t_section( "{$slug}: {$phase}" );

$title = 'Custom SEO Title for Coffee Beans';
$desc  = 'A custom meta description about fresh coffee beans, long enough to be useful in search results for espresso lovers.';

switch ( $phase ) {
	case 'activate':
		delete_option( 'rank_math_registration_skip' ); // start "not set up", as after a fresh install
		$r = activate_plugin( $files[ $slug ] );
		t_ok( "activate {$slug}", ! is_wp_error( $r ), $r );
		break;

	case 'deactivate':
		deactivate_plugins( $files[ $slug ] );
		t_ok( "deactivate {$slug}", ! is_plugin_active( $files[ $slug ] ) );
		break;

	case 'write':
		if ( isset( $seo[ $slug ] ) ) {
			$key = $seo[ $slug ];
			t_ok( 'SEO plugin detected', $key === att_mcp_seo_plugin(), att_mcp_seo_plugins() );
			$id = wp_insert_post( array(
				'post_title'   => "Integration {$slug}",
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( 'Fresh coffee beans make better espresso. ', 40 ) . '</p><!-- /wp:paragraph -->',
			) );
			update_option( 'att_e2e_int_post', $id );
			$r = t_run( 'att/update-seo-meta', array( 'id' => $id, 'title' => $title, 'description' => $desc, 'focus_keyword' => 'coffee beans', 'canonical' => 'https://example.com/canonical-coffee/', 'indexing' => 'noindex' ) );
			t_ok( 'update-seo-meta', is_array( $r ) && $key === $r['plugin'] && $r['change_id'] > 0, $r );
			update_option( 'att_e2e_int_change', is_array( $r ) ? (int) $r['change_id'] : 0 );
			$read = att_mcp_seo_read( $key, $id );
			t_ok( 'stored: title, description, focus keyword', $title === $read['title'] && $desc === $read['description'] && 'coffee beans' === $read['focus_keyword'], $read );
			t_ok( 'stored: canonical and noindex', 'https://example.com/canonical-coffee/' === $read['canonical'] && 'noindex' === $read['indexing'], $read );
			$r = t_run( 'att/update-seo-meta', array( 'id' => $id, 'plugin' => 'nope' ) );
			t_ok( 'unknown plugin refused', is_wp_error( $r ), $r );
			if ( 'rank_math' === $key ) {
				// Rank Math outputs nothing until its setup wizard is done — the tools must say so.
				$audit = t_run( 'att/seo-audit' );
				t_ok( 'Rank Math not set up yet is reported', '' !== att_mcp_seo_plugin_problem( 'rank_math' ) && false !== strpos( wp_json_encode( is_array( $audit ) ? $audit['site'] : array() ), 'not set up' ), is_array( $audit ) ? $audit['site'] : $audit );
				update_option( 'rank_math_registration_skip', true ); // what "Skip" in its setup wizard stores
			}
		} elseif ( 'litespeed-cache' === $slug ) {
			t_ok( 'LiteSpeed Cache detected', att_mcp_litespeed_available() );
			$g = t_run( 'att/get-cache-config' );
			t_ok( 'get-cache-config: LiteSpeed settings', is_array( $g ) && isset( $g['litespeed']['settings']['optm-css_min'], $g['litespeed']['presets']['advanced'] ), $g );
			t_ok( '... a php -S server cannot page-cache, and says so', is_array( $g ) && false === $g['litespeed']['page_cache_supported'] && false !== strpos( wp_json_encode( $g['litespeed']['recommendations'] ), 'not a LiteSpeed web server' ), is_array( $g ) ? $g['litespeed']['recommendations'] : $g );
			$json = wp_json_encode( $g );
			t_ok( '... no credential ids in the output', false === strpos( $json, 'object-pswd' ) && false === strpos( $json, 'cdn-cloudflare_key' ) && false === strpos( $json, '"api_key"' ) );

			$ids    = array( 'optm-css_min', 'optm-js_defer', 'media-lazy_exc' );
			$before = array();
			foreach ( $ids as $id ) {
				$before[ $id ] = att_mcp_litespeed_get( $id, true );
			}
			update_option( 'att_e2e_ls_before', $before );
			$r = t_run( 'att/update-cache-config', array( 'plugin' => 'litespeed', 'settings' => array( 'optm-css_min' => ! $before['optm-css_min'], 'optm-js_defer' => 1, 'media-lazy_exc' => array( 'hero.jpg', 'logo.png' ) ), 'purge' => false ) );
			t_ok( 'update-cache-config (LiteSpeed settings API)', is_array( $r ) && isset( $r['changed']['optm-css_min'] ) && ! $before['optm-css_min'] === att_mcp_litespeed_get( 'optm-css_min', true ) && 1 === (int) att_mcp_litespeed_get( 'optm-js_defer', true ), $r );
			t_ok( '... list setting saved as lines', array( 'hero.jpg', 'logo.png' ) === att_mcp_litespeed_get( 'media-lazy_exc', true ), att_mcp_litespeed_get( 'media-lazy_exc', true ) );
			t_ok( '... stored in LiteSpeed\'s own option', get_option( 'litespeed.conf.optm-js_defer' ) == 1 ); // phpcs:ignore Universal.Operators.StrictComparisons -- stored as int or string
			update_option( 'att_e2e_int_change', is_array( $r ) ? (int) $r['change_id'] : 0 );
			foreach ( array( array( 'api_key' => 'x' ), array( 'cdn-cloudflare_key' => 'x' ), array( 'object-pswd' => 'x' ) ) as $bad ) {
				$r = t_run( 'att/update-cache-config', array( 'plugin' => 'litespeed', 'settings' => $bad ) );
				t_ok( 'credential refused: ' . key( $bad ), 'att_mcp_unknown_setting' === t_code( $r ), $r );
			}
			$r = t_run( 'att/update-cache-config', array( 'plugin' => 'litespeed', 'settings' => array( 'optm-js_defer' => 5 ) ) );
			t_ok( 'out-of-range value refused', 'att_mcp_bad_value' === t_code( $r ), $r );
		} else { // Super Page Cache
			t_ok( 'Super Page Cache detected', att_mcp_spc_available() );
			$g = t_run( 'att/get-cache-config' );
			t_ok( 'get-cache-config: Super Page Cache settings', is_array( $g ) && isset( $g['super_page_cache']['settings']['cf_fallback_cache'] ) && false === $g['super_page_cache']['page_cache_enabled'], $g );
			$json = wp_json_encode( $g );
			t_ok( '... no Cloudflare credentials in the output', false === strpos( $json, 'cf_apitoken' ) && false === strpos( $json, 'cf_apikey' ) && false === strpos( $json, 'cf_email' ) && false === strpos( $json, 'secret_key' ) );
			update_option( 'att_e2e_spc_before', array( 'minify_html' => att_mcp_spc_get( 'minify_html' ), 'cf_prefetch_urls_mode' => att_mcp_spc_get( 'cf_prefetch_urls_mode' ), 'cf_cache_enabled' => att_mcp_spc_get( 'cf_cache_enabled' ), 'cf_fallback_cache' => att_mcp_spc_get( 'cf_fallback_cache' ) ) );
			$r = t_run( 'att/update-cache-config', array( 'plugin' => 'super_page_cache', 'settings' => array( 'cf_fallback_cache' => true, 'minify_html' => true, 'cf_prefetch_urls_mode' => 'hover', 'cf_fallback_cache_excluded_urls' => array( '/checkout/*', '/cart/*' ) ), 'purge' => false ) );
			t_ok( 'update-cache-config (Super Page Cache settings manager)', is_array( $r ) && 1 === (int) att_mcp_spc_get( 'minify_html' ) && 'hover' === att_mcp_spc_get( 'cf_prefetch_urls_mode' ), $r );
			t_ok( '... page cache switched on the way the plugin\'s wizard does', 1 === (int) att_mcp_spc_get( 'cf_cache_enabled' ) && 1 === (int) att_mcp_spc_get( 'cf_fallback_cache' ), is_array( $r ) ? $r : null );
			t_ok( '... disk cache drop-in installed', 'super_page_cache' === att_mcp_advanced_cache_dropin(), att_mcp_advanced_cache_dropin() );
			t_ok( '... exclusions saved as lines', in_array( '/checkout/*', (array) att_mcp_spc_get( 'cf_fallback_cache_excluded_urls' ), true ), att_mcp_spc_get( 'cf_fallback_cache_excluded_urls' ) );
			update_option( 'att_e2e_int_change', is_array( $r ) ? (int) $r['change_id'] : 0 );
			foreach ( array( 'cf_apitoken', 'cf_email', 'cf_purge_url_secret_key', 'cf_cache_enabled' ) as $bad ) {
				$r = t_run( 'att/update-cache-config', array( 'plugin' => 'super_page_cache', 'settings' => array( $bad => 'x' ) ) );
				t_ok( "not changeable: {$bad}", 'att_mcp_unknown_setting' === t_code( $r ), $r );
			}
			$r = t_run( 'att/update-cache-config', array( 'plugin' => 'super_page_cache', 'preset' => 'advanced' ) );
			t_ok( 'presets are LiteSpeed-only', 'att_mcp_bad_input' === t_code( $r ), $r );
		}
		break;

	case 'verify':
		$change = (int) get_option( 'att_e2e_int_change' );
		if ( isset( $seo[ $slug ] ) ) {
			$key  = $seo[ $slug ];
			$id   = (int) get_option( 'att_e2e_int_post' );
			t_ok( 'the SEO plugin is outputting tags', '' === att_mcp_seo_plugin_problem( $key ), att_mcp_seo_plugin_problem( $key ) );
			$page = att_mcp_fetch_own_page( get_permalink( $id ) );
			$scan = is_array( $page ) ? att_mcp_scan_html( $page['body'] ) : null;
			t_ok( 'rendered <title> is the SEO title', $scan && false !== strpos( (string) $scan['title'], $title ), $scan ? $scan['title'] : $page );
			t_ok( 'rendered meta description', $scan && $desc === $scan['meta']['description'], $scan ? $scan['meta'] : null );
			t_ok( 'rendered robots noindex', $scan && false !== strpos( strtolower( (string) $scan['meta']['robots'] ), 'noindex' ), $scan ? $scan['meta']['robots'] : null );

			$a      = t_run( 'att/analyze-post', array( 'id' => $id ) );
			$checks = is_array( $a ) ? wp_list_pluck( $a['checks'], 'status', 'id' ) : array();
			t_ok( 'analyze-post reads the focus keyword from the plugin', is_array( $a ) && 'coffee beans' === $a['focus_keyword'] && $key === $a['seo_plugin']['key'], $a );
			t_ok( '... title/description/keyword checks pass', 'pass' === ( $checks['description'] ?? '' ) && 'pass' === ( $checks['keyword_in_title'] ?? '' ) && 'pass' === ( $checks['keyword_in_description'] ?? '' ), $checks );
			t_ok( '... flags the noindex', 'fail' === ( $checks['indexing'] ?? '' ), $checks );
			$audit = t_run( 'att/seo-audit' );
			$row   = null;
			foreach ( ( is_array( $audit ) ? $audit['posts'] : array() ) as $p ) {
				if ( $id === $p['id'] ) { $row = $p; }
			}
			t_ok( 'seo-audit reads the plugin values', $row && 'coffee beans' === $row['focus_keyword'] && 'noindex' === $row['indexing'] && 'custom' === $row['description_source'] && in_array( 'noindex', $row['issues'], true ), $row );

			$r    = t_run( 'att/update-seo-meta', array( 'id' => $id, 'indexing' => 'index', 'title' => '' ) );
			$read = att_mcp_seo_read( $key, $id );
			t_ok( 'indexing index + clearing the title', is_array( $r ) && ( 'seopress' === $key ? 'default' : 'index' ) === $read['indexing'] && '' === $read['title'], $read );
			$u    = t_run( 'att/undo-change', array( 'id' => $change ) );
			$read = att_mcp_seo_read( $key, $id );
			t_ok( 'undo the first change restores the empty fields', ! is_wp_error( $u ) && '' === $read['title'] && '' === $read['description'] && '' === $read['focus_keyword'] && '' === $read['canonical'] && 'default' === $read['indexing'], is_wp_error( $u ) ? $u : $read );

			// Bulk: several posts in one call, invalid items reported, one undo point.
			$b1 = wp_insert_post( array( 'post_title' => "Bulk one {$slug}", 'post_status' => 'publish', 'post_content' => '<p>Bulk one</p>' ) );
			$b2 = wp_insert_post( array( 'post_title' => "Bulk two {$slug}", 'post_status' => 'publish', 'post_content' => '<p>Bulk two</p>' ) );
			$r  = t_run( 'att/bulk-update-seo-meta', array( 'items' => array(
				array( 'id' => $b1, 'title' => 'Bulk Title One', 'focus_keyword' => 'bulk one' ),
				array( 'id' => $b2, 'description' => 'Bulk description two, long enough to be a real meta description for the second post.' ),
				array( 'id' => 999999, 'title' => 'x' ),
			) ) );
			$r1 = att_mcp_seo_read( $key, $b1 );
			$r2 = att_mcp_seo_read( $key, $b2 );
			t_ok( 'bulk-update-seo-meta writes several posts', is_array( $r ) && 2 === count( $r['updated'] ) && 'Bulk Title One' === $r1['title'] && 'bulk one' === $r1['focus_keyword'] && 0 === strpos( $r2['description'], 'Bulk description two' ), $r );
			t_ok( '... and reports the invalid item', is_array( $r ) && 1 === count( $r['errors'] ) && 999999 === $r['errors'][0]['id'], is_array( $r ) ? $r['errors'] : $r );
			$u  = t_run( 'att/undo-change', array( 'id' => is_array( $r ) ? (int) $r['change_id'] : 0 ) );
			$r1 = att_mcp_seo_read( $key, $b1 );
			$r2 = att_mcp_seo_read( $key, $b2 );
			t_ok( '... one undo reverts the whole batch', ! is_wp_error( $u ) && '' === $r1['title'] && '' === $r1['focus_keyword'] && '' === $r2['description'], is_wp_error( $u ) ? $u : array( $r1, $r2 ) );

			$login  = 'int_author_' . $key . '_' . wp_rand( 1000, 999999 );
			$author = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.com', 'role' => 'author' ) );
			t_ok( 'test author created', is_int( $author ), $author );
			wp_set_current_user( is_int( $author ) ? $author : 0 );
			$r = t_run( 'att/update-seo-meta', array( 'id' => $id, 'title' => 'hacked' ) );
			t_ok( "an author cannot change the SEO meta of someone else's post", 'att_mcp_forbidden' === t_code( $r ) && 'hacked' !== att_mcp_seo_read( $key, $id )['title'], $r );
			wp_set_current_user( 1 );
		} elseif ( 'litespeed-cache' === $slug ) {
			$before = get_option( 'att_e2e_ls_before' );
			$u      = t_run( 'att/undo-change', array( 'id' => $change ) );
			$ok     = ! is_wp_error( $u );
			foreach ( $before as $id => $value ) {
				$ok = $ok && att_mcp_litespeed_get( $id, true ) === $value;
			}
			t_ok( 'undo restores the LiteSpeed settings', $ok, $u );
			$was = array( 'optm-css_min' => att_mcp_litespeed_get( 'optm-css_min', true ), 'guest' => att_mcp_litespeed_get( 'guest', true ) );
			$p   = t_run( 'att/update-cache-config', array( 'plugin' => 'litespeed', 'preset' => 'advanced', 'purge' => false ) );
			t_ok( 'preset "advanced" applied', is_array( $p ) && true === att_mcp_litespeed_get( 'optm-css_min', true ) && true === att_mcp_litespeed_get( 'guest', true ) && count( $p['changed'] ) > 3, $p );
			t_ok( '... warns that guest mode/cache need a LiteSpeed server', is_array( $p ) && false !== strpos( wp_json_encode( $p['warnings'] ), 'not a LiteSpeed web server' ), is_array( $p ) ? $p['warnings'] : null );
			$u = t_run( 'att/undo-change', array( 'id' => is_array( $p ) ? (int) $p['change_id'] : 0 ) );
			t_ok( 'undo the preset', ! is_wp_error( $u ) && $was['optm-css_min'] === att_mcp_litespeed_get( 'optm-css_min', true ) && $was['guest'] === att_mcp_litespeed_get( 'guest', true ), $u );
		} else { // Super Page Cache: the page is served from the disk cache on the second visit.
			$url    = home_url( '/' );
			$first  = att_mcp_fetch_own_page( $url );
			$second = att_mcp_fetch_own_page( $url );
			t_ok( 'disk cache serves the second visit', is_array( $second ) && 'HIT' === strtoupper( att_mcp_header( $second['headers'], 'x-wp-spc-disk-cache' ) ), is_array( $second ) ? $second['headers'] : $second );
			$audit = t_run( 'att/audit-performance' );
			t_ok( 'audit-performance sees the cache hit', is_array( $audit ) && true === $audit['page']['page_cache']['hit'] && ! in_array( 'caching', wp_list_pluck( $audit['recommendations'], 'area' ), true ), is_array( $audit ) ? $audit['recommendations'] : $audit );
			$before = get_option( 'att_e2e_spc_before' );
			$u      = t_run( 'att/undo-change', array( 'id' => $change ) );
			$ok     = ! is_wp_error( $u );
			foreach ( $before as $k => $v ) {
				$ok = $ok && att_mcp_spc_get( $k ) == $v; // phpcs:ignore Universal.Operators.StrictComparisons -- the plugin mixes int and string storage
			}
			t_ok( 'undo restores the Super Page Cache settings (page cache off again)', $ok, $u );
		}
		break;

	case 'rm-tools':
		// Rank Math's own MCP tools while the Rank Math addon is off: available as
		// Rank Math ships them, but under the MCP Controls, logged and undoable.
		global $wpdb;
		$known   = att_mcp_governed_known();
		$missing = array();
		foreach ( array_keys( $known ) as $name ) {
			if ( ! wp_has_ability( $name ) ) {
				$missing[] = $name;
			}
		}
		t_ok( 'Rank Math registers the ' . count( $known ) . ' tools this plugin describes', ! $missing, $missing );
		$unknown = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( 0 === strpos( $ability->get_name(), 'rank-math/' ) && ! isset( $known[ $ability->get_name() ] ) ) {
				$unknown[] = $ability->get_name();
			}
		}
		if ( $unknown ) {
			echo '  note  tools added by a newer Rank Math: ' . implode( ', ', $unknown ) . "\n";
		}
		$seen = (array) get_option( 'att_mcp_seen_abilities', array() );
		t_ok( '... any tool it does not describe is remembered for MCP > Settings', ! array_diff( $unknown, array_keys( $seen ) ), $unknown );

		$mcp = wp_get_ability( 'rank-math/get-settings' )->get_meta_item( 'mcp' );
		t_ok( 'addon off: its tools stay exposed to MCP as Rank Math ships them', ! empty( $mcp['public'] ), $mcp );
		$r = t_run( 'rank-math/get-settings' );
		t_ok( 'rank-math/get-settings runs through this plugin', is_array( $r ) && empty( $r['error'] ), $r );
		$last = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', att_mcp_audit_table() ) );
		t_ok( '... and is logged in MCP > Activity', $last && 'rank-math/get-settings' === $last->ability && 'read' === $last->access && 'ok' === $last->status, $last );

		// A Rank Math write that changes a core option: recorded and undoable.
		$tagline = get_option( 'blogdescription' );
		$r       = t_run( 'rank-math/fix-site-seo', array( 'test_id' => 'site_description', 'value' => 'Fresh beans, roasted weekly' ) );
		t_ok( 'rank-math/fix-site-seo sets the tagline', is_array( $r ) && ! empty( $r['fixed'] ) && 'Fresh beans, roasted weekly' === get_option( 'blogdescription' ), $r );
		$list = t_run( 'att/list-changes', array( 'limit' => 1 ) );
		$c    = is_array( $list ) ? $list['changes'][0] : null;
		t_ok( '... recorded as an undoable change', $c && 'rank-math/fix-site-seo' === $c['ability'] && in_array( 'blogdescription', wp_list_pluck( $c['items'], 'target' ), true ), $list );
		$u = t_run( 'att/undo-change' );
		t_ok( '... and undone', ! is_wp_error( $u ) && $tagline === get_option( 'blogdescription' ), $u );

		// Focus keywords on many posts at once (post meta), undone in one go.
		$r    = t_run( 'rank-math/fix-site-seo', array( 'test_id' => 'focus_keywords', 'post_limit' => 5 ) );
		$list = t_run( 'att/list-changes', array( 'limit' => 1 ) );
		$c    = is_array( $list ) ? $list['changes'][0] : array( 'ability' => '', 'items' => array() );
		$meta = array();
		foreach ( $c['items'] as $it ) {
			if ( 'post_meta' === $it['type'] && 'rank_math_focus_keyword' === $it['target'][1] ) {
				$meta[] = (int) $it['target'][0];
			}
		}
		$set = 0;
		foreach ( $meta as $pid ) {
			$set += '' !== (string) get_post_meta( $pid, 'rank_math_focus_keyword', true ) ? 1 : 0;
		}
		t_ok( 'bulk focus keywords from titles are recorded post by post', is_array( $r ) && ! empty( $r['fixed'] ) && 'rank-math/fix-site-seo' === $c['ability'] && $meta && count( $meta ) === $set, array( $r, $c ) );
		$u    = t_run( 'att/undo-change' );
		$left = 0;
		foreach ( $meta as $pid ) {
			$left += metadata_exists( 'post', $pid, 'rank_math_focus_keyword' ) ? 1 : 0;
		}
		t_ok( '... and one undo removes them all', ! is_wp_error( $u ) && 0 === $left, $left );

		// Modules on/off through Rank Math's own tool: undo and redo.
		$r = t_run( 'rank-math/set-module-status', array( 'modules' => array( 'redirections' => true, '404-monitor' => true ) ) );
		t_ok( 'rank-math/set-module-status turns modules on', is_array( $r ) && ! empty( $r['saved'] ) && in_array( 'redirections', (array) get_option( 'rank_math_modules' ), true ), $r );
		$u = t_run( 'att/undo-change' );
		t_ok( '... undo turns them off again', ! is_wp_error( $u ) && ! in_array( 'redirections', (array) get_option( 'rank_math_modules' ), true ), $u );
		t_run( 'att/undo-change', array( 'id' => is_array( $u ) ? (int) $u['redo_change_id'] : 0 ) );
		t_ok( '... and redo turns them back on', in_array( 'redirections', (array) get_option( 'rank_math_modules' ), true ) && in_array( '404-monitor', (array) get_option( 'rank_math_modules' ), true ) );

		// The MCP Controls apply to Rank Math's tools at run time too.
		update_option( 'att_mcp_controls', array_merge( att_mcp_get_controls(), array( 'writes_paused' => true ) ) );
		$r = t_run( 'rank-math/set-sitemap-settings', array( 'include_images' => true ) );
		t_ok( 'read-only mode refuses Rank Math writes', 'att_mcp_writes_paused' === t_code( $r ), $r );
		t_ok( '... but its reads still work', is_array( t_run( 'rank-math/get-robots-txt' ) ) );
		update_option( 'att_mcp_controls', array_merge( att_mcp_get_controls(), array( 'writes_paused' => false, 'active' => false ) ) );
		$r = t_run( 'rank-math/get-settings' );
		t_ok( 'the kill switch stops Rank Math tools too', 'att_mcp_disabled' === t_code( $r ), $r );
		update_option( 'att_mcp_controls', array_merge( att_mcp_get_controls(), array( 'active' => true ) ) );
		$r = t_run( 'rank-math/set-website-identity', array( 'website_name' => '***redacted***' ) );
		t_ok( 'a redacted placeholder cannot be written through Rank Math', 'att_mcp_redacted_value' === t_code( $r ), $r );

		// Next phase: the Rank Math addon on, with one of its write tools switched off.
		$addons              = (array) get_option( 'att_mcp_addons', array() );
		$addons['rank_math'] = true;
		update_option( 'att_mcp_addons', $addons );
		$toggles = (array) get_option( 'att_mcp_abilities', array() );
		$toggles['rank-math/set-sitemap-settings'] = false;
		foreach ( array( 'rank-math/get-settings', 'rank-math/get-robots-txt', 'rank-math/audit-site-seo', 'rank-math/get-404-logs', 'att/rank-math-save-redirection', 'att/rank-math-delete-redirections', 'att/rank-math-clear-404-logs', 'att/bulk-update-seo-meta' ) as $k ) {
			$toggles[ $k ] = true;
		}
		update_option( 'att_mcp_abilities', $toggles );
		break;

	case 'rm-admin':
		// MCP > Settings lists Rank Math's own tools in the Rank Math card.
		$_GET['page'] = 'att-mcp-abilities';
		set_current_screen( 'toplevel_page_att-mcp-abilities' );
		ob_start();
		att_mcp_settings_page();
		$html = ob_get_clean();
		t_ok( 'the Rank Math card shows as detected, with its own tools and the fix tools', false !== strpos( $html, 'data-addon-card="rank_math"' ) && false !== strpos( $html, 'att_mcp_abilities[rank-math/audit-site-seo]' ) && false !== strpos( $html, 'att_mcp_abilities[att/rank-math-save-redirection]' ) );
		t_ok( '... explaining how its tools are controlled', false !== strpos( $html, 'att-addon-govern' ) );
		t_ok( '... and the switch state of each tool', 1 === preg_match( '#name="att_mcp_abilities\[rank-math/set-sitemap-settings\]"[^>]*>#', $html, $m ) && false === strpos( $m[0], 'checked' ) );
		break;

	case 'rm-govern':
		// Real sites use pretty permalinks. With plain ones every unknown path shows the
		// home page, so there are no 404s to log and no 410 to serve.
		$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
		flush_rewrite_rules( false );

		// The Rank Math addon is on: per-tool toggles apply, and the fix tools work.
		$a   = wp_get_ability( 'rank-math/set-sitemap-settings' );
		$mcp = $a ? $a->get_meta_item( 'mcp' ) : null;
		t_ok( 'a Rank Math tool switched off is hidden from MCP and the REST API', $a && empty( $mcp['public'] ) && false === $a->get_meta_item( 'show_in_rest' ), $mcp );
		$r = t_run( 'rank-math/set-sitemap-settings', array( 'include_images' => true ) );
		t_ok( '... and refuses to run', 'att_mcp_ability_disabled' === t_code( $r ), $r );
		$r = t_run( 'rank-math/get-settings' );
		t_ok( 'a Rank Math tool left on runs', is_array( $r ) && empty( $r['error'] ), $r );

		// Redirections.
		$target = get_permalink( (int) get_option( 'att_e2e_int_post' ) );
		$status = function ( $path ) {
			$resp = wp_safe_remote_get( home_url( $path ), array( 'redirection' => 0, 'timeout' => 20 ) );
			return is_wp_error( $resp ) ? array( 0, '' ) : array( (int) wp_remote_retrieve_response_code( $resp ), (string) wp_remote_retrieve_header( $resp, 'location' ) );
		};
		$r   = t_run( 'att/rank-math-save-redirection', array( 'from' => array( '/old-coffee-page/' ), 'url_to' => $target ) );
		$rid = is_array( $r ) ? (int) $r['id'] : 0;
		t_ok( 'save-redirection creates a 301', $rid > 0 && ! empty( $r['created'] ) && 301 === $r['redirection']['header_code'], $r );
		t_ok( '... and the site redirects the old URL', array( 301, $target ) === $status( '/old-coffee-page/' ), $status( '/old-coffee-page/' ) );
		$r = t_run( 'att/rank-math-save-redirection', array( 'id' => $rid, 'header_code' => 302 ) );
		t_ok( 'edit keeps the sources and changes the type', is_array( $r ) && empty( $r['created'] ) && 302 === $r['redirection']['header_code'] && $target === $r['redirection']['url_to'], $r );
		$u = t_run( 'att/undo-change' );
		t_ok( '... undo restores the 301', ! is_wp_error( $u ) && 301 === att_mcp_rank_math_redirection_row( $rid )['header_code'], $u );
		$r = t_run( 'att/rank-math-save-redirection', array( 'from' => array( '/old-coffee-page/' ), 'url_to' => home_url( '/' ) ) );
		t_ok( 'a new redirection with the same source updates the existing one, as in Rank Math', is_array( $r ) && $rid === $r['id'] && empty( $r['created'] ), $r );
		t_run( 'att/undo-change' );
		t_ok( '... and its undo restores the original destination', $target === att_mcp_rank_math_redirection_row( $rid )['url_to'] );
		$r = t_run( 'att/rank-math-save-redirection', array( 'from' => array( '/loop-me/' ), 'url_to' => '/loop-me' ) );
		t_ok( 'a redirect loop is refused', 'att_mcp_redirect_loop' === t_code( $r ), $r );
		$r = t_run( 'att/rank-math-save-redirection', array( 'from' => array( 'https://example.com/elsewhere/' ), 'url_to' => '/' ) );
		t_ok( "another site's URL cannot be a source", 'att_mcp_bad_input' === t_code( $r ), $r );
		$r = t_run( 'att/rank-math-save-redirection', array( 'sources' => array( array( 'pattern' => '(unclosed', 'comparison' => 'regex' ) ), 'url_to' => '/' ) );
		t_ok( 'an invalid regex is refused', 'att_mcp_bad_input' === t_code( $r ), $r );

		$r = t_run( 'att/rank-math-delete-redirections', array( 'ids' => array( $rid, 987654 ) ) );
		t_ok( 'delete-redirections trashes', is_array( $r ) && array( $rid ) === $r['trashed'] && array( 987654 ) === $r['not_found'] && 'trashed' === att_mcp_rank_math_redirection_row( $rid )['status'], $r );
		t_ok( '... and the redirect stops', 301 !== $status( '/old-coffee-page/' )[0], $status( '/old-coffee-page/' ) );
		$u = t_run( 'att/undo-change' );
		t_ok( '... undo re-activates it', ! is_wp_error( $u ) && 'active' === att_mcp_rank_math_redirection_row( $rid )['status'], $u );
		$r = t_run( 'att/rank-math-delete-redirections', array( 'ids' => array( $rid ), 'permanent' => true ) );
		t_ok( 'permanent delete', is_array( $r ) && array( $rid ) === $r['deleted'] && null === att_mcp_rank_math_redirection_row( $rid ), $r );
		$u   = t_run( 'att/undo-change' );
		$row = att_mcp_rank_math_redirection_row( $rid );
		t_ok( '... undo puts it back under the same id', ! is_wp_error( $u ) && $row && 'active' === $row['status'] && 301 === $row['header_code'], $row );
		t_ok( '... and it redirects again', array( 301, $target ) === $status( '/old-coffee-page/' ), $status( '/old-coffee-page/' ) );
		$r   = t_run( 'att/rank-math-save-redirection', array( 'from' => array( '/gone-page/' ), 'header_code' => 410 ) );
		$gid = is_array( $r ) ? (int) $r['id'] : 0;
		t_ok( '410 needs no destination', $gid > 0 && 410 === $r['redirection']['header_code'] && '' === $r['redirection']['url_to'], $r );
		t_ok( '... and the site answers 410 Gone', 410 === $status( '/gone-page/' )[0], $status( '/gone-page/' ) );
		t_run( 'att/undo-change' );
		t_ok( 'undoing a new redirection removes it', null === att_mcp_rank_math_redirection_row( $gid ) );

		// 404 log: a missing URL is logged by Rank Math, then cleared.
		$missing = '/no-such-page-' . wp_rand( 1000, 999999 ) . '/';
		$status( $missing );
		$logs  = t_run( 'rank-math/get-404-logs' );
		$entry = null;
		foreach ( ( is_array( $logs ) && isset( $logs['items'] ) ) ? $logs['items'] : array() as $item ) {
			if ( false !== strpos( $item['uri'], trim( $missing, '/' ) ) ) {
				$entry = $item;
			}
		}
		t_ok( 'Rank Math logs the 404 (rank-math/get-404-logs)', null !== $entry, $logs );
		$r = t_run( 'att/rank-math-clear-404-logs', array( 'ids' => array( $entry ? (int) $entry['id'] : 0 ) ) );
		t_ok( 'clear-404-logs removes that entry', is_array( $r ) && 1 === $r['deleted'], $r );
		$r = t_run( 'att/rank-math-clear-404-logs' );
		t_ok( '... and needs ids or "all"', 'att_mcp_bad_input' === t_code( $r ), $r );
		$r = t_run( 'att/rank-math-clear-404-logs', array( 'all' => true ) );
		t_ok( '"all" empties the log', is_array( $r ) && 0 === $r['remaining'], $r );

		// Without the module, the tools say how to turn it on.
		\RankMath\Helper::update_modules( array( 'redirections' => 'off' ) );
		$r = t_run( 'att/rank-math-save-redirection', array( 'from' => array( '/x/' ), 'url_to' => '/' ) );
		t_ok( 'a module that is off is reported with how to turn it on', 'att_mcp_module_inactive' === t_code( $r ) && false !== strpos( $r->get_error_message(), 'set-module-status' ), $r );
		\RankMath\Helper::update_modules( array( 'redirections' => 'on' ) );

		$GLOBALS['wp_rewrite']->set_permalink_structure( '' );
		flush_rewrite_rules( false );
		break;

	default:
		fwrite( STDERR, "Unknown phase {$phase}\n" );
		exit( 2 );
}
t_finish();
