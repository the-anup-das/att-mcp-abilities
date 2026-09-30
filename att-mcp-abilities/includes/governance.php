<?php
/**
 * Governance of other plugins' MCP tools.
 *
 * Other plugins — and WordPress core — register abilities that MCP Adapter
 * serves to AI agents on the same connection as this plugin's: Rank Math ships
 * 29, Yoast SEO and All in One SEO a handful each, WordPress 7.1 three. Without
 * this layer they would bypass the MCP Controls.
 *
 * Governed: every ability in the namespace an addon claims ('governs', e.g.
 * "rank-math/"), and every other ability MCP Adapter would expose (the catch-all
 * "Other MCP tools" addon) — never this plugin's own or MCP Adapter's.
 *
 * Only calls that come from an MCP client are governed (between MCP Adapter's
 * mcp_adapter_pre_tool_call and mcp_adapter_tool_call_result filters): a plugin
 * using its own abilities in its own screens is never logged, limited or
 * refused. Through core's wp_register_ability_args filter (WordPress 6.9+):
 *  - the kill switch hides every governed tool from MCP and refuses it;
 *    read-only mode does the same for the write tools;
 *  - when the tool's addon is enabled, its switch in MCP > Settings applies too
 *    (off = hidden from MCP and refused); when the addon is off the tools stay
 *    available as their plugin ships them;
 *  - MCP calls run through att_mcp_dispatch(): activity log, write rate limit,
 *    redaction guard and — for write tools — the change recorder, which
 *    captures every option and post meta value the tool changes (plus
 *    plugin-specific state, see att_mcp_governed_pre_capture()) so that
 *    att/undo-change can restore it.
 *
 * The tool's own permission callback and REST exposure are always kept.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ----- Which abilities, which addon ------------------------------------------ */

/**
 * Governed ability namespaces: name prefix => addon key, from each addon's
 * 'governs' entry ('*' = every other MCP-exposed ability). An addon registered
 * through the att_mcp_addons filter can govern another plugin's tools the same way.
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

/** Would MCP Adapter expose an ability with these args? (An explicit meta.mcp.public wins, else meta.public.) */
function att_mcp_args_mcp_public( $args ) {
    $meta = ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) ? $args['meta'] : array();
    if ( isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) && isset( $meta['mcp']['public'] ) ) {
        return (bool) $meta['mcp']['public'];
    }
    return isset( $meta['public'] ) && true === $meta['public'];
}

/** The addon governing an ability ('' when it is not governed). */
function att_mcp_governing_addon( $name, $args = array() ) {
    $name = (string) $name;
    if ( 0 === strpos( $name, 'att/' ) || 0 === strpos( $name, 'mcp-adapter/' ) ) {
        return '';
    }
    $catch_all = '';
    foreach ( att_mcp_governed_namespaces() as $prefix => $addon ) {
        if ( '*' === $prefix ) {
            $catch_all = $addon;
        } elseif ( '' !== $prefix && 0 === strpos( $name, $prefix ) ) {
            return $addon;
        }
    }
    return ( '' !== $catch_all && att_mcp_args_mcp_public( $args ) ) ? $catch_all : '';
}

/** Is this governed ability a write (from the registry, else its readonly annotation)? */
function att_mcp_governed_access( $name, $args = array() ) {
    $registry = att_mcp_ability_registry();
    if ( isset( $registry[ $name ]['access'] ) ) {
        return $registry[ $name ]['access'];
    }
    return empty( $args['meta']['annotations']['readonly'] ) ? 'write' : 'read';
}

/** Who provides a governed ability, for people ("Yoast SEO", "WordPress", …). */
function att_mcp_ability_provider( $name ) {
    $ns    = strtok( (string) $name, '/' );
    $known = array(
        'rank-math' => 'Rank Math',
        'yoast-seo' => 'Yoast SEO',
        'seopress'  => 'SEOPress',
        'core'      => 'WordPress',
    );
    if ( isset( $known[ $ns ] ) ) {
        return $known[ $ns ];
    }
    if ( 0 === strpos( $ns, 'aioseo' ) ) {
        return 'All in One SEO';
    }
    return ucwords( str_replace( array( '-', '_' ), ' ', $ns ) );
}

/* ----- MCP call context ------------------------------------------------------ */

/**
 * Is an MCP client's tool call running right now? MCP Adapter (0.5+) wraps every
 * tools/call in the mcp_adapter_pre_tool_call / mcp_adapter_tool_call_result
 * filters; older versions are recognised by their /mcp/ REST route.
 *   att_mcp_mcp_context( 'enter' | 'leave' ) — tests and the filters below.
 */
function att_mcp_mcp_context( $action = 'check' ) {
    static $depth = 0;
    if ( 'enter' === $action ) {
        $depth++;
    } elseif ( 'leave' === $action ) {
        $depth = max( 0, $depth - 1 );
    }
    if ( $depth > 0 ) {
        return true;
    }
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
        return 0 === strpos( ltrim( (string) $GLOBALS['wp']->query_vars['rest_route'], '/' ), 'mcp/' );
    }
    return false;
}

/** mcp_adapter_pre_tool_call (runs last, so no later filter can still cancel the call). */
function att_mcp_on_mcp_tool_call( $args ) {
    if ( ! is_wp_error( $args ) ) {
        att_mcp_mcp_context( 'enter' );
    }
    return $args;
}

/** mcp_adapter_tool_call_result. */
function att_mcp_on_mcp_tool_result( $result ) {
    att_mcp_mcp_context( 'leave' );
    return $result;
}

/* ----- Registration ----------------------------------------------------------- */

/**
 * Why a governed ability may not run over MCP right now: array( code, message ),
 * or an empty array when it may.
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
    if ( ! is_array( $args ) || ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
        return $args;
    }
    $addon = att_mcp_governing_addon( $name, $args );
    if ( '' === $addon ) {
        return $args;
    }
    att_mcp_note_governed_ability( $name, $args, $addon );

    $access = att_mcp_governed_access( $name, $args );

    // Clients may omit arguments for tools without required inputs.
    if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) && ! array_key_exists( 'default', $args['input_schema'] )
        && isset( $args['input_schema']['type'] ) && 'object' === $args['input_schema']['type'] && empty( $args['input_schema']['required'] ) ) {
        $args['input_schema']['default'] = array();
    }

    if ( att_mcp_governed_block_reason( $name, $addon, $access ) ) {
        $meta                = ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) ? $args['meta'] : array();
        $meta['mcp']         = array_merge( ( isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ) ? $meta['mcp'] : array(), array( 'public' => false ) );
        $args['meta']        = $meta;
    }

    $original = $args['execute_callback'];
    $record   = 'write' === $access && att_mcp_governed_undoable( $name );
    $args['execute_callback'] = function ( $input = null ) use ( $name, $original, $addon, $access, $record ) {
        if ( ! att_mcp_mcp_context() ) {
            // The plugin's own (non-MCP) use of its tool is not ours to govern.
            return null === $input ? call_user_func( $original ) : call_user_func( $original, $input );
        }
        $blocked = att_mcp_governed_block_reason( $name, $addon, $access );
        if ( $blocked ) {
            return new WP_Error( $blocked[0], $blocked[1] );
        }
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
 * State a governed write tool changes that the change recorder cannot see (a
 * plugin's own table), captured before the call. History items.
 */
function att_mcp_governed_pre_capture( $name, $input ) {
    if ( 'aioseo-posts/seo-data-update' === $name && ! empty( $input['postId'] ) && function_exists( 'att_mcp_capture_aioseo_post' ) ) {
        return array_filter( array( att_mcp_capture_aioseo_post( (int) $input['postId'] ) ) );
    }
    return array();
}

/**
 * Remember governed tools this plugin has no built-in metadata for (other
 * plugins' tools, or tools a newer Rank Math adds), so MCP > Settings can list
 * them. They start off whenever their addon is enabled.
 */
function att_mcp_note_governed_ability( $name, $args, $addon ) {
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
        'addon'       => $addon,
    );
    if ( ! isset( $seen[ $name ] ) || $seen[ $name ] !== $entry ) {
        $seen[ $name ] = $entry;
        update_option( 'att_mcp_seen_abilities', $seen );
    }
}

/** Registry entries for remembered tools of the catch-all "Other MCP tools" addon. */
function att_mcp_other_mcp_registry() {
    $out  = array();
    $seen = get_option( 'att_mcp_seen_abilities', array() );
    foreach ( is_array( $seen ) ? $seen : array() as $name => $tool ) {
        if ( ! is_array( $tool ) || ! isset( $tool['addon'] ) || 'other_mcp' !== $tool['addon'] ) {
            continue;
        }
        $label       = isset( $tool['label'] ) ? (string) $tool['label'] : (string) $name;
        $description = isset( $tool['description'] ) ? (string) $tool['description'] : '';
        $read        = ! empty( $tool['readonly'] );
        $out[ $name ] = att_mcp_registry_entry(
            $label,
            trim( $description . ' (' . att_mcp_ability_provider( $name ) . ')' ),
            'Other MCP Tools',
            $read ? 'read' : 'write',
            $read
        );
    }
    return $out;
}

/** Is there a remembered tool for the "Other MCP tools" addon? (Its detection.) */
function att_mcp_detect_other_mcp() {
    $seen = get_option( 'att_mcp_seen_abilities', array() );
    foreach ( is_array( $seen ) ? $seen : array() as $tool ) {
        if ( is_array( $tool ) && isset( $tool['addon'] ) && 'other_mcp' === $tool['addon'] ) {
            return true;
        }
    }
    return false;
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
        if ( att_mcp_capture_changed( $item ) ) {
            $changed[] = $item;
        }
    }
    return $changed;
}

/** Does the current state differ from a captured item? */
function att_mcp_capture_changed( $item ) {
    $now = att_mcp_capture( $item['type'], $item['target'] );
    if ( ! $now ) {
        return false;
    }
    unset( $now['stylesheet'], $item['stylesheet'] );
    return maybe_serialize( $now ) !== maybe_serialize( $item );
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
