<?php
// Integration tests against the real SEO and cache plugins from WordPress.org,
// one plugin active at a time (integrations.sh installs them). Each phase runs
// in its own process: plugins load at startup, and some finish work at
// shutdown (Yoast rebuilds its indexables then).
//
//   php integrations.php <plugin-slug> activate|write|verify|deactivate
//
// A page-cache drop-in (advanced-cache.php) runs in this CLI process too; as a
// "GET /" it could serve the cached home page and exit. Not a GET = not cacheable.
$_SERVER['REQUEST_METHOD'] = 'POST';
// Some plugins include files by relative path (Super Page Cache: require_once
// 'bootstrap.php'), which PHP resolves against the working directory first —
// this folder has its own bootstrap.php. Run from the site root, as the server does.
chdir( getenv( 'ATT_WP_DIR' ) ? getenv( 'ATT_WP_DIR' ) : __DIR__ . '/wordpress' );
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

	default:
		fwrite( STDERR, "Unknown phase {$phase}\n" );
		exit( 2 );
}
t_finish();
