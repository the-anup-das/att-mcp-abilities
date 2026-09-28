<?php
/**
 * Media abilities — list, upload (URL / raw text / base64, SVG sanitized), and
 * edit attachment details. URL uploads go through att_mcp_safe_fetch(), which
 * re-validates every redirect hop and every resolved IP (SSRF guard).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_media_abilities() {
    $base = att_mcp_ability_base();

    if ( att_mcp_is_enabled( 'att/get-media' ) ) {
        att_mcp_register( 'att/get-media', array_merge( $base, array(
            'label'               => 'Get Media',
            'description'         => 'Lists media library items, newest first.',
            'input_schema'        => array( 'type' => 'object', 'properties' => array(
                'per_page' => array( 'type' => 'integer', 'description' => '1–100. Default 20.' ),
                'page'     => array( 'type' => 'integer', 'description' => 'Page number. Default 1.' ),
                'type'     => array( 'type' => 'string',  'description' => 'MIME type filter e.g. image, image/svg+xml, application/pdf.' ),
                'search'   => array( 'type' => 'string',  'description' => 'Keyword filter.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'upload_files' ); },
            'execute_callback'    => 'att_mcp_execute_get_media',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/upload-media' ) ) {
        att_mcp_register( 'att/upload-media', array_merge( $base, array(
            'label'               => 'Upload Media',
            'description'         => 'Uploads a file to the Media Library from a public URL, raw text content (e.g. SVG markup), or base64 bytes. SVGs are sanitized before saving (needs the Safe SVG plugin). Optionally sets the image as the site logo. Use the returned id as featured_media or in image blocks.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'filename' ), 'properties' => array(
                'filename'       => array( 'type' => 'string',  'description' => 'Target filename WITH extension, e.g. hero.jpg or logo.svg.' ),
                'url'            => array( 'type' => 'string',  'description' => 'Fetch the file from this public URL.' ),
                'content'        => array( 'type' => 'string',  'description' => 'Raw text content for text formats, e.g. SVG markup.' ),
                'content_base64' => array( 'type' => 'string',  'description' => 'Base64-encoded bytes for binary files, e.g. PNG.' ),
                'title'          => array( 'type' => 'string',  'description' => 'Optional attachment title.' ),
                'alt'            => array( 'type' => 'string',  'description' => 'Image alt text (describe the image for screen readers).' ),
                'caption'        => array( 'type' => 'string',  'description' => 'Optional caption.' ),
                'attach_to'      => array( 'type' => 'integer', 'description' => 'Optional post/page ID to attach the file to.' ),
                'set_as_logo'    => array( 'type' => 'boolean', 'description' => 'If true, set the uploaded image/SVG as the site custom logo.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'upload_files' ); },
            'execute_callback'    => 'att_mcp_execute_upload_media',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/update-media' ) ) {
        att_mcp_register( 'att/update-media', array_merge( $base, array(
            'label'               => 'Update Media',
            'description'         => 'Updates a media item\'s title, alt text, caption, or description.',
            'input_schema'        => array( 'type' => 'object', 'required' => array( 'id' ), 'properties' => array(
                'id'          => array( 'type' => 'integer', 'description' => 'Attachment ID.' ),
                'title'       => array( 'type' => 'string',  'description' => 'New title.' ),
                'alt'         => array( 'type' => 'string',  'description' => 'New alt text.' ),
                'caption'     => array( 'type' => 'string',  'description' => 'New caption.' ),
                'description' => array( 'type' => 'string',  'description' => 'New description.' ),
            ) ),
            'permission_callback' => function () { return current_user_can( 'upload_files' ); },
            'execute_callback'    => 'att_mcp_execute_update_media',
        ) ) );
    }
}

function att_mcp_execute_get_media( $input ) {
    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => att_mcp_int_arg( $input, 'per_page', 20, 1, 100 ),
        'paged'          => att_mcp_int_arg( $input, 'page', 1, 1, 10000 ),
    );
    if ( ! empty( $input['type'] ) ) {
        $args['post_mime_type'] = sanitize_mime_type( $input['type'] );
    }
    if ( ! empty( $input['search'] ) ) {
        $args['s'] = sanitize_text_field( (string) $input['search'] );
    }
    $q      = new WP_Query( $args );
    $result = array();
    foreach ( $q->posts as $item ) {
        $meta     = wp_get_attachment_metadata( $item->ID );
        $result[] = array(
            'id'     => $item->ID,
            'title'  => $item->post_title,
            'url'    => wp_get_attachment_url( $item->ID ),
            'type'   => $item->post_mime_type,
            'alt'    => get_post_meta( $item->ID, '_wp_attachment_image_alt', true ),
            'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : null,
            'height' => isset( $meta['height'] ) ? (int) $meta['height'] : null,
            'date'   => get_the_date( 'Y-m-d', $item->ID ),
        );
    }
    return array( 'media' => $result, 'total' => (int) $q->found_posts );
}

function att_mcp_execute_upload_media( $input ) {
    $filename = isset( $input['filename'] ) ? sanitize_file_name( (string) $input['filename'] ) : '';
    if ( '' === $filename ) {
        return new WP_Error( 'att_mcp_no_filename', 'A "filename" (with extension) is required.' );
    }
    if ( ! empty( $input['set_as_logo'] ) && ! current_user_can( 'edit_theme_options' ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'Setting the site logo requires permission to edit theme options.' );
    }
    $attach_to = isset( $input['attach_to'] ) ? (int) $input['attach_to'] : 0;
    if ( $attach_to && ! current_user_can( 'edit_post', $attach_to ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You cannot attach files to that item.' );
    }

    // Extension must map to an allowed mime type (respects Safe SVG's upload_mimes).
    $check = wp_check_filetype( $filename, get_allowed_mime_types() );
    if ( empty( $check['type'] ) ) {
        return new WP_Error( 'att_mcp_bad_type', 'That file type is not allowed for upload on this site.' );
    }
    $is_svg    = ( 'image/svg+xml' === $check['type'] || (bool) preg_match( '/\.svgz?$/i', $filename ) );
    $max_bytes = (int) wp_max_upload_size();

    // Resolve bytes from exactly one source.
    if ( ! empty( $input['url'] ) ) {
        $resp = att_mcp_safe_fetch( $input['url'], $max_bytes );
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
            return new WP_Error( 'att_mcp_fetch_failed', 'Fetch returned HTTP ' . (int) wp_remote_retrieve_response_code( $resp ) . '.' );
        }
        $bytes = wp_remote_retrieve_body( $resp );
    } elseif ( isset( $input['content_base64'] ) && '' !== $input['content_base64'] ) {
        $bytes = base64_decode( (string) $input['content_base64'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a user-supplied file upload, not obfuscated code.
        if ( false === $bytes ) {
            return new WP_Error( 'att_mcp_bad_base64', 'content_base64 is not valid base64.' );
        }
    } elseif ( isset( $input['content'] ) && '' !== $input['content'] ) {
        $bytes = (string) $input['content'];
    } else {
        return new WP_Error( 'att_mcp_no_source', 'Provide one of: url, content, or content_base64.' );
    }

    if ( '' === (string) $bytes ) {
        return new WP_Error( 'att_mcp_empty', 'The resolved file content is empty.' );
    }
    if ( strlen( $bytes ) > $max_bytes ) {
        return new WP_Error( 'att_mcp_too_large', sprintf( 'The file exceeds the site upload limit of %s.', size_format( $max_bytes ) ) );
    }

    // SVG: sanitize before it ever touches disk (don't rely solely on the pipeline).
    if ( $is_svg ) {
        if ( ! class_exists( '\enshrined\svgSanitize\Sanitizer' ) ) {
            return new WP_Error( 'att_mcp_no_svg_sanitizer', 'SVG uploads require the Safe SVG plugin (sanitizer not found).' );
        }
        $sanitizer = new \enshrined\svgSanitize\Sanitizer();
        $clean     = $sanitizer->sanitize( (string) $bytes );
        if ( false === $clean || '' === trim( (string) $clean ) ) {
            return new WP_Error( 'att_mcp_svg_unsafe', 'SVG failed sanitization and was not uploaded.' );
        }
        $bytes = $clean;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = wp_tempnam( $filename );
    if ( ! $tmp ) {
        return new WP_Error( 'att_mcp_tmp', 'Could not create a temp file.' );
    }
    // Writes into the system temp dir only; media_handle_sideload() then moves it through core's upload pipeline.
    if ( false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        wp_delete_file( $tmp );
        return new WP_Error( 'att_mcp_write', 'Could not write the temp file.' );
    }

    $file_array = array( 'name' => $filename, 'tmp_name' => $tmp );
    $post_data  = array();
    if ( ! empty( $input['title'] ) ) {
        $post_data['post_title'] = sanitize_text_field( (string) $input['title'] );
    }
    if ( ! empty( $input['caption'] ) ) {
        $post_data['post_excerpt'] = sanitize_textarea_field( (string) $input['caption'] );
    }

    // Runs the full WP sideload pipeline (incl. Safe SVG's prefilter) + creates the attachment.
    $attach_id = media_handle_sideload( $file_array, $attach_to, null, $post_data );
    if ( is_wp_error( $attach_id ) ) {
        wp_delete_file( $tmp );
        return $attach_id;
    }

    if ( ! empty( $input['alt'] ) ) {
        update_post_meta( $attach_id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( (string) $input['alt'] ) ) );
    }

    $logo = null;
    if ( ! empty( $input['set_as_logo'] ) ) {
        att_mcp_snapshot( 'theme_mod', 'custom_logo', 'Site logo' );
        set_theme_mod( 'custom_logo', (int) $attach_id );
        $logo = array( 'set' => true, 'theme_mod' => 'custom_logo' );
    }

    return array(
        'uploaded'      => true,
        'id'            => (int) $attach_id,
        'url'           => wp_get_attachment_url( $attach_id ),
        'type'          => get_post_mime_type( $attach_id ),
        'sanitized_svg' => $is_svg,
        'logo'          => $logo,
        'note'          => $logo
            ? 'Logo set. If the SVG renders too large/small, add a CSS width rule (update-custom-css) and run att/purge-cache.'
            : 'Uploaded. Run att/purge-cache if a page cache is active.',
    );
}

function att_mcp_execute_update_media( $input ) {
    $id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
    $post = get_post( $id );
    if ( ! $post || 'attachment' !== $post->post_type ) {
        return new WP_Error( 'att_mcp_not_found', 'No media item found with that ID.' );
    }
    if ( ! current_user_can( 'edit_post', $id ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to edit this media item.' );
    }

    $postarr = array( 'ID' => $id );
    if ( isset( $input['title'] ) ) {
        $postarr['post_title'] = sanitize_text_field( (string) $input['title'] );
    }
    if ( isset( $input['caption'] ) ) {
        $postarr['post_excerpt'] = att_mcp_prepare_content( $input['caption'] );
    }
    if ( isset( $input['description'] ) ) {
        $postarr['post_content'] = att_mcp_prepare_content( $input['description'] );
    }
    if ( count( $postarr ) > 1 ) {
        $result = wp_update_post( wp_slash( $postarr ), true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
    }
    if ( isset( $input['alt'] ) ) {
        update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( (string) $input['alt'] ) ) );
    }

    $post = get_post( $id );
    return array(
        'success'     => true,
        'id'          => $id,
        'title'       => $post->post_title,
        'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
        'caption'     => $post->post_excerpt,
        'description' => $post->post_content,
        'url'         => wp_get_attachment_url( $id ),
    );
}
