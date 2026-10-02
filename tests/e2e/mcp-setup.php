<?php
// Creates an Application Password exactly the way the Connect screen's JavaScript
// does (POST /wp/v2/users/me/application-passwords). The MCP session then runs on
// the MCP Adapter this plugin bundles; with "with-adapter-plugin" the separate
// MCP Adapter plugin is activated first, to test both running together.
require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( 1 );

if ( in_array( 'with-adapter-plugin', $argv, true ) ) {
	$r = activate_plugin( 'mcp-adapter/mcp-adapter.php' );
	if ( is_wp_error( $r ) ) {
		echo 'MCP Adapter activation error: ', $r->get_error_message(), "\n";
		exit( 1 );
	}
	echo "MCP Adapter plugin active as well\n";
} else {
	echo 'MCP Adapter plugin active: ', is_plugin_active( 'mcp-adapter/mcp-adapter.php' ) ? 'yes' : 'no (using the bundled one)', "\n";
}
if ( ! att_mcp_adapter_active() ) {
	echo "The bundled MCP Adapter did not load\n";
	exit( 1 );
}
if ( ! wp_is_application_passwords_available_for_user( wp_get_current_user() ) ) {
	echo "Application Passwords are not available\n";
	exit( 1 );
}

$req = new WP_REST_Request( 'POST', '/wp/v2/users/me/application-passwords' );
$req->set_body_params( array( 'name' => 'AI agent (MCP) – e2e', 'app_id' => ATT_MCP_APP_ID ) );
$res = rest_do_request( $req );
if ( $res->is_error() ) {
	echo 'Create failed: ', $res->as_error()->get_error_message(), "\n";
	exit( 1 );
}
$data = $res->get_data();
file_put_contents( __DIR__ . '/app-password.txt', $data['password'] . "\n" . $data['uuid'] );
echo 'Created Application Password (uuid ', $data['uuid'], ', app_id ', $data['app_id'], ")\n";
