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
}
