<?php
/**
 * Governance of other plugins' abilities.
 *
 * Some plugins register their own MCP tools on the Abilities API — Rank Math
 * ships 29, exposed to every MCP client by default. MCP Adapter serves them
 * through the same connection as this plugin's abilities, so without this
 * layer they would bypass the MCP Controls.
 *
 * For every ability in a governed namespace (an addon's 'governs' prefix, e.g.
 * "rank-math/"), core's wp_register_ability_args filter is used to:
 *  - apply the MCP Controls at all times: the kill switch hides and refuses
 *    every tool, read-only mode hides and refuses the write tools;
 *  - when the namespace's addon is enabled, apply the per-tool toggles from
 *    MCP > Settings (a tool that is off is hidden from MCP and the REST API and
 *    refuses to run); when the addon is off, the tools stay available exactly
 *    as their plugin ships them;
 *  - route every call through att_mcp_dispatch(): activity log, write rate
 *    limit, redaction guard, and — for write tools — the change recorder, which
 *    captures every option and post meta value the tool changes so that
 *    att/undo-change can restore them.
 *
 * The tool's own permission callback is always kept.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Governed ability namespaces: name prefix => addon key, from each addon's
 * 'governs' entry (an addon registered through the att_mcp_addons filter can
 * govern another plugin's abilities the same way).
 */
function att_mcp_governed_namespaces() {
    $map = array();
    foreach ( att_mcp_addons() as $key => $cfg ) {
        if ( ! empty( $cfg['governs'] ) && is_string( $cfg['governs'] ) ) {
            $map[ $cfg['governs'] ] = (string) $key;
        }
    }
    return $map;
}

/** The addon governing an ability name ('' when not governed). */
function att_mcp_governing_addon( $name ) {
    foreach ( att_mcp_governed_namespaces() as $prefix => $addon ) {
        if ( '' !== $prefix && 0 === strpos( (string) $name, $prefix ) ) {
            return (string) $addon;
        }
    }
    return '';
}

/** Is this governed ability a write (from the registry, else its readonly annotation)? */
function att_mcp_governed_access( $name, $args = array() ) {
    $registry = att_mcp_ability_registry();
    if ( isset( $registry[ $name ]['access'] ) ) {
        return $registry[ $name ]['access'];
    }
    return empty( $args['meta']['annotations']['readonly'] ) ? 'write' : 'read';
}

/**
 * Why a governed ability may not run right now: array( code, message ), or
 * an empty array when it may.
 */
function att_mcp_governed_block_reason( $name, $addon, $access ) {
    if ( ! att_mcp_is_active() ) {
        return array( 'att_mcp_disabled', 'MCP access is turned off by the site administrator.' );
    }
    if ( 'write' === $access && att_mcp_writes_paused() ) {
        return array( 'att_mcp_writes_paused', 'MCP writes are paused (read-only mode) by the site administrator.' );
    }
    if ( att_mcp_addon_is_enabled( $addon ) && ! att_mcp_is_enabled( $name ) ) {
        return array( 'att_mcp_ability_disabled', sprintf( '"%s" is turned off in MCP > Settings on this site.', $name ) );
    }
    return array();
}

/** wp_register_ability_args filter: apply the MCP Controls to governed abilities. */
function att_mcp_govern_ability_args( $args, $name ) {
    $addon = att_mcp_governing_addon( $name );
    if ( '' === $addon || ! is_array( $args ) || ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
        return $args;
    }
    att_mcp_note_governed_ability( $name, $args );

    $access = att_mcp_governed_access( $name, $args );
    $meta   = ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) ? $args['meta'] : array();

    // Clients may omit arguments for tools without required inputs.
    if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) && ! array_key_exists( 'default', $args['input_schema'] )
        && isset( $args['input_schema']['type'] ) && 'object' === $args['input_schema']['type'] && empty( $args['input_schema']['required'] ) ) {
        $args['input_schema']['default'] = array();
    }

    $blocked = att_mcp_governed_block_reason( $name, $addon, $access );
    if ( $blocked ) {
        $meta['mcp']          = array_merge( ( isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ) ? $meta['mcp'] : array(), array( 'public' => false ) );
        $meta['show_in_rest'] = false;
        $args['meta']         = $meta;
        $args['execute_callback'] = function () use ( $blocked ) {
            return new WP_Error( $blocked[0], $blocked[1] );
        };
        return $args;
    }

    $original = $args['execute_callback'];
    $record   = 'write' === $access && att_mcp_governed_undoable( $name );
    $args['execute_callback'] = function ( $input = null ) use ( $name, $original, $access, $record ) {
        return att_mcp_dispatch( $name, $original, $input, array( 'access' => $access, 'record' => $record ) );
    };
    return $args;
}

/** Can this governed write tool's changes be recorded for undo? */
function att_mcp_governed_undoable( $name ) {
    $registry = att_mcp_ability_registry();
    return ! isset( $registry[ $name ]['undo'] ) || false !== $registry[ $name ]['undo'];
}

/**
 * Remember governed abilities this plugin has no built-in metadata for (a newer
 * version of the other plugin may add tools), so MCP > Settings can list them.
 * They start off whenever their addon is enabled.
 */
function att_mcp_note_governed_ability( $name, $args ) {
    $known = function_exists( 'att_mcp_governed_known' ) ? att_mcp_governed_known() : array();
    if ( isset( $known[ $name ] ) ) {
        return;
    }
    $seen  = get_option( 'att_mcp_seen_abilities', array() );
    $seen  = is_array( $seen ) ? $seen : array();
    $entry = array(
        'label'       => isset( $args['label'] ) ? substr( wp_strip_all_tags( (string) $args['label'] ), 0, 120 ) : $name,
        'description' => isset( $args['description'] ) ? substr( wp_strip_all_tags( (string) $args['description'] ), 0, 300 ) : '',
        'readonly'    => ! empty( $args['meta']['annotations']['readonly'] ),
    );
    if ( ! isset( $seen[ $name ] ) || $seen[ $name ] !== $entry ) {
        $seen[ $name ] = $entry;
        update_option( 'att_mcp_seen_abilities', $seen );
    }
}

/* ----- Change recorder ------------------------------------------------------- */

/**
 * While recording, the state of every option and post meta value is captured
 * just before it is first changed. att_mcp_recorder_stop() returns those
 * captures (only for values that really changed) as history items for
 * att_mcp_record_change(). Protected options (transients, rewrite rules, cron,
 * core structure, secrets, this plugin's controls) and blocked meta keys are
 * never captured: they are regenerated by WordPress or not restorable here.
 */
function att_mcp_recorder( $action = 'items', $item = null ) {
    static $active = false;
    static $items  = array();
    switch ( $action ) {
        case 'start':
            $active = true;
            $items  = array();
            break;
        case 'active':
            return $active;
        case 'add':
            $key = wp_json_encode( array( $item['type'], $item['target'] ) );
            if ( $active && ! isset( $items[ $key ] ) ) {
                $items[ $key ] = $item;
            }
            break;
        case 'stop':
            $active = false;
            $out    = array_values( $items );
            $items  = array();
            return $out;
    }
    return null;
}

function att_mcp_recorder_start() {
    att_mcp_recorder( 'start' );
    add_action( 'update_option', 'att_mcp_recorder_on_option', 1, 1 );
    add_action( 'add_option', 'att_mcp_recorder_on_option', 1, 1 );
    add_action( 'delete_option', 'att_mcp_recorder_on_option', 1, 1 );
    add_filter( 'update_post_metadata', 'att_mcp_recorder_on_post_meta', 1, 3 );
    add_filter( 'add_post_metadata', 'att_mcp_recorder_on_post_meta', 1, 3 );
    add_filter( 'delete_post_metadata', 'att_mcp_recorder_on_post_meta', 1, 3 );
}

/** Stop recording; returns the history items for values that actually changed. */
function att_mcp_recorder_stop() {
    remove_action( 'update_option', 'att_mcp_recorder_on_option', 1 );
    remove_action( 'add_option', 'att_mcp_recorder_on_option', 1 );
    remove_action( 'delete_option', 'att_mcp_recorder_on_option', 1 );
    remove_filter( 'update_post_metadata', 'att_mcp_recorder_on_post_meta', 1 );
    remove_filter( 'add_post_metadata', 'att_mcp_recorder_on_post_meta', 1 );
    remove_filter( 'delete_post_metadata', 'att_mcp_recorder_on_post_meta', 1 );

    $changed = array();
    foreach ( att_mcp_recorder( 'stop' ) as $item ) {
        $now = att_mcp_capture( $item['type'], $item['target'] );
        if ( $now && ( $now['existed'] !== $item['existed'] || maybe_serialize( $now['value'] ) !== maybe_serialize( $item['value'] ) ) ) {
            $changed[] = $item;
        }
    }
    return $changed;
}

/** Hooked before an option is added, updated or deleted. */
function att_mcp_recorder_on_option( $option ) {
    if ( att_mcp_recorder( 'active' ) && is_string( $option ) && ! att_mcp_is_protected_option( $option ) ) {
        att_mcp_recorder( 'add', att_mcp_capture( 'option', $option ) );
    }
}

/** Hooked (as a pass-through filter) before post meta is added, updated or deleted. */
function att_mcp_recorder_on_post_meta( $check, $object_id, $meta_key ) {
    if ( att_mcp_recorder( 'active' ) && (int) $object_id > 0 && is_string( $meta_key ) && ! att_mcp_is_blocked_meta_key( $meta_key ) ) {
        att_mcp_recorder( 'add', att_mcp_capture( 'post_meta', array( (int) $object_id, $meta_key ) ) );
    }
    return $check;
}
