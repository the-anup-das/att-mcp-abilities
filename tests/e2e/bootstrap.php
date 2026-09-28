<?php
/**
 * Shared bootstrap for the end-to-end test scripts: loads the throwaway test
 * WordPress (ATT_WP_DIR, default tests/e2e/wordpress), captures every PHP
 * warning/notice raised from plugin files and every _doing_it_wrong() call.
 */
$_SERVER['HTTP_HOST']   = '127.0.0.1:8899';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = 8899;
$_SERVER['REQUEST_URI'] = '/';
$GLOBALS['att_issues']  = array();

set_error_handler( function ( $no, $str, $file, $line ) {
	$f = str_replace( '\\', '/', (string) $file );
	if ( false !== strpos( $f, 'att-mcp-abilities' ) ) {
		$GLOBALS['att_issues'][] = "PHP[$no] $str @ " . basename( $f ) . ":$line";
	}
	return false;
} );

if ( ! empty( $att_admin ) ) {
	define( 'WP_ADMIN', true );
}
$att_wp_dir = getenv( 'ATT_WP_DIR' ) ? getenv( 'ATT_WP_DIR' ) : __DIR__ . '/wordpress';
if ( ! empty( $att_installing ) ) {
	define( 'WP_INSTALLING', true );
}
require $att_wp_dir . '/wp-load.php';
if ( ! empty( $att_admin ) ) {
	require_once ABSPATH . 'wp-admin/includes/admin.php';
}

add_action( 'doing_it_wrong_run', function ( $function, $message ) {
	$GLOBALS['att_issues'][] = 'doing_it_wrong: ' . $function . ' — ' . wp_strip_all_tags( $message );
}, 10, 2 );

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = array();

function t_ok( $label, $cond, $detail = '' ) {
	if ( $cond ) {
		$GLOBALS['t_pass']++;
		echo "  ok    {$label}\n";
	} else {
		$GLOBALS['t_fail'][] = $label;
		$d = is_wp_error( $detail ) ? $detail->get_error_code() . ': ' . $detail->get_error_message() : ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) );
		echo "  FAIL  {$label}" . ( $d ? "  -> {$d}" : '' ) . "\n";
	}
}

function t_code( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'OK';
}

function t_run( $name, $input = array() ) {
	if ( ! wp_has_ability( $name ) ) {
		return new WP_Error( 'not_registered', "{$name} is not registered" );
	}
	return wp_get_ability( $name )->execute( $input );
}

function t_section( $title ) {
	echo "\n== {$title}\n";
}

/** Print the summary and exit non-zero on any failure or issue (for CI). */
function t_finish() {
	echo "\n== PHP warnings / _doing_it_wrong\n";
	$issues = array_unique( $GLOBALS['att_issues'] );
	if ( empty( $issues ) ) {
		echo "  none\n";
	} else {
		foreach ( $issues as $i ) {
			echo "  ISSUE {$i}\n";
		}
	}
	printf( "\nRESULT: %d passed, %d failed, %d issues\n", $GLOBALS['t_pass'], count( $GLOBALS['t_fail'] ), count( $issues ) );
	foreach ( $GLOBALS['t_fail'] as $f ) {
		echo "  failed: {$f}\n";
	}
	exit( ( $GLOBALS['t_fail'] || $issues ) ? 1 : 0 );
}
