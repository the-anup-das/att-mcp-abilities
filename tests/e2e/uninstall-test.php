<?php
// Runs the plugin's uninstall.php and verifies everything it created is gone.
require __DIR__ . '/bootstrap.php';
global $wpdb;

t_section( 'Uninstall' );
update_option( 'att_mcp_digest_last', 'x' );
define( 'WP_UNINSTALL_PLUGIN', 'att-mcp-abilities/att-mcp-abilities.php' );
include WP_PLUGIN_DIR . '/att-mcp-abilities/uninstall.php';

foreach ( array( 'att_mcp_abilities', 'att_mcp_addons', 'att_mcp_controls', 'att_mcp_db_version', 'att_mcp_digest_last' ) as $o ) {
	t_ok( "option {$o} removed", false === get_option( $o ) );
}
foreach ( array( 'att_mcp_audit', 'att_mcp_changes' ) as $t ) {
	$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM sqlite_master WHERE type = %s AND name = %s', 'table', $wpdb->prefix . $t ) );
	t_ok( "table {$t} dropped", ! $exists );
}
t_ok( 'digest cron cleared', ! wp_next_scheduled( 'att_mcp_daily_digest' ) );
t_finish();
