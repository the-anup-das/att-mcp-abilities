<?php
/**
 * Uninstall: remove every option and table this plugin created, on every site
 * of a multisite network. Application Passwords belong to users and are kept.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

function att_mcp_uninstall_site() {
    global $wpdb;

    foreach ( array(
        'att_mcp_abilities', 'att_mcp_addons', 'att_mcp_controls', 'att_mcp_db_version', 'att_mcp_digest_last', 'att_mcp_seen_abilities',
        // Legacy (pre-1.8.0 wsp_ prefix).
        'wsp_mcp_abilities', 'wsp_mcp_addons', 'wsp_mcp_controls', 'wsp_mcp_db_version',
    ) as $option ) {
        delete_option( $option );
    }

    wp_clear_scheduled_hook( 'att_mcp_daily_digest' );

    foreach ( array( 'att_mcp_audit', 'att_mcp_changes', 'wsp_mcp_audit' ) as $table ) {
        $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
    }
}

if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $att_mcp_site_id ) {
        switch_to_blog( $att_mcp_site_id );
        att_mcp_uninstall_site();
        restore_current_blog();
    }
} else {
    att_mcp_uninstall_site();
}
