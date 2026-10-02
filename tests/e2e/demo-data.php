<?php
/**
 * Gives a FRESH e2e site (setup.sh, without run-all.sh) a realistic configuration and
 * activity history for the WordPress.org screenshots. Runs abilities through core's
 * execute path, as an agent would. See tests/e2e/README.md › Screenshots.
 */
require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( 1 );

update_option( 'blogname', 'Demo Site' );
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
flush_rewrite_rules( false );

$on = array(
	'att/get-posts', 'att/get-pages', 'att/get-categories', 'att/get-tags', 'att/search', 'att/get-site-info',
	'att/list-content', 'att/get-content', 'att/create-page', 'att/update-page', 'att/create-post', 'att/update-post',
	'att/get-media', 'att/upload-media', 'att/get-menus', 'att/create-menu', 'att/update-menu',
	'att/get-site-settings', 'att/update-site-settings', 'att/list-changes', 'att/undo-change', 'att/list-revisions', 'att/restore-revision',
	'att/get-active-theme', 'att/render-page', 'att/get-custom-css', 'att/update-custom-css', 'att/get-theme-mods', 'att/set-theme-mod',
	'att/get-global-styles', 'att/update-global-styles', 'att/get-block-templates', 'att/get-block-template', 'att/save-block-template', 'att/purge-cache',
);
$settings = array();
foreach ( att_mcp_ability_registry() as $key => $cfg ) {
	$settings[ $key ] = in_array( $key, $on, true );
}
update_option( 'att_mcp_abilities', $settings );
update_option( 'att_mcp_addons', array( 'core' => true, 'design' => true, 'advanced' => false ) );
update_option( 'att_mcp_controls', array( 'active' => true, 'writes_paused' => false, 'audit' => true, 'allow_php' => false, 'rate_limit' => 60, 'notify' => true ) );

$run = function ( $name, $input = array() ) {
	$r = wp_get_ability( $name )->execute( $input );
	echo str_pad( $name, 30 ), is_wp_error( $r ) ? 'error: ' . $r->get_error_code() : 'ok', "\n";
	return $r;
};

$run( 'att/get-site-info' );
$run( 'att/get-active-theme' );
$about = $run( 'att/create-page', array(
	'title'   => 'About us',
	'content' => "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">We build friendly websites</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>A small studio for small businesses.</p>\n<!-- /wp:paragraph -->",
	'status'  => 'publish',
) );
$run( 'att/update-site-settings', array( 'tagline' => 'Built with a little help from AI agents', 'show_on_front' => 'page', 'page_on_front' => (int) $about['id'] ) );
$logo = base64_encode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/.wordpress-org/icon-256x256.png' ) );
$run( 'att/upload-media', array( 'filename' => 'studio-logo.png', 'content_base64' => $logo, 'alt' => 'Studio logo', 'set_as_logo' => true ) );
$run( 'att/update-global-styles', array( 'settings' => array( 'color' => array( 'palette' => array( 'custom' => array( array( 'slug' => 'brand', 'color' => '#3D7BF7', 'name' => 'Brand' ) ) ) ) ) ) );
$run( 'att/update-custom-css', array( 'css' => '.wp-block-button__link { border-radius: 999px; }' ) );
$run( 'att/update-page', array( 'id' => (int) $about['id'], 'template' => 'landing.php' ) ); // an agent typo — refused and logged as an error
$run( 'att/create-menu', array( 'name' => 'Main', 'items' => array( array( 'title' => 'Home', 'url' => home_url( '/' ) ), array( 'title' => 'About', 'page_id' => (int) $about['id'] ) ) ) );
$run( 'att/list-changes' );
$run( 'att/undo-change' ); // undoes the Additional CSS change
$run( 'att/list-content', array( 'post_type' => 'page' ) );
