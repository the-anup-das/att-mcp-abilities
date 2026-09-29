<?php
/**
 * Shared helpers for ability modules: common ability config, input clamping,
 * post-status/capability resolution, content preparation, SSRF-safe fetching,
 * internal REST calls, and the protected option/meta lists.
 *
 * MCP inputs arrive as decoded JSON — they are NOT magic-quoted — so values are
 * never wp_unslash()ed. Anything handed to a core API that expects slashed data
 * (wp_insert_post, wp_update_post, update_post_meta) is wp_slash()ed instead.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Base config shared by every ability.
 *
 * - `public` (WP 7.1+) and `mcp.public` (MCP Adapter) mark the ability as meant
 *   for AI clients.
 * - `show_in_rest` stays false: core's REST run endpoint relies only on each
 *   ability's permission callback, so the public reads would become callable
 *   anonymously there. Sites that want REST exposure can opt in with the
 *   `att_mcp_show_in_rest` filter.
 */
function att_mcp_ability_base() {
    return array(
        'category'      => 'att',
        'output_schema' => array( 'type' => 'object' ),
        'meta'          => array(
            'public'       => true,
            'show_in_rest' => (bool) apply_filters( 'att_mcp_show_in_rest', false ),
            'mcp'          => array( 'public' => true ),
        ),
    );
}

/** Read an integer input clamped to [$min, $max]. */
function att_mcp_int_arg( $input, $key, $default, $min = 1, $max = 100 ) {
    $value = isset( $input[ $key ] ) ? (int) $input[ $key ] : (int) $default;
    return max( $min, min( $max, $value ) );
}

/* ----- Post types, statuses, content --------------------------------------- */

/**
 * Post types an agent may never touch through the generic content tools
 * (internal types, or types with a dedicated, safer ability).
 */
function att_mcp_blocked_post_types() {
    return apply_filters( 'att_mcp_blocked_post_types', array(
        'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request',
        'wp_global_styles', 'wp_template', 'wp_template_part', 'custom_css',
    ) );
}

/**
 * Post types whose content is executable code (snippet/code plugins). Editing
 * them is running code, so they are only reachable when the admin-only
 * "Allow PHP" control is on.
 */
function att_mcp_code_post_types() {
    return apply_filters( 'att_mcp_code_post_types', array(
        'wpcode', 'wbcr-snippets', 'custom-css-js', 'elementor_snippet',
    ) );
}

/** Resolve a post type the agent may manage; WP_Error otherwise. */
function att_mcp_get_editable_post_type( $post_type ) {
    $post_type = sanitize_key( (string) $post_type );
    $pto       = $post_type ? get_post_type_object( $post_type ) : null;
    if ( ! $pto ) {
        return new WP_Error( 'att_mcp_bad_post_type', sprintf( 'Unknown post type "%s".', $post_type ) );
    }
    if ( in_array( $post_type, att_mcp_blocked_post_types(), true ) || ( ! $pto->show_ui && 'attachment' !== $post_type ) ) {
        return new WP_Error( 'att_mcp_post_type_blocked', sprintf( 'Post type "%s" cannot be managed with this tool.', $post_type ) );
    }
    if ( in_array( $post_type, att_mcp_code_post_types(), true ) && ! ( att_mcp_php_snippets_allowed() && current_user_can( 'unfiltered_html' ) ) ) {
        return new WP_Error( 'att_mcp_php_blocked', sprintf( 'Post type "%s" stores executable code. The site administrator must turn on "Allow PHP" in MCP > Settings first.', $post_type ) );
    }
    return $pto;
}

/**
 * Validate a requested post status and check the capability it needs.
 * Publishing (publish/future/private) requires the post type's publish cap
 * unless the post already has that status.
 */
function att_mcp_resolve_status( $status, $pto, $current_status = '' ) {
    $status  = sanitize_key( (string) $status );
    $allowed = array( 'publish', 'draft', 'pending', 'private', 'future' );
    if ( ! in_array( $status, $allowed, true ) ) {
        return new WP_Error( 'att_mcp_bad_status', 'Status must be one of: ' . implode( ', ', $allowed ) . '.' );
    }
    if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && $status !== $current_status && ! current_user_can( $pto->cap->publish_posts ) ) {
        return new WP_Error( 'att_mcp_cannot_publish', 'You are not allowed to publish this type of content. Save it as draft or pending instead.' );
    }
    return $status;
}

/** Post content: stored as-is for users with unfiltered_html, KSES-filtered otherwise (same rule as core). */
function att_mcp_prepare_content( $html ) {
    $html = (string) $html;
    return current_user_can( 'unfiltered_html' ) ? $html : wp_kses_post( $html );
}

/** Recursively KSES every string in a value (used for users without unfiltered_html). */
function att_mcp_kses_deep( $value ) {
    if ( is_array( $value ) ) {
        return array_map( 'att_mcp_kses_deep', $value );
    }
    return is_string( $value ) ? wp_kses_post( $value ) : $value;
}

/** Filter free-form structured data (meta, builder settings) for users without unfiltered_html. */
function att_mcp_filter_untrusted( $value ) {
    return current_user_can( 'unfiltered_html' ) ? $value : att_mcp_kses_deep( $value );
}

/* ----- Protected options & meta ------------------------------------------- */

/**
 * Shared secret-key pattern (see also att_mcp_is_secret_name() in dispatch.php).
 * Also covers names like cf_apitoken (Cloudflare), object-pswd (LiteSpeed object
 * cache) and sk_b64 (the QUIC.cloud private key in LiteSpeed's cloud summary).
 */
function att_mcp_secret_pattern() {
    return '/(secret|password|passwd|pswd|_pwd|(^|[_-])pass$|_key$|_token|apitoken|token$|_salt|nonce|smtp|private[_-]?key|client[_-]?secret|api[_-]?key|auth[_-]?key|license|credential|sk_b64)/i';
}

/**
 * Options that may not be read or written over MCP: core/structural keys,
 * secret-like names, transients, and this plugin's own controls (an agent must
 * never be able to switch its own guardrails back on).
 */
function att_mcp_is_protected_option( $name ) {
    global $wpdb;
    $name  = strtolower( (string) $name );
    $exact = array(
        'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet',
        'siteurl', 'home', 'admin_email', 'new_admin_email', 'db_version', 'initial_db_version',
        'cron', 'rewrite_rules', 'user_roles', 'wp_user_roles', strtolower( $wpdb->prefix . 'user_roles' ),
        'users_can_register', 'default_role', 'upload_path', 'upload_url_path',
        'mailserver_url', 'mailserver_login', 'mailserver_pass',
        'auth_key', 'auth_salt', 'recovery_keys', 'recovery_mode_email_last_sent',
        'uninstall_plugins', 'recently_activated', 'wp_force_deactivated_plugins', 'can_compress_scripts',
    );
    if ( in_array( $name, $exact, true ) ) {
        return true;
    }
    // Prefixes: transients, this plugin's own controls, and the auto-update settings.
    foreach ( array( '_transient_', '_site_transient_', 'att_mcp_', 'wsp_mcp_', 'auto_update_' ) as $prefix ) {
        if ( 0 === strpos( $name, $prefix ) ) {
            return true;
        }
    }
    return (bool) preg_match( att_mcp_secret_pattern(), $name );
}

/**
 * Post meta keys the generic content tools must never write (file paths that
 * core later deletes, locks/trash bookkeeping, secrets, this plugin's own keys).
 */
function att_mcp_is_blocked_meta_key( $key ) {
    $key     = (string) $key;
    $blocked = array(
        '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes',
        '_edit_lock', '_edit_last', '_wp_trash_meta_status', '_wp_trash_meta_time',
        '_wp_desired_post_slug', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme',
    );
    if ( '' === $key || in_array( $key, $blocked, true ) || 0 === strpos( $key, '_att_mcp' ) ) {
        return true;
    }
    return (bool) preg_match( att_mcp_secret_pattern(), $key );
}

/** Can the current user write this meta key on this post? */
function att_mcp_can_write_meta( $post_id, $key ) {
    if ( att_mcp_is_blocked_meta_key( $key ) || ! current_user_can( 'edit_post', $post_id ) ) {
        return false;
    }
    // Protected (underscore) keys hold theme/builder settings. Administrators may
    // set them; everyone else goes through core's per-key meta capability.
    if ( is_protected_meta( $key, 'post' ) && ! current_user_can( 'manage_options' ) ) {
        return current_user_can( 'edit_post_meta', $post_id, $key );
    }
    return true;
}

/* ----- SSRF-safe outbound fetching ---------------------------------------- */

/** True when a URL points at a public http(s) host (every resolved IP is public). */
function att_mcp_url_is_public( $url ) {
    $parts = wp_parse_url( (string) $url );
    if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
        return false;
    }
    $host = strtolower( trim( $parts['host'], '[]' ) );
    if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) ) {
        return false;
    }
    $ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : gethostbynamel( $host );
    if ( empty( $ips ) ) {
        return false;
    }
    foreach ( $ips as $ip ) {
        // Rejects loopback, private, link-local (incl. cloud metadata 169.254.x) and reserved ranges.
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return false;
        }
    }
    return (bool) wp_http_validate_url( $url );
}

/**
 * GET a public URL, re-validating every redirect hop against the SSRF guard.
 * Returns the wp_remote_* response array or WP_Error.
 */
function att_mcp_safe_fetch( $url, $max_bytes = 5242880 ) {
    $url = esc_url_raw( trim( (string) $url ) );
    if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
        return new WP_Error( 'att_mcp_bad_url', 'Provide a valid http(s) URL.' );
    }
    for ( $hop = 0; $hop <= 3; $hop++ ) {
        if ( ! att_mcp_url_is_public( $url ) ) {
            return new WP_Error( 'att_mcp_blocked_url', 'Refusing to fetch an internal, private, or reserved address.' );
        }
        $resp = wp_safe_remote_get( $url, array(
            'timeout'             => 20,
            'redirection'         => 0,
            'limit_response_size' => (int) $max_bytes,
        ) );
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code >= 300 && $code < 400 ) {
            $location = wp_remote_retrieve_header( $resp, 'location' );
            if ( ! $location ) {
                return $resp;
            }
            $url = WP_Http::make_absolute_url( is_array( $location ) ? end( $location ) : $location, $url );
            continue;
        }
        return $resp;
    }
    return new WP_Error( 'att_mcp_too_many_redirects', 'Too many redirects.' );
}

/* ----- Fetching this site's own pages -------------------------------------- */

/** Is the URL on this site's own host? */
function att_mcp_is_own_url( $url ) {
    $site_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
    $target_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    return '' !== $site_host && $site_host === $target_host && (bool) preg_match( '#^https?://#i', $url );
}

/**
 * Fetch a page of THIS site. $target is a post (WP_Post or ID) or an on-site URL.
 *
 * Unpublished, private and password-protected posts are fetched as a logged-in
 * preview for the current user: a 2-minute login session sent only to this
 * site's own host and destroyed as soon as the page has been fetched. Same-host
 * redirects are followed (max 3); off-site redirects are never followed.
 *
 * Returns array( url, status, headers, body, preview, ms ) or WP_Error.
 */
function att_mcp_fetch_own_page( $target, $max_bytes = 1048576 ) {
    $url     = '';
    $as_user = false;
    if ( $target instanceof WP_Post || ( is_numeric( $target ) && (int) $target > 0 ) ) {
        $post = get_post( $target );
        if ( ! $post ) {
            return new WP_Error( 'att_mcp_no_post', 'No post/page found for that id.' );
        }
        if ( 'publish' === $post->post_status && is_post_publicly_viewable( $post ) && ! post_password_required( $post ) ) {
            $url = get_permalink( $post );
        } else {
            if ( ! current_user_can( 'edit_post', $post->ID ) ) {
                return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to preview this item.' );
            }
            $url     = get_preview_post_link( $post );
            $as_user = true;
        }
    } elseif ( is_string( $target ) && '' !== $target ) {
        $url = esc_url_raw( $target );
    }

    if ( empty( $url ) ) {
        return new WP_Error( 'att_mcp_no_target', 'Provide a post "id" or an on-site "url".' );
    }
    // SSRF guard: only this site.
    if ( ! att_mcp_is_own_url( $url ) ) {
        return new WP_Error( 'att_mcp_offsite', 'URL must be on this site. Use att/fetch-url for other sites.' );
    }

    $args  = array( 'timeout' => 20, 'redirection' => 0, 'limit_response_size' => (int) $max_bytes );
    $token = '';
    if ( $as_user ) {
        $user_id         = get_current_user_id();
        $expiration      = time() + 2 * MINUTE_IN_SECONDS;
        $sessions        = WP_Session_Tokens::get_instance( $user_id );
        $token           = $sessions->create( $expiration );
        $args['cookies'] = array( LOGGED_IN_COOKIE => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ) );
    }

    $resp  = null;
    $start = microtime( true );
    for ( $hop = 0; $hop <= 3; $hop++ ) {
        $resp = wp_safe_remote_get( $url, $args );
        if ( is_wp_error( $resp ) ) {
            break;
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        $next = ( $code >= 300 && $code < 400 ) ? wp_remote_retrieve_header( $resp, 'location' ) : '';
        $next = is_array( $next ) ? end( $next ) : $next;
        if ( ! $next ) {
            break;
        }
        $next = WP_Http::make_absolute_url( $next, $url );
        if ( ! att_mcp_is_own_url( $next ) ) {
            break; // never follow (or send the preview cookie) off-site
        }
        $url = $next;
    }
    $ms = (int) round( ( microtime( true ) - $start ) * 1000 );
    if ( $token ) {
        $sessions->destroy( $token );
    }
    if ( is_wp_error( $resp ) ) {
        return $resp;
    }

    $headers = wp_remote_retrieve_headers( $resp );
    $headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
    return array(
        'url'     => $url,
        'status'  => (int) wp_remote_retrieve_response_code( $resp ),
        'headers' => array_change_key_case( $headers, CASE_LOWER ),
        'body'    => (string) wp_remote_retrieve_body( $resp ),
        'preview' => $as_user,
        'ms'      => $ms,
    );
}

/** Last value of a response header (headers may repeat). */
function att_mcp_header( $headers, $name ) {
    $name = strtolower( $name );
    if ( ! isset( $headers[ $name ] ) ) {
        return '';
    }
    $value = $headers[ $name ];
    return is_array( $value ) ? (string) end( $value ) : (string) $value;
}

/* ----- Internal REST calls ------------------------------------------------- */

/**
 * Dispatch a REST request internally as the current user. Core's controllers
 * enforce their own capability checks. Returns array( status, data, headers )
 * or WP_Error.
 */
function att_mcp_rest( $method, $route, $params = array() ) {
    $method  = strtoupper( (string) $method );
    $request = new WP_REST_Request( $method, $route );
    if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
        $request->set_query_params( (array) $params );
    } else {
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( (object) $params ) );
    }
    $response = rest_do_request( $request );
    if ( $response->is_error() ) {
        return $response->as_error();
    }
    $headers = array();
    foreach ( array( 'X-WP-Total', 'X-WP-TotalPages' ) as $h ) {
        $all = $response->get_headers();
        if ( isset( $all[ $h ] ) ) {
            $headers[ $h ] = $all[ $h ];
        }
    }
    return array(
        'status'  => $response->get_status(),
        'data'    => rest_get_server()->response_to_data( $response, false ),
        'headers' => $headers,
    );
}
