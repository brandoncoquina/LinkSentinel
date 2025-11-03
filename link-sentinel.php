<?php
/**
 * Plugin Name:       LinkSentinel
 * Description:       Scan internal links for redirects & breakage. Auto‑fix 301/308 permanently redirected links; queue 302/307 and broken links for review. Includes a dashboard with progress indicators, last scan metadata and a CSV export for resolved links.
 * Version:           3.0 Lite RC
 * Author:            Pragmatic Bear
 * Author URI:        https://www.pragmaticbear.com
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL‑2.0‑or‑later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       link-sentinel
 * Domain Path:       /languages
 */

/*
 * Main bootstrap file for the LinkSentinel plugin.
 *
 * This file sets up plugin defaults, registers hooks and loads the
 * administrative interface.  It is intentionally lean and avoids
 * hard‑coding any environment‑specific configuration.  When introducing
 * new functionality, ensure that the core free features remain intact
 * and that any premium extensions are gated behind capability checks or
 * license keys.
 */

defined( 'ABSPATH' ) || die( 'No direct access allowed.' );

// Define a version constant for internal use.  This value should match the
// version header above and must be updated on each release.
if ( ! defined( 'RFX_VERSION' ) ) {
    define( 'RFX_VERSION', '3.0-lite-rc' );
}

if ( ! defined( 'RFX_DB_VERSION' ) ) {
    define( 'RFX_DB_VERSION', '8' );
}

/**
 * Load the plugin text domain for localization.
 *
 * The text domain is loaded on the `plugins_loaded` hook to ensure that
 * translations are available throughout both the admin and public facing
 * portions of the plugin.  Strings throughout the plugin should use
 * functions such as __( 'string', 'link-sentinel' ) to allow translators
 * to provide localized versions.
 */
function linksentinel_load_textdomain() {
    load_plugin_textdomain( 'link-sentinel', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'linksentinel_load_textdomain' );

/**
 * Plugin activation handler.
 *
 * Creates required tables, applies default settings for manual scanning,
 * and removes any legacy scheduled events from previous releases.
 */
function linksentinel_activate() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'rfx_link_monitor';
    $charset_collate = $wpdb->get_charset_collate();
    $sql             = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        original_url text NOT NULL,
        url_hash char(32) NOT NULL DEFAULT '',
        final_url text,
        http_status int(11) DEFAULT NULL,
        status_message varchar(191) DEFAULT NULL,
        resolution_status varchar(20) NOT NULL DEFAULT 'pending',
        scan_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolution_date datetime DEFAULT NULL,
        resolved_by_user_id bigint(20) unsigned DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY post_id (post_id),
        KEY http_status (http_status),
        KEY resolution_status (resolution_status),
        KEY post_status_hash (post_id, resolution_status, url_hash),
        KEY scan_date (scan_date),
        KEY resolution_date (resolution_date)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Populate url_hash for existing rows in manageable batches so large tables are not locked.
    $batch_size = 500;
    do {
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table_name WHERE url_hash = '' OR url_hash IS NULL LIMIT %d", $batch_size ) );
        if ( empty( $ids ) ) {
            break;
        }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $query        = "UPDATE $table_name SET url_hash = MD5(original_url) WHERE id IN ($placeholders)";
        $wpdb->query( call_user_func_array( [ $wpdb, 'prepare' ], array_merge( [ $query ], $ids ) ) );
    } while ( count( $ids ) === $batch_size );

    update_option( 'rfx_db_version', RFX_DB_VERSION );

    $settings = get_option( 'rfx_settings' );
    if ( ! is_array( $settings ) ) {
        $settings = [];
    }

    $defaults = [
        'post_types'             => [ 'post', 'page' ],
        'auto_resolve_permanent' => 0,
        'scan_batch_size'        => 25,
        'scan_progress_interval' => 10,
    ];

    // Remove deprecated scheduling keys when upgrading.
    unset( $settings['scan_hour'], $settings['scan_minute'] );

    update_option( 'rfx_settings', wp_parse_args( $settings, $defaults ) );

    // Clear any scheduled cron jobs left by legacy versions.
    wp_clear_scheduled_hook( 'rfx_nightly_scan' );
}
register_activation_hook( __FILE__, 'linksentinel_activate' );

/**
 * Plugin deactivation handler.
 *
 * Ensures legacy scheduled events are cleared and temporary scan state
 * flags are removed.
 */
function linksentinel_deactivate() {
    wp_clear_scheduled_hook( 'rfx_nightly_scan' );
    delete_option( 'rfx_manual_scan_active' );
    delete_option( 'rfx_manual_scan_processed' );
    delete_option( 'rfx_manual_scan_total' );
    delete_option( 'rfx_manual_scan_last_id' );
    delete_option( 'rfx_manual_scan_token' );
    delete_option( 'rfx_manual_scan_batch_size' );
    delete_option( 'rfx_manual_scan_progress_interval' );
    delete_option( 'rfx_manual_scan_started_at' );
}
register_deactivation_hook( __FILE__, 'linksentinel_deactivate' );

/**
 * Uninstall hook: remove plugin options but leave logs intact.
 *
 * The uninstall file is separate but if needed you can call this function
 * manually when deleting the plugin.  We intentionally leave the link
 * monitor table to allow users to keep historical data even after
 * deactivating the plugin.
 */
function linksentinel_uninstall() {
    delete_option( 'rfx_scan_last_started' );
    delete_option( 'rfx_scan_last_type' );
    delete_option( 'rfx_settings' );
}

/**
 * Admin asset loader.  Only loads on our plugin screen to avoid polluting
 * unrelated admin pages.
 */
function rfx_admin_enqueue_scripts( $hook ) {
    // Tools screens have hooks like tools_page_link-sentinel.
    if ( strpos( $hook, 'link-sentinel' ) === false ) {
        return;
    }

    /*
     * Use set_url_scheme() to ensure that our asset URLs match the scheme (HTTP or HTTPS) of the current request.
     * Without this, browsers running in HTTPS‑only mode may refuse to load http assets, causing console warnings.
     */
    $base_url = plugin_dir_url( __FILE__ );
    $scheme   = is_ssl() ? 'https' : 'http';
    $base_url = set_url_scheme( $base_url, $scheme );
    $resolve_all_batch = (int) apply_filters( 'rfx_resolve_all_batch_size', 5 );
    $resolve_all_batch = max( 1, min( 50, $resolve_all_batch ) );
    $resolve_all_delay = (int) apply_filters( 'rfx_resolve_all_request_delay_ms', 600 );
    if ( $resolve_all_delay < 0 ) {
        $resolve_all_delay = 0;
    }

    wp_enqueue_script( 'linksentinel-admin', $base_url . 'assets/js/admin-main.js', [ 'jquery' ], RFX_VERSION, true );
    wp_localize_script( 'linksentinel-admin', 'RFXAdmin', [
        'ajax_url'            => admin_url( 'admin-ajax.php' ),
        'nonce'               => wp_create_nonce( 'rfx_start_scan_nonce' ),
        'resolve_all_batch'   => $resolve_all_batch,
        'resolve_all_delay'   => $resolve_all_delay,
    ] );
    wp_enqueue_style( 'linksentinel-admin',  $base_url . 'assets/css/admin.css', [], RFX_VERSION );
}
add_action( 'admin_enqueue_scripts', 'rfx_admin_enqueue_scripts' );

/**
 * Helper to determine if a URL is internal (same domain or a relative path).
 *
 * External links—especially in the context of premium scanning—could be
 * optionally processed in the future, but the free version scans only
 * internal links.  Relative paths like `/sample-page/` are treated as
 * internal by default.
 *
 * @param string $url The URL to examine.
 * @return bool True if internal, false otherwise.
 */
function rfx_is_internal_link( $url ) {
    if ( empty( $url ) ) {
        return false;
    }

    // Handle protocol-relative URLs by adding the current scheme.
    if ( 0 === strpos( $url, '//' ) ) {
        $url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
    }

    // Relative paths are always internal.
    if ( isset( $url[0] ) && '/' === $url[0] ) {
        return true;
    }

    // Only process http/https URLs.
    if ( ! preg_match( '#^https?://#i', $url ) ) {
        return false;
    }

    // Parse site and link components.
    $site_parts = wp_parse_url( home_url() );
    $link_parts = wp_parse_url( $url );

    // Extract hosts, schemes, and ports.
    $site_host   = ! empty( $site_parts['host'] ) ? $site_parts['host'] : '';
    $site_scheme = ! empty( $site_parts['scheme'] ) ? strtolower( $site_parts['scheme'] ) : '';
    $site_port   = ! empty( $site_parts['port'] ) ? (int) $site_parts['port'] : null;

    $link_host   = ! empty( $link_parts['host'] ) ? $link_parts['host'] : '';
    $link_scheme = ! empty( $link_parts['scheme'] ) ? strtolower( $link_parts['scheme'] ) : '';
    $link_port   = ! empty( $link_parts['port'] ) ? (int) $link_parts['port'] : null;

    // A link can't be internal without a host.
    if ( empty( $link_host ) || empty( $site_host ) ) {
        return false;
    }

    // Get a list of all internal hosts.
    $internal_hosts = apply_filters( 'rfx_internal_hosts', [ $site_host ] );
    if ( ! is_array( $internal_hosts ) ) {
        $internal_hosts = [ $site_host ];
    }
    if ( ! in_array( $site_host, $internal_hosts, true ) ) {
        $internal_hosts[] = $site_host;
    }

    // Normalize a host by removing 'www.'
    $normalize_host = function ( $host ) {
        return preg_replace( '/^www\./i', '', strtolower( (string) $host ) );
    };

    // Normalize a port by providing the default for the scheme.
    $normalize_port = function ( $port, $scheme ) {
        if ( null !== $port ) {
            return (int) $port;
        }
        if ( 'https' === $scheme ) {
            return 443;
        }
        if ( 'http' === $scheme ) {
            return 80;
        }
        return null;
    };

    $link_host_normalized = $normalize_host( $link_host );
    $site_host_normalized = $normalize_host( $site_host );

    // Check against each registered internal host.
    foreach ( $internal_hosts as $internal_host ) {
        $internal_host = trim( (string) $internal_host );
        if ( empty( $internal_host ) ) {
            continue;
        }

        $candidate_parts = wp_parse_url( $internal_host );
        if ( empty( $candidate_parts['host'] ) ) {
            $candidate_parts = wp_parse_url( '//' . $internal_host );
        }

        $candidate_host   = ! empty( $candidate_parts['host'] ) ? $candidate_parts['host'] : '';
        $candidate_scheme = ! empty( $candidate_parts['scheme'] ) ? strtolower( $candidate_parts['scheme'] ) : '';
        $candidate_port   = ! empty( $candidate_parts['port'] ) ? (int) $candidate_parts['port'] : null;

        // If the normalized hosts don't match, this isn't an internal link.
        if ( $link_host_normalized !== $normalize_host( $candidate_host ) ) {
            continue;
        }

        $is_primary_host = ( $normalize_host( $candidate_host ) === $site_host_normalized );

        // If both URLs have explicit ports, they must match.
        if ( null !== $candidate_port && null !== $link_port ) {
            if ( $candidate_port !== $link_port ) {
                continue;
            }
        }
        // If the candidate has an explicit port but the link doesn't, check against the default port for the link's scheme.
        elseif ( null !== $candidate_port && null === $link_port ) {
            $default_link_port = $normalize_port( null, $link_scheme ?: $candidate_scheme ?: $site_scheme );
            if ( null !== $default_link_port && $candidate_port !== $default_link_port ) {
                continue;
            }
        }
        // If the link has an explicit port but the candidate is the primary host without one, check against the site's port.
        elseif ( null === $candidate_port && null !== $link_port && $is_primary_host ) {
            if ( $link_port !== $site_port ) {
                continue;
            }
        }

        return true;
    }

    return false;
}

/**
 * Attempt to canonicalize an internal URL using built-in WordPress routing.
 *
 * @param string $url Raw URL (may be relative).
 * @return string|false Canonical absolute URL, or false if unresolved.
 */
function rfx_canonical_internal_url( $url ) {
    if ( ! rfx_is_internal_link( $url ) ) {
        return false;
    }

    $absolute = ( 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) )
        ? $url
        : home_url( $url );

    $home_parts = wp_parse_url( home_url() );
    $url_parts  = wp_parse_url( $absolute );

    if ( empty( $url_parts['host'] ) || ( ! empty( $home_parts['host'] ) && $home_parts['host'] !== $url_parts['host'] ) ) {
        return false;
    }

    // Map to posts/pages/custom post types when possible.
    $post_id = url_to_postid( $absolute );
    if ( $post_id ) {
        $permalink = get_permalink( $post_id );
        if ( $permalink ) {
            return $permalink;
        }
    }

    // Attachments.
    $path = isset( $url_parts['path'] ) ? trim( $url_parts['path'], '/' ) : '';
    if ( '' === $path ) {
        return $absolute;
    }

    $path_bits = explode( '/', $path );
    if ( count( $path_bits ) === 2 && 'attachment' === $path_bits[0] ) {
        $attachment = get_page_by_path( $path_bits[1], OBJECT, 'attachment' );
        if ( $attachment ) {
            $link = get_attachment_link( $attachment );
            if ( $link && ! is_wp_error( $link ) ) {
                return $link;
            }
        }
    }

    // Generic taxonomy resolution with memoized lookups.
    static $public_taxonomies = null;
    static $taxonomy_slug_cache = [];

    if ( null === $public_taxonomies ) {
        $public_taxonomies = get_taxonomies( [ 'public' => true ] );
    }

    $slug = end( $path_bits );
    foreach ( $public_taxonomies as $taxonomy ) {
        if ( ! isset( $taxonomy_slug_cache[ $taxonomy ] ) ) {
            $taxonomy_slug_cache[ $taxonomy ] = [];
        }
        if ( array_key_exists( $slug, $taxonomy_slug_cache[ $taxonomy ] ) ) {
            $term = $taxonomy_slug_cache[ $taxonomy ][ $slug ];
        } else {
            $term = get_term_by( 'slug', $slug, $taxonomy );
            $taxonomy_slug_cache[ $taxonomy ][ $slug ] = $term ? $term : false;
        }
        if ( $term && ! is_wp_error( $term ) ) {
            $term_link = get_term_link( $term );
            if ( ! is_wp_error( $term_link ) ) {
                return $term_link;
            }
        }
    }

    return $absolute;
}

/**
 * Replace an href attribute value within post content, accounting for encoded variants.
 *
 * @param string $content     Original post content.
 * @param string $original    Original href attribute value from the markup.
 * @param string $replacement New URL that should replace the original value.
 * @return string Updated content.
 */
function rfx_replace_href_value( $content, $original, $replacement ) {
    if ( '' === $content || '' === $original ) {
        return $content;
    }

    // Always work with an unescaped value before encoding once for output.
    $replacement_raw   = wp_specialchars_decode( $replacement, ENT_QUOTES );
    $replacement_attr  = esc_attr( $replacement_raw );
    $search_variations = array_unique( array_filter( [
        $original,
        wp_specialchars_decode( $original, ENT_QUOTES ),
    ] ) );

    foreach ( $search_variations as $value ) {
        $quoted   = preg_quote( $value, '/' );
        $patterns = [
            '/href\s*=\s*"' . $quoted . '"/i',
            '/href\s*=\s*\'' . $quoted . '\'/i',
        ];
        foreach ( $patterns as $pattern ) {
            $updated = preg_replace( $pattern, 'href="' . $replacement_attr . '"', $content );
            if ( is_string( $updated ) ) {
                $content = $updated;
            }
        }
    }

    return $content;
}

/**
 * Persist post content changes without triggering standard save_post side effects.
 *
 * The update keeps the original post_modified timestamps intact to avoid polluting
 * content history during automated fixes.
 *
 * @param WP_Post $post        Post object being updated.
 * @param string  $new_content New post content.
 */
function rfx_commit_post_content( $post, $new_content ) {
    if ( ! $post instanceof WP_Post ) {
        return;
    }
    if ( $post->post_content === $new_content ) {
        return;
    }
    $update = [
        'ID'                 => $post->ID,
        'post_content'       => $new_content,
        'post_modified'      => $post->post_modified,
        'post_modified_gmt'  => $post->post_modified_gmt,
        'edit_date'          => true,
    ];

    /*
     * Skip the standard after-save hook stack (save_post, wp_insert_post, etc.)
     * because third-party integrations frequently perform expensive work there.
     * During bulk "Resolve All" operations this overhead compounded across dozens
     * of posts was triggering request timeouts (surfacing as 502 errors in the UI).
     * We still clean the post cache manually so editors see the updated content.
     */
    $result = wp_update_post( wp_slash( $update ), true, false );
    if ( is_wp_error( $result ) ) {
        return;
    }

    clean_post_cache( $post->ID );
    do_action( 'rfx_post_content_updated', $post->ID, $new_content );
}

/**
 * Find the final destination of a URL without blindly following all redirects.
 *
 * We manually follow redirects up to a maximum of 5 hops, capturing the
 * first hop status code to determine whether the redirect is permanent
 * (301/308) or temporary (302/307).  This allows us to auto‑fix content
 * only when a permanent redirect is detected.  Requests are attempted via
 * `wp_remote_head()` first to minimize download size and fall back to
 * `wp_remote_get()` if necessary.  The user agent identifies the plugin
 * name/version.
 *
 * @param string $url The original URL.
 * @return array|false Array with keys: final_url, status_code,
 *                     status_message, first_hop_code, is_permanent.
 */
function rfx_get_final_destination_url( $url ) {
    static $memo = [];

    $cache_key     = md5( (string) $url );
    $transient_key = 'rfx_resolve_' . $cache_key;

    if ( array_key_exists( $cache_key, $memo ) ) {
        return $memo[ $cache_key ];
    }

    $cached = get_transient( $transient_key );
    if ( false !== $cached ) {
        $memo[ $cache_key ] = $cached;
        return $cached;
    }

    $store_and_return = static function ( $value ) use ( &$memo, $cache_key, $transient_key, $url ) {
        $memo[ $cache_key ] = $value;
        if ( $value && is_array( $value ) ) {
            $ttl = (int) apply_filters( 'rfx_resolve_cache_ttl', DAY_IN_SECONDS, $url, $value );
            if ( $ttl > 0 ) {
                set_transient( $transient_key, $value, $ttl );
            }
        }
        return $value;
    };

    $is_internal = rfx_is_internal_link( $url );

    // LinkSentinel 2.5.6-lite-1: skip external redirect following by default
    $follow_external = (bool) apply_filters( 'rfx_follow_external_redirects', false, $url );
    if ( ! $is_internal && ! $follow_external ) {
        return $store_and_return( [
            'final_url'      => esc_url_raw( $url ),
            'status_code'    => 0,
            'status_message' => __( 'External skipped', 'link-sentinel' ),
            'first_hop_code' => null,
            'is_permanent'   => false,
            'origin'         => 'external-skipped',
        ] );
    }


    if ( $is_internal ) {
        $canonical = rfx_canonical_internal_url( $url );
        if ( $canonical ) {
            $final = rfx_is_internal_link( $canonical ) ? wp_make_link_relative( $canonical ) : $canonical;
            $changed = (string) $final !== (string) $url;
            return $store_and_return( [
                'final_url'      => esc_url_raw( $final ),
                'status_code'    => 200,
                'status_message' => __( 'Canonical', 'link-sentinel' ),
                'first_hop_code' => $changed ? 301 : 200,
                'is_permanent'   => $changed,
                'origin'         => 'canonical',
            ] );
        }
    }

    $max_hops = 0;
    if ( $is_internal ) {
        $max_hops = 3;
    } elseif ( $follow_external ) {
        $filtered = (int) apply_filters( 'rfx_external_max_hops', 3, $url );
        $max_hops = max( 0, min( 3, $filtered ) );
    }
    $timeout = (float) apply_filters( 'rfx_remote_request_timeout', $is_internal ? 1.5 : 2.0, $url );
    $timeout        = max( 1, $timeout );
    $current        = $url;
    $current_abs    = ( 0 === strpos( $current, 'http://' ) || 0 === strpos( $current, 'https://' ) ) ? $current : home_url( $current );
    $first_hop_code = null;
    $status_message = '';
    $final_url_abs  = $current_abs;
    $status_code    = 0;

    $headers = [
        'User-Agent' => 'WordPress/LinkSentinel/' . RFX_VERSION,
    ];

    // If we're not following redirects, just get the status of the first hop.
    if ( $max_hops === 0 ) {
        $resp = wp_remote_head( $current_abs, [
            'redirection' => 0,
            'timeout'     => $timeout,
            'headers'     => $headers,
        ] );

        if ( is_wp_error( $resp ) ) {
            return $store_and_return( false );
        }

        $status_code    = (int) wp_remote_retrieve_response_code( $resp );
        $status_message = wp_remote_retrieve_response_message( $resp );
        $first_hop_code = $status_code;
    } else {
        // Otherwise, follow redirects up to the max_hops limit.
        for ( $i = 0; $i < $max_hops; $i++ ) {
            $resp = wp_remote_head(
                $current_abs,
                [
                    'redirection' => 0,
                    'timeout'     => $timeout,
                    'headers'     => $headers,
                ]
            );

            if ( is_wp_error( $resp ) ) {
                return $store_and_return( false );
            }

            $code           = (int) wp_remote_retrieve_response_code( $resp );
            $status_message = wp_remote_retrieve_response_message( $resp );

            if ( null === $first_hop_code ) {
                $first_hop_code = $code;
            }

            if ( $code >= 300 && $code < 400 ) {
                $loc = wp_remote_retrieve_header( $resp, 'location' );
                if ( empty( $loc ) ) {
                    $status_code   = $code;
                    $final_url_abs = $current_abs;
                    break;
                }

                // Resolve relative redirects against the current URL's host.
                if ( 0 !== strpos( $loc, 'http://' ) && 0 !== strpos( $loc, 'https://' ) ) {
                    $parsed = wp_parse_url( $current_abs );
                    if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
                        $final_url_abs = $current_abs;
                        $status_code   = $code;
                        break;
                    }
                    $prefix = $parsed['scheme'] . '://' . $parsed['host'] . ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' );
                    if ( 0 === strpos( $loc, '/' ) ) {
                        $current_abs = $prefix . $loc;
                    } else {
                        $base        = isset( $parsed['path'] ) ? trailingslashit( dirname( $parsed['path'] ) ) : '/';
                        $current_abs = $prefix . '/' . ltrim( $base . $loc, '/' );
                    }
                } else {
                    $current_abs = $loc;
                }
                continue;
            }

            $status_code   = $code;
            $final_url_abs = $current_abs;
            break;
        }
    }

    $final_url = $final_url_abs;
    if ( $is_internal && $final_url_abs ) {
        $final_url = wp_make_link_relative( $final_url_abs );
    }

    $is_permanent = in_array( (int) $first_hop_code, [ 301, 308 ], true );

    return $store_and_return( [
        'final_url'      => esc_url_raw( $final_url ),
        'status_code'    => $status_code,
        'status_message' => $status_message,
        'first_hop_code' => $first_hop_code,
        'is_permanent'   => $is_permanent,
        'origin'         => 'http',
    ] );
}

/**
 * Log a link issue to the database.
 *
 * @param int    $post_id               The post ID containing the link.
 * @param string $original_url          The original URL found in content.
 * @param string $final_url             The final resolved URL (optional).
 * @param int    $http_status           HTTP status code from the request.
 * @param string $status_message        Human-readable status message.
 * @param string $resolution_status     Either 'pending' or 'resolved'.
 * @param int    $resolved_by_user_id   Optional user ID responsible for the resolution.
 */
function rfx_log_link_issue( $post_id, $original_url, $final_url, $http_status, $status_message, $resolution_status = 'pending', $resolved_by_user_id = 0 ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'rfx_link_monitor';

    $data = [
        'post_id'             => absint( $post_id ),
        'original_url'        => $original_url,
        'final_url'           => $final_url,
        'http_status'         => (int) $http_status,
        'status_message'      => $status_message,
        'resolution_status'   => $resolution_status,
        'scan_date'           => current_time( 'mysql' ),
        'resolution_date'     => ( 'resolved' === $resolution_status ? current_time( 'mysql' ) : null ),
        'resolved_by_user_id' => absint( $resolved_by_user_id ),
    ];

    $formats = [ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d' ];

    if ( rfx_link_monitor_supports_hash() ) {
        $data['url_hash'] = rfx_get_url_hash( $original_url );
        $formats[]        = '%s';
    }

    $wpdb->insert( $table_name, $data, $formats );
}

/**
 * Generate a deterministic hash for the supplied URL.
 *
 * @param string $url URL to hash.
 * @return string
 */
function rfx_get_url_hash( $url ) {
    return md5( (string) $url );
}

/**
 * Determine whether the link monitor table has a url_hash column.
 *
 * @return bool
 */
function rfx_link_monitor_supports_hash() {
    static $cache = null;
    if ( null !== $cache ) {
        return $cache;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'rfx_link_monitor';
    $cache = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'url_hash' ) );
    return $cache;
}

/**
 * Retrieve the list of post IDs to scan.
 *
 * Uses plugin settings stored in the `rfx_settings` option to determine
 * which post types to scan.  Defaults to posts and pages.  Only
 * published posts are scanned.
 *
 * @return int[] Array of post IDs.
 */
function rfx_get_scannable_post_ids() {
    global $wpdb;
    $post_types   = rfx_get_scannable_post_types();
    $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
    $sql          = "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders)";
    return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $post_types ) ) );
}

/**
 * Return the sanitized list of post types that should be scanned.
 *
 * @return string[]
 */
function rfx_get_scannable_post_types() {
    $settings   = get_option( 'rfx_settings', [] );
    $post_types = [];

    if ( ! empty( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) {
        foreach ( $settings['post_types'] as $type ) {
            $type = sanitize_key( $type );
            if ( post_type_exists( $type ) ) {
                $post_types[] = $type;
            }
        }
    }

    if ( empty( $post_types ) ) {
        $post_types = [ 'post', 'page' ];
    }

    $post_types = apply_filters( 'rfx_scannable_post_types', $post_types );

    if ( ! is_array( $post_types ) ) {
        $post_types = [ 'post', 'page' ];
    }

    $sanitized = [];
    foreach ( $post_types as $type ) {
        $type = sanitize_key( $type );
        if ( post_type_exists( $type ) ) {
            $sanitized[] = $type;
        }
    }

    if ( empty( $sanitized ) ) {
        $sanitized = [ 'post', 'page' ];
    }

    return array_values( array_unique( $sanitized ) );
}

/**
 * Count the number of posts eligible for scanning.
 *
 * @return int
 */
function rfx_count_scannable_posts() {
    global $wpdb;
    $post_types   = rfx_get_scannable_post_types();
    $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
    $sql          = "SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders)";
    return (int) $wpdb->get_var( $wpdb->prepare( $sql, $post_types ) );
}

/**
 * Retrieve the next batch of post IDs using an ID cursor.
 *
 * @param int $after_id Last processed post ID.
 * @param int $limit    Maximum number of IDs to load.
 * @return int[]
 */
function rfx_get_scannable_post_ids_paged( $after_id, $limit ) {
    global $wpdb;
    $post_types   = rfx_get_scannable_post_types();
    $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
    $params       = array_merge( $post_types, [ (int) $after_id, (int) $limit ] );
    $sql          = "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders) AND ID > %d ORDER BY ID ASC LIMIT %d";
    return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
}

/**
 * Hydrate the current scan state from stored options.
 *
 * @return array
 */
function rfx_get_scan_state() {
    $state = [
        'active'     => (bool) get_option( 'rfx_manual_scan_active', 0 ),
        'total'      => (int) get_option( 'rfx_manual_scan_total', 0 ),
        'processed'  => (int) get_option( 'rfx_manual_scan_processed', 0 ),
        'last_id'    => (int) get_option( 'rfx_manual_scan_last_id', 0 ),
        'batch_size' => (int) get_option( 'rfx_manual_scan_batch_size', 25 ),
        'token'      => (string) get_option( 'rfx_manual_scan_token', '' ),
        'started_at' => (string) get_option( 'rfx_manual_scan_started_at', '' ),
        'progress_interval' => (int) get_option( 'rfx_manual_scan_progress_interval', 10 ),
    ];

    $state['batch_size'] = max( 5, min( 100, $state['batch_size'] ?: 25 ) );
    $state['progress_interval'] = max( 1, min( $state['batch_size'], $state['progress_interval'] ?: 10 ) );

    return $state;
}

/**
 * Clear scan progress and release the single-flight lock.
 */
function rfx_reset_scan_state() {
    delete_transient( 'rfx_manual_scan_lock' );
    update_option( 'rfx_manual_scan_active', 0, false );
    update_option( 'rfx_manual_scan_total', 0, false );
    update_option( 'rfx_manual_scan_processed', 0, false );
    update_option( 'rfx_manual_scan_last_id', 0, false );
    update_option( 'rfx_manual_scan_token', '', false );
    update_option( 'rfx_manual_scan_started_at', '', false );
    update_option( 'rfx_manual_scan_progress_interval', 0, false );
}

/**
 * Mark the current scan as complete and release the transient lock.
 *
 * @param int $total Total number of posts that were part of the scan.
 */
function rfx_complete_scan( $total ) {
    delete_transient( 'rfx_manual_scan_lock' );
    update_option( 'rfx_manual_scan_active', 0, false );
    update_option( 'rfx_manual_scan_processed', max( 0, (int) $total ), false );
    update_option( 'rfx_manual_scan_last_id', 0, false );
    update_option( 'rfx_manual_scan_token', '', false );
    update_option( 'rfx_scan_last_finished', current_time( 'mysql' ), false );
}

/**
 * Process a single post: find internal links, resolve them and log issues.
 *
 * This function uses a simple regex to extract the `href` attribute from
 * anchor tags.  Each unique internal link is resolved via
 * `rfx_get_final_destination_url()`.  If a permanent redirect is detected
 * (first hop code 301 or 308) the content is updated to point to the final
 * URL and the issue is logged as resolved.  Temporary redirects and
 * broken links are logged as pending for manual review.
 *
 * @param int   $post_id Post ID to scan.
 * @param array $context Optional context (settings, hash support, etc.).
 */
function rfx_process_single_post( $post_id, $context = [] ) {
    $post = get_post( $post_id );
    if ( ! $post || empty( $post->post_content ) ) {
        return;
    }

    $content         = $post->post_content;
    $updated_content = $content;

    $settings       = ( isset( $context['settings'] ) && is_array( $context['settings'] ) ) ? $context['settings'] : get_option( 'rfx_settings', [] );
    $auto_resolve   = isset( $context['auto_resolve'] ) ? (bool) $context['auto_resolve'] : ! empty( $settings['auto_resolve_permanent'] );
    $hash_supported = isset( $context['hash_supported'] ) ? (bool) $context['hash_supported'] : rfx_link_monitor_supports_hash();

    // Match href attributes in anchor tags.
    $pattern = '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/iu';
    if ( preg_match_all( $pattern, $content, $matches ) ) {
        $links_found = array_unique( array_filter( $matches[2] ) );
        if ( empty( $links_found ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'rfx_link_monitor';

        foreach ( $links_found as $url ) {
            $url = trim( $url );
            if ( '' === $url || 0 === strpos( $url, '#' ) ) {
                continue;
            }

            $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
            if ( $scheme && ! in_array( strtolower( $scheme ), [ 'http', 'https' ], true ) ) {
                continue;
            }

            if ( ! rfx_is_internal_link( $url ) ) {
                continue;
            }
            // Skip admin/login URLs. We only care about links within posts, pages and media.
            $parsed_path = wp_parse_url( $url, PHP_URL_PATH );
            if ( is_string( $parsed_path ) ) {
                // Normalize to leading slash for relative URLs.
                if ( isset( $parsed_path[0] ) && $parsed_path[0] !== '/' ) {
                    $parsed_path = '/' . $parsed_path;
                }
                if ( 0 === strpos( $parsed_path, '/wp-admin' ) || 0 === strpos( $parsed_path, '/wp-login' ) ) {
                    continue;
                }
            }

            $hash        = $hash_supported ? rfx_get_url_hash( $url ) : '';
            $link_status = rfx_get_final_destination_url( $url );
            if ( ! $link_status ) {
                continue;
            }

            $status       = (int) $link_status['status_code'];
            $first_hop    = isset( $link_status['first_hop_code'] ) ? (int) $link_status['first_hop_code'] : 0;
            $final_url    = $link_status['final_url'];
            $is_redirect  = ( $first_hop >= 300 && $first_hop < 400 );
            $is_permanent = ! empty( $link_status['is_permanent'] );

            // Broken link (4xx/5xx) → pending.  Skip logging if the same issue has already been recorded.
            if ( $status >= 400 ) {
                if ( $hash_supported ) {
                    $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE post_id = %d AND resolution_status = %s AND url_hash = %s LIMIT 1", $post_id, 'pending', $hash ) );
                } else {
                    $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE post_id = %d AND original_url = %s AND resolution_status = %s LIMIT 1", $post_id, $url, 'pending' ) );
                }
                if ( ! $existing_id ) {
                    rfx_log_link_issue( $post_id, $url, '', $status, $link_status['status_message'], 'pending' );
                }
                continue;
            }
            // If the final URL differs from the original, decide whether to auto‑fix.
            if ( $final_url !== $url ) {
                if ( $is_redirect ) {
                    $is_perm = ( $is_permanent && ( 301 === $first_hop || 308 === $first_hop ) );
                    if ( $is_perm && $auto_resolve && $status >= 200 && $status < 400 ) {
                        // Auto‑fix permanent redirect when enabled.  Replace only the attribute value.
                        $updated_content = rfx_replace_href_value( $updated_content, $url, $final_url );
                        // Only log the resolved entry if not already recorded.
                        if ( $hash_supported ) {
                            $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE post_id = %d AND resolution_status = %s AND url_hash = %s LIMIT 1", $post_id, 'resolved', $hash ) );
                        } else {
                            $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE post_id = %d AND original_url = %s AND resolution_status = %s LIMIT 1", $post_id, $url, 'resolved' ) );
                        }
                        if ( ! $existing_id ) {
                            rfx_log_link_issue( $post_id, $url, $final_url, 301, __( 'Auto‑fixed (Permanent Redirect)', 'link-sentinel' ), 'resolved' );
                        }
                    } else {
                        // Treat any 3xx as pending.  Use the first hop code as the HTTP status.
                        if ( $hash_supported ) {
                            $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE post_id = %d AND resolution_status = %s AND url_hash = %s LIMIT 1", $post_id, 'pending', $hash ) );
                        } else {
                            $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE post_id = %d AND original_url = %s AND resolution_status = %s LIMIT 1", $post_id, $url, 'pending' ) );
                        }
                        if ( ! $existing_id ) {
                            // Label message depending on permanence.
                            $msg = $is_perm ? __( 'Permanent Redirect', 'link-sentinel' ) : __( 'Temporary Redirect', 'link-sentinel' );
                            rfx_log_link_issue( $post_id, $url, $final_url, $first_hop, $msg, 'pending' );
                        }
                    }
                } else {
                    // Canonical rewrite or normalized URL (treated as a permanent change).
                    if ( $auto_resolve ) {
                        $updated_content = rfx_replace_href_value( $updated_content, $url, $final_url );
                        if ( $hash_supported ) {
                            $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE post_id = %d AND resolution_status = %s AND url_hash = %s LIMIT 1", $post_id, 'resolved', $hash ) );
                        } else {
                            $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_name} WHERE post_id = %d AND original_url = %s AND resolution_status = %s LIMIT 1", $post_id, $url, 'resolved' ) );
                        }
                        if ( ! $existing_id ) {
                            rfx_log_link_issue( $post_id, $url, $final_url, 200, __( 'Auto‑fixed (Canonicalized)', 'link-sentinel' ), 'resolved' );
                        }
                    }
                }
            }
        }
        // Update post content if any changes were made.
        if ( $updated_content !== $content ) {
            rfx_commit_post_content( $post, $updated_content );
            $post->post_content = $updated_content;
        }
    }
}

/**
 * AJAX callback to start a manual scan.
 */
function rfx_ajax_start_scan() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'link-sentinel' ) ], 403 );
    }

    check_ajax_referer( 'rfx_start_scan_nonce' );

    $state    = rfx_get_scan_state();
    $lock_key = get_transient( 'rfx_manual_scan_lock' );

    if ( $state['active'] ) {
        if ( empty( $lock_key ) ) {
            // Stale lock—reset and allow a new scan to start.
            rfx_reset_scan_state();
            $state = rfx_get_scan_state();
        } else {
            wp_send_json_success( [
                'message'   => __( 'Resuming existing scan.', 'link-sentinel' ),
                'resume'    => true,
                'token'     => $state['token'],
                'total'     => $state['total'],
                'processed' => $state['processed'],
                'batch'     => $state['batch_size'],
            ] );
        }
    }

    $total = rfx_count_scannable_posts();
    if ( 0 === $total ) {
        wp_send_json_success( [
            'message'   => __( 'No content found to scan based on your settings.', 'link-sentinel' ),
            'resume'    => false,
            'total'     => 0,
            'processed' => 0,
        ] );
    }

    $settings          = get_option( 'rfx_settings', [] );
    $batch_size        = isset( $settings['scan_batch_size'] ) ? (int) $settings['scan_batch_size'] : 25;
    $batch_size        = max( 5, min( 100, $batch_size ) );
    $progress_interval = isset( $settings['scan_progress_interval'] ) ? (int) $settings['scan_progress_interval'] : 10;
    $progress_interval = max( 1, min( $batch_size, $progress_interval ) );

    $started_at = current_time( 'mysql' );
    $token      = wp_generate_password( 20, false );

    update_option( 'rfx_scan_last_started', $started_at, false );
    update_option( 'rfx_scan_last_type', 'manual', false );
    update_option( 'rfx_manual_scan_active', 1, false );
    update_option( 'rfx_manual_scan_total', $total, false );
    update_option( 'rfx_manual_scan_processed', 0, false );
    update_option( 'rfx_manual_scan_last_id', 0, false );
    update_option( 'rfx_manual_scan_batch_size', $batch_size, false );
    update_option( 'rfx_manual_scan_progress_interval', $progress_interval, false );
    update_option( 'rfx_manual_scan_token', $token, false );
    update_option( 'rfx_manual_scan_started_at', $started_at, false );

    set_transient( 'rfx_manual_scan_lock', $token, 15 * MINUTE_IN_SECONDS );

    wp_send_json_success( [
        'message'   => __( 'Scan initialized.', 'link-sentinel' ),
        'resume'    => false,
        'token'     => $token,
        'total'     => $total,
        'processed' => 0,
        'batch'     => $batch_size,
    ] );
}

/**
 * AJAX callback to process the next batch of posts in the scan.
 */
function rfx_ajax_step_scan() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'link-sentinel' ) ], 403 );
    }

    check_ajax_referer( 'rfx_start_scan_nonce' );

    $token_param = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
    $state       = rfx_get_scan_state();

    if ( ! $state['active'] ) {
        wp_send_json_success( [
            'message'   => __( 'No active scan.', 'link-sentinel' ),
            'done'      => true,
            'token'     => '',
            'processed' => $state['processed'],
            'total'     => $state['total'],
            'batch'     => $state['batch_size'],
        ] );
    }

    if ( empty( $state['token'] ) || $token_param !== $state['token'] ) {
        wp_send_json_error( [ 'message' => __( 'Invalid scan token.', 'link-sentinel' ) ], 400 );
    }

    set_transient( 'rfx_manual_scan_lock', $state['token'], 15 * MINUTE_IN_SECONDS );

    $batch_size        = $state['batch_size'];
    $progress_interval = $state['progress_interval'];
    $step_started_at   = microtime( true );
    $default_budget    = (float) apply_filters( 'rfx_step_scan_step_budget', 10.0 ); // seconds
    $step_budget       = max( 3.0, $default_budget );
    $min_batch_runtime = (int) apply_filters( 'rfx_step_scan_min_batch', 5 );
    $min_batch_runtime = max( 1, min( $batch_size, $min_batch_runtime ) );

    $ids = rfx_get_scannable_post_ids_paged( $state['last_id'], $batch_size );

    if ( empty( $ids ) ) {
        rfx_complete_scan( $state['total'] );
        wp_send_json_success( [
            'message'   => __( 'Scan complete.', 'link-sentinel' ),
            'done'      => true,
            'token'     => '',
            'processed' => $state['total'],
            'total'     => $state['total'],
            'batch'     => $batch_size,
        ] );
    }

    $settings       = get_option( 'rfx_settings', [] );
    $context        = [
        'settings'       => $settings,
        'auto_resolve'   => ! empty( $settings['auto_resolve_permanent'] ),
        'hash_supported' => rfx_link_monitor_supports_hash(),
    ];
    $processed_step = 0;
    $last_id        = $state['last_id'];

    wp_defer_term_counting( true );
    wp_defer_comment_counting( true );
    wp_suspend_cache_invalidation( true );

    try {
        if ( ! isset( $step_started_at ) || ! is_float( $step_started_at ) ) {
            $step_started_at = microtime( true );
        }
        if ( ! isset( $step_budget ) || $step_budget <= 0 ) {
            $fallback_budget = (float) apply_filters( 'rfx_step_scan_step_budget', 10.0 );
            $step_budget     = max( 3.0, $fallback_budget );
        }
        foreach ( $ids as $post_id ) {
            rfx_process_single_post( (int) $post_id, $context );
            $last_id = (int) $post_id;
            $processed_step++;
            if ( $processed_step % $progress_interval === 0 ) {
                update_option( 'rfx_manual_scan_processed', min( $state['total'], $state['processed'] + $processed_step ), false );
            }
            // LinkSentinel 2.5.6-lite-1: bail early after hitting the time budget, but only once we have processed the minimum slice.
            if ( $processed_step >= $min_batch_runtime && ( microtime( true ) - $step_started_at ) >= $step_budget ) {
                break;
            }
        }
    } finally {
        wp_suspend_cache_invalidation( false );
        wp_defer_comment_counting( false );
        wp_defer_term_counting( false );
    }

    $processed_total = min( $state['total'], $state['processed'] + $processed_step );

    update_option( 'rfx_manual_scan_last_id', $last_id, false );
    update_option( 'rfx_manual_scan_processed', $processed_total, false );

    $done = ( $processed_total >= $state['total'] ) || count( $ids ) < $batch_size;

    if ( $done ) {
        rfx_complete_scan( $state['total'] );
        $response_token = '';
        $response_msg   = __( 'Scan complete.', 'link-sentinel' );
        $processed_total = $state['total'];
    } else {
        $response_token = $state['token'];
        $response_msg   = __( 'Batch processed.', 'link-sentinel' );
    }

    wp_send_json_success( [
        'message'   => $response_msg,
        'done'      => $done,
        'token'     => $response_token,
        'processed' => $processed_total,
        'total'     => $state['total'],
        'batch'     => $batch_size,
        'last_id'   => $last_id,
    ] );
}
add_action( 'wp_ajax_rfx_step_scan', 'rfx_ajax_step_scan' );

/**
 * AJAX callback to resolve a pending redirect immediately.
 *
 * This handler updates the affected post by replacing the original URL
 * with the detected final URL and marks the record as resolved in the
 * monitor table.  Only authenticated users with manage_options can
 * perform this action.  A generic nonce is used for CSRF protection.
 */
function rfx_ajax_resolve_link() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'link-sentinel' ) ], 403 );
    }
    // Validate request parameters.
    $id    = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! $id || ! wp_verify_nonce( $nonce, 'rfx_resolve_link_nonce' ) ) {
        wp_send_json_error( [ 'message' => __( 'Invalid request.', 'link-sentinel' ) ] );
    }
    global $wpdb;
    $table_name = $wpdb->prefix . 'rfx_link_monitor';
    // Fetch the pending record.
    $record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d AND resolution_status = %s", $id, 'pending' ), ARRAY_A );
    if ( ! $record ) {
        wp_send_json_error( [ 'message' => __( 'Record not found or already resolved.', 'link-sentinel' ) ] );
    }
    // Ensure there is a final URL to replace with.
    if ( empty( $record['final_url'] ) ) {
        wp_send_json_error( [ 'message' => __( 'This link does not have a detected URL to resolve to.', 'link-sentinel' ) ] );
    }
    // Update the post content: replace the original URL with the final URL in the href attribute.
    $post        = get_post( $record['post_id'] );
    if ( $post && ! empty( $post->post_content ) ) {
        $updated      = rfx_replace_href_value( $post->post_content, $record['original_url'], $record['final_url'] );
        // Only update if changes were made.
        if ( $updated !== $post->post_content && '' !== $updated ) {
            rfx_commit_post_content( $post, $updated );
            $post->post_content = $updated;
        }
    }
    // Mark the record as resolved.  Include the current user in the status message
    // for accountability.  Use display name when available.
    $current_user        = wp_get_current_user();
    $resolved_by         = '';
    $resolved_by_user_id = 0;
    if ( $current_user && $current_user->exists() ) {
        $resolved_by_user_id = $current_user->ID;
        $resolved_by         = $current_user->display_name ? $current_user->display_name : $current_user->user_login;
    }
    $message_suffix = $resolved_by ? sprintf( /* translators: %s: user name */ __( 'Manually Resolved by %s', 'link-sentinel' ), $resolved_by ) : __( 'Manually Resolved', 'link-sentinel' );
    $wpdb->update(
        $table_name,
        [
            'resolution_status' => 'resolved',
            'resolution_date'   => current_time( 'mysql' ),
            'status_message'    => $message_suffix,
            'resolved_by_user_id' => $resolved_by_user_id,
        ],
        [ 'id' => $id ],
        [ '%s', '%s', '%s', '%d' ],
        [ '%d' ]
    );
    wp_send_json_success( [ 'message' => __( 'Link resolved successfully.', 'link-sentinel' ) ] );
}
add_action( 'wp_ajax_rfx_resolve_link', 'rfx_ajax_resolve_link' );

/**
 * AJAX callback to resolve all pending redirects in bulk.
 *
 * This handler resolves pending redirects in small batches to avoid long-running
 * PHP requests on large sites.  Each request processes up to `$batch` records and
 * returns a cursor that the caller can supply on the next invocation.  A transient
 * lock ensures only one bulk resolve runs at a time, and a token passed between
 * requests prevents concurrent sessions from colliding.
 */
function rfx_ajax_resolve_all() {
    // --- LinkSentinel 2.5.6-lite-1: keep each step within a time budget ---
    $step_started_at = microtime( true );
    $default_budget  = (float) apply_filters( 'rfx_resolve_all_step_budget', 12.0 ); // seconds
    $step_budget     = max( 5.0, $default_budget );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'link-sentinel' ) ], 403 );
    }
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! $nonce || ! wp_verify_nonce( $nonce, 'rfx_resolve_all_nonce' ) ) {
        wp_send_json_error( [ 'message' => __( 'Invalid request.', 'link-sentinel' ) ] );
    }

    $default_batch = (int) apply_filters( 'rfx_resolve_all_batch_size', 8 );
    $default_batch = max( 1, min( 50, $default_batch ) );
    $batch_input   = isset( $_POST['batch'] ) ? absint( wp_unslash( $_POST['batch'] ) ) : $default_batch;
    $batch         = max( 1, min( 50, $batch_input ) );
    $cursor = isset( $_POST['cursor'] ) ? absint( wp_unslash( $_POST['cursor'] ) ) : 0;
    $token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
    $total  = isset( $_POST['total'] ) ? absint( wp_unslash( $_POST['total'] ) ) : 0;
    $processed_so_far = isset( $_POST['processed'] ) ? absint( wp_unslash( $_POST['processed'] ) ) : 0;

    $lock_key = 'rfx_resolve_all_lock';
    $lock     = get_transient( $lock_key );

    if ( '' === $token ) {
        if ( ! empty( $lock ) ) {
            wp_send_json_error( [ 'message' => __( 'Another bulk resolve is already running. Please wait for it to finish.', 'link-sentinel' ) ] );
        }
        $token = wp_generate_password( 20, false );
    } else {
        if ( empty( $lock ) || $lock !== $token ) {
            wp_send_json_error( [ 'message' => __( 'Bulk resolve session has expired. Please try again.', 'link-sentinel' ) ] );
        }
    }

    // Refresh the lock for the active session.
    set_transient( $lock_key, $token, 3 * MINUTE_IN_SECONDS );

    global $wpdb;
    $table_name = $wpdb->prefix . 'rfx_link_monitor';

    if ( ! $total ) {
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(id) FROM $table_name WHERE resolution_status = %s AND final_url <> '' AND ( http_status < %d OR http_status IS NULL )",
                'pending',
                400
            )
        );
        if ( 0 === $total ) {
            delete_transient( $lock_key );
            wp_send_json_success( [
                'message'   => __( 'No pending redirects to resolve.', 'link-sentinel' ),
                'done'      => true,
                'token'     => '',
                'cursor'    => $cursor,
                'processed' => $processed_so_far,
                'total'     => 0,
            ] );
        }
    }

    $records = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, post_id, original_url, final_url FROM $table_name WHERE resolution_status = %s AND final_url <> '' AND ( http_status < %d OR http_status IS NULL ) AND id > %d ORDER BY id ASC LIMIT %d",
            'pending',
            400,
            $cursor,
            $batch
        ),
        ARRAY_A
    );

    if ( empty( $records ) ) {
        delete_transient( $lock_key );
        wp_send_json_success( [
            'message'   => __( 'All pending redirects have been resolved.', 'link-sentinel' ),
            'done'      => true,
            'token'     => '',
            'cursor'    => $cursor,
            'processed' => $processed_so_far,
            'total'     => $total,
        ] );
    }

    $records_count = count( $records );

    $current_user        = wp_get_current_user();
    $resolved_by_user_id = ( $current_user && $current_user->exists() ) ? $current_user->ID : 0;
    $resolved_by_name    = '';
    if ( $resolved_by_user_id && $current_user ) {
        $resolved_by_name = $current_user->display_name ? $current_user->display_name : $current_user->user_login;
    }
    $message_suffix = $resolved_by_name
        ? sprintf( /* translators: %s: user name */ __( 'Manually Resolved by %s', 'link-sentinel' ), $resolved_by_name )
        : __( 'Manually Resolved', 'link-sentinel' );

    $processed_step = 0;
    $last_id        = $cursor;
    $hit_budget     = false;

    wp_defer_term_counting( true );
    wp_defer_comment_counting( true );
    wp_suspend_cache_invalidation( true );

    try {
        foreach ( $records as $record ) {
            $post_id = (int) $record['post_id'];
            if ( $post_id <= 0 ) {
                $last_id = (int) $record['id'];
                continue;
            }
            $post = get_post( $post_id );
            if ( $post && ! empty( $post->post_content ) ) {
                $updated = rfx_replace_href_value( $post->post_content, $record['original_url'], $record['final_url'] );
                if ( $updated !== $post->post_content && '' !== $updated ) {
                    rfx_commit_post_content( $post, $updated );
                    $post->post_content = $updated;
                }
            }
            $wpdb->update(
                $table_name,
                [
                    'resolution_status'   => 'resolved',
                    'resolution_date'     => current_time( 'mysql' ),
                    'status_message'      => $message_suffix,
                    'resolved_by_user_id' => $resolved_by_user_id,
                ],
                [ 'id' => $record['id'] ],
                [ '%s', '%s', '%s', '%d' ],
                [ '%d' ]
            );
            $processed_step++;

            // --- LinkSentinel 2.5.6-lite-1: bail early if we hit the time budget ---
            if ( ( microtime( true ) - $step_started_at ) >= $step_budget ) {
                $hit_budget = true;
                break;
            }
            $last_id = (int) $record['id'];
        }
    } finally {
        wp_suspend_cache_invalidation( false );
        wp_defer_comment_counting( false );
        wp_defer_term_counting( false );
    }

    $processed_total    = $processed_so_far + $processed_step;
    $last_step_seconds  = round( microtime( true ) - $step_started_at, 3 );
    $done               = false;
    $next_batch         = 0;

    if ( $total > 0 ) {
        $processed_total = min( $processed_total, $total );
    }

    if ( $processed_total >= $total ) {
        $done = true;
    } elseif ( ! $hit_budget && $records_count < $batch ) {
        $done = true;
    }

    if ( ! $done ) {
        if ( $last_step_seconds >= 0.9 * $step_budget && $batch > 1 ) {
            $next_batch = max( 1, (int) floor( $batch / 2 ) );
        } elseif ( $last_step_seconds > 0 && $last_step_seconds <= 0.5 * $step_budget && $batch < 50 ) {
            $next_batch = min( 50, (int) ceil( $batch + 1 ) );
        }
    }

    if ( $done ) {
        delete_transient( $lock_key );
    }

    wp_send_json_success( [
        'message'        => $done
            ? __( 'All pending redirects have been resolved.', 'link-sentinel' )
            : __( 'Resolving pending redirects…', 'link-sentinel' ),
        'done'           => $done,
        'token'          => $done ? '' : $token,
        'cursor'         => $last_id,
        'processed_step' => $processed_step,
        'processed'      => $processed_total,
        'total'          => $total,
        'last_step_seconds' => $last_step_seconds,
        'step_budget'       => $step_budget,
        'next_batch'        => $next_batch,
    ] );
}
add_action( 'wp_ajax_rfx_resolve_all', 'rfx_ajax_resolve_all' );

/**
 * AJAX callback to change a broken link manually.
 *
 * Allows administrators to supply a new URL for a broken link.  The original
 * URL in the post content is replaced with the supplied URL, and the log
 * entry is marked as resolved.  This handler accepts three POST fields:
 * `id` (the database record ID), `nonce` for CSRF protection, and
 * `new_url`, which must be a valid URL.  If the operation is successful,
 * the post content is updated and the row removed from the broken links
 * table on page reload.
 */
function rfx_ajax_change_link() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'link-sentinel' ) ], 403 );
    }
    $id      = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
    $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    $new_url = isset( $_POST['new_url'] ) ? trim( wp_unslash( $_POST['new_url'] ) ) : '';
    if ( ! $id || ! $nonce || ! $new_url ) {
        wp_send_json_error( [ 'message' => __( 'Missing data.', 'link-sentinel' ) ] );
    }
    if ( ! wp_verify_nonce( $nonce, 'rfx_change_link_nonce' ) ) {
        wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'link-sentinel' ) ] );
    }
    /*
     * Validate the new URL.  Accept absolute URLs as well as relative
     * slugs (paths beginning with '/').  If the provided value is a
     * relative path, we treat it as an internal URL and will store it
     * exactly as provided.  Otherwise we require a well‑formed URL.
     */
    $is_relative = false;
    if ( '/' === substr( $new_url, 0, 1 ) ) {
        // Prepend home_url for validation, but store the slug as is.
        $tmp_url     = home_url( $new_url );
        $is_relative = true;
    } else {
        $tmp_url = $new_url;
    }
    $sanitized_url = esc_url_raw( $tmp_url );
    if ( ! filter_var( $sanitized_url, FILTER_VALIDATE_URL ) ) {
        wp_send_json_error( [ 'message' => __( 'Please provide a valid URL or slug.', 'link-sentinel' ) ] );
    }
    // If relative, use the original slug value; otherwise use sanitized absolute URL.
    $new_url = $is_relative ? $new_url : $sanitized_url;
    global $wpdb;
    $table_name = $wpdb->prefix . 'rfx_link_monitor';
    // Retrieve the record; it must be pending (broken) and have status >= 400.
    $record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d AND resolution_status = %s AND http_status >= %d", $id, 'pending', 400 ), ARRAY_A );
    if ( ! $record ) {
        wp_send_json_error( [ 'message' => __( 'Record not found or not eligible for change.', 'link-sentinel' ) ] );
    }
    // Retrieve the post but defer updating its content until the new URL is validated.
    $post = get_post( $record['post_id'] );
    /*
     * Determine the HTTP status of the provided URL.  We call the same
     * helper used during scans to inspect the first hop without
     * automatically following redirects.  Based on the response we
     * decide whether to mark the entry as resolved, pending or leave it
     * unchanged.  A status of 200–299 is considered valid and will
     * update the post content.  Any 3xx status (301/302/307/308) is
     * flagged as a pending redirect.  Any 4xx/5xx status or network
     * error is treated as invalid and the update is aborted with an
     * error message.
     */
    $link_status = rfx_get_final_destination_url( $tmp_url );
    if ( ! $link_status ) {
        wp_send_json_error( [ 'message' => __( 'Unable to fetch the provided URL. Please try a different link.', 'link-sentinel' ) ] );
    }
    $status_code    = (int) $link_status['status_code'];
    $first_hop_code = (int) $link_status['first_hop_code'];
    // If the response is a redirect (3xx), flag as pending and do not update the post content.
    if ( $first_hop_code >= 300 && $first_hop_code < 400 ) {
        // Update the record: set final_url and http_status to the first hop code, mark as pending.
        $wpdb->update(
            $table_name,
            [
                'final_url'         => $new_url,
                'http_status'       => $first_hop_code,
                'resolution_status' => 'pending',
                'status_message'    => __( 'Temporary Redirect', 'link-sentinel' ),
                'resolution_date'   => null,
                'resolved_by_user_id' => 0,
            ],
            [ 'id' => $record['id'] ],
            [ '%s', '%d', '%s', '%s', '%s', '%d' ],
            [ '%d' ]
        );
        wp_send_json_success( [ 'message' => __( 'The new URL redirects.  It has been flagged for review as a pending redirect.', 'link-sentinel' ) ] );
    }
    // If the response status is >= 400, treat as broken and abort.
    if ( $status_code >= 400 ) {
        wp_send_json_error( [ 'message' => sprintf( __( 'The provided URL returned a %d status and cannot be used.  Please choose a valid link.', 'link-sentinel' ), $status_code ) ] );
    }
    // Otherwise (status 2xx) we can update the link.
    // Update the post content with the new URL if it changed.
    if ( $post && ! empty( $post->post_content ) ) {
        $updated = rfx_replace_href_value( $post->post_content, $record['original_url'], $new_url );
        if ( $updated !== $post->post_content && '' !== $updated ) {
            rfx_commit_post_content( $post, $updated );
            $post->post_content = $updated;
        }
    }
    // Update the record to resolved with status 200.  Include user info in the
    // status message for accountability.
    $current_user = wp_get_current_user();
    $updated_by   = '';
    $resolved_by_user_id = 0;
    if ( $current_user && $current_user->exists() ) {
        $resolved_by_user_id = $current_user->ID;
        $updated_by = $current_user->display_name ? $current_user->display_name : $current_user->user_login;
    }
    $msg = $updated_by ? sprintf( /* translators: %s: user name */ __( 'Manually Updated by %s', 'link-sentinel' ), $updated_by ) : __( 'Manually Updated', 'link-sentinel' );
    $wpdb->update(
        $table_name,
        [
            'final_url'         => $new_url,
            'http_status'       => 200,
            'resolution_status' => 'resolved',
            'status_message'    => $msg,
            'resolution_date'   => current_time( 'mysql' ),
            'resolved_by_user_id' => $resolved_by_user_id,
        ],
        [ 'id' => $record['id'] ],
        [ '%s', '%d', '%s', '%s', '%s', '%d' ],
        [ '%d' ]
    );
    wp_send_json_success( [ 'message' => __( 'Link updated successfully.', 'link-sentinel' ) ] );
}
add_action( 'wp_ajax_rfx_change_link', 'rfx_ajax_change_link' );
add_action( 'wp_ajax_rfx_start_scan', 'rfx_ajax_start_scan' );

/**
 * Admin asset loader.  Only loads on our plugin screen to avoid polluting
 * unrelated admin pages.
 */
function rfx_admin_enqueue_scripts( $hook ) {
    // Tools screens have hooks like tools_page_link-sentinel.
    if ( strpos( $hook, 'link-sentinel' ) === false ) {
        return;
    }

    /*
     * Use set_url_scheme() to ensure that our asset URLs match the scheme (HTTP or HTTPS) of the current request.
     * Without this, browsers running in HTTPS‑only mode may refuse to load http assets, causing console warnings.
     */
    $base_url = plugin_dir_url( __FILE__ );
    $scheme   = is_ssl() ? 'https' : 'http';
    $base_url = set_url_scheme( $base_url, $scheme );
    $resolve_all_batch = (int) apply_filters( 'rfx_resolve_all_batch_size', 5 );
    $resolve_all_batch = max( 1, min( 50, $resolve_all_batch ) );
    $resolve_all_delay = (int) apply_filters( 'rfx_resolve_all_request_delay_ms', 600 );
    if ( $resolve_all_delay < 0 ) {
        $resolve_all_delay = 0;
    }

    wp_enqueue_script( 'linksentinel-admin', $base_url . 'assets/js/admin-main.js', [ 'jquery' ], RFX_VERSION, true );
    wp_localize_script( 'linksentinel-admin', 'RFXAdmin', [
        'ajax_url'            => admin_url( 'admin-ajax.php' ),
        'nonce'               => wp_create_nonce( 'rfx_start_scan_nonce' ),
        'resolve_all_batch'   => $resolve_all_batch,
        'resolve_all_delay'   => $resolve_all_delay,
    ] );
    wp_enqueue_style( 'linksentinel-admin',  $base_url . 'assets/css/admin.css', [], RFX_VERSION );
}
add_action( 'admin_enqueue_scripts', 'rfx_admin_enqueue_scripts' );

/**
 * AJAX callback to report scan progress.
 */
function rfx_ajax_scan_status() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Permission denied.', 'link-sentinel' ) ], 403 );
    }

    check_ajax_referer( 'rfx_start_scan_nonce' );

    $state    = rfx_get_scan_state();
    $running  = $state['active'];
    $total    = (int) $state['total'];
    $processed_count = (int) $state['processed'];

    if ( $running && 0 === $total ) {
        $total = rfx_count_scannable_posts();
        update_option( 'rfx_manual_scan_total', $total, false );
    }

    $processed = ( $total > 0 ) ? min( $processed_count, $total ) : $processed_count;
    $remaining = ( $total > 0 ) ? max( 0, $total - $processed ) : 0;

    $message = $running
        ? sprintf( __( 'Scanning... %1$d of %2$d processed', 'link-sentinel' ), $processed, $total )
        : __( 'No active scans.', 'link-sentinel' );

    wp_send_json_success( [
        'running'     => $running,
        'total_posts' => $total,
        'processed'   => $processed,
        'pending'     => 0,
        'in_progress' => $running ? min( $state['batch_size'], $remaining ) : 0,
        'remaining'   => $remaining,
        'message'     => $message,
        'token'       => $running ? $state['token'] : '',
        'batch'       => $state['batch_size'],
    ] );
}
add_action( 'wp_ajax_rfx_scan_status', 'rfx_ajax_scan_status' );

/**
 * Register our admin page under Tools → LinkSentinel.
 */
function rfx_register_admin_menu() {
    add_management_page(
        __( 'LinkSentinel', 'link-sentinel' ),
        __( 'LinkSentinel', 'link-sentinel' ),
        'manage_options',
        'link-sentinel',
        'rfx_render_dashboard'
    );
}
add_action( 'admin_menu', 'rfx_register_admin_menu', 9 );

/**
 * Redirect legacy slugs and clean up old submenu entries.
 */
function rfx_admin_legacy_redirect() {
    if ( isset( $_GET['page'] ) && 'link-health-monitor' === $_GET['page'] ) {
        wp_safe_redirect( admin_url( 'tools.php?page=link-sentinel' ) );
        exit;
    }
}
add_action( 'admin_init', 'rfx_admin_legacy_redirect' );
add_action( 'admin_menu', function () {
    // Remove any leftover submenu registered under the old slug.
    remove_submenu_page( 'tools.php', 'link-health-monitor' );
}, 999 );

/**
 * Handle saving plugin settings from the settings tab.
 *
 * This function processes form submissions from the settings tab and
 * updates the `rfx_settings` option.  Manual mode no longer manages a
 * schedule, so only the post type list and auto-resolve toggle are saved.
 */
function rfx_save_settings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Permission denied.', 'link-sentinel' ) );
    }
    if ( ! isset( $_POST['rfx_settings_nonce'] ) || ! wp_verify_nonce( $_POST['rfx_settings_nonce'], 'rfx_save_settings' ) ) {
        wp_die( __( 'Invalid nonce.', 'link-sentinel' ) );
    }

    // Post types selected (array of slugs).
    $post_types = [];
    if ( isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ) {
        foreach ( $_POST['post_types'] as $pt ) {
            $slug = sanitize_key( $pt );
            if ( post_type_exists( $slug ) ) {
                $post_types[] = $slug;
            }
        }
    }
    if ( empty( $post_types ) ) {
        // Fallback to posts and pages if nothing selected.
        $post_types = [ 'post', 'page' ];
    }
    $post_types = array_values( array_unique( $post_types ) );

    // Determine whether automatic resolution of permanent redirects is enabled.
    $auto_resolve = ( isset( $_POST['auto_resolve_permanent'] ) && $_POST['auto_resolve_permanent'] ) ? 1 : 0;

    $settings = [
        'post_types'             => $post_types,
        'auto_resolve_permanent' => $auto_resolve,
    ];
    update_option( 'rfx_settings', $settings );

    $follow_external = ( isset( $_POST['follow_external_redirects'] ) && (int) $_POST['follow_external_redirects'] === 1 ) ? '1' : '0';
    update_option( 'rfx_follow_external_redirects', $follow_external );

    // Redirect back to the plugin page with a success flag.
    wp_safe_redirect( add_query_arg( 'settings-updated', 'true', admin_url( 'tools.php?page=link-sentinel#settings' ) ) );
    exit;
}
add_action( 'admin_post_rfx_save_settings', 'rfx_save_settings' );

/**
 * Base list table class to display resolved links with scope (current or previous scans).
 */
if ( ! class_exists( 'RFX_Resolved_Links_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
    class RFX_Resolved_Links_List_Table extends WP_List_Table {
        protected $scope = 'all';
        public function __construct() {
            parent::__construct( [
                'singular' => 'resolved_link',
                'plural'   => 'resolved_links',
                'ajax'     => false,
            ] );
        }
        /**
         * Set the scope for the table.  Accepts 'current', 'previous', or 'all'.
         *
         * @param string $scope Scope string.
         */
        public function set_scope( $scope ) {
            $this->scope = in_array( $scope, [ 'current', 'previous', 'all' ], true ) ? $scope : 'all';
        }
        public function get_columns() {
            return [
                'original_url'    => __( 'Original URL', 'link-sentinel' ),
                'final_url'       => __( 'Corrected To', 'link-sentinel' ),
                'status_message'  => __( 'Action Taken', 'link-sentinel' ),
                'resolved_by'     => __( 'Resolved By', 'link-sentinel' ),
                'post_id'         => __( 'Found In', 'link-sentinel' ),
                'resolution_date' => __( 'Date Corrected', 'link-sentinel' ),
            ];
        }
        public function get_sortable_columns() {
            return [ 'resolution_date' => [ 'resolution_date', true ] ];
        }
        public function column_default( $item, $column_name ) {
            switch ( $column_name ) {
                case 'original_url':
                    return '<code>' . esc_html( $item['original_url'] ) . '</code>';
                case 'final_url':
                    return '<code>' . esc_html( $item['final_url'] ) . '</code>';
                case 'status_message':
                    return esc_html( $item['status_message'] );
                case 'resolved_by':
                    $uid = isset( $item['resolved_by_user_id'] ) ? (int) $item['resolved_by_user_id'] : 0;
                    if ( $uid > 0 ) {
                        $user = get_userdata( $uid );
                        if ( $user ) {
                            $name = $user->display_name ?: $user->user_login;
                            $url  = get_edit_user_link( $uid );
                            return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $name ) );
                        }
                    }
                    return '—';
                case 'post_id':
                    $post_title = get_the_title( $item['post_id'] );
                    if ( empty( $post_title ) ) {
                        $post_title = sprintf( __( 'Post #%d', 'link-sentinel' ), $item['post_id'] );
                    }
                    $edit_link = get_edit_post_link( $item['post_id'] );
                    return sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html( $post_title ) );
                case 'resolution_date':
                    /*
                     * Display a dash when the resolution date is empty or contains a
                     * zeroed value.  WordPress and MySQL can sometimes return
                     * '0000-00-00 00:00:00' or other zero dates for uninitialized
                     * datetime columns.  Trim the value and check the prefix to
                     * catch any such placeholder.  If a valid timestamp exists,
                     * return it verbatim (escaped for output).
                     */
                    $date = isset( $item['resolution_date'] ) ? trim( $item['resolution_date'] ) : '';
                    if ( empty( $date ) ) {
                        return '&mdash;';
                    }
                    // Treat any date string starting with '0000-00-00' as empty.
                    if ( 0 === strpos( $date, '0000-00-00' ) ) {
                        return '&mdash;';
                    }
                    return esc_html( $date );
                default:
                    return '';
            }
        }
        public function no_items() {
            esc_html_e( 'No resolved items yet.', 'link-sentinel' );
        }
        public function prepare_items() {
            global $wpdb;
            $table_name = $wpdb->prefix . 'rfx_link_monitor';
            $per_page   = 20;
            $order     = ( isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'ASC' : 'DESC';
            $current_page = $this->get_pagenum();
            $last_started = get_option( 'rfx_scan_last_started' );
            $where_base  = "resolution_status = %s";
            $params_base = [ 'resolved' ];
            $where       = $where_base;
            $params      = $params_base;
            if ( 'current' === $this->scope && ! empty( $last_started ) ) {
                $where  = $where_base . ' AND resolution_date IS NOT NULL AND resolution_date >= %s';
                $params = array_merge( $params_base, [ $last_started ] );
            } elseif ( 'previous' === $this->scope && ! empty( $last_started ) ) {
                $where  = $where_base . ' AND (resolution_date IS NULL OR resolution_date < %s)';
                $params = array_merge( $params_base, [ $last_started ] );
            }
            // Total items
            $total_items = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table_name WHERE $where", $params ) );
            $this->set_pagination_args( [ 'total_items' => $total_items, 'per_page' => $per_page ] );
            $offset = ( $current_page - 1 ) * $per_page;
            // Retrieve items for the current page.
            $items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE $where ORDER BY resolution_date $order LIMIT %d OFFSET %d", array_merge( $params, [ $per_page, $offset ] ) ), ARRAY_A );
            $this->items = $items;
            $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
        }
    }
}

/**
 * Pending links list table.
 */
if ( ! class_exists( 'RFX_Pending_Links_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
    class RFX_Pending_Links_List_Table extends WP_List_Table {
        public function __construct() {
            parent::__construct( [
                'singular' => 'pending_link',
                'plural'   => 'pending_links',
                'ajax'     => false,
            ] );
        }
        public function get_columns() {
            return [
                'post_id'       => __( 'Post', 'link-sentinel' ),
                'original_url'  => __( 'Original URL', 'link-sentinel' ),
                'final_url'     => __( 'Detected URL', 'link-sentinel' ),
                'http_status'   => __( 'HTTP', 'link-sentinel' ),
                'status_message'=> __( 'Note', 'link-sentinel' ),
                'scan_date'     => __( 'Date Scanned', 'link-sentinel' ),
            ];
        }
        public function get_sortable_columns() {
            return [ 'scan_date' => [ 'scan_date', true ] ];
        }
        public function column_default( $item, $column_name ) {
            switch ( $column_name ) {
                case 'post_id':
                    $post_title = get_the_title( $item['post_id'] );
                    if ( empty( $post_title ) ) {
                        $post_title = sprintf( __( 'Post #%d', 'link-sentinel' ), $item['post_id'] );
                    }
                    $edit_link = get_edit_post_link( $item['post_id'] );
                    return sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html( $post_title ) );
                case 'original_url':
                    return '<code>' . esc_html( $item['original_url'] ) . '</code>';
                case 'final_url':
                    return ! empty( $item['final_url'] ) ? '<code>' . esc_html( $item['final_url'] ) . '</code>' : '';
                case 'http_status':
                    return esc_html( $item['http_status'] );
                case 'status_message':
                    return esc_html( $item['status_message'] );
                case 'scan_date':
                    return esc_html( $item['scan_date'] );
                default:
                    return '';
            }
        }
        public function no_items() {
            esc_html_e( 'Nothing to review. Great job!', 'link-sentinel' );
        }
        public function prepare_items() {
            global $wpdb;
            $table_name   = $wpdb->prefix . 'rfx_link_monitor';
            $per_page     = 20;
            $order        = ( isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'ASC' : 'DESC';
            $current_page = $this->get_pagenum();
            /*
             * Only include pending items that have not been resolved and have HTTP status codes below 400.
             * Broken links (status >= 400) are displayed exclusively in the Broken Links table.
             */
            $total_items  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table_name WHERE resolution_status = %s AND ( http_status < %d OR http_status IS NULL )", 'pending', 400 ) );
            $this->set_pagination_args( [ 'total_items' => $total_items, 'per_page' => $per_page ] );
            $offset       = ( $current_page - 1 ) * $per_page;
            $items        = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE resolution_status = %s AND ( http_status < %d OR http_status IS NULL ) ORDER BY scan_date $order LIMIT %d OFFSET %d",
                    'pending', 400, $per_page, $offset
                ),
                ARRAY_A
            );
            $this->items = $items;
            $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
        }

        /**
         * Custom column output for the original URL column.
         *
         * Displays the original URL as code and adds a "Resolve Now" row action
         * when there is a detected final URL available.  The action passes
         * the record ID and a nonce via data attributes for the JS handler.
         *
         * @param array  $item The current row data.
         * @return string HTML output for the column.
         */
        public function column_original_url( $item ) {
            $value   = '<code>' . esc_html( $item['original_url'] ) . '</code>';
            $actions = [];
            // Only show a resolve action if we have a final URL to replace with.
            if ( ! empty( $item['final_url'] ) ) {
                // Use a global nonce for all rows; verification happens server side.
                $nonce = wp_create_nonce( 'rfx_resolve_link_nonce' );
                $actions['resolve'] = sprintf(
                    '<a href="#" class="rfx-resolve-link" data-id="%1$s" data-nonce="%2$s">%3$s</a>',
                    esc_attr( $item['id'] ),
                    esc_attr( $nonce ),
                    esc_html__( 'Resolve Now', 'link-sentinel' )
                );
            }
            return $value . $this->row_actions( $actions );
        }
    }
}

/**
 * Broken links list table.
 *
 * Displays only pending items where the HTTP status code is 400 or greater,
 * which indicates a client or server error.  Broken links require manual
 * intervention from the user.  Columns largely mirror the pending table
 * but omit the 'Detected URL' column since the final URL may be blank.
 */
if ( ! class_exists( 'RFX_Broken_Links_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
    class RFX_Broken_Links_List_Table extends WP_List_Table {
        public function __construct() {
            parent::__construct( [
                'singular' => 'broken_link',
                'plural'   => 'broken_links',
                'ajax'     => false,
            ] );
        }
        public function get_columns() {
            /*
             * We insert a dedicated Change Link column immediately to the right of
             * the Original URL column.  This provides a consistent location for the
             * inline editing UI.  The order here determines column order on screen.
             */
            return [
                'post_id'       => __( 'Post', 'link-sentinel' ),
                'original_url'  => __( 'Original URL', 'link-sentinel' ),
                'change'        => __( 'Change Link', 'link-sentinel' ),
                'http_status'   => __( 'HTTP', 'link-sentinel' ),
                'status_message'=> __( 'Note', 'link-sentinel' ),
                'scan_date'     => __( 'Date Scanned', 'link-sentinel' ),
            ];
        }
        public function get_sortable_columns() {
            return [ 'scan_date' => [ 'scan_date', true ] ];
        }
        public function column_default( $item, $column_name ) {
            switch ( $column_name ) {
                case 'post_id':
                    $post_title = get_the_title( $item['post_id'] );
                    if ( empty( $post_title ) ) {
                        $post_title = sprintf( __( 'Post #%d', 'link-sentinel' ), $item['post_id'] );
                    }
                    $edit_link = get_edit_post_link( $item['post_id'] );
                    return sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html( $post_title ) );
                case 'original_url':
                    // Only output the URL itself in this column.  Change actions are
                    // rendered in a separate column to the right.
                    return '<code>' . esc_html( $item['original_url'] ) . '</code>';
                case 'http_status':
                    return esc_html( $item['http_status'] );
                case 'status_message':
                    return esc_html( $item['status_message'] );
                case 'scan_date':
                    return esc_html( $item['scan_date'] );
                default:
                    return '';
            }
        }
        public function no_items() {
            esc_html_e( 'No broken links found.', 'link-sentinel' );
        }

        /**
         * Custom output for the change column in the broken links table.
         *
         * We render a simple link labelled "Change".  When clicked, the
         * JavaScript will replace this cell with an inline form consisting
         * of a text input and a "Change" button.  The anchor stores the
         * record ID, nonce and original URL as data attributes for use by JS.
         *
         * @param array $item The current row.
         * @return string HTML for the Change column.
         */
        public function column_change( $item ) {
            $nonce = wp_create_nonce( 'rfx_change_link_nonce' );
            return sprintf(
                '<a href="#" class="rfx-change-inline" data-id="%1$s" data-nonce="%2$s" data-original-url="%3$s">%4$s</a>',
                esc_attr( $item['id'] ),
                esc_attr( $nonce ),
                esc_attr( $item['original_url'] ),
                esc_html__( 'Change', 'link-sentinel' )
            );
        }
        public function prepare_items() {
            global $wpdb;
            $table_name   = $wpdb->prefix . 'rfx_link_monitor';
            $per_page     = 20;
            $order        = ( isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'ASC' : 'DESC';
            $current_page = $this->get_pagenum();
            // Only pending items with status >= 400 are considered broken.
            $total_items  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $table_name WHERE resolution_status = %s AND http_status >= %d", 'pending', 400 ) );
            $this->set_pagination_args( [ 'total_items' => $total_items, 'per_page' => $per_page ] );
            $offset       = ( $current_page - 1 ) * $per_page;
            $items        = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE resolution_status = %s AND http_status >= %d ORDER BY scan_date $order LIMIT %d OFFSET %d",
                    'pending', 400, $per_page, $offset
                ),
                ARRAY_A
            );
            $this->items  = $items;
            $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
        }
    }
}

/**
 * Render the plugin dashboard page.
 */
function rfx_render_dashboard() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have permission to access this page.', 'link-sentinel' ) );
    }
    echo '<div class="wrap">';
    echo '<h1>' . esc_html__( 'LinkSentinel', 'link-sentinel' ) . '</h1>';

    // Last scan information.
    $last_started = get_option( 'rfx_scan_last_started' );
    $last_type    = get_option( 'rfx_scan_last_type' );
    if ( ! empty( $last_started ) ) {
        $type_label = ( 'manual' === $last_type ) ? esc_html__( 'Manual', 'link-sentinel' ) : esc_html__( 'Automatic', 'link-sentinel' );
        echo '<p style="margin: 8px 0 16px; color:#50575e;">' . esc_html__( 'Last scan:', 'link-sentinel' ) . ' ' . esc_html( $last_started ) . ' (' . $type_label . ')</p>';
    }

    // Top card: Manual scan controls.
    echo '<div class="postbox" style="padding:16px 24px; margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . esc_html__( 'Manual Scan', 'link-sentinel' ) . '</h2>';
    echo '<p>' . esc_html__( 'Run a manual scan of your internal links. Results appear below once processing completes.', 'link-sentinel' ) . '</p>';
    echo '<div style="display:flex; gap:8px; flex-wrap:wrap;">';
    echo '<button type="button" id="rfx-start-scan" class="button button-primary">' . esc_html__( 'Scan Now', 'link-sentinel' ) . '</button>';
    echo '<span id="rfx-scan-feedback" style="display:none; margin-left:10px; align-self:center;"></span>';
    echo '</div>';
    // Progress bar container (hidden by default)
    echo '<div id="rfx-scan-status" class="notice notice-info" style="display:none; padding:10px; margin-top:12px;">';
    echo '<p style="margin:0; display:flex; align-items:center; gap:8px;"><span class="spinner is-active" style="float:none; visibility:visible;"></span><strong>' . esc_html__( 'Scan in progress', 'link-sentinel' ) . '</strong> <span id="rfx-scan-status-text"></span></p>';
    echo '<div style="background:#e5e5e5; height:8px; border-radius:4px; margin-top:8px; overflow:hidden;"><div id="rfx-scan-progress" style="height:8px; width:0; background:#2271b1;"></div></div>';
    echo '</div>';
    echo '</div>'; // end postbox

    /*
     * Generate tab navigation with counts for pending and broken items.  We
     * intentionally omit counts on resolved and settings tabs.  Pending
     * redirects include entries flagged for manual review (HTTP status < 400).
     * Broken links include any unresolved links with status >= 400.
     */
    global $wpdb;
    $pending_count_nav = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$wpdb->prefix}rfx_link_monitor WHERE resolution_status = %s AND ( http_status < %d OR http_status IS NULL )", 'pending', 400 ) );
    $broken_count_nav  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$wpdb->prefix}rfx_link_monitor WHERE resolution_status = %s AND http_status >= %d", 'pending', 400 ) );
    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="#resolved" class="nav-tab nav-tab-active">' . esc_html__( 'Resolved Links', 'link-sentinel' ) . '</a>';
    echo '<a href="#pending" class="nav-tab">' . sprintf( esc_html__( 'Pending Redirects (%d)', 'link-sentinel' ), $pending_count_nav ) . '</a>';
    echo '<a href="#broken" class="nav-tab">' . sprintf( esc_html__( 'Broken Links (%d)', 'link-sentinel' ), $broken_count_nav ) . '</a>';
    echo '<a href="#settings" class="nav-tab">' . esc_html__( 'Settings', 'link-sentinel' ) . '</a>';
    echo '</h2>';

    // Resolved tab content: single table for all resolved links with CSV export and clear options.
    echo '<div id="resolved" class="tab-content" style="display:block;">';
    echo '<form method="post">';
    // Build export URL with nonce for CSV download.
    $export_nonce = wp_create_nonce( 'rfx_export_resolved_csv' );
    $export_url   = add_query_arg( [
        'action'   => 'rfx_export_resolved_csv',
        '_wpnonce' => $export_nonce,
    ], admin_url( 'admin-post.php' ) );
    /*
     * Header with export and clear options.
     * We build both the CSV download link and a Clear Table link here.  The clear
     * link includes a nonce for security and an inline confirmation dialog to
     * prevent accidental deletion.  Only resolved items are affected.
     */
    // Nonce and URL for clearing the resolved table.
    $clear_nonce = wp_create_nonce( 'rfx_clear_resolved_links' );
    $clear_url   = add_query_arg( [
        'action'   => 'rfx_clear_resolved_links',
        '_wpnonce' => $clear_nonce,
    ], admin_url( 'admin-post.php' ) );
    echo '<div style="display:flex; justify-content:space-between; align-items:center;">';
    // Heading on the left
    echo '<h3 style="margin-top:10px;">' . esc_html__( 'Resolved Links', 'link-sentinel' ) . '</h3>';
    // Links on the right
    echo '<div style="display:flex; gap:8px;">';
    // Clear Table button with confirmation; uses a simple JS confirm dialog.
    echo '<a href="' . esc_url( $clear_url ) . '" class="button" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to clear all resolved links? This action cannot be undone.', 'link-sentinel' ) ) . '\');">' . esc_html__( 'Clear Table', 'link-sentinel' ) . '</a>';
    // Download CSV button
    echo '<a href="' . esc_url( $export_url ) . '" class="button">' . esc_html__( 'Download CSV', 'link-sentinel' ) . '</a>';
    echo '</div>';
    echo '</div>';
    // Display the full resolved list table (all scopes).
    $resolved_table = new RFX_Resolved_Links_List_Table();
    $resolved_table->set_scope( 'all' );
    $resolved_table->prepare_items();
    $resolved_table->display();
    echo '</form>';
    echo '</div>'; // resolved tab

    // Pending redirects tab content
    echo '<div id="pending" class="tab-content" style="display:none;">';
    echo '<form method="post">';
    // Heading with Resolve All button on the right. Only show if there are pending redirects.
    global $wpdb;
    $pending_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$wpdb->prefix}rfx_link_monitor WHERE resolution_status = %s AND final_url <> '' AND ( http_status < %d OR http_status IS NULL )", 'pending', 400 ) );
    echo '<div style="display:flex; justify-content:space-between; align-items:center;">';
    echo '<h3 style="margin-top:10px;">' . esc_html__( 'Pending Redirects', 'link-sentinel' ) . '</h3>';
    if ( $pending_count > 0 ) {
        $resolve_all_nonce = wp_create_nonce( 'rfx_resolve_all_nonce' );
        echo '<button type="button" id="rfx-resolve-all" class="button button-secondary" data-nonce="' . esc_attr( $resolve_all_nonce ) . '" style="margin-bottom:4px;">' . esc_html__( 'Resolve All', 'link-sentinel' ) . '</button>';
    }
    echo '</div>';
    $pend_table = new RFX_Pending_Links_List_Table();
    $pend_table->prepare_items();
    $pend_table->display();
    echo '</form>';
    echo '</div>'; // pending tab

    // Broken links tab content
    echo '<div id="broken" class="tab-content" style="display:none;">';
    echo '<form method="post">';
    $broken_table = new RFX_Broken_Links_List_Table();
    $broken_table->prepare_items();
    $broken_table->display();
    echo '</form>';
    echo '</div>'; // broken tab

    // Settings tab content
    echo '<div id="settings" class="tab-content" style="display:none;">';
    // Settings heading
    echo '<h3>' . esc_html__( 'Settings', 'link-sentinel' ) . '</h3>';
    // Show success notice if settings were updated.
    if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings updated.', 'link-sentinel' ) . '</p></div>';
    }
    // Fetch current settings to pre-populate form.
    $settings            = get_option( 'rfx_settings', [] );
    $current_types       = ( isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ) ? $settings['post_types'] : [ 'post', 'page' ];
    $external_redirects  = get_option( 'rfx_follow_external_redirects', '0' );
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
    // Nonce and action
    wp_nonce_field( 'rfx_save_settings', 'rfx_settings_nonce' );
    echo '<input type="hidden" name="action" value="rfx_save_settings" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    // Automatic permanent redirect resolution option.
    $auto_resolve_setting = ( isset( $settings['auto_resolve_permanent'] ) && $settings['auto_resolve_permanent'] );
    echo '<tr valign="top">';
    echo '<th scope="row">' . esc_html__( 'Auto‑resolve permanent redirects', 'link-sentinel' ) . '</th>';
    echo '<td>';
    printf(
        '<label><input type="checkbox" name="auto_resolve_permanent" value="1" %s /> %s</label>',
        checked( true, $auto_resolve_setting, false ),
        esc_html__( 'Automatically update links that return a 301 or 308 status when scanning.', 'link-sentinel' )
    );
    echo '<p class="description">' . esc_html__( 'When enabled, permanently redirected links will be updated without requiring manual review.', 'link-sentinel' ) . '</p>';
    echo '</td>';
    echo '</tr>';
    // External redirect resolution toggle.
    echo '<tr valign="top">';
    echo '<th scope="row">' . esc_html__( 'Resolve external redirects (slower)', 'link-sentinel' ) . '</th>';
    echo '<td>';
    printf(
        '<label><input type="checkbox" name="follow_external_redirects" value="1" %s /> %s</label>',
        checked( '1', $external_redirects, false ),
        esc_html__( 'Follow and resolve external redirect chains (may be slower and can cause timeouts on some hosts).', 'link-sentinel' )
    );
    echo '<p class="description">' . esc_html__( 'Enable this if you need to trace redirects that leave your domain. Leave it off to keep scans faster.', 'link-sentinel' ) . '</p>';
    echo '</td>';
    echo '</tr>';
    // Post types field
    echo '<tr valign="top">';
    echo '<th scope="row">' . esc_html__( 'Post types to scan', 'link-sentinel' ) . '</th>';
    echo '<td>';
    // Retrieve all public post types.
    $public_types = get_post_types( [ 'public' => true ], 'objects' );
    foreach ( $public_types as $type ) {
        printf(
            '<label style="display:inline-block; margin-right:10px;"><input type="checkbox" name="post_types[]" value="%s" %s /> %s</label>',
            esc_attr( $type->name ),
            checked( in_array( $type->name, $current_types, true ), true, false ),
            esc_html( $type->labels->singular_name )
        );
    }
    echo '<p class="description">' . esc_html__( 'Select which post types should be included in scans. Defaults to posts and pages.', 'link-sentinel' ) . '</p>';
    echo '</td>';
    echo '</tr>';
    echo '</tbody></table>';
    echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save Settings', 'link-sentinel' ) . '</button></p>';
    echo '</form>';
    echo '</div>'; // settings tab

    echo '</div>'; // tab wrapper
    echo '<p style="margin-top:20px; text-align:center; color:#777; font-size:13px;">&copy;2025 <a href="https://www.pragmaticbear.com" target="_blank" rel="noopener">' . esc_html__( 'Pragmatic Bear', 'link-sentinel' ) . '</a>.</p>';

    echo '</div>'; // wrap
}

/**
 * Export resolved links to a CSV file.
 *
 * Generates a CSV of all entries marked as resolved in the `rfx_link_monitor`
 * table.  Users must have `manage_options` capability to initiate the export.
 * A valid nonce must be supplied via the `_wpnonce` query parameter.  The
 * resulting file is output directly to the browser and terminates script
 * execution via exit().
 */
function rfx_export_resolved_csv() {
    // Only administrators can export data.
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have permission to access this export.', 'link-sentinel' ) );
    }
    // Verify the nonce to protect against CSRF.
    check_admin_referer( 'rfx_export_resolved_csv' );
    global $wpdb;
    $table_name = $wpdb->prefix . 'rfx_link_monitor';
    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=resolved-links-' . gmdate( 'Y-m-d_H-i-s' ) . '.csv' );
    $output = fopen( 'php://output', 'w' );
    fputcsv( $output, [ 'ID', 'Post ID', 'Original URL', 'Final URL', 'HTTP Status', 'Action', 'Scan Date', 'Resolution Date' ] );

    $last_id = 0;
    $batch   = 500;

    while ( true ) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, post_id, original_url, final_url, http_status, status_message, scan_date, resolution_date FROM $table_name WHERE resolution_status = %s AND id > %d ORDER BY id ASC LIMIT %d",
                'resolved',
                $last_id,
                $batch
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            break;
        }

        foreach ( $rows as $row ) {
            fputcsv( $output, [
                $row['id'],
                $row['post_id'],
                $row['original_url'],
                $row['final_url'],
                $row['http_status'],
                $row['status_message'],
                $row['scan_date'],
                $row['resolution_date'],
            ] );
            $last_id = (int) $row['id'];
        }
        fflush( $output );
    }

    fclose( $output );
    exit;
}

/**
 * Admin action to clear all resolved links from the log table.
 *
 * When the Clear Table link is clicked on the Resolved Links tab, this
 * function runs.  It verifies the user's capability and nonce, then
 * deletes all rows in the log table where the resolution_status is
 * 'resolved'.  After deletion, the user is redirected back to the
 * Resolved Links tab.  A simple confirm dialog is presented via
 * JavaScript on the link itself to avoid accidental deletion.
 */
function rfx_clear_resolved_links() {
    // Only administrators may clear the table.
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Permission denied.', 'link-sentinel' ) );
    }
    // Verify nonce for security.
    check_admin_referer( 'rfx_clear_resolved_links' );
    global $wpdb;
    $table_name = $wpdb->prefix . 'rfx_link_monitor';
    // Delete all resolved rows.
    $wpdb->delete( $table_name, [ 'resolution_status' => 'resolved' ], [ '%s' ] );
    // Redirect back to the Resolved tab with a query flag to display a notice (optional).
    $redirect_url = add_query_arg( [ 'page' => 'link-sentinel', 'tab' => 'resolved', 'cleared' => '1' ], admin_url( 'tools.php' ) );
    wp_safe_redirect( $redirect_url );
    exit;
}

// Register the clear resolved action for admin_post.
add_action( 'admin_post_rfx_clear_resolved_links', 'rfx_clear_resolved_links' );

// Register export handlers for logged‑in and non‑logged‑in contexts (nonce is still required).
add_action( 'admin_post_rfx_export_resolved_csv', 'rfx_export_resolved_csv' );
add_action( 'admin_post_nopriv_rfx_export_resolved_csv', 'rfx_export_resolved_csv' );

/* DB Upgrade Hook */
add_action( 'plugins_loaded', function () {
    if ( get_option( 'rfx_db_version' ) !== ( defined('RFX_DB_VERSION') ? RFX_DB_VERSION : '7' ) ) {
        if ( function_exists( 'linksentinel_activate' ) ) {
            linksentinel_activate();
        }
    }
} );
// LinkSentinel 2.5.6-lite-1: tighten HTTP timeouts for resolve runs (gentle defaults)
if ( ! function_exists( 'rfx_links_tight_timeouts' ) ) {
    function rfx_links_tight_timeouts( $args, $url ) {
        $internal = ( strpos( $url, home_url() ) === 0 );
        $cap      = $internal ? 1.5 : 2.0;
        if ( ! isset( $args['timeout'] ) || (float) $args['timeout'] > $cap ) {
            $args['timeout'] = $cap;
        }
        return $args;
    }
    add_filter( 'http_request_args', 'rfx_links_tight_timeouts', 10, 2 );
}


// Map option to the runtime filter (option takes precedence if set)
add_filter( 'rfx_follow_external_redirects', function ( $enabled, $url = '' ) {
    $opt = get_option( 'rfx_follow_external_redirects', '' );
    if ( $opt === '1' ) return true;
    if ( $opt === '0' ) return false;
    return (bool) $enabled;
}, 9, 2 );
