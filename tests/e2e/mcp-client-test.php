<?php
/**
 * A real MCP session (streamable HTTP, JSON-RPC 2.0) against the MCP Adapter
 * default server, authenticated with the Application Password from
 * mcp-setup.php — the same way @automattic/mcp-wordpress-remote connects an AI
 * client. Plain PHP (no WordPress loaded, no curl extension needed).
 *
 *   php mcp-client-test.php                        full session
 *   php mcp-client-test.php --expect-unauthorized  after mcp-revoke.php: must get HTTP 401
 */
require __DIR__ . '/mcp-lib.php';

if ( in_array( '--expect-unauthorized', $argv, true ) ) {
	echo "== After revoking the Application Password\n";
	list( $status ) = mcp( 'initialize', array( 'protocolVersion' => '2025-06-18', 'capabilities' => (object) array(), 'clientInfo' => array( 'name' => 'e2e', 'version' => '1' ) ) );
	check( "connection refused (HTTP {$status})", 401 === $status, $status );
	mcp_finish();
}

echo "== MCP handshake\n";
list( $names, $discover, $info, $execute ) = mcp_connect();
echo '  tools: ', implode( ', ', $names ), "\n";
check( 'adapter exposes discover / info / execute tools', $discover && $info && $execute, implode( ',', $names ) );

echo "== Our abilities through MCP\n";
list( , $text ) = call_tool( $discover, array() );
$count = preg_match_all( '#"att\\\\?/[a-z0-9-]+"#', $text );
check( "discover lists ATT abilities ({$count})", $count >= 50, $text );

list( $err, $text ) = call_tool( $info, array( 'ability_name' => 'att/update-page' ) );
check( 'get-ability-info returns schema + annotations', ! $err && false !== strpos( $text, 'template' ) && false !== strpos( $text, 'annotations' ), $text );

list( $err, $text ) = call_tool( $execute, array( 'ability_name' => 'att/get-site-info', 'parameters' => (object) array() ) );
check( 'execute att/get-site-info', ! $err && false !== strpos( $text, 'wp_version' ), $text );

list( $err, $text ) = call_tool( $execute, array( 'ability_name' => 'att/create-page', 'parameters' => array(
	'title'   => 'Built over MCP',
	'content' => '<!-- wp:heading --><h2 class="wp-block-heading">Hello from an agent</h2><!-- /wp:heading -->',
	'status'  => 'draft',
) ) );
check( 'execute att/create-page (write)', ! $err && false !== strpos( $text, '"created":true' ), $text );
$page_id = preg_match( '/"id":(\d+)/', $text, $m ) ? (int) $m[1] : 0;

list( $err, $text ) = call_tool( $execute, array( 'ability_name' => 'att/get-content', 'parameters' => array( 'id' => $page_id, 'include_meta' => false ) ) );
check( 'read the new draft back', ! $err && false !== strpos( $text, 'Hello from an agent' ), $text );

list( $err, $text ) = call_tool( $execute, array( 'ability_name' => 'att/update-option', 'parameters' => array( 'name' => 'att_mcp_controls', 'value' => array( 'allow_php' => true ) ) ) );
check( 'agent cannot rewrite its own guardrails', $err || false !== strpos( $text, 'protected' ), $text );

list( $err, $text ) = call_tool( $execute, array( 'ability_name' => 'att/rest-get', 'parameters' => array( 'route' => '/wp/v2/users/me/application-passwords' ) ) );
check( 'agent cannot list or mint Application Passwords', $err || false !== strpos( $text, 'not available' ), $text );

mcp_finish();
