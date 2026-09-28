<?php
/**
 * Site Editor abilities (block themes) — templates, template parts (header,
 * footer, …) and global styles (theme.json user styles).
 *
 * These call core's own REST controllers internally (att_mcp_rest), so every
 * capability check, theme-file → customized-template conversion, revision, and
 * the KSES filtering of global styles for users without unfiltered_html behave
 * exactly as in the Site Editor.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_site_editor_abilities() {
    $base = att_mcp_ability_base();
    $can  = function () { return current_user_can( 'edit_theme_options' ); };
    $type = array( 'type' => 'string', 'description' => 'wp_template (default) or wp_template_part.' );

    if ( att_mcp_is_enabled( 'att/get-block-templates' ) ) {
        att_mcp_register( 'att/get-block-templates', array_merge( $base, array(
            'label'               => 'Get Block Templates',
            'description'         => 'Lists the block theme\'s templates (home, single, page, archive, 404…) and template parts (header, footer…) with their IDs, source (theme file or customized) and area. Read one with att/get-block-template.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'type' => array( 'type' => 'string', 'description' => 'wp_template | wp_template_part | all (default).' ),
            ) ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_get_block_templates',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/get-block-template' ) ) {
        att_mcp_register( 'att/get-block-template', array_merge( $base, array(
            'label'               => 'Get Block Template',
            'description'         => 'Returns one template or template part with its full block markup. IDs look like "theme-slug//header".',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
                'id'   => array( 'type' => 'string', 'description' => 'Template ID, e.g. "twentytwentyfive//home".' ),
                'type' => $type,
            ) ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_get_block_template',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/save-block-template' ) ) {
        att_mcp_register( 'att/save-block-template', array_merge( $base, array(
            'label'               => 'Save Block Template',
            'description'         => 'Saves block markup to a template or template part. Pass "id" to edit an existing one (a theme file becomes a customized copy; revisions are kept), or "slug" + "title" to create a new custom template or part. Pass "id" + "reset": true to discard customizations and return to the theme\'s file. Read the current markup first.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'id'          => array( 'type' => 'string',  'description' => 'Existing template ID, e.g. "twentytwentyfive//footer".' ),
                'slug'        => array( 'type' => 'string',  'description' => 'New template slug (create), e.g. "landing" or "page-about".' ),
                'type'        => $type,
                'title'       => array( 'type' => 'string',  'description' => 'Template title.' ),
                'description' => array( 'type' => 'string',  'description' => 'Template description.' ),
                'content'     => array( 'type' => 'string',  'description' => 'Full block markup for the template.' ),
                'area'        => array( 'type' => 'string',  'description' => 'Template part area: header | footer | uncategorized.' ),
                'reset'       => array( 'type' => 'boolean', 'description' => 'Delete the customization (theme templates revert to the theme file; custom ones are removed).' ),
            ) ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_save_block_template',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/get-global-styles' ) ) {
        att_mcp_register( 'att/get-global-styles', array_merge( $base, array(
            'label'               => 'Get Global Styles',
            'description'         => 'Returns the site\'s global styles: the user customizations (what update-global-styles changes), plus the effective palette, font families, layout sizes and merged styles. Set include_theme to also get the theme\'s own theme.json values.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'include_theme' => array( 'type' => 'boolean', 'description' => 'Include the theme\'s base theme.json settings/styles (large). Default false.' ),
            ) ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_get_global_styles',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/update-global-styles' ) ) {
        att_mcp_register( 'att/update-global-styles', array_merge( $base, array(
            'label'               => 'Update Global Styles',
            'description'         => 'Changes global styles in theme.json shape: "settings" (e.g. {"color":{"palette":{"custom":[{"slug":"brand","color":"#0a7","name":"Brand"}]}}}) and "styles" (e.g. {"color":{"background":"#fff","text":"#111"},"typography":{"fontFamily":"var(--wp--preset--font-family--inter)"},"elements":{"link":{"color":{"text":"#0a7"}}}}). Objects merge into the current user styles; lists (like palettes) are replaced whole. replace:true overwrites everything. Previous versions stay available via att/list-revisions.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'settings' => array( 'type' => 'object', 'description' => 'theme.json "settings" to apply.' ),
                'styles'   => array( 'type' => 'object', 'description' => 'theme.json "styles" to apply.' ),
                'replace'  => array( 'type' => 'boolean', 'description' => 'Replace the user styles entirely instead of merging. Default false.' ),
            ) ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_update_global_styles',
        ) ) );
    }
}

/* ------------------------------------------------------------------------- */

function att_mcp_template_route( $type ) {
    return ( 'wp_template_part' === $type ) ? '/wp/v2/template-parts' : '/wp/v2/templates';
}

/** Map a REST template object to a compact payload. */
function att_mcp_template_payload( $t, $with_content = false ) {
    $row = array(
        'id'             => isset( $t['id'] ) ? $t['id'] : null,
        'wp_id'          => isset( $t['wp_id'] ) ? (int) $t['wp_id'] : 0,
        'slug'           => isset( $t['slug'] ) ? $t['slug'] : null,
        'type'           => isset( $t['type'] ) ? $t['type'] : null,
        'title'          => isset( $t['title']['raw'] ) ? $t['title']['raw'] : ( isset( $t['title']['rendered'] ) ? $t['title']['rendered'] : '' ),
        'description'    => isset( $t['description'] ) ? $t['description'] : '',
        'source'         => isset( $t['source'] ) ? $t['source'] : null,        // theme | custom
        'has_theme_file' => ! empty( $t['has_theme_file'] ),
        'is_custom'      => ! empty( $t['is_custom'] ),
        'area'           => isset( $t['area'] ) ? $t['area'] : null,
        'modified'       => isset( $t['modified'] ) ? $t['modified'] : null,
    );
    if ( $with_content ) {
        $row['content'] = isset( $t['content']['raw'] ) ? $t['content']['raw'] : ( is_string( $t['content'] ) ? $t['content'] : '' );
    }
    return $row;
}

function att_mcp_require_block_templates() {
    if ( ! current_theme_supports( 'block-templates' ) && ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
        return new WP_Error( 'att_mcp_not_block_theme', 'The active theme is not a block theme. Use theme mods, options, Additional CSS, menus and widgets instead.' );
    }
    return true;
}

function att_mcp_execute_get_block_templates( $input ) {
    $ok = att_mcp_require_block_templates();
    if ( is_wp_error( $ok ) ) {
        return $ok;
    }
    $want  = isset( $input['type'] ) ? sanitize_key( $input['type'] ) : 'all';
    $types = ( 'all' === $want || '' === $want ) ? array( 'wp_template', 'wp_template_part' ) : array( $want );
    $out   = array();
    foreach ( $types as $type ) {
        if ( ! in_array( $type, array( 'wp_template', 'wp_template_part' ), true ) ) {
            return new WP_Error( 'att_mcp_bad_type', 'type must be wp_template, wp_template_part, or all.' );
        }
        $res = att_mcp_rest( 'GET', att_mcp_template_route( $type ), array( 'context' => 'edit', 'per_page' => 100 ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        foreach ( (array) $res['data'] as $t ) {
            $out[ $type ][] = att_mcp_template_payload( $t );
        }
    }
    return array( 'theme' => get_stylesheet(), 'templates' => $out );
}

function att_mcp_execute_get_block_template( $input ) {
    $ok = att_mcp_require_block_templates();
    if ( is_wp_error( $ok ) ) {
        return $ok;
    }
    $id   = isset( $input['id'] ) ? sanitize_text_field( (string) $input['id'] ) : '';
    $type = ( isset( $input['type'] ) && 'wp_template_part' === $input['type'] ) ? 'wp_template_part' : 'wp_template';
    if ( '' === $id ) {
        return new WP_Error( 'att_mcp_no_id', 'A template "id" is required (see att/get-block-templates).' );
    }
    $res = att_mcp_rest( 'GET', att_mcp_template_route( $type ) . '/' . $id, array( 'context' => 'edit' ) );
    if ( is_wp_error( $res ) ) {
        return $res;
    }
    return att_mcp_template_payload( $res['data'], true );
}

function att_mcp_execute_save_block_template( $input ) {
    $ok = att_mcp_require_block_templates();
    if ( is_wp_error( $ok ) ) {
        return $ok;
    }
    $type  = ( isset( $input['type'] ) && 'wp_template_part' === $input['type'] ) ? 'wp_template_part' : 'wp_template';
    $route = att_mcp_template_route( $type );
    $id    = isset( $input['id'] ) ? sanitize_text_field( (string) $input['id'] ) : '';

    if ( $id && ! empty( $input['reset'] ) ) {
        $res = att_mcp_rest( 'DELETE', $route . '/' . $id, array( 'force' => true ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        return array( 'reset' => true, 'id' => $id, 'note' => 'Customization removed. Run att/purge-cache if a page cache is active.' );
    }

    $body = array();
    if ( isset( $input['content'] ) ) {
        $body['content'] = att_mcp_prepare_content( $input['content'] );
    }
    if ( isset( $input['title'] ) ) {
        $body['title'] = sanitize_text_field( (string) $input['title'] );
    }
    if ( isset( $input['description'] ) ) {
        $body['description'] = sanitize_text_field( (string) $input['description'] );
    }
    if ( 'wp_template_part' === $type && ! empty( $input['area'] ) && in_array( $input['area'], array( 'header', 'footer', 'uncategorized', 'sidebar', 'navigation-overlay' ), true ) ) {
        $body['area'] = sanitize_key( $input['area'] );
    }

    if ( $id ) {
        if ( ! $body ) {
            return new WP_Error( 'att_mcp_nothing_to_do', 'Pass content, title or description to save (or reset:true).' );
        }
        $res = att_mcp_rest( 'POST', $route . '/' . $id, $body );
    } else {
        $slug = isset( $input['slug'] ) ? sanitize_title( (string) $input['slug'] ) : '';
        if ( '' === $slug || ! isset( $body['content'] ) ) {
            return new WP_Error( 'att_mcp_missing', 'To create a template pass "slug", "title" and "content"; to edit one pass its "id".' );
        }
        $body['slug'] = $slug;
        if ( ! isset( $body['title'] ) ) {
            $body['title'] = ucwords( str_replace( '-', ' ', $slug ) );
        }
        $res = att_mcp_rest( 'POST', $route, $body );
    }
    if ( is_wp_error( $res ) ) {
        return $res;
    }
    $saved         = att_mcp_template_payload( $res['data'] );
    $saved['note'] = 'Saved. Check it with att/render-page and run att/purge-cache if a page cache is active. Older versions: att/list-revisions with id ' . $saved['wp_id'] . '.';
    return $saved;
}

/** User global-styles post ID for the active theme (created on first use). */
function att_mcp_global_styles_id() {
    if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) || ! function_exists( 'wp_theme_has_theme_json' ) || ! wp_theme_has_theme_json() ) {
        return new WP_Error( 'att_mcp_no_theme_json', 'Global styles need a block theme (or a classic theme with theme.json).' );
    }
    $id = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
    return $id ? $id : new WP_Error( 'att_mcp_no_global_styles', 'Could not find or create the global styles record.' );
}

function att_mcp_execute_get_global_styles( $input ) {
    $id = att_mcp_global_styles_id();
    if ( is_wp_error( $id ) ) {
        return $id;
    }
    $user = att_mcp_rest( 'GET', '/wp/v2/global-styles/' . $id, array( 'context' => 'edit' ) );
    if ( is_wp_error( $user ) ) {
        return $user;
    }
    $out = array(
        'id'        => $id,
        'theme'     => get_stylesheet(),
        'user'      => array(
            'settings' => isset( $user['data']['settings'] ) ? $user['data']['settings'] : array(),
            'styles'   => isset( $user['data']['styles'] ) ? $user['data']['styles'] : array(),
        ),
        'effective' => array(
            'palette'       => wp_get_global_settings( array( 'color', 'palette' ) ),
            'font_families' => wp_get_global_settings( array( 'typography', 'fontFamilies' ) ),
            'font_sizes'    => wp_get_global_settings( array( 'typography', 'fontSizes' ) ),
            'spacing_sizes' => wp_get_global_settings( array( 'spacing', 'spacingSizes' ) ),
            'layout'        => wp_get_global_settings( array( 'layout' ) ),
            'styles'        => wp_get_global_styles(),
        ),
    );
    if ( ! empty( $input['include_theme'] ) ) {
        $theme = att_mcp_rest( 'GET', '/wp/v2/global-styles/themes/' . get_stylesheet(), array() );
        if ( ! is_wp_error( $theme ) ) {
            $out['theme_json'] = array(
                'settings' => isset( $theme['data']['settings'] ) ? $theme['data']['settings'] : array(),
                'styles'   => isset( $theme['data']['styles'] ) ? $theme['data']['styles'] : array(),
            );
        }
    }
    return $out;
}

/** Recursive merge where associative arrays merge and lists are replaced whole. */
function att_mcp_merge_assoc( $base, $changes ) {
    if ( ! is_array( $base ) || ! is_array( $changes ) || ( $changes && array_keys( $changes ) === range( 0, count( $changes ) - 1 ) ) ) {
        return $changes;
    }
    foreach ( $changes as $key => $value ) {
        $base[ $key ] = ( isset( $base[ $key ] ) && is_array( $base[ $key ] ) && is_array( $value ) ) ? att_mcp_merge_assoc( $base[ $key ], $value ) : $value;
    }
    return $base;
}

function att_mcp_execute_update_global_styles( $input ) {
    $id = att_mcp_global_styles_id();
    if ( is_wp_error( $id ) ) {
        return $id;
    }
    $settings = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? $input['settings'] : null;
    $styles   = ( isset( $input['styles'] ) && is_array( $input['styles'] ) ) ? $input['styles'] : null;
    if ( null === $settings && null === $styles ) {
        return new WP_Error( 'att_mcp_nothing_to_do', 'Pass "settings" and/or "styles".' );
    }

    $current = array( 'settings' => array(), 'styles' => array() );
    if ( empty( $input['replace'] ) ) {
        $res = att_mcp_rest( 'GET', '/wp/v2/global-styles/' . $id, array( 'context' => 'edit' ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $current['settings'] = isset( $res['data']['settings'] ) ? (array) $res['data']['settings'] : array();
        $current['styles']   = isset( $res['data']['styles'] ) ? (array) $res['data']['styles'] : array();
    }

    $body = array(
        'settings' => null !== $settings ? att_mcp_merge_assoc( $current['settings'], $settings ) : $current['settings'],
        'styles'   => null !== $styles ? att_mcp_merge_assoc( $current['styles'], $styles ) : $current['styles'],
    );
    $saved = att_mcp_rest( 'POST', '/wp/v2/global-styles/' . $id, $body );
    if ( is_wp_error( $saved ) ) {
        return $saved;
    }
    // Core caches merged theme.json data for the rest of the request; drop it so
    // the response (and any later read in this request) reflects the save.
    if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
        wp_clean_theme_json_cache();
    }
    return array(
        'updated'  => true,
        'id'       => $id,
        'settings' => isset( $saved['data']['settings'] ) ? $saved['data']['settings'] : array(),
        'styles'   => isset( $saved['data']['styles'] ) ? $saved['data']['styles'] : array(),
        'note'     => 'Run att/purge-cache if a page cache is active. Earlier versions: att/list-revisions with id ' . $id . '.',
    );
}
