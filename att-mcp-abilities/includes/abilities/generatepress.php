<?php
/**
 * GeneratePress abilities — read/change GeneratePress settings and create,
 * inspect, edit, and remove GeneratePress Elements over MCP.
 *
 * Why dedicated settings tools (not the generic get/update-option):
 *  - The write READS the current option, MERGES the partial change, and writes
 *    the full structure — a blind update_option would wipe prior non-default keys.
 *  - GeneratePress generates its front-end CSS from generate_settings and CACHES
 *    it in a SEPARATE option (generate_dynamic_css_output). A plain settings
 *    write does NOT refresh that cache, so the change wouldn't appear. The write
 *    ability regenerates it (source-confirmed against GeneratePress 3.6.1).
 *
 * Settings facts confirmed from the GeneratePress theme SVN (3.6.1):
 *  - option `generate_settings`; reader generate_get_option(); defaults generate_get_defaults().
 *  - nested arrays: global_colors [{name,slug,color}], font_manager
 *    [{fontFamily,googleFont,googleFontVariants,googleFontCategory}], typography [per-selector].
 *  - dynamic CSS cache: generate_dynamic_css_output + generate_dynamic_css_cached_version.
 *
 * Elements (GP Premium, closed-source) are `gp_elements` posts configured by
 * `_generate_*` post meta. save-element writes through the hardened content
 * core and only accepts `_generate_*` meta keys; the agent is told to confirm
 * the exact keys on this install with get-element first. Hook elements that
 * execute PHP are gated behind the admin-only "Allow PHP" control.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_generatepress_abilities() {
    $base = att_mcp_ability_base();
    $can  = function () { return current_user_can( 'edit_theme_options' ); };

    // ---- Settings: read ----------------------------------------------------
    if ( att_mcp_is_enabled( 'att/get-generatepress-settings' ) ) {
        att_mcp_register( 'att/get-generatepress-settings', array_merge( $base, array(
            'label'               => 'Get GeneratePress Settings',
            'description'         => 'Returns GeneratePress settings (the generate_settings option) merged with the theme defaults — layout, container width, global_colors, font_manager, typography, etc. Pass optional "keys" to narrow the result. (Spacing lives in a separate option, generate_spacing_settings — use get-option for that.)',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'keys' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional list of top-level setting keys to return.' ),
                ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_get_generatepress_settings',
        ) ) );
    }

    // ---- Settings: write ---------------------------------------------------
    if ( att_mcp_is_enabled( 'att/update-generatepress-settings' ) ) {
        att_mcp_register( 'att/update-generatepress-settings', array_merge( $base, array(
            'label'               => 'Update GeneratePress Settings',
            'description'         => 'Changes GeneratePress settings: pass a "changes" object of generate_settings keys. Only those keys change (read-merge-write preserves the rest), and the cached dynamic CSS is regenerated so the change appears. NESTED arrays (global_colors, typography, font_manager) are replaced WHOLE — read the current full array first, edit it, and pass it back complete. Values are stored as-given, so use valid types/shapes. Undoable with att/undo-change.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'changes' => array( 'type' => 'object', 'description' => 'Map of generate_settings keys to new values, e.g. {"container_width":900,"content_layout_setting":"one-container"}.' ),
                ),
                'required'   => array( 'changes' ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_update_generatepress_settings',
        ) ) );
    }

    // ---- Elements: read (list) --------------------------------------------
    if ( att_mcp_is_enabled( 'att/list-elements' ) ) {
        att_mcp_register( 'att/list-elements', array_merge( $base, array(
            'label'               => 'List GeneratePress Elements',
            'description'         => 'Lists GeneratePress Elements (gp_elements): id, title, status, element type, block type, and hook. Requires GP Premium.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array( 'per_page' => array( 'type' => 'integer', 'description' => 'Max elements to return, 1–100 (default 50).' ) ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_list_elements',
        ) ) );
    }

    // ---- Elements: read (single, full meta) -------------------------------
    if ( att_mcp_is_enabled( 'att/get-element' ) ) {
        att_mcp_register( 'att/get-element', array_merge( $base, array(
            'label'               => 'Get GeneratePress Element',
            'description'         => 'Returns one GeneratePress Element by id: title, status, content, and ALL post meta — use it to learn the exact meta keys/values this GP Premium version uses before calling save-element. Requires GP Premium.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array( 'id' => array( 'type' => 'integer', 'description' => 'Element (gp_elements) post id.' ) ),
                'required'   => array( 'id' ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_get_element',
        ) ) );
    }

    // ---- Elements: write --------------------------------------------------
    if ( att_mcp_is_enabled( 'att/save-element' ) ) {
        att_mcp_register( 'att/save-element', array_merge( $base, array(
            'label'               => 'Save GeneratePress Element',
            'description'         => 'Creates (omit id) or updates (pass id) a GeneratePress Element. element_type: hook | block | header | layout. Convenience fields map to meta: hook → _generate_hook, priority → _generate_hook_priority, block_type → _generate_block_type (e.g. site-header, site-footer, hook, page-hero, content-template), display_rules → _generate_element_display_conditions ([{"rule":"general:site","object":""}]), exclude_rules → _generate_element_exclude_conditions, user_rules → _generate_element_user_conditions (["general:all"]). Any other "_generate_*" key can be passed in "meta". Read a similar existing element with get-element first to confirm keys and value formats. Status publish = active, draft = inactive. Elements that execute PHP require the site admin\'s "Allow PHP" control.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'id'            => array( 'type' => 'integer', 'description' => 'Element ID to update. Omit to create.' ),
                    'title'         => array( 'type' => 'string',  'description' => 'Element title.' ),
                    'content'       => array( 'type' => 'string',  'description' => 'Element content: block markup (block elements) or HTML (hook elements).' ),
                    'status'        => array( 'type' => 'string',  'description' => 'publish (active) | draft (inactive).' ),
                    'element_type'  => array( 'type' => 'string',  'description' => 'hook | block | header | layout.' ),
                    'block_type'    => array( 'type' => 'string',  'description' => 'For block elements, e.g. site-header, site-footer, hook, right-sidebar, page-hero, content-template, loop-template, post-meta-template.' ),
                    'hook'          => array( 'type' => 'string',  'description' => 'Hook name, e.g. generate_after_header, generate_before_footer.' ),
                    'priority'      => array( 'type' => 'integer', 'description' => 'Hook priority (default 10).' ),
                    'display_rules' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Where it shows: [{"rule":"general:site","object":""}], [{"rule":"post:page","object":"42"}], …' ),
                    'exclude_rules' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Where it must not show (same shape).' ),
                    'user_rules'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Who sees it, e.g. ["general:all"], ["general:logged_in"].' ),
                    'meta'          => array( 'type' => 'object',  'description' => 'Other _generate_* meta keys to set (null deletes).' ),
                ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_save_element',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/delete-element' ) ) {
        att_mcp_register( 'att/delete-element', array_merge( $base, array(
            'label'               => 'Delete GeneratePress Element',
            'description'         => 'Moves a GeneratePress Element to trash (restorable in WP Admin).',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array( 'id' => array( 'type' => 'integer', 'description' => 'Element ID.' ) ),
                'required'   => array( 'id' ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_delete_element',
        ) ) );
    }
}

/* ------------------------------------------------------------------------- */
/* Settings callbacks                                                         */
/* ------------------------------------------------------------------------- */

/** Regenerate GeneratePress's separately cached dynamic CSS. */
function att_mcp_gp_refresh_css() {
    $status = 'none';
    if ( function_exists( 'generate_update_dynamic_css_cache' ) ) {
        generate_update_dynamic_css_cache();
        $status = 'regenerated';
    } elseif ( function_exists( 'generate_get_dynamic_css' ) ) {
        update_option( 'generate_dynamic_css_output', wp_strip_all_tags( generate_get_dynamic_css() ) );
        $status = 'regenerated';
    } else {
        delete_option( 'generate_dynamic_css_output' );
        $status = 'invalidated';
    }
    delete_option( 'generate_dynamic_css_cached_version' ); // force rebuild on next init
    return $status;
}

function att_mcp_execute_get_generatepress_settings( $input ) {
    if ( ! function_exists( 'generate_get_defaults' ) ) {
        return new WP_Error( 'att_mcp_gp_inactive', 'GeneratePress is not active on this site.' );
    }

    $defaults = generate_get_defaults();
    $stored   = get_option( 'generate_settings', array() );
    if ( ! is_array( $stored ) ) {
        $stored = array();
    }

    $merged = wp_parse_args( $stored, $defaults );

    if ( ! empty( $input['keys'] ) && is_array( $input['keys'] ) ) {
        $want = array();
        foreach ( $input['keys'] as $k ) {
            $k = sanitize_key( $k );
            if ( array_key_exists( $k, $merged ) ) {
                $want[ $k ] = $merged[ $k ];
            }
        }
        $merged = $want;
    }

    return array(
        'option'        => 'generate_settings',
        'theme'         => get_template(),
        'version'       => defined( 'GENERATE_VERSION' ) ? GENERATE_VERSION : null,
        'settings'      => $merged,
        'stored_keys'   => array_keys( $stored ),
        'nested_arrays' => array( 'global_colors', 'font_manager', 'typography' ),
        'note'          => 'Spacing settings live in the separate option "generate_spacing_settings" and are not included here.',
    );
}

function att_mcp_execute_update_generatepress_settings( $input ) {
    if ( ! function_exists( 'generate_get_defaults' ) ) {
        return new WP_Error( 'att_mcp_gp_inactive', 'GeneratePress is not active on this site.' );
    }

    $changes = isset( $input['changes'] ) ? $input['changes'] : null;
    if ( ! is_array( $changes ) || array() === $changes ) {
        return new WP_Error( 'att_mcp_no_changes', 'Provide a non-empty "changes" object of generate_settings keys to set.' );
    }

    $clean = array();
    foreach ( $changes as $k => $v ) {
        $clean[ sanitize_key( $k ) ] = att_mcp_filter_untrusted( $v );
    }

    // Read RAW option (not defaults) so prior non-default values are preserved.
    $current = get_option( 'generate_settings', array() );
    if ( ! is_array( $current ) ) {
        $current = array();
    }

    $change_id = att_mcp_snapshot( 'option', 'generate_settings', 'GeneratePress settings: ' . implode( ', ', array_keys( $clean ) ) );

    // Merge partial change on top, write the full structure. Nested arrays
    // (global_colors/typography/font_manager) are replaced whole by the caller.
    $updated    = update_option( 'generate_settings', array_merge( $current, $clean ) );
    $css_status = att_mcp_gp_refresh_css();

    $after  = get_option( 'generate_settings', array() );
    $values = array();
    foreach ( array_keys( $clean ) as $k ) {
        $values[ $k ] = isset( $after[ $k ] ) ? $after[ $k ] : null;
    }

    return array(
        'updated'      => (bool) $updated, // false on identical no-op as well as failure
        'option'       => 'generate_settings',
        'changed_keys' => array_keys( $clean ),
        'values'       => $values,
        'css_cache'    => $css_status,
        'change_id'    => $change_id,
        'note'         => 'If a page/object cache plugin is active, also run att/purge-cache — inline CSS may be embedded in cached HTML.',
    );
}

/* ------------------------------------------------------------------------- */
/* Elements callbacks                                                         */
/* ------------------------------------------------------------------------- */

function att_mcp_gp_elements_available() {
    return post_type_exists( 'gp_elements' )
        ? true
        : new WP_Error( 'att_mcp_no_gp_elements', 'GeneratePress Elements (GP Premium) is not active on this site.' );
}

function att_mcp_execute_list_elements( $input ) {
    $ok = att_mcp_gp_elements_available();
    if ( is_wp_error( $ok ) ) {
        return $ok;
    }

    $posts = get_posts( array(
        'post_type'   => 'gp_elements',
        'post_status' => array( 'publish', 'draft', 'private' ),
        'numberposts' => att_mcp_int_arg( $input, 'per_page', 50, 1, 100 ),
        'orderby'     => 'date',
        'order'       => 'DESC',
    ) );

    $out = array();
    foreach ( $posts as $p ) {
        $out[] = array(
            'id'            => $p->ID,
            'title'         => $p->post_title,
            'status'        => $p->post_status,
            'element_type'  => get_post_meta( $p->ID, '_generate_element_type', true ),
            'block_type'    => get_post_meta( $p->ID, '_generate_block_type', true ),
            'hook'          => get_post_meta( $p->ID, '_generate_hook', true ),
            'hook_priority' => get_post_meta( $p->ID, '_generate_hook_priority', true ),
        );
    }

    return array( 'elements' => $out, 'total' => count( $out ) );
}

function att_mcp_execute_get_element( $input ) {
    $ok = att_mcp_gp_elements_available();
    if ( is_wp_error( $ok ) ) {
        return $ok;
    }

    $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
    $p  = $id > 0 ? get_post( $id ) : null;
    if ( ! $p || 'gp_elements' !== $p->post_type ) {
        return new WP_Error( 'att_mcp_not_element', 'No GeneratePress Element found with that id.' );
    }

    // Return ALL meta (single-value keys flattened) so the exact schema is visible.
    $flat = array();
    foreach ( get_post_meta( $id ) as $k => $vals ) {
        if ( att_mcp_is_secret_name( $k ) ) {
            continue;
        }
        if ( is_array( $vals ) && 1 === count( $vals ) ) {
            $flat[ $k ] = maybe_unserialize( $vals[0] );
        } else {
            $flat[ $k ] = array_map( 'maybe_unserialize', (array) $vals );
        }
    }

    return array(
        'id'      => $p->ID,
        'title'   => $p->post_title,
        'status'  => $p->post_status,
        'content' => $p->post_content,
        'meta'    => $flat,
    );
}

/** Does a stored/new "Execute PHP" meta value turn PHP execution on? */
function att_mcp_gp_truthy( $value ) {
    return ! ( empty( $value ) || 'false' === $value || '0' === $value );
}

/**
 * PHP gate for Elements: an Element with "Execute PHP" turns its content into
 * code, so enabling that flag — or changing the content of an Element that has
 * it — needs the admin-only "Allow PHP" control. Called by save-element and by
 * the generic content core (so att/update-content cannot bypass it).
 */
function att_mcp_gp_php_gate( $id, $meta, $content_changing ) {
    $meta     = is_array( $meta ) ? $meta : array();
    $exec_new = array_key_exists( '_generate_hook_execute_php', $meta ) ? att_mcp_gp_truthy( $meta['_generate_hook_execute_php'] ) : null;
    $exec_old = $id > 0 ? att_mcp_gp_truthy( get_post_meta( $id, '_generate_hook_execute_php', true ) ) : false;
    $runs_php = ( null === $exec_new ) ? $exec_old : $exec_new;
    if ( $runs_php && ( true === $exec_new || $content_changing ) && ! ( att_mcp_php_snippets_allowed() && current_user_can( 'unfiltered_html' ) ) ) {
        return new WP_Error( 'att_mcp_php_blocked', 'This Element executes PHP. The site administrator must turn on "Allow PHP" in MCP > Settings > MCP Controls first.' );
    }
    return true;
}

function att_mcp_execute_save_element( $input ) {
    $ok = att_mcp_gp_elements_available();
    if ( is_wp_error( $ok ) ) {
        return $ok;
    }

    $meta = ( isset( $input['meta'] ) && is_array( $input['meta'] ) ) ? $input['meta'] : array();
    foreach ( array_keys( $meta ) as $key ) {
        if ( 0 !== strpos( (string) $key, '_generate_' ) ) {
            return new WP_Error( 'att_mcp_bad_meta', sprintf( 'Only _generate_* meta keys can be set on Elements ("%s" refused).', $key ) );
        }
    }
    $map = array(
        'element_type'  => '_generate_element_type',
        'block_type'    => '_generate_block_type',
        'hook'          => '_generate_hook',
        'priority'      => '_generate_hook_priority',
        'display_rules' => '_generate_element_display_conditions',
        'exclude_rules' => '_generate_element_exclude_conditions',
        'user_rules'    => '_generate_element_user_conditions',
    );
    foreach ( $map as $field => $key ) {
        if ( isset( $input[ $field ] ) ) {
            $meta[ $key ] = is_string( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : $input[ $field ];
        }
    }
    if ( isset( $meta['_generate_element_type'] ) && ! in_array( $meta['_generate_element_type'], array( 'hook', 'block', 'header', 'layout' ), true ) ) {
        return new WP_Error( 'att_mcp_bad_element_type', 'element_type must be hook, block, header, or layout.' );
    }

    $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
    if ( $id <= 0 && empty( $meta['_generate_element_type'] ) ) {
        return new WP_Error( 'att_mcp_no_element_type', 'element_type is required when creating an Element.' );
    }

    // (The PHP gate for "Execute PHP" Elements runs inside att_mcp_content_save().)
    $data = array( 'meta' => $meta );
    foreach ( array( 'id', 'title', 'content', 'status' ) as $field ) {
        if ( isset( $input[ $field ] ) ) {
            $data[ $field ] = $input[ $field ];
        }
    }
    if ( $id <= 0 && ! isset( $data['status'] ) ) {
        $data['status'] = 'draft';
    }

    $result = att_mcp_content_save( $data, 'gp_elements' );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    $result['note'] = 'Saved. Status publish makes the Element active. Check it with att/render-page and run att/purge-cache if a page cache is active.';
    return $result;
}

function att_mcp_execute_delete_element( $input ) {
    $ok = att_mcp_gp_elements_available();
    if ( is_wp_error( $ok ) ) {
        return $ok;
    }
    unset( $input['force'] );
    return att_mcp_content_delete( $input, 'gp_elements' );
}
