<?php
/**
 * CDN — rewrite asset URLs to serve from a CDN hostname.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_CDN {

	/** @var array */
	private $settings;

	/** @var string */
	private $site_host;

	public function __construct() {
		$this->settings = prime_cache_get_settings();

		if ( empty( $this->settings['cdn_enabled'] ) || empty( $this->settings['cdn_hostname'] ) ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$this->site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		// Priority 5: CDN rewrite runs AFTER WebP URL rewrite (priority 3) in LIFO order.
		add_action( 'template_redirect', function() {
			ob_start( array( $this, 'rewrite' ) );
		}, 5 );
	}

	/**
	 * Rewrite URLs in HTML to CDN hostname.
	 */
	public function rewrite( $html ) {
		if ( strlen( $html ) < 255 ) {
			return $html;
		}

		$cdn_hosts = $this->get_cdn_hosts();
		if ( empty( $cdn_hosts ) ) {
			return $html;
		}

		$dirs     = $this->get_include_dirs();
		$excludes = $this->parse_list( $this->settings['cdn_exclude'] );
		$scheme   = is_ssl() ? 'https' : 'http';
		$site_url = home_url();

		// Build regex to match asset URLs in the included directories.
		$dirs_pattern = implode( '|', array_map( function( $d ) {
			return preg_quote( $d, '#' );
		}, $dirs ) );

		if ( empty( $dirs_pattern ) ) {
			return $html;
		}

		// Protect blocks where URL rewriting would cause breakage:
		// inline JS, JSON-LD, CSS, templates. Replace with placeholders.
		$cdn_placeholders = array();
		$html = preg_replace_callback( '#<(?:script|style|template|noscript)[^>]*>.*?</(?:script|style|template|noscript)>#is', function( $m ) use ( &$cdn_placeholders ) {
			$key = '<!--PC_CDN_PROTECT_' . count( $cdn_placeholders ) . '-->';
			$cdn_placeholders[ $key ] = $m[0];
			return $key;
		}, $html );

		// Match URLs starting with site URL or relative path containing include dirs.
		$pattern = '#(?:(?:' . preg_quote( $site_url, '#' ) . ')|(?:(?:https?:)?//' . preg_quote( $this->site_host, '#' ) . '))(/(?:' . $dirs_pattern . ')/[^\s"\'>]+)#i';

		$host_count = count( $cdn_hosts );
		$i = 0;

		$html = preg_replace_callback( $pattern, function( $m ) use ( $cdn_hosts, $host_count, &$i, $excludes, $scheme ) {
			$path = $m[1];

			// Check exclusions.
			foreach ( $excludes as $ex ) {
				if ( ! empty( $ex ) && false !== stripos( $path, $ex ) ) {
					return $m[0];
				}
			}

			// Round-robin across CDN hostnames.
			$cdn = $cdn_hosts[ $i % $host_count ];
			$i++;

			return $scheme . '://' . $cdn . $path;
		}, $html );

		// Also rewrite relative URLs (starting with /wp-content/ etc.) not caught above.
		if ( $this->settings['cdn_relative'] ) {
			// Handle src and href (single URL).
			$rel_pattern = '#((?:src|href)\s*=\s*["\'])(/(?:' . $dirs_pattern . ')/[^\s"\']+)#i';
			$html = preg_replace_callback( $rel_pattern, function( $m ) use ( $cdn_hosts, $host_count, &$i, $excludes, $scheme ) {
				$path = $m[2];
				foreach ( $excludes as $ex ) {
					if ( ! empty( $ex ) && false !== stripos( $path, $ex ) ) {
						return $m[0];
					}
				}
				$cdn = $cdn_hosts[ $i % $host_count ];
				$i++;
				return $m[1] . $scheme . '://' . $cdn . $path;
			}, $html );

			// Handle srcset separately — may contain multiple comma-separated candidates.
			$srcset_pattern = '#(srcset\s*=\s*["\'])([^"\']+)(["\'])#i';
			$html = preg_replace_callback( $srcset_pattern, function( $m ) use ( $cdn_hosts, $host_count, &$i, $excludes, $scheme, $dirs_pattern ) {
				$candidates = explode( ',', $m[2] );
				$rewritten  = array();
				// Use same CDN host for all candidates in this srcset attribute.
				$srcset_cdn = $cdn_hosts[ $i % $host_count ];
				$i++;
				foreach ( $candidates as $candidate ) {
					$candidate = trim( $candidate );
					$parts = preg_split( '#\s+#', $candidate, 2 );
					$url   = $parts[0];
					$desc  = isset( $parts[1] ) ? ' ' . $parts[1] : '';

					if ( preg_match( '#^/(?:' . $dirs_pattern . ')/#i', $url ) ) {
						$skip = false;
						foreach ( $excludes as $ex ) {
							if ( ! empty( $ex ) && false !== stripos( $url, $ex ) ) {
								$skip = true;
								break;
							}
						}
						if ( ! $skip ) {
							$url = $scheme . '://' . $srcset_cdn . $url;
						}
					}
					$rewritten[] = $url . $desc;
				}
				return $m[1] . implode( ', ', $rewritten ) . $m[3];
			}, $html );
		}

		// Restore protected blocks.
		if ( ! empty( $cdn_placeholders ) ) {
			$html = str_replace( array_keys( $cdn_placeholders ), array_values( $cdn_placeholders ), $html );
		}

		return $html;
	}

	/**
	 * Get CDN hostname(s).
	 */
	private function get_cdn_hosts() {
		$raw = trim( $this->settings['cdn_hostname'] );
		if ( empty( $raw ) ) {
			return array();
		}
		$hosts = array_filter( array_map( 'trim', preg_split( '#[\r\n,]+#', $raw ) ) );
		// Strip scheme if provided.
		return array_map( function( $h ) {
			return preg_replace( '#^https?://#i', '', rtrim( $h, '/' ) );
		}, $hosts );
	}

	/**
	 * Get directories to include for CDN rewriting.
	 */
	private function get_include_dirs() {
		$raw = trim( $this->settings['cdn_include_dirs'] );
		if ( empty( $raw ) ) {
			return array( 'wp-content', 'wp-includes' );
		}
		return array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	}

	private function parse_list( $value ) {
		if ( empty( $value ) ) {
			return array();
		}
		return array_filter( array_map( 'trim', preg_split( '#[\r\n,]+#', $value ) ) );
	}
}
