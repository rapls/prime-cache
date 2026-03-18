<?php
/**
 * Cloudflare integration — purge cache via Cloudflare API v4.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Cloudflare {

	const API_BASE = 'https://api.cloudflare.com/client/v4/';

	/** @var array */
	private $settings;

	public function __construct() {
		$this->settings = prime_cache_get_settings();

		if ( ! $this->is_enabled() ) {
			return;
		}

		// Purge on full cache clear.
		add_action( 'prime_cache_after_purge_all', array( $this, 'purge_everything' ) );

		// Debounced purge on individual URL clear.
		add_action( 'prime_cache_url_purged', array( $this, 'queue_url' ) );
		add_action( 'shutdown', array( $this, 'flush_queued_urls' ) );
	}

	private function is_enabled() {
		return ! empty( $this->settings['cloudflare_enabled'] )
			&& ! empty( $this->settings['cloudflare_zone_id'] )
			&& ( ! empty( $this->settings['cloudflare_api_key'] ) || defined( 'PRIME_CACHE_CF_API_TOKEN' ) );
	}

	/**
	 * Get auth headers for Cloudflare API.
	 */
	private function get_headers() {
		$headers = array( 'Content-Type' => 'application/json' );

		// API Token (preferred).
		if ( defined( 'PRIME_CACHE_CF_API_TOKEN' ) && PRIME_CACHE_CF_API_TOKEN ) {
			$headers['Authorization'] = 'Bearer ' . PRIME_CACHE_CF_API_TOKEN;
			return $headers;
		}

		$key = trim( $this->settings['cloudflare_api_key'] );

		// Bearer token format (starts with a long string without @).
		if ( strlen( $key ) > 37 && false === strpos( $key, '@' ) ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		} else {
			// Global API Key + Email format.
			$headers['X-Auth-Key']   = $key;
			$headers['X-Auth-Email'] = trim( $this->settings['cloudflare_email'] );
		}

		return $headers;
	}

	/**
	 * Purge everything (full zone purge).
	 */
	public function purge_everything() {
		$zone = trim( $this->settings['cloudflare_zone_id'] );
		$url  = self::API_BASE . 'zones/' . $zone . '/purge_cache';

		wp_remote_request( $url, array(
			'method'    => 'POST',
			'timeout'   => 10,
			'sslverify' => true,
			'headers'   => $this->get_headers(),
			'body'      => wp_json_encode( array( 'purge_everything' => true ) ),
		) );
	}

	/**
	 * Purge specific URLs (up to 30 per API call).
	 *
	 * @param array $urls URLs to purge.
	 * @return bool|WP_Error
	 */
	public function purge_urls( $urls ) {
		$zone = trim( $this->settings['cloudflare_zone_id'] );
		$url  = self::API_BASE . 'zones/' . $zone . '/purge_cache';

		// CF API limit: 30 URLs per call.
		$chunks = array_chunk( $urls, 30 );
		$result = true;

		foreach ( $chunks as $chunk ) {
			$response = wp_remote_request( $url, array(
				'method'    => 'POST',
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => $this->get_headers(),
				'body'      => wp_json_encode( array( 'files' => array_values( $chunk ) ) ),
			) );

			if ( is_wp_error( $response ) ) {
				$result = $response;
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $code || ( isset( $body['success'] ) && ! $body['success'] ) ) {
				$error_msg = 'Cloudflare API error (HTTP ' . $code . ')';
				if ( ! empty( $body['errors'][0]['message'] ) ) {
					$error_msg = $body['errors'][0]['message'];
				}
				$result = new WP_Error( 'cloudflare_purge_failed', $error_msg );
			}
		}

		return $result;
	}

	/** @var array Queued URLs for batch purge. */
	private static $queued_urls = array();

	/**
	 * Queue a URL for batch purge on shutdown.
	 */
	public function queue_url( $url ) {
		if ( ! empty( $url ) ) {
			self::$queued_urls[] = $url;
		}
	}

	/**
	 * Flush all queued URLs in one API call.
	 */
	public function flush_queued_urls() {
		if ( empty( self::$queued_urls ) ) {
			return;
		}

		$urls = array_unique( self::$queued_urls );

		// If many URLs, just purge everything.
		if ( count( $urls ) > 30 ) {
			$this->purge_everything();
		} else {
			$this->purge_urls( $urls );
		}

		self::$queued_urls = array();
	}
}
