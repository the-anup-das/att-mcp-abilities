<?php
/**
 * Cache plugin configuration: read, recommend and change the performance
 * settings of LiteSpeed Cache and Super Page Cache.
 *
 * Changes go through each plugin's own settings API (LiteSpeed:
 * Conf::update_confs() and its presets; Super Page Cache:
 * Settings_Manager::update_settings()), so the plugin's own side effects run:
 * .htaccess rules, the advanced-cache.php drop-in, purges and Cloudflare rules.
 *
 * Only a whitelist of performance settings is exposed. Credentials are never
 * read or written: QUIC.cloud keys, Cloudflare e-mail/API key/API token,
 * purge and preloader secrets, object-cache passwords.
 *
 * Every change is recorded for att/undo-change (history types litespeed_conf
 * and spc_settings).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_cache_config_abilities() {
    $base  = att_mcp_ability_base();
    $admin = function () { return current_user_can( 'manage_options' ); };

    if ( att_mcp_is_enabled( 'att/get-cache-config' ) ) {
        att_mcp_register( 'att/get-cache-config', array_merge( $base, array(
            'label'               => 'Read Cache Configuration',
            'description'         => 'Shows how page caching and front-end optimization are set up: which cache/optimization plugins are active (and conflicts between them), the WP_CACHE drop-in, the persistent object cache, and — for LiteSpeed Cache and Super Page Cache — every performance setting with its current value, what it needs (a LiteSpeed web server, a QUIC.cloud connection), the available LiteSpeed presets, and prioritised recommendations. Credentials are never returned. Change settings with att/update-cache-config.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'plugin' => array( 'type' => 'string', 'enum' => array( 'litespeed', 'super_page_cache' ), 'description' => 'Only this plugin. Default: every supported plugin that is active.' ),
                ),
            ),
            'permission_callback' => $admin,
            'execute_callback'    => 'att_mcp_execute_get_cache_config',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/update-cache-config' ) ) {
        att_mcp_register( 'att/update-cache-config', array_merge( $base, array(
            'label'               => 'Update Cache Configuration',
            'description'         => 'Changes LiteSpeed Cache or Super Page Cache settings through the plugin\'s own settings API (its .htaccess rules, drop-in, purges and Cloudflare rules update as they would from its admin screen). Pass "settings" as { id: value } using the ids from att/get-cache-config, and/or a LiteSpeed "preset" (essentials, basic, advanced, aggressive, extreme — applied first, then "settings"). Optimization settings (combine/defer/delay JS, unused CSS, lazy-loading) can break layouts or scripts: change a few at a time, then check pages with att/render-page or att/audit-performance. Undo with att/undo-change.',
            'input_schema'        => array(
                'type'       => 'object',
                'required'   => array( 'plugin' ),
                'properties' => array(
                    'plugin'   => array( 'type' => 'string', 'enum' => array( 'litespeed', 'super_page_cache' ), 'description' => 'Which plugin to configure.' ),
                    'settings' => array( 'type' => 'object', 'description' => 'Setting id => new value. Booleans as true/false, lists as arrays of strings.' ),
                    'preset'   => array( 'type' => 'string', 'enum' => array( 'essentials', 'basic', 'advanced', 'aggressive', 'extreme' ), 'description' => 'LiteSpeed only: apply one of its built-in presets first.' ),
                    'purge'    => array( 'type' => 'boolean', 'description' => 'Purge all caches afterwards so the new settings apply everywhere. Default true.' ),
                ),
            ),
            'permission_callback' => $admin,
            'execute_callback'    => 'att_mcp_execute_update_cache_config',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => false, 'idempotent' => true ) ) ),
        ) ) );
    }
}

/* ----- Detection ------------------------------------------------------------ */

/**
 * Active cache and front-end optimization plugins.
 * `page_cache` = the plugin can serve cached pages; `configurable` = this
 * plugin's settings can be changed with att/update-cache-config.
 */
function att_mcp_detect_cache_plugins() {
    $version = function ( $constant ) {
        return defined( $constant ) ? (string) constant( $constant ) : null;
    };
    $known = array(
        'litespeed'        => array( 'LiteSpeed Cache', defined( 'LSCWP_V' ), $version( 'LSCWP_V' ), true ),
        'super_page_cache' => array( 'Super Page Cache', defined( 'SWCFPC_VERSION' ), $version( 'SWCFPC_VERSION' ), true ),
        'wp_rocket'        => array( 'WP Rocket', defined( 'WP_ROCKET_VERSION' ), $version( 'WP_ROCKET_VERSION' ), true ),
        'w3_total_cache'   => array( 'W3 Total Cache', defined( 'W3TC_VERSION' ), $version( 'W3TC_VERSION' ), true ),
        'wp_super_cache'   => array( 'WP Super Cache', defined( 'WPCACHEHOME' ), null, true ),
        'wp_fastest_cache' => array( 'WP Fastest Cache', class_exists( 'WpFastestCache' ), null, true ),
        'speedycache'      => array( 'SpeedyCache', defined( 'SPEEDYCACHE_VERSION' ), $version( 'SPEEDYCACHE_VERSION' ), true ),
        'cache_enabler'    => array( 'Cache Enabler', class_exists( 'Cache_Enabler' ), null, true ),
        'breeze'           => array( 'Breeze', defined( 'BREEZE_VERSION' ), $version( 'BREEZE_VERSION' ), true ),
        'hummingbird'      => array( 'Hummingbird', defined( 'WPHB_VERSION' ), $version( 'WPHB_VERSION' ), true ),
        'sg_optimizer'     => array( 'Speed Optimizer (SiteGround)', defined( 'SiteGround_Optimizer\VERSION' ), $version( 'SiteGround_Optimizer\VERSION' ), true ),
        'flying_press'     => array( 'FlyingPress', defined( 'FLYING_PRESS_VERSION' ), $version( 'FLYING_PRESS_VERSION' ), true ),
        'nitropack'        => array( 'NitroPack', defined( 'NITROPACK_VERSION' ), $version( 'NITROPACK_VERSION' ), true ),
        'jetpack_boost'    => array( 'Jetpack Boost', defined( 'JETPACK_BOOST_VERSION' ), $version( 'JETPACK_BOOST_VERSION' ), false ),
        'autoptimize'      => array( 'Autoptimize', defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ), $version( 'AUTOPTIMIZE_PLUGIN_VERSION' ), false ),
        'perfmatters'      => array( 'Perfmatters', defined( 'PERFMATTERS_VERSION' ), $version( 'PERFMATTERS_VERSION' ), false ),
    );
    $out = array();
    foreach ( $known as $key => $p ) {
        if ( $p[1] ) {
            $out[] = array(
                'key'          => $key,
                'name'         => $p[0],
                'version'      => $p[2],
                'page_cache'   => $p[3],
                'configurable' => in_array( $key, array( 'litespeed', 'super_page_cache' ), true ),
            );
        }
    }
    return $out;
}

/** Which plugin owns wp-content/advanced-cache.php (the page-cache drop-in)? */
function att_mcp_advanced_cache_dropin() {
    $file = WP_CONTENT_DIR . '/advanced-cache.php';
    if ( ! is_readable( $file ) ) {
        return null;
    }
    $head = (string) file_get_contents( $file, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local drop-in, first 4 KB
    $owners = array(
        'LiteSpeed'        => 'litespeed',
        'SWCFPC'           => 'super_page_cache',
        'WP Rocket'        => 'wp_rocket',
        'W3 Total Cache'   => 'w3_total_cache',
        'W3TC'             => 'w3_total_cache',
        'WP SUPER CACHE'   => 'wp_super_cache',
        'wp-super-cache'   => 'wp_super_cache',
        'WP Fastest Cache' => 'wp_fastest_cache',
        'SpeedyCache'      => 'speedycache',
        'Cache Enabler'    => 'cache_enabler',
        'Breeze'           => 'breeze',
        'FlyingPress'      => 'flying_press',
    );
    foreach ( $owners as $needle => $owner ) {
        if ( false !== stripos( $head, $needle ) ) {
            return $owner;
        }
    }
    return 'unknown';
}

/* ----- LiteSpeed Cache ------------------------------------------------------- */

function att_mcp_litespeed_available() {
    return defined( 'LSCWP_V' ) && class_exists( '\LiteSpeed\Conf' ) && method_exists( '\LiteSpeed\Conf', 'update_confs' );
}

/**
 * Whitelisted LiteSpeed settings: id => array( type, label, group, requires[, enum values] ).
 * requires: 'server' = LiteSpeed web server (or QUIC.cloud CDN), 'quic' = QUIC.cloud connection.
 */
function att_mcp_litespeed_settings() {
    $b = 'bool';
    $i = 'int';
    $l = 'lines';
    return array(
        // Page cache — served by the LiteSpeed web server itself.
        'cache'                        => array( $b, 'Enable page cache', 'cache', 'server' ),
        'cache-priv'                   => array( $b, 'Cache pages for logged-in users (private cache)', 'cache', 'server' ),
        'cache-commenter'              => array( $b, 'Cache pages for commenters', 'cache', 'server' ),
        'cache-rest'                   => array( $b, 'Cache REST API responses', 'cache', 'server' ),
        'cache-page_login'             => array( $b, 'Cache the login page', 'cache', 'server' ),
        'cache-mobile'                 => array( $b, 'Separate mobile cache (only if the theme serves different HTML to phones)', 'cache', 'server' ),
        'cache-browser'                => array( $b, 'Browser cache for static files', 'cache', 'server' ),
        'cache-ttl_pub'                => array( $i, 'Public page cache TTL (seconds)', 'cache', 'server' ),
        'cache-ttl_priv'               => array( $i, 'Private cache TTL (seconds)', 'cache', 'server' ),
        'cache-ttl_frontpage'          => array( $i, 'Front page cache TTL (seconds)', 'cache', 'server' ),
        'cache-ttl_feed'               => array( $i, 'Feed cache TTL (seconds)', 'cache', 'server' ),
        'cache-ttl_browser'            => array( $i, 'Browser cache TTL (seconds)', 'cache', 'server' ),
        'cache-exc'                    => array( $l, 'Never cache these URIs (one per line)', 'cache', 'server' ),
        'purge-stale'                  => array( $b, 'Serve stale pages while they are regenerated', 'cache', 'server' ),
        'esi'                          => array( $b, 'Edge Side Includes (cache pages that contain personal blocks)', 'cache', 'server' ),
        'guest'                        => array( $b, 'Guest mode (instant cached page on the first visit)', 'cache', 'server' ),
        'guest_optm'                   => array( $b, 'Guest optimization (maximum optimization for first visits)', 'cache', 'server' ),
        'crawler'                      => array( $b, 'Crawler that keeps the cache warm (uses server resources)', 'cache', 'server' ),
        'util-instant_click'           => array( $b, 'Instant click (prefetch pages on hover)', 'cache', '' ),
        // CSS
        'optm-css_min'                 => array( $b, 'Minify CSS', 'css', '' ),
        'optm-css_comb'                => array( $b, 'Combine CSS files (rarely needed on HTTP/2; can break layouts)', 'css', '' ),
        'optm-ucss'                    => array( $b, 'Remove unused CSS (UCSS)', 'css', 'quic' ),
        'optm-ucss_inline'             => array( $b, 'Inline the unused-CSS-free stylesheet', 'css', 'quic' ),
        'optm-css_async'               => array( $b, 'Load CSS asynchronously with generated critical CSS', 'css', 'quic' ),
        'optm-ccss_per_url'            => array( $b, 'Critical CSS per URL (instead of per post type)', 'css', 'quic' ),
        'optm-css_async_inline'        => array( $b, 'Inline the asynchronously loaded CSS library', 'css', '' ),
        'optm-css_font_display'        => array( $b, 'font-display: swap for fonts', 'css', '' ),
        'optm-css_exc'                 => array( $l, 'CSS files excluded from minify/combine', 'css', '' ),
        // JavaScript
        'optm-js_min'                  => array( $b, 'Minify JavaScript', 'js', '' ),
        'optm-js_comb'                 => array( $b, 'Combine JavaScript files (can break scripts)', 'js', '' ),
        'optm-js_defer'                => array( 'enum', 'Load JavaScript: 0 = normal, 1 = deferred, 2 = delayed until user interaction', 'js', '', array( 0, 1, 2 ) ),
        'optm-js_exc'                  => array( $l, 'JS files excluded from minify/combine', 'js', '' ),
        'optm-js_defer_exc'            => array( $l, 'JS excluded from defer/delay', 'js', '' ),
        // HTML
        'optm-html_min'                => array( $b, 'Minify HTML', 'html', '' ),
        'optm-qs_rm'                   => array( $b, 'Remove query strings from static files', 'html', '' ),
        'optm-ggfonts_rm'              => array( $b, 'Remove Google Fonts', 'html', '' ),
        'optm-ggfonts_async'           => array( $b, 'Load Google Fonts asynchronously', 'html', '' ),
        'optm-emoji_rm'                => array( $b, 'Remove the WordPress emoji script', 'html', '' ),
        'optm-noscript_rm'             => array( $b, 'Remove noscript tags', 'html', '' ),
        'optm-dns_prefetch_ctrl'       => array( $b, 'Automatic DNS prefetch for external hosts', 'html', '' ),
        'optm-dns_prefetch'            => array( $l, 'Hosts to DNS-prefetch', 'html', '' ),
        'optm-dns_preconnect'          => array( $l, 'Hosts to preconnect', 'html', '' ),
        'optm-localize'                => array( $b, 'Host third-party scripts locally', 'html', '' ),
        'optm-guest_only'              => array( $b, 'Only optimize pages for guests (not logged-in users)', 'html', '' ),
        // Media
        'media-lazy'                   => array( $b, 'Lazy-load images', 'media', '' ),
        'media-iframe_lazy'            => array( $b, 'Lazy-load iframes', 'media', '' ),
        'media-lazy_exc'               => array( $l, 'Images excluded from lazy loading', 'media', '' ),
        'media-add_missing_sizes'      => array( $b, 'Add missing image width/height (prevents layout shift)', 'media', '' ),
        'media-placeholder_resp'       => array( $b, 'Responsive image placeholders', 'media', '' ),
        'media-lqip'                   => array( $b, 'Low-quality image placeholders', 'media', 'quic' ),
        'media-vpi'                    => array( $b, 'Viewport images (never lazy-load images in the first screen)', 'media', 'quic' ),
        // Image optimization (done by QUIC.cloud)
        'img_optm-auto'                => array( $b, 'Optimize images automatically after upload', 'image', 'quic' ),
        'img_optm-webp'                => array( 'enum', 'Next-gen images: 0 = off, 1 = WebP, 2 = AVIF', 'image', 'quic', array( 0, 1, 2 ) ),
        'img_optm-webp_replace_srcset' => array( $b, 'Serve WebP/AVIF in srcset too', 'image', 'quic' ),
        'img_optm-lossless'            => array( $b, 'Lossless compression', 'image', 'quic' ),
        'img_optm-exif'                => array( $b, 'Keep EXIF data', 'image', 'quic' ),
        'img_optm-ori'                 => array( $b, 'Optimize original images', 'image', 'quic' ),
        // Other
        'discuss-avatar_cache'         => array( $b, 'Cache Gravatars locally', 'other', '' ),
        'misc-heartbeat_front'         => array( $b, 'Control WordPress Heartbeat on the front end', 'other', '' ),
        'misc-heartbeat_front_ttl'     => array( $i, 'Front-end Heartbeat interval (seconds; 0 = off)', 'other', '' ),
        'misc-heartbeat_back'          => array( $b, 'Control Heartbeat in the admin', 'other', '' ),
        'misc-heartbeat_back_ttl'      => array( $i, 'Admin Heartbeat interval (seconds; 0 = off)', 'other', '' ),
        'misc-heartbeat_editor'        => array( $b, 'Control Heartbeat in the editor', 'other', '' ),
        'misc-heartbeat_editor_ttl'    => array( $i, 'Editor Heartbeat interval (seconds; 0 = off)', 'other', '' ),
        'debug-disable_all'            => array( $b, 'Disable ALL LiteSpeed features (troubleshooting only)', 'other', '' ),
    );
}

function att_mcp_litespeed_presets() {
    return array(
        'essentials' => 'Page cache only, no optimization. Safe on every site.',
        'basic'      => 'Essentials + mobile cache and automatic image optimization (image optimization needs QUIC.cloud). Low risk.',
        'advanced'   => 'Basic + guest mode, CSS/JS/HTML minify, deferred JS, emoji and query-string removal, font-display swap, DNS prefetch and Gravatar cache. Recommended for most sites; check pages afterwards.',
        'aggressive' => 'Advanced + CSS/JS combine, unused CSS, critical CSS and iframe lazy-loading (UCSS/critical CSS need QUIC.cloud). Higher risk of layout or script problems.',
        'extreme'    => 'Aggressive + image lazy-loading and placeholders, viewport images, delayed JS and missing image sizes. Highest risk: test every page type.',
    );
}

/** A LiteSpeed setting value. $stored = true ignores constant overrides (LITESPEED_CONF__*). */
function att_mcp_litespeed_get( $id, $stored = false ) {
    return \LiteSpeed\Conf::cls()->conf( $id, $stored );
}

function att_mcp_litespeed_server() {
    $type = defined( 'LITESPEED_SERVER_TYPE' ) ? (string) LITESPEED_SERVER_TYPE : 'NONE';
    return array(
        'type'                 => $type,
        'page_cache_supported' => 'NONE' !== $type,
    );
}

function att_mcp_litespeed_quic_connected() {
    try {
        if ( class_exists( '\LiteSpeed\Cloud' ) && method_exists( '\LiteSpeed\Cloud', 'cls' ) ) {
            $cloud = \LiteSpeed\Cloud::cls();
            return method_exists( $cloud, 'activated' ) ? (bool) $cloud->activated() : false;
        }
    } catch ( \Throwable $e ) {
        return false;
    }
    return false;
}

/** Ids a LiteSpeed preset changes (read from the preset file the plugin ships). */
function att_mcp_litespeed_preset_ids( $preset ) {
    if ( ! class_exists( '\LiteSpeed\Preset' ) || ! method_exists( '\LiteSpeed\Preset', 'get_standard' ) ) {
        return new WP_Error( 'att_mcp_no_presets', 'This LiteSpeed Cache version has no presets.' );
    }
    $file = \LiteSpeed\Preset::get_standard( $preset );
    if ( ! is_readable( $file ) ) {
        return new WP_Error( 'att_mcp_no_preset', sprintf( 'LiteSpeed preset "%s" was not found.', $preset ) );
    }
    $ids = array();
    foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
        $row = json_decode( trim( $line ), true );
        if ( is_array( $row ) && isset( $row[0] ) && is_string( $row[0] ) && '_version' !== $row[0] && ! att_mcp_is_secret_name( $row[0] ) ) {
            $ids[] = $row[0];
        }
    }
    if ( ! $ids ) {
        return new WP_Error( 'att_mcp_bad_preset', sprintf( 'LiteSpeed preset "%s" could not be read.', $preset ) );
    }
    return $ids;
}

/** History capture/restore for LiteSpeed settings (see includes/history.php). */
function att_mcp_capture_litespeed_conf( $ids ) {
    if ( ! att_mcp_litespeed_available() ) {
        return null;
    }
    $ids = array_values( array_unique( array_map( 'strval', (array) $ids ) ) );
    sort( $ids );
    $values = array();
    foreach ( $ids as $id ) {
        $values[ $id ] = att_mcp_litespeed_get( $id, true );
    }
    return array( 'type' => 'litespeed_conf', 'target' => $ids, 'existed' => true, 'value' => $values );
}

function att_mcp_restore_litespeed_conf( $item ) {
    if ( ! att_mcp_litespeed_available() ) {
        return new WP_Error( 'att_mcp_plugin_inactive', 'LiteSpeed Cache is not active, so its settings cannot be restored now.' );
    }
    \LiteSpeed\Conf::cls()->update_confs( (array) $item['value'] );
    return true;
}

function att_mcp_litespeed_config() {
    $server = att_mcp_litespeed_server();
    $quic   = att_mcp_litespeed_quic_connected();
    $values = array();
    $rows   = array();
    foreach ( att_mcp_litespeed_settings() as $id => $spec ) {
        $value          = att_mcp_litespeed_get( $id );
        $stored         = att_mcp_litespeed_get( $id, true );
        $values[ $id ]  = $value;
        $row            = array( 'value' => $value, 'label' => $spec[1], 'group' => $spec[2], 'type' => $spec[0] );
        if ( 'enum' === $spec[0] ) {
            $row['values'] = $spec[4];
        }
        if ( $spec[3] ) {
            $row['requires'] = 'server' === $spec[3] ? 'LiteSpeed web server' : 'QUIC.cloud connection';
        }
        if ( $stored !== $value ) {
            $row['overridden_by_constant'] = true;
        }
        $rows[ $id ] = $row;
    }

    return array(
        'version'              => LSCWP_V,
        'server'               => $server['type'],
        'page_cache_supported' => $server['page_cache_supported'],
        'quic_cloud_connected' => $quic,
        'settings'             => $rows,
        'presets'              => att_mcp_litespeed_presets(),
        'recommendations'      => att_mcp_litespeed_recommendations( $values, $server['page_cache_supported'], $quic ),
    );
}

function att_mcp_litespeed_recommendations( $v, $server_ok, $quic ) {
    $rec = array();
    $add = function ( $priority, $issue, $fix, $settings = null ) use ( &$rec ) {
        $item = array( 'priority' => $priority, 'issue' => $issue, 'fix' => $fix );
        if ( $settings ) {
            $item['settings'] = $settings;
        }
        $rec[] = $item;
    };
    $on = function ( $id ) use ( $v ) {
        return ! empty( $v[ $id ] );
    };

    if ( $on( 'debug-disable_all' ) ) {
        $add( 'high', '"Disable all features" is on, so LiteSpeed Cache does nothing.', 'Turn it off unless you are troubleshooting.', array( 'debug-disable_all' => false ) );
    }
    if ( ! $server_ok ) {
        $add( 'high', 'This server is not a LiteSpeed web server, so LiteSpeed Cache cannot cache pages here (its page cache runs inside the LiteSpeed server or the QUIC.cloud CDN). Its optimization features still work.', 'Keep LiteSpeed Cache for CSS/JS/HTML/media optimization and add a PHP page cache (for example Super Page Cache), or move to LiteSpeed hosting / QUIC.cloud CDN.' );
    } elseif ( ! $on( 'cache' ) ) {
        $add( 'high', 'Page caching is off.', 'Turn the page cache on.', array( 'cache' => true ) );
    }
    if ( $server_ok && $on( 'cache' ) && ! $on( 'cache-browser' ) ) {
        $add( 'medium', 'Browser caching of static files is off, so repeat visitors download CSS/JS/images again.', 'Turn on browser cache.', array( 'cache-browser' => true ) );
    }
    $min = array();
    foreach ( array( 'optm-css_min', 'optm-js_min', 'optm-html_min' ) as $id ) {
        if ( ! $on( $id ) ) {
            $min[ $id ] = true;
        }
    }
    if ( $min ) {
        $add( 'medium', 'CSS/JS/HTML are not all minified.', 'Minifying is low-risk and shrinks every page.', $min );
    }
    if ( empty( $v['optm-js_defer'] ) ) {
        $add( 'medium', 'JavaScript loads normally and blocks rendering.', 'Defer JavaScript (1). "Delay" (2) is faster still but can break sliders, menus or consent banners — test carefully.', array( 'optm-js_defer' => 1 ) );
    }
    if ( ! $on( 'media-add_missing_sizes' ) ) {
        $add( 'low', 'Images without width/height cause layout shift (CLS).', 'Let LiteSpeed add missing image sizes.', array( 'media-add_missing_sizes' => true ) );
    }
    if ( ! $on( 'optm-emoji_rm' ) ) {
        $add( 'low', 'The WordPress emoji script loads on every page.', 'Remove it (browsers show emoji natively).', array( 'optm-emoji_rm' => true ) );
    }
    if ( ! $on( 'optm-css_font_display' ) ) {
        $add( 'low', 'Text can stay invisible while web fonts load.', 'Use font-display: swap.', array( 'optm-css_font_display' => true ) );
    }
    if ( ! $on( 'media-iframe_lazy' ) ) {
        $add( 'low', 'Iframes (videos, maps) load immediately even below the fold.', 'Lazy-load iframes.', array( 'media-iframe_lazy' => true ) );
    }
    if ( ! $quic ) {
        $needs = array();
        foreach ( att_mcp_litespeed_settings() as $id => $spec ) {
            if ( 'quic' === $spec[3] && $on( $id ) ) {
                $needs[] = $id;
            }
        }
        if ( $needs ) {
            $add( 'medium', 'These settings are on but need a QUIC.cloud connection, which this site does not have, so they do nothing: ' . implode( ', ', $needs ) . '.', 'Connect QUIC.cloud in LiteSpeed Cache > General (free tier available), or turn them off.' );
        }
    }
    if ( $on( 'optm-css_comb' ) || $on( 'optm-js_comb' ) ) {
        $add( 'low', 'CSS/JS combining is on. On HTTP/2 it rarely helps and is a common cause of broken layouts and scripts.', 'If anything looks broken, turn combining off first.' );
    }
    if ( $on( 'guest' ) && ! $on( 'cache' ) ) {
        $add( 'low', 'Guest mode is on but the page cache is off, so guest mode has no effect.', 'Turn the page cache on (on a LiteSpeed server) or guest mode off.' );
    }
    return $rec;
}

/* ----- Super Page Cache ------------------------------------------------------ */

function att_mcp_spc_available() {
    return defined( 'SWCFPC_VERSION' ) && class_exists( '\SPC\Services\Settings_Store' ) && class_exists( '\SPC\Modules\Settings_Manager' );
}

/** Whitelisted Super Page Cache settings: key => array( type, label, group[, enum values] ). */
function att_mcp_spc_settings() {
    $b     = 'bool';
    $i     = 'int';
    $l     = 'lines';
    $sched = array( 'never', 'daily', 'weekly', 'monthly' );
    $beat  = array( 'default', 'reduced', 'disabled' );
    $s     = array(
        // Page cache
        'cf_fallback_cache'                  => array( $b, 'Disk page cache — the main page cache (installs the advanced-cache.php drop-in and WP_CACHE)', 'cache' ),
        'cf_fallback_cache_ttl'              => array( $i, 'Disk cache lifespan in seconds (0 = until purged)', 'cache' ),
        'cf_fallback_cache_excluded_urls'    => array( $l, 'Never cache these URIs (one per line, * wildcard)', 'cache' ),
        'cf_fallback_cache_excluded_cookies' => array( $l, 'Skip the cache when these cookies are present', 'cache' ),
        'cf_maxage'                          => array( $i, 'CDN/Cloudflare edge cache TTL (s-maxage, seconds)', 'cache' ),
        'cf_browser_maxage'                  => array( $i, 'Browser cache TTL for pages (max-age, seconds)', 'cache' ),
        'stale_while_revalidate'             => array( $b, 'Serve stale pages while refreshing them', 'cache' ),
        'stale_while_revalidate_ttl'         => array( $i, 'Stale-while-revalidate window (seconds)', 'cache' ),
        'cf_browser_caching_htaccess'        => array( $b, 'Browser caching for static files (.htaccess rules; Apache/LiteSpeed)', 'cache' ),
        'cf_strip_cookies'                   => array( $b, 'Strip response cookies from cached pages', 'cache' ),
        'cf_auto_purge'                      => array( $b, 'Purge affected pages when content changes', 'cache' ),
        'cf_auto_purge_all'                  => array( $b, 'Purge the whole cache when content changes', 'cache' ),
        'cf_auto_purge_on_comments'          => array( $b, 'Purge when comments change', 'cache' ),
        'cf_preloader'                       => array( $b, 'Preloader (keeps the cache warm)', 'cache' ),
        'cf_preloader_start_on_purge'        => array( $b, 'Start the preloader after each purge', 'cache' ),
        'cf_preload_last_urls'               => array( $b, 'Preload the most recently published URLs', 'cache' ),
        // Optimization
        'minify_html'                        => array( $b, 'Minify HTML', 'html' ),
        'cf_prefetch_urls_mode'              => array( 'enum', 'Prefetch internal links: off, hover or viewport', 'html', array( 'off', 'hover', 'viewport' ) ),
        'dns_prefetch_domains'               => array( $l, 'Hosts to DNS-prefetch', 'html' ),
        'preconnect_domains'                 => array( $l, 'Hosts to preconnect', 'html' ),
        'optimize_google_fonts'              => array( $b, 'Optimize Google Fonts loading', 'html' ),
        'local_google_fonts'                 => array( $b, 'Host Google Fonts locally', 'html' ),
        'cf_remove_cache_buster'             => array( $b, 'Remove the cache-buster query string', 'html' ),
        'cf_native_lazy_loading'             => array( $b, 'Native lazy-loading (loading="lazy")', 'media' ),
        'cf_lazy_loading'                    => array( $b, 'JavaScript lazy-loading', 'media' ),
        'cf_lazy_load_video_iframe'          => array( $b, 'Lazy-load videos and iframes', 'media' ),
        'cf_lazy_load_skip_images'           => array( $i, 'Number of first images never lazy-loaded', 'media' ),
        'cf_lazy_load_excluded'              => array( $l, 'Images excluded from lazy-loading', 'media' ),
        'cf_lazy_load_bg'                    => array( $b, 'Lazy-load CSS background images', 'media' ),
        // Other
        'cf_heartbeat_frontend'              => array( 'enum', 'Heartbeat on the front end', 'other', $beat ),
        'cf_heartbeat_admin'                 => array( 'enum', 'Heartbeat in the admin', 'other', $beat ),
        'cf_heartbeat_editor'                => array( 'enum', 'Heartbeat in the editor', 'other', $beat ),
        'database_optimization'              => array( $b, 'Scheduled database cleanup', 'database' ),
        'post_revision_interval'             => array( 'enum', 'Delete post revisions', 'database', $sched ),
        'auto_draft_post_interval'           => array( 'enum', 'Delete auto-drafts', 'database', $sched ),
        'trashed_post_interval'              => array( 'enum', 'Empty the post trash', 'database', $sched ),
        'spam_comment_interval'              => array( 'enum', 'Delete spam comments', 'database', $sched ),
        'trashed_comment_interval'           => array( 'enum', 'Empty the comment trash', 'database', $sched ),
        'all_transients_interval'            => array( 'enum', 'Delete transients', 'database', $sched ),
        'optimize_tables_interval'           => array( 'enum', 'Optimize database tables', 'database', $sched ),
        'cf_object_cache_purge_on_flush'     => array( $b, 'Flush the object cache when purging', 'other' ),
        'cf_opcache_purge_on_flush'          => array( $b, 'Reset OPcache when purging', 'other' ),
    );
    $bypass = array(
        'cf_bypass_404'          => '404 pages',
        'cf_bypass_single_post'  => 'single posts',
        'cf_bypass_pages'        => 'pages',
        'cf_bypass_front_page'   => 'the front page',
        'cf_bypass_home'         => 'the blog home',
        'cf_bypass_archives'     => 'archives',
        'cf_bypass_tags'         => 'tag archives',
        'cf_bypass_category'     => 'category archives',
        'cf_bypass_feeds'        => 'feeds',
        'cf_bypass_search_pages' => 'search results',
        'cf_bypass_author_pages' => 'author pages',
        'cf_bypass_amp'          => 'AMP pages',
        'cf_bypass_query_var'    => 'URLs with query strings',
        'cf_bypass_wp_json_rest' => 'REST API responses',
        'cf_bypass_sitemap'      => 'sitemaps',
        'cf_bypass_file_robots'  => 'robots.txt',
    );
    foreach ( $bypass as $key => $what ) {
        $s[ $key ] = array( $b, 'Do not cache ' . $what, 'bypass' );
    }
    return $s;
}

function att_mcp_spc_get( $key ) {
    return \SPC\Services\Settings_Store::get_instance()->get( $key );
}

/** Save through Super Page Cache's settings manager. Returns array( updated, rejected ). */
function att_mcp_spc_update( $values ) {
    $manager = new \SPC\Modules\Settings_Manager();
    $result  = $manager->update_settings( (array) $values, true );
    return array(
        'updated'  => isset( $result['updated'] ) ? (array) $result['updated'] : array(),
        'rejected' => isset( $result['rejected'] ) ? (array) $result['rejected'] : array(),
    );
}

function att_mcp_capture_spc_settings( $keys ) {
    if ( ! att_mcp_spc_available() ) {
        return null;
    }
    $keys = array_values( array_unique( array_map( 'strval', (array) $keys ) ) );
    sort( $keys );
    $values = array();
    foreach ( $keys as $key ) {
        $values[ $key ] = att_mcp_spc_get( $key );
    }
    return array( 'type' => 'spc_settings', 'target' => $keys, 'existed' => true, 'value' => $values );
}

function att_mcp_restore_spc_settings( $item ) {
    if ( ! att_mcp_spc_available() ) {
        return new WP_Error( 'att_mcp_plugin_inactive', 'Super Page Cache is not active, so its settings cannot be restored now.' );
    }
    att_mcp_spc_update( (array) $item['value'] );
    return true;
}

function att_mcp_spc_config() {
    $store  = \SPC\Services\Settings_Store::get_instance();
    $values = array();
    $rows   = array();
    foreach ( att_mcp_spc_settings() as $key => $spec ) {
        $value          = att_mcp_spc_get( $key );
        $values[ $key ] = $value;
        $row            = array( 'value' => 'bool' === $spec[0] ? (bool) $value : $value, 'label' => $spec[1], 'group' => $spec[2], 'type' => $spec[0] );
        if ( 'enum' === $spec[0] ) {
            $row['values'] = $spec[3];
        }
        if ( method_exists( $store, 'is_overridden' ) && $store->is_overridden( $key ) ) {
            $row['overridden_by_constant'] = true;
        }
        $rows[ $key ] = $row;
    }
    $cloudflare = method_exists( $store, 'is_cloudflare_connected' ) ? (bool) $store->is_cloudflare_connected() : false;

    return array(
        'version'              => SWCFPC_VERSION,
        'page_cache_enabled'   => ! empty( $values['cf_fallback_cache'] ) && ! empty( att_mcp_spc_get( 'cf_cache_enabled' ) ),
        'dropin_active'        => defined( 'WP_CACHE' ) && WP_CACHE && 'super_page_cache' === att_mcp_advanced_cache_dropin(),
        'cloudflare_connected' => $cloudflare,
        'settings'             => $rows,
        'recommendations'      => att_mcp_spc_recommendations( $values, $cloudflare ),
    );
}

function att_mcp_spc_recommendations( $v, $cloudflare ) {
    $rec = array();
    $add = function ( $priority, $issue, $fix, $settings = null ) use ( &$rec ) {
        $item = array( 'priority' => $priority, 'issue' => $issue, 'fix' => $fix );
        if ( $settings ) {
            $item['settings'] = $settings;
        }
        $rec[] = $item;
    };
    $on = function ( $key ) use ( $v ) {
        return ! empty( $v[ $key ] );
    };
    $other_page_caches = array();
    foreach ( att_mcp_detect_cache_plugins() as $p ) {
        if ( $p['page_cache'] && ! in_array( $p['key'], array( 'super_page_cache', 'litespeed' ), true ) ) {
            $other_page_caches[] = $p['name'];
        }
    }

    if ( ! $on( 'cf_fallback_cache' ) || empty( att_mcp_spc_get( 'cf_cache_enabled' ) ) ) {
        if ( $other_page_caches ) {
            $add( 'info', 'The disk page cache is off; ' . implode( ', ', $other_page_caches ) . ' is caching pages instead.', 'Keep only one page cache active.' );
        } else {
            $add( 'high', 'The disk page cache is off, so every visit is generated by PHP' . ( $cloudflare ? ' unless Cloudflare caches it' : '' ) . '.', 'Turn on the disk page cache.', array( 'cf_fallback_cache' => true ) );
        }
    } else {
        if ( ! ( defined( 'WP_CACHE' ) && WP_CACHE ) || 'super_page_cache' !== att_mcp_advanced_cache_dropin() ) {
            $add( 'high', 'The disk page cache is on but its advanced-cache.php drop-in is not active (WP_CACHE off, or another plugin owns the drop-in), so pages are not served from the cache.', 'Make wp-config.php and wp-content writable, then save the setting again (turn it off and on), or add define( \'WP_CACHE\', true ); to wp-config.php.' );
        }
        if ( $other_page_caches ) {
            $add( 'high', 'Another page cache is active too (' . implode( ', ', $other_page_caches ) . '). Two page caches conflict and cause stale pages.', 'Deactivate the other page cache, or turn the disk page cache off.' );
        }
        if ( ! $on( 'cf_auto_purge' ) && ! $on( 'cf_auto_purge_all' ) ) {
            $add( 'high', 'Automatic purging is off, so edits do not appear until the cache is purged by hand.', 'Turn on automatic purging.', array( 'cf_auto_purge' => true ) );
        }
        if ( ! $on( 'cf_preloader' ) ) {
            $add( 'low', 'The preloader is off, so the first visitor after a purge gets an uncached page.', 'Turn on the preloader.', array( 'cf_preloader' => true ) );
        }
        if ( ! $on( 'stale_while_revalidate' ) ) {
            $add( 'low', 'Pages being refreshed are generated while the visitor waits.', 'Serve stale pages while refreshing.', array( 'stale_while_revalidate' => true ) );
        }
    }
    if ( ! $on( 'cf_browser_caching_htaccess' ) ) {
        $add( 'medium', 'Static files are not given long browser-cache lifetimes by the plugin.', 'Turn on browser caching for static files (Apache/LiteSpeed servers; nginx needs server rules).', array( 'cf_browser_caching_htaccess' => true ) );
    }
    if ( ! $on( 'cf_native_lazy_loading' ) && ! $on( 'cf_lazy_loading' ) ) {
        $add( 'medium', 'Images below the fold are loaded immediately.', 'Turn on native lazy-loading.', array( 'cf_native_lazy_loading' => true ) );
    }
    if ( ! $on( 'minify_html' ) ) {
        $add( 'low', 'HTML is not minified.', 'Minify HTML.', array( 'minify_html' => true ) );
    }
    if ( isset( $v['cf_prefetch_urls_mode'] ) && 'off' === $v['cf_prefetch_urls_mode'] ) {
        $add( 'low', 'Internal links are not prefetched.', 'Prefetch on hover makes page-to-page navigation feel instant.', array( 'cf_prefetch_urls_mode' => 'hover' ) );
    }
    if ( isset( $v['cf_heartbeat_frontend'] ) && 'default' === $v['cf_heartbeat_frontend'] ) {
        $add( 'low', 'WordPress Heartbeat runs at full rate on the front end.', 'Disable it on the front end unless a plugin needs it there.', array( 'cf_heartbeat_frontend' => 'disabled' ) );
    }
    if ( ! $on( 'database_optimization' ) ) {
        $add( 'low', 'No scheduled database cleanup.', 'Turn on scheduled cleanup (e.g. weekly revisions, spam and transients) or run att/optimize-database from time to time.', array( 'database_optimization' => true, 'spam_comment_interval' => 'weekly', 'all_transients_interval' => 'weekly' ) );
    }
    return $rec;
}

/* ----- Values ---------------------------------------------------------------- */

/**
 * Validate one incoming value against a whitelist spec. $spec[0] is the type;
 * enum values are the last element. Returns the clean value or WP_Error.
 */
function att_mcp_cache_clean_value( $key, $value, $spec ) {
    switch ( $spec[0] ) {
        case 'bool':
            if ( is_string( $value ) ) {
                $parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
                if ( null === $parsed ) {
                    return new WP_Error( 'att_mcp_bad_value', sprintf( '"%s" must be true or false.', $key ) );
                }
                return $parsed;
            }
            return (bool) $value;

        case 'int':
            if ( ! is_numeric( $value ) || (int) $value < 0 ) {
                return new WP_Error( 'att_mcp_bad_value', sprintf( '"%s" must be a whole number of 0 or more.', $key ) );
            }
            return (int) $value;

        case 'enum':
            $allowed = end( $spec );
            foreach ( (array) $allowed as $option ) {
                if ( (string) $option === (string) ( is_bool( $value ) ? (int) $value : $value ) ) {
                    return $option;
                }
            }
            return new WP_Error( 'att_mcp_bad_value', sprintf( '"%s" must be one of: %s.', $key, implode( ', ', (array) $allowed ) ) );

        case 'lines':
            $lines = is_array( $value ) ? $value : preg_split( '/\r\n|\r|\n/', (string) $value );
            $clean = array();
            foreach ( $lines as $line ) {
                if ( is_scalar( $line ) ) {
                    $line = sanitize_text_field( (string) $line );
                    if ( '' !== $line ) {
                        $clean[] = $line;
                    }
                }
            }
            return array_slice( $clean, 0, 300 );
    }
    return sanitize_text_field( (string) $value );
}

/* ----- Execute --------------------------------------------------------------- */

function att_mcp_execute_get_cache_config( $input ) {
    $only = isset( $input['plugin'] ) ? sanitize_key( (string) $input['plugin'] ) : '';

    $plugins      = att_mcp_detect_cache_plugins();
    $page_caches  = wp_list_filter( $plugins, array( 'page_cache' => true ) );
    $warnings     = array();
    if ( count( $page_caches ) > 1 ) {
        $warnings[] = 'More than one page-cache plugin is active (' . implode( ', ', wp_list_pluck( $page_caches, 'name' ) ) . '). Run only one page cache: two caches serve stale pages and fight over advanced-cache.php. (LiteSpeed Cache can stay for optimization if its page cache is off or the server is not LiteSpeed.)';
    }

    $out = array(
        'plugins'       => $plugins,
        'wp_cache'      => defined( 'WP_CACHE' ) && WP_CACHE,
        'page_cache_dropin' => att_mcp_advanced_cache_dropin(),
        'object_cache'  => (bool) wp_using_ext_object_cache(),
        'warnings'      => $warnings,
    );

    if ( ( '' === $only || 'litespeed' === $only ) && att_mcp_litespeed_available() ) {
        $out['litespeed'] = att_mcp_litespeed_config();
    }
    if ( ( '' === $only || 'super_page_cache' === $only ) && att_mcp_spc_available() ) {
        $out['super_page_cache'] = att_mcp_spc_config();
    }
    if ( ! isset( $out['litespeed'] ) && ! isset( $out['super_page_cache'] ) ) {
        $out['note'] = $page_caches
            ? 'The active cache plugin is not one this tool can configure (LiteSpeed Cache or Super Page Cache). Use its admin screen, or att/get-option / att/update-option on its settings option.'
            : 'No page-cache plugin is active. On a LiteSpeed server install LiteSpeed Cache; otherwise Super Page Cache (both free on WordPress.org, installable with att/manage-plugin), then configure it here.';
    }
    return $out;
}

function att_mcp_execute_update_cache_config( $input ) {
    $plugin   = isset( $input['plugin'] ) ? sanitize_key( (string) $input['plugin'] ) : '';
    $settings = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? $input['settings'] : array();
    $preset   = isset( $input['preset'] ) ? sanitize_key( (string) $input['preset'] ) : '';
    $purge    = ! isset( $input['purge'] ) || ! empty( $input['purge'] );

    if ( 'litespeed' === $plugin ) {
        if ( ! att_mcp_litespeed_available() ) {
            return new WP_Error( 'att_mcp_plugin_inactive', 'LiteSpeed Cache is not active on this site.' );
        }
        $spec = att_mcp_litespeed_settings();
    } elseif ( 'super_page_cache' === $plugin ) {
        if ( ! att_mcp_spc_available() ) {
            return new WP_Error( 'att_mcp_plugin_inactive', 'Super Page Cache is not active on this site.' );
        }
        if ( '' !== $preset ) {
            return new WP_Error( 'att_mcp_bad_input', 'Presets are a LiteSpeed Cache feature. For Super Page Cache pass "settings".' );
        }
        $spec = att_mcp_spc_settings();
    } else {
        return new WP_Error( 'att_mcp_bad_input', '"plugin" must be "litespeed" or "super_page_cache".' );
    }

    if ( '' !== $preset && ! isset( att_mcp_litespeed_presets()[ $preset ] ) ) {
        return new WP_Error( 'att_mcp_bad_input', 'Unknown preset. Use one of: ' . implode( ', ', array_keys( att_mcp_litespeed_presets() ) ) . '.' );
    }
    if ( ! $settings && '' === $preset ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pass "settings" (id => value) and/or a "preset".' );
    }

    // Validate everything before changing anything.
    $clean   = array();
    $unknown = array();
    foreach ( $settings as $key => $value ) {
        $key = (string) $key;
        if ( ! isset( $spec[ $key ] ) ) {
            $unknown[] = $key;
            continue;
        }
        $value = att_mcp_cache_clean_value( $key, $value, $spec[ $key ] );
        if ( is_wp_error( $value ) ) {
            return $value;
        }
        $clean[ $key ] = $value;
    }
    if ( $unknown ) {
        return new WP_Error( 'att_mcp_unknown_setting', 'These settings are unknown or not changeable over MCP: ' . implode( ', ', $unknown ) . '. Allowed ids are listed by att/get-cache-config.' );
    }

    $preset_ids = array();
    if ( '' !== $preset ) {
        $preset_ids = att_mcp_litespeed_preset_ids( $preset );
        if ( is_wp_error( $preset_ids ) ) {
            return $preset_ids;
        }
    }

    $warnings = array();
    $label    = ( 'litespeed' === $plugin ? 'LiteSpeed Cache' : 'Super Page Cache' ) . ': ' . ( $preset ? 'preset ' . $preset . ( $clean ? ' + ' : '' ) : '' ) . implode( ', ', array_keys( $clean ) );

    if ( 'litespeed' === $plugin ) {
        $ids    = array_values( array_unique( array_merge( $preset_ids, array_keys( $clean ) ) ) );
        $before = array();
        foreach ( $ids as $id ) {
            $before[ $id ] = att_mcp_litespeed_get( $id, true );
        }
        $change_id = att_mcp_record_change( array( att_mcp_capture_litespeed_conf( $ids ) ), $label );

        if ( $preset ) {
            \LiteSpeed\Preset::cls()->apply( $preset );
        }
        if ( $clean ) {
            \LiteSpeed\Conf::cls()->update_confs( $clean );
        }

        $server = att_mcp_litespeed_server();
        $quic   = att_mcp_litespeed_quic_connected();
        $changed = array();
        foreach ( $ids as $id ) {
            $after = att_mcp_litespeed_get( $id, true );
            if ( $after !== $before[ $id ] ) {
                $changed[ $id ] = array( 'from' => $before[ $id ], 'to' => $after );
            }
            if ( ! empty( $after ) && isset( $spec[ $id ] ) ) {
                if ( 'server' === $spec[ $id ][3] && ! $server['page_cache_supported'] ) {
                    $warnings['server'] = 'Page-cache settings were saved, but this is not a LiteSpeed web server, so LiteSpeed Cache cannot cache pages here.';
                } elseif ( 'quic' === $spec[ $id ][3] && ! $quic ) {
                    $warnings[ 'quic_' . $id ] = sprintf( '"%s" is on but needs a QUIC.cloud connection (LiteSpeed Cache > General) before it does anything.', $id );
                }
            }
        }
        $rejected = array();
    } else {
        $keys = array_keys( $clean );
        if ( isset( $clean['cf_fallback_cache'] ) ) {
            $keys[] = 'cf_cache_enabled'; // the plugin's master switch; restored on undo too
        }
        $before = array();
        foreach ( $keys as $key ) {
            $before[ $key ] = att_mcp_spc_get( $key );
        }
        $change_id = att_mcp_record_change( array( att_mcp_capture_spc_settings( $keys ) ), $label );

        $spc_values = array();
        foreach ( $clean as $key => $value ) {
            $spc_values[ $key ] = is_bool( $value ) ? (int) $value : $value; // the plugin stores booleans as 0/1
        }
        // Pages are only cached when the master switch AND the disk cache are on. The
        // first time, turn caching on the way the plugin's setup wizard does.
        if ( ! empty( $spc_values['cf_fallback_cache'] ) && empty( $before['cf_cache_enabled'] ) ) {
            $wizard = att_mcp_rest( 'GET', '/spc/v1/settings/wizard' );
            if ( is_wp_error( $wizard ) ) {
                $warnings['wizard'] = 'Super Page Cache reported: ' . $wizard->get_error_message();
            }
            unset( $spc_values['cf_fallback_cache'] );
        }
        $result   = $spc_values ? att_mcp_spc_update( $spc_values ) : array( 'updated' => array(), 'rejected' => array() );
        $rejected = $result['rejected'];

        $changed = array();
        foreach ( $keys as $key ) {
            $after = att_mcp_spc_get( $key );
            if ( $after !== $before[ $key ] ) {
                $changed[ $key ] = array( 'from' => $before[ $key ], 'to' => $after );
            }
        }
        if ( ! empty( $clean['cf_fallback_cache'] ) && ! ( defined( 'WP_CACHE' ) && WP_CACHE ) && 'super_page_cache' !== att_mcp_advanced_cache_dropin() ) {
            $warnings['dropin'] = 'The disk page cache is on, but its advanced-cache.php drop-in could not be installed (wp-config.php or wp-content not writable). Pages are not cached until WP_CACHE is enabled.';
        }
    }

    $purged = null;
    if ( $purge && $changed && function_exists( 'att_mcp_execute_purge_cache' ) ) {
        $purged = att_mcp_execute_purge_cache( array() );
        $purged = $purged['purged'];
    }

    return array(
        'plugin'    => $plugin,
        'preset'    => $preset ? $preset : null,
        'changed'   => $changed,
        'unchanged' => array_values( array_diff( array_keys( $clean ), array_keys( $changed ), $rejected ) ),
        'rejected'  => $rejected, // locked by a constant in wp-config.php
        'warnings'  => array_values( $warnings ),
        'purged'    => $purged,
        'change_id' => $change_id,
        'note'      => 'Check the result with att/audit-performance (and att/render-page for layout). Undo with att/undo-change' . ( $change_id ? ' (change #' . $change_id . ')' : '' ) . '.',
    );
}
