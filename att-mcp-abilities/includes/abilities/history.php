<?php
/**
 * History abilities — undo MCP changes (options, theme mods, CSS, settings,
 * builder data, meta; see includes/history.php) and roll content back to a
 * core revision (posts, pages, patterns, templates, global styles).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_history_abilities() {
    $base = att_mcp_ability_base();

    if ( att_mcp_is_enabled( 'att/list-changes' ) ) {
        att_mcp_register( 'att/list-changes', array_merge( $base, array(
            'label'               => 'List Changes',
            'description'         => "Lists the most recent undoable MCP changes, newest first: change id, time, ability, what it touched, and whether it was already undone. Includes changes made by other plugins' MCP tools that this site governs, such as Rank Math's own write tools (rank-math/…).",
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'limit'          => array( 'type' => 'integer', 'description' => '1–50. Default 20.' ),
                'include_values' => array( 'type' => 'boolean', 'description' => 'Include the stored previous values. Default false.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_list_changes',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/undo-change' ) ) {
        att_mcp_register( 'att/undo-change', array_merge( $base, array(
            'label'               => 'Undo Change',
            'description'         => 'Restores the values an MCP change overwrote. Pass a change id from att/list-changes, or omit it to undo the most recent change that has not been undone. The undo itself is recorded, so it can be reversed too.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'id' => array( 'type' => 'integer', 'description' => 'Change id. Default: latest.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_undo_change',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/list-revisions' ) ) {
        att_mcp_register( 'att/list-revisions', array_merge( $base, array(
            'label'               => 'List Revisions',
            'description'         => 'Lists saved revisions of a post, page, pattern, template, or global-styles item (newest first).',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
                'id'       => array( 'type' => 'integer', 'description' => 'Post/page/template ID (the wp_id of a template).' ),
                'per_page' => array( 'type' => 'integer', 'description' => '1–50. Default 20.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_list_revisions',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/restore-revision' ) ) {
        att_mcp_register( 'att/restore-revision', array_merge( $base, array(
            'label'               => 'Restore Revision',
            'description'         => 'Restores an item to an earlier revision (the current version is kept as a new revision).',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'revision_id' ), 'properties' => array(
                'revision_id' => array( 'type' => 'integer', 'description' => 'Revision ID from att/list-revisions.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
            'execute_callback'    => 'att_mcp_execute_restore_revision',
        ) ) );
    }
}

function att_mcp_execute_list_changes( $input ) {
    $with_values = ! empty( $input['include_values'] );
    $out         = array();
    foreach ( att_mcp_recent_changes( isset( $input['limit'] ) ? (int) $input['limit'] : 20 ) as $row ) {
        $items = array();
        foreach ( $row->items as $item ) {
            $entry = array(
                'type'    => $item['type'],
                'target'  => $item['target'],
                'existed' => ! empty( $item['existed'] ),
            );
            if ( $with_values && att_mcp_can_restore_item( $item ) ) {
                $entry['previous_value'] = $item['value'];
            }
            $items[] = $entry;
        }
        $user  = $row->user_id ? get_userdata( (int) $row->user_id ) : null;
        $out[] = array(
            'id'      => (int) $row->id,
            'time'    => $row->created,
            'user'    => $user ? $user->user_login : null,
            'ability' => $row->ability,
            'label'   => $row->label,
            'undone'  => $row->undone ? $row->undone : false,
            'items'   => $items,
        );
    }
    return array( 'changes' => $out );
}

function att_mcp_execute_undo_change( $input ) {
    $id = isset( $input['id'] ) ? (int) $input['id'] : 0;
    if ( $id <= 0 ) {
        foreach ( att_mcp_recent_changes( 50 ) as $row ) {
            if ( empty( $row->undone ) ) {
                $id = (int) $row->id;
                break;
            }
        }
        if ( $id <= 0 ) {
            return new WP_Error( 'att_mcp_no_change', 'There is no change left to undo.' );
        }
    }
    return att_mcp_undo_change( $id );
}

function att_mcp_execute_list_revisions( $input ) {
    $post = get_post( isset( $input['id'] ) ? (int) $input['id'] : 0 );
    if ( ! $post ) {
        return new WP_Error( 'att_mcp_not_found', 'No item found with that ID.' );
    }
    if ( ! current_user_can( 'edit_post', $post->ID ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to edit this item.' );
    }
    if ( ! wp_revisions_enabled( $post ) ) {
        return new WP_Error( 'att_mcp_no_revisions', 'Revisions are not enabled for this item.' );
    }
    $out = array();
    foreach ( wp_get_post_revisions( $post->ID, array( 'posts_per_page' => att_mcp_int_arg( $input, 'per_page', 20, 1, 50 ) ) ) as $rev ) {
        $user  = get_userdata( (int) $rev->post_author );
        $out[] = array(
            'revision_id' => (int) $rev->ID,
            'date'        => $rev->post_date,
            'author'      => $user ? $user->user_login : null,
            'autosave'    => wp_is_post_autosave( $rev ) ? true : false,
            'title'       => $rev->post_title,
            'preview'     => wp_trim_words( wp_strip_all_tags( $rev->post_content ), 30 ),
            'length'      => strlen( $rev->post_content ),
        );
    }
    return array( 'id' => (int) $post->ID, 'type' => $post->post_type, 'revisions' => $out );
}

function att_mcp_execute_restore_revision( $input ) {
    $revision_id = isset( $input['revision_id'] ) ? (int) $input['revision_id'] : 0;
    $rev         = wp_get_post_revision( $revision_id ); // takes its argument by reference
    if ( ! $rev ) {
        return new WP_Error( 'att_mcp_not_found', 'No revision found with that ID.' );
    }
    $parent = get_post( $rev->post_parent );
    if ( ! $parent || ! current_user_can( 'edit_post', $parent->ID ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to edit the item this revision belongs to.' );
    }
    $restored = wp_restore_post_revision( $rev->ID );
    if ( ! $restored || is_wp_error( $restored ) ) {
        return is_wp_error( $restored ) ? $restored : new WP_Error( 'att_mcp_restore_failed', 'The revision could not be restored.' );
    }
    return array(
        'success'     => true,
        'id'          => (int) $parent->ID,
        'revision_id' => (int) $rev->ID,
        'url'         => get_permalink( $parent ),
        'note'        => 'Run att/purge-cache if a page cache is active.',
    );
}
