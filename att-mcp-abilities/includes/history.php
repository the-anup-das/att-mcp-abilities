<?php
/**
 * Change history (undo).
 *
 * Before an MCP write overwrites a stored value, the ability captures the old
 * state with att_mcp_capture() and records it with att_mcp_record_change().
 * att/undo-change later restores those values (and records the values it
 * replaced, so an undo can itself be undone).
 *
 * Covered: options, option autoload flags, theme mods, Additional CSS, post meta
 * (incl. Elementor data and kit settings, SEO plugin fields), All in One SEO
 * post data, and LiteSpeed Cache / Super Page Cache settings (restored through
 * each plugin's own settings API). Post/page/template content is covered by
 * core revisions instead (att/list-revisions, att/restore-revision).
 *
 * Stored in {$wpdb->prefix}att_mcp_changes; the newest 50 changes are kept and
 * a single change larger than 1 MB is not recorded (the write still happens).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_changes_table() {
    global $wpdb;
    return $wpdb->prefix . 'att_mcp_changes';
}

function att_mcp_create_changes_table() {
    global $wpdb;
    $table   = att_mcp_changes_table();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        created DATETIME NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        ability VARCHAR(100) NOT NULL DEFAULT '',
        label VARCHAR(255) NOT NULL DEFAULT '',
        items LONGTEXT NOT NULL,
        undone DATETIME NULL DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY created (created)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/** The ability currently executing (set by the dispatcher; used to label changes). */
function att_mcp_current_ability( $set = null ) {
    static $current = '';
    if ( null !== $set ) {
        $current = (string) $set;
    }
    return $current;
}

/**
 * Capture the current state of one target.
 *   option      target = option name
 *   theme_mod   target = theme mod key (active theme)
 *   post_meta   target = array( post_id, meta_key )
 *   custom_css  target = theme stylesheet slug
 *   option_autoload  target = option name (value = autoloaded or not)
 *   litespeed_conf   target = list of LiteSpeed Cache setting ids
 *   spc_settings     target = list of Super Page Cache setting keys
 *   aioseo_post      target = post id (All in One SEO title, description, …)
 */
function att_mcp_capture( $type, $target ) {
    switch ( $type ) {
        case 'option':
            $missing = new stdClass();
            $value   = get_option( (string) $target, $missing );
            $existed = ( $value !== $missing );
            return array( 'type' => 'option', 'target' => (string) $target, 'existed' => $existed, 'value' => $existed ? $value : null );

        case 'option_autoload':
            $missing = new stdClass();
            $existed = ( get_option( (string) $target, $missing ) !== $missing );
            return array( 'type' => 'option_autoload', 'target' => (string) $target, 'existed' => $existed, 'value' => $existed && array_key_exists( (string) $target, wp_load_alloptions() ) );

        case 'litespeed_conf':
        case 'spc_settings':
        case 'aioseo_post':
            // Plugin-backed state: captured and restored through the plugin's own API.
            $fn = 'att_mcp_capture_' . $type;
            return function_exists( $fn ) ? call_user_func( $fn, $target ) : null;

        case 'theme_mod':
            $mods    = get_theme_mods();
            $mods    = is_array( $mods ) ? $mods : array();
            $existed = array_key_exists( (string) $target, $mods );
            return array( 'type' => 'theme_mod', 'target' => (string) $target, 'stylesheet' => get_stylesheet(), 'existed' => $existed, 'value' => $existed ? $mods[ $target ] : null );

        case 'post_meta':
            $post_id = (int) $target[0];
            $key     = (string) $target[1];
            $existed = metadata_exists( 'post', $post_id, $key );
            return array( 'type' => 'post_meta', 'target' => array( $post_id, $key ), 'existed' => $existed, 'value' => $existed ? get_post_meta( $post_id, $key, true ) : null );

        case 'custom_css':
            $stylesheet = $target ? (string) $target : get_stylesheet();
            return array( 'type' => 'custom_css', 'target' => $stylesheet, 'existed' => true, 'value' => (string) wp_get_custom_css( $stylesheet ) );
    }
    return null;
}

/** Record captured items as one undoable change. Returns the change id (0 if not recorded). */
function att_mcp_record_change( $items, $label = '' ) {
    global $wpdb;
    $items = array_values( array_filter( (array) $items ) );
    if ( empty( $items ) ) {
        return 0;
    }
    $blob = maybe_serialize( $items );
    if ( strlen( $blob ) > 1048576 ) {
        return 0; // too large to keep — the write proceeds without an undo point
    }
    $ok = $wpdb->insert( att_mcp_changes_table(), array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        'created' => current_time( 'mysql' ),
        'user_id' => get_current_user_id(),
        'ability' => substr( att_mcp_current_ability(), 0, 100 ),
        'label'   => substr( (string) $label, 0, 255 ),
        'items'   => $blob,
    ) );
    if ( ! $ok ) {
        return 0;
    }
    $id = (int) $wpdb->insert_id;
    if ( $id > 50 ) {
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', att_mcp_changes_table(), $id - 50 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }
    return $id;
}

/** Shorthand: capture one target and record it. */
function att_mcp_snapshot( $type, $target, $label = '' ) {
    return att_mcp_record_change( array( att_mcp_capture( $type, $target ) ), $label );
}

function att_mcp_get_change( $id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', att_mcp_changes_table(), (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $row ) {
        $row->items = maybe_unserialize( $row->items );
        $row->items = is_array( $row->items ) ? $row->items : array();
    }
    return $row;
}

function att_mcp_recent_changes( $limit = 20 ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', att_mcp_changes_table(), max( 1, min( 50, (int) $limit ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    foreach ( (array) $rows as $row ) {
        $row->items = maybe_unserialize( $row->items );
        $row->items = is_array( $row->items ) ? $row->items : array();
    }
    return (array) $rows;
}

/** Can the current user restore this captured item? */
function att_mcp_can_restore_item( $item ) {
    switch ( isset( $item['type'] ) ? $item['type'] : '' ) {
        case 'option':
        case 'option_autoload':
            return current_user_can( 'manage_options' ) && ! att_mcp_is_protected_option( $item['target'] );
        case 'litespeed_conf':
        case 'spc_settings':
            return current_user_can( 'manage_options' ) && function_exists( 'att_mcp_restore_' . $item['type'] );
        case 'aioseo_post':
            return current_user_can( 'edit_post', (int) $item['target'] ) && function_exists( 'att_mcp_restore_aioseo_post' );
        case 'theme_mod':
            return current_user_can( 'edit_theme_options' );
        case 'custom_css':
            return current_user_can( 'edit_css' );
        case 'post_meta':
            return current_user_can( 'edit_post', (int) $item['target'][0] ) && ! att_mcp_is_blocked_meta_key( $item['target'][1] );
    }
    return false;
}

/** Write one captured item back. Returns true or WP_Error. */
function att_mcp_restore_item( $item ) {
    $value   = isset( $item['value'] ) ? $item['value'] : null;
    $existed = ! empty( $item['existed'] );

    switch ( $item['type'] ) {
        case 'option':
            if ( $existed ) {
                update_option( $item['target'], $value );
            } else {
                delete_option( $item['target'] );
            }
            if ( 'generate_settings' === $item['target'] && function_exists( 'att_mcp_gp_refresh_css' ) ) {
                att_mcp_gp_refresh_css();
            }
            break;

        case 'option_autoload':
            if ( $existed ) {
                wp_set_option_autoload( $item['target'], (bool) $value );
            }
            break;

        case 'litespeed_conf':
        case 'spc_settings':
        case 'aioseo_post':
            $result = call_user_func( 'att_mcp_restore_' . $item['type'], $item );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            break;

        case 'theme_mod':
            if ( ! empty( $item['stylesheet'] ) && get_stylesheet() !== $item['stylesheet'] ) {
                return new WP_Error( 'att_mcp_theme_changed', sprintf( 'This theme mod belongs to the theme "%s", which is no longer active.', $item['stylesheet'] ) );
            }
            if ( $existed ) {
                set_theme_mod( $item['target'], $value );
            } else {
                remove_theme_mod( $item['target'] );
            }
            break;

        case 'custom_css':
            $result = wp_update_custom_css_post( (string) $value, array( 'stylesheet' => $item['target'] ) );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            break;

        case 'post_meta':
            list( $post_id, $key ) = $item['target'];
            if ( $existed ) {
                update_post_meta( (int) $post_id, $key, wp_slash( $value ) );
            } else {
                delete_post_meta( (int) $post_id, $key );
            }
            if ( 0 === strpos( $key, '_elementor' ) && function_exists( 'att_mcp_elementor_clear_cache' ) ) {
                att_mcp_elementor_clear_cache();
            }
            break;

        default:
            return new WP_Error( 'att_mcp_bad_change', 'Unknown change type.' );
    }

    do_action( 'att_mcp_after_restore_item', $item );
    return true;
}

/**
 * Undo a recorded change: restore its items (newest first) after recording the
 * values being replaced as a new change (so the undo can be undone).
 */
function att_mcp_undo_change( $id ) {
    global $wpdb;
    $change = att_mcp_get_change( $id );
    if ( ! $change ) {
        return new WP_Error( 'att_mcp_no_change', 'No change found with that id.' );
    }
    if ( ! empty( $change->undone ) ) {
        return new WP_Error( 'att_mcp_already_undone', sprintf( 'Change %d was already undone on %s.', (int) $change->id, $change->undone ) );
    }
    foreach ( $change->items as $item ) {
        if ( ! att_mcp_can_restore_item( $item ) ) {
            return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to undo this change.' );
        }
    }

    $redo = array();
    foreach ( $change->items as $item ) {
        $redo[] = att_mcp_capture( $item['type'], $item['target'] );
    }
    $redo_id = att_mcp_record_change( $redo, sprintf( 'Undo of change #%d', (int) $change->id ) );

    $restored = array();
    foreach ( array_reverse( $change->items ) as $item ) {
        $result = att_mcp_restore_item( $item );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $restored[] = array( 'type' => $item['type'], 'target' => $item['target'] );
    }

    $wpdb->update( att_mcp_changes_table(), array( 'undone' => current_time( 'mysql' ) ), array( 'id' => (int) $change->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

    return array(
        'undone'         => (int) $change->id,
        'restored'       => $restored,
        'redo_change_id' => $redo_id,
        'note'           => 'Run att/purge-cache if a page cache is active. To reapply the change, undo change #' . (int) $redo_id . '.',
    );
}
