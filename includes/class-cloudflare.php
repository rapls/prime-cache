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

		// Purge on full cache clear (with failure retry).
		add_action( 'prime_cache_after_purge_all', array( $this, 'purge_everything_safe' ) );
		add_action( 'prime_cache_cf_retry_full_purge', array( $this, 'purge_everything_safe' ) );

		// Debounced purge on individual URL clear.
		add_action( 'prime_cache_url_purged', array( $this, 'queue_url' ) );
		add_action( 'shutdown', array( $this, 'flush_queued_urls' ) );
		add_action( 'prime_cache_cf_deferred_purge', array( $this, 'run_deferred_purge' ) );
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

		$key  = trim( $this->settings['cloudflare_api_key'] );
		$mode = $this->settings['cloudflare_auth_mode'] ?? 'token';

		if ( 'global_key' === $mode ) {
			$headers['X-Auth-Key']   = $key;
			$headers['X-Auth-Email'] = trim( $this->settings['cloudflare_email'] );
		} else {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		return $headers;
	}

	/**
	 * Purge everything (full zone purge).
	 */
	/**
	 * Purge everything with failure retry.
	 */
	public function purge_everything_safe() {
		$result = $this->purge_everything();

		// Clear any pending retry events on success to prevent stale retries.
		if ( true === $result ) {
			delete_option( 'prime_cache_cf_full_purge_retries' );
			wp_clear_scheduled_hook( 'prime_cache_cf_retry_full_purge' );
			return;
		}

		// Failure — schedule retry with dedup guard.
		$retries = (int) get_option( 'prime_cache_cf_full_purge_retries', 0 );
		if ( $retries < 3 && ! wp_next_scheduled( 'prime_cache_cf_retry_full_purge' ) ) {
			update_option( 'prime_cache_cf_full_purge_retries', $retries + 1, false );
			wp_schedule_single_event( time() + 60, 'prime_cache_cf_retry_full_purge' );
		} elseif ( $retries >= 3 ) {
			delete_option( 'prime_cache_cf_full_purge_retries' );
		}
	}

	public function purge_everything() {
		$zone = trim( $this->settings['cloudflare_zone_id'] );
		$url  = self::API_BASE . 'zones/' . $zone . '/purge_cache';

		$response = wp_remote_request( $url, array(
			'method'    => 'POST',
			'timeout'   => 10,
			'sslverify' => true,
			'headers'   => $this->get_headers(),
			'body'      => wp_json_encode( array( 'purge_everything' => true ) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ( isset( $body['success'] ) && ! $body['success'] ) ) {
			$error_msg = 'Cloudflare API error (HTTP ' . $code . ')';
			if ( ! empty( $body['errors'][0]['message'] ) ) {
				$error_msg = $body['errors'][0]['message'];
			}
			return new WP_Error( 'cloudflare_purge_failed', $error_msg );
		}

		return true;
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
		self::$queued_urls = array();

		// Small batches (≤30): send immediately. On failure, queue for cron retry.
		// Larger batches: defer to cron to avoid blocking shutdown.
		if ( count( $urls ) <= 30 ) {
			$result = $this->purge_urls( $urls );
			if ( true !== $result ) {
				// API failed — queue for async retry instead of losing the purge.
				$existing = get_option( 'prime_cache_cf_purge_queue', array() );
				$merged   = array_unique( array_merge( $existing, $urls ) );
				update_option( 'prime_cache_cf_purge_queue', $merged, false );
				delete_option( 'prime_cache_cf_purge_retries' );
				if ( ! wp_next_scheduled( 'prime_cache_cf_deferred_purge' ) ) {
					wp_schedule_single_event( time() + 30, 'prime_cache_cf_deferred_purge' );
				}
			}
		} else {
			$existing = get_option( 'prime_cache_cf_purge_queue', array() );
			$merged   = array_unique( array_merge( $existing, $urls ) );
			update_option( 'prime_cache_cf_purge_queue', $merged, false );
			delete_option( 'prime_cache_cf_purge_retries' );
			if ( ! wp_next_scheduled( 'prime_cache_cf_deferred_purge' ) ) {
				wp_schedule_single_event( time(), 'prime_cache_cf_deferred_purge' );
			}
		}
	}

	/**
	 * Cron handler for deferred Cloudflare purge.
	 */
	public function run_deferred_purge() {
		$urls = get_option( 'prime_cache_cf_purge_queue', array() );
		if ( empty( $urls ) ) {
			return;
		}

		$result = $this->purge_urls( $urls );

		if ( true === $result ) {
			// Success — clear queue.
			delete_option( 'prime_cache_cf_purge_queue' );
		} else {
			// Failure — increment retry counter and re-schedule.
			$retries = (int) get_option( 'prime_cache_cf_purge_retries', 0 );
			if ( $retries >= 3 ) {
				// Give up after 3 retries to prevent infinite loop.
				delete_option( 'prime_cache_cf_purge_queue' );
				delete_option( 'prime_cache_cf_purge_retries' );
			} else {
				update_option( 'prime_cache_cf_purge_retries', $retries + 1, false );
				wp_schedule_single_event( time() + 60, 'prime_cache_cf_deferred_purge' );
			}
		}
	}
}
