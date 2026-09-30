<?php
/**
 * A minimal MCP client (streamable HTTP, JSON-RPC 2.0) for the end-to-end
 * tests: talks to the MCP Adapter default server with the Application Password
 * from mcp-setup.php, the same way @automattic/mcp-wordpress-remote connects an
 * AI client. Plain PHP (no WordPress loaded, no curl extension needed).
 */
$site     = getenv( 'ATT_SITE_URL' ) ? getenv( 'ATT_SITE_URL' ) : 'http://127.0.0.1:8899';
$endpoint = $site . '/?rest_route=/mcp/mcp-adapter-default-server';
$password = is_readable( __DIR__ . '/app-password.txt' ) ? trim( explode( "\n", trim( (string) file_get_contents( __DIR__ . '/app-password.txt' ) ) )[0] ) : '';
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

/** Handshake, then the adapter's discover / get-info / execute tool names. */
function mcp_connect() {
	list( $status, $init ) = mcp( 'initialize', array( 'protocolVersion' => '2025-06-18', 'capabilities' => (object) array(), 'clientInfo' => array( 'name' => 'att-e2e-client', 'version' => '1.0' ) ) );
	check( 'initialize', 200 === $status && isset( $init['result']['serverInfo'] ), json_encode( $init ) );
	mcp( 'notifications/initialized', array(), true );

	list( , $tools ) = mcp( 'tools/list', array() );
	$names = array();
	foreach ( (array) ( isset( $tools['result']['tools'] ) ? $tools['result']['tools'] : array() ) as $t ) {
		$names[] = $t['name'];
	}
	$find = function ( $needle ) use ( $names ) {
		foreach ( $names as $n ) {
			if ( false !== strpos( $n, $needle ) ) {
				return $n;
			}
		}
		return '';
	};
	return array( $names, $find( 'discover-abilities' ), $find( 'get-ability-info' ), $find( 'execute-ability' ) );
}

/** Does an ability name appear in a JSON text (slashes may be escaped)? */
function lists_ability( $text, $name ) {
	return (bool) preg_match( '#"' . str_replace( '/', '\\\\?/', preg_quote( $name, '#' ) ) . '"#', $text );
}

function mcp_finish() {
	global $passed, $failed;
	echo "\nRESULT: {$passed} passed, {$failed} failed\n";
	exit( $failed ? 1 : 0 );
}
