<?php
/**
 * Addon framework.
 *
 * Each addon bundles the MCP ability groups for ONE integration (a plugin or
 * theme) and can be enabled/disabled as a unit. The model is TWO-LEVEL: an
 * ability is live only when ALL of these hold —
 *   1. its addon is AVAILABLE (the target plugin/theme is detected),
 *   2. its addon is ENABLED (admin opted in; integrations default OFF),
 *   3. the ability's own per-ability toggle is on.
 * The `core` addon is always available and always on (cannot be disabled).
 *
 * Extend with the `att_mcp_addons` filter:
 *   add_filter( 'att_mcp_addons', function ( $addons ) {
 *       $addons['my_plugin'] = array(
 *           'label'  => 'My Plugin',
 *           'detect' => function () { return class_exists( 'My_Plugin' ); },
 *           'groups' => array( 'My Group' ),   // matches the registry 'group'
 *       );
 *       return $addons;
 *   } );
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_addons() {
    static $cache = null;
    if ( null !== $cache ) {
        return $cache;
    }

    $addons = array(
        'core' => array(
            'label'       => __( 'Core WordPress', 'att-mcp-abilities' ),
            'description' => __( 'Posts, pages, any content type, taxonomy, comments, media, users, search, menus, site settings, performance, SEO, and change history (undo). Always available.', 'att-mcp-abilities' ),
            'detect'      => '__return_true',
            'core'        => true, // always on; cannot be disabled
            'groups'      => array( 'Posts', 'Pages', 'Content', 'Taxonomy', 'Comments', 'Media', 'Users', 'Search', 'Menus', 'Site', 'Performance', 'SEO', 'History' ),
        ),
        'design' => array(
            'label'       => __( 'Design & Theme (generic)', 'att-mcp-abilities' ),
            'description' => __( 'Theme-agnostic design tools: Additional CSS, theme mods, options, page rendering, reference-site fetching, cache purge, and — for block themes — templates, template parts and global styles.', 'att-mcp-abilities' ),
            'detect'      => '__return_true',
            'groups'      => array( 'Design', 'Site Editor' ),
        ),
        'generatepress' => array(
            'label'       => __( 'GeneratePress', 'att-mcp-abilities' ),
            'description' => __( 'Read/change GeneratePress settings and create, edit, or remove GeneratePress Elements.', 'att-mcp-abilities' ),
            'detect'      => 'att_mcp_detect_generatepress',
            'groups'      => array( 'GeneratePress' ),
        ),
        'elementor' => array(
            'label'       => __( 'Elementor', 'att-mcp-abilities' ),
            'description' => __( 'Inspect and edit Elementor page structures, whole page layouts, and the global kit.', 'att-mcp-abilities' ),
            'detect'      => 'att_mcp_detect_elementor',
            'groups'      => array( 'Elementor' ),
        ),
        'code_snippets' => array(
            'label'       => __( 'Code Snippets', 'att-mcp-abilities' ),
            'description' => __( 'Create, read, and delete entries in the Code Snippets plugin.', 'att-mcp-abilities' ),
            'detect'      => 'att_mcp_detect_code_snippets',
            'groups'      => array( 'Code Snippets' ),
        ),
        'rank_math' => array(
            'label'       => __( 'Rank Math SEO', 'att-mcp-abilities' ),
            'description' => __( "Choose which of Rank Math's own MCP tools agents may use (site audit and fixes, post analysis, SEO scores, settings, sitemaps, links, redirections, 404 log, Search Console, AI Visibility), and add fix tools for redirections and the 404 log.", 'att-mcp-abilities' ),
            'detect'      => 'att_mcp_detect_rank_math',
            'groups'      => array( 'Rank Math', 'Rank Math Fixes' ),
            'governs'     => 'rank-math/', // other plugin's abilities this addon controls (see governance.php)
        ),
        'other_mcp' => array(
            'label'       => __( 'Other MCP tools', 'att-mcp-abilities' ),
            'description' => __( 'MCP tools that other plugins and WordPress itself offer AI agents on this connection (for example Yoast SEO, All in One SEO, WordPress core). Choose them one by one.', 'att-mcp-abilities' ),
            'detect'      => 'att_mcp_detect_other_mcp',
            'groups'      => array( 'Other MCP Tools' ),
            'governs'     => '*', // every other ability MCP Adapter exposes (see governance.php)
        ),
        'advanced' => array(
            'label'       => __( 'Advanced (site administration)', 'att-mcp-abilities' ),
            'description' => __( 'Full WordPress REST API access as the connected user, and installing/activating plugins and themes from WordPress.org. Powerful — enable only for agents you fully trust.', 'att-mcp-abilities' ),
            'detect'      => '__return_true',
            'groups'      => array( 'Advanced' ),
        ),
    );

    $addons = apply_filters( 'att_mcp_addons', $addons );
    if ( did_action( 'init' ) ) {
        $cache = $addons;
    }
    return $addons;
}

// Named detection callbacks (stable references that survive the filter).
function att_mcp_detect_generatepress() { return function_exists( 'generate_get_defaults' ); }
function att_mcp_detect_elementor()     { return class_exists( '\Elementor\Plugin' ); }
function att_mcp_detect_code_snippets() { return class_exists( '\Code_Snippets\Snippet' ); }
function att_mcp_detect_rank_math()     { return defined( 'RANK_MATH_VERSION' ); }

/** Is the addon's target plugin/theme present on this site? */
function att_mcp_addon_is_available( $key ) {
    $addons = att_mcp_addons();
    if ( ! isset( $addons[ $key ] ) ) {
        return false;
    }
    $detect = isset( $addons[ $key ]['detect'] ) ? $addons[ $key ]['detect'] : null;
    return is_callable( $detect ) ? (bool) call_user_func( $detect ) : true;
}

/** Saved addon enable states merged with defaults (core forced on, others opt-in OFF). */
function att_mcp_get_addon_settings() {
    $saved = get_option( ATT_MCP_ADDONS_OPTION, array() );
    $saved = is_array( $saved ) ? $saved : array();
    $out   = array();
    foreach ( att_mcp_addons() as $key => $cfg ) {
        if ( ! empty( $cfg['core'] ) ) {
            $out[ $key ] = true;
        } else {
            $out[ $key ] = isset( $saved[ $key ] ) ? (bool) $saved[ $key ] : false;
        }
    }
    return $out;
}

/** Usable = available (detected) AND enabled. */
function att_mcp_addon_is_enabled( $key ) {
    if ( ! att_mcp_addon_is_available( $key ) ) {
        return false;
    }
    $s = att_mcp_get_addon_settings();
    return ! empty( $s[ $key ] );
}

/** Map a registry group name to its owning addon key ('' if unmapped). */
function att_mcp_addon_for_group( $group ) {
    foreach ( att_mcp_addons() as $key => $cfg ) {
        if ( ! empty( $cfg['groups'] ) && in_array( $group, (array) $cfg['groups'], true ) ) {
            return $key;
        }
    }
    return '';
}

/**
 * Sanitize callback for the addons option (core always true). An addon whose
 * target is not installed has a disabled checkbox (never submitted), so its
 * saved state is preserved instead of being reset to off.
 */
function att_mcp_sanitize_addons( $input ) {
    $input = is_array( $input ) ? $input : array();
    $saved = get_option( ATT_MCP_ADDONS_OPTION, array() );
    $saved = is_array( $saved ) ? $saved : array();
    $clean = array();
    foreach ( att_mcp_addons() as $key => $cfg ) {
        if ( ! empty( $cfg['core'] ) ) {
            $clean[ $key ] = true;
        } elseif ( ! att_mcp_addon_is_available( $key ) ) {
            $clean[ $key ] = ! empty( $saved[ $key ] );
        } else {
            $clean[ $key ] = ! empty( $input[ $key ] );
        }
    }
    return $clean;
}
