<?php
/**
 * Varnish Cache integration.
 *
 * Sends HTTP PURGE requests to Varnish reverse proxy when cache is cleared.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Varnish {

	/** @var array */
	private $settings;

	public function __construct() {
		$this->settings = prime_cache_get_settings();

		if ( ! $this->is_enabled() ) {
			return;
		}

		// Full site purge.
		add_action( 'prime_cache_after_purge_all', array( $this, 'purge_all' ) );

		// Single URL purge — hook into storage delete.
		add_action( 'prime_cache_url_purged', array( $this, 'purge_url' ) );
	}

	/**
	 * Whether Varnish purging is enabled.
	 */
	private function is_enabled() {
		// Setting toggle.
		if ( ! empty( $this->settings['varnish_enabled'] ) ) {
			return true;
		}

		// Constant override.
		if ( defined( 'PRIME_CACHE_VARNISH_IP' ) && PRIME_CACHE_VARNISH_IP ) {
			return true;
		}

		return false;
	}

	/**
	 * Get Varnish server IP addresses.
	 *
	 * @return array List of IPs (empty string = use hostname directly).
	 */
	private function get_ips() {
		// Constant takes priority.
		if ( defined( 'PRIME_CACHE_VARNISH_IP' ) && PRIME_CACHE_VARNISH_IP ) {
			$ips = PRIME_CACHE_VARNISH_IP;
		} else {
			$ips = trim( $this->settings['varnish_ip'] );
		}

		if ( empty( $ips ) ) {
			return array( '' );
		}

		if ( is_string( $ips ) ) {
			$ips = array_map( 'trim', preg_split( '#[\r\n,]+#', $ips ) );
		}

		return array_filter( (array) $ips );
	}

	/**
	 * Purge the entire site from Varnish.
	 */
	public function purge_all() {
		$home = trailingslashit( home_url() );
		$this->send_purge( $home . '.*', true );
	}

	/**
	 * Purge a specific URL from Varnish.
	 *
	 * @param string $url URL to purge.
	 */
	public function purge_url( $url ) {
		if ( empty( $url ) ) {
			return;
		}
		$this->send_purge( $url, false );
	}

	/**
	 * Send HTTP PURGE request(s) to Varnish.
	 *
	 * @param string $url   URL to purge.
	 * @param bool   $regex Whether this is a regex purge.
	 */
	private function send_purge( $url, $regex = false ) {
		$parsed = wp_parse_url( $url );
		$host   = $parsed['host'] ?? '';
		$scheme = apply_filters( 'prime_cache_varnish_scheme', 'http' );
		$path   = $parsed['path'] ?? '/';

		if ( $regex ) {
			$path = rtrim( $path, '/' ) . '/.*';
		}

		$headers = array(
			'host'           => $host,
			'X-Purge-Method' => $regex ? 'regex' : 'default',
		);

		$headers = apply_filters( 'prime_cache_varnish_purge_headers', $headers, $url );

		$args = array(
			'method'      => 'PURGE',
			'blocking'    => false,
			'redirection' => 0,
			'timeout'     => 5,
			'sslverify'   => false,
			'headers'     => $headers,
		);

		$args = apply_filters( 'prime_cache_varnish_purge_args', $args, $url );

		$ips = $this->get_ips();

		foreach ( $ips as $ip ) {
			if ( ! empty( $ip ) ) {
				// Send to specific IP with Host header.
				$purge_url = $scheme . '://' . $ip . $path;
			} else {
				// No IP configured — send to hostname directly.
				$purge_url = $scheme . '://' . $host . $path;
			}

			$purge_url = apply_filters( 'prime_cache_varnish_purge_url', $purge_url, $url, $ip );

			wp_remote_request( $purge_url, $args );
		}
	}
}
