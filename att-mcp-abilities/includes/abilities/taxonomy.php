<?php
/**
 * Taxonomy abilities — categories, tags, and any other taxonomy.
 * Writes use core's per-term capabilities (edit_term / delete_term) and the
 * taxonomy's own edit_terms capability.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_taxonomy_abilities() {
    $base = att_mcp_ability_base();

    if ( att_mcp_is_enabled( 'att/get-categories' ) ) {
        att_mcp_register( 'att/get-categories', array_merge( $base, array(
            'label'               => 'Get Categories',
            'description'         => 'Returns all post categories (including empty ones).',
            'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
            'permission_callback' => '__return_true',
            'execute_callback'    => 'att_mcp_execute_get_categories',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/create-category' ) ) {
        att_mcp_register( 'att/create-category', array_merge( $base, array(
            'label'               => 'Create Category',
            'description'         => 'Creates a new post category.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'name' ), 'properties' => array(
                'name'        => array( 'type' => 'string',  'description' => 'Category name.' ),
                'slug'        => array( 'type' => 'string',  'description' => 'Optional slug.' ),
                'description' => array( 'type' => 'string',  'description' => 'Description.' ),
                'parent'      => array( 'type' => 'integer', 'description' => 'Parent category ID.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'manage_categories' ); },
            'execute_callback'    => 'att_mcp_execute_create_category',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/get-tags' ) ) {
        att_mcp_register( 'att/get-tags', array_merge( $base, array(
            'label'               => 'Get Tags',
            'description'         => 'Returns all post tags (including empty ones).',
            'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
            'permission_callback' => '__return_true',
            'execute_callback'    => 'att_mcp_execute_get_tags',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/create-tag' ) ) {
        att_mcp_register( 'att/create-tag', array_merge( $base, array(
            'label'               => 'Create Tag',
            'description'         => 'Creates a new post tag.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'name' ), 'properties' => array(
                'name'        => array( 'type' => 'string', 'description' => 'Tag name.' ),
                'slug'        => array( 'type' => 'string', 'description' => 'Optional slug.' ),
                'description' => array( 'type' => 'string', 'description' => 'Description.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'manage_categories' ); },
            'execute_callback'    => 'att_mcp_execute_create_tag',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/get-terms' ) ) {
        att_mcp_register( 'att/get-terms', array_merge( $base, array(
            'label'               => 'Get Terms',
            'description'         => 'Lists terms of any taxonomy (e.g. "product_cat"). Private taxonomies require permission to assign them.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'taxonomy' ), 'properties' => array(
                'taxonomy' => array( 'type' => 'string',  'description' => 'Taxonomy slug, e.g. category, post_tag, product_cat.' ),
                'search'   => array( 'type' => 'string',  'description' => 'Name filter.' ),
                'parent'   => array( 'type' => 'integer', 'description' => 'Only direct children of this term ID.' ),
                'per_page' => array( 'type' => 'integer', 'description' => '1–500. Default 200.' ),
            ) ),
            'permission_callback' => '__return_true',
            'execute_callback'    => 'att_mcp_execute_get_terms',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/update-term' ) ) {
        att_mcp_register( 'att/update-term', array_merge( $base, array(
            'label'               => 'Update Term',
            'description'         => 'Updates a category, tag, or other term by ID (name, slug, description, parent).',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
                'id'          => array( 'type' => 'integer', 'description' => 'Term ID.' ),
                'name'        => array( 'type' => 'string',  'description' => 'New name.' ),
                'slug'        => array( 'type' => 'string',  'description' => 'New slug.' ),
                'description' => array( 'type' => 'string',  'description' => 'New description.' ),
                'parent'      => array( 'type' => 'integer', 'description' => 'New parent term ID (hierarchical taxonomies).' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'manage_categories' ) || current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_update_term',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/delete-term' ) ) {
        att_mcp_register( 'att/delete-term', array_merge( $base, array(
            'label'               => 'Delete Term',
            'description'         => 'Deletes a category, tag, or other term by ID. Posts keep existing; the default category cannot be deleted.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
                'id' => array( 'type' => 'integer', 'description' => 'Term ID.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'manage_categories' ) || current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_delete_term',
        ) ) );
    }
}

/* ------------------------------------------------------------------------- */

function att_mcp_term_payload( $t ) {
    return array(
        'id'          => (int) $t->term_id,
        'name'        => $t->name,
        'slug'        => $t->slug,
        'taxonomy'    => $t->taxonomy,
        'description' => $t->description,
        'parent'      => (int) $t->parent,
        'count'       => (int) $t->count,
    );
}

/** Term description: kept for users with unfiltered_html, KSES-filtered otherwise. */
function att_mcp_term_description( $text ) {
    return att_mcp_prepare_content( (string) $text );
}

function att_mcp_execute_get_categories( $input ) {
    $result = array();
    foreach ( get_categories( array( 'hide_empty' => false ) ) as $c ) {
        $result[] = array( 'id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'count' => $c->count, 'parent' => $c->parent );
    }
    return array( 'categories' => $result );
}

function att_mcp_execute_get_tags( $input ) {
    $result = array();
    foreach ( get_tags( array( 'hide_empty' => false ) ) as $t ) {
        $result[] = array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count );
    }
    return array( 'tags' => $result );
}

/** Create a term in $taxonomy after checking that taxonomy's edit capability. */
function att_mcp_create_term( $taxonomy, $input ) {
    $tax = get_taxonomy( $taxonomy );
    if ( ! $tax || ! current_user_can( $tax->cap->edit_terms ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to create terms in this taxonomy.' );
    }
    $name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
    if ( '' === $name ) {
        return new WP_Error( 'att_mcp_no_name', 'A "name" is required.' );
    }
    $args = array();
    if ( ! empty( $input['slug'] ) ) {
        $args['slug'] = sanitize_title( (string) $input['slug'] );
    }
    if ( isset( $input['description'] ) ) {
        $args['description'] = att_mcp_term_description( $input['description'] );
    }
    if ( ! empty( $input['parent'] ) && $tax->hierarchical ) {
        $args['parent'] = (int) $input['parent'];
    }
    // wp_insert_term() unslashes its input.
    $result = wp_insert_term( wp_slash( $name ), $taxonomy, wp_slash( $args ) );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    return array( 'success' => true, 'id' => (int) $result['term_id'], 'name' => $name, 'taxonomy' => $taxonomy );
}

function att_mcp_execute_create_category( $input ) {
    return att_mcp_create_term( 'category', $input );
}

function att_mcp_execute_create_tag( $input ) {
    unset( $input['parent'] );
    return att_mcp_create_term( 'post_tag', $input );
}

function att_mcp_execute_get_terms( $input ) {
    $taxonomy = isset( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';
    $tax      = get_taxonomy( $taxonomy );
    if ( ! $tax ) {
        return new WP_Error( 'att_mcp_bad_taxonomy', 'Unknown taxonomy.' );
    }
    if ( ! $tax->public && ! current_user_can( $tax->cap->assign_terms ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'This taxonomy is private.' );
    }
    $args = array(
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'number'     => att_mcp_int_arg( $input, 'per_page', 200, 1, 500 ),
    );
    if ( ! empty( $input['search'] ) ) {
        $args['search'] = sanitize_text_field( (string) $input['search'] );
    }
    if ( isset( $input['parent'] ) ) {
        $args['parent'] = (int) $input['parent'];
    }
    $terms = get_terms( $args );
    if ( is_wp_error( $terms ) ) {
        return $terms;
    }
    return array( 'taxonomy' => $taxonomy, 'terms' => array_map( 'att_mcp_term_payload', $terms ) );
}

function att_mcp_execute_update_term( $input ) {
    $term = get_term( isset( $input['id'] ) ? (int) $input['id'] : 0 );
    if ( ! $term || is_wp_error( $term ) ) {
        return new WP_Error( 'att_mcp_not_found', 'No term found with that ID.' );
    }
    if ( ! current_user_can( 'edit_term', $term->term_id ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to edit this term.' );
    }
    $args = array();
    if ( isset( $input['name'] ) ) {
        $args['name'] = sanitize_text_field( (string) $input['name'] );
    }
    if ( isset( $input['slug'] ) ) {
        $args['slug'] = sanitize_title( (string) $input['slug'] );
    }
    if ( isset( $input['description'] ) ) {
        $args['description'] = att_mcp_term_description( $input['description'] );
    }
    if ( isset( $input['parent'] ) && is_taxonomy_hierarchical( $term->taxonomy ) ) {
        $args['parent'] = (int) $input['parent'];
    }
    if ( ! $args ) {
        return new WP_Error( 'att_mcp_nothing_to_do', 'Pass at least one of name, slug, description, parent.' );
    }
    // wp_update_term() unslashes its input.
    $result = wp_update_term( $term->term_id, $term->taxonomy, wp_slash( $args ) );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    return array( 'success' => true, 'term' => att_mcp_term_payload( get_term( $term->term_id, $term->taxonomy ) ) );
}

function att_mcp_execute_delete_term( $input ) {
    $term = get_term( isset( $input['id'] ) ? (int) $input['id'] : 0 );
    if ( ! $term || is_wp_error( $term ) ) {
        return new WP_Error( 'att_mcp_not_found', 'No term found with that ID.' );
    }
    if ( ! current_user_can( 'delete_term', $term->term_id ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to delete this term (the default category can never be deleted).' );
    }
    $result = wp_delete_term( $term->term_id, $term->taxonomy );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    if ( true !== $result ) {
        return new WP_Error( 'att_mcp_delete_failed', 'The term could not be deleted.' );
    }
    return array( 'success' => true, 'deleted' => (int) $term->term_id, 'taxonomy' => $term->taxonomy );
}
