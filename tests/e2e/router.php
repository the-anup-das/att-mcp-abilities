<?php
/**
 * Router for PHP's built-in server:
 *   php -S 127.0.0.1:8899 -t wordpress router.php
 * Real files and directories (wp-login.php, wp-admin/, assets) are served by the
 * built-in server from the -t document root; everything else goes to WordPress
 * (pretty permalinks, the REST API).
 */
$root = getenv( 'ATT_WP_DIR' ) ? getenv( 'ATT_WP_DIR' ) : __DIR__ . '/wordpress';
$path = urldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

// Never serve dotfiles or the SQLite database, even on this local-only test server.
if ( preg_match( '#/\.|^/wp-content/database(/|$)#', $path ) ) {
	http_response_code( 403 );
	return true;
}
if ( file_exists( $root . $path ) ) {
	return false;
}
chdir( $root );
require $root . '/index.php';
