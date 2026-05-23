<?php
/**
 * Media Optimizer — adds missing image dimensions to prevent CLS.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Media_Optimizer {

	/** @var array */
	private $settings;

	public function __construct() {
		$this->settings = prime_cache_get_settings();

		// Upload-time image processing (works in admin context).
		if ( ! empty( $this->settings['img_strip_exif'] ) || ! empty( $this->settings['img_resize'] ) ) {
			add_filter( 'wp_handle_upload', array( $this, 'process_upload' ) );
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Add missing image dimensions (Free). An add-on registers its own
		// pipeline transforms for additional media optimizations.
		if ( ! empty( $this->settings['add_missing_dimensions'] ) ) {
			global $prime_cache_html_pipeline;
			if ( $prime_cache_html_pipeline ) {
				$prime_cache_html_pipeline->register( 'media_optimizer', array( $this, 'process' ), 20 );
			} else {
				add_action( 'template_redirect', function() { ob_start( array( $this, 'process' ) ); }, 4 );
			}
		}
	}

	public function process( $html ) {
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		if ( ! empty( $this->settings['add_missing_dimensions'] ) ) {
			$html = $this->add_image_dimensions( $html );
		}

		return $html;
	}

	/**
	 * Add missing width and height attributes to <img> tags to prevent CLS.
	 */
	private function add_image_dimensions( $html ) {
		return preg_replace_callback( '#<img\s[^>]+>#i', function( $m ) {
			$tag = $m[0];

			// Fix invalid width/height with unit suffixes (e.g. "313px" → "313").
			// HTML attributes must be plain numbers; units cause CLS.
			$tag = preg_replace( '#\b(width\s*=\s*["\'])(\d+)\s*px\s*(["\'])#i', '$1$2$3', $tag );
			$tag = preg_replace( '#\b(height\s*=\s*["\'])(\d+)\s*px\s*(["\'])#i', '$1$2$3', $tag );

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

			return preg_replace( '#^(<img)\b#i', '$1' . $add, $tag );
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

		// Use transient cache to avoid repeated file reads. Include filemtime in
		// the key so a re-uploaded image (same path, new bytes) does not return
		// stale dimensions for up to a week — that would resurrect CLS shifts on
		// the very pages this module is supposed to stabilize.
		$mtime     = @filemtime( $path );
		$cache_key = 'pc_imgdim_' . md5( $path . '|' . ( false === $mtime ? '0' : $mtime ) );
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
		return ( $real && Prime_Cache_File_Optimizer::path_within( $real, realpath( ABSPATH ) ) ) ? $real : false;
	}

	// ── Upload-time Image Processing ────────────────────────

	/**
	 * Process uploaded images: strip EXIF and/or resize.
	 *
	 * @param array $upload Upload data from wp_handle_upload.
	 * @return array Modified upload data.
	 */
	public function process_upload( $upload ) {
		if ( empty( $upload['file'] ) || ! empty( $upload['error'] ) ) {
			return $upload;
		}

		$file = $upload['file'];
		$type = $upload['type'] ?? '';

		// Only process JPEG images (EXIF and resize).
		if ( ! in_array( $type, array( 'image/jpeg', 'image/jpg' ), true ) ) {
			// PNG resize only (no EXIF).
			if ( 'image/png' === $type && ! empty( $this->settings['img_resize'] ) ) {
				$this->resize_image( $file, $type );
			}
			return $upload;
		}

		// Strip EXIF metadata.
		if ( ! empty( $this->settings['img_strip_exif'] ) ) {
			$this->strip_exif( $file );
		}

		// Resize oversized images.
		if ( ! empty( $this->settings['img_resize'] ) ) {
			$this->resize_image( $file, $type );
		}

		return $upload;
	}

	/**
	 * Strip EXIF metadata from a JPEG file by re-saving with GD.
	 *
	 * @param string $file Absolute file path.
	 */
	private function strip_exif( $file ) {
		if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
			return;
		}

		$img = @imagecreatefromjpeg( $file );
		if ( ! $img ) {
			return;
		}

		// Re-save without EXIF (GD doesn't preserve metadata).
		imagejpeg( $img, $file, 92 );
		imagedestroy( $img );
	}

	/**
	 * Resize an image if it exceeds max dimensions.
	 *
	 * @param string $file Absolute file path.
	 * @param string $type MIME type.
	 */
	private function resize_image( $file, $type ) {
		$max_w = (int) ( $this->settings['img_max_width'] ?? 2560 );
		$max_h = (int) ( $this->settings['img_max_height'] ?? 2560 );

		if ( $max_w <= 0 && $max_h <= 0 ) {
			return;
		}

		$size = @getimagesize( $file );
		if ( ! $size ) {
			return;
		}

		$orig_w = $size[0];
		$orig_h = $size[1];

		// Skip if already within limits.
		if ( ( $max_w <= 0 || $orig_w <= $max_w ) && ( $max_h <= 0 || $orig_h <= $max_h ) ) {
			return;
		}

		// Use WordPress's built-in image editor for safe resizing.
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return;
		}

		$new_w = $max_w > 0 ? min( $orig_w, $max_w ) : $orig_w;
		$new_h = $max_h > 0 ? min( $orig_h, $max_h ) : $orig_h;

		$editor->resize( $new_w, $new_h, false );
		$editor->save( $file );
	}
}
