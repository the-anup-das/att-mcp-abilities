<?php
/**
 * Performance: measure what makes the site slow, then fix it.
 *
 *  att/audit-performance      server, database, autoload, cron, cache plugins and a real
 *                             anonymous page fetch → prioritised recommendations
 *  att/pagespeed-insights     Google PageSpeed Insights (Lighthouse lab data + real-user
 *                             Core Web Vitals from the Chrome UX Report)
 *  att/optimize-database      revisions, auto-drafts, spam/trash, expired transients, orphaned meta
 *  att/set-option-autoload    stop (or start) autoloading large options — undoable
 *  att/regenerate-thumbnails  create missing image sizes
 *
 * Cache plugin settings (LiteSpeed Cache, Super Page Cache) are in cache-config.php.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_performance_abilities() {
    $base  = att_mcp_ability_base();
    $admin = function () { return current_user_can( 'manage_options' ); };

    if ( att_mcp_is_enabled( 'att/audit-performance' ) ) {
        att_mcp_register( 'att/audit-performance', array_merge( $base, array(
            'label'               => 'Audit Performance',
            'description'         => 'Finds what makes this site slow and returns prioritised fixes, each naming the ability that applies it. Checks the server (PHP version, OPcache, persistent object cache, debug flags), the database (revisions, spam, expired transients, orphaned meta, autoloaded options and the largest ones), WP-Cron, and active cache/optimization plugins, then fetches a real page as an anonymous visitor: response times (first, repeat and uncached), compression, page-cache hits, render-blocking scripts and styles, third-party hosts, image weight and formats, lazy-loading, missing image dimensions, fonts and page size. Use att/pagespeed-insights for Lighthouse scores and real-user Core Web Vitals.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'id'    => array( 'type' => 'integer', 'description' => 'Published post/page ID to test. Default: the home page.' ),
                    'url'   => array( 'type' => 'string', 'description' => 'URL on this site to test (alternative to id).' ),
                    'fetch' => array( 'type' => 'boolean', 'description' => 'Fetch and analyse the page (three requests). Default true; false = server and database checks only.' ),
                ),
            ),
            'permission_callback' => $admin,
            'execute_callback'    => 'att_mcp_execute_audit_performance',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/pagespeed-insights' ) ) {
        att_mcp_register( 'att/pagespeed-insights', array_merge( $base, array(
            'label'               => 'PageSpeed Insights',
            'description'         => 'Runs Google PageSpeed Insights on a page of this site: Lighthouse scores, lab metrics (LCP, FCP, TBT, CLS, Speed Index), real-user Core Web Vitals from the Chrome UX Report when Google has enough traffic data, the LCP element, and the biggest opportunities with the resources responsible. The page URL is sent to Google; the site must be publicly reachable. Takes 15–60 seconds. Without an API key (ATT_MCP_PSI_API_KEY in wp-config.php) Google applies a low shared quota.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'id'         => array( 'type' => 'integer', 'description' => 'Published post/page ID. Default: the home page.' ),
                    'url'        => array( 'type' => 'string', 'description' => 'URL on this site (alternative to id).' ),
                    'strategy'   => array( 'type' => 'string', 'enum' => array( 'mobile', 'desktop' ), 'description' => 'Default mobile (what Google ranks on).' ),
                    'categories' => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string', 'enum' => array( 'performance', 'accessibility', 'best-practices', 'seo' ) ),
                        'description' => 'Lighthouse categories. Default: performance and seo.',
                    ),
                ),
            ),
            'permission_callback' => $admin,
            'execute_callback'    => 'att_mcp_execute_pagespeed_insights',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/optimize-database' ) ) {
        att_mcp_register( 'att/optimize-database', array_merge( $base, array(
            'label'               => 'Optimize Database',
            'description'         => 'Cleans database bloat with WordPress\'s own delete functions: old post revisions (keeping the newest N per post), auto-drafts older than 7 days, spam and trashed comments, expired transients, and orphaned post/comment/term meta. Emptying the post trash is opt-in (task "trashed_posts"). Runs as a DRY RUN by default and only reports counts; pass dry_run:false to delete. Deletions cannot be undone — make sure a backup exists. Works in batches of 500 per task; run again while "remaining" is above 0.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'tasks'          => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string', 'enum' => array( 'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments', 'expired_transients', 'orphaned_meta' ) ),
                        'description' => 'Default: every task except trashed_posts.',
                    ),
                    'keep_revisions' => array( 'type' => 'integer', 'description' => 'Revisions to keep per post (0–100). Default 5.' ),
                    'dry_run'        => array( 'type' => 'boolean', 'description' => 'Only count. Default true.' ),
                ),
            ),
            'permission_callback' => $admin,
            'execute_callback'    => 'att_mcp_execute_optimize_database',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => true, 'idempotent' => true ) ) ),
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/set-option-autoload' ) ) {
        att_mcp_register( 'att/set-option-autoload', array_merge( $base, array(
            'label'               => 'Set Option Autoload',
            'description'         => 'Turns autoloading off (or on) for named options. WordPress loads every autoloaded option on every request, so large options that are rarely used (see "autoload.largest" in att/audit-performance) slow down the whole site. The options keep their values; only when they are loaded changes. Core, secret-like and MCP-control options are refused. Undoable with att/undo-change.',
            'input_schema'        => array(
                'type'       => 'object',
                'required'   => array( 'options', 'autoload' ),
                'properties' => array(
                    'options'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Option names (max 50).' ),
                    'autoload' => array( 'type' => 'boolean', 'description' => 'false = stop autoloading, true = autoload again.' ),
                ),
            ),
            'permission_callback' => $admin,
            'execute_callback'    => 'att_mcp_execute_set_option_autoload',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => false, 'idempotent' => true ) ) ),
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/regenerate-thumbnails' ) ) {
        att_mcp_register( 'att/regenerate-thumbnails', array_merge( $base, array(
            'label'               => 'Regenerate Thumbnails',
            'description'         => 'Creates missing image sizes for Media Library images (after a theme change, or when pages serve full-size images because the right size does not exist), so WordPress can serve smaller files through srcset. Pass "ids", or omit them to find images with missing sizes automatically. mode "missing" (default) only adds missing sizes; "all" regenerates every size. Processes up to 25 images per call.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Attachment IDs. Default: find images with missing sizes.' ),
                    'mode'  => array( 'type' => 'string', 'enum' => array( 'missing', 'all' ), 'description' => 'Default missing.' ),
                    'limit' => array( 'type' => 'integer', 'description' => 'Images per call (1–25). Default 10.' ),
                ),
            ),
            'permission_callback' => function () { return current_user_can( 'upload_files' ); },
            'execute_callback'    => 'att_mcp_execute_regenerate_thumbnails',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => false, 'idempotent' => true ) ) ),
        ) ) );
    }
}

/* ----- Shared --------------------------------------------------------------- */

/** Resolve the public URL to test from { id | url }, defaulting to the home page. */
function att_mcp_perf_target_url( $input ) {
    if ( ! empty( $input['id'] ) ) {
        $post = get_post( (int) $input['id'] );
        if ( ! $post ) {
            return new WP_Error( 'att_mcp_no_post', 'No post/page found for that id.' );
        }
        if ( 'publish' !== $post->post_status || ! is_post_publicly_viewable( $post ) || post_password_required( $post ) ) {
            return new WP_Error( 'att_mcp_not_public', 'Performance is measured on published, public pages (what visitors get). Publish it first, or test another page.' );
        }
        return get_permalink( $post );
    }
    if ( ! empty( $input['url'] ) ) {
        $url = esc_url_raw( (string) $input['url'] );
        if ( ! att_mcp_is_own_url( $url ) ) {
            return new WP_Error( 'att_mcp_offsite', 'The URL must be on this site.' );
        }
        return $url;
    }
    return home_url( '/' );
}

/** Autoload values core treats as "load on every request". */
function att_mcp_autoload_values() {
    return function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes', 'on', 'auto-on', 'auto' );
}

/* ----- Audit ----------------------------------------------------------------- */

function att_mcp_perf_server_checks() {
    $webp = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
    $avif = wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) );
    return array(
        'php_version'       => PHP_VERSION,
        'wp_version'        => get_bloginfo( 'version' ),
        'environment'       => wp_get_environment_type(),
        'opcache'           => extension_loaded( 'Zend OPcache' ) && (bool) ini_get( 'opcache.enable' ),
        'object_cache'      => (bool) wp_using_ext_object_cache(),
        'memory_limit'      => (string) ini_get( 'memory_limit' ),
        'wp_memory_limit'   => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : null,
        'wp_debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
        'savequeries'       => defined( 'SAVEQUERIES' ) && SAVEQUERIES,
        'script_debug'      => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
        'wp_cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
        'https'             => wp_is_using_https(),
        'image_editor_webp' => (bool) $webp,
        'image_editor_avif' => (bool) $avif,
        'active_plugins'    => count( (array) get_option( 'active_plugins', array() ) ),
    );
}

function att_mcp_perf_database_checks() {
    global $wpdb;
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live counts for an admin-only diagnostic
    $autoload = att_mcp_autoload_values();
    $in       = implode( ', ', array_fill( 0, count( $autoload ), '%s' ) );

    $out = array(
        'revisions'          => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_type = %s', $wpdb->posts, 'revision' ) ),
        'auto_drafts'        => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_status = %s', $wpdb->posts, 'auto-draft' ) ),
        'trashed_posts'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_status = %s', $wpdb->posts, 'trash' ) ),
        'spam_comments'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE comment_approved = %s', $wpdb->comments, 'spam' ) ),
        'trashed_comments'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE comment_approved = %s', $wpdb->comments, 'trash' ) ),
        'expired_transients' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_value < %d', $wpdb->options, $wpdb->esc_like( '_transient_timeout_' ) . '%', $wpdb->esc_like( '_site_transient_timeout_' ) . '%', time() ) ),
        'orphaned_postmeta'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i m LEFT JOIN %i p ON p.ID = m.post_id WHERE p.ID IS NULL', $wpdb->postmeta, $wpdb->posts ) ),
    );

    $row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, SUM(LENGTH(option_value)) AS bytes FROM %i WHERE autoload IN ($in)", array_merge( array( $wpdb->options ), $autoload ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is placeholders only
    $top = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, LENGTH(option_value) AS bytes FROM %i WHERE autoload IN ($in) ORDER BY bytes DESC LIMIT 10", array_merge( array( $wpdb->options ), $autoload ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is placeholders only
    // phpcs:enable

    $largest = array();
    foreach ( (array) $top as $option ) {
        $largest[] = array(
            'name'      => $option->option_name,
            'kb'        => round( $option->bytes / 1024, 1 ),
            'protected' => att_mcp_is_protected_option( $option->option_name ),
        );
    }
    $out['autoload'] = array(
        'options' => $row ? (int) $row->n : 0,
        'kb'      => $row ? round( $row->bytes / 1024, 1 ) : 0,
        'largest' => $largest,
    );
    return $out;
}

function att_mcp_perf_cron_checks() {
    $late  = 0;
    $total = 0;
    $crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
    foreach ( (array) $crons as $timestamp => $hooks ) {
        foreach ( (array) $hooks as $events ) {
            $total += count( (array) $events );
            if ( $timestamp < time() - 10 * MINUTE_IN_SECONDS ) {
                $late += count( (array) $events );
            }
        }
    }
    return array( 'events' => $total, 'overdue' => $late );
}

/** Analyse a fetched page for front-end performance. */
function att_mcp_perf_page_checks( $url ) {
    $first = att_mcp_fetch_own_page( $url, 3145728 );
    if ( is_wp_error( $first ) ) {
        return $first;
    }
    $repeat   = att_mcp_fetch_own_page( $url, 3145728 );
    $page     = is_wp_error( $repeat ) ? $first : $repeat;
    $uncached = att_mcp_fetch_own_page( add_query_arg( 'att_mcp_nocache', wp_rand( 100000, 999999 ), $url ), 3145728 );

    $scan      = att_mcp_scan_html( $page['body'], $page['url'] );
    $site_host = att_mcp_url_host( home_url() );
    $cache     = att_mcp_page_cache_signals( $page['headers'], $scan['comments'] );

    // Scripts and styles.
    $third = array();
    $count_host = function ( $host ) use ( &$third, $site_host ) {
        if ( '' !== $host && $host !== $site_host ) {
            $third[ $host ] = isset( $third[ $host ] ) ? $third[ $host ] + 1 : 1;
        }
    };
    $blocking_js = array();
    $jquery      = false;
    $emoji       = false !== strpos( $page['body'], 'wp-emoji-release' ) || false !== strpos( $page['body'], '_wpemojiSettings' );
    foreach ( $scan['scripts'] as $s ) {
        $count_host( $s['host'] );
        if ( $s['blocking'] ) {
            $blocking_js[] = $s['src'];
        }
        if ( false !== stripos( $s['src'], 'jquery' ) ) {
            $jquery = true;
        }
    }
    $blocking_css = array();
    $google_fonts = false;
    foreach ( $scan['stylesheets'] as $s ) {
        $count_host( $s['host'] );
        if ( $s['blocking'] ) {
            $blocking_css[] = $s['href'];
        }
        if ( false !== strpos( $s['host'], 'fonts.googleapis.com' ) ) {
            $google_fonts = true;
        }
    }
    foreach ( $scan['iframes'] as $f ) {
        $count_host( $f['host'] );
    }

    // Images.
    $images = array( 'total' => count( $scan['images'] ), 'missing_dimensions' => 0, 'missing_alt' => 0, 'lazy' => 0, 'eager_after_first_three' => 0, 'first_image_lazy' => false, 'legacy_format' => 0, 'modern_format' => 0, 'local_kb' => 0, 'largest' => array() );
    $sizes  = array();
    foreach ( $scan['images'] as $i => $img ) {
        $count_host( att_mcp_url_host( $img['src'] ) );
        if ( ! $img['width'] || ! $img['height'] ) {
            $images['missing_dimensions']++;
        }
        if ( null === $img['alt'] ) {
            $images['missing_alt']++;
        }
        $lazy = 'lazy' === $img['loading'];
        if ( $lazy ) {
            $images['lazy']++;
        } elseif ( $i >= 3 ) {
            $images['eager_after_first_three']++;
        }
        if ( 0 === $i && $lazy ) {
            $images['first_image_lazy'] = true;
        }
        $ext = strtolower( pathinfo( (string) wp_parse_url( $img['src'], PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif' ), true ) ) {
            $images['legacy_format']++;
        } elseif ( in_array( $ext, array( 'webp', 'avif' ), true ) ) {
            $images['modern_format']++;
        }
        $file = att_mcp_local_file_for_url( $img['src'] );
        if ( $file && ! isset( $sizes[ $img['src'] ] ) ) {
            $sizes[ $img['src'] ] = (int) filesize( $file );
        }
    }
    arsort( $sizes );
    $images['local_kb'] = round( array_sum( $sizes ) / 1024 );
    foreach ( array_slice( $sizes, 0, 5, true ) as $src => $bytes ) {
        $images['largest'][] = array( 'src' => $src, 'kb' => round( $bytes / 1024 ) );
    }

    $iframes_eager = 0;
    foreach ( $scan['iframes'] as $f ) {
        if ( 'lazy' !== $f['loading'] ) {
            $iframes_eager++;
        }
    }
    arsort( $third );
    $encoding = strtolower( att_mcp_header( $page['headers'], 'content-encoding' ) );

    return array(
        'url'           => $page['url'],
        'status'        => $page['status'],
        'response_ms'   => array(
            'first'    => $first['ms'],
            'repeat'   => is_wp_error( $repeat ) ? null : $repeat['ms'],
            'uncached' => is_wp_error( $uncached ) ? null : $uncached['ms'],
        ),
        'html_kb'       => round( strlen( $page['body'] ) / 1024, 1 ),
        'compression'   => '' !== $encoding ? $encoding : 'none',
        'cache_control' => att_mcp_header( $page['headers'], 'cache-control' ),
        'page_cache'    => $cache,
        'scripts'       => array(
            'total'            => count( $scan['scripts'] ),
            'render_blocking'  => array_slice( $blocking_js, 0, 15 ),
            'inline'           => $scan['inline_scripts'],
            'inline_kb'        => round( $scan['inline_script_bytes'] / 1024, 1 ),
            'jquery'           => $jquery,
            'emoji_script'     => $emoji,
        ),
        'styles'        => array(
            'total'           => count( $scan['stylesheets'] ),
            'render_blocking' => array_slice( $blocking_css, 0, 15 ),
            'inline_kb'       => round( $scan['inline_style_bytes'] / 1024, 1 ),
            'google_fonts'    => $google_fonts,
        ),
        'images'        => $images,
        'iframes'       => array( 'total' => count( $scan['iframes'] ), 'not_lazy' => $iframes_eager ),
        'preloads'      => count( $scan['preloads'] ),
        'preconnects'   => array_values( array_unique( $scan['preconnects'] ) ),
        'third_party'   => array_slice( $third, 0, 15, true ),
        'dom_elements'  => $scan['elements'],
    );
}

/** Turn the raw checks into prioritised, actionable recommendations. */
function att_mcp_perf_recommendations( $server, $db, $cron, $plugins, $page ) {
    $rec = array();
    $add = function ( $priority, $area, $issue, $fix, $ability = null ) use ( &$rec ) {
        $rec[] = array_filter( array( 'priority' => $priority, 'area' => $area, 'issue' => $issue, 'fix' => $fix, 'ability' => $ability ) );
    };

    $page_caches  = wp_list_filter( $plugins, array( 'page_cache' => true ) );
    $configurable = wp_list_filter( $plugins, array( 'configurable' => true ) );
    $cfg_ability  = $configurable ? 'att/get-cache-config, then att/update-cache-config' : null;

    // Page cache.
    if ( $page ) {
        $repeat = $page['response_ms']['repeat'];
        if ( ! $page_caches && empty( $page['page_cache']['signals'] ) ) {
            $add( 'high', 'caching', 'No page cache: every visit is built by PHP and the database.', 'Install a page cache — LiteSpeed Cache on LiteSpeed servers, otherwise Super Page Cache — and configure it.', 'att/manage-plugin, then att/update-cache-config' );
        } elseif ( false === $page['page_cache']['hit'] || ( $page_caches && empty( $page['page_cache']['signals'] ) && null !== $repeat && $repeat > 300 ) ) {
            $add( 'high', 'caching', 'A cache plugin is active but this page was not served from the cache on a repeat visit.', 'Check that page caching is on and this URL is not excluded (att/get-cache-config lists the settings and problems).', $cfg_ability );
        }
        if ( count( $page_caches ) > 1 ) {
            $add( 'high', 'caching', 'Several page-cache plugins are active (' . implode( ', ', wp_list_pluck( $page_caches, 'name' ) ) . ').', 'Keep one page cache; conflicting caches serve stale or broken pages.', 'att/manage-plugin' );
        }
        if ( null !== $repeat && $repeat > 800 ) {
            $add( 'high', 'server', sprintf( 'Slow response for visitors: %d ms (repeat visit).', $repeat ), 'Get the page cache working; if it is, the host may be overloaded.', $cfg_ability );
        } elseif ( null !== $repeat && $repeat > 400 ) {
            $add( 'medium', 'server', sprintf( 'Response time for visitors is %d ms (repeat visit); under 200 ms is achievable with a page cache.', $repeat ), 'Check page-cache hits and server location.', $cfg_ability );
        }
        $uncached = $page['response_ms']['uncached'];
        if ( null !== $uncached && $uncached > 1500 ) {
            $add( 'medium', 'server', sprintf( 'Generating a page without cache takes %d ms: slow plugins, a slow database or no object cache.', $uncached ), 'Reduce heavy plugins, add a persistent object cache (Redis/Memcached, ask the host), and fix the database items below.' );
        }
        if ( 'none' === $page['compression'] ) {
            $add( 'medium', 'server', 'HTML is sent without gzip/brotli compression.', 'Enable compression on the server or in the cache plugin.' );
        }
        if ( count( $page['scripts']['render_blocking'] ) > 2 ) {
            $add( 'medium', 'javascript', sprintf( '%d scripts block rendering in <head>.', count( $page['scripts']['render_blocking'] ) ), 'Defer JavaScript (LiteSpeed "optm-js_defer": 1) or load scripts in the footer.', $cfg_ability );
        }
        if ( count( $page['styles']['render_blocking'] ) > 6 ) {
            $add( 'medium', 'css', sprintf( '%d stylesheets block rendering.', count( $page['styles']['render_blocking'] ) ), 'Minify CSS and load non-critical CSS asynchronously (LiteSpeed critical CSS), remove unused plugin CSS.', $cfg_ability );
        }
        $img = $page['images'];
        if ( $img['missing_dimensions'] > 0 ) {
            $add( 'medium', 'images', sprintf( '%d images have no width/height, causing layout shift (CLS).', $img['missing_dimensions'] ), 'Add width and height to the <img> tags in the content, or turn on LiteSpeed "media-add_missing_sizes".', $cfg_ability ? $cfg_ability : 'att/update-content' );
        }
        if ( $img['first_image_lazy'] ) {
            $add( 'medium', 'images', 'The first image on the page is lazy-loaded, which delays the Largest Contentful Paint.', 'Load the hero/above-the-fold image eagerly (remove loading="lazy", add fetchpriority="high"), or exclude it from the cache plugin\'s lazy-loading.' );
        }
        if ( $img['eager_after_first_three'] > 3 ) {
            $add( 'medium', 'images', sprintf( '%d images further down the page are not lazy-loaded.', $img['eager_after_first_three'] ), 'Turn on lazy-loading for images (cache plugin setting, or loading="lazy").', $cfg_ability );
        }
        foreach ( $img['largest'] as $big ) {
            if ( $big['kb'] > 300 ) {
                $add( 'medium', 'images', sprintf( 'Heavy image: %s is %d KB.', $big['src'], $big['kb'] ), 'Resize/compress it or serve WebP/AVIF; if a smaller size is missing, regenerate thumbnails so srcset can use it.', 'att/regenerate-thumbnails' );
            }
        }
        if ( $img['legacy_format'] >= 3 && 0 === $img['modern_format'] ) {
            $add( 'low', 'images', 'Images are served as JPEG/PNG only.', 'Serve WebP or AVIF (LiteSpeed image optimization with QUIC.cloud, or an image-optimization plugin).' . ( $server['image_editor_webp'] ? ' This server can also create WebP when images are uploaded.' : '' ), $cfg_ability );
        }
        if ( $page['iframes']['not_lazy'] > 0 ) {
            $add( 'low', 'embeds', sprintf( '%d iframes (videos/maps) load immediately.', $page['iframes']['not_lazy'] ), 'Lazy-load iframes (LiteSpeed "media-iframe_lazy", Super Page Cache "cf_lazy_load_video_iframe").', $cfg_ability );
        }
        if ( $page['styles']['google_fonts'] ) {
            $add( 'low', 'fonts', 'Fonts load from Google Fonts (extra DNS lookup and connection).', 'Host fonts locally (Super Page Cache "local_google_fonts") or load them asynchronously (LiteSpeed "optm-ggfonts_async").', $cfg_ability );
        }
        if ( $page['scripts']['emoji_script'] ) {
            $add( 'low', 'javascript', 'The WordPress emoji script loads on every page.', 'Remove it (LiteSpeed "optm-emoji_rm").', $cfg_ability );
        }
        if ( count( $page['third_party'] ) > 6 ) {
            $add( 'medium', 'third-party', sprintf( 'The page loads resources from %d other hosts.', count( $page['third_party'] ) ), 'Remove unused trackers and widgets; preconnect to the ones that stay.' );
        }
        if ( $page['html_kb'] > 200 ) {
            $add( 'low', 'html', sprintf( 'The HTML is %s KB.', $page['html_kb'] ), 'Large inline CSS/JS or page-builder markup: minify HTML and trim unused sections.', $cfg_ability );
        }
        if ( $page['dom_elements'] > 1500 ) {
            $add( 'medium', 'html', sprintf( 'The page has %d HTML elements (Lighthouse warns above 1,400).', $page['dom_elements'] ), 'Simplify deeply nested page-builder sections.' );
        }
    }

    // Server.
    if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
        $add( 'medium', 'server', 'PHP ' . PHP_VERSION . ' is old and slower than PHP 8.2+.', 'Switch to PHP 8.2 or newer in the hosting control panel (test the site afterwards).' );
    }
    if ( ! $server['opcache'] ) {
        $add( 'high', 'server', 'PHP OPcache is off, so PHP recompiles WordPress on every request.', 'Ask the host to enable OPcache.' );
    }
    if ( $server['wp_debug'] && 'production' === $server['environment'] ) {
        $add( 'medium', 'server', 'WP_DEBUG is on in production.', 'Set WP_DEBUG to false in wp-config.php.' );
    }
    if ( $server['savequeries'] ) {
        $add( 'high', 'server', 'SAVEQUERIES is on: every query is logged in memory.', 'Remove SAVEQUERIES from wp-config.php.' );
    }
    if ( $server['script_debug'] ) {
        $add( 'medium', 'server', 'SCRIPT_DEBUG is on: unminified core scripts are served.', 'Set SCRIPT_DEBUG to false in wp-config.php.' );
    }
    if ( ! $server['object_cache'] && $server['active_plugins'] > 25 ) {
        $add( 'low', 'server', 'No persistent object cache on a site with many plugins.', 'Ask the host for Redis or Memcached and enable it with the host\'s or the cache plugin\'s object-cache option.' );
    }

    // Database.
    $autoload_kb = $db['autoload']['kb'];
    if ( $autoload_kb > 800 ) {
        $add( 'high', 'database', sprintf( 'Autoloaded options total %s KB, loaded on every request (WordPress warns above 800 KB).', $autoload_kb ), 'Stop autoloading the largest options that are not needed on every page (see database.autoload.largest), or remove leftovers of deleted plugins.', 'att/set-option-autoload' );
    } elseif ( $autoload_kb > 400 ) {
        $add( 'medium', 'database', sprintf( 'Autoloaded options total %s KB.', $autoload_kb ), 'Review database.autoload.largest.', 'att/set-option-autoload' );
    }
    $bloat = array();
    foreach ( array( 'revisions' => 1000, 'auto_drafts' => 50, 'spam_comments' => 100, 'trashed_comments' => 100, 'expired_transients' => 200, 'orphaned_postmeta' => 1000 ) as $key => $limit ) {
        if ( $db[ $key ] > $limit ) {
            $bloat[] = $key . ' (' . $db[ $key ] . ')';
        }
    }
    if ( $bloat ) {
        $add( 'low', 'database', 'Database bloat: ' . implode( ', ', $bloat ) . '.', 'Clean it up (dry run first).', 'att/optimize-database' );
    }

    // Cron.
    if ( $cron['overdue'] > 10 ) {
        $add( 'medium', 'cron', sprintf( '%d scheduled tasks are overdue: WP-Cron is not running reliably.', $cron['overdue'] ), $server['wp_cron_disabled'] ? 'DISABLE_WP_CRON is set: make sure a real server cron calls wp-cron.php every 5 minutes.' : 'Low traffic or a page cache can stall WP-Cron: set DISABLE_WP_CRON and add a server cron job for wp-cron.php.' );
    }

    $order = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
    usort( $rec, function ( $a, $b ) use ( $order ) {
        return $order[ $a['priority'] ] - $order[ $b['priority'] ];
    } );
    return $rec;
}

function att_mcp_execute_audit_performance( $input ) {
    $server  = att_mcp_perf_server_checks();
    $db      = att_mcp_perf_database_checks();
    $cron    = att_mcp_perf_cron_checks();
    $plugins = att_mcp_detect_cache_plugins();

    $page = null;
    $page_error = null;
    if ( ! isset( $input['fetch'] ) || ! empty( $input['fetch'] ) ) {
        $url = att_mcp_perf_target_url( $input );
        if ( is_wp_error( $url ) ) {
            return $url;
        }
        $page = att_mcp_perf_page_checks( $url );
        if ( is_wp_error( $page ) ) {
            $page_error = $page->get_error_message();
            $page       = null;
        }
    }

    $rec = att_mcp_perf_recommendations( $server, $db, $cron, $plugins, $page );
    return array_filter( array(
        'recommendations' => $rec,
        'page'            => $page,
        'page_error'      => $page_error,
        'server'          => $server,
        'database'        => $db,
        'cron'            => $cron,
        'cache_plugins'   => $plugins,
        'note'            => 'Response times are measured from this server to itself (no network latency). For field data and Lighthouse scores run att/pagespeed-insights.',
    ), function ( $v ) {
        return null !== $v;
    } );
}

/* ----- PageSpeed Insights ------------------------------------------------------ */

function att_mcp_psi_api_key() {
    $key = defined( 'ATT_MCP_PSI_API_KEY' ) ? (string) ATT_MCP_PSI_API_KEY : '';
    return (string) apply_filters( 'att_mcp_psi_api_key', $key );
}

function att_mcp_execute_pagespeed_insights( $input ) {
    $url = att_mcp_perf_target_url( $input );
    if ( is_wp_error( $url ) ) {
        return $url;
    }
    $strategy = ( isset( $input['strategy'] ) && 'desktop' === $input['strategy'] ) ? 'desktop' : 'mobile';
    $map      = array( 'performance' => 'PERFORMANCE', 'accessibility' => 'ACCESSIBILITY', 'best-practices' => 'BEST_PRACTICES', 'seo' => 'SEO' );
    $cats     = array();
    foreach ( ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) ? $input['categories'] : array( 'performance', 'seo' ) as $c ) {
        if ( isset( $map[ $c ] ) ) {
            $cats[] = $map[ $c ];
        }
    }
    if ( ! $cats ) {
        $cats = array( 'PERFORMANCE' );
    }

    $query = 'url=' . rawurlencode( $url ) . '&strategy=' . $strategy . '&category=' . implode( '&category=', array_unique( $cats ) );
    $key   = att_mcp_psi_api_key();
    if ( '' !== $key ) {
        $query .= '&key=' . rawurlencode( $key );
    }
    $response = wp_safe_remote_get( 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . $query, array( 'timeout' => 90 ) );
    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'att_mcp_psi_failed', 'PageSpeed Insights did not answer: ' . $response->get_error_message() );
    }
    $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( 200 !== $code || ! is_array( $data ) || empty( $data['lighthouseResult'] ) ) {
        $message = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'HTTP ' . $code;
        if ( 429 === $code ) {
            $message .= ' — the shared quota is used up; add a free API key as ATT_MCP_PSI_API_KEY in wp-config.php.';
        }
        return new WP_Error( 'att_mcp_psi_failed', 'PageSpeed Insights failed: ' . $message . ' (The site must be reachable from the internet.)' );
    }
    return att_mcp_psi_summarize( $data, $url, $strategy );
}

/** Condense a PageSpeed Insights v5 response to what an agent needs to act on. */
function att_mcp_psi_summarize( $data, $url, $strategy ) {
    $lh     = $data['lighthouseResult'];
    $audits = isset( $lh['audits'] ) && is_array( $lh['audits'] ) ? $lh['audits'] : array();

    $scores = array();
    foreach ( isset( $lh['categories'] ) ? (array) $lh['categories'] : array() as $id => $cat ) {
        $scores[ $id ] = isset( $cat['score'] ) ? (int) round( $cat['score'] * 100 ) : null;
    }

    $lab = array();
    foreach ( array( 'largest-contentful-paint' => 'lcp', 'first-contentful-paint' => 'fcp', 'total-blocking-time' => 'tbt', 'cumulative-layout-shift' => 'cls', 'speed-index' => 'speed_index', 'server-response-time' => 'server_response' ) as $id => $label ) {
        if ( isset( $audits[ $id ] ) ) {
            $lab[ $label ] = array(
                'value' => isset( $audits[ $id ]['displayValue'] ) ? $audits[ $id ]['displayValue'] : null,
                'score' => isset( $audits[ $id ]['score'] ) ? $audits[ $id ]['score'] : null,
            );
        }
    }

    $field = function ( $experience ) {
        if ( empty( $experience['metrics'] ) ) {
            return null;
        }
        $names = array( 'LARGEST_CONTENTFUL_PAINT_MS' => 'lcp_ms', 'INTERACTION_TO_NEXT_PAINT' => 'inp_ms', 'CUMULATIVE_LAYOUT_SHIFT_SCORE' => 'cls', 'FIRST_CONTENTFUL_PAINT_MS' => 'fcp_ms', 'EXPERIMENTAL_TIME_TO_FIRST_BYTE' => 'ttfb_ms' );
        $out   = array( 'overall' => isset( $experience['overall_category'] ) ? $experience['overall_category'] : null );
        foreach ( $names as $metric => $label ) {
            if ( isset( $experience['metrics'][ $metric ]['percentile'] ) ) {
                $p = $experience['metrics'][ $metric ]['percentile'];
                $out[ $label ] = array(
                    'p75'      => 'cls' === $label ? $p / 100 : $p,
                    'category' => isset( $experience['metrics'][ $metric ]['category'] ) ? $experience['metrics'][ $metric ]['category'] : null,
                );
            }
        }
        return $out;
    };

    // Opportunities and insights: failing audits in the performance category, largest savings first.
    $perf_ids = array();
    if ( isset( $lh['categories']['performance']['auditRefs'] ) ) {
        foreach ( $lh['categories']['performance']['auditRefs'] as $ref ) {
            if ( empty( $ref['group'] ) || 'metrics' !== $ref['group'] ) {
                $perf_ids[] = $ref['id'];
            }
        }
    }
    $opps = array();
    foreach ( $perf_ids as $id ) {
        if ( empty( $audits[ $id ] ) ) {
            continue;
        }
        $a    = $audits[ $id ];
        $mode = isset( $a['scoreDisplayMode'] ) ? $a['scoreDisplayMode'] : '';
        if ( ! isset( $a['score'] ) || null === $a['score'] || $a['score'] >= 0.9 || in_array( $mode, array( 'notApplicable', 'manual', 'informative', 'error' ), true ) ) {
            continue;
        }
        $savings_ms    = isset( $a['details']['overallSavingsMs'] ) ? (int) $a['details']['overallSavingsMs'] : 0;
        $savings_bytes = isset( $a['details']['overallSavingsBytes'] ) ? (int) $a['details']['overallSavingsBytes'] : 0;
        if ( ! empty( $a['metricSavings'] ) && is_array( $a['metricSavings'] ) ) {
            foreach ( array( 'LCP', 'FCP', 'TBT' ) as $m ) {
                $savings_ms = max( $savings_ms, isset( $a['metricSavings'][ $m ] ) ? (int) $a['metricSavings'][ $m ] : 0 );
            }
        }
        $items = array();
        if ( ! empty( $a['details']['items'] ) && is_array( $a['details']['items'] ) ) {
            foreach ( array_slice( $a['details']['items'], 0, 5 ) as $item ) {
                $entry = array();
                foreach ( array( 'url', 'wastedBytes', 'wastedMs', 'totalBytes', 'label' ) as $f ) {
                    if ( isset( $item[ $f ] ) && is_scalar( $item[ $f ] ) ) {
                        $entry[ $f ] = is_float( $item[ $f ] ) ? round( $item[ $f ] ) : $item[ $f ];
                    }
                }
                if ( ! $entry && isset( $item['node']['snippet'] ) ) {
                    $entry['element'] = substr( (string) $item['node']['snippet'], 0, 200 );
                }
                if ( $entry ) {
                    $items[] = $entry;
                }
            }
        }
        $opp = array(
            'id'    => $id,
            'title' => isset( $a['title'] ) ? $a['title'] : $id,
            'score' => $a['score'],
        );
        if ( isset( $a['displayValue'] ) ) {
            $opp['value'] = $a['displayValue'];
        }
        if ( $savings_ms ) {
            $opp['savings_ms'] = $savings_ms;
        }
        if ( $savings_bytes ) {
            $opp['savings_kb'] = (int) round( $savings_bytes / 1024 );
        }
        if ( $items ) {
            $opp['items'] = $items;
        }
        $opps[] = $opp;
    }
    usort( $opps, function ( $a, $b ) {
        $sa = isset( $a['savings_ms'] ) ? $a['savings_ms'] : 0;
        $sb = isset( $b['savings_ms'] ) ? $b['savings_ms'] : 0;
        if ( $sb !== $sa ) {
            return $sb - $sa;
        }
        return (float) $a['score'] === (float) $b['score'] ? 0 : ( $a['score'] < $b['score'] ? -1 : 1 );
    } );

    // Failing audits in the other categories (accessibility, SEO, best practices).
    $failing = array();
    foreach ( isset( $lh['categories'] ) ? (array) $lh['categories'] : array() as $cat_id => $cat ) {
        if ( 'performance' === $cat_id || empty( $cat['auditRefs'] ) ) {
            continue;
        }
        foreach ( $cat['auditRefs'] as $ref ) {
            $a = isset( $audits[ $ref['id'] ] ) ? $audits[ $ref['id'] ] : null;
            if ( $a && isset( $a['score'] ) && null !== $a['score'] && $a['score'] < 1 && 'binary' === ( isset( $a['scoreDisplayMode'] ) ? $a['scoreDisplayMode'] : '' ) ) {
                $failing[ $cat_id ][] = array( 'id' => $ref['id'], 'title' => isset( $a['title'] ) ? $a['title'] : $ref['id'] );
            }
        }
    }

    // The LCP element (audit id differs between Lighthouse versions).
    $lcp_element = null;
    foreach ( array( 'largest-contentful-paint-element', 'lcp-breakdown-insight', 'lcp-discovery-insight' ) as $id ) {
        if ( null === $lcp_element && ! empty( $audits[ $id ]['details'] ) ) {
            $lcp_element = att_mcp_psi_find_snippet( $audits[ $id ]['details'] );
        }
    }

    return array_filter( array(
        'url'             => $url,
        'strategy'        => $strategy,
        'scores'          => $scores,
        'field_data'      => $field( isset( $data['loadingExperience'] ) ? $data['loadingExperience'] : array() ),
        'field_data_site' => $field( isset( $data['originLoadingExperience'] ) ? $data['originLoadingExperience'] : array() ),
        'lab'             => $lab,
        'lcp_element'     => $lcp_element,
        'opportunities'   => array_slice( $opps, 0, 10 ),
        'failing_audits'  => $failing,
        'lighthouse'      => isset( $lh['lighthouseVersion'] ) ? $lh['lighthouseVersion'] : null,
        'note'            => 'field_data = real Chrome users over the last 28 days (null when Google has too little traffic data); lab = one simulated load. Google ranks on field data.',
    ), function ( $v ) {
        return null !== $v && array() !== $v;
    } );
}

function att_mcp_psi_find_snippet( $node, $depth = 0 ) {
    if ( ! is_array( $node ) || $depth > 6 ) {
        return null;
    }
    if ( isset( $node['snippet'] ) && is_string( $node['snippet'] ) ) {
        return array_filter( array( 'snippet' => substr( $node['snippet'], 0, 300 ), 'selector' => isset( $node['selector'] ) ? (string) $node['selector'] : null ) );
    }
    foreach ( $node as $child ) {
        $found = att_mcp_psi_find_snippet( $child, $depth + 1 );
        if ( $found ) {
            return $found;
        }
    }
    return null;
}

/* ----- Database ------------------------------------------------------------------ */

function att_mcp_execute_optimize_database( $input ) {
    global $wpdb;
    $all     = array( 'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments', 'expired_transients', 'orphaned_meta' );
    $default = array_values( array_diff( $all, array( 'trashed_posts' ) ) );
    $tasks   = ( isset( $input['tasks'] ) && is_array( $input['tasks'] ) ) ? array_values( array_intersect( $all, array_map( 'strval', $input['tasks'] ) ) ) : $default;
    if ( ! $tasks ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pick at least one task: ' . implode( ', ', $all ) . '.' );
    }
    $dry   = ! isset( $input['dry_run'] ) || ! empty( $input['dry_run'] );
    $keep  = att_mcp_int_arg( $input, 'keep_revisions', 5, 0, 100 );
    $batch = 500;
    $out   = array();

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- finds rows for WordPress's own delete functions
    foreach ( $tasks as $task ) {
        $ids = array();
        switch ( $task ) {
            case 'revisions':
                $parents = $wpdb->get_results( $wpdb->prepare( 'SELECT post_parent, COUNT(*) AS n FROM %i WHERE post_type = %s GROUP BY post_parent HAVING COUNT(*) > %d', $wpdb->posts, 'revision', $keep ) );
                $found   = 0;
                foreach ( (array) $parents as $row ) {
                    $found += (int) $row->n - $keep;
                    if ( count( $ids ) < $batch ) {
                        $revs = $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i WHERE post_type = %s AND post_parent = %d ORDER BY post_date DESC, ID DESC LIMIT %d, 100000', $wpdb->posts, 'revision', (int) $row->post_parent, $keep ) );
                        $ids  = array_merge( $ids, array_slice( $revs, 0, $batch - count( $ids ) ) );
                    }
                }
                $delete = function ( $id ) { return (bool) wp_delete_post_revision( (int) $id ); };
                break;

            case 'auto_drafts':
                $ids    = $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i WHERE post_status = %s AND post_date < %s LIMIT %d', $wpdb->posts, 'auto-draft', gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ), $batch ) );
                $found  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_status = %s AND post_date < %s', $wpdb->posts, 'auto-draft', gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
                $delete = function ( $id ) { return (bool) wp_delete_post( (int) $id, true ); };
                break;

            case 'trashed_posts':
                $ids    = $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i WHERE post_status = %s LIMIT %d', $wpdb->posts, 'trash', $batch ) );
                $found  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_status = %s', $wpdb->posts, 'trash' ) );
                $delete = function ( $id ) { return (bool) wp_delete_post( (int) $id, true ); };
                break;

            case 'spam_comments':
            case 'trashed_comments':
                $status = 'spam_comments' === $task ? 'spam' : 'trash';
                $ids    = $wpdb->get_col( $wpdb->prepare( 'SELECT comment_ID FROM %i WHERE comment_approved = %s LIMIT %d', $wpdb->comments, $status, $batch ) );
                $found  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE comment_approved = %s', $wpdb->comments, $status ) );
                $delete = function ( $id ) { return (bool) wp_delete_comment( (int) $id, true ); };
                break;

            case 'expired_transients':
                $found   = 0;
                $deleted = 0;
                // Transients and (single-site) site transients whose timeout has passed;
                // deleted with core's API so the value, its timeout and the caches all go.
                foreach ( array( '_transient_timeout_' => 'delete_transient', '_site_transient_timeout_' => 'delete_site_transient' ) as $prefix => $delete_fn ) {
                    $like   = $wpdb->esc_like( $prefix ) . '%';
                    $found += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s AND option_value < %d', $wpdb->options, $like, time() ) );
                    if ( ! $dry ) {
                        $names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s AND option_value < %d LIMIT %d', $wpdb->options, $like, time(), $batch ) );
                        foreach ( $names as $name ) {
                            $deleted += call_user_func( $delete_fn, substr( $name, strlen( $prefix ) ) ) ? 1 : 0;
                        }
                    }
                }
                $out[ $task ] = array( 'found' => $found, 'deleted' => $deleted, 'remaining' => max( 0, $found - $deleted ) );
                continue 2;

            case 'orphaned_meta':
                $found   = 0;
                $deleted = 0;
                foreach ( array( 'post' => array( $wpdb->postmeta, $wpdb->posts, 'post_id', 'ID' ), 'comment' => array( $wpdb->commentmeta, $wpdb->comments, 'comment_id', 'comment_ID' ), 'term' => array( $wpdb->termmeta, $wpdb->terms, 'term_id', 'term_id' ) ) as $type => $t ) {
                    $found += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i m LEFT JOIN %i o ON o.%i = m.%i WHERE o.%i IS NULL', $t[0], $t[1], $t[3], $t[2], $t[3] ) );
                    if ( ! $dry ) {
                        $mids = $wpdb->get_col( $wpdb->prepare( 'SELECT m.meta_id FROM %i m LEFT JOIN %i o ON o.%i = m.%i WHERE o.%i IS NULL LIMIT %d', $t[0], $t[1], $t[3], $t[2], $t[3], $batch ) );
                        foreach ( $mids as $mid ) {
                            $deleted += delete_metadata_by_mid( $type, (int) $mid ) ? 1 : 0;
                        }
                    }
                }
                $out[ $task ] = array( 'found' => $found, 'deleted' => $deleted, 'remaining' => $found - $deleted );
                continue 2;
        }

        $deleted = 0;
        if ( ! $dry ) {
            foreach ( $ids as $id ) {
                $deleted += $delete( $id ) ? 1 : 0;
            }
        }
        $out[ $task ] = array( 'found' => (int) $found, 'deleted' => $deleted, 'remaining' => max( 0, (int) $found - $deleted ) );
    }
    // phpcs:enable

    return array(
        'dry_run' => $dry,
        'tasks'   => $out,
        'note'    => $dry ? 'Nothing was deleted. Run again with dry_run:false to delete (cannot be undone — make sure a backup exists).' : 'Done. Run again if any task still has "remaining" above 0.',
    );
}

/* ----- Autoload ---------------------------------------------------------------- */

function att_mcp_execute_set_option_autoload( $input ) {
    $names = ( isset( $input['options'] ) && is_array( $input['options'] ) ) ? array_slice( array_unique( array_map( 'strval', $input['options'] ) ), 0, 50 ) : array();
    if ( ! $names || ! array_key_exists( 'autoload', (array) $input ) ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pass "options" (a list of option names) and "autoload" (true or false).' );
    }
    $autoload = (bool) $input['autoload'];

    $results = array();
    $apply   = array();
    $items   = array();
    $missing = new stdClass();
    foreach ( $names as $name ) {
        $name = sanitize_text_field( $name );
        if ( att_mcp_is_protected_option( $name ) ) {
            $results[ $name ] = 'refused: protected option';
            continue;
        }
        if ( get_option( $name, $missing ) === $missing ) {
            $results[ $name ] = 'skipped: no such option';
            continue;
        }
        $items[]        = att_mcp_capture( 'option_autoload', $name );
        $apply[ $name ] = $autoload;
    }
    $change_id = 0;
    if ( $apply ) {
        $change_id = att_mcp_record_change( $items, sprintf( 'Autoload %s: %s', $autoload ? 'on' : 'off', implode( ', ', array_keys( $apply ) ) ) );
        foreach ( wp_set_option_autoload_values( $apply ) as $name => $changed ) {
            $results[ $name ] = $changed ? 'updated' : 'unchanged';
        }
    }
    return array(
        'autoload'  => $autoload,
        'results'   => $results,
        'change_id' => $change_id,
    );
}

/* ----- Thumbnails ---------------------------------------------------------------- */

function att_mcp_execute_regenerate_thumbnails( $input ) {
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $mode  = ( isset( $input['mode'] ) && 'all' === $input['mode'] ) ? 'all' : 'missing';
    $limit = att_mcp_int_arg( $input, 'limit', 10, 1, 25 );

    $ids = ( isset( $input['ids'] ) && is_array( $input['ids'] ) ) ? array_slice( array_map( 'intval', $input['ids'] ), 0, $limit ) : array();
    $auto = ! $ids;
    if ( $auto ) {
        // Find images with missing sizes, newest first.
        $scanned = 0;
        $paged   = 1;
        while ( count( $ids ) < $limit && $scanned < 1000 ) {
            $batch = get_posts( array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => 100,
                'paged'          => $paged++,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'DESC',
            ) );
            if ( ! $batch ) {
                break;
            }
            foreach ( $batch as $id ) {
                $scanned++;
                if ( wp_attachment_is_image( $id ) && ( 'all' === $mode || wp_get_missing_image_subsizes( $id ) ) ) {
                    $ids[] = (int) $id;
                    if ( count( $ids ) >= $limit ) {
                        break;
                    }
                }
            }
        }
    }

    $results = array();
    foreach ( $ids as $id ) {
        if ( ! wp_attachment_is_image( $id ) ) {
            $results[] = array( 'id' => $id, 'error' => 'Not an image attachment.' );
            continue;
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            $results[] = array( 'id' => $id, 'error' => 'You are not allowed to edit this image.' );
            continue;
        }
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) {
            $results[] = array( 'id' => $id, 'error' => 'The original file is missing.' );
            continue;
        }
        $missing = array_keys( (array) wp_get_missing_image_subsizes( $id ) );
        if ( 'missing' === $mode ) {
            if ( ! $missing ) {
                $results[] = array( 'id' => $id, 'created' => array(), 'note' => 'No sizes were missing.' );
                continue;
            }
            $meta = wp_update_image_subsizes( $id );
        } else {
            $meta = wp_generate_attachment_metadata( $id, $file );
            if ( ! is_wp_error( $meta ) && $meta ) {
                wp_update_attachment_metadata( $id, $meta );
            }
        }
        if ( is_wp_error( $meta ) ) {
            $results[] = array( 'id' => $id, 'error' => $meta->get_error_message() );
            continue;
        }
        $still     = array_keys( (array) wp_get_missing_image_subsizes( $id ) );
        $results[] = array(
            'id'      => $id,
            'created' => 'all' === $mode ? array_keys( isset( $meta['sizes'] ) ? (array) $meta['sizes'] : array() ) : array_values( array_diff( $missing, $still ) ),
            'missing' => $still,
        );
    }

    return array(
        'mode'      => $mode,
        'processed' => count( $results ),
        'results'   => $results,
        'note'      => $auto && count( $ids ) >= $limit ? 'There may be more images to process: call again.' : 'Purge the page cache so pages use the new sizes.',
    );
}
