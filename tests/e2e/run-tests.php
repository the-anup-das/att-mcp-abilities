<?php
// Step 2: exercise every ability through core's WP_Ability::execute() — the
// same path the MCP Adapter uses — and verify functionality + security gates.
require __DIR__ . '/bootstrap.php';

$net = in_array( '--net', $argv, true ); // tests that need internet access
wp_set_current_user( 1 );

function set_controls( $changes ) {
	$c = array_merge( att_mcp_get_controls(), $changes );
	update_option( 'att_mcp_controls', $c );
}

/* ------------------------------------------------------------------ */
t_section( 'Registration' );
$registry   = att_mcp_ability_registry();
$registered = array_filter( array_keys( $registry ), 'wp_has_ability' );
t_ok( 'all ' . count( $registry ) . ' registry abilities registered', count( $registered ) === count( $registry ), array_values( array_diff( array_keys( $registry ), $registered ) ) );
$a = wp_get_ability( 'att/get-posts' );
t_ok( 'meta.public is true, show_in_rest false', $a && true === $a->get_meta_item( 'public' ) && false === $a->get_meta_item( 'show_in_rest' ) );
$ann = $a ? $a->get_meta_item( 'annotations' ) : array();
t_ok( 'read ability annotated readonly', ! empty( $ann['readonly'] ) );
$ann = wp_get_ability( 'att/delete-post' )->get_meta_item( 'annotations' );
t_ok( 'delete ability annotated destructive', ! empty( $ann['destructive'] ) && empty( $ann['readonly'] ) );
t_ok( 'no-arg ability accepts missing input', ! is_wp_error( wp_get_ability( 'att/get-site-info' )->execute() ) );

/* ------------------------------------------------------------------ */
t_section( 'Core reads' );
foreach ( array( 'get-site-info', 'get-posts', 'get-pages', 'get-categories', 'get-tags', 'get-site-settings', 'get-plugins', 'get-themes',
	'get-active-theme', 'get-custom-css', 'get-theme-mods', 'get-menus', 'get-media', 'get-users', 'get-comments', 'list-changes' ) as $n ) {
	$r = t_run( 'att/' . $n );
	t_ok( $n, ! is_wp_error( $r ), $r );
}
$r = t_run( 'att/search', array( 'query' => 'Hello' ) );
t_ok( 'search', ! is_wp_error( $r ) && isset( $r['results'] ), $r );
$r = t_run( 'att/get-terms', array( 'taxonomy' => 'category' ) );
t_ok( 'get-terms category', ! is_wp_error( $r ) && ! empty( $r['terms'] ), $r );
$r = t_run( 'att/get-site-info' );
t_ok( 'admin sees admin_email', isset( $r['admin_email'] ) );

/* ------------------------------------------------------------------ */
t_section( 'Content: exact block markup, templates, terms, meta' );
$raw = <<<'HTML'
<!-- wp:paragraph {"className":"x\u002dy"} -->
<p>Path C:\temp\new — "quote" ✓ &amp; more</p>
<!-- /wp:paragraph -->
HTML;
$page = t_run( 'att/create-page', array( 'title' => 'Test Page', 'content' => $raw, 'status' => 'publish', 'slug' => 'test-page' ) );
t_ok( 'create-page', ! is_wp_error( $page ) && ! empty( $page['id'] ), $page );
$page_id = is_array( $page ) ? (int) $page['id'] : 0;
$got = t_run( 'att/get-content', array( 'id' => $page_id ) );
t_ok( 'content stored byte-exact (backslashes kept)', is_array( $got ) && $got['content'] === $raw, is_array( $got ) ? $got['content'] : $got );
$bad = t_run( 'att/update-page', array( 'id' => $page_id, 'title' => 'SHOULD NOT SAVE', 'template' => 'nope.php' ) );
t_ok( 'invalid template rejected', 'att_mcp_bad_template' === t_code( $bad ), $bad );
t_ok( '... and nothing was half-saved', 'Test Page' === get_the_title( $page_id ) );
$tpls = is_array( $got ) ? array_keys( (array) $got['available_templates'] ) : array();
if ( $tpls ) {
	$r = t_run( 'att/update-page', array( 'id' => $page_id, 'template' => $tpls[0], 'menu_order' => 3 ) );
	t_ok( 'valid template applied', ! is_wp_error( $r ) && get_page_template_slug( $page_id ) === $tpls[0], $r );
}
$draft = t_run( 'att/create-page', array( 'title' => 'Draft Child', 'content' => '<p>draft body</p>', 'parent' => $page_id ) );
$draft_id = is_array( $draft ) ? (int) $draft['id'] : 0;
t_ok( 'create child draft page', $draft_id && 'draft' === get_post_status( $draft_id ) && $page_id === wp_get_post_parent_id( $draft_id ), $draft );

$post = t_run( 'att/create-post', array( 'title' => 'Hello MCP', 'content' => '<p>Body</p>', 'status' => 'publish', 'categories' => array( 'News' ), 'tags' => array( 'launch', 'ai' ) ) );
$post_id = is_array( $post ) ? (int) $post['id'] : 0;
t_ok( 'create-post with new term names', $post_id && has_term( 'news', 'category', $post_id ) && has_tag( 'launch', $post_id ), $post );

$r = t_run( 'att/update-content', array( 'id' => $page_id, 'meta' => array( '_att_test_flag' => 'yes', '_wp_attached_file' => '../../wp-config.php', 'api_key' => 'x' ) ) );
t_ok( 'meta: allowed key written', 'yes' === get_post_meta( $page_id, '_att_test_flag', true ), $r );
t_ok( 'meta: _wp_attached_file refused', is_array( $r ) && isset( $r['meta']['skipped']['_wp_attached_file'] ) && '' === get_post_meta( $page_id, '_wp_attached_file', true ), $r );
t_ok( 'meta: secret-like key refused', is_array( $r ) && isset( $r['meta']['skipped']['api_key'] ) );
$u = t_run( 'att/undo-change' );
t_ok( 'undo meta change', ! is_wp_error( $u ) && '' === get_post_meta( $page_id, '_att_test_flag', true ), $u );

$list = t_run( 'att/list-content', array( 'post_type' => 'page', 'status' => 'any' ) );
$ids  = is_array( $list ) ? wp_list_pluck( $list['items'], 'id' ) : array();
t_ok( 'list-content includes drafts', in_array( $draft_id, $ids, true ), $list );
$r = t_run( 'att/list-content', array( 'post_type' => 'wp_template' ) );
t_ok( 'list-content refuses wp_template', 'att_mcp_post_type_blocked' === t_code( $r ), $r );
$r = t_run( 'att/create-content', array( 'post_type' => 'wp_block', 'title' => 'CTA pattern', 'content' => '<!-- wp:paragraph --><p>Call us</p><!-- /wp:paragraph -->', 'status' => 'publish' ) );
t_ok( 'create-content reusable pattern (wp_block)', ! is_wp_error( $r ) && 'wp_block' === get_post_type( $r['id'] ), $r );
$pattern_id = is_array( $r ) ? (int) $r['id'] : 0;
$r = t_run( 'att/delete-content', array( 'id' => $pattern_id ) );
t_ok( 'delete-content trashes', is_array( $r ) && ! empty( $r['trashed'] ), $r );

/* ------------------------------------------------------------------ */
t_section( 'Revisions' );
t_run( 'att/update-page', array( 'id' => $page_id, 'content' => '<p>Version 2</p>' ) );
t_run( 'att/update-page', array( 'id' => $page_id, 'content' => '<p>Version 3</p>' ) );
$revs = t_run( 'att/list-revisions', array( 'id' => $page_id ) );
t_ok( 'list-revisions', is_array( $revs ) && count( $revs['revisions'] ) >= 2, $revs );
$older = is_array( $revs ) ? end( $revs['revisions'] ) : null;
if ( $older ) {
	$r = t_run( 'att/restore-revision', array( 'revision_id' => $older['revision_id'] ) );
	t_ok( 'restore-revision', ! is_wp_error( $r ) && false !== strpos( get_post_field( 'post_content', $page_id ), 'C:\temp\new' ), $r );
}

/* ------------------------------------------------------------------ */
t_section( 'Taxonomy' );
$c = t_run( 'att/create-category', array( 'name' => 'Test Cat', 'description' => 'desc' ) );
$cat_id = is_array( $c ) ? (int) $c['id'] : 0;
t_ok( 'create-category', $cat_id > 0, $c );
$r = t_run( 'att/update-term', array( 'id' => $cat_id, 'name' => 'Renamed Cat', 'slug' => 'renamed-cat' ) );
t_ok( 'update-term', is_array( $r ) && 'Renamed Cat' === $r['term']['name'], $r );
$r = t_run( 'att/delete-term', array( 'id' => (int) get_option( 'default_category' ) ) );
t_ok( 'default category cannot be deleted', 'att_mcp_forbidden' === t_code( $r ), $r );
$r = t_run( 'att/delete-term', array( 'id' => $cat_id ) );
t_ok( 'delete-term', is_array( $r ) && ! get_term( $cat_id ), $r );

/* ------------------------------------------------------------------ */
t_section( 'Site settings + undo' );
$before = get_option( 'blogname' );
$r = t_run( 'att/update-site-settings', array( 'title' => 'Cloned Site', 'tagline' => 'New tagline', 'show_on_front' => 'page', 'page_on_front' => $page_id, 'permalink_structure' => '/%postname%/' ) );
t_ok( 'update-site-settings', ! is_wp_error( $r ) && 'Cloned Site' === get_option( 'blogname' ) && $page_id === (int) get_option( 'page_on_front' ) && '/%postname%/' === get_option( 'permalink_structure' ), $r );
$r = t_run( 'att/update-site-settings', array( 'page_on_front' => 999999 ) );
t_ok( 'invalid homepage id rejected', 'att_mcp_bad_page' === t_code( $r ), $r );
$r = t_run( 'att/update-site-settings', array( 'timezone' => 'Mars/Olympus' ) );
t_ok( 'invalid timezone rejected', 'att_mcp_bad_timezone' === t_code( $r ), $r );
$u = t_run( 'att/undo-change' );
t_ok( 'undo site settings', ! is_wp_error( $u ) && $before === get_option( 'blogname' ) && 'posts' === get_option( 'show_on_front' ), $u );
t_ok( '... incl. the permalink structure WordPress routes with', '' === get_option( 'permalink_structure' ) && '' === $GLOBALS['wp_rewrite']->permalink_structure, $GLOBALS['wp_rewrite']->permalink_structure );
$redo = is_array( $u ) ? (int) $u['redo_change_id'] : 0;
$u2 = t_run( 'att/undo-change', array( 'id' => $redo ) );
t_ok( 'undo the undo (redo)', ! is_wp_error( $u2 ) && 'Cloned Site' === get_option( 'blogname' ), $u2 );
$u3 = t_run( 'att/undo-change', array( 'id' => $redo ) );
t_ok( 'same change cannot be undone twice', 'att_mcp_already_undone' === t_code( $u3 ), $u3 );

/* ------------------------------------------------------------------ */
t_section( 'Options / theme mods / CSS (protection + undo)' );
foreach ( array( 'att_mcp_controls', 'att_mcp_abilities', 'admin_email', 'siteurl', 'wp_user_roles', '_transient_x', '_site_transient_update_plugins', 'active_plugins', 'my_plugin_api_key', 'smtp_password', 'auto_update_plugins', 'auto_update_themes' ) as $name ) {
	$r = t_run( 'att/update-option', array( 'name' => $name, 'value' => 'x' ) );
	t_ok( "update-option refuses {$name}", 'att_mcp_protected' === t_code( $r ), $r );
}
$r = t_run( 'att/get-option', array( 'name' => 'auth_key' ) );
t_ok( 'get-option refuses auth_key', 'att_mcp_protected' === t_code( $r ), $r );
$r = t_run( 'att/update-option', array( 'name' => 'att_test_setting', 'value' => array( 'a' => 1 ) ) );
t_ok( 'update-option normal option', ! is_wp_error( $r ) && array( 'a' => 1 ) === get_option( 'att_test_setting' ), $r );
t_run( 'att/undo-change' );
t_ok( 'undo removes option that did not exist', false === get_option( 'att_test_setting' ) );
t_run( 'att/set-theme-mod', array( 'key' => 'att_color', 'value' => '#0a7' ) );
t_ok( 'set-theme-mod', '#0a7' === get_theme_mod( 'att_color' ) );
t_run( 'att/undo-change' );
t_ok( 'undo theme mod', false === get_theme_mod( 'att_color' ) );
t_run( 'att/update-custom-css', array( 'css' => 'body{color:red}' ) );
t_run( 'att/update-custom-css', array( 'css' => 'a{color:blue}', 'append' => true ) );
t_ok( 'custom css append', false !== strpos( wp_get_custom_css(), 'a{color:blue}' ) && false !== strpos( wp_get_custom_css(), 'body{color:red}' ) );
t_run( 'att/undo-change' );
t_ok( 'undo custom css', 'body{color:red}' === trim( wp_get_custom_css() ), wp_get_custom_css() );

/* ------------------------------------------------------------------ */
t_section( 'Menus' );
$m = t_run( 'att/create-menu', array( 'name' => 'Main Nav', 'items' => array(
	array( 'title' => 'Home', 'url' => home_url( '/' ) ),
	array( 'title' => 'About', 'page_id' => $page_id, 'children' => array( array( 'title' => 'Team', 'url' => 'https://example.com/team', 'target' => '_blank' ) ) ),
) ) );
$menu_id = is_array( $m ) ? (int) $m['id'] : 0;
t_ok( 'create-menu with nested item', $menu_id && 3 === $m['items_added'], $m );
$items = wp_get_nav_menu_items( $menu_id );
$team  = null;
$home  = null;
foreach ( (array) $items as $it ) {
	if ( 'Team' === $it->title ) { $team = $it; }
	if ( 'Home' === $it->title ) { $home = $it; }
}
t_ok( 'child item has parent + target', $team && (int) $team->menu_item_parent > 0 && '_blank' === $team->target );
$r = t_run( 'att/update-menu', array( 'id' => $menu_id, 'new_name' => 'Primary', 'update_items' => array( array( 'id' => $home->ID, 'title' => 'Start' ) ), 'remove_item_ids' => array( $team->ID ), 'add_items' => array( array( 'title' => 'Blog', 'url' => '/blog/' ) ) ) );
$titles = is_array( $r ) ? wp_list_pluck( $r['menu']['items'], 'title' ) : array();
t_ok( 'update-menu rename/edit/remove/add', is_array( $r ) && 'Primary' === $r['menu']['name'] && in_array( 'Start', $titles, true ) && in_array( 'Blog', $titles, true ) && ! in_array( 'Team', $titles, true ), $r );
$start = null;
foreach ( $r['menu']['items'] as $it ) { if ( 'Start' === $it['title'] ) { $start = $it; } }
t_ok( 'edited item kept its URL (no field reset)', $start && home_url( '/' ) === $start['url'], $start );
$r = t_run( 'att/update-menu', array( 'id' => $menu_id, 'remove_item_ids' => array( $post_id ) ) );
t_ok( 'cannot delete a non-menu post via remove_item_ids', is_array( $r ) && ! empty( $r['errors'] ) && get_post( $post_id ), $r );
$r = t_run( 'att/delete-menu', array( 'id' => $menu_id ) );
t_ok( 'delete-menu', ! is_wp_error( $r ), $r );

/* ------------------------------------------------------------------ */
t_section( 'Media + SSRF guard' );
$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
$up  = t_run( 'att/upload-media', array( 'filename' => 'dot.png', 'content_base64' => $png, 'alt' => 'A dot', 'title' => 'Dot' ) );
$media_id = is_array( $up ) ? (int) $up['id'] : 0;
t_ok( 'upload-media base64', $media_id > 0, $up );
$r = t_run( 'att/update-media', array( 'id' => $media_id, 'alt' => 'Blue dot', 'caption' => 'Caption' ) );
t_ok( 'update-media', is_array( $r ) && 'Blue dot' === $r['alt'], $r );
$r = t_run( 'att/update-post', array( 'id' => $post_id, 'featured_media' => $media_id ) );
t_ok( 'featured image via update-post', $media_id === (int) get_post_thumbnail_id( $post_id ), $r );
$r = t_run( 'att/upload-media', array( 'filename' => 'x.php', 'content' => '<?php echo 1;' ) );
t_ok( '.php upload refused', 'att_mcp_bad_type' === t_code( $r ), $r );
foreach ( array( 'http://127.0.0.1:8899/wp-includes/images/w-logo-blue.png', 'http://localhost/a.png', 'http://169.254.169.254/latest/meta-data/', 'http://10.1.2.3/a.png', 'http://192.168.0.1/a.png', 'http://[::1]/a.png', 'http://0.0.0.0/a.png', 'http://2130706433/a.png' ) as $bad_url ) {
	$r = t_run( 'att/upload-media', array( 'filename' => 'a.png', 'url' => $bad_url ) );
	t_ok( "SSRF blocked: {$bad_url}", in_array( t_code( $r ), array( 'att_mcp_blocked_url', 'att_mcp_bad_url' ), true ), $r );
}
$r = t_run( 'att/fetch-url', array( 'url' => 'http://127.0.0.1:8899/' ) );
t_ok( 'fetch-url blocks private address', 'att_mcp_blocked_url' === t_code( $r ), $r );
$r = t_run( 'att/upload-media', array( 'filename' => 'a.png', 'url' => 'file:///C:/Windows/win.ini' ) );
t_ok( 'file:// refused', 'att_mcp_bad_url' === t_code( $r ), $r );
if ( $net ) {
	$r = t_run( 'att/fetch-url', array( 'url' => 'https://example.com/', 'format' => 'text' ) );
	t_ok( 'fetch-url public page (network)', is_array( $r ) && 200 === $r['status'] && ! empty( $r['title'] ), $r );
}

/* ------------------------------------------------------------------ */
t_section( 'Render page (needs php -S on 127.0.0.1:8899)' );
$r = t_run( 'att/render-page', array( 'id' => $page_id, 'strip_scripts' => true ) );
t_ok( 'render published page', is_array( $r ) && 200 === $r['status'] && false !== strpos( $r['html'], 'Test Page' ), is_array( $r ) ? $r['status'] : $r );
$r = t_run( 'att/render-page', array( 'id' => $draft_id, 'strip_scripts' => true ) );
t_ok( 'render DRAFT as preview', is_array( $r ) && ! empty( $r['preview'] ) && 200 === $r['status'] && false !== strpos( $r['html'], 'draft body' ), is_array( $r ) ? array( $r['status'], substr( $r['html'], 0, 200 ) ) : $r );
$sessions = WP_Session_Tokens::get_instance( 1 )->get_all();
t_ok( 'preview login session destroyed afterwards', 0 === count( $sessions ), count( $sessions ) );
$r = t_run( 'att/render-page', array( 'url' => 'https://example.com/' ) );
t_ok( 'render-page refuses other sites', 'att_mcp_offsite' === t_code( $r ), $r );

/* ------------------------------------------------------------------ */
t_section( 'Page analysis helpers' );
$scan = att_mcp_scan_html( '<html lang="en"><head><title>T &amp; X</title><meta name="description" content="D"><link rel="stylesheet" href="/a.css"><script src="https://cdn.example.com/x.js"></script><script src="/y.js" defer></script><script type="application/ld+json">{}</script></head><body><h1>Main</h1><p>First <a href="/about/">About us</a> para.</p><h3>Skip</h3><img src="/wp-content/uploads/a.jpg" alt=""><img src="b.png" loading="lazy" width="10" height="10"><a href="https://other.org/" rel="nofollow"><img src="c.png" alt="Logo"></a><!-- Page cached by LiteSpeed Cache --></body></html>' );
t_ok( 'scanner: title, meta, lang, JSON-LD', 'T & X' === $scan['title'] && 'D' === $scan['meta']['description'] && 'en' === $scan['lang'] && 1 === $scan['jsonld'], $scan );
t_ok( 'scanner: render-blocking vs deferred scripts', 2 === count( $scan['scripts'] ) && $scan['scripts'][0]['blocking'] && 'cdn.example.com' === $scan['scripts'][0]['host'] && ! $scan['scripts'][1]['blocking'] && $scan['stylesheets'][0]['blocking'], $scan['scripts'] );
t_ok( 'scanner: headings, links, image alts', array( 1, 3 ) === wp_list_pluck( $scan['headings'], 'level' ) && 'About us' === $scan['links'][0]['text'] && $scan['links'][0]['internal'] && '[image: Logo]' === $scan['links'][1]['text'] && $scan['links'][1]['nofollow'] && '' === $scan['images'][0]['alt'] && null === $scan['images'][1]['alt'], $scan['links'] );
t_ok( 'scanner: paragraphs + cache-plugin marker', 'First About us para.' === $scan['paragraphs'][0] && in_array( 'html:litespeed-cache', att_mcp_page_cache_signals( array(), $scan['comments'] )['signals'], true ), $scan['paragraphs'] );
$sig = att_mcp_page_cache_signals( array( 'x-wp-spc-disk-cache' => 'HIT', 'cache-control' => 'max-age=60' ), array() );
t_ok( 'cache signals: hit header', true === $sig['hit'] && in_array( 'cache-control', $sig['signals'], true ), $sig );
$stats = att_mcp_text_stats( str_repeat( 'The cat sat on the mat. ', 10 ), 'en_US' );
t_ok( 'text stats + Flesch reading ease', 60 === $stats['words'] && 10 === $stats['sentences'] && $stats['flesch_reading_ease'] > 90, $stats );
$file = att_mcp_local_file_for_url( wp_get_attachment_url( $media_id ) );
t_ok( 'local file for an upload URL', '' !== $file && is_file( $file ), $file );
t_ok( 'no local file through ../', '' === att_mcp_local_file_for_url( content_url( '/uploads/../../wp-config.php' ) ) );

/* ------------------------------------------------------------------ */
t_section( 'Performance (needs php -S on 127.0.0.1:8899)' );
$r = t_run( 'att/audit-performance', array( 'id' => $post_id ) );
t_ok( 'audit-performance', is_array( $r ) && isset( $r['server']['php_version'], $r['database']['autoload']['kb'], $r['page']['response_ms']['first'], $r['cron'] ) && 200 === $r['page']['status'], $r );
t_ok( '... recommends a page cache on a bare site', is_array( $r ) && in_array( 'caching', wp_list_pluck( $r['recommendations'], 'area' ), true ), is_array( $r ) ? $r['recommendations'] : $r );
t_ok( '... high priorities first', is_array( $r ) && 'high' === $r['recommendations'][0]['priority'] );
$r = t_run( 'att/audit-performance', array( 'fetch' => false ) );
t_ok( 'audit-performance without a page fetch', is_array( $r ) && ! isset( $r['page'] ) && isset( $r['database'] ), $r );
$r = t_run( 'att/audit-performance', array( 'id' => $draft_id ) );
t_ok( 'audit refuses unpublished pages', 'att_mcp_not_public' === t_code( $r ), $r );
$r = t_run( 'att/audit-performance', array( 'url' => 'https://example.com/' ) );
t_ok( 'audit refuses other sites', 'att_mcp_offsite' === t_code( $r ), $r );

// PageSpeed Insights, with Google's response mocked.
$psi = array(
	'loadingExperience' => array( 'overall_category' => 'SLOW', 'metrics' => array(
		'LARGEST_CONTENTFUL_PAINT_MS'   => array( 'percentile' => 4100, 'category' => 'SLOW' ),
		'CUMULATIVE_LAYOUT_SHIFT_SCORE' => array( 'percentile' => 12, 'category' => 'AVERAGE' ),
	) ),
	'lighthouseResult'  => array(
		'lighthouseVersion' => '12.0.0',
		'categories'        => array(
			'performance' => array( 'score' => 0.42, 'auditRefs' => array( array( 'id' => 'largest-contentful-paint', 'group' => 'metrics' ), array( 'id' => 'render-blocking-resources' ), array( 'id' => 'uses-optimized-images' ), array( 'id' => 'dom-size' ) ) ),
			'seo'         => array( 'score' => 0.9, 'auditRefs' => array( array( 'id' => 'meta-description' ) ) ),
		),
		'audits'            => array(
			'largest-contentful-paint'         => array( 'displayValue' => '4.1 s', 'score' => 0.2 ),
			'render-blocking-resources'        => array( 'title' => 'Eliminate render-blocking resources', 'score' => 0.3, 'scoreDisplayMode' => 'metricSavings', 'details' => array( 'overallSavingsMs' => 1200, 'items' => array( array( 'url' => 'https://x.test/y.css', 'wastedMs' => 800.4 ) ) ) ),
			'uses-optimized-images'            => array( 'title' => 'Efficiently encode images', 'score' => 0.5, 'scoreDisplayMode' => 'metricSavings', 'details' => array( 'overallSavingsMs' => 300, 'overallSavingsBytes' => 204800 ) ),
			'dom-size'                         => array( 'title' => 'Avoid an excessive DOM size', 'score' => 0.95, 'scoreDisplayMode' => 'numeric' ),
			'meta-description'                 => array( 'title' => 'Document does not have a meta description', 'score' => 0, 'scoreDisplayMode' => 'binary' ),
			'largest-contentful-paint-element' => array( 'details' => array( 'items' => array( array( 'items' => array( array( 'node' => array( 'snippet' => '<img src="hero.jpg">', 'selector' => 'main > img' ) ) ) ) ) ) ),
		),
	),
);
$psi_url  = '';
$psi_mock = function ( $pre, $args, $url ) use ( $psi, &$psi_url ) {
	if ( false === strpos( $url, 'pagespeedonline' ) ) {
		return $pre;
	}
	$psi_url = $url;
	return array( 'headers' => array(), 'body' => wp_json_encode( $psi ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
};
add_filter( 'pre_http_request', $psi_mock, 10, 3 );
$r = t_run( 'att/pagespeed-insights', array( 'id' => $post_id, 'strategy' => 'desktop' ) );
remove_filter( 'pre_http_request', $psi_mock, 10 );
t_ok( 'pagespeed-insights: scores', is_array( $r ) && 42 === $r['scores']['performance'] && 90 === $r['scores']['seo'], $r );
t_ok( '... field data (CLS scaled to 0.12)', is_array( $r ) && 'SLOW' === $r['field_data']['overall'] && 0.12 === $r['field_data']['cls']['p75'], is_array( $r ) ? $r['field_data'] : $r );
t_ok( '... opportunities sorted by savings, passing audits left out', is_array( $r ) && 2 === count( $r['opportunities'] ) && 'render-blocking-resources' === $r['opportunities'][0]['id'] && 200 === $r['opportunities'][1]['savings_kb'], is_array( $r ) ? $r['opportunities'] : $r );
t_ok( '... failing SEO audit and LCP element', is_array( $r ) && 'meta-description' === $r['failing_audits']['seo'][0]['id'] && 'main > img' === $r['lcp_element']['selector'], $r );
t_ok( '... request carries the page URL, strategy and categories', false !== strpos( $psi_url, rawurlencode( get_permalink( $post_id ) ) ) && false !== strpos( $psi_url, 'strategy=desktop' ) && false !== strpos( $psi_url, 'category=PERFORMANCE&category=SEO' ), $psi_url );
$r = t_run( 'att/pagespeed-insights', array( 'url' => 'https://example.com/' ) );
t_ok( 'pagespeed-insights refuses other sites', 'att_mcp_offsite' === t_code( $r ), $r );

// Database clean-up: revisions, spam, an expired transient, orphaned meta.
for ( $i = 1; $i <= 6; $i++ ) {
	wp_update_post( array( 'ID' => $post_id, 'post_content' => "<p>Body v{$i}</p>" ) );
}
$spam = wp_insert_comment( array( 'comment_post_ID' => $post_id, 'comment_content' => 'Buy now', 'comment_approved' => 'spam' ) );
set_transient( 'att_e2e_expired', 'x', 60 );
update_option( '_transient_timeout_att_e2e_expired', time() - 100 );
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => 987654, 'meta_key' => 'att_orphan', 'meta_value' => 'x' ) );
$revs_before = count( wp_get_post_revisions( $post_id ) );
$dry = t_run( 'att/optimize-database', array( 'keep_revisions' => 2 ) );
t_ok( 'optimize-database dry run counts', is_array( $dry ) && $dry['dry_run'] && $dry['tasks']['revisions']['found'] >= 4 && $dry['tasks']['spam_comments']['found'] >= 1 && $dry['tasks']['expired_transients']['found'] >= 1 && $dry['tasks']['orphaned_meta']['found'] >= 1, $dry );
t_ok( '... and deletes nothing', get_comment( $spam ) && count( wp_get_post_revisions( $post_id ) ) === $revs_before && false !== get_option( '_transient_timeout_att_e2e_expired' ) );
$run = t_run( 'att/optimize-database', array( 'keep_revisions' => 2, 'dry_run' => false ) );
t_ok( 'optimize-database deletes', is_array( $run ) && ! get_comment( $spam ) && 2 === count( wp_get_post_revisions( $post_id ) ) && false === get_option( '_transient_timeout_att_e2e_expired' ) && 0 === $run['tasks']['orphaned_meta']['remaining'], $run );
t_ok( '... published content untouched', 'publish' === get_post_status( $post_id ) && 'publish' === get_post_status( $page_id ) );

// Autoload.
add_option( 'att_e2e_big_option', str_repeat( 'x', 5000 ), '', true );
$r = t_run( 'att/set-option-autoload', array( 'options' => array( 'att_e2e_big_option', 'siteurl', 'att_e2e_no_such_option' ), 'autoload' => false ) );
t_ok( 'set-option-autoload off', is_array( $r ) && 'updated' === $r['results']['att_e2e_big_option'] && 0 === strpos( $r['results']['siteurl'], 'refused' ) && 0 === strpos( $r['results']['att_e2e_no_such_option'], 'skipped' ) && ! array_key_exists( 'att_e2e_big_option', wp_load_alloptions( true ) ), $r );
t_ok( '... value kept', str_repeat( 'x', 5000 ) === get_option( 'att_e2e_big_option' ) );
$u = t_run( 'att/undo-change' );
t_ok( 'undo autoload change', ! is_wp_error( $u ) && array_key_exists( 'att_e2e_big_option', wp_load_alloptions( true ) ), $u );

// Thumbnails.
$img = imagecreatetruecolor( 1600, 1000 );
imagefill( $img, 0, 0, imagecolorallocate( $img, 30, 120, 200 ) );
ob_start();
imagejpeg( $img, null, 80 );
$jpg = ob_get_clean();
imagedestroy( $img );
$up     = t_run( 'att/upload-media', array( 'filename' => 'big-photo.jpg', 'content_base64' => base64_encode( $jpg ), 'alt' => 'Big photo' ) );
$big_id = is_array( $up ) ? (int) $up['id'] : 0;
$meta   = wp_get_attachment_metadata( $big_id );
$size   = is_array( $meta ) && ! empty( $meta['sizes'] ) ? key( $meta['sizes'] ) : '';
if ( $size ) {
	unset( $meta['sizes'][ $size ] );
	wp_update_attachment_metadata( $big_id, $meta );
}
$r = t_run( 'att/regenerate-thumbnails', array( 'ids' => array( $big_id ) ) );
t_ok( "regenerate-thumbnails recreates the missing '{$size}' size", '' !== $size && is_array( $r ) && in_array( $size, $r['results'][0]['created'], true ) && isset( wp_get_attachment_metadata( $big_id )['sizes'][ $size ] ), $r );
$r = t_run( 'att/regenerate-thumbnails' );
t_ok( '... auto-scan finds nothing left to do', is_array( $r ) && 0 === $r['processed'], $r );

// Cache configuration with no cache plugin installed.
$r = t_run( 'att/get-cache-config' );
t_ok( 'get-cache-config with no cache plugin', is_array( $r ) && array() === $r['plugins'] && ! empty( $r['note'] ) && ! isset( $r['litespeed'] ), $r );
$r = t_run( 'att/update-cache-config', array( 'plugin' => 'litespeed', 'settings' => array( 'cache' => true ) ) );
t_ok( 'update-cache-config refuses an inactive plugin', 'att_mcp_plugin_inactive' === t_code( $r ), $r );

/* ------------------------------------------------------------------ */
t_section( 'SEO (no SEO plugin installed)' );
$para = 'Choosing fresh coffee beans is the single biggest step toward better espresso at home. Roast date, origin and grind size all change the taste in the cup, and small changes make a big difference to every shot you pull. ';
$body = '<!-- wp:paragraph --><p>' . $para . '</p><!-- /wp:paragraph -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">How to store coffee beans</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>' . str_repeat( 'Keep the bag sealed, away from light, heat and moisture, and buy only what you will use within a few weeks. ', 8 ) . '</p><!-- /wp:paragraph -->'
	. '<!-- wp:heading --><h2 class="wp-block-heading">Grinding for espresso</h2><!-- /wp:heading -->'
	. '<!-- wp:paragraph --><p>' . str_repeat( 'Grind right before brewing and adjust in small steps until the shot runs in about thirty seconds. ', 8 ) . ' Read our <a href="' . esc_url( get_permalink( $post_id ) ) . '">launch notes</a> or the <a href="https://en.wikipedia.org/wiki/Espresso">espresso history</a>.</p><!-- /wp:paragraph -->'
	. '<!-- wp:image --><figure class="wp-block-image"><img src="' . esc_url( wp_get_attachment_url( $big_id ) ) . '"/></figure><!-- /wp:image -->';
$seo_id = wp_insert_post( array( 'post_title' => 'Best Coffee Beans for Espresso', 'post_name' => 'best-coffee-beans', 'post_status' => 'publish', 'post_content' => $body ) );
$r      = t_run( 'att/analyze-post', array( 'id' => $seo_id, 'focus_keyword' => 'coffee beans' ) );
$status = is_array( $r ) ? wp_list_pluck( $r['checks'], 'status', 'id' ) : array();
t_ok( 'analyze-post on the rendered page', is_array( $r ) && isset( $r['score'] ) && 200 === $r['rendered']['status'] && false !== strpos( $r['rendered']['title'], 'Best Coffee Beans' ), $r );
t_ok( '... keyword in title, slug, intro and a subheading', 'pass' === ( $status['keyword_in_title'] ?? '' ) && 'pass' === ( $status['keyword_in_slug'] ?? '' ) && 'pass' === ( $status['keyword_in_intro'] ?? '' ) && 'pass' === ( $status['keyword_in_subheadings'] ?? '' ), $status );
t_ok( '... no meta description without an SEO plugin', 'fail' === ( $status['description'] ?? '' ), $status );
t_ok( '... image without alt found', 'fail' === ( $status['image_alt'] ?? '' ) && 1 === count( $r['images_without_alt'] ), $status );
t_ok( '... internal and external links counted', is_array( $r ) && 1 === $r['stats']['internal_links'] && 1 === $r['stats']['external_links'], is_array( $r ) ? $r['stats'] : $r );
t_ok( '... link suggestions never suggest the post itself', is_array( $r ) && ! in_array( $seo_id, wp_list_pluck( isset( $r['link_suggestions'] ) ? $r['link_suggestions'] : array(), 'id' ), true ) );
$r = t_run( 'att/analyze-post', array( 'id' => $seo_id, 'render' => false ) );
t_ok( 'analyze-post without rendering', is_array( $r ) && ! isset( $r['rendered'] ) && isset( $r['score'] ), $r );
$r = t_run( 'att/seo-audit' );
$row = null;
foreach ( ( is_array( $r ) ? $r['posts'] : array() ) as $p ) {
	if ( $seo_id === $p['id'] ) { $row = $p; }
}
t_ok( 'seo-audit', is_array( $r ) && $row && in_array( 'missing_description', $row['issues'], true ) && in_array( 'images_missing_alt', $row['issues'], true ) && isset( $r['summary']['missing_description'] ), $r );
t_ok( '... site check: no SEO plugin', is_array( $r ) && false !== strpos( wp_json_encode( $r['site'] ), 'No SEO plugin' ), is_array( $r ) ? $r['site'] : $r );
$r = t_run( 'att/update-seo-meta', array( 'id' => $seo_id, 'title' => 'x' ) );
t_ok( 'update-seo-meta needs an SEO plugin', 'att_mcp_no_seo_plugin' === t_code( $r ), $r );
$r = t_run( 'att/analyze-post', array( 'id' => $media_id ) );
t_ok( 'analyze-post: attachment is analysed or refused cleanly', ! is_wp_error( $r ) || in_array( t_code( $r ), array( 'att_mcp_not_viewable' ), true ), $r );

/* ------------------------------------------------------------------ */
t_section( 'Secrets are never written back as placeholders' );
update_option( 'att_e2e_cfg', array( 'cf_apitoken' => 'real-token', 'color' => 'red', 'nested' => array( 'client_secret' => 's3' ) ) );
$g = t_run( 'att/get-option', array( 'name' => 'att_e2e_cfg' ) );
t_ok( 'get-option masks cf_apitoken and nested secrets', is_array( $g ) && '***redacted***' === $g['value']['cf_apitoken'] && '***redacted***' === $g['value']['nested']['client_secret'] && 'red' === $g['value']['color'], $g );
$v          = is_array( $g ) ? $g['value'] : array();
$v['color'] = 'blue';
$r          = t_run( 'att/update-option', array( 'name' => 'att_e2e_cfg', 'value' => $v ) );
$now        = get_option( 'att_e2e_cfg' );
t_ok( 'writing a redacted structure back keeps the real secrets', ! is_wp_error( $r ) && 'real-token' === $now['cf_apitoken'] && 's3' === $now['nested']['client_secret'] && 'blue' === $now['color'], $now );
$r = t_run( 'att/update-option', array( 'name' => 'att_e2e_cfg', 'value' => array( 'new_api_key' => '***redacted***' ) ) );
t_ok( 'a placeholder with nothing stored behind it is refused', 'att_mcp_redacted_value' === t_code( $r ), $r );
$r = t_run( 'att/create-post', array( 'title' => 'x', 'content' => 'token: ***redacted***' ) );
t_ok( 'other write abilities refuse the placeholder', 'att_mcp_redacted_value' === t_code( $r ), $r );
foreach ( array( 'litespeed.conf.object-pswd', 'litespeed.conf.cdn-cloudflare_key', 'litespeed.cloud._summary.sk_b64', 'swcfpc_cf_apitoken', 'my_credentials' ) as $name ) {
	$r = t_run( 'att/get-option', array( 'name' => $name ) );
	t_ok( "get-option refuses {$name}", 'att_mcp_protected' === t_code( $r ), $r );
}
$red = att_mcp_redact_deep( array( 'sk_b64' => 'k', 'pk_b64' => 'public', 'cf_apitoken' => 't', 'cf_email' => 'e@x.test' ) );
t_ok( 'redaction covers QUIC.cloud and Cloudflare keys', '***redacted***' === $red['sk_b64'] && '***redacted***' === $red['cf_apitoken'] && 'public' === $red['pk_b64'], $red );
$red = att_mcp_redact_deep( array( 'api_key_set' => true, 'smtp_port' => 587, 'password' => 'x', 'token' => '' ) );
t_ok( 'redaction keeps booleans, numbers and empty strings (strict output schemas stay valid)', true === $red['api_key_set'] && 587 === $red['smtp_port'] && '***redacted***' === $red['password'] && '' === $red['token'], $red );
t_ok( "other plugins' error results are logged as errors", 'error' === att_mcp_audit_describe( array( 'error' => array( 'code' => 'no_fields', 'message' => 'Nothing to do' ) ) )[0] && 'ok' === att_mcp_audit_describe( array( 'fixed' => false, 'summary' => 'x' ) )[0] );

/* ------------------------------------------------------------------ */
t_section( "Other plugins' MCP tools follow the MCP Controls (simulated tools)" );
t_ok( 'no Rank Math entries while Rank Math is inactive', ! array_filter( array_keys( att_mcp_ability_registry() ), function ( $k ) { return 0 === strpos( $k, 'rank-math/' ) || 0 === strpos( $k, 'att/rank-math-' ); } ) && ! att_mcp_addon_is_available( 'rank_math' ) );
$gov_calls = 0;
$gov_args  = array(
	'label'               => 'E2E governed tool',
	'description'         => 'Writes an option and post meta.',
	'category'            => 'att',
	'input_schema'        => array( 'type' => 'object', 'properties' => array( 'v' => array( 'type' => 'string' ) ) ),
	'permission_callback' => '__return_true',
	'meta'                => array( 'annotations' => array( 'readonly' => false ), 'mcp' => array( 'public' => true ), 'show_in_rest' => true ),
	'execute_callback'    => function ( $input = array() ) use ( &$gov_calls, $page_id ) {
		$gov_calls++;
		update_option( 'att_e2e_gov_opt', isset( $input['v'] ) ? $input['v'] : 'x' );
		update_post_meta( $page_id, 'att_e2e_gov_meta', 'new' );
		set_transient( 'att_e2e_gov_transient', 'noise', 60 );
		return array( 'saved' => true, 'secret_token' => 'abc' );
	},
);
$last_audit = function () use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', att_mcp_audit_table() ) );
};
$g = att_mcp_govern_ability_args( $gov_args, 'rank-math/att-e2e-fake' );
t_ok( 'while its addon is off, a governed tool stays exposed as its plugin ships it', true === $g['meta']['mcp']['public'] && true === $g['meta']['show_in_rest'] && array() === $g['input_schema']['default'], $g['meta'] );
$before = $last_audit();
$res    = call_user_func( $g['execute_callback'], array( 'v' => 'own-use' ) );
t_ok( "the plugin's own (non-MCP) use of its tool is left alone", 1 === $gov_calls && 'abc' === $res['secret_token'] && $before == $last_audit(), $res ); // phpcs:ignore Universal.Operators.StrictComparisons -- same row object
update_option( 'att_e2e_gov_opt', 'before' );
delete_post_meta( $page_id, 'att_e2e_gov_meta' );
att_mcp_mcp_context( 'enter' );
$res = call_user_func( $g['execute_callback'], array( 'v' => 'after' ) );
att_mcp_mcp_context( 'leave' );
t_ok( 'an MCP call runs through the dispatcher (output redacted)', 2 === $gov_calls && is_array( $res ) && '***redacted***' === $res['secret_token'] && 'after' === get_option( 'att_e2e_gov_opt' ), $res );
$last = $last_audit();
t_ok( '... is logged as a write', $last && 'rank-math/att-e2e-fake' === $last->ability && 'write' === $last->access && 'ok' === $last->status, $last );
$list    = t_run( 'att/list-changes', array( 'limit' => 1 ) );
$c       = is_array( $list ) ? $list['changes'][0] : array( 'ability' => '', 'label' => '', 'items' => array() );
$targets = wp_json_encode( wp_list_pluck( $c['items'], 'target' ) );
t_ok( '... and its option + post meta changes are recorded (transients are not)', 'rank-math/att-e2e-fake' === $c['ability'] && false !== strpos( $c['label'], '(Rank Math)' ) && 2 === count( $c['items'] ) && false !== strpos( $targets, 'att_e2e_gov_opt' ) && false !== strpos( $targets, 'att_e2e_gov_meta' ), $c );
$u = t_run( 'att/undo-change' );
t_ok( 'undo restores what the governed tool changed', ! is_wp_error( $u ) && 'before' === get_option( 'att_e2e_gov_opt' ) && ! metadata_exists( 'post', $page_id, 'att_e2e_gov_meta' ), $u );
$seen = (array) get_option( 'att_mcp_seen_abilities', array() );
t_ok( 'a tool this plugin does not describe is remembered for MCP > Settings', isset( $seen['rank-math/att-e2e-fake'] ) && false === $seen['rank-math/att-e2e-fake']['readonly'] && 'rank_math' === $seen['rank-math/att-e2e-fake']['addon'], $seen );

set_controls( array( 'writes_paused' => true ) );
$g = att_mcp_govern_ability_args( $gov_args, 'rank-math/att-e2e-fake' );
att_mcp_mcp_context( 'enter' );
$r = call_user_func( $g['execute_callback'], array() );
att_mcp_mcp_context( 'leave' );
t_ok( 'read-only mode hides a governed write tool from MCP and refuses it there', false === $g['meta']['mcp']['public'] && true === $g['meta']['show_in_rest'] && 'att_mcp_writes_paused' === t_code( $r ) && 2 === $gov_calls, $r );
call_user_func( $g['execute_callback'], array( 'v' => 'own-use' ) );
t_ok( "... while the plugin's own use keeps working", 3 === $gov_calls );
set_controls( array( 'writes_paused' => false, 'active' => false ) );
$g = att_mcp_govern_ability_args( $gov_args, 'rank-math/att-e2e-fake' );
att_mcp_mcp_context( 'enter' );
$r = call_user_func( $g['execute_callback'], array() );
att_mcp_mcp_context( 'leave' );
t_ok( 'the kill switch hides and stops governed tools over MCP', false === $g['meta']['mcp']['public'] && 'att_mcp_disabled' === t_code( $r ) && 3 === $gov_calls, $r );
set_controls( array( 'active' => true ) );

// Any other plugin's (or core's) MCP-exposed tool falls under "Other MCP tools".
t_ok( "any other plugin's MCP-exposed tool is governed", 'other_mcp' === att_mcp_governing_addon( 'e2e-other/tool', $gov_args ) && 'other_mcp' === att_mcp_governing_addon( 'core/get-site-info', array( 'meta' => array( 'public' => true ) ) ) );
t_ok( "... but not tools MCP does not expose, nor this plugin's or MCP Adapter's", '' === att_mcp_governing_addon( 'e2e-other/private', array( 'meta' => array( 'show_in_rest' => true ) ) ) && '' === att_mcp_governing_addon( 'core/x', array( 'meta' => array( 'public' => true, 'mcp' => array( 'public' => false ) ) ) ) && '' === att_mcp_governing_addon( 'att/get-posts', $gov_args ) && '' === att_mcp_governing_addon( 'mcp-adapter/execute-ability', $gov_args ) );
$g    = att_mcp_govern_ability_args( $gov_args, 'e2e-other/tool' );
$seen = (array) get_option( 'att_mcp_seen_abilities', array() );
t_ok( '... is remembered for the "Other MCP tools" card', isset( $seen['e2e-other/tool'] ) && 'other_mcp' === $seen['e2e-other/tool']['addon'] && att_mcp_addon_is_available( 'other_mcp' ) && isset( att_mcp_other_mcp_registry()['e2e-other/tool'] ), $seen );
set_controls( array( 'active' => false ) );
$g = att_mcp_govern_ability_args( $gov_args, 'e2e-other/tool' );
t_ok( '... and hidden from MCP by the kill switch', false === $g['meta']['mcp']['public'] );
set_controls( array( 'active' => true ) );
delete_option( 'att_mcp_seen_abilities' );

// Undo keeps every row of a meta key that has several.
add_post_meta( $page_id, 'att_e2e_multi', 'a' );
add_post_meta( $page_id, 'att_e2e_multi', 'b' );
$item = att_mcp_capture( 'post_meta', array( $page_id, 'att_e2e_multi' ) );
update_post_meta( $page_id, 'att_e2e_multi', 'z' );
att_mcp_restore_item( $item );
t_ok( 'undo restores every row of a multi-row meta key', array( 'a', 'b' ) === get_post_meta( $page_id, 'att_e2e_multi', false ), get_post_meta( $page_id, 'att_e2e_multi', false ) );
delete_post_meta( $page_id, 'att_e2e_multi' );

/* ------------------------------------------------------------------ */
t_section( 'Bulk SEO meta (no SEO plugin installed)' );
$r = t_run( 'att/bulk-update-seo-meta', array( 'items' => array( array( 'id' => $post_id, 'title' => 'x' ) ) ) );
t_ok( 'bulk-update-seo-meta needs an SEO plugin', 'att_mcp_no_seo_plugin' === t_code( $r ), $r );
$r = t_run( 'att/bulk-update-seo-meta', array( 'items' => array_fill( 0, 51, array( 'id' => $post_id, 'title' => 'x' ) ) ) );
t_ok( '... and takes at most 50 items', 'att_mcp_bad_input' === t_code( $r ), $r );

/* ------------------------------------------------------------------ */
t_section( 'Site Editor (block theme: ' . get_stylesheet() . ')' );
$r = t_run( 'att/get-block-templates' );
t_ok( 'get-block-templates', is_array( $r ) && ! empty( $r['templates']['wp_template'] ) && ! empty( $r['templates']['wp_template_part'] ), $r );
$footer = null;
foreach ( (array) ( is_array( $r ) ? $r['templates']['wp_template_part'] : array() ) as $p ) {
	if ( 'footer' === $p['slug'] ) { $footer = $p; }
}
if ( $footer ) {
	$g = t_run( 'att/get-block-template', array( 'id' => $footer['id'], 'type' => 'wp_template_part' ) );
	t_ok( 'get-block-template footer', is_array( $g ) && '' !== $g['content'], $g );
	$new = '<!-- wp:paragraph --><p>ATT footer</p><!-- /wp:paragraph -->';
	$s   = t_run( 'att/save-block-template', array( 'id' => $footer['id'], 'type' => 'wp_template_part', 'content' => $new ) );
	t_ok( 'save-block-template (customize theme part)', is_array( $s ) && $s['wp_id'] > 0, $s );
	$g2 = t_run( 'att/get-block-template', array( 'id' => $footer['id'], 'type' => 'wp_template_part' ) );
	t_ok( 'footer content saved', is_array( $g2 ) && $new === $g2['content'] && 'custom' === $g2['source'], $g2 );
	$reset = t_run( 'att/save-block-template', array( 'id' => $footer['id'], 'type' => 'wp_template_part', 'reset' => true ) );
	t_ok( 'reset template part to theme file', is_array( $reset ) && ! empty( $reset['reset'] ), $reset );
}
$c = t_run( 'att/save-block-template', array( 'slug' => 'att-landing', 'title' => 'ATT Landing', 'content' => '<!-- wp:post-content /-->' ) );
t_ok( 'create custom template', is_array( $c ) && 'att-landing' === $c['slug'], $c );
$gs = t_run( 'att/get-global-styles' );
t_ok( 'get-global-styles', is_array( $gs ) && $gs['id'] > 0, $gs );
$us = t_run( 'att/update-global-styles', array(
	'settings' => array( 'color' => array( 'palette' => array( 'custom' => array( array( 'slug' => 'att-brand', 'color' => '#00aa77', 'name' => 'Brand' ) ) ) ) ),
	'styles'   => array( 'color' => array( 'background' => '#ffffff' ) ),
) );
t_ok( 'update-global-styles', ! is_wp_error( $us ), $us );
$pal = wp_json_encode( wp_get_global_settings( array( 'color', 'palette' ) ) );
t_ok( 'palette contains new brand color', false !== strpos( $pal, 'att-brand' ), $pal );
$us2 = t_run( 'att/update-global-styles', array( 'styles' => array( 'typography' => array( 'fontSize' => '18px' ) ) ) );
$gs2 = t_run( 'att/get-global-styles' );
t_ok( 'merge keeps earlier global styles', is_array( $gs2 ) && isset( $gs2['user']['styles']['color']['background'], $gs2['user']['styles']['typography']['fontSize'] ), is_array( $gs2 ) ? $gs2['user'] : $gs2 );

/* ------------------------------------------------------------------ */
t_section( 'REST passthrough' );
$r = t_run( 'att/rest-get', array( 'route' => '/wp/v2/types' ) );
t_ok( 'rest-get /wp/v2/types', is_array( $r ) && 200 === $r['status'] && isset( $r['data']['post'] ), $r );
$r = t_run( 'att/rest-get', array( 'route' => '/wp/v2/pages', 'params' => array( 'per_page' => 2, '_fields' => 'id,title' ) ) );
t_ok( 'rest-get with params + headers', is_array( $r ) && isset( $r['headers']['X-WP-Total'] ), $r );
foreach ( array(
	array( 'GET', '/wp/v2/users/me/application-passwords' ),
	array( 'GET', '/wp-abilities/v1/abilities' ),
	array( 'GET', '/mcp/mcp-adapter-default-server' ),
	array( 'POST', '/batch/v1' ),
	array( 'POST', '/wp/v2/users' ),
	array( 'POST', '/WP/V2/Users/1' ),
	array( 'POST', '/wp/v2/plugins' ),
	array( 'POST', '/wp/v2/settings' ),
	array( 'POST', '//wp/v2//settings' ),
	array( 'POST', '/wp/v2%2Fsettings' ),
	array( 'POST', '/code-snippets/v1/snippets' ),
) as $case ) {
	$r = 'GET' === $case[0] ? t_run( 'att/rest-get', array( 'route' => $case[1] ) ) : t_run( 'att/rest-write', array( 'method' => $case[0], 'route' => $case[1], 'params' => array() ) );
	t_ok( "blocked {$case[0]} {$case[1]}", 'att_mcp_route_blocked' === t_code( $r ), $r );
}
$r = t_run( 'att/rest-get', array( 'route' => '/wp/v2/../wp/v2/users' ) );
t_ok( 'path traversal refused', 'att_mcp_bad_route' === t_code( $r ), $r );
$r = t_run( 'att/rest-write', array( 'method' => 'GET', 'route' => '/wp/v2/posts' ) );
t_ok( 'rest-write refuses GET', 'att_mcp_bad_method' === t_code( $r ), $r );
$r = t_run( 'att/rest-write', array( 'method' => 'POST', 'route' => '/wp/v2/blocks', 'params' => array( 'title' => 'Via REST', 'content' => '<p>x</p>', 'status' => 'publish' ) ) );
t_ok( 'rest-write creates a pattern', is_array( $r ) && 201 === $r['status'], $r );
if ( is_array( $r ) ) {
	$d = t_run( 'att/rest-write', array( 'method' => 'DELETE', 'route' => '/wp/v2/blocks/' . $r['data']['id'], 'params' => array( 'force' => true ) ) );
	t_ok( 'rest-write DELETE with query params', is_array( $d ) && 200 === $d['status'], $d );
}

/* ------------------------------------------------------------------ */
t_section( 'Plugins & themes' );
$r = t_run( 'att/manage-plugin', array( 'action' => 'deactivate', 'plugin' => 'att-mcp-abilities/att-mcp-abilities.php' ) );
t_ok( 'cannot deactivate itself', 'att_mcp_protected_plugin' === t_code( $r ), $r );
$r = t_run( 'att/manage-plugin', array( 'action' => 'activate', 'plugin' => 'hello.php' ) );
t_ok( 'activate hello.php', is_array( $r ) && 'active' === $r['status'], $r );
$r = t_run( 'att/manage-plugin', array( 'action' => 'deactivate', 'plugin' => 'hello' ) );
t_ok( 'deactivate (resolved without .php)', is_array( $r ) && 'inactive' === $r['status'], $r );
if ( $net ) {
	$r = t_run( 'att/manage-plugin', array( 'action' => 'install', 'slug' => 'hello-dolly' ) );
	t_ok( 'install plugin from WordPress.org (network)', is_array( $r ) && ! empty( $r['installed'] ), $r );
}
$themes = t_run( 'att/get-themes' );
$active = get_stylesheet();
$other  = null;
foreach ( (array) ( is_array( $themes ) ? $themes['themes'] : array() ) as $t ) {
	if ( ! $t['active'] ) { $other = $t['stylesheet']; break; }
}
if ( $other ) {
	$r = t_run( 'att/manage-theme', array( 'action' => 'activate', 'slug' => $other ) );
	t_ok( "switch theme to {$other}", is_array( $r ) && get_stylesheet() === $other, $r );
	t_run( 'att/manage-theme', array( 'action' => 'activate', 'slug' => $active ) );
	t_ok( 'switch back', get_stylesheet() === $active );
}

/* ------------------------------------------------------------------ */
t_section( 'PHP gate for code-storing post types' );
register_post_type( 'wpcode', array( 'show_ui' => true, 'label' => 'WPCode' ) );
$r = t_run( 'att/create-content', array( 'post_type' => 'wpcode', 'title' => 'x', 'content' => 'echo 1;' ) );
t_ok( 'wpcode post type blocked without Allow PHP', 'att_mcp_php_blocked' === t_code( $r ), $r );
set_controls( array( 'allow_php' => true ) );
$r = t_run( 'att/create-content', array( 'post_type' => 'wpcode', 'title' => 'x', 'content' => 'echo 1;' ) );
t_ok( '... allowed once the admin turns Allow PHP on', ! is_wp_error( $r ), $r );
set_controls( array( 'allow_php' => false ) );

/* ------------------------------------------------------------------ */
t_section( 'Per-object permissions (Author / Contributor)' );
$author  = wp_insert_user( array( 'user_login' => 'author1', 'user_pass' => wp_generate_password(), 'user_email' => 'a1@example.com', 'role' => 'author' ) );
$contrib = wp_insert_user( array( 'user_login' => 'contrib1', 'user_pass' => wp_generate_password(), 'user_email' => 'c1@example.com', 'role' => 'contributor' ) );
wp_set_current_user( $author );
$r = t_run( 'att/update-page', array( 'id' => $page_id, 'title' => 'hacked' ) );
t_ok( 'author cannot update pages', 'ability_invalid_permissions' === t_code( $r ), $r );
$r = t_run( 'att/update-post', array( 'id' => $post_id, 'title' => 'hacked' ) );
t_ok( "author cannot edit admin's post", 'att_mcp_forbidden' === t_code( $r ) && 'Hello MCP' === get_the_title( $post_id ), $r );
$r = t_run( 'att/update-content', array( 'id' => $page_id, 'title' => 'hacked' ) );
t_ok( "author cannot edit admin's page via update-content", 'att_mcp_forbidden' === t_code( $r ), $r );
$r = t_run( 'att/delete-post', array( 'id' => $post_id ) );
t_ok( "author cannot trash admin's post", 'att_mcp_forbidden' === t_code( $r ) && 'publish' === get_post_status( $post_id ), $r );
$r = t_run( 'att/get-content', array( 'id' => $draft_id ) );
t_ok( "author cannot read admin's draft", 'att_mcp_forbidden' === t_code( $r ), $r );
$r = t_run( 'att/create-post', array( 'title' => 'Author post', 'content' => '<p>a</p>', 'status' => 'publish' ) );
t_ok( 'author can publish own post', is_array( $r ) && 'publish' === $r['status'], $r );
$r = t_run( 'att/update-option', array( 'name' => 'blogname', 'value' => 'x' ) );
t_ok( 'author cannot update options', 'ability_invalid_permissions' === t_code( $r ), $r );
$r = t_run( 'att/get-site-info' );
t_ok( 'non-admin does not see admin_email', is_array( $r ) && ! isset( $r['admin_email'] ), $r );
wp_set_current_user( $contrib );
$r = t_run( 'att/create-post', array( 'title' => 'C post', 'content' => '<p>c</p>', 'status' => 'publish' ) );
t_ok( 'contributor cannot publish', 'att_mcp_cannot_publish' === t_code( $r ), $r );
$r = t_run( 'att/create-post', array( 'title' => 'C draft', 'content' => '<p>safe</p><script>alert(1)</script>' ) );
t_ok( 'contributor draft: script stripped', is_array( $r ) && false === strpos( get_post_field( 'post_content', $r['id'] ), '<script' ), $r );
wp_set_current_user( 1 );

/* ------------------------------------------------------------------ */
t_section( 'Controls: read-only, kill switch, rate limit' );
set_controls( array( 'writes_paused' => true ) );
$r = t_run( 'att/create-post', array( 'title' => 'x', 'content' => 'x' ) );
t_ok( 'read-only mode blocks writes', 'att_mcp_writes_paused' === t_code( $r ), $r );
t_ok( '... but reads still work', ! is_wp_error( t_run( 'att/get-posts' ) ) );
set_controls( array( 'writes_paused' => false, 'active' => false ) );
$r = t_run( 'att/get-posts' );
t_ok( 'kill switch blocks everything', 'att_mcp_disabled' === t_code( $r ), $r );
set_controls( array( 'active' => true, 'rate_limit' => 2 ) );
t_run( 'att/set-theme-mod', array( 'key' => 'rl1', 'value' => 1 ) );
t_run( 'att/set-theme-mod', array( 'key' => 'rl2', 'value' => 1 ) );
$r = t_run( 'att/set-theme-mod', array( 'key' => 'rl3', 'value' => 1 ) );
t_ok( 'rate limit stops the 3rd write in a minute', 'att_mcp_rate_limited' === t_code( $r ), $r );
t_ok( '... reads are not rate limited', ! is_wp_error( t_run( 'att/get-posts' ) ) );
set_controls( array( 'rate_limit' => 0 ) );

/* ------------------------------------------------------------------ */
t_section( 'Audit log, redaction, sanitizers, digest' );
global $wpdb;
$errors = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', att_mcp_audit_table(), 'error' ) );
$oks    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', att_mcp_audit_table(), 'ok' ) );
t_ok( "audit rows recorded ({$oks} ok / {$errors} error)", $errors > 0 && $oks > 0 );
$last = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ability = %s ORDER BY id DESC LIMIT 1', att_mcp_audit_table(), 'att/set-theme-mod' ) );
t_ok( 'rate-limited call logged as error', $last && 'error' === $last->status, $last );
$red = att_mcp_redact_deep( array( 'api_key' => 'x', 'nested' => array( 'password' => 'y', 'ok' => 'z' ), 'license_key' => 'k' ) );
t_ok( 'deep redaction', '***redacted***' === $red['api_key'] && '***redacted***' === $red['nested']['password'] && 'z' === $red['nested']['ok'] && '***redacted***' === $red['license_key'], $red );
update_option( 'att_mcp_abilities', array_merge( get_option( 'att_mcp_abilities' ), array( 'att/elementor-get-page' => true ) ) );
$clean = att_mcp_sanitize_settings( array() );
t_ok( 'toggles of inactive plugins survive a save', ! empty( $clean['att/elementor-get-page'] ) && empty( $clean['att/get-posts'] ) );
update_option( 'att_mcp_addons', array( 'elementor' => true, 'design' => true ) );
$clean = att_mcp_sanitize_addons( array() );
t_ok( 'addon enable state of inactive plugins survives', ! empty( $clean['elementor'] ) && empty( $clean['design'] ) && ! empty( $clean['core'] ) );
$ctl = att_mcp_sanitize_controls( array( 'rate_limit' => '5000', 'active' => '1' ) );
t_ok( 'controls sanitizer clamps', 1000 === $ctl['rate_limit'] && true === $ctl['active'] && false === $ctl['allow_php'] );
set_controls( array( 'notify' => true ) );
delete_option( 'att_mcp_digest_last' );
$mail = null;
add_filter( 'pre_wp_mail', function ( $pre, $atts ) use ( &$mail ) { $mail = $atts; return true; }, 10, 2 );
att_mcp_send_daily_digest();
t_ok( 'daily digest email built', is_array( $mail ) && false !== strpos( $mail['subject'], 'MCP activity' ), $mail ? $mail['subject'] : 'no mail' );
att_mcp_sync_digest_schedule();
t_ok( 'digest cron scheduled when enabled', (bool) wp_next_scheduled( 'att_mcp_daily_digest' ) );
set_controls( array( 'notify' => false ) );
att_mcp_sync_digest_schedule();
t_ok( 'digest cron removed when disabled', ! wp_next_scheduled( 'att_mcp_daily_digest' ) );

t_finish();
