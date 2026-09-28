<?php
// Revokes the test Application Password the way the Connect screen's Revoke button does.
require __DIR__ . '/bootstrap.php';
wp_set_current_user( 1 );
list( , $uuid ) = explode( "\n", trim( file_get_contents( __DIR__ . '/app-password.txt' ) ) );
$res = rest_do_request( new WP_REST_Request( 'DELETE', '/wp/v2/users/me/application-passwords/' . trim( $uuid ) ) );
if ( $res->is_error() ) {
	echo 'Revoke failed: ', $res->as_error()->get_error_message(), "\n";
	exit( 1 );
}
echo 'Revoked ', trim( $uuid ), "\n";
