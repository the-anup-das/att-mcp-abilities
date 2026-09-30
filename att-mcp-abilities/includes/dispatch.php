<?php
/**
 * Execution dispatch layer — the single seam every ability flows through.
 *
 * att_mcp_register() wraps wp_register_ability() so each execute_callback is
 * routed through att_mcp_dispatch(), which:
 *   - enforces the read-only safety valve (pause-writes),
 *   - fires att_mcp_before_execute / att_mcp_after_execute action hooks,
 *   - converts uncaught exceptions into WP_Error (so they are still audited),
 *   - writes an audit-log entry (inputs redacted),
 *   - deep-redacts secret-looking values from the returned payload.
 *
 * Other plugins' abilities in a governed namespace (governance.php) flow
 * through here too, with their writes recorded for undo.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ----- Registration wrapper ----------------------------------------------- */

function att_mcp_register( $key, $args ) {
    // MCP tool hints (readOnlyHint / destructiveHint / idempotentHint) from the registry's access level.
    $registry = att_mcp_ability_registry();
    $read     = isset( $registry[ $key ]['access'] ) && 'read' === $registry[ $key ]['access'];
    $args['meta']['annotations'] = array_merge(
        array(
            'readonly'    => $read,
            'destructive' => ! $read && (bool) preg_match( '/(delete|remove|manage-|rest-write|undo|restore|save-page|update-option|replace)/', $key ),
            'idempotent'  => $read,
        ),
        isset( $args['meta']['annotations'] ) && is_array( $args['meta']['annotations'] ) ? $args['meta']['annotations'] : array()
    );

    // Clients may omit arguments for tools without required inputs; core validates a
    // missing input against the object schema, so default it to an empty object.
    if ( isset( $args['input_schema'] ) && is_array( $args['input_schema'] ) && ! array_key_exists( 'default', $args['input_schema'] ) ) {
        $args['input_schema']['default'] = array();
    }

    if ( isset( $args['execute_callback'] ) ) {
        $original = $args['execute_callback'];
        $args['execute_callback'] = function ( $input = array() ) use ( $key, $original ) {
            return att_mcp_dispatch( $key, $original, $input );
        };
    }
    return wp_register_ability( $key, $args );
}

/**
 * Run one ability call. $opts (for governed abilities of other plugins):
 *   access  'read' | 'write' — overrides the registry's access level,
 *   record  true — capture every option/post meta change for undo.
 */
function att_mcp_dispatch( $key, $callback, $input, $opts = array() ) {
    $registry = function_exists( 'att_mcp_ability_registry' ) ? att_mcp_ability_registry() : array();
    $access   = isset( $opts['access'] ) ? $opts['access'] : ( isset( $registry[ $key ]['access'] ) ? $registry[ $key ]['access'] : 'read' );
    $input    = is_array( $input ) ? $input : array();

    // Kill switch + read-only safety valve (registration is also gated; this is defense in depth).
    if ( ! att_mcp_is_active() ) {
        return new WP_Error( 'att_mcp_disabled', 'MCP access is turned off by the site administrator.' );
    }
    if ( 'write' === $access && att_mcp_writes_paused() ) {
        return new WP_Error( 'att_mcp_writes_paused', 'MCP writes are paused (read-only mode) by the site administrator.' );
    }
    if ( 'write' === $access ) {
        $limited = att_mcp_rate_limit_hit();
        if ( is_wp_error( $limited ) ) {
            att_mcp_audit_log( $key, $access, $input, $limited, 0 );
            return $limited;
        }
        // A redaction placeholder written back would overwrite the real secret.
        // Only abilities that restore the stored value (att_mcp_unredact_deep) may receive one.
        if ( ! in_array( $key, att_mcp_unredacting_abilities(), true ) && att_mcp_contains_redacted( $input ) ) {
            $error = att_mcp_redacted_error();
            att_mcp_audit_log( $key, $access, $input, $error, 0 );
            return $error;
        }
    }

    do_action( 'att_mcp_before_execute', $key, $input, $access );

    att_mcp_current_ability( $key );
    $recording = ! empty( $opts['record'] ) && 'write' === $access && function_exists( 'att_mcp_recorder_start' );
    if ( $recording ) {
        att_mcp_recorder_start();
    }
    $start = microtime( true );
    try {
        $result = call_user_func( $callback, $input );
    } catch ( \Throwable $e ) {
        $result = new WP_Error( 'att_mcp_exception', 'The ability failed: ' . $e->getMessage() );
    }
    $ms = (int) round( ( microtime( true ) - $start ) * 1000 );
    if ( $recording ) {
        // Whatever the outcome, anything that changed can be undone.
        $changed = att_mcp_recorder_stop();
        if ( $changed ) {
            att_mcp_record_change( $changed, isset( $registry[ $key ]['label'] ) ? $registry[ $key ]['label'] : $key );
        }
    }
    att_mcp_current_ability( '' );

    // Record the true result, then redact what we hand back to the agent.
    att_mcp_audit_log( $key, $access, $input, $result, $ms );
    do_action( 'att_mcp_after_execute', $key, $input, $result, $access );

    return is_wp_error( $result ) ? $result : att_mcp_redact_deep( $result );
}

/* ----- Controls ------------------------------------------------------------ */

function att_mcp_get_controls() {
    $saved = get_option( ATT_MCP_CONTROLS_OPTION, array() );
    $saved = is_array( $saved ) ? $saved : array();
    return array(
        'active'        => isset( $saved['active'] ) ? (bool) $saved['active'] : true,
        'writes_paused' => ! empty( $saved['writes_paused'] ),
        'audit'         => isset( $saved['audit'] ) ? (bool) $saved['audit'] : true,
        'allow_php'     => ! empty( $saved['allow_php'] ),
        'rate_limit'    => isset( $saved['rate_limit'] ) ? max( 0, (int) $saved['rate_limit'] ) : 60,
        'notify'        => ! empty( $saved['notify'] ),
    );
}

function att_mcp_is_active()      { $c = att_mcp_get_controls(); return ! empty( $c['active'] ); }
function att_mcp_writes_paused()  { $c = att_mcp_get_controls(); return ! empty( $c['writes_paused'] ); }
function att_mcp_audit_enabled()  { $c = att_mcp_get_controls(); return ! empty( $c['audit'] ); }

/**
 * Admin-side gate for anything that runs PHP (PHP Code Snippets, GeneratePress
 * hook Elements with "Execute PHP"). An agent cannot grant this to itself, and
 * it is always off when the site disallows file editing.
 */
function att_mcp_php_snippets_allowed() {
    if ( ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'ATT_MCP_DISALLOW_PHP' ) && ATT_MCP_DISALLOW_PHP ) ) {
        return false;
    }
    $c = att_mcp_get_controls();
    return ! empty( $c['allow_php'] );
}

function att_mcp_sanitize_controls( $input ) {
    $input = is_array( $input ) ? $input : array();
    return array(
        'active'        => ! empty( $input['active'] ),
        'writes_paused' => ! empty( $input['writes_paused'] ),
        'audit'         => ! empty( $input['audit'] ),
        'allow_php'     => ! empty( $input['allow_php'] ),
        'rate_limit'    => isset( $input['rate_limit'] ) ? max( 0, min( 1000, (int) $input['rate_limit'] ) ) : 60,
        'notify'        => ! empty( $input['notify'] ),
    );
}

/* ----- Rate limiting -------------------------------------------------------- */

/**
 * Per-user limit on write calls per minute (MCP Controls; 0 = unlimited).
 * Stops a runaway or hijacked agent from making hundreds of changes quickly.
 */
function att_mcp_rate_limit_hit() {
    $c     = att_mcp_get_controls();
    $limit = (int) apply_filters( 'att_mcp_write_rate_limit', $c['rate_limit'] );
    if ( $limit <= 0 ) {
        return false;
    }
    $bucket = 'att_mcp_rl_' . get_current_user_id() . '_' . (int) floor( time() / 60 );
    $count  = (int) get_transient( $bucket );
    if ( $count >= $limit ) {
        return new WP_Error( 'att_mcp_rate_limited', sprintf( 'Write limit reached (%d changes per minute). Wait a minute and retry; the site administrator can raise the limit in MCP > Settings.', $limit ) );
    }
    set_transient( $bucket, $count + 1, 2 * MINUTE_IN_SECONDS );
    return false;
}

/* ----- Daily email digest --------------------------------------------------- */

/** Keep the daily digest event in sync with the "notify" control. */
function att_mcp_sync_digest_schedule() {
    $c         = att_mcp_get_controls();
    $scheduled = wp_next_scheduled( 'att_mcp_daily_digest' );
    if ( $c['notify'] && ! $scheduled ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'att_mcp_daily_digest' );
    } elseif ( ! $c['notify'] && $scheduled ) {
        wp_clear_scheduled_hook( 'att_mcp_daily_digest' );
    }
}

/** Cron callback: email the site admin a summary of the last day's MCP write calls. */
function att_mcp_send_daily_digest() {
    global $wpdb;
    $c = att_mcp_get_controls();
    if ( ! $c['notify'] ) {
        return;
    }
    $since = (string) get_option( 'att_mcp_digest_last', '' );
    if ( '' === $since ) {
        $since = wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ); // audit rows store site-local time
    }
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE access = 'write' AND created > %s ORDER BY id ASC LIMIT 200", att_mcp_audit_table(), $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    update_option( 'att_mcp_digest_last', current_time( 'mysql' ), false );
    if ( empty( $rows ) ) {
        return;
    }

    $lines = array();
    foreach ( $rows as $r ) {
        $user    = $r->user_id ? get_userdata( (int) $r->user_id ) : null;
        $lines[] = sprintf( '%s  %-6s  %-34s  %s  %s', $r->created, $r->status, $r->ability, $user ? $user->user_login : '#' . (int) $r->user_id, $r->summary );
    }
    $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    /* translators: 1: site name, 2: number of changes */
    $subject = sprintf( __( '[%1$s] MCP activity: %2$d write calls by AI agents', 'att-mcp-abilities' ), $site, count( $rows ) );
    $body    = __( 'AI agents connected through ATT MCP Abilities made these write calls since the last summary:', 'att-mcp-abilities' ) . "\n\n"
        . implode( "\n", $lines ) . "\n\n"
        . __( 'Review everything in MCP > Activity:', 'att-mcp-abilities' ) . ' ' . admin_url( 'admin.php?page=att-mcp-activity' ) . "\n"
        . __( 'Turn these emails off in MCP > Settings > MCP Controls.', 'att-mcp-abilities' );
    wp_mail( get_option( 'admin_email' ), $subject, $body );
}

/* ----- Secret redaction ---------------------------------------------------- */

function att_mcp_is_secret_name( $name ) {
    return (bool) preg_match( att_mcp_secret_pattern(), (string) $name );
}

/** The placeholder that replaces secret values in everything returned to an agent. */
function att_mcp_redaction_marker() {
    return '***redacted***';
}

/**
 * Recursively mask values whose KEY looks secret (safe: only secret-named keys).
 * Only non-empty strings are masked: booleans and numbers are not secrets, and
 * keeping their type keeps results valid against strict output schemas.
 */
function att_mcp_redact_deep( $data, $depth = 0 ) {
    if ( $depth > 12 || ! is_array( $data ) ) {
        return $data;
    }
    $out = array();
    foreach ( $data as $k => $v ) {
        if ( is_string( $k ) && is_string( $v ) && '' !== $v && att_mcp_is_secret_name( $k ) ) {
            $out[ $k ] = att_mcp_redaction_marker();
        } elseif ( is_array( $v ) ) {
            $out[ $k ] = att_mcp_redact_deep( $v, $depth + 1 );
        } else {
            $out[ $k ] = $v;
        }
    }
    return $out;
}

/** Does a value contain the redaction placeholder anywhere? */
function att_mcp_contains_redacted( $data, $depth = 0 ) {
    if ( is_string( $data ) ) {
        return false !== strpos( $data, att_mcp_redaction_marker() );
    }
    if ( ! is_array( $data ) || $depth > 24 ) {
        return false;
    }
    foreach ( $data as $v ) {
        if ( att_mcp_contains_redacted( $v, $depth + 1 ) ) {
            return true;
        }
    }
    return false;
}

/**
 * An agent that read a structure (redacted) and writes it back sends the
 * placeholder where a secret was. Put the stored value back at those paths.
 */
function att_mcp_unredact_deep( $new, $old, $depth = 0 ) {
    if ( $new === att_mcp_redaction_marker() ) {
        return is_scalar( $old ) ? $old : $new;
    }
    if ( ! is_array( $new ) || ! is_array( $old ) || $depth > 24 ) {
        return $new;
    }
    foreach ( $new as $k => $v ) {
        if ( array_key_exists( $k, $old ) ) {
            $new[ $k ] = att_mcp_unredact_deep( $v, $old[ $k ], $depth + 1 );
        }
    }
    return $new;
}

/** Write abilities that restore redacted values from the stored structure before writing. */
function att_mcp_unredacting_abilities() {
    return array( 'att/update-option', 'att/set-theme-mod', 'att/update-generatepress-settings', 'att/elementor-update-element', 'att/elementor-update-kit' );
}

function att_mcp_redacted_error() {
    return new WP_Error( 'att_mcp_redacted_value', 'The input contains "' . att_mcp_redaction_marker() . '", a placeholder for a secret this server never reveals. Writing it would destroy the real value: send the real value, or leave that key out.' );
}

/* ----- Audit log ----------------------------------------------------------- */

function att_mcp_audit_table() {
    global $wpdb;
    return $wpdb->prefix . 'att_mcp_audit';
}

function att_mcp_create_audit_table() {
    global $wpdb;
    $table   = att_mcp_audit_table();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        created DATETIME NOT NULL,
        ability VARCHAR(100) NOT NULL,
        access VARCHAR(10) NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL,
        duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
        inputs LONGTEXT NULL,
        summary TEXT NULL,
        PRIMARY KEY  (id),
        KEY created (created),
        KEY ability (ability)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/** One-time migration of legacy `wsp_`-prefixed options/table (pre-1.8.0 rename). */
function att_mcp_migrate_legacy() {
    $opts = array(
        'wsp_mcp_abilities' => ATT_MCP_OPTION,
        'wsp_mcp_addons'    => ATT_MCP_ADDONS_OPTION,
        'wsp_mcp_controls'  => ATT_MCP_CONTROLS_OPTION,
    );
    foreach ( $opts as $old => $new ) {
        $legacy = get_option( $old, null );
        if ( null !== $legacy && false === get_option( $new, false ) ) {
            update_option( $new, $legacy ); // carry the old settings forward
        }
        delete_option( $old );
    }
    delete_option( 'wsp_mcp_db_version' );

    // Rename the audit table if a legacy one exists and the new one doesn't.
    global $wpdb;
    $old_t   = $wpdb->prefix . 'wsp_mcp_audit';
    $new_t   = att_mcp_audit_table();
    $has_old = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $has_new = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $has_old && ! $has_new ) {
        $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $old_t, $new_t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
    }
}

/** Activation: create tables and sync the digest schedule. */
function att_mcp_activate() {
    att_mcp_create_audit_table();
    att_mcp_create_changes_table();
    update_option( 'att_mcp_db_version', ATT_MCP_VERSION );
}

/** Deactivation: remove scheduled events (data is kept until uninstall). */
function att_mcp_deactivate() {
    wp_clear_scheduled_hook( 'att_mcp_daily_digest' );
}

/** Create/upgrade the tables once per version (covers file-overwrite updates). */
function att_mcp_maybe_upgrade() {
    if ( get_option( 'att_mcp_db_version' ) !== ATT_MCP_VERSION ) {
        att_mcp_migrate_legacy();
        att_mcp_create_audit_table();
        att_mcp_create_changes_table();
        update_option( 'att_mcp_db_version', ATT_MCP_VERSION );
    }
    att_mcp_sync_digest_schedule();
}

/** Classify a result as ok/error and build a one-line summary. */
function att_mcp_audit_describe( $result ) {
    if ( is_wp_error( $result ) ) {
        return array( 'error', $result->get_error_code() . ': ' . $result->get_error_message() );
    }
    if ( is_array( $result ) ) {
        // Legacy/soft failures: array( 'success' => false, 'error' => '…' ).
        if ( isset( $result['success'] ) && false === $result['success'] ) {
            return array( 'error', isset( $result['error'] ) && is_scalar( $result['error'] ) ? (string) $result['error'] : 'failed' );
        }
        // Other plugins' tools (e.g. Rank Math) report failures as array( 'error' => array( code, message ) ).
        if ( ! empty( $result['error'] ) && ( is_string( $result['error'] ) || is_array( $result['error'] ) ) ) {
            $error = $result['error'];
            if ( is_array( $error ) ) {
                $error = ( isset( $error['code'] ) ? $error['code'] . ': ' : '' ) . ( isset( $error['message'] ) ? $error['message'] : wp_json_encode( $error ) );
            }
            return array( 'error', (string) $error );
        }
        return array( 'ok', 'keys: ' . implode( ', ', array_slice( array_keys( $result ), 0, 12 ) ) );
    }
    return array( 'ok', is_scalar( $result ) ? (string) $result : gettype( $result ) );
}

function att_mcp_audit_log( $key, $access, $input, $result, $ms = 0 ) {
    if ( ! att_mcp_audit_enabled() ) {
        return;
    }
    global $wpdb;

    list( $status, $summary ) = att_mcp_audit_describe( $result );

    $inputs_json = wp_json_encode( att_mcp_redact_deep( is_array( $input ) ? $input : array() ) );
    if ( strlen( (string) $inputs_json ) > 4000 ) {
        $inputs_json = substr( $inputs_json, 0, 4000 ) . '…';
    }

    $wpdb->insert( att_mcp_audit_table(), array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        'created'     => current_time( 'mysql' ),
        'ability'     => substr( (string) $key, 0, 100 ),
        'access'      => substr( (string) $access, 0, 10 ),
        'user_id'     => get_current_user_id(),
        'status'      => $status,
        'duration_ms' => (int) $ms,
        'inputs'      => $inputs_json,
        'summary'     => substr( (string) $summary, 0, 1000 ),
    ) );

    att_mcp_audit_prune( 500 );
}

function att_mcp_audit_prune( $keep ) {
    global $wpdb;
    $table  = att_mcp_audit_table();
    $max_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ( $max_id > $keep ) {
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $table, $max_id - $keep ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }
}

function att_mcp_audit_recent( $limit = 200 ) {
    global $wpdb;
    $limit = max( 1, min( 500, (int) $limit ) );
    return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', att_mcp_audit_table(), $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

function att_mcp_audit_clear() {
    global $wpdb;
    $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', att_mcp_audit_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/** admin-post handler: MCP > Activity "Clear log" button. */
function att_mcp_handle_clear_audit() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to do this.', 'att-mcp-abilities' ), 403 );
    }
    check_admin_referer( 'att_mcp_clear_audit' );
    att_mcp_audit_clear();
    wp_safe_redirect( add_query_arg( 'cleared', '1', admin_url( 'admin.php?page=att-mcp-activity' ) ) );
    exit;
}
