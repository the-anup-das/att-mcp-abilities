<?php
/**
 * Elementor abilities — inspect and edit Elementor page structures (element
 * trees stored as JSON in `_elementor_data` post meta), write whole layouts,
 * and read/update the global kit (Site Settings: colors, fonts, layout).
 *
 * Hardening:
 *  - every read/write checks edit_post on the specific post,
 *  - settings from users without unfiltered_html are KSES-filtered (an HTML
 *    widget must not become a stored-XSS path for authors/editors),
 *  - every write records an undo point (att/undo-change) before saving.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────

function att_mcp_elementor_is_active() {
    return class_exists( '\Elementor\Plugin' );
}

/** Clear Elementor's generated CSS/file cache. */
function att_mcp_elementor_clear_cache() {
    if ( att_mcp_elementor_is_active() && isset( \Elementor\Plugin::$instance->files_manager ) ) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
}

/** Resolve a post the current user may edit. */
function att_mcp_elementor_post( $post_id ) {
    $post = get_post( (int) $post_id );
    if ( ! $post ) {
        return new WP_Error( 'att_mcp_not_found', 'No post found with that ID.' );
    }
    if ( ! current_user_can( 'edit_post', $post->ID ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to edit this post.' );
    }
    return $post;
}

/** Read and decode a page's element tree (WP_Error if not an Elementor page / not editable). */
function att_mcp_elementor_get_data( $post_id ) {
    $post = att_mcp_elementor_post( $post_id );
    if ( is_wp_error( $post ) ) {
        return $post;
    }
    if ( 'builder' !== get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
        return new WP_Error( 'att_mcp_not_elementor_page', 'This post was not built with Elementor. Use att/elementor-save-page to give it an Elementor layout.' );
    }
    $raw  = get_post_meta( $post->ID, '_elementor_data', true );
    $data = $raw ? json_decode( is_string( $raw ) ? $raw : wp_json_encode( $raw ), true ) : array();
    return is_array( $data ) ? $data : array();
}

/** Save a page's element tree (records an undo point first). */
function att_mcp_elementor_save_data( $post_id, $data, $label = 'Elementor layout' ) {
    att_mcp_record_change( array(
        att_mcp_capture( 'post_meta', array( $post_id, '_elementor_data' ) ),
        att_mcp_capture( 'post_meta', array( $post_id, '_elementor_edit_mode' ) ),
    ), sprintf( '%s on #%d', $label, $post_id ) );

    update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
    update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
    delete_post_meta( $post_id, '_elementor_css' ); // per-post CSS regenerates on next view
    att_mcp_elementor_clear_cache();
}

function att_mcp_elementor_generate_id() {
    return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 );
}

// Recursively find an element by ID.
function att_mcp_elementor_find_by_id( $elements, $id ) {
    foreach ( (array) $elements as $el ) {
        if ( isset( $el['id'] ) && $el['id'] === $id ) {
            return $el;
        }
        if ( ! empty( $el['elements'] ) ) {
            $found = att_mcp_elementor_find_by_id( $el['elements'], $id );
            if ( null !== $found ) {
                return $found;
            }
        }
    }
    return null;
}

// Recursively remove an element by ID.
function att_mcp_elementor_remove_by_id( &$elements, $id ) {
    foreach ( $elements as $i => $el ) {
        if ( isset( $el['id'] ) && $el['id'] === $id ) {
            array_splice( $elements, $i, 1 );
            return true;
        }
        if ( ! empty( $elements[ $i ]['elements'] ) ) {
            if ( att_mcp_elementor_remove_by_id( $elements[ $i ]['elements'], $id ) ) {
                return true;
            }
        }
    }
    return false;
}

// Recursively merge settings into an element by ID.
function att_mcp_elementor_update_by_id( &$elements, $id, $settings ) {
    foreach ( $elements as &$el ) {
        if ( isset( $el['id'] ) && $el['id'] === $id ) {
            $el['settings'] = array_merge( isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array(), $settings );
            return true;
        }
        if ( ! empty( $el['elements'] ) ) {
            if ( att_mcp_elementor_update_by_id( $el['elements'], $id, $settings ) ) {
                return true;
            }
        }
    }
    return false;
}

// Insert a new element into a specific parent by ID.
function att_mcp_elementor_insert_into( &$elements, $parent_id, $new_element, $position ) {
    foreach ( $elements as &$el ) {
        if ( isset( $el['id'] ) && $el['id'] === $parent_id ) {
            if ( ! isset( $el['elements'] ) || ! is_array( $el['elements'] ) ) {
                $el['elements'] = array();
            }
            if ( null !== $position ) {
                array_splice( $el['elements'], $position, 0, array( $new_element ) );
            } else {
                $el['elements'][] = $new_element;
            }
            return true;
        }
        if ( ! empty( $el['elements'] ) ) {
            if ( att_mcp_elementor_insert_into( $el['elements'], $parent_id, $new_element, $position ) ) {
                return true;
            }
        }
    }
    return false;
}

// Find the ID of the first insertable element (container or column).
function att_mcp_elementor_first_insertable( $elements ) {
    foreach ( (array) $elements as $el ) {
        $type = isset( $el['elType'] ) ? $el['elType'] : '';
        if ( in_array( $type, array( 'container', 'column' ), true ) && isset( $el['id'] ) ) {
            return $el['id'];
        }
        if ( ! empty( $el['elements'] ) ) {
            $found = att_mcp_elementor_first_insertable( $el['elements'] );
            if ( $found ) {
                return $found;
            }
        }
    }
    return null;
}

// Build a simplified tree for get-page response.
function att_mcp_elementor_simplify_tree( $elements ) {
    $result = array();
    foreach ( (array) $elements as $el ) {
        $item = array(
            'id'   => isset( $el['id'] ) ? $el['id'] : null,
            'type' => isset( $el['elType'] ) ? $el['elType'] : 'unknown',
        );
        if ( ! empty( $el['widgetType'] ) ) {
            $item['widget_type'] = $el['widgetType'];
        }
        // Surface the first recognisable text setting as a preview.
        $s = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
        foreach ( array( 'title', 'text', 'editor', 'html', 'url' ) as $k ) {
            if ( ! empty( $s[ $k ] ) && is_string( $s[ $k ] ) ) {
                $item['preview'] = wp_trim_words( wp_strip_all_tags( $s[ $k ] ), 8 );
                break;
            }
        }
        if ( ! empty( $el['elements'] ) ) {
            $item['children'] = att_mcp_elementor_simplify_tree( $el['elements'] );
        }
        $result[] = $item;
    }
    return $result;
}

// Collect matching elements into $results.
function att_mcp_elementor_search_tree( $elements, $widget_type, $search, &$results ) {
    foreach ( (array) $elements as $el ) {
        $type_match   = ! $widget_type || ( isset( $el['widgetType'] ) && $el['widgetType'] === $widget_type );
        $search_match = ! $search || false !== stripos( (string) wp_json_encode( isset( $el['settings'] ) ? $el['settings'] : array() ), $search );

        if ( $type_match && $search_match && ( $widget_type || $search ) ) {
            $results[] = array(
                'id'          => isset( $el['id'] ) ? $el['id'] : null,
                'type'        => isset( $el['elType'] ) ? $el['elType'] : 'unknown',
                'widget_type' => isset( $el['widgetType'] ) ? $el['widgetType'] : null,
            );
        }
        if ( ! empty( $el['elements'] ) ) {
            att_mcp_elementor_search_tree( $el['elements'], $widget_type, $search, $results );
        }
    }
}

/**
 * Validate and normalise a full element tree for att/elementor-save-page:
 * known element types only, widgets need a widgetType, missing IDs generated,
 * settings filtered for users without unfiltered_html.
 */
function att_mcp_elementor_normalize_tree( $elements, $depth = 0 ) {
    if ( $depth > 20 ) {
        return new WP_Error( 'att_mcp_tree_too_deep', 'The element tree is nested too deeply.' );
    }
    $out = array();
    foreach ( (array) $elements as $el ) {
        if ( ! is_array( $el ) || empty( $el['elType'] ) || ! in_array( $el['elType'], array( 'container', 'section', 'column', 'widget' ), true ) ) {
            return new WP_Error( 'att_mcp_bad_element', 'Every element needs elType: container, section, column, or widget.' );
        }
        $node = array(
            'id'       => ( isset( $el['id'] ) && preg_match( '/^[a-z0-9]{6,12}$/i', (string) $el['id'] ) ) ? (string) $el['id'] : att_mcp_elementor_generate_id(),
            'elType'   => $el['elType'],
            'settings' => ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) ? att_mcp_filter_untrusted( $el['settings'] ) : array(),
            'elements' => array(),
        );
        if ( 'widget' === $el['elType'] ) {
            if ( empty( $el['widgetType'] ) || ! is_string( $el['widgetType'] ) ) {
                return new WP_Error( 'att_mcp_bad_widget', 'Widgets need a widgetType, e.g. heading, text-editor, image, button.' );
            }
            $node['widgetType'] = sanitize_key( $el['widgetType'] );
        }
        if ( isset( $el['isInner'] ) ) {
            $node['isInner'] = (bool) $el['isInner'];
        }
        if ( ! empty( $el['elements'] ) ) {
            $children = att_mcp_elementor_normalize_tree( $el['elements'], $depth + 1 );
            if ( is_wp_error( $children ) ) {
                return $children;
            }
            $node['elements'] = $children;
        }
        $out[] = $node;
    }
    return $out;
}

/** Active kit (Site Settings) post ID. */
function att_mcp_elementor_kit_id() {
    $kit = (int) get_option( 'elementor_active_kit' );
    return ( $kit && get_post( $kit ) ) ? $kit : new WP_Error( 'att_mcp_no_kit', 'No active Elementor kit (Site Settings) was found.' );
}

// ─────────────────────────────────────────────
// REGISTRATION
// ─────────────────────────────────────────────

function att_mcp_register_elementor_abilities() {
    if ( ! att_mcp_elementor_is_active() ) {
        return;
    }

    $base     = att_mcp_ability_base();
    $can_edit = function () { return current_user_can( 'edit_posts' ); };

    if ( att_mcp_is_enabled( 'att/elementor-list-pages' ) ) {
        att_mcp_register( 'att/elementor-list-pages', array_merge( $base, array(
            'label'               => 'List Elementor Pages',
            'description'         => 'Lists pages/posts built with Elementor.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'post_type' => array( 'type' => 'string',  'description' => 'Post type to query. Default page.' ),
                'status'    => array( 'type' => 'string',  'description' => 'publish | draft | pending | private | any. Default publish.' ),
                'per_page'  => array( 'type' => 'integer', 'description' => '1–100. Default 20.' ),
            ) ),
            'permission_callback' => $can_edit,
            'execute_callback'    => 'att_mcp_execute_elementor_list_pages',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/elementor-get-page' ) ) {
        att_mcp_register( 'att/elementor-get-page', array_merge( $base, array(
            'label'               => 'Get Page Structure',
            'description'         => 'Returns the element tree of an Elementor-built page (IDs, types, widget types, text previews). Pass full:true to get the complete raw tree with all settings.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array(
                'post_id' => array( 'type' => 'integer', 'description' => 'Post ID.' ),
                'full'    => array( 'type' => 'boolean', 'description' => 'Return the raw element tree including settings. Default false.' ),
            ) ),
            'permission_callback' => $can_edit,
            'execute_callback'    => 'att_mcp_execute_elementor_get_page',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/elementor-get-element' ) ) {
        att_mcp_register( 'att/elementor-get-element', array_merge( $base, array(
            'label'               => 'Get Element Settings',
            'description'         => 'Returns all settings for a specific element.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'post_id', 'element_id' ), 'properties' => array(
                'post_id'    => array( 'type' => 'integer', 'description' => 'Post ID.' ),
                'element_id' => array( 'type' => 'string',  'description' => 'Element ID.' ),
            ) ),
            'permission_callback' => $can_edit,
            'execute_callback'    => 'att_mcp_execute_elementor_get_element',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/elementor-find-element' ) ) {
        att_mcp_register( 'att/elementor-find-element', array_merge( $base, array(
            'label'               => 'Find Element',
            'description'         => 'Finds elements by widget type or settings content search.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array(
                'post_id'     => array( 'type' => 'integer', 'description' => 'Post ID.' ),
                'widget_type' => array( 'type' => 'string',  'description' => 'Filter by widget type e.g. heading.' ),
                'search'      => array( 'type' => 'string',  'description' => 'Search string to match in element settings.' ),
            ) ),
            'permission_callback' => $can_edit,
            'execute_callback'    => 'att_mcp_execute_elementor_find_element',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/elementor-list-templates' ) ) {
        att_mcp_register( 'att/elementor-list-templates', array_merge( $base, array(
            'label'               => 'List Templates',
            'description'         => 'Lists Elementor saved templates (read one\'s tree with elementor-get-page using its ID).',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'type'     => array( 'type' => 'string',  'description' => 'Template type slug e.g. page, section, header, footer.' ),
                'per_page' => array( 'type' => 'integer', 'description' => '1–100. Default 20.' ),
            ) ),
            'permission_callback' => $can_edit,
            'execute_callback'    => 'att_mcp_execute_elementor_list_templates',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/elementor-get-kit' ) ) {
        att_mcp_register( 'att/elementor-get-kit', array_merge( $base, array(
            'label'               => 'Get Global Kit',
            'description'         => 'Returns the Elementor Site Settings kit: global colors (system_colors/custom_colors), global fonts (system_typography/custom_typography), container width, and other site-wide settings. Pass keys to narrow the result.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'keys' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Optional list of setting keys to return.' ),
            ) ),
            'permission_callback' => $can_edit,
            'execute_callback'    => 'att_mcp_execute_elementor_get_kit',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/elementor-update-element' ) ) {
        att_mcp_register( 'att/elementor-update-element', array_merge( $base, array(
            'label'               => 'Update Element',
            'description'         => 'Merges new settings into a widget or container. Undoable with att/undo-change.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'post_id', 'element_id', 'settings' ), 'properties' => array(
                'post_id'    => array( 'type' => 'integer', 'description' => 'Post ID.' ),
                'element_id' => array( 'type' => 'string',  'description' => 'Element ID.' ),
                'settings'   => array( 'type' => 'object',  'description' => 'Settings key/value pairs to merge.' ),
            ) ),
            'permission_callback' => $can_edit,
            'execute_callback'    => 'att_mcp_execute_elementor_update_element',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/elementor-add-widget' ) ) {
        att_mcp_register( 'att/elementor-add-widget', array_merge( $base, array(
            'label'               => 'Add Widget',
            'description'         => 'Adds a widget to a container or column on an Elementor page. Undoable with att/undo-change.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'post_id', 'widget_type' ), 'properties' => array(
                'post_id'      => array( 'type' => 'integer', 'description' => 'Post ID.' ),
                'widget_type'  => array( 'type' => 'string',  'description' => 'Elementor widget type e.g. heading, text-editor, image, button.' ),
                'container_id' => array( 'type' => 'string',  'description' => 'Parent container or column ID. Uses first available if omitted.' ),
                'settings'     => array( 'type' => 'object',  'description' => 'Widget settings.' ),
                'position'     => array( 'type' => 'integer', 'description' => 'Zero-based insert position inside the parent.' ),
            ) ),
            'permission_callback' => $can_edit,
            'execute_callback'    => 'att_mcp_execute_elementor_add_widget',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/elementor-add-container' ) ) {
        att_mcp_register( 'att/elementor-add-container', array_merge( $base, array(
            'label'               => 'Add Container',
            'description'         => 'Adds a layout container or section to an Elementor page. Undoable with att/undo-change.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array(
                'post_id'   => array( 'type' => 'integer', 'description' => 'Post ID.' ),
                'type'      => array( 'type' => 'string',  'description' => 'container (modern, default) or section (legacy).' ),
                'parent_id' => array( 'type' => 'string',  'description' => 'Parent element ID to nest inside. Omit to add at root level.' ),
                'settings'  => array( 'type' => 'object',  'description' => 'Container/section settings.' ),
                'position'  => array( 'type' => 'integer', 'description' => 'Zero-based insert position.' ),
            ) ),
            'permission_callback' => $can_edit,
            'execute_callback'    => 'att_mcp_execute_elementor_add_container',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/elementor-remove-element' ) ) {
        att_mcp_register( 'att/elementor-remove-element', array_merge( $base, array(
            'label'               => 'Remove Element',
            'description'         => 'Removes a widget or container from an Elementor page. Undoable with att/undo-change.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'post_id', 'element_id' ), 'properties' => array(
                'post_id'    => array( 'type' => 'integer', 'description' => 'Post ID.' ),
                'element_id' => array( 'type' => 'string',  'description' => 'Element ID to remove.' ),
            ) ),
            'permission_callback' => $can_edit,
            'execute_callback'    => 'att_mcp_execute_elementor_remove_element',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/elementor-save-page' ) ) {
        att_mcp_register( 'att/elementor-save-page', array_merge( $base, array(
            'label'               => 'Save Page Layout',
            'description'         => 'Writes a complete Elementor element tree to a page, replacing its layout — the fastest way to build a page. Turns Elementor on for pages that do not use it yet. Each element: {"elType":"container|section|column|widget","settings":{…},"elements":[…]}; widgets add "widgetType" (heading, text-editor, image, button, icon-list, …). IDs are generated when omitted. Undoable with att/undo-change.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'post_id', 'elements' ), 'properties' => array(
                'post_id'       => array( 'type' => 'integer', 'description' => 'Page/post ID (create the page first with att/create-page).' ),
                'elements'      => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Root elements (containers, or sections with columns).' ),
                'page_settings' => array( 'type' => 'object',  'description' => 'Optional Elementor page settings to merge (e.g. {"hide_title":"yes"}).' ),
                'template'      => array( 'type' => 'string',  'description' => 'Optional page template: elementor_canvas (no header/footer), elementor_header_footer (full width), or default.' ),
            ) ),
            'permission_callback' => $can_edit,
            'execute_callback'    => 'att_mcp_execute_elementor_save_page',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/elementor-update-kit' ) ) {
        att_mcp_register( 'att/elementor-update-kit', array_merge( $base, array(
            'label'               => 'Update Global Kit',
            'description'         => 'Changes Elementor Site Settings: pass a "changes" object of kit keys (e.g. system_colors, custom_colors, system_typography, custom_typography, container_width). Top-level keys are replaced whole — read the kit first with elementor-get-kit and send complete arrays. Undoable with att/undo-change.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'changes' ), 'properties' => array(
                'changes' => array( 'type' => 'object', 'description' => 'Kit settings to set.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
            'execute_callback'    => 'att_mcp_execute_elementor_update_kit',
        ) ) );
    }
}

// ─────────────────────────────────────────────
// EXECUTE CALLBACKS
// ─────────────────────────────────────────────

function att_mcp_execute_elementor_list_pages( $input ) {
    $post_type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'page';
    if ( ! post_type_exists( $post_type ) ) {
        return new WP_Error( 'att_mcp_bad_post_type', 'Unknown post type.' );
    }
    $status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'publish';
    if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ), true ) ) {
        return new WP_Error( 'att_mcp_bad_status', 'Unsupported status.' );
    }

    $q = new WP_Query( array(
        'post_type'      => $post_type,
        'post_status'    => $status,
        'posts_per_page' => att_mcp_int_arg( $input, 'per_page', 20, 1, 100 ),
        'meta_key'       => '_elementor_edit_mode', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the only way to find Elementor pages; bounded by per_page.
        'meta_value'     => 'builder',              // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
    ) );

    $pages = array();
    foreach ( $q->posts as $post ) {
        if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post->ID ) ) {
            continue;
        }
        $pages[] = array(
            'id'     => $post->ID,
            'title'  => $post->post_title,
            'url'    => get_permalink( $post->ID ),
            'status' => $post->post_status,
            'type'   => $post->post_type,
        );
    }
    return array( 'pages' => $pages, 'total' => (int) $q->found_posts );
}

function att_mcp_execute_elementor_get_page( $input ) {
    $post_id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
    $data    = att_mcp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) {
        return $data;
    }
    return array(
        'post_id'   => $post_id,
        'structure' => ! empty( $input['full'] ) ? $data : att_mcp_elementor_simplify_tree( $data ),
    );
}

function att_mcp_execute_elementor_get_element( $input ) {
    $post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
    $element_id = isset( $input['element_id'] ) ? sanitize_text_field( (string) $input['element_id'] ) : '';

    $data = att_mcp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) {
        return $data;
    }

    $element = att_mcp_elementor_find_by_id( $data, $element_id );
    if ( ! $element ) {
        return new WP_Error( 'att_mcp_not_found', 'Element not found.' );
    }

    return array(
        'id'          => $element['id'],
        'type'        => isset( $element['elType'] ) ? $element['elType'] : 'unknown',
        'widget_type' => isset( $element['widgetType'] ) ? $element['widgetType'] : null,
        'settings'    => isset( $element['settings'] ) ? $element['settings'] : array(),
    );
}

function att_mcp_execute_elementor_find_element( $input ) {
    $post_id     = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
    $widget_type = isset( $input['widget_type'] ) ? sanitize_text_field( (string) $input['widget_type'] ) : '';
    $search      = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';

    $data = att_mcp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) {
        return $data;
    }

    $results = array();
    att_mcp_elementor_search_tree( $data, $widget_type, $search, $results );
    return array( 'results' => $results, 'total' => count( $results ) );
}

function att_mcp_execute_elementor_list_templates( $input ) {
    $args = array(
        'post_type'      => 'elementor_library',
        'post_status'    => 'publish',
        'posts_per_page' => att_mcp_int_arg( $input, 'per_page', 20, 1, 100 ),
    );
    if ( ! empty( $input['type'] ) ) {
        $args['tax_query'] = array( array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Elementor stores template types as a taxonomy; bounded by per_page.
            'taxonomy' => 'elementor_library_type',
            'field'    => 'slug',
            'terms'    => sanitize_key( $input['type'] ),
        ) );
    }
    $q         = new WP_Query( $args );
    $templates = array();
    foreach ( $q->posts as $post ) {
        $templates[] = array(
            'id'    => $post->ID,
            'title' => $post->post_title,
            'type'  => get_post_meta( $post->ID, '_elementor_template_type', true ),
            'date'  => get_the_date( 'Y-m-d', $post->ID ),
        );
    }
    return array( 'templates' => $templates, 'total' => (int) $q->found_posts );
}

function att_mcp_execute_elementor_get_kit( $input ) {
    $kit = att_mcp_elementor_kit_id();
    if ( is_wp_error( $kit ) ) {
        return $kit;
    }
    $settings = get_post_meta( $kit, '_elementor_page_settings', true );
    $settings = is_array( $settings ) ? $settings : array();
    if ( ! empty( $input['keys'] ) && is_array( $input['keys'] ) ) {
        $settings = array_intersect_key( $settings, array_flip( array_map( 'strval', $input['keys'] ) ) );
    }
    return array( 'kit_id' => $kit, 'settings' => $settings );
}

function att_mcp_execute_elementor_update_element( $input ) {
    $post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
    $element_id = isset( $input['element_id'] ) ? sanitize_text_field( (string) $input['element_id'] ) : '';
    $settings   = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? att_mcp_filter_untrusted( $input['settings'] ) : array();

    $data = att_mcp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) {
        return $data;
    }
    if ( ! att_mcp_elementor_update_by_id( $data, $element_id, $settings ) ) {
        return new WP_Error( 'att_mcp_not_found', 'Element not found.' );
    }

    att_mcp_elementor_save_data( $post_id, $data, 'Update element ' . $element_id );
    return array( 'success' => true, 'post_id' => $post_id, 'element_id' => $element_id );
}

function att_mcp_execute_elementor_add_widget( $input ) {
    $post_id      = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
    $widget_type  = isset( $input['widget_type'] ) ? sanitize_key( $input['widget_type'] ) : '';
    $container_id = isset( $input['container_id'] ) ? sanitize_text_field( (string) $input['container_id'] ) : null;
    $settings     = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? att_mcp_filter_untrusted( $input['settings'] ) : array();
    $position     = isset( $input['position'] ) ? (int) $input['position'] : null;

    if ( '' === $widget_type ) {
        return new WP_Error( 'att_mcp_no_widget', 'A widget_type is required.' );
    }
    $data = att_mcp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) {
        return $data;
    }

    $new_widget = array(
        'id'         => att_mcp_elementor_generate_id(),
        'elType'     => 'widget',
        'widgetType' => $widget_type,
        'settings'   => $settings,
        'elements'   => array(),
    );

    if ( $container_id ) {
        $target = att_mcp_elementor_find_by_id( $data, $container_id );
        if ( ! $target ) {
            return new WP_Error( 'att_mcp_not_found', 'Container not found.' );
        }
        // Widgets cannot go directly inside a legacy section — they need a column.
        if ( isset( $target['elType'] ) && 'section' === $target['elType'] ) {
            return new WP_Error( 'att_mcp_bad_parent', 'Target is a section (legacy layout). Please target a column inside the section instead.' );
        }
        if ( ! att_mcp_elementor_insert_into( $data, $container_id, $new_widget, $position ) ) {
            return new WP_Error( 'att_mcp_insert_failed', 'Failed to insert widget.' );
        }
    } else {
        $auto_parent = att_mcp_elementor_first_insertable( $data );
        if ( ! $auto_parent ) {
            return new WP_Error( 'att_mcp_no_container', 'No container or column found. Create one first or provide container_id.' );
        }
        if ( ! att_mcp_elementor_insert_into( $data, $auto_parent, $new_widget, null ) ) {
            return new WP_Error( 'att_mcp_insert_failed', 'Failed to insert widget.' );
        }
    }

    att_mcp_elementor_save_data( $post_id, $data, 'Add ' . $widget_type . ' widget' );
    return array( 'success' => true, 'element_id' => $new_widget['id'], 'widget_type' => $widget_type );
}

function att_mcp_execute_elementor_add_container( $input ) {
    $post_id   = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
    $type      = isset( $input['type'] ) ? sanitize_key( $input['type'] ) : 'container';
    $parent_id = isset( $input['parent_id'] ) ? sanitize_text_field( (string) $input['parent_id'] ) : null;
    $settings  = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? att_mcp_filter_untrusted( $input['settings'] ) : array();
    $position  = isset( $input['position'] ) ? (int) $input['position'] : null;

    // Normalise type — only container (modern) and section (legacy) are valid root types.
    if ( ! in_array( $type, array( 'container', 'section' ), true ) ) {
        $type = 'container';
    }

    $data = att_mcp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) {
        return $data;
    }

    $new_element = array(
        'id'       => att_mcp_elementor_generate_id(),
        'elType'   => $type,
        'settings' => $settings,
        'elements' => array(),
        'isInner'  => (bool) $parent_id,
    );

    if ( $parent_id ) {
        if ( ! att_mcp_elementor_insert_into( $data, $parent_id, $new_element, $position ) ) {
            return new WP_Error( 'att_mcp_not_found', 'Parent element not found.' );
        }
    } elseif ( null !== $position ) {
        array_splice( $data, $position, 0, array( $new_element ) );
    } else {
        $data[] = $new_element;
    }

    att_mcp_elementor_save_data( $post_id, $data, 'Add ' . $type );
    return array( 'success' => true, 'element_id' => $new_element['id'], 'type' => $type );
}

function att_mcp_execute_elementor_remove_element( $input ) {
    $post_id    = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
    $element_id = isset( $input['element_id'] ) ? sanitize_text_field( (string) $input['element_id'] ) : '';

    $data = att_mcp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) {
        return $data;
    }
    if ( ! att_mcp_elementor_remove_by_id( $data, $element_id ) ) {
        return new WP_Error( 'att_mcp_not_found', 'Element not found.' );
    }

    att_mcp_elementor_save_data( $post_id, $data, 'Remove element ' . $element_id );
    return array( 'success' => true, 'message' => "Element {$element_id} removed." );
}

function att_mcp_execute_elementor_save_page( $input ) {
    $post = att_mcp_elementor_post( isset( $input['post_id'] ) ? (int) $input['post_id'] : 0 );
    if ( is_wp_error( $post ) ) {
        return $post;
    }
    if ( empty( $input['elements'] ) || ! is_array( $input['elements'] ) ) {
        return new WP_Error( 'att_mcp_no_elements', 'Pass "elements": the root elements of the layout.' );
    }
    $tree = att_mcp_elementor_normalize_tree( $input['elements'] );
    if ( is_wp_error( $tree ) ) {
        return $tree;
    }

    $template = null;
    if ( isset( $input['template'] ) ) {
        $template = sanitize_text_field( (string) $input['template'] );
        $allowed  = array_merge( array( 'default', 'elementor_canvas', 'elementor_header_footer', 'elementor_theme' ), array_keys( wp_get_theme()->get_page_templates( $post, $post->post_type ) ) );
        if ( ! in_array( $template, $allowed, true ) ) {
            return new WP_Error( 'att_mcp_bad_template', 'Unknown template. Use default, elementor_canvas, elementor_header_footer, or a theme template.' );
        }
    }

    // One undo point for everything this call touches.
    $keys = array( '_elementor_data', '_elementor_edit_mode', '_elementor_template_type', '_elementor_version', '_elementor_page_settings' );
    if ( null !== $template ) {
        $keys[] = '_wp_page_template';
    }
    $capture = array();
    foreach ( $keys as $key ) {
        $capture[] = att_mcp_capture( 'post_meta', array( $post->ID, $key ) );
    }
    $change_id = att_mcp_record_change( $capture, sprintf( 'Elementor layout for #%d', $post->ID ) );

    update_post_meta( $post->ID, '_elementor_edit_mode', 'builder' );
    if ( ! get_post_meta( $post->ID, '_elementor_template_type', true ) ) {
        update_post_meta( $post->ID, '_elementor_template_type', 'page' === $post->post_type ? 'wp-page' : 'wp-post' );
    }
    if ( defined( 'ELEMENTOR_VERSION' ) ) {
        update_post_meta( $post->ID, '_elementor_version', ELEMENTOR_VERSION );
    }
    update_post_meta( $post->ID, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );
    if ( isset( $input['page_settings'] ) && is_array( $input['page_settings'] ) ) {
        $current = get_post_meta( $post->ID, '_elementor_page_settings', true );
        $current = is_array( $current ) ? $current : array();
        update_post_meta( $post->ID, '_elementor_page_settings', wp_slash( array_merge( $current, att_mcp_filter_untrusted( $input['page_settings'] ) ) ) );
    }
    if ( null !== $template ) {
        update_post_meta( $post->ID, '_wp_page_template', $template );
    }
    delete_post_meta( $post->ID, '_elementor_css' );
    att_mcp_elementor_clear_cache();

    return array(
        'success'   => true,
        'post_id'   => (int) $post->ID,
        'elements'  => att_mcp_elementor_simplify_tree( $tree ),
        'change_id' => $change_id,
        'url'       => get_permalink( $post ),
        'note'      => 'Check the result with att/render-page and run att/purge-cache if a page cache is active.',
    );
}

function att_mcp_execute_elementor_update_kit( $input ) {
    $kit = att_mcp_elementor_kit_id();
    if ( is_wp_error( $kit ) ) {
        return $kit;
    }
    if ( ! current_user_can( 'edit_post', $kit ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to edit the Elementor kit.' );
    }
    $changes = ( isset( $input['changes'] ) && is_array( $input['changes'] ) ) ? $input['changes'] : array();
    if ( ! $changes ) {
        return new WP_Error( 'att_mcp_no_changes', 'Provide a non-empty "changes" object.' );
    }

    $current = get_post_meta( $kit, '_elementor_page_settings', true );
    $current = is_array( $current ) ? $current : array();
    $change  = att_mcp_snapshot( 'post_meta', array( $kit, '_elementor_page_settings' ), 'Elementor kit: ' . implode( ', ', array_keys( $changes ) ) );

    update_post_meta( $kit, '_elementor_page_settings', wp_slash( array_merge( $current, att_mcp_filter_untrusted( $changes ) ) ) );
    att_mcp_elementor_clear_cache();

    return array(
        'updated'      => true,
        'kit_id'       => $kit,
        'changed_keys' => array_keys( $changes ),
        'change_id'    => $change,
        'note'         => 'Run att/purge-cache if a page cache is active.',
    );
}
