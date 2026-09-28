<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_search_abilities() {
    $base = att_mcp_ability_base();

    if ( att_mcp_is_enabled( 'att/search' ) ) {
        att_mcp_register( 'att/search', array_merge( $base, array(
            'label'               => 'Search Content',
            'description'         => 'Searches published posts and pages by keyword.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'query' ), 'properties' => array(
                'query'    => array( 'type' => 'string',  'description' => 'Search keyword.' ),
                'per_page' => array( 'type' => 'integer', 'description' => '1–50. Default 10.' ),
            ) ),
            'permission_callback' => '__return_true',
            'execute_callback'    => 'att_mcp_execute_search',
        ) ) );
    }
}

function att_mcp_execute_search( $input ) {
    $keyword = isset( $input['query'] ) ? sanitize_text_field( (string) $input['query'] ) : '';
    if ( '' === $keyword ) {
        return new WP_Error( 'att_mcp_no_query', 'A search "query" is required.' );
    }
    $q = new WP_Query( array(
        's'              => $keyword,
        'post_status'    => 'publish',
        'has_password'   => false,
        'posts_per_page' => att_mcp_int_arg( $input, 'per_page', 10, 1, 50 ),
    ) );
    $results = array();
    foreach ( $q->posts as $p ) {
        $results[] = array( 'id' => $p->ID, 'title' => $p->post_title, 'url' => get_permalink( $p->ID ), 'type' => $p->post_type );
    }
    return array( 'results' => $results, 'total' => (int) $q->found_posts );
}
