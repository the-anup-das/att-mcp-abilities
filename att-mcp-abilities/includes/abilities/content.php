<?php
/**
 * Content abilities — list, read, create, update, and delete ANY content type
 * (pages, posts, reusable patterns `wp_block`, block navigation `wp_navigation`,
 * custom post types, GeneratePress Elements, Elementor templates, …).
 *
 * This is also the hardened core the Posts and Pages abilities call into:
 *   - per-object capability checks (edit_post / delete_post, not just edit_posts),
 *   - publish rights checked against the post type's own publish capability,
 *   - content kept byte-exact for users with unfiltered_html (block markup with
 *     backslashes/JSON is not corrupted), KSES-filtered otherwise,
 *   - page templates validated BEFORE saving (no half-applied updates),
 *   - meta writes through a blocklist (att_mcp_can_write_meta) with undo points.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Input schema properties shared by every create/update ability. */
function att_mcp_content_schema_props() {
    return array(
        'title'          => array( 'type' => 'string', 'description' => 'Title.' ),
        'content'        => array( 'type' => 'string', 'description' => 'Full content — block markup (<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->) or HTML. Replaces the existing content.' ),
        'excerpt'        => array( 'type' => 'string', 'description' => 'Excerpt.' ),
        'status'         => array( 'type' => 'string', 'description' => 'publish | draft | pending | private | future (future needs "date"). New items default to draft.' ),
        'slug'           => array( 'type' => 'string', 'description' => 'URL slug.' ),
        'parent'         => array( 'type' => 'integer', 'description' => 'Parent item ID for hierarchical types such as pages (0 = top level).' ),
        'menu_order'     => array( 'type' => 'integer', 'description' => 'Sort order.' ),
        'template'       => array( 'type' => 'string', 'description' => 'Page template (file name or block template slug — see att/get-content available_templates). "default" resets to the theme default.' ),
        'date'           => array( 'type' => 'string', 'description' => 'Publish date in site time, e.g. "2026-10-01 09:00:00".' ),
        'comment_status' => array( 'type' => 'string', 'description' => 'open | closed.' ),
        'featured_media' => array( 'type' => 'integer', 'description' => 'Featured image attachment ID (0 removes it).' ),
        'terms'          => array( 'type' => 'object', 'description' => 'Terms per taxonomy, replacing existing ones, e.g. {"category":[3,"News"],"post_tag":["launch"]}. Use IDs or existing names/slugs; unknown names are created when you may manage that taxonomy.' ),
        'meta'           => array( 'type' => 'object', 'description' => 'Post meta to set, e.g. {"_generate-disable-headline":"true"}. A null value deletes the key. Core file/lock keys and secret-like keys are refused. Undoable with att/undo-change.' ),
    );
}

function att_mcp_register_content_abilities() {
    $base = att_mcp_ability_base();

    if ( att_mcp_is_enabled( 'att/list-content' ) ) {
        att_mcp_register( 'att/list-content', array_merge( $base, array(
            'label'               => 'List Content',
            'description'         => 'Lists content of any type and status (drafts included) that you can edit. Use post_type "page", "post", "wp_block" (patterns), "wp_navigation", a custom post type, or "any".',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'post_type' => array( 'type' => 'string', 'description' => 'Post type slug, or "any". Default page.' ),
                'status'    => array( 'type' => 'string', 'description' => 'publish | draft | pending | private | future | trash | any. Default any.' ),
                'search'    => array( 'type' => 'string', 'description' => 'Keyword filter.' ),
                'parent'    => array( 'type' => 'integer', 'description' => 'Only children of this item ID.' ),
                'orderby'   => array( 'type' => 'string', 'description' => 'date | modified | title | menu_order. Default modified.' ),
                'per_page'  => array( 'type' => 'integer', 'description' => '1–100. Default 50.' ),
                'page'      => array( 'type' => 'integer', 'description' => 'Page number. Default 1.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_list_content',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/get-content' ) ) {
        att_mcp_register( 'att/get-content', array_merge( $base, array(
            'label'               => 'Get Content',
            'description'         => 'Returns one item by ID with its full raw content (block markup/HTML), status, template and available templates, terms, featured image, and post meta (large values are summarised — request them with meta_keys). Read an item before rewriting it.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
                'id'           => array( 'type' => 'integer', 'description' => 'Item ID.' ),
                'include_meta' => array( 'type' => 'boolean', 'description' => 'Include post meta. Default true.' ),
                'meta_keys'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Return only these meta keys, in full.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_get_content',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/create-content' ) ) {
        att_mcp_register( 'att/create-content', array_merge( $base, array(
            'label'               => 'Create Content',
            'description'         => 'Creates an item of any content type (post_type required), e.g. a reusable block pattern ("wp_block"), a custom post type entry, or a page. Defaults to draft.',
            'input_schema'        => array(
                'type'       => 'object',
                'required'   => array( 'post_type' ),
                'properties' => array_merge( array( 'post_type' => array( 'type' => 'string', 'description' => 'Post type slug.' ) ), att_mcp_content_schema_props() ),
            ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_create_content',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/update-content' ) ) {
        att_mcp_register( 'att/update-content', array_merge( $base, array(
            'label'               => 'Update Content',
            'description'         => 'Updates any content item by ID. Only the fields you pass change. "content" replaces the whole body — read it with att/get-content first and send back the complete edited markup. Earlier versions stay available via att/list-revisions.',
            'input_schema'        => array(
                'type'       => 'object',
                'required'   => array( 'id' ),
                'properties' => array_merge( array( 'id' => array( 'type' => 'integer', 'description' => 'Item ID.' ) ), att_mcp_content_schema_props() ),
            ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_update_content',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/delete-content' ) ) {
        att_mcp_register( 'att/delete-content', array_merge( $base, array(
            'label'               => 'Delete Content',
            'description'         => 'Moves any content item to the trash by ID (restorable from WP Admin). Pass force:true to delete permanently.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
                'id'    => array( 'type' => 'integer', 'description' => 'Item ID.' ),
                'force' => array( 'type' => 'boolean', 'description' => 'Permanently delete instead of trashing. Default false.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_delete_content',
        ) ) );
    }
}

/* ------------------------------------------------------------------------- */
/* Execute callbacks                                                          */
/* ------------------------------------------------------------------------- */

function att_mcp_execute_list_content( $input ) {
    $type = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'page';
    if ( 'any' !== $type ) {
        $pto = att_mcp_get_editable_post_type( $type );
        if ( is_wp_error( $pto ) ) {
            return $pto;
        }
    }

    $status  = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'any';
    $allowed = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'any' );
    if ( ! in_array( $status, $allowed, true ) ) {
        return new WP_Error( 'att_mcp_bad_status', 'status must be one of: ' . implode( ', ', $allowed ) . '.' );
    }
    if ( 'attachment' === $type ) {
        $status = 'inherit';
    }

    $orderby = isset( $input['orderby'] ) && in_array( $input['orderby'], array( 'date', 'modified', 'title', 'menu_order' ), true ) ? $input['orderby'] : 'modified';
    $args    = array(
        'post_type'      => $type,
        'post_status'    => $status,
        'posts_per_page' => att_mcp_int_arg( $input, 'per_page', 50, 1, 100 ),
        'paged'          => att_mcp_int_arg( $input, 'page', 1, 1, 10000 ),
        'orderby'        => $orderby,
        'order'          => in_array( $orderby, array( 'title', 'menu_order' ), true ) ? 'ASC' : 'DESC',
    );
    if ( ! empty( $input['search'] ) ) {
        $args['s'] = sanitize_text_field( $input['search'] );
    }
    if ( isset( $input['parent'] ) ) {
        $args['post_parent'] = (int) $input['parent'];
    }

    $q       = new WP_Query( $args );
    $blocked = att_mcp_blocked_post_types();
    $items   = array();
    foreach ( $q->posts as $p ) {
        if ( in_array( $p->post_type, $blocked, true ) || ! current_user_can( 'edit_post', $p->ID ) ) {
            continue;
        }
        $items[] = array(
            'id'         => $p->ID,
            'type'       => $p->post_type,
            'status'     => $p->post_status,
            'title'      => $p->post_title,
            'slug'       => $p->post_name,
            'url'        => get_permalink( $p ),
            'parent'     => (int) $p->post_parent,
            'menu_order' => (int) $p->menu_order,
            'template'   => get_page_template_slug( $p ) ? get_page_template_slug( $p ) : 'default',
            'modified'   => $p->post_modified,
        );
    }

    return array(
        'items'       => $items,
        'total'       => (int) $q->found_posts,
        'total_pages' => (int) $q->max_num_pages,
    );
}

function att_mcp_execute_get_content( $input ) {
    $post = get_post( isset( $input['id'] ) ? (int) $input['id'] : 0 );
    if ( ! $post || in_array( $post->post_type, att_mcp_blocked_post_types(), true ) ) {
        return new WP_Error( 'att_mcp_not_found', 'No content found with that ID.' );
    }
    if ( ! current_user_can( 'edit_post', $post->ID ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to edit this item.' );
    }
    $keys = ( isset( $input['meta_keys'] ) && is_array( $input['meta_keys'] ) ) ? array_map( 'strval', $input['meta_keys'] ) : array();
    return att_mcp_content_payload( $post, ! isset( $input['include_meta'] ) || ! empty( $input['include_meta'] ), $keys );
}

function att_mcp_execute_create_content( $input ) {
    unset( $input['id'] );
    return att_mcp_content_save( $input );
}

function att_mcp_execute_update_content( $input ) {
    if ( empty( $input['id'] ) ) {
        return new WP_Error( 'att_mcp_no_id', 'An "id" is required.' );
    }
    return att_mcp_content_save( $input );
}

function att_mcp_execute_delete_content( $input ) {
    return att_mcp_content_delete( $input );
}

/* ------------------------------------------------------------------------- */
/* Shared implementation (also used by posts.php, pages.php, GP Elements)    */
/* ------------------------------------------------------------------------- */

/** Full read payload for one item. */
function att_mcp_content_payload( $post, $include_meta = true, $meta_keys = array() ) {
    $terms = array();
    foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
        $assigned = wp_get_object_terms( $post->ID, $taxonomy );
        if ( is_wp_error( $assigned ) ) {
            continue;
        }
        foreach ( $assigned as $t ) {
            $terms[ $taxonomy ][] = array( 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
        }
    }

    $template = get_page_template_slug( $post );
    $data     = array(
        'id'                  => (int) $post->ID,
        'type'                => $post->post_type,
        'status'              => $post->post_status,
        'title'               => $post->post_title,
        'slug'                => $post->post_name,
        'url'                 => get_permalink( $post ),
        'edit_url'            => get_edit_post_link( $post->ID, 'raw' ),
        'parent'              => (int) $post->post_parent,
        'menu_order'          => (int) $post->menu_order,
        'author'              => (int) $post->post_author,
        'date'                => $post->post_date,
        'modified'            => $post->post_modified,
        'comment_status'      => $post->comment_status,
        'excerpt'             => $post->post_excerpt,
        'content'             => $post->post_content,
        'has_blocks'          => has_blocks( $post ),
        'is_elementor'        => ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ),
        'template'            => $template ? $template : 'default',
        'available_templates' => wp_get_theme()->get_page_templates( $post, $post->post_type ),
        'featured_media'      => (int) get_post_thumbnail_id( $post ),
        'featured_media_url'  => get_the_post_thumbnail_url( $post, 'full' ) ? get_the_post_thumbnail_url( $post, 'full' ) : null,
        'terms'               => $terms,
    );

    if ( $include_meta ) {
        $meta = array();
        foreach ( get_post_meta( $post->ID ) as $key => $values ) {
            if ( $meta_keys && ! in_array( $key, $meta_keys, true ) ) {
                continue;
            }
            if ( att_mcp_is_secret_name( $key ) || in_array( $key, array( '_edit_lock', '_edit_last' ), true ) ) {
                continue;
            }
            $value = ( 1 === count( $values ) ) ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
            $size  = strlen( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
            if ( ! $meta_keys && $size > 20000 ) {
                $meta[ $key ] = sprintf( '(omitted: %d bytes — pass meta_keys:["%s"] to read it)', $size, $key );
                continue;
            }
            $meta[ $key ] = $value;
        }
        $data['meta'] = $meta;
    }

    return $data;
}

/**
 * Create (no id) or update (id) an item. $forced_type restricts the tool to one
 * post type (e.g. 'post' for att/update-post).
 */
function att_mcp_content_save( $input, $forced_type = '' ) {
    $id     = isset( $input['id'] ) ? (int) $input['id'] : 0;
    $is_new = ( $id <= 0 );

    if ( $is_new ) {
        $type = $forced_type ? $forced_type : ( isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : '' );
        $pto  = att_mcp_get_editable_post_type( $type );
        if ( is_wp_error( $pto ) ) {
            return $pto;
        }
        if ( 'attachment' === $type ) {
            return new WP_Error( 'att_mcp_use_upload', 'Use att/upload-media to add media.' );
        }
        if ( ! current_user_can( $pto->cap->create_posts ) ) {
            return new WP_Error( 'att_mcp_forbidden', sprintf( 'You are not allowed to create %s.', $pto->labels->name ) );
        }
        $post           = null;
        $current_status = '';
        $postarr        = array( 'post_type' => $type, 'post_status' => 'draft' );
    } else {
        $post = get_post( $id );
        if ( ! $post || ( $forced_type && $forced_type !== $post->post_type ) ) {
            return new WP_Error( 'att_mcp_not_found', sprintf( 'No %s found with ID %d.', $forced_type ? $forced_type : 'item', $id ) );
        }
        $pto = att_mcp_get_editable_post_type( $post->post_type );
        if ( is_wp_error( $pto ) ) {
            return $pto;
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to edit this item.' );
        }
        $type           = $post->post_type;
        $current_status = $post->post_status;
        $postarr        = array( 'ID' => $id );
    }

    // ---- Validate everything BEFORE writing anything ----------------------
    if ( isset( $input['title'] ) ) {
        $postarr['post_title'] = sanitize_text_field( (string) $input['title'] );
    }
    if ( isset( $input['content'] ) ) {
        $postarr['post_content'] = att_mcp_prepare_content( $input['content'] );
    }
    if ( isset( $input['excerpt'] ) ) {
        $postarr['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
    }
    if ( isset( $input['slug'] ) ) {
        $postarr['post_name'] = sanitize_title( (string) $input['slug'] );
    }
    if ( isset( $input['menu_order'] ) ) {
        $postarr['menu_order'] = (int) $input['menu_order'];
    }
    if ( isset( $input['parent'] ) ) {
        $parent = (int) $input['parent'];
        if ( $parent && ( ! get_post( $parent ) || $parent === $id ) ) {
            return new WP_Error( 'att_mcp_bad_parent', 'The parent item does not exist.' );
        }
        $postarr['post_parent'] = $parent;
    }
    if ( isset( $input['comment_status'] ) ) {
        if ( ! in_array( $input['comment_status'], array( 'open', 'closed' ), true ) ) {
            return new WP_Error( 'att_mcp_bad_comment_status', 'comment_status must be open or closed.' );
        }
        $postarr['comment_status'] = $input['comment_status'];
    }
    if ( isset( $input['date'] ) && '' !== $input['date'] ) {
        $ts = strtotime( (string) $input['date'] );
        if ( false === $ts ) {
            return new WP_Error( 'att_mcp_bad_date', 'Could not parse "date". Use e.g. 2026-10-01 09:00:00.' );
        }
        $postarr['post_date']     = gmdate( 'Y-m-d H:i:s', $ts );
        $postarr['post_date_gmt'] = get_gmt_from_date( $postarr['post_date'] );
        $postarr['edit_date']     = true;
    }
    if ( isset( $input['status'] ) ) {
        $status = att_mcp_resolve_status( $input['status'], $pto, $current_status );
        if ( is_wp_error( $status ) ) {
            return $status;
        }
        $postarr['post_status'] = $status;
    }
    if ( isset( $input['template'] ) ) {
        $template = sanitize_text_field( (string) $input['template'] );
        $template = ( '' === $template ) ? 'default' : $template;
        if ( 'default' !== $template && ! array_key_exists( $template, wp_get_theme()->get_page_templates( $post, $type ) ) ) {
            return new WP_Error( 'att_mcp_bad_template', 'Unknown template. Available: ' . implode( ', ', array_keys( wp_get_theme()->get_page_templates( $post, $type ) ) ) . ' (or "default").' );
        }
        $postarr['page_template'] = $template;
    }

    // PHP gate for GeneratePress "Execute PHP" hook Elements (every write path goes through here).
    if ( 'gp_elements' === $type && function_exists( 'att_mcp_gp_php_gate' ) ) {
        $gate = att_mcp_gp_php_gate( $is_new ? 0 : $id, isset( $input['meta'] ) ? $input['meta'] : array(), isset( $input['content'] ) );
        if ( is_wp_error( $gate ) ) {
            return $gate;
        }
    }

    // Shortcut term params for posts.
    $terms = ( isset( $input['terms'] ) && is_array( $input['terms'] ) ) ? $input['terms'] : array();
    if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
        $terms['category'] = $input['categories'];
    }
    if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
        $terms['post_tag'] = $input['tags'];
    }

    // ---- Write ---------------------------------------------------------------
    // MCP input is not slashed; core's insert/update functions unslash, so slash here.
    $result = $is_new ? wp_insert_post( wp_slash( $postarr ), true ) : wp_update_post( wp_slash( $postarr ), true );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    $id       = (int) $result;
    $warnings = array();

    if ( $terms ) {
        list( , $term_warnings ) = att_mcp_apply_terms( $id, $type, $terms );
        $warnings = array_merge( $warnings, $term_warnings );
    }

    if ( isset( $input['featured_media'] ) ) {
        $media = (int) $input['featured_media'];
        if ( 0 === $media ) {
            delete_post_thumbnail( $id );
        } elseif ( ! wp_attachment_is_image( $media ) || ! set_post_thumbnail( $id, $media ) ) {
            $warnings[] = sprintf( 'featured_media %d is not an image attachment; featured image unchanged.', $media );
        }
    }

    $meta_result = null;
    if ( isset( $input['meta'] ) && is_array( $input['meta'] ) && $input['meta'] ) {
        list( $updated, $skipped ) = att_mcp_apply_meta( $id, $input['meta'] );
        $meta_result = array( 'updated' => $updated, 'skipped' => $skipped );
    }

    return array(
        'success'   => true,
        'created'   => $is_new,
        'id'        => $id,
        'post_type' => $type,
        'status'    => get_post_status( $id ),
        'url'       => get_permalink( $id ),
        'edit_url'  => get_edit_post_link( $id, 'raw' ),
        'meta'      => $meta_result,
        'warnings'  => $warnings,
        'note'      => 'Run att/purge-cache if a page cache is active. Check the result with att/render-page.',
    );
}

/** Trash (default) or permanently delete an item. */
function att_mcp_content_delete( $input, $forced_type = '' ) {
    $id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
    $post = get_post( $id );
    if ( ! $post || ( $forced_type && $forced_type !== $post->post_type ) ) {
        return new WP_Error( 'att_mcp_not_found', sprintf( 'No %s found with ID %d.', $forced_type ? $forced_type : 'item', $id ) );
    }
    $pto = att_mcp_get_editable_post_type( $post->post_type );
    if ( is_wp_error( $pto ) ) {
        return $pto;
    }
    if ( ! current_user_can( 'delete_post', $id ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to delete this item.' );
    }

    $force = ! empty( $input['force'] );
    if ( 'attachment' === $post->post_type ) {
        $done = wp_delete_attachment( $id, $force );
    } elseif ( $force ) {
        $done = wp_delete_post( $id, true );
    } else {
        $done = wp_trash_post( $id );
    }
    if ( ! $done ) {
        return new WP_Error( 'att_mcp_delete_failed', 'Could not delete the item.' );
    }

    $trashed = ( 'trash' === get_post_status( $id ) );
    return array(
        'success' => true,
        'id'      => $id,
        'trashed' => $trashed,
        'deleted' => ! $trashed,
        'message' => $trashed ? "Item {$id} moved to trash." : "Item {$id} permanently deleted.",
    );
}

/** Replace terms per taxonomy. Returns array( applied, warnings ). */
function att_mcp_apply_terms( $post_id, $post_type, $terms ) {
    $applied  = array();
    $warnings = array();
    foreach ( (array) $terms as $taxonomy => $values ) {
        $taxonomy = sanitize_key( $taxonomy );
        $tax      = get_taxonomy( $taxonomy );
        if ( ! $tax || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
            $warnings[] = sprintf( 'Taxonomy "%s" does not apply to %s.', $taxonomy, $post_type );
            continue;
        }
        if ( ! current_user_can( $tax->cap->assign_terms ) ) {
            $warnings[] = sprintf( 'You may not assign %s.', $taxonomy );
            continue;
        }
        $ids = array();
        foreach ( (array) $values as $value ) {
            if ( is_numeric( $value ) ) {
                $term = get_term( (int) $value, $taxonomy );
                if ( $term && ! is_wp_error( $term ) ) {
                    $ids[] = (int) $term->term_id;
                } else {
                    $warnings[] = sprintf( 'Term %d not found in %s.', (int) $value, $taxonomy );
                }
                continue;
            }
            $name = sanitize_text_field( (string) $value );
            if ( '' === $name ) {
                continue;
            }
            $term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
            if ( ! $term ) {
                $term = get_term_by( 'name', $name, $taxonomy );
            }
            if ( $term ) {
                $ids[] = (int) $term->term_id;
                continue;
            }
            if ( ! current_user_can( $tax->cap->edit_terms ) ) {
                $warnings[] = sprintf( 'Term "%s" does not exist in %s and you may not create terms.', $name, $taxonomy );
                continue;
            }
            $new = wp_insert_term( $name, $taxonomy );
            if ( is_wp_error( $new ) ) {
                $warnings[] = $new->get_error_message();
            } else {
                $ids[] = (int) $new['term_id'];
            }
        }
        $set = wp_set_object_terms( $post_id, $ids, $taxonomy );
        if ( is_wp_error( $set ) ) {
            $warnings[] = $set->get_error_message();
        } else {
            $applied[ $taxonomy ] = $ids;
        }
    }
    return array( $applied, $warnings );
}

/** Write meta with an undo point. Returns array( updated_keys, skipped[key => reason] ). */
function att_mcp_apply_meta( $post_id, $meta ) {
    $pending = array();
    $skipped = array();
    $capture = array();
    foreach ( (array) $meta as $key => $value ) {
        $key = (string) $key;
        if ( ! preg_match( '/^[A-Za-z0-9_\-:.]{1,191}$/', $key ) ) {
            $skipped[ $key ] = 'invalid key';
            continue;
        }
        if ( ! att_mcp_can_write_meta( $post_id, $key ) ) {
            $skipped[ $key ] = 'not allowed';
            continue;
        }
        $capture[]       = att_mcp_capture( 'post_meta', array( $post_id, $key ) );
        $pending[ $key ] = $value;
    }
    if ( $capture ) {
        att_mcp_record_change( $capture, sprintf( 'Meta on #%d: %s', $post_id, implode( ', ', array_keys( $pending ) ) ) );
    }
    foreach ( $pending as $key => $value ) {
        if ( null === $value ) {
            delete_post_meta( $post_id, $key );
        } else {
            update_post_meta( $post_id, $key, wp_slash( att_mcp_filter_untrusted( $value ) ) );
        }
    }
    return array( array_keys( $pending ), $skipped );
}
