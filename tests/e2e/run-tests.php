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
