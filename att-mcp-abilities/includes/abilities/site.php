<?php
/**
 * Site abilities — public site info, installed plugins/themes, and the core
 * site settings an agent needs to build a site (identity, homepage, permalinks,
 * formats, icon, logo). Settings writes are undoable (att/undo-change).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_site_abilities() {
    $base = att_mcp_ability_base();

    if ( att_mcp_is_enabled( 'att/get-site-info' ) ) {
        att_mcp_register( 'att/get-site-info', array_merge( $base, array(
            'label'               => 'Get Site Info',
            'description'         => 'Returns site name, URL, tagline, WordPress version, and language.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
            'permission_callback' => '__return_true',
            'execute_callback'    => 'att_mcp_execute_get_site_info',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/get-plugins' ) ) {
        att_mcp_register( 'att/get-plugins', array_merge( $base, array(
            'label'               => 'Get Plugins',
            'description'         => 'Lists installed plugins with name, version, author, plugin file, and whether each is active.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'active_only' => array( 'type' => 'boolean', 'description' => 'Only return active plugins. Default false.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'activate_plugins' ); },
            'execute_callback'    => 'att_mcp_execute_get_plugins',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/get-themes' ) ) {
        att_mcp_register( 'att/get-themes', array_merge( $base, array(
            'label'               => 'Get Themes',
            'description'         => 'Lists installed themes (stylesheet slug, name, version, parent, block theme or classic) and marks the active one.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
            'permission_callback' => function () { return current_user_can( 'switch_themes' ) || current_user_can( 'edit_theme_options' ); },
            'execute_callback'    => 'att_mcp_execute_get_themes',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/get-site-settings' ) ) {
        att_mcp_register( 'att/get-site-settings', array_merge( $base, array(
            'label'               => 'Get Site Settings',
            'description'         => 'Returns core site settings: title, tagline, homepage mode and pages, posts per page, permalink structure, timezone, date/time formats, week start, site icon, logo, search-engine visibility, and default comment status.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
            'execute_callback'    => 'att_mcp_execute_get_site_settings',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/update-site-settings' ) ) {
        att_mcp_register( 'att/update-site-settings', array_merge( $base, array(
            'label'               => 'Update Site Settings',
            'description'         => 'Changes core site settings. Pass only what should change. To use a static homepage set show_on_front "page" and page_on_front (and optionally page_for_posts for the blog). Undoable with att/undo-change.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'title'                  => array( 'type' => 'string',  'description' => 'Site title.' ),
                'tagline'                => array( 'type' => 'string',  'description' => 'Tagline.' ),
                'show_on_front'          => array( 'type' => 'string',  'description' => 'posts (latest posts) | page (static page).' ),
                'page_on_front'          => array( 'type' => 'integer', 'description' => 'Homepage page ID (0 = none).' ),
                'page_for_posts'         => array( 'type' => 'integer', 'description' => 'Blog/posts page ID (0 = none).' ),
                'posts_per_page'         => array( 'type' => 'integer', 'description' => 'Posts per blog page (1–100).' ),
                'permalink_structure'    => array( 'type' => 'string',  'description' => 'e.g. "/%postname%/". "" = plain links.' ),
                'timezone'               => array( 'type' => 'string',  'description' => 'PHP timezone identifier, e.g. "Asia/Kolkata".' ),
                'date_format'            => array( 'type' => 'string',  'description' => 'PHP date format, e.g. "F j, Y".' ),
                'time_format'            => array( 'type' => 'string',  'description' => 'PHP time format, e.g. "g:i a".' ),
                'start_of_week'          => array( 'type' => 'integer', 'description' => '0 = Sunday … 6 = Saturday.' ),
                'site_icon'              => array( 'type' => 'integer', 'description' => 'Site icon (favicon) image attachment ID, 0 removes.' ),
                'custom_logo'            => array( 'type' => 'integer', 'description' => 'Logo image attachment ID, 0 removes.' ),
                'blog_public'            => array( 'type' => 'boolean', 'description' => 'false asks search engines not to index the site.' ),
                'default_comment_status' => array( 'type' => 'string',  'description' => 'open | closed (default for new posts).' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
            'execute_callback'    => 'att_mcp_execute_update_site_settings',
        ) ) );
    }
}

/* ------------------------------------------------------------------------- */

function att_mcp_execute_get_site_info( $input ) {
    $info = array(
        'name'       => get_bloginfo( 'name' ),
        'url'        => home_url( '/' ),
        'tagline'    => get_bloginfo( 'description' ),
        'wp_version' => get_bloginfo( 'version' ),
        'language'   => get_bloginfo( 'language' ),
    );
    // Only administrators see the admin email (this read is otherwise public).
    if ( current_user_can( 'manage_options' ) ) {
        $info['admin_email'] = get_option( 'admin_email' );
    }
    return $info;
}

function att_mcp_execute_get_plugins( $input ) {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $active_only = ! empty( $input['active_only'] );
    $plugins     = array();
    $active      = array();
    foreach ( get_plugins() as $file => $data ) {
        $is_active = is_plugin_active( $file );
        if ( $active_only && ! $is_active ) {
            continue;
        }
        $row = array(
            'name'    => $data['Name'],
            'version' => $data['Version'],
            'author'  => wp_strip_all_tags( (string) $data['Author'] ),
            'file'    => $file,
            'active'  => $is_active,
        );
        $plugins[] = $row;
        if ( $is_active ) {
            $active[] = $row;
        }
    }
    return array(
        'plugins'        => $plugins,
        'active_plugins' => $active, // kept for backwards compatibility
        'total'          => count( $plugins ),
    );
}

function att_mcp_execute_get_themes( $input ) {
    $active = get_stylesheet();
    $themes = array();
    foreach ( wp_get_themes() as $slug => $theme ) {
        $themes[] = array(
            'stylesheet'     => $slug,
            'name'           => $theme->get( 'Name' ),
            'version'        => $theme->get( 'Version' ),
            'parent'         => $theme->parent() ? $theme->get_template() : null,
            'is_block_theme' => method_exists( $theme, 'is_block_theme' ) ? $theme->is_block_theme() : false,
            'active'         => ( $slug === $active ),
        );
    }
    return array( 'themes' => $themes, 'active' => $active );
}

function att_mcp_execute_get_site_settings( $input ) {
    $icon = (int) get_option( 'site_icon' );
    $logo = (int) get_theme_mod( 'custom_logo' );
    return array(
        'title'                  => get_option( 'blogname' ),
        'tagline'                => get_option( 'blogdescription' ),
        'url'                    => home_url( '/' ),
        'admin_email'            => get_option( 'admin_email' ), // read-only here
        'show_on_front'          => get_option( 'show_on_front' ),
        'page_on_front'          => (int) get_option( 'page_on_front' ),
        'page_for_posts'         => (int) get_option( 'page_for_posts' ),
        'posts_per_page'         => (int) get_option( 'posts_per_page' ),
        'permalink_structure'    => get_option( 'permalink_structure' ),
        'timezone'               => wp_timezone_string(),
        'date_format'            => get_option( 'date_format' ),
        'time_format'            => get_option( 'time_format' ),
        'start_of_week'          => (int) get_option( 'start_of_week' ),
        'site_icon'              => $icon,
        'site_icon_url'          => $icon ? wp_get_attachment_url( $icon ) : null,
        'custom_logo'            => $logo,
        'custom_logo_url'        => $logo ? wp_get_attachment_url( $logo ) : null,
        'blog_public'            => (bool) get_option( 'blog_public' ),
        'default_comment_status' => get_option( 'default_comment_status' ),
        'users_can_register'     => (bool) get_option( 'users_can_register' ), // read-only here
    );
}

/** Validate a published-page ID for homepage settings (0 allowed). */
function att_mcp_valid_page_id( $id ) {
    $id = (int) $id;
    if ( 0 === $id ) {
        return true;
    }
    $page = get_post( $id );
    return $page && 'page' === $page->post_type && 'trash' !== $page->post_status;
}

function att_mcp_execute_update_site_settings( $input ) {
    $options    = array(); // option name => new value
    $theme_mods = array(); // theme mod => new value

    $map = array( 'title' => 'blogname', 'tagline' => 'blogdescription', 'date_format' => 'date_format', 'time_format' => 'time_format' );
    foreach ( $map as $field => $option ) {
        if ( isset( $input[ $field ] ) ) {
            $options[ $option ] = sanitize_option( $option, sanitize_text_field( (string) $input[ $field ] ) );
        }
    }
    if ( isset( $input['show_on_front'] ) ) {
        if ( ! in_array( $input['show_on_front'], array( 'posts', 'page' ), true ) ) {
            return new WP_Error( 'att_mcp_bad_value', 'show_on_front must be "posts" or "page".' );
        }
        $options['show_on_front'] = $input['show_on_front'];
    }
    foreach ( array( 'page_on_front', 'page_for_posts' ) as $field ) {
        if ( isset( $input[ $field ] ) ) {
            if ( ! att_mcp_valid_page_id( $input[ $field ] ) ) {
                return new WP_Error( 'att_mcp_bad_page', sprintf( '%s must be the ID of an existing page (or 0).', $field ) );
            }
            $options[ $field ] = (int) $input[ $field ];
        }
    }
    if ( isset( $options['page_on_front'], $options['page_for_posts'] ) && $options['page_on_front'] && $options['page_on_front'] === $options['page_for_posts'] ) {
        return new WP_Error( 'att_mcp_bad_page', 'The homepage and the posts page must be different pages.' );
    }
    if ( isset( $input['posts_per_page'] ) ) {
        $options['posts_per_page'] = max( 1, min( 100, (int) $input['posts_per_page'] ) );
    }
    if ( isset( $input['start_of_week'] ) ) {
        $options['start_of_week'] = max( 0, min( 6, (int) $input['start_of_week'] ) );
    }
    if ( isset( $input['timezone'] ) ) {
        if ( ! in_array( $input['timezone'], timezone_identifiers_list(), true ) ) {
            return new WP_Error( 'att_mcp_bad_timezone', 'timezone must be a PHP timezone identifier such as "Europe/London".' );
        }
        $options['timezone_string'] = $input['timezone'];
        $options['gmt_offset']      = '';
    }
    if ( isset( $input['permalink_structure'] ) ) {
        $structure = sanitize_option( 'permalink_structure', (string) $input['permalink_structure'] );
        if ( '' !== $structure && false === strpos( $structure, '%' ) ) {
            return new WP_Error( 'att_mcp_bad_permalink', 'permalink_structure must contain a tag such as %postname%, or be "" for plain links.' );
        }
        $options['permalink_structure'] = $structure;
    }
    if ( isset( $input['site_icon'] ) ) {
        $icon = (int) $input['site_icon'];
        if ( $icon && ! wp_attachment_is_image( $icon ) ) {
            return new WP_Error( 'att_mcp_bad_media', 'site_icon must be an image attachment ID.' );
        }
        $options['site_icon'] = $icon;
    }
    if ( isset( $input['blog_public'] ) ) {
        $options['blog_public'] = $input['blog_public'] ? 1 : 0;
    }
    if ( isset( $input['default_comment_status'] ) ) {
        if ( ! in_array( $input['default_comment_status'], array( 'open', 'closed' ), true ) ) {
            return new WP_Error( 'att_mcp_bad_value', 'default_comment_status must be "open" or "closed".' );
        }
        $options['default_comment_status'] = $input['default_comment_status'];
    }
    if ( isset( $input['custom_logo'] ) ) {
        $logo = (int) $input['custom_logo'];
        if ( $logo && ! wp_attachment_is_image( $logo ) ) {
            return new WP_Error( 'att_mcp_bad_media', 'custom_logo must be an image attachment ID.' );
        }
        $theme_mods['custom_logo'] = $logo;
    }

    if ( ! $options && ! $theme_mods ) {
        return new WP_Error( 'att_mcp_nothing_to_do', 'Pass at least one setting to change.' );
    }

    // One undo point for the whole update.
    $capture = array();
    foreach ( array_keys( $options ) as $name ) {
        $capture[] = att_mcp_capture( 'option', $name );
    }
    foreach ( array_keys( $theme_mods ) as $name ) {
        $capture[] = att_mcp_capture( 'theme_mod', $name );
    }
    $change_id = att_mcp_record_change( $capture, 'Site settings: ' . implode( ', ', array_merge( array_keys( $options ), array_keys( $theme_mods ) ) ) );

    foreach ( $options as $name => $value ) {
        if ( 'permalink_structure' === $name ) {
            global $wp_rewrite;
            $wp_rewrite->set_permalink_structure( $value );
            continue;
        }
        update_option( $name, $value );
    }
    foreach ( $theme_mods as $name => $value ) {
        if ( $value ) {
            set_theme_mod( $name, $value );
        } else {
            remove_theme_mod( $name );
        }
    }
    if ( isset( $options['permalink_structure'] ) ) {
        flush_rewrite_rules( false );
    }

    return array(
        'updated'   => array_merge( array_keys( $options ), array_keys( $theme_mods ) ),
        'settings'  => att_mcp_execute_get_site_settings( array() ),
        'change_id' => $change_id,
        'note'      => isset( $options['permalink_structure'] )
            ? 'Permalinks changed. If links 404 on Apache, re-save Settings > Permalinks once in WP Admin. Run att/purge-cache if a page cache is active.'
            : 'Run att/purge-cache if a page cache is active.',
    );
}
