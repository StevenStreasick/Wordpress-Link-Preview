<?php
/*
Plugin Name: Link Preview
Description: Generates a minimal OpenGraph link preview via shortcode [link_preview url="https://example.com"].
Version: 1.0
Author: Steven Streasick
*/

if ( ! defined( 'ABSPATH' ) ) exit; // safety

function slp_enqueue_styles() {
    wp_enqueue_style(
        'roboto-condensed',
        'https://fonts.googleapis.com/css2?family=Roboto+Condensed:wght@300;400;700&display=swap',
        array(),
        null
    );

    wp_enqueue_style(
        'slp-link-preview', // handle
        plugin_dir_url(__FILE__) . 'link-preview-style.css', // URL to your CSS file
        [], // dependencies
        filemtime(plugin_dir_path(__FILE__)  . 'link-preview-style.css'),
        'all' // media
    );
}
add_action('wp_enqueue_scripts', 'slp_enqueue_styles');
function slp_get_open_graph_data( $url ) {
    $cache_key = 'slp_' . md5( $url );
    $cached    = get_transient( $cache_key );
    if ( $cached ) return $cached;

    // Internal URL — no HTTP needed, just grab from WP directly
    if ( strpos( $url, home_url() ) === 0 ) {
        $post_id = url_to_postid( $url );
        if ( $post_id ) {
            $data = [
                'title'       => get_the_title( $post_id ),
                'description' => get_the_excerpt( $post_id ),
                'image'       => get_the_post_thumbnail_url( $post_id, 'full' ) ?: '',
            ];
            set_transient( $cache_key, $data, HOUR_IN_SECONDS * .001 );
            return $data;
        }
    }

    // Fire the request but don't wait for the response at all
    wp_remote_get( $url, [
        'timeout'   => 0.01, // return control to PHP almost instantly
        'blocking'  => false, // ← key flag — PHP does not wait for response
        'headers'   => [ 'User-Agent' => 'Mozilla/5.0 (compatible; LinkPreviewBot/1.0)' ],
        'sslverify' => true,
    ]);

    // Schedule WP-Cron to do the real fetch and cache it properly
    if ( ! wp_next_scheduled( 'slp_fetch_preview', [ $url ] ) ) {
        wp_clear_scheduled_hook( 'slp_fetch_preview', [ $url ] ); // clear any stale job first
        wp_schedule_single_event( time(), 'slp_fetch_preview', [ $url ] );
    }

    return 'pending';
}

// This runs in the background via WP-Cron
add_action( 'slp_fetch_preview', 'slp_do_background_fetch' );
function slp_do_background_fetch( $url ) {
    $cache_key   = 'slp_' . md5( $url );
    $pending_key = 'slp_pending_' . md5( $url );

    $response = wp_remote_get( $url, [
        'timeout'   => 10,
        'headers'   => [ 'User-Agent' => 'Mozilla/5.0 (compatible; LinkPreviewBot/1.0)' ],
        'sslverify' => true,
    ]);

    if ( is_wp_error( $response ) ) {
        delete_transient( $pending_key );
        return;
    }

    $body = wp_remote_retrieve_body( $response );

    preg_match( '/<meta property="og:title" content="([^"]+)"/i', $body, $title );
    preg_match( '/<meta name="twitter:title" content="([^"]+)"/i', $body, $title2 );
    preg_match( '/<meta property="og:description" content="([^"]+)"/i', $body, $desc );
    preg_match( '/<meta property="og:image" content="([^"]+)"/i', $body, $image );

    $data = [
        'title'       => $title[1] ?? $title2[1] ?? '',
        'description' => $desc[1] ?? '',
        'image'       => $image[1] ?? '',
    ];

    set_transient( $cache_key, $data, HOUR_IN_SECONDS * .001 );
    delete_transient( $pending_key );
}

function slp_render_preview( $url, $meta ) {
    ob_start(); ?>
    <div class="slp-preview">
        <?php if ( ! empty( $meta['image'] ) ): ?>
            <a target="_blank" href="<?php echo esc_url( $url ); ?>">
                <img src="<?php echo esc_url( $meta['image'] ); ?>" alt="">
            </a>
        <?php endif; ?>
        <div class="slp-preview-content">
            <div class="slp-preview-title"><strong><?php echo esc_html( $meta['title'] ); ?></strong></div><br>
            <div class="slp-preview-description"><em><?php echo esc_html( $meta['description'] ); ?></em></div>
        </div>
    </div>
    <?php return ob_get_clean();
}

function slp_shortcode( $atts ) {
    $atts = shortcode_atts( ['url' => ''], $atts );
    if ( empty( $atts['url'] ) ) return '';

    $url  = esc_url_raw( $atts['url'] );
    $meta = slp_get_open_graph_data( $url );

    if ( $meta !== 'pending' && $meta ) {
        return slp_render_preview( $url, $meta );
    }

    $id = 'slp-' . md5( $url );

    return '
        <div id="' . esc_attr( $id ) . '" class="slp-preview slp-preview--loading">
            <span>Loading preview...</span>
        </div>
        <script>
        setTimeout(function() {
            let attempts = 0;
            const maxAttempts = 5;

            function trySwap() {
                fetch(window.location.href)
                    .then(r => r.text())
                    .then(html => {
                        const parser  = new DOMParser();
                        const doc     = parser.parseFromString(html, "text/html");
                        const updated = doc.getElementById(' . json_encode( $id ) . ');

                        if (updated && !updated.classList.contains("slp-preview--loading")) {
                            document.getElementById(' . json_encode( $id ) . ').outerHTML = updated.outerHTML;
                        } else if (++attempts < maxAttempts) {
                            setTimeout(trySwap, 2000);
                        }
                    })
                    .catch(function() {
                        if (++attempts < maxAttempts) setTimeout(trySwap, 2000);
                    });
            }

            trySwap();
        }, 3000);
        </script>';
}

add_shortcode( 'link_preview', 'slp_shortcode' );
