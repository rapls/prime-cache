<?php
/**
 * Media Optimizer — YouTube thumbnail replacement & missing image dimensions.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Media_Optimizer {

	/** @var array */
	private $settings;

	public function __construct() {
		$this->settings = prime_cache_get_settings();

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( $this->settings['youtube_thumbnail'] || $this->settings['add_missing_dimensions'] ) {
			add_action( 'template_redirect', function() {
				ob_start( array( $this, 'process' ) );
			}, 4 );
		}
	}

	public function process( $html ) {
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		if ( $this->settings['youtube_thumbnail'] ) {
			$html = $this->replace_youtube_iframes( $html );
		}

		if ( $this->settings['add_missing_dimensions'] ) {
			$html = $this->add_image_dimensions( $html );
		}

		return $html;
	}

	/**
	 * Replace YouTube iframes with lightweight thumbnail placeholders.
	 * Video loads only on click — dramatically reduces page weight.
	 */
	private function replace_youtube_iframes( $html ) {
		$pattern = '#<iframe[^>]+src=["\'](?:https?:)?//(?:www\.)?youtube(?:-nocookie)?\.com/embed/([a-zA-Z0-9_-]+)([^"\']*)["\'][^>]*></iframe>#i';

		$html = preg_replace_callback( $pattern, function( $m ) {
			$vid    = $m[1];
			$params = $m[2];
			$thumb  = "https://i.ytimg.com/vi/{$vid}/hqdefault.jpg";

			// Build embed URL for when user clicks.
			$embed_url = "https://www.youtube.com/embed/{$vid}?autoplay=1" . ( $params ? '&' . ltrim( $params, '?' ) : '' );

			// No inline onclick — use data attributes + event delegation for CSP compatibility.
			return '<div class="pc-yt-wrap" style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;max-width:100%;cursor:pointer;background:#000" data-pc-yt-src="' . esc_attr( $embed_url ) . '">'
				. '<img src="' . esc_url( $thumb ) . '" alt="YouTube video" loading="lazy" style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover">'
				. '<div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:68px;height:48px;background:rgba(255,0,0,.8);border-radius:14px;display:flex;align-items:center;justify-content:center">'
				. '<svg width="24" height="24" viewBox="0 0 24 24" fill="#fff"><path d="M8 5v14l11-7z"/></svg>'
				. '</div></div>';
		}, $html );

		// Enqueue external JS for YouTube click handling (CSP-compatible).
		if ( false !== strpos( $html, 'pc-yt-wrap' ) ) {
			add_action( 'wp_footer', array( $this, 'enqueue_yt_script' ), 99 );
		}

		return $html;
	}

	/**
	 * Add missing width and height attributes to <img> tags to prevent CLS.
	 */
	private function add_image_dimensions( $html ) {
		return preg_replace_callback( '#<img\s[^>]+>#i', function( $m ) {
			$tag = $m[0];

			// Skip if both width and height already present.
			if ( preg_match( '#\bwidth\s*=#i', $tag ) && preg_match( '#\bheight\s*=#i', $tag ) ) {
				return $tag;
			}

			// Extract src.
			if ( ! preg_match( '#src=["\']([^"\']+)["\']#i', $tag, $src_m ) ) {
				return $tag;
			}

			$src  = $src_m[1];
			$dims = $this->get_image_dimensions( $src );

			if ( ! $dims ) {
				return $tag;
			}

			// Add missing attributes.
			$add = '';
			if ( ! preg_match( '#\bwidth\s*=#i', $tag ) ) {
				$add .= ' width="' . (int) $dims[0] . '"';
			}
			if ( ! preg_match( '#\bheight\s*=#i', $tag ) ) {
				$add .= ' height="' . (int) $dims[1] . '"';
			}

			return str_replace( '<img ', '<img' . $add . ' ', $tag );
		}, $html );
	}

	/**
	 * Get image dimensions from local file.
	 *
	 * @return array|false [width, height] or false.
	 */
	private function get_image_dimensions( $url ) {
		$path = $this->url_to_path( $url );
		if ( ! $path || ! is_readable( $path ) ) {
			return false;
		}

		// Use transient cache to avoid repeated file reads.
		$cache_key = 'pc_imgdim_' . md5( $path );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$size = @getimagesize( $path );
		if ( ! $size || empty( $size[0] ) || empty( $size[1] ) ) {
			return false;
		}

		$dims = array( $size[0], $size[1] );
		set_transient( $cache_key, $dims, WEEK_IN_SECONDS );

		return $dims;
	}

	private function url_to_path( $url ) {
		$home_url = home_url( '/' );
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			$path = ABSPATH . ltrim( $url, '/' );
		} elseif ( 0 === strpos( $url, $home_url ) ) {
			$path = ABSPATH . substr( $url, strlen( $home_url ) );
		} else {
			return false;
		}
		$path = strtok( $path, '?' );
		$real = realpath( $path );
		return ( $real && 0 === strpos( $real, realpath( ABSPATH ) ) ) ? $real : false;
	}

	/**
	 * Print YouTube click handler script in footer.
	 */
	public function enqueue_yt_script() {
		wp_enqueue_script(
			'pc-yt',
			plugins_url( 'assets/pc-yt.js', dirname( __FILE__ ) ),
			array(),
			PRIME_CACHE_VERSION,
			array( 'strategy' => 'defer', 'in_footer' => true )
		);
	}
}
