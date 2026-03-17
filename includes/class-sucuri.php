<?php
/**
 * Sucuri WAF/CDN cache integration.
 *
 * Sends cache clear requests to the Sucuri Firewall API when Prime Cache is purged.
 * API docs: https://docs.sucuri.net/website-firewall/performance/clear-cache/
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Sucuri {

	const API_URL = 'https://waf.sucuri.net/api?v2&';

	/** @var array */
	private $settings;

	public function __construct() {
		$this->settings = prime_cache_get_settings();

		if ( ! $this->is_enabled() ) {
			return;
		}

		// Purge on full cache clear.
		add_action( 'prime_cache_after_purge_all', array( $this, 'purge' ) );

		// Purge on single URL clear (Sucuri API only supports full purge).
		// We debounce to avoid sending many requests when multiple URLs are purged.
		add_action( 'shutdown', array( $this, 'maybe_flush_pending' ) );
		add_action( 'prime_cache_url_purged', array( $this, 'mark_pending' ) );
	}

	/**
	 * Whether Sucuri integration is enabled.
	 */
	private function is_enabled() {
		if ( ! empty( $this->settings['sucuri_enabled'] ) ) {
			return $this->get_api_key() !== false;
		}
		return false;
	}

	/**
	 * Get and validate the API key.
	 *
	 * @return array|false Array with 'k' and 's' keys, or false if invalid.
	 */
	private function get_api_key() {
		// Constant override.
		$key = defined( 'PRIME_CACHE_SUCURI_API_KEY' ) ? PRIME_CACHE_SUCURI_API_KEY : $this->settings['sucuri_api_key'];
		$key = trim( $key );

		if ( empty( $key ) ) {
			return false;
		}

		// Format: 32-char-key/32-char-secret.
		if ( ! preg_match( '@^(?P<k>[a-z0-9]{32})/(?P<s>[a-z0-9]{32})$@', $key, $m ) ) {
			return false;
		}

		return array( 'k' => $m['k'], 's' => $m['s'] );
	}

	/**
	 * Send cache clear request to Sucuri WAF API.
	 *
	 * @return bool|WP_Error
	 */
	public function purge() {
		$creds = $this->get_api_key();
		if ( ! $creds ) {
			return false;
		}

		$url = self::API_URL;

		$args = array(
			'timeout'     => 10,
			'redirection' => 3,
			'httpversion' => '1.1',
			'blocking'    => true,
			'sslverify'   => true,
			'body'        => array(
				'a'    => 'clear_cache',
				'k'    => $creds['k'],
				's'    => $creds['s'],
				'time' => time(),
			),
		);

		$args = apply_filters( 'prime_cache_sucuri_request_args', $args );

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! empty( $data['status'] ) && 1 === (int) $data['status'] ) {
			return true;
		}

		$msg = $data['messages'][0] ?? __( 'Unknown error from Sucuri API.', 'prime-cache' );
		return new WP_Error( 'sucuri_purge_failed', $msg );
	}

	/**
	 * Mark that a URL purge occurred — triggers a debounced full purge on shutdown.
	 */
	public function mark_pending( $url ) {
		if ( ! defined( 'PRIME_CACHE_SUCURI_PENDING' ) ) {
			define( 'PRIME_CACHE_SUCURI_PENDING', true );
		}
	}

	/**
	 * If any URL was purged during this request, send one Sucuri purge on shutdown.
	 */
	public function maybe_flush_pending() {
		if ( defined( 'PRIME_CACHE_SUCURI_PENDING' ) && PRIME_CACHE_SUCURI_PENDING ) {
			$this->purge();
		}
	}
}
