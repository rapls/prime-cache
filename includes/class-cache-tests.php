<?php
/**
 * Cache eligibility tests.
 *
 * Used both inside WordPress (full test) and from the dropin (early test).
 * Must stay aligned with dropins/page-cache.php conditions.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Tests {

	/**
	 * @var array Plugin settings.
	 */
	private $settings;

	/**
	 * @param array $settings Plugin settings.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Run pre-buffering tests to decide if we should start output buffering.
	 *
	 * @return bool
	 */
	public function can_start_buffering() {
		// Only GET requests.
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return false;
		}

		// Skip admin / login / cron / xmlrpc / REST.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		if ( preg_match( '#(/wp-admin|/wp-login\.php|/wp-cron\.php|/xmlrpc\.php)#', $request_uri ) ) {
			return false;
		}

		// WooCommerce early exclusion (boundary-aware).
		if ( preg_match( '#(?:^|/)(?:cart|checkout|my-account)(?:/|$|\?)|(?:^|/)wc-api(?:/|$)|(?:[?&](?:wc-ajax|add-to-cart)=)#i', $request_uri ) ) {
			return false;
		}

		// WooCommerce session cookies.
		if ( ! empty( $_COOKIE ) ) {
			foreach ( array_keys( $_COOKIE ) as $ck ) {
				if ( 0 === strpos( $ck, 'woocommerce_cart_hash' ) || 0 === strpos( $ck, 'wp_woocommerce_session_' ) || 0 === strpos( $ck, 'woocommerce_items_in_cart' ) ) {
					return false;
				}
			}
		}

		// Skip non-HTML file extensions.
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( $path && preg_match( '#\.(php|xml|xsl|json|css|js|map|txt)$#i', $path ) && ! preg_match( '#/index\.php$#', $path ) ) {
			return false;
		}

		// Skip if DONOTCACHEPAGE is set.
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		// Query string handling (aligned with dropin: ignore + cache-list + reject).
		if ( ! $this->can_cache_query_string() ) {
			return false;
		}

		// Logged-in user check.
		if ( ! $this->settings['cache_logged_in'] && $this->has_logged_in_cookie() ) {
			return false;
		}

		// Rejected cookies.
		if ( $this->has_rejected_cookie() ) {
			return false;
		}

		// Rejected URIs.
		if ( $this->is_rejected_uri( $request_uri ) ) {
			return false;
		}

		// Rejected user agents.
		if ( $this->is_rejected_ua() ) {
			return false;
		}

		// Rejected referrers.
		if ( $this->is_rejected_referrer() ) {
			return false;
		}

		return true;
	}

	/**
	 * Run post-buffering tests on the captured HTML.
	 *
	 * @param string $buffer HTML output.
	 * @return bool
	 */
	public function can_cache_buffer( $buffer ) {
		if ( strlen( $buffer ) < 255 ) {
			return false;
		}

		// Check HTTP status code — allow 404 if cache_404 is enabled.
		$status = http_response_code();
		if ( 404 === $status ) {
			$s = prime_cache_get_settings();
			if ( empty( $s['cache_404'] ) ) {
				return false;
			}
		} elseif ( 200 !== $status ) {
			return false;
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		// Must contain closing html tag.
		if ( false === stripos( $buffer, '</html>' ) ) {
			return false;
		}

		// Skip search results.
		if ( function_exists( 'is_search' ) && is_search() ) {
			return false;
		}

		// Skip password-protected posts.
		if ( function_exists( 'get_queried_object' ) ) {
			$obj = get_queried_object();
			if ( $obj && isset( $obj->post_password ) && '' !== $obj->post_password ) {
				return false;
			}
		}

		// Skip posts with per-post cache disable metabox.
		if ( function_exists( 'get_queried_object_id' ) && class_exists( 'Prime_Cache_Post_Metabox' ) ) {
			$qid = get_queried_object_id();
			if ( $qid && Prime_Cache_Post_Metabox::is_cache_disabled( $qid ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check query string cacheability.
	 *
	 * Aligned with dropin logic:
	 *  - cache_ignore_qs: strip these params (no effect on cache key)
	 *  - cache_query_strings: create unique cache variant per value
	 *  - Any other param: reject (don't cache)
	 *
	 * @return bool True if request is cacheable.
	 */
	private function can_cache_query_string() {
		if ( empty( $_GET ) ) {
			return true;
		}

		$ignored = array_map( 'trim', explode( ',', $this->settings['cache_ignore_qs'] ) );
		$ignored = array_filter( $ignored );

		$remaining = array_diff_key( $_GET, array_flip( $ignored ) );

		if ( empty( $remaining ) ) {
			return true;
		}

		// Check if remaining params are in the cache_query_strings whitelist.
		$cached_qs = array_map( 'trim', explode( ',', $this->settings['cache_query_strings'] ?? '' ) );
		$cached_qs = array_filter( $cached_qs );

		if ( empty( $cached_qs ) ) {
			return false; // Unknown params, no whitelist.
		}

		$unknown = array_diff_key( $remaining, array_flip( $cached_qs ) );
		return empty( $unknown );
	}

	/**
	 * Check for WordPress logged-in cookies.
	 *
	 * @return bool
	 */
	private function has_logged_in_cookie() {
		if ( empty( $_COOKIE ) ) {
			return false;
		}

		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( strpos( $name, 'wordpress_logged_in_' ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check for rejected cookies.
	 *
	 * @return bool
	 */
	private function has_rejected_cookie() {
		$pattern = trim( $this->settings['cache_reject_cookies'] );
		if ( empty( $pattern ) || empty( $_COOKIE ) ) {
			return false;
		}

		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( @preg_match( '#' . $pattern . '#i', $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check for rejected URI patterns.
	 *
	 * @param string $uri Request URI.
	 * @return bool
	 */
	private function is_rejected_uri( $uri ) {
		$pattern = trim( $this->settings['cache_reject_uri'] );
		if ( empty( $pattern ) ) {
			return false;
		}

		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! $path ) {
			return false;
		}

		return (bool) @preg_match( '#(' . $pattern . ')#i', $path );
	}

	/**
	 * Check for rejected user agents.
	 *
	 * @return bool
	 */
	private function is_rejected_ua() {
		$pattern = trim( $this->settings['cache_reject_ua'] );
		if ( empty( $pattern ) ) {
			return false;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
		if ( empty( $ua ) ) {
			return false;
		}

		return (bool) @preg_match( '#' . $pattern . '#i', $ua );
	}

	/**
	 * Check for rejected referrers.
	 *
	 * @return bool
	 */
	private function is_rejected_referrer() {
		$pattern = trim( $this->settings['cache_reject_referrer'] ?? '' );
		if ( empty( $pattern ) || empty( $_SERVER['HTTP_REFERER'] ) ) {
			return false;
		}

		return (bool) @preg_match( '#(' . $pattern . ')#i', $_SERVER['HTTP_REFERER'] );
	}

	/**
	 * Detect mobile user agent.
	 *
	 * @return bool
	 */
	public function is_mobile() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
		if ( empty( $ua ) ) {
			return false;
		}

		$mobile_agents = 'Mobile|Android|Silk/|Kindle|BlackBerry|Opera Mini|Opera Mobi|webOS';

		return (bool) preg_match( '#(' . $mobile_agents . ')#i', $ua );
	}
}
