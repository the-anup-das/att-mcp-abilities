<?php
/**
 * Code Snippets abilities — let an MCP agent create/read/update/delete entries
 * in the Code Snippets plugin (CSS / JS / HTML, with PHP gated). This is the
 * "run code the WordPress-native way" lever for things CSS + plugin settings
 * can't do (custom font enqueue, byline via a theme hook, etc.).
 *
 * Only registers if the Code Snippets plugin's API is present (see registry.php).
 * Uses the plugin's own API (\Code_Snippets\save_snippet, get_snippets, …) — it
 * stores snippets in a custom table, so the generic update-option tool can't.
 *
 * Gates:
 *  - PHP scopes need the ADMIN-side "Allow PHP" control (MCP > Settings) — an
 *    agent-supplied flag alone is never enough — plus unfiltered_html, and are
 *    always off when DISALLOW_FILE_EDIT is set.
 *  - JS / HTML scopes inject markup into every page, so they need unfiltered_html.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** CSS scopes (no code execution). */
function att_mcp_snippets_css_scopes() {
    return array( 'site-css', 'admin-css' );
}

/** JS / HTML scopes — output on every page; require unfiltered_html. */
function att_mcp_snippets_markup_scopes() {
    return array( 'site-head-js', 'site-footer-js', 'content', 'head-content', 'footer-content' );
}

/** PHP scopes — gated behind the admin "Allow PHP" control. */
function att_mcp_snippets_php_scopes() {
    return array( 'global', 'admin', 'front-end', 'single-use' );
}

function att_mcp_snippets_api_loaded() {
    return function_exists( 'Code_Snippets\\save_snippet' ) && class_exists( 'Code_Snippets\\Snippet' );
}

function att_mcp_register_snippets_abilities() {
    $base = att_mcp_ability_base();
    $can  = function () { return current_user_can( 'manage_options' ); };

    if ( att_mcp_is_enabled( 'att/get-snippets' ) ) {
        att_mcp_register( 'att/get-snippets', array_merge( $base, array(
            'label'               => 'Get Snippets',
            'description'         => 'Lists all Code Snippets entries (id, name, scope, active state, tags, and code). Read this before editing one.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_get_snippets',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/save-snippet' ) ) {
        att_mcp_register( 'att/save-snippet', array_merge( $base, array(
            'label'               => 'Save Snippet',
            'description'         => 'Creates a new Code Snippet (omit id) or updates one (pass id). For CSS use scope "site-css"; JS use "site-footer-js"/"site-head-js"; HTML use "content"/"head-content"/"footer-content". PHP scopes (global/admin/front-end/single-use) only work when the site administrator has turned on "Allow PHP" in MCP > Settings, and also need "allow_php": true as confirmation. CSS/JS/HTML code is stored as-is (no wrapping tags); PHP code omits the opening <?php tag. PHP snippets are activated safely: if they error they stay off.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'id'          => array( 'type' => 'integer', 'description' => 'Existing snippet id to update. Omit to create a new one.' ),
                    'name'        => array( 'type' => 'string', 'description' => 'Snippet title (required when creating).' ),
                    'code'        => array( 'type' => 'string', 'description' => 'The snippet body (required when creating).' ),
                    'scope'       => array( 'type' => 'string', 'description' => 'site-css, admin-css, site-head-js, site-footer-js, content, head-content, footer-content, or (gated) global/admin/front-end/single-use.' ),
                    'description' => array( 'type' => 'string', 'description' => 'Optional description.' ),
                    'tags'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional tags.' ),
                    'active'      => array( 'type' => 'boolean', 'description' => 'Whether the snippet is active. Default true.' ),
                    'priority'    => array( 'type' => 'integer', 'description' => 'Execution priority (default 10).' ),
                    'allow_php'   => array( 'type' => 'boolean', 'description' => 'Confirmation flag for PHP scopes (the admin control must also be on).' ),
                ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_save_snippet',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/delete-snippet' ) ) {
        att_mcp_register( 'att/delete-snippet', array_merge( $base, array(
            'label'               => 'Delete Snippet',
            'description'         => 'Deletes a Code Snippets entry by id.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array( 'id' => array( 'type' => 'integer', 'description' => 'Snippet id to delete.' ) ),
                'required'   => array( 'id' ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_delete_snippet',
        ) ) );
    }
}

function att_mcp_execute_get_snippets( $input ) {
    if ( ! att_mcp_snippets_api_loaded() ) {
        return new WP_Error( 'att_mcp_no_code_snippets', 'The Code Snippets plugin is not active.' );
    }

    $out = array();
    foreach ( \Code_Snippets\get_snippets() as $s ) {
        $out[] = array(
            'id'       => (int) $s->id,
            'name'     => $s->name,
            'scope'    => $s->scope,
            'active'   => (bool) $s->active,
            'priority' => (int) $s->priority,
            'tags'     => is_array( $s->tags ) ? array_values( $s->tags ) : array(),
            'code'     => $s->code,
        );
    }

    return array(
        'snippets'    => $out,
        'total'       => count( $out ),
        'php_allowed' => att_mcp_php_snippets_allowed(),
    );
}

/** Check the current user may save a snippet in this scope. */
function att_mcp_snippet_scope_allowed( $scope, $confirmed_php ) {
    $css    = att_mcp_snippets_css_scopes();
    $markup = att_mcp_snippets_markup_scopes();
    $php    = att_mcp_snippets_php_scopes();

    if ( in_array( $scope, $css, true ) ) {
        return true;
    }
    if ( in_array( $scope, $markup, true ) ) {
        return current_user_can( 'unfiltered_html' )
            ? true
            : new WP_Error( 'att_mcp_forbidden', 'JavaScript/HTML snippets require the unfiltered_html capability.' );
    }
    if ( in_array( $scope, $php, true ) ) {
        if ( ! att_mcp_php_snippets_allowed() ) {
            return new WP_Error( 'att_mcp_php_blocked', 'PHP snippets are disabled. The site administrator must turn on "Allow PHP" in MCP > Settings > MCP Controls (it is always off when DISALLOW_FILE_EDIT is set).' );
        }
        if ( ! $confirmed_php ) {
            return new WP_Error( 'att_mcp_php_confirm', 'PHP snippet scopes also require "allow_php": true — PHP can break the site, so opt in deliberately.' );
        }
        return current_user_can( 'unfiltered_html' )
            ? true
            : new WP_Error( 'att_mcp_forbidden', 'PHP snippets require the unfiltered_html capability.' );
    }
    return new WP_Error( 'att_mcp_bad_scope', 'Unsupported scope. Allowed: ' . implode( ', ', array_merge( $css, $markup, $php ) ) . '.' );
}

function att_mcp_execute_save_snippet( $input ) {
    if ( ! att_mcp_snippets_api_loaded() ) {
        return new WP_Error( 'att_mcp_no_code_snippets', 'The Code Snippets plugin is not active.' );
    }

    $id       = isset( $input['id'] ) ? (int) $input['id'] : 0;
    $existing = null;
    if ( $id > 0 ) {
        $existing = \Code_Snippets\get_snippet( $id );
        if ( ! $existing || ! $existing->id ) {
            return new WP_Error( 'att_mcp_no_snippet', 'No snippet found with that id.' );
        }
        // Editing an existing PHP snippet (even without changing its scope) is running code.
        if ( in_array( $existing->scope, att_mcp_snippets_php_scopes(), true ) ) {
            $gate = att_mcp_snippet_scope_allowed( $existing->scope, ! empty( $input['allow_php'] ) );
            if ( is_wp_error( $gate ) ) {
                return $gate;
            }
        }
    }

    $scope = isset( $input['scope'] ) ? sanitize_key( $input['scope'] ) : ( $existing ? $existing->scope : '' );
    if ( '' === $scope ) {
        return new WP_Error( 'att_mcp_no_scope', 'A "scope" is required (e.g. site-css, site-footer-js, content).' );
    }
    $gate = att_mcp_snippet_scope_allowed( $scope, ! empty( $input['allow_php'] ) );
    if ( is_wp_error( $gate ) ) {
        return $gate;
    }

    $name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : ( $existing ? $existing->name : '' );
    $code = isset( $input['code'] ) ? (string) $input['code'] : ( $existing ? $existing->code : '' );
    if ( '' === $name ) {
        return new WP_Error( 'att_mcp_no_name', 'A snippet "name" is required.' );
    }
    if ( '' === trim( $code ) ) {
        return new WP_Error( 'att_mcp_no_code', 'Snippet "code" is required.' );
    }

    $want_active = isset( $input['active'] ) ? (bool) $input['active'] : ( $existing ? (bool) $existing->active : true );

    // Save INACTIVE first, then activate via activate_snippet() — for PHP that
    // step detects fatal/parse errors and leaves the snippet off if it breaks.
    $data = array(
        'id'       => $id,
        'scope'    => $scope,
        'name'     => $name,
        'desc'     => isset( $input['description'] ) ? wp_kses_post( (string) $input['description'] ) : ( $existing ? $existing->desc : '' ),
        'code'     => $code,
        'priority' => isset( $input['priority'] ) ? (int) $input['priority'] : ( $existing ? (int) $existing->priority : 10 ),
        'active'   => false,
    );
    if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
        $data['tags'] = array_map( 'sanitize_text_field', $input['tags'] );
    }

    $saved = \Code_Snippets\save_snippet( $data );
    if ( ! $saved || empty( $saved->id ) ) {
        return new WP_Error( 'att_mcp_save_failed', 'Code Snippets failed to save the snippet.' );
    }

    $activation = null;
    if ( $want_active ) {
        $result = \Code_Snippets\activate_snippet( (int) $saved->id );
        if ( is_string( $result ) ) {
            // Error string means activation failed (e.g. PHP error) — left inactive.
            $activation = array( 'activated' => false, 'error' => $result );
        } else {
            $activation = array( 'activated' => true );
        }
    }

    return array(
        'saved'      => true,
        'id'         => (int) $saved->id,
        'scope'      => $saved->scope,
        'name'       => $saved->name,
        'active'     => $activation ? ! empty( $activation['activated'] ) : false,
        'activation' => $activation,
        'note'       => 'For CSS/JS snippets, purge the cache so the change appears.',
    );
}

function att_mcp_execute_delete_snippet( $input ) {
    if ( ! att_mcp_snippets_api_loaded() ) {
        return new WP_Error( 'att_mcp_no_code_snippets', 'The Code Snippets plugin is not active.' );
    }

    $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
    if ( $id <= 0 ) {
        return new WP_Error( 'att_mcp_no_id', 'A snippet "id" is required.' );
    }

    $deleted = \Code_Snippets\delete_snippet( $id );
    return $deleted
        ? array( 'deleted' => true, 'id' => $id )
        : new WP_Error( 'att_mcp_delete_failed', 'The snippet could not be deleted.' );
}
