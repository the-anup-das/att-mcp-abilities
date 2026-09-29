<?php
/**
 * Page and content analysis shared by the Performance and SEO abilities:
 *  - att_mcp_scan_html()        an HTML scanner built on core's WP_HTML_Tag_Processor
 *                               (no DOM extension needed; nothing is executed or fetched),
 *  - att_mcp_text_stats()       words, sentences, readability,
 *  - att_mcp_local_file_for_url() maps an on-site asset URL to its file (for real byte sizes),
 *  - att_mcp_page_cache_signals() reads page-cache evidence from response headers and markup.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Lower-cased host of a URL ('' for relative URLs). Protocol-relative URLs are supported. */
function att_mcp_url_host( $url ) {
    $url = (string) $url;
    if ( 0 === strpos( $url, '//' ) ) {
        $url = 'https:' . $url;
    }
    return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
}

/** Is this URL on the site's own host (or relative)? */
function att_mcp_is_internal_url( $url ) {
    $host = att_mcp_url_host( $url );
    return '' === $host || att_mcp_url_host( home_url() ) === $host;
}

/** Collapse whitespace and trim. */
function att_mcp_clean_text( $text ) {
    return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
}

/**
 * Scan an HTML document or fragment and return what matters for performance and
 * SEO: title/meta, headings, images, links, scripts, stylesheets, visible text.
 */
function att_mcp_scan_html( $html, $base_url = '' ) {
    $html = (string) $html;
    $out  = array(
        'title'               => null,
        'lang'                => null,
        'meta'                => array( 'description' => null, 'robots' => null, 'canonical' => null, 'viewport' => null, 'og_title' => null, 'og_description' => null, 'og_image' => null ),
        'jsonld'              => 0,
        'headings'            => array(),
        'images'              => array(),
        'links'               => array(),
        'iframes'             => array(),
        'scripts'             => array(),
        'inline_scripts'      => 0,
        'inline_script_bytes' => 0,
        'stylesheets'         => array(),
        'inline_style_bytes'  => 0,
        'preloads'            => array(),
        'preconnects'         => array(),
        'paragraphs'          => array(),
        'comments'            => array(),
        'text'                => '',
        'elements'            => 0,
        'bytes'               => strlen( $html ),
    );
    if ( '' === $html || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
        return $out;
    }

    $p       = new WP_HTML_Tag_Processor( $html );
    $in_body = false === stripos( $html, '<body' ); // fragments (post content) are all "body"
    $text    = array();
    $heading = null; // array( level, text )
    $link    = null; // array( href, text, internal, nofollow, image_alt )
    $para    = null; // text buffer

    $finish_link = function () use ( &$link, &$out ) {
        if ( null === $link ) {
            return;
        }
        $anchor = att_mcp_clean_text( $link['text'] );
        if ( '' === $anchor && null !== $link['image_alt'] ) {
            $anchor = '[image: ' . $link['image_alt'] . ']';
        }
        if ( count( $out['links'] ) < 500 ) {
            $out['links'][] = array( 'href' => $link['href'], 'text' => $anchor, 'internal' => $link['internal'], 'nofollow' => $link['nofollow'] );
        }
        $link = null;
    };
    $finish_para = function () use ( &$para, &$out ) {
        if ( null === $para ) {
            return;
        }
        $t = att_mcp_clean_text( $para );
        if ( '' !== $t && count( $out['paragraphs'] ) < 400 ) {
            $out['paragraphs'][] = $t;
        }
        $para = null;
    };

    while ( $p->next_token() ) {
        $type = $p->get_token_type();

        if ( '#text' === $type ) {
            $t = $p->get_modifiable_text();
            if ( $in_body ) {
                $text[] = $t;
            }
            if ( null !== $heading ) {
                $heading['text'] .= $t;
            }
            if ( null !== $link ) {
                $link['text'] .= $t;
            }
            if ( null !== $para ) {
                $para .= $t;
            }
            continue;
        }
        if ( '#comment' === $type ) {
            $c = trim( $p->get_modifiable_text() );
            if ( '' !== $c && count( $out['comments'] ) < 50 && 0 !== strpos( $c, 'wp:' ) && 0 !== strpos( $c, '/wp:' ) ) {
                $out['comments'][] = substr( $c, 0, 200 );
            }
            continue;
        }
        if ( '#tag' !== $type ) {
            continue;
        }

        $tag = $p->get_tag();
        if ( $p->is_tag_closer() ) {
            if ( null !== $heading && 'H' . $heading['level'] === $tag ) {
                if ( count( $out['headings'] ) < 150 ) {
                    $out['headings'][] = array( 'level' => $heading['level'], 'text' => att_mcp_clean_text( $heading['text'] ) );
                }
                $heading = null;
            } elseif ( 'A' === $tag ) {
                $finish_link();
            } elseif ( 'P' === $tag ) {
                $finish_para();
            } elseif ( 'HEAD' === $tag ) {
                $in_body = true;
            }
            continue;
        }

        $out['elements']++;
        switch ( $tag ) {
            case 'HTML':
                $lang = $p->get_attribute( 'lang' );
                $out['lang'] = is_string( $lang ) ? $lang : null;
                break;

            case 'BODY':
                $in_body = true;
                break;

            case 'TITLE':
                if ( null === $out['title'] ) {
                    $out['title'] = att_mcp_clean_text( $p->get_modifiable_text() );
                }
                break;

            case 'META':
                $name    = strtolower( (string) ( $p->get_attribute( 'name' ) ? $p->get_attribute( 'name' ) : $p->get_attribute( 'property' ) ) );
                $content = $p->get_attribute( 'content' );
                $content = is_string( $content ) ? att_mcp_clean_text( $content ) : '';
                $map     = array( 'description' => 'description', 'robots' => 'robots', 'viewport' => 'viewport', 'og:title' => 'og_title', 'og:description' => 'og_description', 'og:image' => 'og_image' );
                if ( isset( $map[ $name ] ) && null === $out['meta'][ $map[ $name ] ] ) {
                    $out['meta'][ $map[ $name ] ] = $content;
                }
                break;

            case 'LINK':
                $rel  = strtolower( (string) $p->get_attribute( 'rel' ) );
                $href = (string) $p->get_attribute( 'href' );
                if ( preg_match( '/\bcanonical\b/', $rel ) && null === $out['meta']['canonical'] ) {
                    $out['meta']['canonical'] = $href;
                } elseif ( preg_match( '/\bstylesheet\b/', $rel ) && '' !== $href ) {
                    $media = strtolower( (string) $p->get_attribute( 'media' ) );
                    $out['stylesheets'][] = array(
                        'href'     => $href,
                        'host'     => att_mcp_url_host( $href ),
                        'blocking' => ! $in_body && ( '' === $media || 'all' === $media || 'screen' === $media ),
                    );
                } elseif ( preg_match( '/\bpreload\b/', $rel ) ) {
                    $out['preloads'][] = array( 'href' => $href, 'as' => (string) $p->get_attribute( 'as' ) );
                } elseif ( preg_match( '/\b(preconnect|dns-prefetch)\b/', $rel ) ) {
                    $out['preconnects'][] = att_mcp_url_host( $href ) ? att_mcp_url_host( $href ) : $href;
                }
                break;

            case 'SCRIPT':
                $src   = $p->get_attribute( 'src' );
                $stype = strtolower( trim( (string) $p->get_attribute( 'type' ) ) );
                if ( 'application/ld+json' === $stype ) {
                    $out['jsonld']++;
                    break;
                }
                $is_js = '' === $stype || in_array( $stype, array( 'text/javascript', 'application/javascript', 'module' ), true );
                if ( ! $is_js ) {
                    break; // templates, JSON data, speculation rules, …
                }
                if ( is_string( $src ) && '' !== $src ) {
                    $async = null !== $p->get_attribute( 'async' );
                    $defer = null !== $p->get_attribute( 'defer' );
                    $out['scripts'][] = array(
                        'src'      => $src,
                        'host'     => att_mcp_url_host( $src ),
                        'async'    => $async,
                        'defer'    => $defer,
                        'module'   => 'module' === $stype,
                        'blocking' => ! $in_body && ! $async && ! $defer && 'module' !== $stype,
                    );
                } else {
                    $out['inline_scripts']++;
                    $out['inline_script_bytes'] += strlen( $p->get_modifiable_text() );
                }
                break;

            case 'STYLE':
                $out['inline_style_bytes'] += strlen( $p->get_modifiable_text() );
                break;

            case 'IMG':
                $src = $p->get_attribute( 'src' );
                foreach ( array( 'data-src', 'data-lazy-src', 'data-litespeed-src' ) as $lazy_attr ) {
                    $lazy = $p->get_attribute( $lazy_attr );
                    if ( is_string( $lazy ) && '' !== $lazy && ( ! is_string( $src ) || 0 === strpos( (string) $src, 'data:' ) ) ) {
                        $src = $lazy;
                    }
                }
                $alt   = $p->get_attribute( 'alt' );
                $class = (string) $p->get_attribute( 'class' );
                $image = array(
                    'index'         => count( $out['images'] ),
                    'src'           => is_string( $src ) ? $src : '',
                    'alt'           => is_string( $alt ) ? $alt : ( true === $alt ? '' : null ),
                    'width'         => is_string( $p->get_attribute( 'width' ) ) ? (int) $p->get_attribute( 'width' ) : null,
                    'height'        => is_string( $p->get_attribute( 'height' ) ) ? (int) $p->get_attribute( 'height' ) : null,
                    'loading'       => is_string( $p->get_attribute( 'loading' ) ) ? strtolower( $p->get_attribute( 'loading' ) ) : null,
                    'fetchpriority' => is_string( $p->get_attribute( 'fetchpriority' ) ) ? strtolower( $p->get_attribute( 'fetchpriority' ) ) : null,
                    'srcset'        => null !== $p->get_attribute( 'srcset' ),
                    'attachment_id' => preg_match( '/\bwp-image-(\d+)\b/', $class, $m ) ? (int) $m[1] : 0,
                );
                if ( count( $out['images'] ) < 200 ) {
                    $out['images'][] = $image;
                }
                if ( null !== $link && null === $link['image_alt'] ) {
                    $link['image_alt'] = null === $image['alt'] ? '' : $image['alt'];
                }
                break;

            case 'IFRAME':
                $src = (string) $p->get_attribute( 'src' );
                $out['iframes'][] = array( 'src' => $src, 'host' => att_mcp_url_host( $src ), 'loading' => is_string( $p->get_attribute( 'loading' ) ) ? strtolower( $p->get_attribute( 'loading' ) ) : null );
                break;

            case 'H1':
            case 'H2':
            case 'H3':
            case 'H4':
            case 'H5':
            case 'H6':
                if ( $in_body ) {
                    $heading = array( 'level' => (int) substr( $tag, 1 ), 'text' => '' );
                }
                break;

            case 'A':
                $finish_link();
                $href = $p->get_attribute( 'href' );
                if ( is_string( $href ) && '' !== $href && 0 !== strpos( $href, '#' ) && ! preg_match( '#^(mailto|tel|javascript):#i', $href ) ) {
                    $rel  = strtolower( (string) $p->get_attribute( 'rel' ) );
                    $link = array(
                        'href'      => $href,
                        'text'      => '',
                        'internal'  => att_mcp_is_internal_url( $href ),
                        'nofollow'  => (bool) preg_match( '/\b(nofollow|ugc|sponsored)\b/', $rel ),
                        'image_alt' => null,
                    );
                }
                break;

            case 'P':
                $finish_para();
                if ( $in_body ) {
                    $para = '';
                }
                break;
        }
    }
    $finish_link();
    $finish_para();

    $out['text'] = substr( att_mcp_clean_text( implode( ' ', $text ) ), 0, 300000 );
    return $out;
}

/** English syllable estimate (for the Flesch reading-ease score). */
function att_mcp_syllables( $word ) {
    $w = strtolower( (string) preg_replace( '/[^a-z]/i', '', $word ) );
    if ( strlen( $w ) <= 3 ) {
        return 1;
    }
    $w = (string) preg_replace( '/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $w );
    $w = (string) preg_replace( '/^y/', '', $w );
    return max( 1, (int) preg_match_all( '/[aeiouy]{1,2}/', $w ) );
}

/**
 * Words, sentences and readability for a block of text. The Flesch score is
 * only computed for English (it is an English-language formula).
 */
function att_mcp_text_stats( $text, $locale = '' ) {
    $text  = att_mcp_clean_text( $text );
    $words = preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'’\-]*/u', $text, $m ) ? $m[0] : array();
    $count = count( $words );
    $sentences = $count ? preg_split( '/(?<=[.!?。！？])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) : array();
    $sentence_count = max( $count ? 1 : 0, count( $sentences ) );

    $long = 0;
    foreach ( $sentences as $s ) {
        if ( preg_match_all( '/[\p{L}\p{N}]+/u', $s ) > 20 ) {
            $long++;
        }
    }

    $flesch = null;
    if ( $count >= 30 && 0 === strpos( strtolower( (string) $locale ), 'en' ) ) {
        $syllables = 0;
        foreach ( $words as $w ) {
            $syllables += att_mcp_syllables( $w );
        }
        $flesch = round( 206.835 - 1.015 * ( $count / $sentence_count ) - 84.6 * ( $syllables / $count ), 1 );
    }

    return array(
        'words'                   => $count,
        'sentences'               => $sentence_count,
        'avg_words_per_sentence'  => $sentence_count ? round( $count / $sentence_count, 1 ) : 0,
        'long_sentences_percent'  => $sentence_count ? (int) round( 100 * $long / $sentence_count ) : 0,
        'reading_minutes'         => (int) max( $count ? 1 : 0, ceil( $count / 230 ) ),
        'flesch_reading_ease'     => $flesch,
    );
}

/**
 * Map an on-site asset URL (uploads, wp-content, wp-includes) to its file so the
 * real byte size can be read. Returns '' for external or unknown URLs. The
 * resolved path must stay inside the matched base directory.
 */
function att_mcp_local_file_for_url( $url ) {
    $url = (string) $url;
    if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
        return '';
    }
    if ( 0 === strpos( $url, '//' ) ) {
        $url = 'https:' . $url;
    } elseif ( '/' === substr( $url, 0, 1 ) ) {
        $url = home_url( $url );
    }
    $path = (string) strtok( $url, '?#' );
    $bases = array();
    $uploads = wp_get_upload_dir();
    if ( empty( $uploads['error'] ) ) {
        $bases[] = array( $uploads['baseurl'], $uploads['basedir'] );
    }
    $bases[] = array( content_url(), WP_CONTENT_DIR );
    $bases[] = array( includes_url(), ABSPATH . WPINC );

    foreach ( $bases as $base ) {
        $base_url = (string) preg_replace( '#^https?:#i', '', untrailingslashit( $base[0] ) );
        $no_proto = (string) preg_replace( '#^https?:#i', '', $path );
        if ( 0 !== stripos( $no_proto, $base_url . '/' ) ) {
            continue;
        }
        $root = realpath( $base[1] );
        $file = realpath( $base[1] . '/' . rawurldecode( substr( $no_proto, strlen( $base_url ) + 1 ) ) );
        if ( $root && $file && is_file( $file ) && 0 === strpos( wp_normalize_path( $file ), trailingslashit( wp_normalize_path( $root ) ) ) ) {
            return $file;
        }
    }
    return '';
}

/**
 * Page-cache evidence from response headers (CDNs, server caches, cache
 * plugins) and from cache-plugin HTML signatures. `hit` is true/false when a
 * header says so, null when unknown.
 */
function att_mcp_page_cache_signals( $headers, $html_comments = array() ) {
    $signals = array();
    $hit     = null;

    $cc = att_mcp_header( $headers, 'cache-control' );
    if ( preg_match( '/(?:^|[,\s])(?:s-maxage|max-age)=(\d+)/i', $cc, $m ) && (int) $m[1] > 0 && false === stripos( $cc, 'private' ) && false === stripos( $cc, 'no-store' ) ) {
        $signals[] = 'cache-control';
    }
    $expires = att_mcp_header( $headers, 'expires' );
    if ( $expires && strtotime( $expires ) > time() ) {
        $signals[] = 'expires';
    }
    if ( (int) att_mcp_header( $headers, 'age' ) > 0 ) {
        $signals[] = 'age';
        $hit       = true;
    }
    $cache_headers = array( 'x-cache', 'x-cache-status', 'x-proxy-cache', 'x-nginx-cache', 'x-fastcgi-cache', 'x-srcache-fetch-status', 'cf-cache-status', 'x-litespeed-cache', 'x-qc-cache', 'x-sucuri-cache', 'x-kinsta-cache', 'x-wpe-cached', 'x-ac', 'x-hcdn-cache-status', 'x-rocket-nginx-serving-static', 'x-wp-super-cache', 'x-varnish', 'x-wp-spc-disk-cache', 'x-wp-cf-super-cache' );
    foreach ( $cache_headers as $name ) {
        $value = strtolower( att_mcp_header( $headers, $name ) );
        if ( '' === $value ) {
            continue;
        }
        $signals[] = $name;
        if ( preg_match( '/\b(hit|stale)\b/', $value ) ) {
            $hit = true;
        } elseif ( null === $hit && preg_match( '/\b(miss|bypass|dynamic|expired|disabled|no-cache)\b/', $value ) ) {
            $hit = false;
        }
    }
    if ( 'true' === strtolower( att_mcp_header( $headers, 'x-cache-enabled' ) ) ) {
        $signals[] = 'x-cache-enabled';
    }

    // Disk-cache plugins often only mark the HTML.
    $markers = array(
        'wp-super-cache'   => '/WP-Super-Cache|Cached page generated by WP-Super-Cache/i',
        'w3-total-cache'   => '/Performance optimized by W3 Total Cache/i',
        'wp-rocket'        => '/This website is like a Rocket|WP Rocket/i',
        'litespeed-cache'  => '/Page (?:cached|optimized) by LiteSpeed Cache/i',
        'wp-fastest-cache' => '/WP Fastest Cache/i',
        'super-page-cache' => '/Super Page Cache|SPC:|swcfpc/i',
        'cache-enabler'    => '/Cache Enabler/i',
    );
    foreach ( (array) $html_comments as $comment ) {
        foreach ( $markers as $plugin => $regex ) {
            if ( preg_match( $regex, $comment ) ) {
                $signals[] = 'html:' . $plugin;
                if ( null === $hit ) {
                    $hit = true;
                }
            }
        }
    }

    return array( 'signals' => array_values( array_unique( $signals ) ), 'hit' => $hit );
}
