<?php
// Renders every MCP admin screen as an administrator and checks the output.
$att_admin = true;
require __DIR__ . '/bootstrap.php';
wp_set_current_user( 1 );

function render_screen( $page, $callback ) {
	$_GET['page'] = $page;
	set_current_screen( 'toplevel_page_' . $page );
	ob_start();
	call_user_func( $callback );
	return ob_get_clean();
}

t_section( 'Admin screens' );
$settings = render_screen( 'att-mcp-abilities', 'att_mcp_settings_page' );
t_ok( 'settings page renders', false !== strpos( $settings, 'MCP Controls' ) && false !== strpos( $settings, 'att_mcp_controls[allow_php]' ) && false !== strpos( $settings, 'att_mcp_controls[rate_limit]' ) );
t_ok( 'settings page lists addon cards', substr_count( $settings, 'data-addon-card=' ) >= 5, substr_count( $settings, 'data-addon-card=' ) );
t_ok( 'settings page has nonce fields', false !== strpos( $settings, 'name="_wpnonce"' ) && false !== strpos( $settings, 'option_page' ) );

$config = render_screen( 'att-mcp-config', 'att_mcp_config_page' );
t_ok( 'connect page renders password generator', false !== strpos( $config, 'id="att-mcp-pw-generate"' ), substr( wp_strip_all_tags( $config ), 0, 300 ) );
t_ok( 'connect page shows 5 client configs', 5 === substr_count( $config, 'data-att-config="1"' ) );
t_ok( 'connect page does not warn about a missing endpoint', false === strpos( $config, 'att-endpoint-missing' ) );
t_ok( 'config contains REST URL + username', false !== strpos( $config, 'mcp-adapter-default-server' ) && false !== strpos( $config, '&quot;WP_API_USERNAME&quot;: &quot;admin&quot;' ) );

$activity = render_screen( 'att-mcp-activity', 'att_mcp_activity_page' );
t_ok( 'activity page renders with rows', false !== strpos( $activity, 'att/' ) && false !== strpos( $activity, 'att_mcp_clear_audit' ) );
t_ok( 'activity page shows undoable changes', false !== strpos( $activity, 'Recent undoable changes' ) );

foreach ( array( 'settings' => $settings, 'config' => $config, 'activity' => $activity ) as $name => $html ) {
	t_ok( "no inline <script> on {$name} screen", false === stripos( $html, '<script' ) );
}

t_section( 'Assets' );
$_GET['page'] = 'att-mcp-config';
att_mcp_enqueue_admin_assets( 'mcp_page_att-mcp-config' );
$scripts = wp_scripts();
t_ok( 'admin.js enqueued with wp-api-fetch', wp_script_is( 'att-mcp-admin', 'enqueued' ) && in_array( 'wp-api-fetch', $scripts->registered['att-mcp-admin']->deps, true ) );
t_ok( 'admin.js localized', false !== strpos( (string) $scripts->get_data( 'att-mcp-admin', 'data' ), 'attMcpAdmin' ) );
t_ok( 'admin.css enqueued', wp_style_is( 'att-mcp-admin', 'enqueued' ) );
$_GET['page'] = 'some-other-page';
wp_dequeue_script( 'att-mcp-admin' );
att_mcp_enqueue_admin_assets( 'index.php' );
t_ok( 'assets not loaded on other admin pages', ! wp_script_is( 'att-mcp-admin', 'enqueued' ) );

t_section( 'Plugins screen + notice' );
$links = att_mcp_plugin_action_links( array() );
t_ok( 'Settings action link', 1 === count( $links ) && false !== strpos( $links[0], 'page=att-mcp-abilities' ) );
set_current_screen( 'plugins' );
ob_start();
att_mcp_dependency_notice();
$notice = ob_get_clean();
t_ok( 'MCP Adapter notice on Plugins screen', false !== strpos( $notice, 'MCP Adapter' ) );
set_current_screen( 'dashboard' );
$_GET['page'] = '';
ob_start();
att_mcp_dependency_notice();
t_ok( 'no notice on the Dashboard', '' === ob_get_clean() );

t_finish();
