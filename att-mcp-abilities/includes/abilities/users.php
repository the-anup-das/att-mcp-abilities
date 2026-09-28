<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_users_abilities() {
    $base = att_mcp_ability_base();

    if ( att_mcp_is_enabled( 'att/get-users' ) ) {
        att_mcp_register( 'att/get-users', array_merge( $base, array(
            'label'               => 'Get Users',
            'description'         => 'Lists registered users (ID, display name, email, roles, registration date).',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'role'     => array( 'type' => 'string',  'description' => 'Only users with this role, e.g. author.' ),
                'per_page' => array( 'type' => 'integer', 'description' => '1–200. Default 100.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'list_users' ); },
            'execute_callback'    => 'att_mcp_execute_get_users',
        ) ) );
    }
}

function att_mcp_execute_get_users( $input ) {
    $args = array( 'number' => att_mcp_int_arg( $input, 'per_page', 100, 1, 200 ) );
    if ( ! empty( $input['role'] ) ) {
        $args['role'] = sanitize_key( $input['role'] );
    }
    $result = array();
    foreach ( get_users( $args ) as $u ) {
        $result[] = array(
            'id'           => $u->ID,
            'display_name' => $u->display_name,
            'email'        => $u->user_email,
            'roles'        => array_values( $u->roles ),
            'registered'   => $u->user_registered,
        );
    }
    return array( 'users' => $result );
}
