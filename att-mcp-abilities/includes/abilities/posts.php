<?php
/**
 * Posts abilities. Writes delegate to the hardened content core in content.php
 * (per-post capability checks, publish rights, exact block markup, undoable meta).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_posts_abilities() {
    $base  = att_mcp_ability_base();
    $props = att_mcp_content_schema_props();
    unset( $props['parent'], $props['menu_order'] );
    $props['categories'] = array( 'type' => 'array', 'items' => array( 'type' => array( 'integer', 'string' ) ), 'description' => 'Category IDs or names (replaces existing).' );
    $props['tags']       = array( 'type' => 'array', 'items' => array( 'type' => array( 'integer', 'string' ) ), 'description' => 'Tag IDs or names (replaces existing).' );

    if ( att_mcp_is_enabled( 'att/get-posts' ) ) {
        att_mcp_register( 'att/get-posts', array_merge( $base, array(
            'label'               => 'Get Blog Posts',
            'description'         => 'Returns published blog posts with metadata (drafts and other unpublished statuses are never returned — use att/list-content for those).',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'per_page' => array( 'type' => 'integer', 'description' => 'Number of posts, 1–100. Default 10.' ),
                'page'     => array( 'type' => 'integer', 'description' => 'Page number. Default 1.' ),
            ) ),
            'permission_callback' => '__return_true',
            'execute_callback'    => 'att_mcp_execute_get_posts',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/create-post' ) ) {
        att_mcp_register( 'att/create-post', array_merge( $base, array(
            'label'               => 'Create Post',
            'description'         => 'Creates a blog post. Defaults to draft; publishing requires publish rights.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'title', 'content' ), 'properties' => $props ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_create_post',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/update-post' ) ) {
        att_mcp_register( 'att/update-post', array_merge( $base, array(
            'label'               => 'Update Post',
            'description'         => 'Updates an existing blog post by ID. Only the fields you pass change; "content" replaces the whole body.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array_merge( array( 'id' => array( 'type' => 'integer', 'description' => 'Post ID.' ) ), $props ) ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_update_post',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/delete-post' ) ) {
        att_mcp_register( 'att/delete-post', array_merge( $base, array(
            'label'               => 'Delete Post',
            'description'         => 'Moves a blog post to trash.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
                'id' => array( 'type' => 'integer', 'description' => 'Post ID.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'delete_posts' ); },
            'execute_callback'    => 'att_mcp_execute_delete_post',
        ) ) );
    }
}

function att_mcp_execute_get_posts( $input ) {
    // Published posts only — drafts/pending/future are never exposed by this public read.
    $q = new WP_Query( array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => att_mcp_int_arg( $input, 'per_page', 10, 1, 100 ),
        'paged'          => att_mcp_int_arg( $input, 'page', 1, 1, 10000 ),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'has_password'   => false,
    ) );
    $posts = array();
    foreach ( $q->posts as $p ) {
        $posts[] = array(
            'id'         => $p->ID,
            'title'      => $p->post_title,
            'url'        => get_permalink( $p->ID ),
            'status'     => $p->post_status,
            'date'       => get_the_date( 'Y-m-d', $p->ID ),
            'author'     => get_the_author_meta( 'display_name', $p->post_author ),
            'categories' => wp_get_post_categories( $p->ID, array( 'fields' => 'names' ) ),
            'tags'       => wp_get_post_tags( $p->ID, array( 'fields' => 'names' ) ),
            'excerpt'    => has_excerpt( $p->ID ) ? get_the_excerpt( $p ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ), 40 ),
        );
    }
    return array( 'posts' => $posts, 'total' => (int) $q->found_posts, 'total_pages' => (int) $q->max_num_pages );
}

function att_mcp_execute_create_post( $input ) {
    unset( $input['id'] );
    return att_mcp_content_save( $input, 'post' );
}

function att_mcp_execute_update_post( $input ) {
    if ( empty( $input['id'] ) ) {
        return new WP_Error( 'att_mcp_no_id', 'A post "id" is required.' );
    }
    return att_mcp_content_save( $input, 'post' );
}

function att_mcp_execute_delete_post( $input ) {
    unset( $input['force'] );
    return att_mcp_content_delete( $input, 'post' );
}
