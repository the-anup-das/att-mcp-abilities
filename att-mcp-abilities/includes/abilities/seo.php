<?php
/**
 * SEO: analyse and optimise posts.
 *
 *  att/analyze-post     on-page SEO and readability checks for one post, on its rendered page
 *  att/seo-audit        the same essentials across many posts + site-wide checks
 *  att/update-seo-meta  SEO title, meta description, focus keyword, canonical and indexing,
 *                       written to whichever SEO plugin is active — undoable
 *  att/bulk-update-seo-meta  the same for up to 50 posts, as one undoable change
 *
 * Supported SEO plugins: Yoast SEO, Rank Math, All in One SEO and SEOPress. Yoast,
 * Rank Math and SEOPress keep these fields in post meta (the plugins read them
 * directly; Yoast re-indexes on meta change). All in One SEO keeps them in its
 * own table and is written through its Post model (AIOSEO 4.9.8+).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function att_mcp_register_seo_abilities() {
    $base = att_mcp_ability_base();
    $can  = function () { return current_user_can( 'edit_posts' ); };

    if ( att_mcp_is_enabled( 'att/analyze-post' ) ) {
        att_mcp_register( 'att/analyze-post', array_merge( $base, array(
            'label'               => 'Analyze Post SEO',
            'description'         => 'Checks one post or page for on-page SEO and readability, on the page as it is really rendered: SEO title and meta description (length, focus keyword), focus keyword in the slug, intro, subheadings and image alt text, keyword density, content length, H1 and heading structure, images without alt text or too heavy, internal/external links, readability, noindex, canonical, Open Graph and schema tags. Returns a score, each check with a concrete fix, and existing posts worth linking to. Fix findings with att/update-seo-meta (title, description, keyword), att/update-post or att/update-content (text, headings, links) and att/update-media (alt text).',
            'input_schema'        => array(
                'type'       => 'object',
                'required'   => array( 'id' ),
                'properties' => array(
                    'id'            => array( 'type' => 'integer', 'description' => 'Post or page ID.' ),
                    'focus_keyword' => array( 'type' => 'string', 'description' => 'Keyword to check against. Default: the focus keyword stored in the SEO plugin.' ),
                    'render'        => array( 'type' => 'boolean', 'description' => 'Fetch the rendered page for the real <title>, meta tags and H1. Default true.' ),
                ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_analyze_post',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/seo-audit' ) ) {
        att_mcp_register( 'att/seo-audit', array_merge( $base, array(
            'label'               => 'SEO Audit',
            'description'         => 'Audits many posts at once and ranks what to fix: missing or badly sized SEO titles and meta descriptions, duplicate titles/descriptions, missing focus keywords, thin content, images without alt text, posts without internal links, extra H1s and noindexed posts — plus site-wide problems (search engines discouraged, plain permalinks, no SEO plugin, sitemaps off). Use att/analyze-post on the worst posts for detailed fixes.',
            'input_schema'        => array(
                'type'       => 'object',
                'properties' => array(
                    'post_type' => array( 'type' => 'string', 'description' => 'Post type to audit (e.g. post, page, product). Default: posts and pages.' ),
                    'per_page'  => array( 'type' => 'integer', 'description' => 'Items per call (1–50). Default 20.' ),
                    'page'      => array( 'type' => 'integer', 'description' => 'Page of results. Default 1.' ),
                ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_seo_audit',
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/update-seo-meta' ) ) {
        att_mcp_register( 'att/update-seo-meta', array_merge( $base, array(
            'label'               => 'Update SEO Meta',
            'description'         => 'Sets the SEO title, meta description, focus keyword, canonical URL and search-engine indexing of a post or page in the active SEO plugin (Yoast SEO, Rank Math, All in One SEO or SEOPress). Only the fields you pass change; an empty string clears a field back to the plugin\'s template. Titles and descriptions may use the plugin\'s variables (e.g. Yoast %%sitename%%). Aim for titles of 30–60 characters and descriptions of 120–160 that contain the focus keyword. "canonical" and "indexing" need editor rights. Undoable with att/undo-change; purge the page cache afterwards.',
            'input_schema'        => array(
                'type'       => 'object',
                'required'   => array( 'id' ),
                'properties' => array(
                    'id'            => array( 'type' => 'integer', 'description' => 'Post or page ID.' ),
                    'title'         => array( 'type' => 'string', 'description' => 'SEO title (the <title> tag).' ),
                    'description'   => array( 'type' => 'string', 'description' => 'Meta description.' ),
                    'focus_keyword' => array( 'type' => 'string', 'description' => 'Focus keyword/keyphrase.' ),
                    'canonical'     => array( 'type' => 'string', 'description' => 'Canonical URL (empty string = the post\'s own URL).' ),
                    'indexing'      => array( 'type' => 'string', 'enum' => array( 'index', 'noindex', 'default' ), 'description' => 'noindex hides the page from search engines; default follows the plugin\'s setting for this post type.' ),
                    'plugin'        => array( 'type' => 'string', 'enum' => array( 'yoast', 'rank_math', 'aioseo', 'seopress' ), 'description' => 'Only needed when several SEO plugins are active.' ),
                ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_update_seo_meta',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => false, 'idempotent' => true ) ) ),
        ) ) );
    }

    if ( att_mcp_is_enabled( 'att/bulk-update-seo-meta' ) ) {
        att_mcp_register( 'att/bulk-update-seo-meta', array_merge( $base, array(
            'label'               => 'Bulk Update SEO Meta',
            'description'         => 'The same as att/update-seo-meta for up to 50 posts in one call — for fixing site-wide findings from att/seo-audit or Rank Math\'s rank-math/audit-site-seo (titles too long, missing descriptions, missing focus keywords) — recorded as ONE change that att/undo-change reverts. Pass "items": [{id, title?, description?, focus_keyword?, canonical?, indexing?}]; each item changes only the fields it includes. Items that are invalid or not yours to edit are reported in "errors" and skipped; the rest are written. Write a specific title and description per post rather than templated text.',
            'input_schema'        => array(
                'type'       => 'object',
                'required'   => array( 'items' ),
                'properties' => array(
                    'items'  => array(
                        'type'  => 'array',
                        'items' => array(
                            'type'       => 'object',
                            'required'   => array( 'id' ),
                            'properties' => array(
                                'id'            => array( 'type' => 'integer' ),
                                'title'         => array( 'type' => 'string' ),
                                'description'   => array( 'type' => 'string' ),
                                'focus_keyword' => array( 'type' => 'string' ),
                                'canonical'     => array( 'type' => 'string' ),
                                'indexing'      => array( 'type' => 'string', 'enum' => array( 'index', 'noindex', 'default' ) ),
                            ),
                        ),
                        'description' => 'Up to 50 posts.',
                    ),
                    'plugin' => array( 'type' => 'string', 'enum' => array( 'yoast', 'rank_math', 'aioseo', 'seopress' ), 'description' => 'Only needed when several SEO plugins are active.' ),
                ),
            ),
            'permission_callback' => $can,
            'execute_callback'    => 'att_mcp_execute_bulk_update_seo_meta',
            'meta'                => array_merge( $base['meta'], array( 'annotations' => array( 'destructive' => false, 'idempotent' => true ) ) ),
        ) ) );
    }
}

/* ----- SEO plugins ----------------------------------------------------------- */

/** Active supported SEO plugins, in order of preference. */
function att_mcp_seo_plugins() {
    $out = array();
    if ( defined( 'WPSEO_VERSION' ) ) {
        $out['yoast'] = array( 'name' => 'Yoast SEO', 'version' => WPSEO_VERSION );
    }
    if ( defined( 'RANK_MATH_VERSION' ) ) {
        $out['rank_math'] = array( 'name' => 'Rank Math', 'version' => RANK_MATH_VERSION );
    }
    if ( defined( 'AIOSEO_VERSION' ) && function_exists( 'aioseo' ) && class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
        $out['aioseo'] = array( 'name' => 'All in One SEO', 'version' => AIOSEO_VERSION );
    }
    if ( defined( 'SEOPRESS_VERSION' ) ) {
        $out['seopress'] = array( 'name' => 'SEOPress', 'version' => SEOPRESS_VERSION );
    }
    return $out;
}

/** The SEO plugin to use: the requested one, else the first active one. '' when none. */
function att_mcp_seo_plugin( $requested = '' ) {
    $active = att_mcp_seo_plugins();
    if ( '' !== $requested ) {
        return isset( $active[ $requested ] ) ? $requested : new WP_Error( 'att_mcp_seo_plugin_inactive', sprintf( 'The SEO plugin "%s" is not active. Active: %s.', $requested, $active ? implode( ', ', array_keys( $active ) ) : 'none' ) );
    }
    return $active ? (string) key( $active ) : '';
}

/** Why an active SEO plugin outputs no SEO tags ('' when it works). */
function att_mcp_seo_plugin_problem( $plugin ) {
    if ( 'rank_math' === $plugin && is_callable( array( '\RankMath\Helper', 'is_invalid_registration' ) ) && \RankMath\Helper::is_invalid_registration() ) {
        return 'Rank Math is not set up yet: until its setup wizard is finished (connect a free Rank Math account, or choose "Skip") it outputs no SEO title, description or robots tags. Values saved now apply once it is set up.';
    }
    if ( 'seopress' === $plugin && function_exists( 'seopress_get_toggle_option' ) && '1' !== (string) seopress_get_toggle_option( 'titles' ) ) {
        return 'SEOPress "Titles & Metas" is turned off (SEO > Dashboard), so SEO titles and descriptions are not output.';
    }
    return '';
}

/** Post meta keys per meta-based SEO plugin. */
function att_mcp_seo_meta_keys( $plugin ) {
    $keys = array(
        'yoast'     => array( 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'focus_keyword' => '_yoast_wpseo_focuskw', 'canonical' => '_yoast_wpseo_canonical', 'robots' => '_yoast_wpseo_meta-robots-noindex' ),
        'rank_math' => array( 'title' => 'rank_math_title', 'description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword', 'canonical' => 'rank_math_canonical_url', 'robots' => 'rank_math_robots' ),
        'seopress'  => array( 'title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc', 'focus_keyword' => '_seopress_analysis_target_kw', 'canonical' => '_seopress_robots_canonical', 'robots' => '_seopress_robots_index' ),
    );
    return isset( $keys[ $plugin ] ) ? $keys[ $plugin ] : array();
}

function att_mcp_aioseo_post( $post_id ) {
    return \AIOSEO\Plugin\Common\Models\Post::getPost( (int) $post_id );
}

/** AIOSEO's keyphrases JSON field as an array. */
function att_mcp_aioseo_keyphrases( $model ) {
    $kp = isset( $model->keyphrases ) ? $model->keyphrases : null;
    $kp = is_string( $kp ) ? json_decode( $kp, true ) : json_decode( (string) wp_json_encode( $kp ), true );
    return is_array( $kp ) ? $kp : array();
}

/** Stored SEO fields of a post: title, description, focus_keyword, canonical, indexing. */
function att_mcp_seo_read( $plugin, $post_id ) {
    $out = array( 'title' => '', 'description' => '', 'focus_keyword' => '', 'canonical' => '', 'indexing' => 'default' );
    if ( 'aioseo' === $plugin ) {
        try {
            $m  = att_mcp_aioseo_post( $post_id );
            $kp = att_mcp_aioseo_keyphrases( $m );
            $out['title']         = (string) ( isset( $m->title ) ? $m->title : '' );
            $out['description']   = (string) ( isset( $m->description ) ? $m->description : '' );
            $out['canonical']     = (string) ( isset( $m->canonical_url ) ? $m->canonical_url : '' );
            $out['focus_keyword'] = ! empty( $m->focus_keyword ) && is_string( $m->focus_keyword ) ? $m->focus_keyword : ( isset( $kp['focus']['keyphrase'] ) ? (string) $kp['focus']['keyphrase'] : '' );
            if ( isset( $m->robots_default ) && ! $m->robots_default ) {
                $out['indexing'] = ! empty( $m->robots_noindex ) ? 'noindex' : 'index';
            }
        } catch ( \Throwable $e ) {
            $out['error'] = $e->getMessage();
        }
        return $out;
    }

    $keys = att_mcp_seo_meta_keys( $plugin );
    if ( ! $keys ) {
        return $out;
    }
    foreach ( array( 'title', 'description', 'focus_keyword', 'canonical' ) as $field ) {
        $out[ $field ] = (string) get_post_meta( $post_id, $keys[ $field ], true );
    }
    $robots = get_post_meta( $post_id, $keys['robots'], true );
    if ( 'yoast' === $plugin ) {
        $out['indexing'] = '1' === (string) $robots ? 'noindex' : ( '2' === (string) $robots ? 'index' : 'default' );
    } elseif ( 'rank_math' === $plugin && is_array( $robots ) && $robots ) {
        $out['indexing'] = in_array( 'noindex', $robots, true ) ? 'noindex' : ( in_array( 'index', $robots, true ) ? 'index' : 'default' );
    } elseif ( 'seopress' === $plugin ) {
        $out['indexing'] = 'yes' === (string) $robots ? 'noindex' : 'default';
    }
    return $out;
}

/**
 * Best-effort title/description as the plugin outputs them (templates and
 * variables resolved) without rendering the page. Null values = unknown.
 */
function att_mcp_seo_effective( $plugin, $post ) {
    $out = array( 'title' => null, 'description' => null );
    try {
        if ( 'yoast' === $plugin && function_exists( 'YoastSEO' ) ) {
            $meta = YoastSEO()->meta->for_post( $post->ID );
            if ( $meta ) {
                $out['title']       = (string) $meta->title;
                $out['description'] = (string) $meta->description;
            }
        } elseif ( 'aioseo' === $plugin && function_exists( 'aioseo' ) ) {
            $out['title']       = (string) aioseo()->meta->title->getPostTitle( $post );
            $out['description'] = (string) aioseo()->meta->description->getPostDescription( $post );
        } elseif ( 'rank_math' === $plugin && class_exists( '\RankMath\Helper' ) && function_exists( 'rank_math' ) && is_object( rank_math()->variables ) ) {
            // Rank Math sets its variables up on the front end only; do it here too.
            rank_math()->variables->setup();
            $stored = att_mcp_seo_read( 'rank_math', $post->ID );
            $title  = '' !== $stored['title'] ? $stored['title'] : (string) \RankMath\Helper::get_settings( 'titles.pt_' . $post->post_type . '_title' );
            $desc   = '' !== $stored['description'] ? $stored['description'] : (string) \RankMath\Helper::get_settings( 'titles.pt_' . $post->post_type . '_description' );
            $out['title']       = '' !== $title ? (string) \RankMath\Helper::replace_vars( $title, $post ) : null;
            $out['description'] = '' !== $desc ? (string) \RankMath\Helper::replace_vars( $desc, $post ) : null;
        }
    } catch ( \Throwable $e ) {
        return array( 'title' => null, 'description' => null );
    }
    foreach ( $out as $k => $v ) {
        $out[ $k ] = null === $v ? null : att_mcp_clean_text( wp_strip_all_tags( html_entity_decode( $v, ENT_QUOTES, 'UTF-8' ) ) );
    }
    return $out;
}

/* ----- Text helpers ------------------------------------------------------------ */

function att_mcp_seo_norm( $text ) {
    $text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
    $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
    return att_mcp_clean_text( remove_accents( $text ) );
}

/** Whole-phrase occurrences of a keyword in a text (case and accent insensitive). */
function att_mcp_seo_count( $text, $keyword ) {
    $keyword = att_mcp_seo_norm( $keyword );
    if ( '' === $keyword ) {
        return 0;
    }
    return (int) preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $keyword, '/' ) . '(?![\p{L}\p{N}])/u', att_mcp_seo_norm( $text ) );
}

function att_mcp_seo_len( $text ) {
    return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
}

/** The post's content rendered to HTML (blocks + shortcodes), without echoing anything. */
function att_mcp_seo_content_html( $post ) {
    $content = (string) $post->post_content;
    ob_start();
    try {
        $html = do_shortcode( has_blocks( $content ) ? do_blocks( $content ) : wpautop( $content ) );
    } finally {
        ob_end_clean();
    }
    return $html;
}

/**
 * Normalise a URL for "same page?" comparisons: absolute, no scheme, fragment
 * or trailing slash. The query string is kept (plain permalinks are ?p=123).
 */
function att_mcp_seo_url_key( $url ) {
    $url   = WP_Http::make_absolute_url( (string) strtok( (string) $url, '#' ), home_url( '/' ) );
    $parts = wp_parse_url( $url );
    if ( ! is_array( $parts ) ) {
        return (string) $url;
    }
    $key = ( isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . untrailingslashit( isset( $parts['path'] ) ? $parts['path'] : '' );
    return empty( $parts['query'] ) ? $key : $key . '?' . $parts['query'];
}

/* ----- analyze-post -------------------------------------------------------------- */

function att_mcp_execute_analyze_post( $input ) {
    $post = get_post( isset( $input['id'] ) ? (int) $input['id'] : 0 );
    if ( ! $post ) {
        return new WP_Error( 'att_mcp_no_post', 'No post/page found for that id.' );
    }
    if ( ! current_user_can( 'edit_post', $post->ID ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to edit this item.' );
    }
    if ( ! is_post_type_viewable( $post->post_type ) ) {
        return new WP_Error( 'att_mcp_not_viewable', 'This content type has no public page, so there is nothing to optimise for search.' );
    }

    $plugin = att_mcp_seo_plugin();
    $stored = $plugin ? att_mcp_seo_read( $plugin, $post->ID ) : null;
    $kw     = isset( $input['focus_keyword'] ) && '' !== trim( (string) $input['focus_keyword'] ) ? sanitize_text_field( (string) $input['focus_keyword'] ) : '';
    if ( '' === $kw && $stored && '' !== $stored['focus_keyword'] ) {
        $kw = trim( strtok( $stored['focus_keyword'], ',' ) ); // Rank Math/SEOPress store a list; the first is primary
    }

    // Content (what the author controls).
    $html     = att_mcp_seo_content_html( $post );
    $scan     = att_mcp_scan_html( $html, get_permalink( $post ) );
    $stats    = att_mcp_text_stats( $scan['text'], get_locale() );
    $words    = $stats['words'];
    $is_post  = 'post' === $post->post_type;

    // Rendered page (what search engines see).
    $render   = ! isset( $input['render'] ) || ! empty( $input['render'] );
    $rendered = null;
    $rscan    = null;
    $render_error = null;
    if ( $render ) {
        $page = att_mcp_fetch_own_page( $post, 2097152 );
        if ( is_wp_error( $page ) ) {
            $render_error = $page->get_error_message();
        } else {
            $rscan    = att_mcp_scan_html( $page['body'], $page['url'] );
            $h1       = wp_list_filter( $rscan['headings'], array( 'level' => 1 ) );
            $rendered = array(
                'status'      => $page['status'],
                'preview'     => $page['preview'],
                'title'       => $rscan['title'],
                'description' => $rscan['meta']['description'],
                'robots'      => $rscan['meta']['robots'],
                'canonical'   => $rscan['meta']['canonical'],
                'og_title'    => $rscan['meta']['og_title'],
                'og_image'    => $rscan['meta']['og_image'],
                'schema_blocks' => $rscan['jsonld'],
                'h1'          => array_values( wp_list_pluck( $h1, 'text' ) ),
                'lang'        => $rscan['lang'],
            );
        }
    }

    $effective = $plugin ? att_mcp_seo_effective( $plugin, $post ) : array( 'title' => null, 'description' => null );
    $title     = $rendered && null !== $rendered['title'] ? $rendered['title'] : ( $effective['title'] ? $effective['title'] : ( $stored && '' !== $stored['title'] ? $stored['title'] : get_the_title( $post ) ) );
    $desc      = $rendered ? (string) $rendered['description'] : (string) ( $effective['description'] ? $effective['description'] : ( $stored ? $stored['description'] : '' ) );

    $checks = array();
    $check  = function ( $id, $status, $message, $fix = '' ) use ( &$checks ) {
        $checks[] = array_filter( array( 'id' => $id, 'status' => $status, 'message' => $message, 'fix' => $fix ) );
    };
    $meta_fix = $plugin ? 'att/update-seo-meta' : 'Install an SEO plugin (e.g. Yoast SEO or Rank Math) to control titles and descriptions.';
    if ( $plugin && '' !== att_mcp_seo_plugin_problem( $plugin ) ) {
        $check( 'seo_plugin', 'fail', att_mcp_seo_plugin_problem( $plugin ), 'The site owner has to finish this in the plugin\'s admin screens.' );
    }

    // Title.
    $tlen = att_mcp_seo_len( $title );
    if ( 0 === $tlen ) {
        $check( 'title', 'fail', 'The page has no <title>.', $meta_fix );
    } elseif ( $tlen < 30 || $tlen > 65 ) {
        $check( 'title', $tlen < 20 || $tlen > 75 ? 'fail' : 'warn', sprintf( 'The SEO title is %d characters ("%s"); 30–60 fits search results best.', $tlen, $title ), $meta_fix );
    } else {
        $check( 'title', 'pass', sprintf( 'The SEO title is %d characters.', $tlen ) );
    }

    // Meta description.
    $dlen = att_mcp_seo_len( $desc );
    if ( 0 === $dlen ) {
        $check( 'description', 'fail', 'No meta description: Google will pick a random text snippet.', $meta_fix . ' — write 120–160 characters that include the focus keyword and invite the click.' );
    } elseif ( $dlen < 110 || $dlen > 165 ) {
        $check( 'description', $dlen < 50 || $dlen > 200 ? 'fail' : 'warn', sprintf( 'The meta description is %d characters; 120–160 is ideal.', $dlen ), $meta_fix );
    } else {
        $check( 'description', 'pass', sprintf( 'The meta description is %d characters.', $dlen ) );
    }

    // Focus keyword.
    $density = null;
    if ( '' === $kw ) {
        $check( 'focus_keyword', 'warn', 'No focus keyword is set, so keyword placement cannot be checked.', ( $plugin ? 'Set one with att/update-seo-meta (focus_keyword), or pass focus_keyword to this tool.' : 'Pass focus_keyword to this tool.' ) );
    } else {
        $in_title = att_mcp_seo_count( $title, $kw ) > 0;
        $check( 'keyword_in_title', $in_title ? 'pass' : 'fail', $in_title ? 'The focus keyword is in the SEO title.' : 'The focus keyword is not in the SEO title.', $in_title ? '' : $meta_fix . ' — put the keyword near the start of the title.' );
        $in_desc = att_mcp_seo_count( $desc, $kw ) > 0;
        $check( 'keyword_in_description', $in_desc ? 'pass' : 'warn', $in_desc ? 'The focus keyword is in the meta description.' : 'The focus keyword is not in the meta description.', $in_desc ? '' : $meta_fix );
        $slug_kw = sanitize_title( $kw );
        $in_slug = '' !== $slug_kw && false !== strpos( $post->post_name, $slug_kw );
        $check( 'keyword_in_slug', $in_slug ? 'pass' : 'warn', $in_slug ? 'The focus keyword is in the URL slug.' : sprintf( 'The URL slug "%s" does not contain the focus keyword.', $post->post_name ), $in_slug ? '' : ( 'publish' === $post->post_status ? 'Only change the slug of a published post together with a redirect from the old URL.' : 'Set the slug with att/update-post or att/update-content.' ) );
        $intro    = isset( $scan['paragraphs'][0] ) ? $scan['paragraphs'][0] : implode( ' ', array_slice( explode( ' ', $scan['text'] ), 0, 100 ) );
        $in_intro = att_mcp_seo_count( $intro, $kw ) > 0;
        $check( 'keyword_in_intro', $in_intro ? 'pass' : 'warn', $in_intro ? 'The focus keyword appears in the first paragraph.' : 'The focus keyword does not appear in the first paragraph.', $in_intro ? '' : 'Mention it naturally in the opening paragraph.' );
        $sub   = array_filter( $scan['headings'], function ( $h ) { return $h['level'] >= 2; } );
        $in_h  = 0;
        foreach ( $sub as $h ) {
            $in_h += att_mcp_seo_count( $h['text'], $kw ) > 0 ? 1 : 0;
        }
        if ( $sub ) {
            $check( 'keyword_in_subheadings', $in_h ? 'pass' : 'warn', $in_h ? sprintf( 'The focus keyword is in %d of %d subheadings.', $in_h, count( $sub ) ) : 'No subheading contains the focus keyword.', $in_h ? '' : 'Use the keyword (or a close variant) in at least one H2/H3.' );
        }
        $occurrences = att_mcp_seo_count( $scan['text'], $kw );
        $kw_words    = max( 1, count( preg_split( '/\s+/u', trim( $kw ) ) ) );
        $density     = $words ? round( 100 * $occurrences * $kw_words / $words, 2 ) : 0;
        if ( $words >= 100 ) {
            if ( $density < 0.5 ) {
                $check( 'keyword_density', 'warn', sprintf( 'The focus keyword appears %d times (%s%% of the text) — too rarely.', $occurrences, $density ), 'Use it (and synonyms) a few more times where it reads naturally.' );
            } elseif ( $density > 3 ) {
                $check( 'keyword_density', $density > 4 ? 'fail' : 'warn', sprintf( 'The focus keyword makes up %s%% of the text — this reads as keyword stuffing.', $density ), 'Replace some occurrences with synonyms or pronouns.' );
            } else {
                $check( 'keyword_density', 'pass', sprintf( 'Keyword density is %s%% (%d times).', $density, $occurrences ) );
            }
        }
        if ( $scan['images'] ) {
            $alt_kw = 0;
            foreach ( $scan['images'] as $img ) {
                $alt_kw += ( is_string( $img['alt'] ) && att_mcp_seo_count( $img['alt'], $kw ) ) ? 1 : 0;
            }
            $check( 'keyword_in_image_alt', $alt_kw ? 'pass' : 'warn', $alt_kw ? 'An image alt text contains the focus keyword.' : 'No image alt text contains the focus keyword.', $alt_kw ? '' : 'Describe the main image with alt text that naturally includes the keyword (att/update-media).' );
        }
        $dupes = att_mcp_seo_keyword_used_elsewhere( $plugin, $kw, $post->ID );
        if ( $dupes ) {
            $check( 'keyword_cannibalization', 'warn', 'Other content targets the same focus keyword: ' . implode( ', ', wp_list_pluck( $dupes, 'title' ) ) . '.', 'Give each page its own keyword, or merge the pages and redirect.' );
        }
    }

    // Length and structure.
    $min_words = $is_post ? 600 : 300;
    if ( $words < 300 ) {
        $check( 'content_length', 'fail', sprintf( 'Only %d words — thin content rarely ranks.', $words ), 'Expand the content to cover the topic fully (aim for 600+ words for articles).' );
    } elseif ( $words < $min_words ) {
        $check( 'content_length', 'warn', sprintf( '%d words; %d+ is recommended for this kind of content.', $words, $min_words ), 'Add depth: examples, FAQs, steps.' );
    } else {
        $check( 'content_length', 'pass', sprintf( '%d words.', $words ) );
    }
    $content_h1 = count( wp_list_filter( $scan['headings'], array( 'level' => 1 ) ) );
    if ( $rendered ) {
        $h1_count = count( $rendered['h1'] );
        if ( 1 === $h1_count ) {
            $check( 'h1', 'pass', 'The page has exactly one H1.' );
        } else {
            $check( 'h1', 'warn', 0 === $h1_count ? 'The rendered page has no H1 heading.' : sprintf( 'The rendered page has %d H1 headings.', $h1_count ), $content_h1 ? 'Change the H1 headings inside the content to H2 (the theme already prints the title as H1).' : 'Make sure the theme shows the title as an H1.' );
        }
    } elseif ( $content_h1 ) {
        $check( 'h1', 'warn', 'The content contains an H1; the theme normally prints the title as the H1.', 'Use H2 for sections inside the content.' );
    }
    $levels = wp_list_pluck( $scan['headings'], 'level' );
    $sub_count = count( array_filter( $levels, function ( $l ) { return $l >= 2; } ) );
    if ( $words > 350 && $sub_count < floor( $words / 350 ) ) {
        $check( 'subheadings', 'warn', sprintf( '%d subheadings for %d words; long text without subheadings is hard to scan.', $sub_count, $words ), 'Break the text into sections with an H2 roughly every 300 words.' );
    } elseif ( $words > 350 ) {
        $check( 'subheadings', 'pass', sprintf( '%d subheadings.', $sub_count ) );
    }
    $prev = 1;
    foreach ( $levels as $level ) {
        if ( $level > $prev + 1 ) {
            $check( 'heading_order', 'warn', sprintf( 'A heading level is skipped (H%d follows H%d).', $level, $prev ), 'Keep heading levels in order (H2 → H3 → H4).' );
            break;
        }
        $prev = $level;
    }

    // Images.
    $no_alt = array();
    $heavy  = array();
    foreach ( $scan['images'] as $img ) {
        if ( null === $img['alt'] ) {
            $no_alt[] = array_filter( array( 'src' => $img['src'], 'attachment_id' => $img['attachment_id'] ) );
        }
        $file = att_mcp_local_file_for_url( $img['src'] );
        if ( $file && filesize( $file ) > 200 * 1024 ) {
            $heavy[] = array_filter( array( 'src' => $img['src'], 'kb' => (int) round( filesize( $file ) / 1024 ), 'attachment_id' => $img['attachment_id'] ) );
        }
    }
    if ( $scan['images'] ) {
        $check( 'image_alt', $no_alt ? 'fail' : 'pass', $no_alt ? sprintf( '%d of %d images have no alt attribute.', count( $no_alt ), count( $scan['images'] ) ) : 'Every image has an alt attribute.', $no_alt ? 'Add descriptive alt text (att/update-media for library images, or edit the <img> in the content).' : '' );
        if ( $heavy ) {
            $check( 'image_weight', 'warn', sprintf( '%d images are heavier than 200 KB.', count( $heavy ) ), 'Compress them or serve WebP/AVIF; large images slow the page (see att/audit-performance).' );
        }
    } elseif ( $words > 300 ) {
        $check( 'images', 'warn', 'The content has no images.', 'Add at least one relevant image with alt text.' );
    }
    if ( $is_post ) {
        $check( 'featured_image', has_post_thumbnail( $post ) ? 'pass' : 'warn', has_post_thumbnail( $post ) ? 'A featured image is set.' : 'No featured image: shares on social media and many themes need one.', has_post_thumbnail( $post ) ? '' : 'Set one with att/update-post (featured_image).' );
    }

    // Links.
    $self      = att_mcp_seo_url_key( get_permalink( $post ) );
    $internal  = array();
    $external  = 0;
    $empty_txt = 0;
    foreach ( $scan['links'] as $link ) {
        if ( '' === $link['text'] ) {
            $empty_txt++;
        }
        if ( $link['internal'] ) {
            if ( att_mcp_seo_url_key( $link['href'] ) !== $self ) {
                $internal[ att_mcp_seo_url_key( $link['href'] ) ] = true;
            }
        } else {
            $external++;
        }
    }
    $want = $words > 1000 ? 3 : 2;
    $check( 'internal_links', count( $internal ) >= $want ? 'pass' : ( $internal ? 'warn' : 'fail' ), sprintf( '%d internal links in the content.', count( $internal ) ), count( $internal ) >= $want ? '' : 'Link to related content on this site with descriptive anchor text (see link_suggestions).' );
    $check( 'external_links', $external ? 'pass' : 'info', $external ? sprintf( '%d outbound links.', $external ) : 'No outbound links.', $external ? '' : 'Linking to an authoritative source can add credibility.' );
    if ( $empty_txt ) {
        $check( 'link_text', 'warn', sprintf( '%d links have no anchor text.', $empty_txt ), 'Give every link descriptive text (or alt text for image links).' );
    }

    // Readability.
    if ( null !== $stats['flesch_reading_ease'] ) {
        $f = $stats['flesch_reading_ease'];
        $check( 'readability', $f >= 60 ? 'pass' : ( $f >= 30 ? 'warn' : 'fail' ), sprintf( 'Flesch reading ease %s (%s).', $f, $f >= 60 ? 'easy' : ( $f >= 30 ? 'fairly difficult' : 'very difficult' ) ), $f >= 60 ? '' : 'Use shorter sentences and simpler words.' );
    }
    if ( $stats['sentences'] >= 5 && $stats['long_sentences_percent'] > 25 ) {
        $check( 'sentence_length', 'warn', sprintf( '%d%% of sentences have more than 20 words.', $stats['long_sentences_percent'] ), 'Split long sentences.' );
    }
    $long_paras = count( array_filter( $scan['paragraphs'], function ( $p ) { return str_word_count( $p ) > 150; } ) );
    if ( $long_paras ) {
        $check( 'paragraph_length', 'warn', sprintf( '%d paragraphs are longer than 150 words.', $long_paras ), 'Break them up; short paragraphs read better on phones.' );
    }

    // Indexing and tags.
    $robots  = $rendered ? strtolower( (string) $rendered['robots'] ) : '';
    $noindex = false !== strpos( $robots, 'noindex' ) || ( $stored && 'noindex' === $stored['indexing'] );
    if ( $noindex ) {
        $check( 'indexing', 'publish' === $post->post_status ? 'fail' : 'info', 'This page tells search engines not to index it (noindex).', 'If it should appear in search results, set indexing to "default" or "index" with att/update-seo-meta.' );
    }
    if ( $rendered && $rendered['canonical'] && ! $rendered['preview'] && att_mcp_seo_url_key( $rendered['canonical'] ) !== $self ) {
        $check( 'canonical', 'warn', sprintf( 'The canonical URL points elsewhere (%s), so this page will not rank itself.', $rendered['canonical'] ), 'Clear the canonical with att/update-seo-meta unless this page is intentionally a duplicate.' );
    }
    if ( $rendered ) {
        $og = $rendered['og_title'] && $rendered['og_image'];
        $check( 'social_tags', $og ? 'pass' : 'warn', $og ? 'Open Graph title and image are set.' : 'Open Graph title/image missing: social shares look plain.', $og ? '' : 'Enable Open Graph in the SEO plugin and set a featured image.' );
        $check( 'schema', $rendered['schema_blocks'] ? 'pass' : 'info', $rendered['schema_blocks'] ? 'Structured data (JSON-LD) is present.' : 'No structured data (JSON-LD) found.', $rendered['schema_blocks'] ? '' : 'An SEO plugin adds Article/WebPage schema automatically.' );
    }
    if ( strlen( $post->post_name ) > 75 ) {
        $check( 'slug_length', 'warn', 'The URL slug is long.', 'Short, keyword-focused slugs are easier to read and share.' );
    }
    if ( 'publish' === $post->post_status && strtotime( $post->post_modified_gmt ) < time() - YEAR_IN_SECONDS ) {
        $check( 'freshness', 'info', 'Last updated more than a year ago.', 'Refresh facts, dates and examples; updated content often regains rankings.' );
    }

    // Score: pass = 2, warn = 1, fail = 0 (info ignored).
    $points = 0;
    $max    = 0;
    foreach ( $checks as $c ) {
        if ( 'info' !== $c['status'] ) {
            $max    += 2;
            $points += 'pass' === $c['status'] ? 2 : ( 'warn' === $c['status'] ? 1 : 0 );
        }
    }
    $order = array( 'fail' => 0, 'warn' => 1, 'info' => 2, 'pass' => 3 );
    usort( $checks, function ( $a, $b ) use ( $order ) {
        return $order[ $a['status'] ] - $order[ $b['status'] ];
    } );

    return array_filter( array(
        'post'             => array(
            'id'       => $post->ID,
            'title'    => get_the_title( $post ),
            'type'     => $post->post_type,
            'status'   => $post->post_status,
            'url'      => get_permalink( $post ),
            'slug'     => $post->post_name,
            'modified' => $post->post_modified,
        ),
        'score'            => $max ? (int) round( 100 * $points / $max ) : null,
        'focus_keyword'    => '' !== $kw ? $kw : null,
        'checks'           => $checks,
        'seo_plugin'       => $plugin ? array_merge( array( 'key' => $plugin ), att_mcp_seo_plugins()[ $plugin ] ) : null,
        'seo_meta'         => $stored,
        'rendered'         => $rendered,
        'render_error'     => $render_error,
        'stats'            => array_merge( $stats, array( 'keyword_density_percent' => $density, 'internal_links' => count( $internal ), 'external_links' => $external, 'images' => count( $scan['images'] ) ) ),
        'headings'         => array_slice( $scan['headings'], 0, 40 ),
        'images_without_alt' => array_slice( $no_alt, 0, 20 ),
        'heavy_images'     => array_slice( $heavy, 0, 10 ),
        'link_suggestions' => att_mcp_seo_link_suggestions( $post, $kw, array_keys( $internal ) ),
    ), function ( $v ) {
        return null !== $v && array() !== $v;
    } );
}

/** Other published content whose SEO plugin focus keyword equals $kw. */
function att_mcp_seo_keyword_used_elsewhere( $plugin, $kw, $exclude_id ) {
    $keys = att_mcp_seo_meta_keys( $plugin );
    if ( ! $keys || '' === $kw ) {
        return array();
    }
    $q = new WP_Query( array(
        'post_type'              => 'any',
        'post_status'            => 'publish',
        'posts_per_page'         => 6,
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
        'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off keyword lookup
            array( 'key' => $keys['focus_keyword'], 'value' => $kw, 'compare' => '=' ),
        ),
    ) );
    $out = array();
    foreach ( $q->posts as $p ) {
        if ( (int) $p->ID !== (int) $exclude_id && count( $out ) < 5 ) {
            $out[] = array( 'id' => $p->ID, 'title' => get_the_title( $p ), 'url' => get_permalink( $p ) );
        }
    }
    return $out;
}

/** Published posts/pages related to this one that it does not link to yet. */
function att_mcp_seo_link_suggestions( $post, $kw, $already_linked ) {
    $terms = '' !== $kw ? $kw : wp_trim_words( get_the_title( $post ), 4, '' );
    if ( '' === trim( $terms ) ) {
        return array();
    }
    $q = new WP_Query( array(
        's'                      => $terms,
        'post_type'              => array( 'post', 'page' ),
        'post_status'            => 'publish',
        'posts_per_page'         => 8,
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ) );
    $out = array();
    foreach ( $q->posts as $p ) {
        if ( (int) $p->ID === (int) $post->ID || in_array( att_mcp_seo_url_key( get_permalink( $p ) ), $already_linked, true ) ) {
            continue;
        }
        $out[] = array( 'id' => $p->ID, 'title' => get_the_title( $p ), 'url' => get_permalink( $p ) );
        if ( count( $out ) >= 5 ) {
            break;
        }
    }
    return $out;
}

/* ----- seo-audit ------------------------------------------------------------------ */

function att_mcp_execute_seo_audit( $input ) {
    $types = array( 'post', 'page' );
    if ( ! empty( $input['post_type'] ) ) {
        $type = sanitize_key( (string) $input['post_type'] );
        if ( ! post_type_exists( $type ) || ! is_post_type_viewable( $type ) ) {
            return new WP_Error( 'att_mcp_bad_post_type', 'Unknown or non-public post type.' );
        }
        $types = array( $type );
    }
    $per_page = att_mcp_int_arg( $input, 'per_page', 20, 1, 50 );
    $paged    = att_mcp_int_arg( $input, 'page', 1, 1, 10000 );

    $q = new WP_Query( array(
        'post_type'      => $types,
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    $plugin = att_mcp_seo_plugin();
    $rows   = array();
    $titles = array();
    $descs  = array();
    foreach ( $q->posts as $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            continue;
        }
        $stored    = $plugin ? att_mcp_seo_read( $plugin, $post->ID ) : null;
        $effective = $plugin ? att_mcp_seo_effective( $plugin, $post ) : array( 'title' => null, 'description' => null );
        $html      = (string) $post->post_content;
        $scan      = att_mcp_scan_html( $html );
        $words     = att_mcp_text_stats( wp_strip_all_tags( strip_shortcodes( $html ) ) )['words'];

        $title = null !== $effective['title'] && '' !== $effective['title'] ? $effective['title'] : ( $stored && '' !== $stored['title'] ? $stored['title'] : null );
        if ( null === $title ) {
            $title = get_the_title( $post ) . ' - ' . get_bloginfo( 'name' ); // the default template of every supported plugin
        }
        $desc = null !== $effective['description'] ? $effective['description'] : ( $stored ? $stored['description'] : '' );
        $desc_source = ( $stored && '' !== $stored['description'] ) ? 'custom' : ( '' !== (string) $desc ? 'template' : 'none' );

        $issues = array();
        $tlen   = att_mcp_seo_len( $title );
        $dlen   = att_mcp_seo_len( (string) $desc );
        if ( $tlen < 30 || $tlen > 65 ) {
            $issues[] = 'title_length';
        }
        if ( 0 === $dlen ) {
            $issues[] = 'missing_description';
        } elseif ( $dlen < 110 || $dlen > 165 ) {
            $issues[] = 'description_length';
        }
        if ( $plugin && '' === $stored['focus_keyword'] ) {
            $issues[] = 'no_focus_keyword';
        }
        if ( $words < 300 ) {
            $issues[] = 'thin_content';
        }
        $no_alt = count( array_filter( $scan['images'], function ( $i ) { return null === $i['alt']; } ) );
        if ( $no_alt ) {
            $issues[] = 'images_missing_alt';
        }
        $internal = count( array_filter( $scan['links'], function ( $l ) { return $l['internal']; } ) );
        if ( 0 === $internal ) {
            $issues[] = 'no_internal_links';
        }
        if ( wp_list_filter( $scan['headings'], array( 'level' => 1 ) ) ) {
            $issues[] = 'h1_in_content';
        }
        if ( $stored && 'noindex' === $stored['indexing'] ) {
            $issues[] = 'noindex';
        }
        if ( 'post' === $post->post_type && ! has_post_thumbnail( $post ) ) {
            $issues[] = 'no_featured_image';
        }

        $titles[ att_mcp_seo_norm( $title ) ][] = $post->ID;
        if ( $dlen ) {
            $descs[ att_mcp_seo_norm( $desc ) ][] = $post->ID;
        }
        $rows[ $post->ID ] = array(
            'id'                 => $post->ID,
            'title'              => get_the_title( $post ),
            'type'               => $post->post_type,
            'url'                => get_permalink( $post ),
            'seo_title'          => $title,
            'title_length'       => $tlen,
            'description'        => $desc,
            'description_length' => $dlen,
            'description_source' => $desc_source,
            'focus_keyword'      => $stored ? $stored['focus_keyword'] : null,
            'indexing'           => $stored ? $stored['indexing'] : null,
            'words'              => $words,
            'images_missing_alt' => $no_alt,
            'internal_links'     => $internal,
            'issues'             => $issues,
        );
    }
    foreach ( array( 'duplicate_title' => $titles, 'duplicate_description' => $descs ) as $issue => $groups ) {
        foreach ( $groups as $ids ) {
            if ( count( $ids ) > 1 ) {
                foreach ( $ids as $id ) {
                    $rows[ $id ]['issues'][] = $issue;
                }
            }
        }
    }

    $summary = array();
    foreach ( $rows as $row ) {
        foreach ( $row['issues'] as $issue ) {
            $summary[ $issue ] = isset( $summary[ $issue ] ) ? $summary[ $issue ] + 1 : 1;
        }
    }
    arsort( $summary );
    $rows = array_values( $rows );
    usort( $rows, function ( $a, $b ) {
        return count( $b['issues'] ) - count( $a['issues'] );
    } );

    return array_filter( array(
        'site'        => att_mcp_seo_site_checks( $plugin ),
        'seo_plugin'  => $plugin ? $plugin : null,
        'summary'     => $summary,
        'posts'       => $rows,
        'total'       => (int) $q->found_posts,
        'total_pages' => (int) $q->max_num_pages,
        'page'        => $paged,
        'fixes'       => array(
            'title_length / missing_description / description_length / no_focus_keyword / duplicate_*' => 'att/update-seo-meta',
            'thin_content / no_internal_links / h1_in_content'                                         => 'att/update-post, att/update-page or att/update-content',
            'images_missing_alt'                                                                        => 'att/update-media (library images) or edit the content',
            'no_featured_image'                                                                         => 'att/update-post (featured_image)',
            'details for one post'                                                                      => 'att/analyze-post',
        ),
        'note'        => 'Titles and descriptions are the SEO plugin\'s values without rendering pages; att/analyze-post checks the rendered page.',
    ), function ( $v ) {
        return null !== $v && array() !== $v;
    } );
}

/** Site-wide SEO problems. */
function att_mcp_seo_site_checks( $plugin ) {
    $issues = array();
    if ( ! get_option( 'blog_public' ) ) {
        $issues[] = array( 'priority' => 'high', 'issue' => 'Search engines are asked not to index this site (Settings > Reading > "Discourage search engines").', 'fix' => 'Turn it off when the site is ready to go live (att/update-site-settings or Settings > Reading).' );
    }
    if ( '' === (string) get_option( 'permalink_structure' ) ) {
        $issues[] = array( 'priority' => 'high', 'issue' => 'Plain permalinks (?p=123) are used.', 'fix' => 'Use a readable structure such as /%postname%/ (att/update-site-settings).' );
    }
    if ( ! $plugin ) {
        $issues[] = array( 'priority' => 'medium', 'issue' => 'No SEO plugin is active, so titles, meta descriptions, social tags and schema cannot be controlled.', 'fix' => 'Install one (Yoast SEO, Rank Math, All in One SEO or SEOPress) with att/manage-plugin.' );
    } elseif ( '' !== att_mcp_seo_plugin_problem( $plugin ) ) {
        $issues[] = array( 'priority' => 'high', 'issue' => att_mcp_seo_plugin_problem( $plugin ), 'fix' => 'The site owner has to do this in the plugin\'s admin screens.' );
    }
    if ( count( att_mcp_seo_plugins() ) > 1 ) {
        $issues[] = array( 'priority' => 'high', 'issue' => 'Several SEO plugins are active (' . implode( ', ', wp_list_pluck( att_mcp_seo_plugins(), 'name' ) ) . '): duplicate titles, meta tags and schema.', 'fix' => 'Keep one SEO plugin.' );
    }
    if ( ! $plugin && function_exists( 'wp_sitemaps_get_server' ) && ! wp_sitemaps_get_server()->sitemaps_enabled() ) {
        $issues[] = array( 'priority' => 'medium', 'issue' => 'XML sitemaps are disabled.', 'fix' => 'Enable sitemaps (an SEO plugin or WordPress core sitemaps).' );
    }
    if ( '' === trim( (string) get_bloginfo( 'description' ) ) ) {
        $issues[] = array( 'priority' => 'low', 'issue' => 'The site has no tagline; some themes and SEO templates use it.', 'fix' => 'Set a short tagline (att/update-site-settings).' );
    }
    return $issues;
}

/* ----- update-seo-meta ---------------------------------------------------------- */

/** History capture/restore for All in One SEO post data (see includes/history.php). */
function att_mcp_capture_aioseo_post( $post_id ) {
    if ( ! isset( att_mcp_seo_plugins()['aioseo'] ) ) {
        return null;
    }
    $m = att_mcp_aioseo_post( $post_id );
    return array(
        'type'    => 'aioseo_post',
        'target'  => (int) $post_id,
        'existed' => true,
        'value'   => array(
            'title'         => isset( $m->title ) ? $m->title : null,
            'description'   => isset( $m->description ) ? $m->description : null,
            'canonical_url' => isset( $m->canonical_url ) ? $m->canonical_url : null,
            'focus_keyword' => isset( $m->focus_keyword ) ? $m->focus_keyword : null,
            'keyphrases'    => att_mcp_aioseo_keyphrases( $m ),
            'default'       => isset( $m->robots_default ) ? (bool) $m->robots_default : true,
            'noindex'       => ! empty( $m->robots_noindex ),
        ),
    );
}

function att_mcp_restore_aioseo_post( $item ) {
    if ( ! isset( att_mcp_seo_plugins()['aioseo'] ) ) {
        return new WP_Error( 'att_mcp_plugin_inactive', 'All in One SEO is not active, so this change cannot be undone now.' );
    }
    $data = (array) $item['value'];
    if ( version_compare( AIOSEO_VERSION, '5.0', '<' ) ) {
        unset( $data['focus_keyword'] );
    }
    $result = \AIOSEO\Plugin\Common\Models\Post::savePost( (int) $item['target'], $data );
    return is_string( $result ) && '' !== $result ? new WP_Error( 'att_mcp_aioseo_failed', 'All in One SEO could not save: ' . $result ) : true;
}

/* SEO meta writes are split so att/update-seo-meta and att/bulk-update-seo-meta share them. */

/** The SEO plugin to write to (from "plugin", else the active one); WP_Error when there is none. */
function att_mcp_seo_resolve_plugin( $input ) {
    $plugin = att_mcp_seo_plugin( isset( $input['plugin'] ) ? sanitize_key( (string) $input['plugin'] ) : '' );
    if ( is_wp_error( $plugin ) ) {
        return $plugin;
    }
    if ( '' === $plugin ) {
        return new WP_Error( 'att_mcp_no_seo_plugin', 'No supported SEO plugin is active (Yoast SEO, Rank Math, All in One SEO or SEOPress). Install one with att/manage-plugin first; without one WordPress outputs no meta description.' );
    }
    if ( 'aioseo' === $plugin && version_compare( AIOSEO_VERSION, '4.9.8', '<' ) ) {
        return new WP_Error( 'att_mcp_aioseo_old', 'All in One SEO 4.9.8 or newer is needed (older versions reset unsent fields when saving). Update the plugin first.' );
    }
    return $plugin;
}

/** A post whose SEO meta the current user may change; WP_Error otherwise. */
function att_mcp_seo_check_post( $id ) {
    $post = get_post( (int) $id );
    if ( ! $post ) {
        return new WP_Error( 'att_mcp_no_post', 'No post/page found for that id.' );
    }
    if ( ! current_user_can( 'edit_post', $post->ID ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'You are not allowed to edit this item.' );
    }
    if ( ! is_post_type_viewable( $post->post_type ) ) {
        return new WP_Error( 'att_mcp_not_viewable', 'This content type has no public page.' );
    }
    return $post;
}

/** The requested SEO field changes (validated); WP_Error when there are none or one is invalid. */
function att_mcp_seo_parse_changes( $input ) {
    $input   = (array) $input;
    $changes = array();
    foreach ( array( 'title' => 300, 'description' => 500, 'focus_keyword' => 200 ) as $field => $max ) {
        if ( array_key_exists( $field, $input ) ) {
            $changes[ $field ] = substr( sanitize_text_field( (string) $input[ $field ] ), 0, $max );
        }
    }
    if ( array_key_exists( 'canonical', $input ) ) {
        $canonical = trim( (string) $input['canonical'] );
        if ( '' !== $canonical && ! wp_http_validate_url( $canonical ) ) {
            return new WP_Error( 'att_mcp_bad_input', '"canonical" must be an absolute http(s) URL, or an empty string.' );
        }
        $changes['canonical'] = '' === $canonical ? '' : esc_url_raw( $canonical );
    }
    if ( array_key_exists( 'indexing', $input ) ) {
        if ( ! in_array( $input['indexing'], array( 'index', 'noindex', 'default' ), true ) ) {
            return new WP_Error( 'att_mcp_bad_input', '"indexing" must be index, noindex or default.' );
        }
        $changes['indexing'] = $input['indexing'];
    }
    if ( ! $changes ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pass at least one of: title, description, focus_keyword, canonical, indexing.' );
    }
    if ( ( isset( $changes['canonical'] ) || isset( $changes['indexing'] ) ) && ! current_user_can( 'edit_others_posts' ) ) {
        return new WP_Error( 'att_mcp_forbidden', 'Changing the canonical URL or indexing needs editor rights.' );
    }
    return $changes;
}

/** Post meta writes for a meta-based SEO plugin: meta key => value (null = delete). */
function att_mcp_seo_meta_writes( $plugin, $post_id, $changes ) {
    $keys  = att_mcp_seo_meta_keys( $plugin );
    $write = array();
    foreach ( array( 'title', 'description', 'focus_keyword', 'canonical' ) as $field ) {
        if ( isset( $changes[ $field ] ) ) {
            $write[ $keys[ $field ] ] = '' === $changes[ $field ] ? null : $changes[ $field ];
        }
    }
    if ( isset( $changes['indexing'] ) ) {
        $mode = $changes['indexing'];
        if ( 'yoast' === $plugin ) {
            $write[ $keys['robots'] ] = 'default' === $mode ? null : ( 'noindex' === $mode ? '1' : '2' );
        } elseif ( 'rank_math' === $plugin ) {
            $robots = get_post_meta( $post_id, $keys['robots'], true );
            $robots = array_values( array_diff( is_array( $robots ) ? $robots : array(), array( 'index', 'noindex' ) ) );
            $write[ $keys['robots'] ] = 'default' === $mode ? null : array_merge( array( $mode ), $robots );
        } else { // seopress: 'yes' = noindex; no per-post "force index"
            $write[ $keys['robots'] ] = 'noindex' === $mode ? 'yes' : null;
        }
    }
    return $write;
}

/** History items capturing what a write of $changes will overwrite. */
function att_mcp_seo_capture_items( $plugin, $post_id, $changes ) {
    if ( 'aioseo' === $plugin ) {
        return array( att_mcp_capture_aioseo_post( $post_id ) );
    }
    $items = array();
    foreach ( array_keys( att_mcp_seo_meta_writes( $plugin, $post_id, $changes ) ) as $key ) {
        $items[] = att_mcp_capture( 'post_meta', array( (int) $post_id, $key ) );
    }
    return $items;
}

/** Write $changes to the SEO plugin. True or WP_Error. */
function att_mcp_seo_write( $plugin, $post_id, $changes ) {
    if ( 'aioseo' === $plugin ) {
        $data = array();
        foreach ( array( 'title', 'description' ) as $field ) {
            if ( isset( $changes[ $field ] ) ) {
                $data[ $field ] = $changes[ $field ];
            }
        }
        if ( isset( $changes['canonical'] ) ) {
            $data['canonical_url'] = $changes['canonical'];
        }
        if ( isset( $changes['focus_keyword'] ) ) {
            $kp = att_mcp_aioseo_keyphrases( att_mcp_aioseo_post( $post_id ) );
            $kp['focus']['keyphrase'] = $changes['focus_keyword'];
            $data['keyphrases']       = $kp;
            if ( version_compare( AIOSEO_VERSION, '5.0', '>=' ) ) {
                $data['focus_keyword'] = $changes['focus_keyword'];
            }
        }
        if ( isset( $changes['indexing'] ) ) {
            $data['default'] = 'default' === $changes['indexing'];
            $data['noindex'] = 'noindex' === $changes['indexing'];
        }
        $result = \AIOSEO\Plugin\Common\Models\Post::savePost( $post_id, $data );
        if ( is_string( $result ) && '' !== $result ) {
            return new WP_Error( 'att_mcp_aioseo_failed', 'All in One SEO could not save: ' . $result );
        }
        return true;
    }
    foreach ( att_mcp_seo_meta_writes( $plugin, $post_id, $changes ) as $key => $value ) {
        if ( null === $value ) {
            delete_post_meta( $post_id, $key );
        } else {
            update_post_meta( $post_id, $key, wp_slash( $value ) );
        }
    }
    return true;
}

/** "from → to" for each changed field. */
function att_mcp_seo_diff( $before, $after, $changes ) {
    $changed = array();
    foreach ( array_keys( $changes ) as $field ) {
        $changed[ $field ] = array( 'from' => $before[ $field ], 'to' => $after[ $field ] );
    }
    return $changed;
}

/** Follow-up notes after an SEO meta write. */
function att_mcp_seo_write_notes( $plugin, $indexing_changes ) {
    $notes = array();
    if ( 'seopress' === $plugin && in_array( 'index', $indexing_changes, true ) ) {
        $notes[] = 'SEOPress has no per-post "force index": the post now follows its post-type setting.';
    }
    if ( 'rank_math' === $plugin ) {
        $notes[] = "Rank Math recalculates a post's stored SEO score (rank-math/get-seo-scores) when the post is next saved in its editor.";
    }
    if ( '' !== att_mcp_seo_plugin_problem( $plugin ) ) {
        $notes[] = att_mcp_seo_plugin_problem( $plugin );
    }
    $notes[] = 'Purge the page cache (att/purge-cache) and verify with att/analyze-post.';
    return implode( ' ', $notes );
}

function att_mcp_execute_update_seo_meta( $input ) {
    $post = att_mcp_seo_check_post( isset( $input['id'] ) ? (int) $input['id'] : 0 );
    if ( is_wp_error( $post ) ) {
        return $post;
    }
    $plugin = att_mcp_seo_resolve_plugin( $input );
    if ( is_wp_error( $plugin ) ) {
        return $plugin;
    }
    $changes = att_mcp_seo_parse_changes( $input );
    if ( is_wp_error( $changes ) ) {
        return $changes;
    }

    $before    = att_mcp_seo_read( $plugin, $post->ID );
    $change_id = att_mcp_record_change( att_mcp_seo_capture_items( $plugin, $post->ID, $changes ), 'SEO meta of #' . $post->ID . ': ' . implode( ', ', array_keys( $changes ) ) );
    $result    = att_mcp_seo_write( $plugin, $post->ID, $changes );
    if ( is_wp_error( $result ) ) {
        return $result;
    }

    return array(
        'plugin'    => $plugin,
        'id'        => $post->ID,
        'changed'   => att_mcp_seo_diff( $before, att_mcp_seo_read( $plugin, $post->ID ), $changes ),
        'change_id' => $change_id,
        'note'      => att_mcp_seo_write_notes( $plugin, isset( $changes['indexing'] ) ? array( $changes['indexing'] ) : array() ),
    );
}

function att_mcp_execute_bulk_update_seo_meta( $input ) {
    $items = ( isset( $input['items'] ) && is_array( $input['items'] ) ) ? array_values( $input['items'] ) : array();
    if ( ! $items ) {
        return new WP_Error( 'att_mcp_bad_input', 'Pass "items": a list of {id, title?, description?, focus_keyword?, canonical?, indexing?}.' );
    }
    if ( count( $items ) > 50 ) {
        return new WP_Error( 'att_mcp_bad_input', 'At most 50 items per call; send the rest in another call.' );
    }
    $plugin = att_mcp_seo_resolve_plugin( $input );
    if ( is_wp_error( $plugin ) ) {
        return $plugin;
    }

    // Validate every item first; invalid ones are reported and skipped.
    $valid  = array();
    $errors = array();
    foreach ( $items as $index => $item ) {
        $item = is_array( $item ) ? $item : array();
        $id   = isset( $item['id'] ) ? (int) $item['id'] : 0;
        if ( isset( $valid[ $id ] ) ) {
            $errors[] = array( 'index' => $index, 'id' => $id, 'error' => 'This id appears more than once.' );
            continue;
        }
        $post    = att_mcp_seo_check_post( $id );
        $changes = is_wp_error( $post ) ? $post : att_mcp_seo_parse_changes( $item );
        if ( is_wp_error( $changes ) ) {
            $errors[] = array( 'index' => $index, 'id' => $id, 'error' => $changes->get_error_message() );
            continue;
        }
        $valid[ $post->ID ] = $changes;
    }
    if ( ! $valid ) {
        return new WP_Error( 'att_mcp_bad_input', 'No item could be applied. First problem: item ' . $errors[0]['index'] . ' (id ' . $errors[0]['id'] . '): ' . $errors[0]['error'] );
    }

    // One undo point for the whole batch.
    $before   = array();
    $captures = array();
    $indexing = array();
    foreach ( $valid as $id => $changes ) {
        $before[ $id ] = att_mcp_seo_read( $plugin, $id );
        $captures      = array_merge( $captures, att_mcp_seo_capture_items( $plugin, $id, $changes ) );
        if ( isset( $changes['indexing'] ) ) {
            $indexing[] = $changes['indexing'];
        }
    }
    $change_id = att_mcp_record_change( $captures, sprintf( 'SEO meta of %d posts: #%s', count( $valid ), implode( ', #', array_keys( $valid ) ) ) );

    $updated = array();
    foreach ( $valid as $id => $changes ) {
        $result = att_mcp_seo_write( $plugin, $id, $changes );
        if ( is_wp_error( $result ) ) {
            $errors[] = array( 'id' => $id, 'error' => $result->get_error_message() );
            continue;
        }
        $updated[] = array( 'id' => $id, 'changed' => att_mcp_seo_diff( $before[ $id ], att_mcp_seo_read( $plugin, $id ), $changes ) );
    }

    return array(
        'plugin'    => $plugin,
        'updated'   => $updated,
        'errors'    => $errors,
        'change_id' => $change_id,
        'note'      => att_mcp_seo_write_notes( $plugin, $indexing ),
    );
}
