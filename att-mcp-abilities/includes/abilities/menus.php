<?php
/**
 * Menu abilities — read, create, update, and delete classic WordPress
 * navigation menus.
 *
 * Menus are `nav_menu` taxonomy terms; items are `nav_menu_item` posts. These
 * use core APIs (wp_get_nav_menus / wp_create_nav_menu / wp_update_nav_menu_item
 * / wp_delete_nav_menu), so no plugin/theme gate is needed. Theme menu LOCATIONS
 * (e.g. GeneratePress "primary") are read from get_registered_nav_menus() and
 * assigned via the nav_menu_locations theme mod (undoable).
 *
 * Block themes use the Navigation block (wp_navigation posts) instead — edit
 * those with att/update-content or the REST tools.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** JSON schema for one menu item (children use the same shape). */
function att_mcp_menu_item_schema() {
    return array(
        'type'       => 'object',
        'properties' => array(
            'title'       => array( 'type' => 'string',  'description' => 'Item label (optional for page/post/term links — defaults to the object title).' ),
            'url'         => array( 'type' => 'string',  'description' => 'Custom link URL (for a custom item).' ),
            'page_id'     => array( 'type' => 'integer', 'description' => 'Link to this page ID (shortcut for object_id with object=page).' ),
            'object_id'   => array( 'type' => 'integer', 'description' => 'Linked object ID (post/page/term).' ),
            'object'      => array( 'type' => 'string',  'description' => 'Object kind, e.g. page, post, category, product_cat. Default page.' ),
            'type'        => array( 'type' => 'string',  'description' => 'post_type | taxonomy | custom. Default post_type for object links, custom for url.' ),
            'target'      => array( 'type' => 'string',  'description' => '"_blank" to open in a new tab, "" otherwise.' ),
            'classes'     => array( 'type' => 'string',  'description' => 'Space-separated CSS classes (e.g. a "button" class a theme styles).' ),
            'parent'      => array( 'type' => 'integer', 'description' => 'Existing parent menu-item ID (for add_items on an existing menu).' ),
            'order'       => array( 'type' => 'integer', 'description' => 'Explicit position; otherwise the item is appended.' ),
            'children'    => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Sub-items with this same shape (nested dropdowns).' ),
        ),
    );
}

function att_mcp_register_menus_abilities() {
    $base = att_mcp_ability_base();
    $can  = function () { return current_user_can( 'edit_theme_options' ); };
    $item = att_mcp_menu_item_schema();

    if ( att_mcp_is_enabled( 'att/get-menus' ) ) {
        att_mcp_register( 'att/get-menus', array_merge( $base, array(
            'label'               => 'Get Menus',
            'description'         => 'Lists all classic navigation menus with their items, which theme locations each menu is assigned to, and the theme\'s available (registered) menu locations.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_get_menus',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/create-menu' ) ) {
        att_mcp_register( 'att/create-menu', array_merge( $base, array(
            'label'               => 'Create Menu',
            'description'         => 'Creates a navigation menu, optionally with (nested) items, and assigns it to theme locations. Items are custom links ({title,url}) or object links ({title,page_id} / {object_id,object,type}); use "children" for dropdowns. Location slugs come from att/get-menus registered_locations.',
            'input_schema'        => array(
                'type'       => 'object',
                'required'   => array( 'name' ),
                'properties' => array(
                    'name'      => array( 'type' => 'string', 'description' => 'Menu name (must be unique).' ),
                    'location'  => array( 'type' => 'string', 'description' => 'A registered theme location slug (e.g. "primary").' ),
                    'locations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Several theme location slugs.' ),
                    'items'     => array( 'type' => 'array', 'items' => $item, 'description' => 'Ordered menu items.' ),
                ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_create_menu',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/update-menu' ) ) {
        att_mcp_register( 'att/update-menu', array_merge( $base, array(
            'label'               => 'Update Menu',
            'description'         => 'Updates a menu (by id or name): rename it, remove items, edit items (title/url/parent/order/target/classes), append new (nested) items, replace all items, and assign or unassign theme locations. Read it with att/get-menus first.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'id'                 => array( 'type' => 'integer', 'description' => 'Menu ID.' ),
                    'name'               => array( 'type' => 'string',  'description' => 'Menu name (alternative to id).' ),
                    'new_name'           => array( 'type' => 'string',  'description' => 'Rename the menu.' ),
                    'replace_items'      => array( 'type' => 'boolean', 'description' => 'Delete ALL existing items before adding add_items.' ),
                    'remove_item_ids'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Menu-item IDs to delete.' ),
                    'update_items'       => array( 'type' => 'array', 'items' => array( 'type' => 'object' ), 'description' => 'Existing items to change: {id, title?, url?, parent?, order?, target?, classes?}.' ),
                    'add_items'          => array( 'type' => 'array', 'items' => $item, 'description' => 'Items to append (same shape as create-menu items).' ),
                    'locations'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Theme locations to assign this menu to.' ),
                    'unassign_locations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Theme locations to clear (only if this menu is assigned there).' ),
                ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_update_menu',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/delete-menu' ) ) {
        att_mcp_register( 'att/delete-menu', array_merge( $base, array(
            'label'               => 'Delete Menu',
            'description'         => 'Deletes a navigation menu by id or name. (Its location assignment is cleared automatically by WordPress.)',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'id'   => array( 'type' => 'integer', 'description' => 'Menu (nav_menu term) ID.' ),
                    'name' => array( 'type' => 'string', 'description' => 'Menu name (alternative to id).' ),
                ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_delete_menu',
        ) ) );
    }
}

/* ------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* ------------------------------------------------------------------------- */

/** Resolve a menu from {id} or {name}. */
function att_mcp_resolve_menu( $input ) {
    $selector = null;
    if ( ! empty( $input['id'] ) ) {
        $selector = (int) $input['id'];
    } elseif ( ! empty( $input['name'] ) ) {
        $selector = sanitize_text_field( (string) $input['name'] );
    }
    if ( empty( $selector ) ) {
        return new WP_Error( 'att_mcp_no_menu', 'Provide a menu "id" or "name".' );
    }
    $menu = wp_get_nav_menu_object( $selector );
    return $menu ? $menu : new WP_Error( 'att_mcp_no_menu_found', 'No menu found for that id/name.' );
}

/** Menu payload for responses. */
function att_mcp_menu_payload( $menu ) {
    $items = array();
    foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $it ) {
        $items[] = array(
            'id'        => (int) $it->ID,
            'title'     => $it->title,
            'url'       => $it->url,
            'type'      => $it->type,      // custom | post_type | taxonomy
            'object'    => $it->object,    // page | post | category | ...
            'object_id' => (int) $it->object_id,
            'parent'    => (int) $it->menu_item_parent,
            'order'     => (int) $it->menu_order,
            'target'    => $it->target,
            'classes'   => trim( implode( ' ', array_filter( (array) $it->classes ) ) ),
        );
    }
    $assigned = array();
    foreach ( get_nav_menu_locations() as $loc => $mid ) {
        if ( (int) $mid === (int) $menu->term_id ) {
            $assigned[] = $loc;
        }
    }
    return array(
        'id'        => (int) $menu->term_id,
        'name'      => $menu->name,
        'slug'      => $menu->slug,
        'count'     => (int) $menu->count,
        'locations' => $assigned,
        'items'     => $items,
    );
}

/** Build wp_update_nav_menu_item() args from an item definition (validated). */
function att_mcp_menu_item_args( $item, $existing = array() ) {
    $args = $existing ? $existing : array( 'menu-item-status' => 'publish' );

    if ( isset( $item['title'] ) ) {
        $args['menu-item-title'] = sanitize_text_field( (string) $item['title'] );
    }
    if ( isset( $item['target'] ) ) {
        $args['menu-item-target'] = ( '_blank' === $item['target'] ) ? '_blank' : '';
    }
    if ( isset( $item['classes'] ) ) {
        $args['menu-item-classes'] = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $item['classes'] ) ) ) );
    }
    if ( isset( $item['order'] ) ) {
        $args['menu-item-position'] = (int) $item['order'];
    }
    if ( isset( $item['parent'] ) ) {
        $args['menu-item-parent-id'] = (int) $item['parent'];
    }

    $object_id = 0;
    if ( ! empty( $item['page_id'] ) ) {
        $object_id = (int) $item['page_id'];
        $item['object'] = 'page';
        $item['type']   = 'post_type';
    } elseif ( ! empty( $item['object_id'] ) ) {
        $object_id = (int) $item['object_id'];
    }

    if ( $object_id > 0 ) {
        $type   = ! empty( $item['type'] ) ? sanitize_key( $item['type'] ) : 'post_type';
        $object = ! empty( $item['object'] ) ? sanitize_key( $item['object'] ) : 'page';
        if ( 'taxonomy' === $type ) {
            $term = get_term( $object_id, $object );
            if ( ! $term || is_wp_error( $term ) ) {
                return new WP_Error( 'att_mcp_bad_menu_object', sprintf( 'Term %d not found in %s.', $object_id, $object ) );
            }
        } elseif ( 'post_type' === $type ) {
            $target = get_post( $object_id );
            if ( ! $target ) {
                return new WP_Error( 'att_mcp_bad_menu_object', sprintf( 'Item %d not found.', $object_id ) );
            }
            $object = $target->post_type;
        } else {
            return new WP_Error( 'att_mcp_bad_menu_type', 'Object links need type post_type or taxonomy.' );
        }
        $args['menu-item-object-id'] = $object_id;
        $args['menu-item-object']    = $object;
        $args['menu-item-type']      = $type;
        $args['menu-item-url']       = '';
    } elseif ( isset( $item['url'] ) ) {
        $url = esc_url_raw( (string) $item['url'] );
        if ( '' === $url ) {
            return new WP_Error( 'att_mcp_bad_menu_url', 'Invalid menu item URL.' );
        }
        $args['menu-item-url']       = $url;
        $args['menu-item-type']      = 'custom';
        $args['menu-item-object']    = 'custom';
        $args['menu-item-object-id'] = 0;
    } elseif ( ! $existing ) {
        return new WP_Error( 'att_mcp_menu_item_target', 'Each new item needs a url, page_id, or object_id.' );
    }

    return $args;
}

/** Append items (recursively, for "children") to a menu. Returns array( ids, errors ). */
function att_mcp_menu_add_items( $menu_id, $items, $parent_id = 0, $depth = 0 ) {
    $ids    = array();
    $errors = array();
    foreach ( (array) $items as $item ) {
        if ( ! is_array( $item ) ) {
            continue;
        }
        if ( $parent_id && ! isset( $item['parent'] ) ) {
            $item['parent'] = $parent_id;
        }
        $args = att_mcp_menu_item_args( $item );
        if ( is_wp_error( $args ) ) {
            $errors[] = $args->get_error_message();
            continue;
        }
        $item_id = wp_update_nav_menu_item( $menu_id, 0, wp_slash( $args ) );
        if ( is_wp_error( $item_id ) ) {
            $errors[] = $item_id->get_error_message();
            continue;
        }
        $ids[] = (int) $item_id;
        if ( ! empty( $item['children'] ) && is_array( $item['children'] ) && $depth < 6 ) {
            list( $child_ids, $child_errors ) = att_mcp_menu_add_items( $menu_id, $item['children'], (int) $item_id, $depth + 1 );
            $ids    = array_merge( $ids, $child_ids );
            $errors = array_merge( $errors, $child_errors );
        }
    }
    return array( $ids, $errors );
}

/** Is this nav_menu_item post part of the menu? */
function att_mcp_menu_owns_item( $menu_id, $item_id ) {
    return is_nav_menu_item( $item_id ) && has_term( (int) $menu_id, 'nav_menu', (int) $item_id );
}

/** Assign/unassign theme locations (undoable). Returns array( assigned, unknown ). */
function att_mcp_menu_assign_locations( $menu_id, $assign, $unassign = array() ) {
    $assign   = array_filter( array_map( 'sanitize_key', (array) $assign ) );
    $unassign = array_filter( array_map( 'sanitize_key', (array) $unassign ) );
    if ( ! $assign && ! $unassign ) {
        return array( array(), array() );
    }
    $registered = get_registered_nav_menus();
    $current    = get_theme_mod( 'nav_menu_locations', array() );
    $current    = is_array( $current ) ? $current : array();
    $assigned   = array();
    $unknown    = array();

    att_mcp_snapshot( 'theme_mod', 'nav_menu_locations', 'Menu locations' );
    foreach ( $assign as $loc ) {
        if ( isset( $registered[ $loc ] ) ) {
            $current[ $loc ] = (int) $menu_id;
            $assigned[]      = $loc;
        } else {
            $unknown[] = $loc;
        }
    }
    foreach ( $unassign as $loc ) {
        if ( isset( $current[ $loc ] ) && (int) $current[ $loc ] === (int) $menu_id ) {
            unset( $current[ $loc ] );
        }
    }
    set_theme_mod( 'nav_menu_locations', $current );
    return array( $assigned, $unknown );
}

/* ------------------------------------------------------------------------- */
/* Execute callbacks                                                          */
/* ------------------------------------------------------------------------- */

function att_mcp_execute_get_menus( $input ) {
    $out = array();
    foreach ( wp_get_nav_menus() as $menu ) {
        $out[] = att_mcp_menu_payload( $menu );
    }
    $data = array(
        'menus'                => $out,
        'total'                => count( $out ),
        'registered_locations' => get_registered_nav_menus(), // [ slug => description ]
    );
    if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
        $data['note'] = 'The active theme is a block theme: its header uses the Navigation block (wp_navigation posts). Edit those with att/list-content post_type "wp_navigation" and att/update-content.';
    }
    return $data;
}

function att_mcp_execute_create_menu( $input ) {
    $name = isset( $input['name'] ) ? sanitize_text_field( (string) $input['name'] ) : '';
    if ( '' === $name ) {
        return new WP_Error( 'att_mcp_no_name', 'A menu "name" is required.' );
    }

    $menu_id = wp_create_nav_menu( wp_slash( $name ) ); // WP_Error if the name already exists
    if ( is_wp_error( $menu_id ) ) {
        return $menu_id;
    }

    list( $added, $errors ) = att_mcp_menu_add_items( $menu_id, isset( $input['items'] ) ? $input['items'] : array() );

    $locations = isset( $input['locations'] ) && is_array( $input['locations'] ) ? $input['locations'] : array();
    if ( ! empty( $input['location'] ) ) {
        $locations[] = $input['location'];
    }
    list( $assigned, $unknown ) = att_mcp_menu_assign_locations( $menu_id, $locations );

    return array(
        'created'           => true,
        'id'                => (int) $menu_id,
        'name'              => $name,
        'items_added'       => count( $added ),
        'item_ids'          => $added,
        'errors'            => $errors,
        'locations'         => $assigned,
        'unknown_locations' => $unknown,
        'note'              => 'Run att/purge-cache if a page cache is active.',
    );
}

function att_mcp_execute_update_menu( $input ) {
    $menu = att_mcp_resolve_menu( $input );
    if ( is_wp_error( $menu ) ) {
        return $menu;
    }
    $menu_id = (int) $menu->term_id;
    $report  = array( 'removed' => array(), 'updated' => array(), 'added' => array(), 'errors' => array() );

    if ( ! empty( $input['new_name'] ) ) {
        $renamed = wp_update_nav_menu_object( $menu_id, wp_slash( array( 'menu-name' => sanitize_text_field( (string) $input['new_name'] ), 'description' => $menu->description ) ) );
        if ( is_wp_error( $renamed ) ) {
            return $renamed;
        }
    }

    $to_remove = array();
    if ( ! empty( $input['replace_items'] ) ) {
        foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $it ) {
            $to_remove[] = (int) $it->ID;
        }
    }
    if ( ! empty( $input['remove_item_ids'] ) && is_array( $input['remove_item_ids'] ) ) {
        $to_remove = array_merge( $to_remove, array_map( 'intval', $input['remove_item_ids'] ) );
    }
    foreach ( array_unique( $to_remove ) as $item_id ) {
        if ( ! att_mcp_menu_owns_item( $menu_id, $item_id ) ) {
            $report['errors'][] = sprintf( 'Item %d is not in this menu.', $item_id );
            continue;
        }
        if ( wp_delete_post( $item_id, true ) ) {
            $report['removed'][] = $item_id;
        }
    }

    if ( ! empty( $input['update_items'] ) && is_array( $input['update_items'] ) ) {
        foreach ( $input['update_items'] as $change ) {
            $item_id = isset( $change['id'] ) ? (int) $change['id'] : 0;
            if ( ! $item_id || ! att_mcp_menu_owns_item( $menu_id, $item_id ) ) {
                $report['errors'][] = sprintf( 'Item %d is not in this menu.', $item_id );
                continue;
            }
            // Start from the item's full current state — wp_update_nav_menu_item() resets omitted fields.
            $it       = wp_setup_nav_menu_item( get_post( $item_id ) );
            $existing = array(
                'menu-item-db-id'       => $item_id,
                'menu-item-object-id'   => (int) $it->object_id,
                'menu-item-object'      => $it->object,
                'menu-item-parent-id'   => (int) $it->menu_item_parent,
                'menu-item-position'    => (int) $it->menu_order,
                'menu-item-type'        => $it->type,
                'menu-item-title'       => $it->title,
                'menu-item-url'         => $it->url,
                'menu-item-description' => $it->description,
                'menu-item-attr-title'  => $it->attr_title,
                'menu-item-target'      => $it->target,
                'menu-item-classes'     => implode( ' ', array_filter( (array) $it->classes ) ),
                'menu-item-xfn'         => $it->xfn,
                'menu-item-status'      => 'publish',
            );
            $args = att_mcp_menu_item_args( $change, $existing );
            if ( is_wp_error( $args ) ) {
                $report['errors'][] = $args->get_error_message();
                continue;
            }
            $saved = wp_update_nav_menu_item( $menu_id, $item_id, wp_slash( $args ) );
            if ( is_wp_error( $saved ) ) {
                $report['errors'][] = $saved->get_error_message();
            } else {
                $report['updated'][] = $item_id;
            }
        }
    }

    if ( ! empty( $input['add_items'] ) && is_array( $input['add_items'] ) ) {
        list( $added, $errors ) = att_mcp_menu_add_items( $menu_id, $input['add_items'] );
        $report['added']  = $added;
        $report['errors'] = array_merge( $report['errors'], $errors );
    }

    list( $assigned, $unknown ) = att_mcp_menu_assign_locations(
        $menu_id,
        isset( $input['locations'] ) ? $input['locations'] : array(),
        isset( $input['unassign_locations'] ) ? $input['unassign_locations'] : array()
    );
    if ( $unknown ) {
        $report['errors'][] = 'Unknown theme locations: ' . implode( ', ', $unknown );
    }

    $report['menu'] = att_mcp_menu_payload( wp_get_nav_menu_object( $menu_id ) );
    $report['note'] = 'Run att/purge-cache if a page cache is active.';
    return $report;
}

function att_mcp_execute_delete_menu( $input ) {
    $menu = att_mcp_resolve_menu( $input );
    if ( is_wp_error( $menu ) ) {
        return $menu;
    }
    $result = wp_delete_nav_menu( $menu->term_id );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    if ( false === $result ) {
        return new WP_Error( 'att_mcp_delete_failed', 'Failed to delete the menu.' );
    }
    return array( 'deleted' => true, 'id' => (int) $menu->term_id, 'name' => $menu->name );
}
