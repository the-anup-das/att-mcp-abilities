<?php
/**
 * Advanced abilities (opt-in "Advanced" addon).
 *
 *  - att/rest-get, att/rest-write: call core/plugin REST routes internally as
 *    the connected user. Core's own capability checks apply, so this grants
 *    nothing the user's Application Password could not already do — but it does
 *    route every call through this plugin's toggles, read-only mode, rate limit
 *    and audit log. Credential, user, plugin, settings, batch, abilities, code
 *    and MCP routes are refused so the dedicated (separately toggled) tools stay
 *    the only path to them.
 *  - att/manage-plugin, att/manage-theme: install from WordPress.org and
 *    activate/deactivate/switch. Core capabilities apply (and DISALLOW_FILE_MODS
 *    removes the install capabilities entirely). This plugin and the MCP Adapter
 *    can never be deactivated from here.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_advanced_abilities() {
    $base = att_mcp_ability_base();

    if ( att_mcp_is_enabled( 'att/rest-get' ) ) {
        att_mcp_register( 'att/rest-get', array_merge( $base, array(
            'label'               => 'REST API Read',
            'description'         => 'Calls a WordPress REST API GET route as the connected user and returns the JSON. Useful routes: /wp/v2/types, /wp/v2/widgets, /wp/v2/sidebars, /wp/v2/navigation, /wp/v2/blocks (patterns), /wp/v2/block-patterns/patterns, /wp/v2/menus, /wp/v2/menu-items, /wp/v2/global-styles/themes/{theme}, and plugin routes (e.g. /wc/v3/products). Use "params" for query args such as {"per_page":50,"context":"edit","_fields":"id,title"}.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'route' ), 'properties' => array(
                'route'  => array( 'type' => 'string', 'description' => 'REST route, e.g. /wp/v2/widgets.' ),
                'params' => array( 'type' => 'object', 'description' => 'Query parameters.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_rest_get',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/rest-write' ) ) {
        att_mcp_register( 'att/rest-write', array_merge( $base, array(
            'label'               => 'REST API Write',
            'description'         => 'Calls a WordPress REST API POST/PUT/PATCH/DELETE route as the connected user, e.g. move widgets (POST /wp/v2/widgets/{id} {"sidebar":"footer-1"}), create a Navigation block menu (POST /wp/v2/navigation), or update a plugin\'s own REST resource. Users, plugins, site settings, batch, credential, abilities and code routes are refused — use the dedicated tools. Prefer the dedicated tools whenever one exists (they add undo points).',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'method', 'route' ), 'properties' => array(
                'method' => array( 'type' => 'string', 'description' => 'POST | PUT | PATCH | DELETE.' ),
                'route'  => array( 'type' => 'string', 'description' => 'REST route, e.g. /wp/v2/widgets/block-2.' ),
                'params' => array( 'type' => 'object', 'description' => 'JSON body (POST/PUT/PATCH) or query args (DELETE, e.g. {"force":true}).' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_rest_write',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/manage-plugin' ) ) {
        att_mcp_register( 'att/manage-plugin', array_merge( $base, array(
            'label'               => 'Manage Plugins',
            'description'         => 'Installs a plugin from WordPress.org by slug (optionally activating it), or activates/deactivates an installed plugin. Use att/get-plugins to see installed plugins and their "file". This plugin and the MCP Adapter cannot be deactivated here.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'action' ), 'properties' => array(
                'action'   => array( 'type' => 'string',  'description' => 'install | activate | deactivate.' ),
                'slug'     => array( 'type' => 'string',  'description' => 'WordPress.org plugin slug, e.g. "contact-form-7" (install).' ),
                'plugin'   => array( 'type' => 'string',  'description' => 'Installed plugin file, e.g. "contact-form-7/wp-contact-form-7.php" (activate/deactivate).' ),
                'activate' => array( 'type' => 'boolean', 'description' => 'Activate right after installing. Default false.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'activate_plugins' ) || current_user_can( 'install_plugins' ); },
            'execute_callback'    => 'att_mcp_execute_manage_plugin',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/manage-theme' ) ) {
        att_mcp_register( 'att/manage-theme', array_merge( $base, array(
            'label'               => 'Manage Themes',
            'description'         => 'Installs a theme from WordPress.org by slug, or activates an installed theme (see att/get-themes). Switching themes changes the whole site design and resets theme-specific settings such as menu locations and widgets — confirm with the site owner first.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'action', 'slug' ), 'properties' => array(
                'action' => array( 'type' => 'string', 'description' => 'install | activate.' ),
                'slug'   => array( 'type' => 'string', 'description' => 'Theme slug (WordPress.org slug for install; stylesheet slug for activate), e.g. "generatepress".' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'switch_themes' ) || current_user_can( 'install_themes' ); },
            'execute_callback'    => 'att_mcp_execute_manage_theme',
        ) ) );
    }
}

/* ------------------------------------------------------------------------- */
/* REST passthrough                                                           */
/* ------------------------------------------------------------------------- */

/** Normalise and vet a REST route for the passthrough. Returns the route or WP_Error. */
function att_mcp_rest_route_check( $route, $method ) {
    $route = '/' . ltrim( rawurldecode( (string) $route ), '/' );
    $route = preg_replace( '#/+#', '/', $route );
    $route = strtok( $route, '?#' );
    if ( ! $route || false !== strpos( $route, '..' ) || preg_match( '#[\s<>"\\\\]#', $route ) ) {
        return new WP_Error( 'att_mcp_bad_route', 'Invalid REST route.' );
    }
    $check = strtolower( untrailingslashit( $route ) );

    $always = array(
        '#^/?$#',                          // index (use a specific route)
        '#^/mcp(/|$)#',                    // the MCP server itself
        '#^/batch/#',                      // batch requests would bypass these checks
        '#^/wp-abilities/#',               // abilities run endpoint (bypasses per-ability toggles)
        '#/application-passwords#',        // credentials
        '#^/code-snippets/#', '#^/wpcode/#', // code execution plugins
    );
    $writes = array(
        '#^/wp/v2/users#',                 // accounts & roles
        '#^/wp/v2/plugins#',               // use att/manage-plugin
        '#^/wp/v2/themes#',
        '#^/wp/v2/settings#',              // use att/update-site-settings
        '#^/wp/v2/global-styles#',         // use att/update-global-styles
        '#^/wp/v2/(templates|template-parts)#', // use att/save-block-template
    );
    // REST bases of code-storing post types, unless PHP is allowed.
    if ( ! att_mcp_php_snippets_allowed() ) {
        foreach ( att_mcp_code_post_types() as $type ) {
            $pto = get_post_type_object( $type );
            if ( $pto && ! empty( $pto->show_in_rest ) ) {
                $always[] = '#^/' . preg_quote( trim( ( $pto->rest_namespace ? $pto->rest_namespace : 'wp/v2' ) . '/' . ( $pto->rest_base ? $pto->rest_base : $type ), '/' ), '#' ) . '#';
            }
        }
    }
    $patterns = (array) apply_filters( 'att_mcp_rest_denied_routes', 'GET' === $method ? $always : array_merge( $always, $writes ), $method );
    foreach ( $patterns as $pattern ) {
        if ( @preg_match( $pattern, $check ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- patterns may come from a filter.
            return new WP_Error( 'att_mcp_route_blocked', sprintf( 'The route %s is not available through the REST tools. Use the dedicated ability instead.', $route ) );
        }
    }
    return $route;
}

/** Shared response shaping (size guard). */
function att_mcp_rest_result( $res ) {
    if ( is_wp_error( $res ) ) {
        return $res;
    }
    $json = wp_json_encode( $res['data'] );
    if ( strlen( (string) $json ) > 600000 ) {
        return new WP_Error( 'att_mcp_too_large', 'The response is larger than 600 KB. Narrow it with params such as per_page, page, or _fields.' );
    }
    return array(
        'status'  => $res['status'],
        'headers' => $res['headers'],
        'data'    => $res['data'],
    );
}

function att_mcp_execute_rest_get( $input ) {
    $route = att_mcp_rest_route_check( isset( $input['route'] ) ? $input['route'] : '', 'GET' );
    if ( is_wp_error( $route ) ) {
        return $route;
    }
    $params = ( isset( $input['params'] ) && is_array( $input['params'] ) ) ? $input['params'] : array();
    return att_mcp_rest_result( att_mcp_rest( 'GET', $route, $params ) );
}

function att_mcp_execute_rest_write( $input ) {
    $method = isset( $input['method'] ) ? strtoupper( (string) $input['method'] ) : '';
    if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
        return new WP_Error( 'att_mcp_bad_method', 'method must be POST, PUT, PATCH, or DELETE (use att/rest-get for reads).' );
    }
    $route = att_mcp_rest_route_check( isset( $input['route'] ) ? $input['route'] : '', $method );
    if ( is_wp_error( $route ) ) {
        return $route;
    }
    $params = ( isset( $input['params'] ) && is_array( $input['params'] ) ) ? $input['params'] : array();
    return att_mcp_rest_result( att_mcp_rest( $method, $route, $params ) );
}

/* ------------------------------------------------------------------------- */
/* Plugins & themes                                                           */
/* ------------------------------------------------------------------------- */

/**
 * Plugins that must never be deactivated through MCP (this one and the MCP Adapter).
 * Compares canonical real paths as well as plugin_basename(): on symlinked or
 * junctioned installs plugin_basename() can fall back to a full path, which must
 * not open a way around this protection.
 */
function att_mcp_is_protected_plugin( $plugin_file ) {
    $plugin_file = (string) $plugin_file;
    if ( plugin_basename( ATT_MCP_FILE ) === $plugin_file ) {
        return true;
    }
    $target = realpath( WP_PLUGIN_DIR . '/' . $plugin_file );
    $self   = realpath( ATT_MCP_FILE );
    if ( $target && $self ) {
        $target = wp_normalize_path( $target );
        $self   = wp_normalize_path( $self );
        if ( '\\' === DIRECTORY_SEPARATOR ) { // Windows paths are case-insensitive
            $target = strtolower( $target );
            $self   = strtolower( $self );
        }
        if ( $target === $self ) {
            return true;
        }
    }
    $lower = strtolower( $plugin_file );
    return 0 === strpos( $lower, 'mcp-adapter/' ) || false !== strpos( $lower, '/mcp-adapter.php' );
}

/** Resolve an installed plugin file from "dir/file.php", "dir/file", or a folder slug. */
function att_mcp_resolve_plugin_file( $value ) {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $value   = trim( (string) $value, '/ ' );
    $plugins = get_plugins();
    if ( isset( $plugins[ $value ] ) ) {
        return $value;
    }
    if ( isset( $plugins[ $value . '.php' ] ) ) {
        return $value . '.php';
    }
    foreach ( array_keys( $plugins ) as $file ) {
        if ( 0 === strpos( $file, $value . '/' ) ) {
            return $file;
        }
    }
    return '';
}

function att_mcp_execute_manage_plugin( $input ) {
    $action = isset( $input['action'] ) ? sanitize_key( $input['action'] ) : '';

    if ( 'install' === $action ) {
        if ( ! current_user_can( 'install_plugins' ) ) {
            return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to install plugins on this site.' );
        }
        $slug = isset( $input['slug'] ) ? sanitize_key( $input['slug'] ) : '';
        if ( '' === $slug ) {
            return new WP_Error( 'att_mcp_no_slug', 'A WordPress.org plugin "slug" is required.' );
        }
        if ( att_mcp_resolve_plugin_file( $slug ) ) {
            return new WP_Error( 'att_mcp_already_installed', 'That plugin is already installed — use action "activate".' );
        }
        $status = ( ! empty( $input['activate'] ) && current_user_can( 'activate_plugins' ) ) ? 'active' : 'inactive';
        $res    = att_mcp_rest( 'POST', '/wp/v2/plugins', array( 'slug' => $slug, 'status' => $status ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        return array(
            'installed' => true,
            'plugin'    => isset( $res['data']['plugin'] ) ? $res['data']['plugin'] . '.php' : $slug,
            'name'      => isset( $res['data']['name'] ) ? $res['data']['name'] : $slug,
            'version'   => isset( $res['data']['version'] ) ? $res['data']['version'] : null,
            'status'    => isset( $res['data']['status'] ) ? $res['data']['status'] : $status,
        );
    }

    if ( in_array( $action, array( 'activate', 'deactivate' ), true ) ) {
        $file = att_mcp_resolve_plugin_file( isset( $input['plugin'] ) ? $input['plugin'] : ( isset( $input['slug'] ) ? $input['slug'] : '' ) );
        if ( '' === $file ) {
            return new WP_Error( 'att_mcp_no_plugin', 'Plugin not found. Pass the plugin file from att/get-plugins.' );
        }
        if ( 'deactivate' === $action && att_mcp_is_protected_plugin( $file ) ) {
            return new WP_Error( 'att_mcp_protected_plugin', 'This plugin keeps the MCP connection alive and cannot be deactivated through MCP.' );
        }
        $cap = ( 'activate' === $action ) ? 'activate_plugin' : 'deactivate_plugin';
        if ( ! current_user_can( $cap, $file ) ) {
            return new WP_Error( 'att_mcp_forbidden', sprintf( 'You are not allowed to %s this plugin.', $action ) );
        }
        $res = att_mcp_rest( 'POST', '/wp/v2/plugins/' . preg_replace( '/\.php$/', '', $file ), array( 'status' => 'activate' === $action ? 'active' : 'inactive' ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        return array(
            'plugin' => $file,
            'status' => isset( $res['data']['status'] ) ? $res['data']['status'] : null,
            'note'   => 'Run att/purge-cache if a page cache is active.',
        );
    }

    return new WP_Error( 'att_mcp_bad_action', 'action must be install, activate, or deactivate.' );
}

function att_mcp_execute_manage_theme( $input ) {
    $action = isset( $input['action'] ) ? sanitize_key( $input['action'] ) : '';
    $slug   = isset( $input['slug'] ) ? sanitize_key( $input['slug'] ) : '';
    if ( '' === $slug ) {
        return new WP_Error( 'att_mcp_no_slug', 'A theme "slug" is required.' );
    }

    if ( 'install' === $action ) {
        if ( ! current_user_can( 'install_themes' ) ) {
            return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to install themes on this site.' );
        }
        if ( wp_get_theme( $slug )->exists() ) {
            return new WP_Error( 'att_mcp_already_installed', 'That theme is already installed — use action "activate".' );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $api = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
        if ( is_wp_error( $api ) ) {
            return $api;
        }
        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Theme_Upgrader( $skin );
        $result   = $upgrader->install( $api->download_link );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( is_wp_error( $skin->result ) ) {
            return $skin->result;
        }
        if ( $skin->get_errors()->has_errors() ) {
            return $skin->get_errors();
        }
        if ( ! $result ) {
            return new WP_Error( 'att_mcp_install_failed', 'The theme could not be installed (the server may need FTP credentials for file changes).' );
        }
        return array( 'installed' => true, 'slug' => $slug, 'name' => $api->name, 'version' => $api->version );
    }

    if ( 'activate' === $action ) {
        if ( ! current_user_can( 'switch_themes' ) ) {
            return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to switch themes.' );
        }
        $theme = wp_get_theme( $slug );
        if ( ! $theme->exists() || ! $theme->is_allowed() ) {
            return new WP_Error( 'att_mcp_no_theme', 'That theme is not installed (or not allowed on this site).' );
        }
        if ( $theme->errors() ) {
            return new WP_Error( 'att_mcp_broken_theme', 'That theme is broken: ' . $theme->errors()->get_error_message() );
        }
        $previous = get_stylesheet();
        switch_theme( $theme->get_stylesheet() );
        return array(
            'activated' => $theme->get_stylesheet(),
            'previous'  => $previous,
            'note'      => 'Theme switched. Menu locations and widgets may need to be reassigned (att/get-menus, att/update-menu). To go back, activate "' . $previous . '".',
        );
    }

    return new WP_Error( 'att_mcp_bad_action', 'action must be install or activate.' );
}
