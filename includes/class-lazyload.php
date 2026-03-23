<?php
/**
 * Lazy Load — defer loading of images, iframes, and videos.
 *
 * Uses native loading="lazy" attribute and IntersectionObserver fallback.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_LazyLoad {

	/** @var array */
	private $settings;

	public function __construct() {
		$this->settings = prime_cache_get_settings();

		// Disable WordPress native lazy loading (5.5+) if requested.
		if ( ! empty( $this->settings['lazyload_disable_native'] ) ) {
			add_filter( 'wp_lazy_loading_enabled', '__return_false' );
		}

		if ( ! $this->settings['lazyload_images'] && ! $this->settings['lazyload_iframes'] && ! $this->settings['lazyload_videos'] ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		global $prime_cache_html_pipeline;
		if ( $prime_cache_html_pipeline ) {
			$prime_cache_html_pipeline->register( 'lazyload', array( $this, 'process' ), 40 );
		} else {
			add_action( 'template_redirect', function() { ob_start( array( $this, 'process' ) ); }, 2 );
		}
	}

	/**
	 * Process HTML to add lazy loading attributes.
	 */
	public function process( $html ) {
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		$excludes = $this->parse_list( $this->settings['lazyload_exclude'] );
		$count    = 0;

		// Images.
		if ( $this->settings['lazyload_images'] ) {
			$html = preg_replace_callback( '#<img\s[^>]+>#i', function( $m ) use ( $excludes, &$count ) {
				$tag = $m[0];
				$count++;

				// Skip first 2 images (likely above the fold).
				if ( $count <= 2 ) {
					return $tag;
				}

				// Skip if already has loading attribute.
				if ( preg_match( '#loading\s*=#i', $tag ) ) {
					return $tag;
				}

				// Skip if fetchpriority="high" (LCP image).
				if ( false !== stripos( $tag, 'fetchpriority' ) ) {
					return $tag;
				}

				// Check exclusions.
				if ( $this->is_excluded( $tag, $excludes ) ) {
					return $tag;
				}

				// Use regex insertion to handle <img\n or <img\t variations.
				return preg_replace( '#^(<img)\b#i', '$1 loading="lazy"', $tag );
			}, $html );
		}

		// Iframes.
		if ( $this->settings['lazyload_iframes'] ) {
			$html = preg_replace_callback( '#<iframe\s[^>]+>#i', function( $m ) use ( $excludes ) {
				$tag = $m[0];

				if ( preg_match( '#loading\s*=#i', $tag ) ) {
					return $tag;
				}

				if ( $this->is_excluded( $tag, $excludes ) ) {
					return $tag;
				}

				return preg_replace( '#^(<iframe)\b#i', '$1 loading="lazy"', $tag );
			}, $html );
		}

		// Videos.
		if ( $this->settings['lazyload_videos'] ) {
			$html = preg_replace_callback( '#<video\s[^>]+>#i', function( $m ) use ( $excludes ) {
				$tag = $m[0];

				// Apply exclusion patterns to videos too.
				if ( $this->is_excluded( $tag, $excludes ) ) {
					return $tag;
				}

				$replaced = preg_replace( '#preload\s*=\s*["\'](?:auto|metadata)["\']#i', 'preload="none"', $tag );
				if ( null !== $replaced && $replaced !== $tag ) {
					$tag = $replaced;
				} elseif ( false === stripos( $tag, 'preload' ) ) {
					$tag = preg_replace( '#^(<video)\b#i', '$1 preload="none"', $tag );
				}

				return $tag;
			}, $html );
		}

		return $html;
	}

	private function is_excluded( $tag, $excludes ) {
		foreach ( $excludes as $pattern ) {
			if ( ! empty( $pattern ) && false !== stripos( $tag, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	private function parse_list( $value ) {
		if ( empty( $value ) ) {
			return array();
		}
		return array_filter( array_map( 'trim', preg_split( '#[\r\n,]+#', $value ) ) );
	}
}
