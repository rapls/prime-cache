<?php
/**
 * Performance Tweaks — disable unused WordPress features to reduce page weight.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Performance_Tweaks {

	/** @var array */
	private $s;

	public function __construct() {
		$this->s = prime_cache_get_settings();

		if ( $this->s['disable_jquery_migrate'] ) {
			add_action( 'wp_default_scripts', array( $this, 'remove_jquery_migrate' ) );
		}

		// Restore local jQuery when theme/plugin loads it from external CDN.
		// Eliminates external connection overhead (DNS+TCP+TLS ~600ms on mobile).
		if ( ! empty( $this->s['local_jquery'] ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'restore_local_jquery' ), 999 );
		}

		if ( $this->s['disable_wp_embed'] ) {
			add_action( 'wp_footer', function() { wp_deregister_script( 'wp-embed' ); } );
			add_action( 'init', function() {
				remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
				remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			} );
		}

		if ( $this->s['disable_dashicons'] ) {
			add_action( 'wp_enqueue_scripts', function() {
				if ( ! is_user_logged_in() ) {
					wp_deregister_style( 'dashicons' );
				}
			} );
		}

		if ( $this->s['disable_wp_version'] ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}

		if ( $this->s['disable_xmlrpc'] ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'wp_headers', function( $headers ) {
				unset( $headers['X-Pingback'] );
				return $headers;
			} );
		}

		if ( $this->s['disable_self_pingback'] ) {
			add_action( 'pre_ping', function( &$links ) {
				$home = home_url();
				foreach ( $links as $i => $link ) {
					if ( 0 === strpos( $link, $home ) ) {
						unset( $links[ $i ] );
					}
				}
			} );
		}

		if ( $this->s['limit_revisions'] ) {
			$max = max( 0, (int) $this->s['revisions_max'] );
			add_filter( 'wp_revisions_to_keep', function() use ( $max ) {
				return $max;
			} );
		}

		if ( $this->s['disable_rss_feeds'] ) {
			add_action( 'do_feed', array( $this, 'disable_feed' ), 1 );
			add_action( 'do_feed_rdf', array( $this, 'disable_feed' ), 1 );
			add_action( 'do_feed_rss', array( $this, 'disable_feed' ), 1 );
			add_action( 'do_feed_rss2', array( $this, 'disable_feed' ), 1 );
			add_action( 'do_feed_atom', array( $this, 'disable_feed' ), 1 );
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}

		if ( $this->s['disable_oembed'] ) {
			add_action( 'init', function() {
				remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
				remove_action( 'wp_head', 'wp_oembed_add_host_js' );
				remove_action( 'rest_api_init', 'wp_oembed_register_route' );
				remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
			} );
		}

		if ( $this->s['disable_block_css'] ) {
			add_action( 'wp_enqueue_scripts', function() {
				wp_dequeue_style( 'wp-block-library' );
				wp_dequeue_style( 'wp-block-library-theme' );
				wp_dequeue_style( 'wc-blocks-style' );
			}, 100 );
		}

		if ( $this->s['disable_google_fonts'] ) {
			add_action( 'wp_enqueue_scripts', function() {
				global $wp_styles;
				if ( ! $wp_styles ) return;
				foreach ( $wp_styles->registered as $handle => $dep ) {
					if ( ! empty( $dep->src ) && ( false !== strpos( $dep->src, 'fonts.googleapis.com' ) || false !== strpos( $dep->src, 'fonts.bunny.net' ) ) ) {
						wp_dequeue_style( $handle );
						wp_deregister_style( $handle );
					}
				}
			}, 100 );
		}

		if ( $this->s['disable_global_styles'] ) {
			add_action( 'wp_enqueue_scripts', function() {
				wp_dequeue_style( 'global-styles' );
			}, 100 );
			remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
		}

		// #1: WP head cleanup.
		if ( $this->s['disable_shortlink'] ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}
		if ( $this->s['disable_rsd_wlw'] ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}
		if ( $this->s['disable_rest_api_link'] ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
			remove_action( 'xmlrpc_rsd_apis', 'rest_output_rsd' );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}

		// #2: Blank favicon.
		if ( $this->s['add_blank_favicon'] ) {
			add_action( 'wp_head', function() {
				if ( ! has_site_icon() ) {
					echo '<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22/>">' . "\n";
				}
			}, 1 );
		}

		// #3: Disable WP sitemap.
		if ( $this->s['disable_wp_sitemap'] ) {
			add_filter( 'wp_sitemaps_enabled', '__return_false' );
		}

		// Limit excessive dns-prefetch / preconnect hints.
		// Themes like Cocoon output <link rel="preconnect dns-prefetch"> directly
		// in wp_head, bypassing wp_resource_hints. Process via HTML pipeline.
		if ( ! empty( $this->s['limit_dns_prefetch'] ) ) {
			add_filter( 'wp_resource_hints', array( $this, 'limit_dns_prefetch_hints' ), 999, 2 );
			global $prime_cache_html_pipeline;
			if ( $prime_cache_html_pipeline ) {
				$prime_cache_html_pipeline->register( 'dns_limit', array( $this, 'limit_dns_prefetch_html' ), 5 );
			}
		}

		// WooCommerce script optimization.
		if ( $this->s['woo_disable_scripts'] ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'woo_optimize_scripts' ), 99 );
		}

		// #6: WooCommerce cart fragments AJAX.
		if ( $this->s['woo_disable_cart_frag'] ) {
			add_action( 'wp_enqueue_scripts', function() {
				if ( class_exists( 'WooCommerce' ) && ! is_cart() && ! is_checkout() ) {
					wp_dequeue_script( 'wc-cart-fragments' );
				}
			}, 99 );
		}
	}

	/**
	 * Restore WordPress's bundled jQuery when a theme/plugin overrides it with a CDN URL.
	 *
	 * Cocoon and some themes replace jQuery with cdnjs.cloudflare.com versions.
	 * External CDN adds ~600ms of connection overhead on mobile (DNS+TCP+TLS).
	 * This restores the local copy which loads from the same origin — no extra connection.
	 */
	public function restore_local_jquery() {
		if ( is_admin() ) {
			return;
		}
		$wp_scripts = wp_scripts();
		$site       = site_url();

		// Handles to check: Cocoon may override 'jquery' directly or 'jquery-core'.
		$jquery_handles = array(
			'jquery'         => includes_url( 'js/jquery/jquery.min.js' ),
			'jquery-core'    => includes_url( 'js/jquery/jquery.min.js' ),
			'jquery-migrate' => includes_url( 'js/jquery/jquery-migrate.min.js' ),
		);

		foreach ( $jquery_handles as $handle => $local_src ) {
			if ( empty( $wp_scripts->registered[ $handle ] ) ) {
				continue;
			}
			$src = $wp_scripts->registered[ $handle ]->src;
			if ( ! $src ) {
				continue;
			}
			// Check if src is external (starts with // or http and is not our site).
			$is_external = ( 0 === strpos( $src, '//' ) || 0 === strpos( $src, 'http' ) )
				&& false === strpos( $src, $site );
			if ( $is_external ) {
				$wp_scripts->registered[ $handle ]->src = $local_src;
				$wp_scripts->registered[ $handle ]->ver = null; // use WP default version
			}
		}
	}

	/**
	 * Remove jQuery Migrate from frontend (keep in admin for compatibility).
	 */
	public function remove_jquery_migrate( $scripts ) {
		if ( is_admin() ) return;
		if ( ! empty( $scripts->registered['jquery'] ) ) {
			$deps = $scripts->registered['jquery']->deps;
			$scripts->registered['jquery']->deps = array_diff( $deps, array( 'jquery-migrate' ) );
		}
	}

	/**
	 * Redirect feeds to homepage.
	 */
	public function disable_feed() {
		wp_safe_redirect( home_url(), 301 );
		exit;
	}

	/**
	 * Disable WooCommerce scripts/styles on non-WooCommerce pages.
	 */
	public function woo_optimize_scripts() {
		if ( ! class_exists( 'WooCommerce' ) ) return;

		// Keep scripts on WooCommerce pages.
		if ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() || is_product() ) {
			return;
		}

		// Dequeue WooCommerce assets on non-WC pages.
		wp_dequeue_style( 'woocommerce-general' );
		wp_dequeue_style( 'woocommerce-layout' );
		wp_dequeue_style( 'woocommerce-smallscreen' );
		wp_dequeue_style( 'wc-blocks-style' );
		wp_dequeue_script( 'wc-cart-fragments' );
		wp_dequeue_script( 'woocommerce' );
		wp_dequeue_script( 'wc-add-to-cart' );

		// Remove WC structured data if available.
		if ( function_exists( 'WC' ) && WC() && isset( WC()->structured_data ) ) {
			remove_action( 'wp_head', array( WC()->structured_data, 'output_structured_data' ) );
		}
	}

	/**
	 * Limit the number of dns-prefetch / preconnect hints (wp_resource_hints filter).
	 *
	 * - Removes self-origin hints (preconnect to own domain is useless).
	 * - For dns-prefetch: drops origins that already have a preconnect hint
	 *   (preconnect implies dns-prefetch, so keeping both is redundant).
	 * - Caps total hints at 4 per relation type.
	 */
	public function limit_dns_prefetch_hints( $hints, $relation_type ) {
		if ( 'dns-prefetch' !== $relation_type && 'preconnect' !== $relation_type ) {
			return $hints;
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$max       = 4;

		// Remove self-origin hints.
		$hints = array_filter( $hints, function ( $hint ) use ( $site_host ) {
			$url  = is_array( $hint ) ? ( $hint['href'] ?? '' ) : $hint;
			$host = wp_parse_url( $url, PHP_URL_HOST );
			return $host && $host !== $site_host;
		} );

		// For dns-prefetch, drop origins that already have a preconnect hint.
		if ( 'dns-prefetch' === $relation_type ) {
			$preconnect_origins = $this->get_preconnect_origins();
			$hints = array_filter( $hints, function ( $hint ) use ( $preconnect_origins ) {
				$url  = is_array( $hint ) ? ( $hint['href'] ?? '' ) : $hint;
				$host = wp_parse_url( $url, PHP_URL_HOST );
				return ! $host || ! isset( $preconnect_origins[ $host ] );
			} );
		}

		// Re-index and cap.
		$hints = array_values( $hints );
		if ( count( $hints ) > $max ) {
			$hints = array_slice( $hints, 0, $max );
		}

		return $hints;
	}

	/**
	 * Collect origins that have preconnect hints registered via wp_resource_hints.
	 *
	 * Temporarily unhooks our own filter to avoid recursion when calling
	 * apply_filters( 'wp_resource_hints', ... ).
	 *
	 * @return array<string, true> Map of hostnames.
	 */
	private function get_preconnect_origins() {
		static $origins = null;
		if ( null !== $origins ) {
			return $origins;
		}
		$origins = array();

		// Temporarily remove our filter to prevent recursion.
		remove_filter( 'wp_resource_hints', array( $this, 'limit_dns_prefetch_hints' ), 999 );

		$preconnect_hints = apply_filters( 'wp_resource_hints', array(), 'preconnect' );

		// Re-add our filter.
		add_filter( 'wp_resource_hints', array( $this, 'limit_dns_prefetch_hints' ), 999, 2 );

		foreach ( $preconnect_hints as $hint ) {
			$url  = is_array( $hint ) ? ( $hint['href'] ?? '' ) : $hint;
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $host ) {
				$origins[ $host ] = true;
			}
		}
		return $origins;
	}

	/**
	 * Limit dns-prefetch / preconnect link tags in HTML output.
	 *
	 * Handles themes (e.g. Cocoon) that output <link rel="preconnect dns-prefetch">
	 * directly in wp_head, bypassing the wp_resource_hints filter.
	 *
	 * Processing:
	 * 1. Remove self-origin hints.
	 * 2. Remove dns-prefetch tags whose origin already has a preconnect tag.
	 * 3. Cap remaining hints at 4.
	 */
	public function limit_dns_prefetch_html( $html ) {
		$max       = 4;
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$pattern   = '#<link\s[^>]*rel=["\'](?:dns-prefetch|preconnect|preconnect\s+dns-prefetch|dns-prefetch\s+preconnect)["\'][^>]*>#i';

		// First pass: collect all hint tags and identify preconnect origins.
		$preconnect_origins = array();
		if ( preg_match_all( $pattern, $html, $matches ) ) {
			foreach ( $matches[0] as $tag ) {
				if ( preg_match( '/rel=["\'][^"\']*preconnect[^"\']*["\']/', $tag ) ) {
					$origin = $this->extract_href_host( $tag );
					if ( $origin ) {
						$preconnect_origins[ $origin ] = true;
					}
				}
			}
		}

		// Second pass: filter tags.
		$count = 0;
		$html  = preg_replace_callback(
			$pattern,
			function ( $m ) use ( $max, &$count, $site_host, $preconnect_origins ) {
				$tag    = $m[0];
				$origin = $this->extract_href_host( $tag );

				// Remove self-origin hints.
				if ( $origin && $origin === $site_host ) {
					return '';
				}

				// Remove dns-prefetch-only tags when a preconnect for the same origin exists.
				$is_preconnect = (bool) preg_match( '/rel=["\'][^"\']*preconnect[^"\']*["\']/', $tag );
				if ( ! $is_preconnect && $origin && isset( $preconnect_origins[ $origin ] ) ) {
					return '';
				}

				$count++;
				return $count > $max ? '' : $tag;
			},
			$html
		);

		return $html;
	}

	/**
	 * Extract the hostname from a link tag's href attribute.
	 *
	 * @param string $tag HTML link tag.
	 * @return string|null Hostname or null.
	 */
	private function extract_href_host( $tag ) {
		if ( preg_match( '/href=["\']([^"\']+)["\']/', $tag, $m ) ) {
			return wp_parse_url( $m[1], PHP_URL_HOST );
		}
		return null;
	}
}
