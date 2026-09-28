<?php
/**
 * Pages abilities. Writes delegate to the hardened content core in content.php
 * (per-page capability checks, publish rights, exact block markup, templates,
 * undoable meta).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_pages_abilities() {
    $base  = att_mcp_ability_base();
    $props = att_mcp_content_schema_props();
    unset( $props['terms'] );

    if ( att_mcp_is_enabled( 'att/get-pages' ) ) {
        att_mcp_register( 'att/get-pages', array_merge( $base, array(
            'label'               => 'Get Pages',
            'description'         => 'Returns published pages (use att/list-content for drafts and att/get-content for full content).',
            'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
            'permission_callback' => '__return_true',
            'execute_callback'    => 'att_mcp_execute_get_pages',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/create-page' ) ) {
        att_mcp_register( 'att/create-page', array_merge( $base, array(
            'label'               => 'Create Page',
            'description'         => 'Creates a page. Defaults to draft; publishing requires publish rights. Set "template" to use a theme page template and "parent" to nest it.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'title', 'content' ), 'properties' => $props ),
            'permission_callback' => function () { return current_user_can( 'edit_pages' ); },
            'execute_callback'    => 'att_mcp_execute_create_page',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/update-page' ) ) {
        att_mcp_register( 'att/update-page', array_merge( $base, array(
            'label'               => 'Update Page',
            'description'         => 'Updates an existing page by ID. Only the fields you pass change; "content" replaces the whole body — read it with att/get-content first.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array_merge( array( 'id' => array( 'type' => 'integer', 'description' => 'Page ID.' ) ), $props ) ),
            'permission_callback' => function () { return current_user_can( 'edit_pages' ); },
            'execute_callback'    => 'att_mcp_execute_update_page',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/delete-page' ) ) {
        att_mcp_register( 'att/delete-page', array_merge( $base, array(
            'label'               => 'Delete Page',
            'description'         => 'Moves a page to trash.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
                'id' => array( 'type' => 'integer', 'description' => 'Page ID.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'delete_pages' ); },
            'execute_callback'    => 'att_mcp_execute_delete_page',
        ) ) );
    }
}

function att_mcp_execute_get_pages( $input ) {
    $pages  = get_pages( array( 'post_status' => 'publish' ) );
    $result = array();
    foreach ( $pages as $page ) {
        if ( post_password_required( $page ) ) {
            continue;
        }
        $result[] = array(
            'id'         => $page->ID,
            'title'      => $page->post_title,
            'url'        => get_permalink( $page->ID ),
            'parent'     => (int) $page->post_parent,
            'menu_order' => (int) $page->menu_order,
            'status'     => $page->post_status,
        );
    }
    return array(
        'pages'          => $result,
        'front_page_id'  => ( 'page' === get_option( 'show_on_front' ) ) ? (int) get_option( 'page_on_front' ) : 0,
        'posts_page_id'  => ( 'page' === get_option( 'show_on_front' ) ) ? (int) get_option( 'page_for_posts' ) : 0,
    );
}

function att_mcp_execute_create_page( $input ) {
    unset( $input['id'] );
    return att_mcp_content_save( $input, 'page' );
}

function att_mcp_execute_update_page( $input ) {
    if ( empty( $input['id'] ) ) {
        return new WP_Error( 'att_mcp_no_id', 'A page "id" is required.' );
    }
    return att_mcp_content_save( $input, 'page' );
}

function att_mcp_execute_delete_page( $input ) {
    unset( $input['force'] );
    return att_mcp_content_delete( $input, 'page' );
}
