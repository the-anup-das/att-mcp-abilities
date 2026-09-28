<?php
// Installs the throwaway test site and activates the plugin under test.
$att_installing = true;
require __DIR__ . '/bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
	wp_install( 'ATT Test Site', 'admin', 'admin@example.com', true, '', 'password' );
	echo "WordPress installed\n";
}
echo 'WordPress ', get_bloginfo( 'version' ), ' | PHP ', PHP_VERSION, "\n";

$result = activate_plugin( 'att-mcp-abilities/att-mcp-abilities.php' );
if ( is_wp_error( $result ) ) {
	echo 'Activation error: ', $result->get_error_message(), "\n";
	exit( 1 );
}
echo "Plugin activated\n";

global $wpdb;
$table_exists = function ( $t ) use ( $wpdb ) {
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM sqlite_master WHERE type = %s AND name = %s', 'table', $wpdb->prefix . $t ) );
};

// On Windows junctions WordPress core can fail to map the plugin's real path
// (plugin_basename() returns a full path), so it skips the activation hook. The
// plugin self-heals on the next load via att_mcp_maybe_upgrade() — verify that path.
if ( ! $table_exists( 'att_mcp_audit' ) && 'att-mcp-abilities/att-mcp-abilities.php' !== plugin_basename( ATT_MCP_FILE ) ) {
	echo "Note: WordPress skipped the activation hook (junction path mapping); checking self-heal on load\n";
	att_mcp_maybe_upgrade();
}

foreach ( array( 'att_mcp_audit', 'att_mcp_changes' ) as $t ) {
	$exists = $table_exists( $t );
	echo $t, ': ', $exists ? 'exists' : 'MISSING', "\n";
	if ( ! $exists ) {
		exit( 1 );
	}
}
