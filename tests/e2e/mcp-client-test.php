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
$site     = getenv( 'ATT_SITE_URL' ) ? getenv( 'ATT_SITE_URL' ) : 'http://127.0.0.1:8899';
$endpoint = $site . '/?rest_route=/mcp/mcp-adapter-default-server';
$password = trim( explode( "\n", trim( (string) file_get_contents( __DIR__ . '/app-password.txt' ) ) )[0] );
$session  = null;
$next_id  = 0;
$passed   = 0;
$failed   = 0;

function mcp( $method, $params, $notify = false ) {
	global $endpoint, $password, $session, $next_id;
	$msg = array( 'jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params );
	if ( ! $notify ) {
		$msg['id'] = ++$next_id;
	}
	$headers = array(
		'Content-Type: application/json',
		'Accept: application/json, text/event-stream',
		'Authorization: Basic ' . base64_encode( 'admin:' . $password ),
	);
	if ( $session ) {
		$headers[] = 'Mcp-Session-Id: ' . $session;
	}
	$ctx  = stream_context_create( array( 'http' => array(
		'method'        => 'POST',
		'header'        => implode( "\r\n", $headers ),
		'content'       => json_encode( $msg ),
		'ignore_errors' => true,
		'timeout'       => 60,
	) ) );
	$body   = (string) file_get_contents( $endpoint, false, $ctx );
	$status = 0;
	foreach ( (array) $http_response_header as $h ) {
		if ( preg_match( '#^HTTP/\S+\s+(\d+)#', $h, $m ) ) {
			$status = (int) $m[1];
		}
		if ( 0 === stripos( $h, 'Mcp-Session-Id:' ) ) {
			$session = trim( substr( $h, 15 ) );
		}
	}
	if ( preg_match( '/^data: (.+)$/m', $body, $m ) ) {
		$body = $m[1]; // SSE framing
	}
	return array( $status, $notify ? null : json_decode( ltrim( $body, "\xEF\xBB\xBF" ), true ) );
}

function call_tool( $tool, $arguments ) {
	list( , $r ) = mcp( 'tools/call', array( 'name' => $tool, 'arguments' => (object) $arguments ) );
	if ( isset( $r['error'] ) ) {
		return array( true, $r['error']['message'] );
	}
	$text = '';
	foreach ( (array) ( isset( $r['result']['content'] ) ? $r['result']['content'] : array() ) as $c ) {
		$text .= isset( $c['text'] ) ? $c['text'] : '';
	}
	return array( ! empty( $r['result']['isError'] ), $text );
}

function check( $label, $cond, $detail = '' ) {
	global $passed, $failed;
	if ( $cond ) {
		$passed++;
		echo "  ok    {$label}\n";
	} else {
		$failed++;
		echo "  FAIL  {$label}  -> " . substr( (string) $detail, 0, 300 ) . "\n";
	}
}

if ( in_array( '--expect-unauthorized', $argv, true ) ) {
	echo "== After revoking the Application Password\n";
	list( $status ) = mcp( 'initialize', array( 'protocolVersion' => '2025-06-18', 'capabilities' => (object) array(), 'clientInfo' => array( 'name' => 'e2e', 'version' => '1' ) ) );
	check( "connection refused (HTTP {$status})", 401 === $status, $status );
	echo "\nRESULT: {$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}

echo "== MCP handshake\n";
list( $status, $init ) = mcp( 'initialize', array( 'protocolVersion' => '2025-06-18', 'capabilities' => (object) array(), 'clientInfo' => array( 'name' => 'att-e2e-client', 'version' => '1.0' ) ) );
check( 'initialize', 200 === $status && isset( $init['result']['serverInfo'] ), json_encode( $init ) );
mcp( 'notifications/initialized', array(), true );

list( , $tools ) = mcp( 'tools/list', array() );
$names = array();
foreach ( (array) ( isset( $tools['result']['tools'] ) ? $tools['result']['tools'] : array() ) as $t ) {
	$names[] = $t['name'];
}
echo '  tools: ', implode( ', ', $names ), "\n";
$find = function ( $needle ) use ( $names ) {
	foreach ( $names as $n ) {
		if ( false !== strpos( $n, $needle ) ) {
			return $n;
		}
	}
	return '';
};
$discover = $find( 'discover-abilities' );
$info     = $find( 'get-ability-info' );
$execute  = $find( 'execute-ability' );
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

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
