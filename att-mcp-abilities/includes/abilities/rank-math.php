<?php
/**
 * Rank Math SEO.
 *
 * Rank Math registers 29 MCP tools of its own on the Abilities API
 * (rank-math/…: site audit and automatic fixes, post analysis, SEO scores,
 * settings, sitemaps, links, redirections and 404 log reads, Search Console
 * keywords, AI Visibility). governance.php puts them under the MCP Controls;
 * this file describes them for MCP > Settings and adds the write tools Rank
 * Math's set lacks, so an agent can act on what its audit finds:
 *
 *  att/rank-math-save-redirection    create or edit a redirection (e.g. for a 404 or a changed slug)
 *  att/rank-math-delete-redirections trash or delete redirections
 *  att/rank-math-clear-404-logs      clear 404 log entries once they are fixed
 *
 * Per-post fixes (SEO title, description, focus keyword, canonical, noindex)
 * go through att/update-seo-meta and att/bulk-update-seo-meta; content through
 * att/update-post / att/update-content; image alt text through att/update-media.
 *
 * All changes go through Rank Math's own classes (\RankMath\Redirections\
 * Redirection and DB, \RankMath\Monitor\DB) and redirection changes are undoable
 * (history type rank_math_redirection).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Rank Math's own tools, for MCP > Settings: name => array( label, description,
 * access, undo ). 'undo' => false for tools whose changes are not restorable
 * here (auto-update settings live in a protected core option; AI Visibility
 * brands live on Rank Math's servers).
 */
function att_mcp_governed_known() {
    $r = function ( $label, $description, $access = 'read', $undo = true ) {
        return array( 'label' => $label, 'description' => $description, 'access' => $access, 'undo' => $undo );
    };
    return array(
        // Audit & analysis.
        'rank-math/audit-site-seo'       => $r( __( 'Run Site SEO Audit', 'att-mcp-abilities' ), __( "Rank Math's site-wide SEO analysis, with a fix hint for each failed test.", 'att-mcp-abilities' ) ),
        'rank-math/fix-site-seo'         => $r( __( 'Fix Site SEO Findings', 'att-mcp-abilities' ), __( 'Automatic fixes for failed audit tests: can make the site visible to search engines, change permalinks and the tagline, enable modules, and set focus keywords on many posts from their titles. Undoable.', 'att-mcp-abilities' ), 'write' ),
        'rank-math/analyze-post-content' => $r( __( 'Analyze Post (Rank Math tests)', 'att-mcp-abilities' ), __( "A post's content and metadata with Rank Math's 24 on-page tests, for the agent to evaluate.", 'att-mcp-abilities' ) ),
        'rank-math/get-seo-scores'       => $r( __( 'Read SEO Scores', 'att-mcp-abilities' ), __( 'Stored Rank Math SEO scores of posts (refreshed when a post is saved in the editor).', 'att-mcp-abilities' ) ),
        'rank-math/get-post-seo-meta'    => $r( __( 'Read Post SEO Meta', 'att-mcp-abilities' ), __( "A post's SEO title, description, focus keyword and robots settings.", 'att-mcp-abilities' ) ),
        'rank-math/get-post-schema'      => $r( __( 'Read Post Schema', 'att-mcp-abilities' ), __( 'The schema (structured data) applied to a post.', 'att-mcp-abilities' ) ),
        'rank-math/get-post-links'       => $r( __( 'Read Post Links', 'att-mcp-abilities' ), __( 'Internal and external links in posts.', 'att-mcp-abilities' ) ),
        'rank-math/get-link-report'      => $r( __( 'Read Link Report', 'att-mcp-abilities' ), __( 'Status of links across the site (full audit with Rank Math PRO).', 'att-mcp-abilities' ) ),
        'rank-math/get-redirections'     => $r( __( 'Read Redirections', 'att-mcp-abilities' ), __( 'The redirections configured in Rank Math.', 'att-mcp-abilities' ) ),
        'rank-math/get-404-logs'         => $r( __( 'Read 404 Log', 'att-mcp-abilities' ), __( "URLs visitors hit that don't exist (404 Monitor module).", 'att-mcp-abilities' ) ),
        'rank-math/get-sitemap-status'   => $r( __( 'Read Sitemap Status', 'att-mcp-abilities' ), __( 'Whether XML sitemaps are enabled and what they include.', 'att-mcp-abilities' ) ),
        'rank-math/get-top-keywords'     => $r( __( 'Read Top Keywords', 'att-mcp-abilities' ), __( 'Top-performing Google Search Console keywords (needs Search Console connected in Rank Math).', 'att-mcp-abilities' ) ),
        // Settings.
        'rank-math/get-settings'                => $r( __( 'Read Rank Math Settings', 'att-mcp-abilities' ), __( 'General, titles & meta and sitemap settings.', 'att-mcp-abilities' ) ),
        'rank-math/get-system-status'           => $r( __( 'Read System Status', 'att-mcp-abilities' ), __( "Rank Math's health report for the site.", 'att-mcp-abilities' ) ),
        'rank-math/get-robots-txt'              => $r( __( 'Read robots.txt', 'att-mcp-abilities' ), __( 'The robots.txt served by the site.', 'att-mcp-abilities' ) ),
        'rank-math/get-llms-txt'                => $r( __( 'Read llms.txt', 'att-mcp-abilities' ), __( 'The llms.txt served by the site.', 'att-mcp-abilities' ) ),
        'rank-math/set-website-identity'        => $r( __( 'Set Website Identity', 'att-mcp-abilities' ), __( 'Knowledge-graph details: person or organization, name, logo, social profiles. Undoable.', 'att-mcp-abilities' ), 'write' ),
        'rank-math/set-global-seo-settings'     => $r( __( 'Set Global SEO Settings', 'att-mcp-abilities' ), __( 'Site-wide SEO defaults (robots, separator, titles). Undoable.', 'att-mcp-abilities' ), 'write' ),
        'rank-math/set-homepage-seo'            => $r( __( 'Set Homepage SEO', 'att-mcp-abilities' ), __( "The homepage's SEO title, description and focus keyword. Undoable.", 'att-mcp-abilities' ), 'write' ),
        'rank-math/set-post-type-seo-settings'  => $r( __( 'Set Post Type SEO Defaults', 'att-mcp-abilities' ), __( 'SEO defaults (title and description templates, robots, schema) for a post type. Undoable.', 'att-mcp-abilities' ), 'write' ),
        'rank-math/set-link-settings'           => $r( __( 'Set Link Settings', 'att-mcp-abilities' ), __( 'Link behavior across the site (nofollow, new tab, category base). Undoable.', 'att-mcp-abilities' ), 'write' ),
        'rank-math/set-sitemap-settings'        => $r( __( 'Set Sitemap Settings', 'att-mcp-abilities' ), __( 'What the XML sitemaps include. Undoable.', 'att-mcp-abilities' ), 'write' ),
        'rank-math/set-breadcrumb-settings'     => $r( __( 'Set Breadcrumb Settings', 'att-mcp-abilities' ), __( 'Breadcrumb output and labels. Undoable.', 'att-mcp-abilities' ), 'write' ),
        'rank-math/set-module-status'           => $r( __( 'Turn Rank Math Modules On/Off', 'att-mcp-abilities' ), __( 'Enable or disable Rank Math modules (redirections, 404 monitor, schema, sitemap…). Undoable.', 'att-mcp-abilities' ), 'write' ),
        'rank-math/set-plugin-preferences'      => $r( __( 'Set Rank Math Auto-updates', 'att-mcp-abilities' ), __( "Turn Rank Math's automatic updates on or off. Not undoable.", 'att-mcp-abilities' ), 'write', false ),
        // AI Visibility (Rank Math Content AI plan).
        'rank-math/get-ai-visibility-overview'       => $r( __( 'Read AI Visibility Overview', 'att-mcp-abilities' ), __( "The brand's visibility on AI platforms (needs a Rank Math Content AI plan).", 'att-mcp-abilities' ) ),
        'rank-math/get-ai-visibility-brand-insights' => $r( __( 'Read AI Visibility Brand Insights', 'att-mcp-abilities' ), __( 'AI Visibility metrics for one brand (Content AI plan).', 'att-mcp-abilities' ) ),
        'rank-math/get-ai-visibility-brand-queries'  => $r( __( 'Read AI Visibility Queries', 'att-mcp-abilities' ), __( 'The queries monitored for a brand (Content AI plan).', 'att-mcp-abilities' ) ),
        'rank-math/create-ai-visibility-brand'       => $r( __( 'Create AI Visibility Brand', 'att-mcp-abilities' ), __( "Add a brand to track in Rank Math's AI Visibility service (Content AI plan). Not undoable.", 'att-mcp-abilities' ), 'write', false ),
    );
}

/** Registry entries for the Rank Math addon (added only while Rank Math is active). */
function att_mcp_rank_math_registry() {
    $out = array();
    foreach ( att_mcp_governed_known() as $name => $tool ) {
        $entry         = att_mcp_registry_entry( $tool['label'], $tool['description'], 'Rank Math', $tool['access'], 'read' === $tool['access'] );
        $entry['undo'] = $tool['undo'];
        $out[ $name ]  = $entry;
    }
    // Tools a newer Rank Math added (see att_mcp_note_governed_ability()); they start off.
    $seen = get_option( 'att_mcp_seen_abilities', array() );
    foreach ( is_array( $seen ) ? $seen : array() as $name => $tool ) {
        if ( 0 === strpos( (string) $name, 'rank-math/' ) && ! isset( $out[ $name ] ) && is_array( $tool ) ) {
            $out[ $name ] = att_mcp_registry_entry(
                isset( $tool['label'] ) ? (string) $tool['label'] : (string) $name,
                isset( $tool['description'] ) ? (string) $tool['description'] : '',
                'Rank Math',
                empty( $tool['readonly'] ) ? 'write' : 'read',
                false
            );
        }
    }

    $e = 'att_mcp_registry_entry';
    return $out + array(
        'att/rank-math-save-redirection'    => $e( __( 'Save Redirection', 'att-mcp-abilities' ), __( 'Create or edit a Rank Math redirection (301/302/307, or 410/451), e.g. for a 404 or a changed slug. Undoable.', 'att-mcp-abilities' ), 'Rank Math Fixes', 'write' ),
        'att/rank-math-delete-redirections' => $e( __( 'Delete Redirections', 'att-mcp-abilities' ), __( 'Move Rank Math redirections to its trash, or delete them permanently. Undoable.', 'att-mcp-abilities' ), 'Rank Math Fixes', 'write' ),
        'att/rank-math-clear-404-logs'      => $e( __( 'Clear 404 Log', 'att-mcp-abilities' ), __( 'Remove fixed entries (or everything) from the Rank Math 404 log. Not undoable.', 'att-mcp-abilities' ), 'Rank Math Fixes', 'write' ),
    );
}

function att_mcp_register_rank_math_abilities() {
    $base = att_mcp_ability_base();

    if ( att_mcp_is_enabled( 'att/rank-math-save-redirection' ) ) {
        att_mcp_register( 'att/rank-math-save-redirection', array_merge( $base, array(
            'label'               => 'Save Rank Math Redirection',
            'description'         => 'Creates or edits a redirection in Rank Math (Redirections module), the same way its Redirections screen does. Typical fixes: redirect URLs from rank-math/get-404-logs to the right page, or an old slug to its new URL. Pass "from" (paths or URLs on this site, matched exactly) and/or "sources" ({pattern, comparison: exact|contains|start|end|regex, ignore_case}), "url_to" (a URL or a path on this site), and "header_code" (301 permanent — default, 302/307 temporary, 410 gone, 451 unavailable for legal reasons; 410/451 need no url_to). Pass "id" (from rank-math/get-redirections) to edit one; fields you leave out keep their values. A new redirection whose sources equal an existing active one updates that one, as in Rank Math. Refuses redirects that would loop. Undoable with att/undo-change.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'id'          => array( 'type' => 'integer', 'description' => 'Redirection to edit. Omit to create one.' ),
                    'from'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Source paths or URLs on this site, matched exactly (e.g. "/old-page/").' ),
                    'sources'     => array(
                        'type'        => 'array',
                        'description' => 'Sources with a match type. Replaces the existing sources when editing.',
                        'items'       => array(
                            'type'       => 'object',
                            'properties' => array(
                                'pattern'     => array( 'type' => 'string' ),
                                'comparison'  => array( 'type' => 'string', 'enum' => array( 'exact', 'contains', 'start', 'end', 'regex' ) ),
                                'ignore_case' => array( 'type' => 'boolean' ),
                            ),
                        ),
                    ),
                    'url_to'      => array( 'type' => 'string', 'description' => 'Destination URL, or a path on this site.' ),
                    'header_code' => array( 'type' => 'integer', 'enum' => array( 301, 302, 307, 410, 451 ), 'description' => 'Default 301.' ),
                    'status'      => array( 'type' => 'string', 'enum' => array( 'active', 'inactive' ), 'description' => 'Default active.' ),
                ),
            ),
            'permission_callback' => function () { return att_mcp_rank_math_can( 'redirections' ); },
            'execute_callback'    => 'att_mcp_execute_rank_math_save_redirection',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => false, 'idempotent' => true ) ) ),
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/rank-math-delete-redirections' ) ) {
        att_mcp_register( 'att/rank-math-delete-redirections', array_merge( $base, array(
            'label'               => 'Delete Rank Math Redirections',
            'description'         => 'Moves Rank Math redirections to its trash (restorable from its Redirections screen), or deletes them permanently with "permanent": true. Get ids from rank-math/get-redirections. Undoable with att/undo-change either way.',
            'input_schema'        => array(
                'type'       => 'object',
                'required'   => array( 'ids' ),
                'properties' => array(
                    'ids'       => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Redirection ids (max 100).' ),
                    'permanent' => array( 'type' => 'boolean', 'description' => 'Delete instead of trashing. Default false.' ),
                ),
            ),
            'permission_callback' => function () { return att_mcp_rank_math_can( 'redirections' ); },
            'execute_callback'    => 'att_mcp_execute_rank_math_delete_redirections',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/rank-math-clear-404-logs' ) ) {
        att_mcp_register( 'att/rank-math-clear-404-logs', array_merge( $base, array(
            'label'               => 'Clear Rank Math 404 Log',
            'description'         => 'Removes entries from the Rank Math 404 log (404 Monitor module) — typically after redirecting them with att/rank-math-save-redirection. Pass "ids" from rank-math/get-404-logs, or "all": true to empty the log. Log entries cannot be restored.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Log entry ids (max 500).' ),
                    'all' => array( 'type' => 'boolean', 'description' => 'Empty the whole log.' ),
                ),
            ),
            'permission_callback' => function () { return att_mcp_rank_math_can( '404_monitor' ); },
            'execute_callback'    => 'att_mcp_execute_rank_math_clear_404_logs',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => true, 'idempotent' => true ) ) ),
        ) ) );
    }
}

/* ----- Helpers ------------------------------------------------------------------ */

/** Rank Math capability check (its Role Manager can grant these to other roles). */
function att_mcp_rank_math_can( $cap ) {
    if ( is_callable( array( '\RankMath\Helper', 'has_cap' ) ) ) {
        return (bool) \RankMath\Helper::has_cap( $cap );
    }
    return current_user_can( 'manage_options' );
}

/** Rank Math is active, set up, and (optionally) the module is on; WP_Error otherwise. */
function att_mcp_rank_math_ready( $module = '' ) {
    if ( ! class_exists( '\RankMath\Helper' ) ) {
        return new WP_Error( 'att_mcp_plugin_inactive', 'Rank Math SEO is not active on this site.' );
    }
    $problem = function_exists( 'att_mcp_seo_plugin_problem' ) ? att_mcp_seo_plugin_problem( 'rank_math' ) : '';
    if ( '' !== $problem ) {
        return new WP_Error( 'att_mcp_rank_math_not_ready', $problem );
    }
    if ( '' !== $module && ! \RankMath\Helper::is_module_active( $module ) ) {
        $names = array( 'redirections' => 'Redirections', '404-monitor' => '404 Monitor' );
        return new WP_Error(
            'att_mcp_module_inactive',
            sprintf(
                'Rank Math\'s %1$s module is off. Turn it on in Rank Math > Dashboard, or with rank-math/set-module-status {"modules": {"%2$s": true}}.',
                isset( $names[ $module ] ) ? $names[ $module ] : $module,
                $module
            )
        );
    }
    return true;
}

/** Does a regular expression compile? (The warning an invalid one raises is the answer, not an error.) */
function att_mcp_regex_compiles( $regex ) {
    set_error_handler( '__return_true' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- scoped to this one preg_match
    $ok = false !== preg_match( $regex, '' );
    restore_error_handler();
    return $ok;
}

/** One redirection row, normalized for output. */
function att_mcp_rank_math_redirection_row( $id ) {
    $row = \RankMath\Redirections\DB::get_redirection_by_id( (int) $id );
    if ( ! $row ) {
        return null;
    }
    return array(
        'id'          => (int) $row['id'],
        'sources'     => array_values( (array) $row['sources'] ),
        'url_to'      => (string) $row['url_to'],
        'header_code' => (int) $row['header_code'],
        'status'      => (string) $row['status'],
        'hits'        => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
    );
}

/* ----- History (undo) ------------------------------------------------------------- */

function att_mcp_capture_rank_math_redirection( $id ) {
    if ( ! class_exists( '\RankMath\Redirections\DB' ) ) {
        return null;
    }
    $row = \RankMath\Redirections\DB::get_redirection_by_id( (int) $id );
    return array(
        'type'    => 'rank_math_redirection',
        'target'  => (int) $id,
        'existed' => (bool) $row,
        'value'   => $row ? $row : null,
    );
}

function att_mcp_restore_rank_math_redirection( $item ) {
    global $wpdb;
    if ( ! class_exists( '\RankMath\Redirections\DB' ) ) {
        return new WP_Error( 'att_mcp_plugin_inactive', 'Rank Math SEO is not active, so this redirection change cannot be undone now.' );
    }
    $id     = (int) $item['target'];
    $exists = (bool) \RankMath\Redirections\DB::get_redirection_by_id( $id );

    if ( empty( $item['existed'] ) ) {
        if ( $exists ) {
            \RankMath\Redirections\DB::delete( array( $id ) );
        }
        return true;
    }

    $row = (array) $item['value'];
    if ( $exists ) {
        \RankMath\Redirections\DB::update( array(
            'id'          => $id,
            'sources'     => $row['sources'],
            'url_to'      => $row['url_to'],
            'header_code' => $row['header_code'],
            'status'      => $row['status'],
        ) );
        return true;
    }

    // Deleted since: put the row back under its own id (so later undo/redo items still match).
    $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- restoring a row into Rank Math's own table; its API cannot insert with a given id
        $wpdb->prefix . 'rank_math_redirections',
        array(
            'id'            => $id,
            'sources'       => maybe_serialize( $row['sources'] ),
            'url_to'        => (string) $row['url_to'],
            'header_code'   => (int) $row['header_code'],
            'hits'          => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
            'status'        => (string) $row['status'],
            'created'       => isset( $row['created'] ) ? (string) $row['created'] : current_time( 'mysql' ),
            'updated'       => current_time( 'mysql' ),
            'last_accessed' => isset( $row['last_accessed'] ) ? (string) $row['last_accessed'] : '0000-00-00 00:00:00',
        ),
        array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
    );
    if ( class_exists( '\RankMath\Redirections\Cache' ) ) {
        \RankMath\Redirections\Cache::purge( $id );
    }
    return true;
}

/* ----- Execute --------------------------------------------------------------------- */

function att_mcp_execute_rank_math_save_redirection( $input ) {
    $ready = att_mcp_rank_math_ready( 'redirections' );
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }

    $id       = isset( $input['id'] ) ? (int) $input['id'] : 0;
    $existing = $id ? \RankMath\Redirections\DB::get_redirection_by_id( $id ) : null;
    if ( $id && ! $existing ) {
        return new WP_Error( 'att_mcp_not_found', 'No Rank Math redirection with that id.' );
    }

    // Sources.
    $sources = array();
    if ( isset( $input['sources'] ) && is_array( $input['sources'] ) ) {
        foreach ( $input['sources'] as $source ) {
            if ( ! is_array( $source ) || ! isset( $source['pattern'] ) || '' === trim( (string) $source['pattern'] ) ) {
                return new WP_Error( 'att_mcp_bad_input', 'Every source needs a non-empty "pattern".' );
            }
            $comparison = isset( $source['comparison'] ) ? (string) $source['comparison'] : 'exact';
            if ( ! in_array( $comparison, array( 'exact', 'contains', 'start', 'end', 'regex' ), true ) ) {
                return new WP_Error( 'att_mcp_bad_input', '"comparison" must be exact, contains, start, end or regex.' );
            }
            $pattern = trim( (string) $source['pattern'] );
            if ( 'regex' === $comparison && ! att_mcp_regex_compiles( '@' . $pattern . '@' ) ) { // Rank Math wraps patterns in "@"
                return new WP_Error( 'att_mcp_bad_input', sprintf( 'The regex "%s" is not valid.', $pattern ) );
            }
            $sources[] = array( 'pattern' => $pattern, 'comparison' => $comparison, 'ignore' => ! empty( $source['ignore_case'] ) ? 'case' : '' );
        }
    }
    if ( isset( $input['from'] ) ) {
        foreach ( (array) $input['from'] as $from ) {
            $from = trim( (string) $from );
            if ( '' === $from ) {
                continue;
            }
            if ( preg_match( '#^https?://#i', $from ) && ! att_mcp_is_own_url( $from ) ) {
                return new WP_Error( 'att_mcp_bad_input', sprintf( '"%s" is not on this site; only this site\'s URLs can be redirected.', $from ) );
            }
            $sources[] = array( 'pattern' => $from, 'comparison' => 'exact', 'ignore' => '' );
        }
    }
    if ( ! $sources && $existing ) {
        $sources = array_values( (array) $existing['sources'] );
    }
    if ( ! $sources ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pass "from" (paths or URLs to redirect) or "sources".' );
    }

    // Type, destination, status.
    $code = isset( $input['header_code'] ) ? (int) $input['header_code'] : ( $existing ? (int) $existing['header_code'] : 301 );
    if ( ! in_array( $code, array( 301, 302, 307, 410, 451 ), true ) ) {
        return new WP_Error( 'att_mcp_bad_input', '"header_code" must be 301, 302, 307, 410 or 451.' );
    }
    $url_to = isset( $input['url_to'] ) ? trim( (string) $input['url_to'] ) : ( $existing ? (string) $existing['url_to'] : '' );
    if ( in_array( $code, array( 410, 451 ), true ) ) {
        $url_to = '';
    } elseif ( '' === $url_to ) {
        return new WP_Error( 'att_mcp_bad_input', '"url_to" is required for 301, 302 and 307 redirects.' );
    } elseif ( '/' !== substr( $url_to, 0, 1 ) && ! wp_http_validate_url( $url_to ) ) {
        return new WP_Error( 'att_mcp_bad_input', '"url_to" must be an http(s) URL or a path starting with "/".' );
    }
    $status = isset( $input['status'] ) ? (string) $input['status'] : ( $existing && 'trashed' !== $existing['status'] ? (string) $existing['status'] : 'active' );
    if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
        return new WP_Error( 'att_mcp_bad_input', '"status" must be active or inactive.' );
    }

    $redirection = \RankMath\Redirections\Redirection::from( array(
        'id'          => $id ? $id : '',
        'sources'     => $sources,
        'url_to'      => $url_to,
        'header_code' => (string) $code,
        'status'      => $status,
    ) );
    if ( ! $redirection->has_sources() ) {
        return new WP_Error( 'att_mcp_bad_input', 'None of the sources is valid (a source cannot be the home page or another site).' );
    }
    // Rank Math's own check compares exact strings; also catch "/page/" → "/page", which
    // WordPress's trailing-slash redirect would turn into a loop.
    $loops = $redirection->is_infinite_loop();
    if ( ! $loops && '' !== $url_to ) {
        $dest = untrailingslashit( (string) strtok( '/' === substr( $url_to, 0, 1 ) ? home_url( $url_to ) : $url_to, '?#' ) );
        foreach ( (array) $redirection->sources as $source ) {
            if ( 'exact' === $source['comparison'] && untrailingslashit( home_url( '/' . ltrim( (string) $source['pattern'], '/' ) ) ) === $dest ) {
                $loops = true;
            }
        }
    }
    if ( $loops ) {
        return new WP_Error( 'att_mcp_redirect_loop', 'This redirection would send visitors in a loop: a source is the same as the destination.' );
    }

    // Undo point: the row being edited, or — for a new redirection — the active one
    // Rank Math will update instead because it has the same sources.
    $before = null;
    if ( $id ) {
        $before = att_mcp_capture_rank_math_redirection( $id );
    } else {
        $match = \RankMath\Redirections\DB::match_redirections_source( maybe_serialize( $redirection->sources ) );
        if ( ! empty( $match[0]['id'] ) ) {
            $before = att_mcp_capture_rank_math_redirection( (int) $match[0]['id'] );
        }
    }

    $saved = (int) $redirection->save();
    if ( $saved <= 0 ) {
        return new WP_Error( 'att_mcp_save_failed', 'Rank Math could not save the redirection.' );
    }
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Rank Math's own hook, fired as its Redirections screen does.
    do_action( 'rank_math/redirection/saved', $redirection, $input );

    $item      = $before ? $before : array( 'type' => 'rank_math_redirection', 'target' => $saved, 'existed' => false, 'value' => null );
    $change_id = att_mcp_record_change( array( $item ), 'Rank Math redirection #' . $saved );

    return array(
        'id'          => $saved,
        'created'     => ! $before,
        'redirection' => att_mcp_rank_math_redirection_row( $saved ),
        'change_id'   => $change_id,
        'note'        => 'Purge the page cache (att/purge-cache) if one is active. Clear fixed 404 log entries with att/rank-math-clear-404-logs.',
    );
}

function att_mcp_execute_rank_math_delete_redirections( $input ) {
    $ready = att_mcp_rank_math_ready( 'redirections' );
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }
    $ids = ( isset( $input['ids'] ) && is_array( $input['ids'] ) ) ? array_slice( array_values( array_unique( array_filter( array_map( 'intval', $input['ids'] ) ) ) ), 0, 100 ) : array();
    if ( ! $ids ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pass "ids" (from rank-math/get-redirections).' );
    }

    $found     = array();
    $not_found = array();
    $items     = array();
    foreach ( $ids as $id ) {
        $capture = att_mcp_capture_rank_math_redirection( $id );
        if ( $capture && $capture['existed'] ) {
            $found[] = $id;
            $items[] = $capture;
        } else {
            $not_found[] = $id;
        }
    }
    if ( ! $found ) {
        return new WP_Error( 'att_mcp_not_found', 'None of those redirections exists.' );
    }

    $permanent = ! empty( $input['permanent'] );
    $change_id = att_mcp_record_change( $items, sprintf( 'Rank Math redirections %s: #%s', $permanent ? 'deleted' : 'trashed', implode( ', #', $found ) ) );
    if ( $permanent ) {
        \RankMath\Redirections\DB::delete( $found );
    } else {
        \RankMath\Redirections\DB::change_status( $found, 'trashed' );
    }

    return array(
        $permanent ? 'deleted' : 'trashed' => $found,
        'not_found'                        => $not_found,
        'change_id'                        => $change_id,
    );
}

function att_mcp_execute_rank_math_clear_404_logs( $input ) {
    $ready = att_mcp_rank_math_ready( '404-monitor' );
    if ( is_wp_error( $ready ) ) {
        return $ready;
    }
    if ( ! empty( $input['all'] ) ) {
        $count = (int) \RankMath\Monitor\DB::get_count();
        \RankMath\Monitor\DB::clear_logs();
        return array( 'deleted' => $count, 'remaining' => 0 );
    }
    $ids = ( isset( $input['ids'] ) && is_array( $input['ids'] ) ) ? array_slice( array_values( array_unique( array_filter( array_map( 'intval', $input['ids'] ) ) ) ), 0, 500 ) : array();
    if ( ! $ids ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pass "ids" (from rank-math/get-404-logs), or "all": true.' );
    }
    $deleted = (int) \RankMath\Monitor\DB::delete_log( $ids );
    return array(
        'deleted'   => $deleted,
        'remaining' => (int) \RankMath\Monitor\DB::get_count(),
    );
}
