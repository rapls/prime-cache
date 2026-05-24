<?php
/**
 * Image Converter — WebP conversion, delivery, bulk processing.
 *
 * WebP conversion and delivery ship in Prime Cache (Free). Additional formats
 * (e.g. AVIF) are provided by a separate add-on that hooks the extension
 * points defined here:
 *  - do_action( 'prime_cache_convert_image_extra', $source )
 *  - apply_filters( 'prime_cache_image_needs_conversion', $needs, $base )
 *  - apply_filters( 'prime_cache_picture_extra_sources', $extra, ... )
 *  - apply_filters( 'prime_cache_url_rewrite_format', $picked, ... )
 *
 * Features:
 *  - Convert JPG/PNG to WebP on upload (all thumbnail sizes)
 *  - Lossy / Lossless / Custom quality modes
 *  - Auto-remove converted files if larger than original
 *  - Serve WebP via URL rewrite or <picture> tag
 *  - Batch conversion of existing images via AJAX
 *  - Per-folder inclusion / exclusion
 *  - Conversion statistics and a Media Library column
 *
 * NOTE: Image resizing and EXIF stripping on upload are handled separately by
 * Prime_Cache_Media_Optimizer (wp_handle_upload → process_upload). They are
 * intentionally NOT implemented here to avoid double-processing.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Image_Converter {

	/** @var array */
	private $settings;

	public function __construct() {
		// Defer hook registration until all plugins are loaded. The Free engine is
		// instantiated while prime-cache.php loads (before any add-on is even
		// included), so the add-on's Prime_Cache_WebP class is not available yet
		// at this point. Waiting for plugins_loaded (priority 30, after a typical
		// add-on's priority-20 module init) lets us reliably detect that engine
		// and avoid double-registering the same image hooks.
		if ( did_action( 'plugins_loaded' ) ) {
			$this->init();
		} else {
			add_action( 'plugins_loaded', array( $this, 'init' ), 30 );
		}
	}

	/**
	 * Register upload/delivery/AJAX/column hooks once Pro detection is reliable.
	 */
	public function init() {
		// When the Pro add-on's Prime_Cache_WebP engine is present it registers
		// the same upload/delivery/AJAX/column hooks. Defer to it entirely to
		// avoid double-processing every image and emitting duplicate
		// <source>/column markup. Free's engine drives WebP when it is absent.
		if ( class_exists( 'Prime_Cache_WebP' ) ) {
			return;
		}

		$this->settings = prime_cache_get_settings();

		// WebP is the format produced by Free. Additional formats (AVIF) are
		// added by an add-on via the prime_cache_convert_image_extra action. An
		// add-on declares it provides a format via prime_cache_image_has_extra_formats
		// so the upload/delivery pipeline still activates for an add-on-only
		// configuration (e.g. AVIF enabled while WebP is disabled).
		$webp_ok       = ! empty( $this->settings['webp_enabled'] );
		$extra_formats = (bool) apply_filters( 'prime_cache_image_has_extra_formats', false );
		$active        = ! empty( $this->settings['img_conversion_enabled'] ) && ( $webp_ok || $extra_formats );

		// Auto-optimize on upload.
		if ( $active && ! empty( $this->settings['img_auto_optimize'] ) ) {
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_upload' ), 10, 2 );
		}

		// Frontend delivery. Pick the HTML transform by delivery method:
		//  - 'picture': wrap <img> in <picture> with <source> variants (browser
		//    negotiates; body is identical for every browser, cache-safe).
		//  - 'url': swap <img> URLs to .webp per the Accept header and emit
		//    Vary: Accept so the page cache stores a variant per Accept value.
		//  - 'rewrite': served entirely at the server level by the .htaccess image
		//    rules; the HTML is left UNTOUCHED. Rewriting it here would vary the
		//    body by Accept without a Vary header and serve the wrong format from
		//    the page cache, so no HTML callback is registered for this method.
		$delivery      = $this->settings['img_delivery_method'];
		$webp_callback = null;
		if ( 'picture' === $delivery ) {
			$webp_callback = array( $this, 'rewrite_to_picture_tags' );
		} elseif ( 'url' === $delivery ) {
			$webp_callback = array( $this, 'rewrite_html' );
		}
		if ( $active && $webp_callback && ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() ) {
			global $prime_cache_html_pipeline;
			if ( $prime_cache_html_pipeline ) {
				$prime_cache_html_pipeline->register( 'webp', $webp_callback, 30 );
			} else {
				add_action( 'template_redirect', function() use ( $webp_callback ) { ob_start( $webp_callback ); }, 3 );
			}
		}

		// AJAX batch handlers (registered regardless — bulk/column work for WebP in Free).
		add_action( 'wp_ajax_pc_img_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_pc_img_batch', array( $this, 'ajax_batch' ) );
		add_action( 'wp_ajax_pc_img_stats', array( $this, 'ajax_stats' ) );

		// Media Library column.
		add_filter( 'manage_media_columns', array( $this, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
	}

	// ── Upload Hooks ─────────────────────────────────────────

	/**
	 * Convert uploaded image and all thumbnails to WebP. Add-ons may produce
	 * additional formats via the prime_cache_convert_image_extra action that
	 * convert_image() fires for each source file.
	 */
	public function on_upload( $metadata, $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_readable( $file ) ) {
			return $metadata;
		}

		if ( $this->is_excluded( $file ) ) {
			return $metadata;
		}

		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return $metadata;
		}

		// Skip PNG if excluded.
		if ( 'png' === $ext && ! empty( $this->settings['img_exclude_png'] ) ) {
			return $metadata;
		}

		$files_to_convert = array( $file );

		// Add thumbnails.
		if ( ! empty( $metadata['sizes'] ) ) {
			$dir = dirname( $file );
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files_to_convert[] = $dir . '/' . $size['file'];
				}
			}
		}

		$orig_total = 0;
		$conv_total = 0;

		foreach ( $files_to_convert as $src ) {
			if ( ! is_readable( $src ) ) {
				continue;
			}
			$orig_size = filesize( $src );
			$orig_total += $orig_size;

			$this->convert_image( $src );

			// Pick the best variant size.
			$best = $orig_size;
			if ( file_exists( $src . '.avif' ) ) {
				$best = min( $best, filesize( $src . '.avif' ) );
			}
			if ( file_exists( $src . '.webp' ) ) {
				$best = min( $best, filesize( $src . '.webp' ) );
			}
			$conv_total += $best;
		}

		// Read old meta BEFORE overwriting (for stats dedup).
		$old_meta = get_post_meta( $attachment_id, '_prime_cache_img_opt', true );

		// Determine format badges — check main file AND all thumbnails.
		$has_webp = file_exists( $file . '.webp' );
		$has_avif = file_exists( $file . '.avif' );
		foreach ( $files_to_convert as $src ) {
			if ( $src === $file ) continue;
			if ( ! $has_webp && file_exists( $src . '.webp' ) ) $has_webp = true;
			if ( ! $has_avif && file_exists( $src . '.avif' ) ) $has_avif = true;
			if ( $has_webp && $has_avif ) break;
		}

		// Only record the attachment as optimized when at least one variant was
		// actually produced. If both formats failed (unsupported server, too-large
		// image) or were removed by Delete Larger Conversions, leave it unmarked
		// so it shows as not-optimized, isn't counted in stats, and is re-detected
		// by a later successful conversion.
		if ( ! $has_webp && ! $has_avif ) {
			// If this attachment was previously recorded as optimized (e.g. the
			// source was later replaced with one that can't be converted), clear
			// the stale record and roll back its contribution to the aggregate
			// stats so the Media Library doesn't show a phantom "optimized" badge.
			if ( is_array( $old_meta ) ) {
				if ( ! empty( $old_meta['stats_counted'] ) ) {
					$stats              = get_option( 'prime_cache_img_stats', array( 'converted' => 0, 'saved' => 0 ) );
					$stats['converted'] = max( 0, ( $stats['converted'] ?? 0 ) - 1 );
					$stats['saved']     = max( 0, ( $stats['saved'] ?? 0 ) - (int) ( $old_meta['stats_saved'] ?? 0 ) );
					update_option( 'prime_cache_img_stats', $stats, false );
				}
				delete_post_meta( $attachment_id, '_prime_cache_img_opt' );
			}
			return $metadata;
		}

		// Save per-attachment optimization data.
		$savings = $orig_total > 0 ? round( ( 1 - $conv_total / $orig_total ) * 100, 1 ) : 0;
		update_post_meta( $attachment_id, '_prime_cache_img_opt', array(
			'original' => $orig_total,
			'optimized' => $conv_total,
			'savings'   => $savings,
			'webp'      => $has_webp,
			'avif'      => $has_avif,
			'time'      => time(),
		) );

		$this->update_stats( $attachment_id, $old_meta );

		return $metadata;
	}

	// ── Conversion ───────────────────────────────────────────

	/**
	 * Convert a single image file to WebP.
	 *
	 * After the WebP attempt, fires prime_cache_convert_image_extra so an
	 * add-on can produce additional formats (e.g. AVIF) from the same source.
	 */
	public function convert_image( $source_path ) {
		$results = array();

		if ( ! empty( $this->settings['webp_enabled'] ) ) {
			$webp = $this->create_variant( $source_path, 'webp' );
			if ( $webp ) {
				$results['webp'] = $webp;
			}
		}

		// Extension point: add-ons (e.g. AVIF) convert the same source here.
		do_action( 'prime_cache_convert_image_extra', $source_path );

		return $results;
	}

	/**
	 * Create a WebP variant of an image.
	 */
	private function create_variant( $source, $format ) {
		$dest = $source . '.' . $format;

		// Skip if exists and newer.
		if ( file_exists( $dest ) && filemtime( $dest ) >= filemtime( $source ) ) {
			return $dest;
		}

		$quality = $this->get_quality( $format );
		$method  = $this->settings['img_converter'];

		$success = false;

		// Try the preferred engine, then always fall back to GD. GD is the most
		// widely available library, so it is the universal last resort even when
		// the stored setting is 'imagick' — otherwise a site that downgraded from
		// Pro with img_converter='imagick' on a host without Imagick would fail
		// every conversion. ('gd' skips the Imagick attempt above entirely.)
		if ( 'imagick' === $method || ( 'auto' === $method && class_exists( 'Imagick' ) ) ) {
			$success = $this->convert_imagick( $source, $dest, $format, $quality );
		}

		if ( ! $success ) {
			$success = $this->convert_gd( $source, $dest, $format, $quality );
		}

		if ( ! $success ) {
			@unlink( $dest );
			return false;
		}

		// Auto-remove if larger than original.
		if ( ! empty( $this->settings['img_auto_remove_larger'] ) && file_exists( $dest ) ) {
			if ( filesize( $dest ) >= filesize( $source ) ) {
				@unlink( $dest );
				// Record that this format was attempted but intentionally skipped
				// (the variant came out larger than the original). The bulk
				// scanner treats this empty marker as "handled" so the image is
				// not re-detected as unoptimized on every scan. A newer source or
				// a successful future conversion clears it (below).
				@touch( $dest . '.skip' );
				return false;
			}
		}

		// A real variant exists now — drop any stale skip marker from a prior run.
		@unlink( $dest . '.skip' );

		return $dest;
	}

	/**
	 * Whether an image still needs conversion for the enabled formats.
	 *
	 * An empty "<file>.<fmt>.skip" sidecar means a previous run produced a
	 * variant larger than the original and deleted it (Delete Larger
	 * Conversions), so the image is treated as already handled instead of being
	 * re-listed by the bulk scanner forever.
	 *
	 * @param string $base      Absolute source image path (e.g. /path/img.jpg).
	 * @param bool   $want_webp Whether WebP output is desired.
	 * @return bool
	 */
	private function needs_conversion( $base, $want_webp ) {
		// Only honor the "larger than original" skip markers while Delete Larger
		// Conversions is enabled. If the user turns that option off, larger
		// variants are now acceptable, so previously-skipped images must be
		// re-detected and converted instead of being suppressed forever.
		$honor_skip = ! empty( $this->settings['img_auto_remove_larger'] );
		// A skip marker is only valid while it is at least as new as the source.
		// If the source image was replaced (or its mtime bumped) after the marker
		// was written, the marker is stale and the image must be re-evaluated —
		// otherwise a replaced original could never be re-converted by the scanner.
		$skip_valid = $honor_skip && file_exists( $base . '.webp.skip' )
			&& @filemtime( $base . '.webp.skip' ) >= @filemtime( $base );
		$webp_need  = $want_webp && ! file_exists( $base . '.webp' ) && ! $skip_valid;

		// Extension point: an add-on may OR-in a need for additional formats
		// (e.g. a missing AVIF variant). Free computes the WebP need only.
		return (bool) apply_filters( 'prime_cache_image_needs_conversion', $webp_need, $base );
	}

	private function get_quality( $format ) {
		$mode = $this->settings['img_quality_mode'];
		if ( 'lossless' === $mode ) {
			return 100;
		}
		return max( 1, min( 100, (int) $this->settings['webp_quality'] ) );
	}

	/**
	 * Estimate whether GD can decode an image of this size without exhausting
	 * PHP's memory_limit. GD loads the entire uncompressed bitmap into PHP
	 * memory (~4-5 bytes/pixel) plus transient working copies during decode, so
	 * a very large source image can fatal the request with "Allowed memory size
	 * exhausted". getimagesize() reads only the header, so the job can be sized
	 * up front and skipped gracefully. Imagick is intentionally not checked
	 * here — it stores pixels in its own memory (and can spill to disk via its
	 * resource limits), so it is not bound by PHP's memory_limit the way GD is.
	 *
	 * @param string $source Absolute path to the source image.
	 * @return bool True if GD decoding is expected to fit in available memory.
	 */
	private function gd_can_decode( $source ) {
		$info = @getimagesize( $source );
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return false; // Unknown dimensions — do not risk a fatal.
		}

		// Give image work the same headroom WordPress core grants before bailing.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$limit = $this->memory_limit_bytes();
		if ( $limit < 0 ) {
			return true; // memory_limit = -1 (unlimited).
		}

		// Estimate peak memory: GD holds the full truecolor bitmap (4 bytes/px)
		// while it decodes; high-bit-depth or extra-channel sources can need
		// more, so take the larger of the 4-byte canvas and the decoded source
		// (bits/8 × channels). A 1.8x factor covers libjpeg/libpng scanline
		// buffers and the transient palette→truecolor copy on indexed PNGs.
		// This keeps ordinary 12–24MP photos converting on a 256MB limit while
		// still skipping the multi-tens-of-megapixel originals that fatal.
		$bits         = ( isset( $info['bits'] ) && $info['bits'] > 0 ) ? (int) $info['bits'] : 8;
		$channels     = ( isset( $info['channels'] ) && $info['channels'] > 0 ) ? (int) $info['channels'] : 3;
		$bytes_per_px = max( 4.0, ( $bits / 8 ) * $channels );
		$needed       = (int) ( (float) $info[0] * (float) $info[1] * $bytes_per_px * 1.8 );
		$available    = $limit - memory_get_usage( true );

		return $needed > 0 && $needed < $available;
	}

	/**
	 * Resolve PHP's memory_limit to bytes. Returns -1 when unlimited or unparseable.
	 *
	 * @return int Bytes, or -1 for "no limit".
	 */
	private function memory_limit_bytes() {
		$raw = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $raw || '-1' === $raw ) {
			return -1;
		}
		$value = (int) $raw;
		switch ( strtolower( substr( $raw, -1 ) ) ) {
			case 'g': $value *= 1024; // fall through.
			case 'm': $value *= 1024; // fall through.
			case 'k': $value *= 1024;
		}
		return $value;
	}

	private function convert_gd( $source, $dest, $format, $quality ) {
		// Free only encodes WebP. Reject any other format outright so no
		// additional-format encoding paths live in the Free engine.
		if ( 'webp' !== $format ) {
			return false;
		}

		$ext = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );

		// Skip before decoding if the uncompressed bitmap would not fit in PHP's
		// memory_limit — prevents an "Allowed memory size exhausted" fatal on
		// very large source images.
		if ( ! $this->gd_can_decode( $source ) ) {
			return false;
		}

		switch ( $ext ) {
			case 'jpg': case 'jpeg': $img = @imagecreatefromjpeg( $source ); break;
			case 'png':
				$img = @imagecreatefrompng( $source );
				if ( $img ) { imagepalettetotruecolor( $img ); imagealphablending( $img, true ); imagesavealpha( $img, true ); }
				break;
			default: return false;
		}

		if ( ! $img ) {
			return false;
		}

		$result = false;
		if ( function_exists( 'imagewebp' ) ) {
			$result = imagewebp( $img, $dest, $quality );
		}

		imagedestroy( $img );

		if ( $result && file_exists( $dest ) && filesize( $dest ) > 0 ) {
			return true;
		}
		@unlink( $dest );
		return false;
	}

	private function convert_imagick( $source, $dest, $format, $quality ) {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}

		// Free only encodes WebP. Reject any other format outright so no
		// additional-format encoding paths live in the Free engine.
		if ( 'webp' !== $format ) {
			return false;
		}

		try {
			$img = new Imagick( $source );

			// Strip EXIF if enabled.
			if ( ! empty( $this->settings['img_strip_exif'] ) ) {
				$img->stripImage();
			}

			$img->setImageFormat( 'webp' );
			$img->setImageCompressionQuality( $quality );
			$img->setOption( 'webp:method', '4' );
			if ( 100 === $quality ) {
				$img->setOption( 'webp:lossless', 'true' );
			}

			$result = $img->writeImage( $dest );
			$img->destroy();
			return $result;
		} catch ( Exception $e ) {
			return false;
		}
	}

	// ── Frontend Delivery ────────────────────────────────────

	/**
	 * Rewrite img src/srcset to WebP (URL swap method).
	 */
	public function rewrite_html( $html ) {
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		// URL delivery rewrites <img> URLs per the Accept header, so the response
		// body is browser-specific. The page-cache dropin keys cache by host /
		// path / scheme / mobile / cookies / query string — NOT by Accept — so a
		// single cached body would be reused across browsers, serving .webp URLs
		// to clients that can't render them. Advertise Vary: Accept (appended
		// so an existing Vary like Cookie is preserved) for any downstream/CDN
		// cache, and exclude this response from the page cache so the negotiated
		// body is never reused. This is done for every response in URL mode —
		// including clients that accept neither format — so a non-negotiated body
		// is never cached and later handed to a capable browser. (rewrite_html is
		// only registered for the 'url' delivery method.)
		header( 'Vary: Accept', false );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$accept   = isset( $_SERVER['HTTP_ACCEPT'] ) ? $_SERVER['HTTP_ACCEPT'] : '';
		$use_webp = ! empty( $this->settings['webp_enabled'] ) && false !== strpos( $accept, 'image/webp' );

		// Choose the format that actually exists for EACH image, per-image:
		// WebP if available, else leave the original. An add-on may return a
		// higher-priority format (e.g. 'avif') via the filter below — Free
		// computes its own WebP pick first so it is the default.
		$pick = function ( $base ) use ( $use_webp, $accept ) {
			$webp_pick = ( $use_webp && $this->variant_exists( $base, 'webp' ) ) ? 'webp' : null;
			return apply_filters( 'prime_cache_url_rewrite_format', $webp_pick, $base, $accept );
		};

		// Shield ONLY content that is not real page markup to rewrite: inline
		// scripts (avoid corrupting JS string literals) and client-side templates
		// (<template>, <script type="text/html">) and <textarea> (literal text /
		// code samples). Unlike the <picture> wrapping in rewrite_to_picture_tags(),
		// URL mode MUST still rewrite real <picture>/<source>/<noscript> <img>
		// markup, so those are intentionally NOT shielded here.
		$placeholders = array();
		$html = preg_replace_callback( '#<(?:script|template|textarea)[^>]*>.*?</(?:script|template|textarea)>#is', function( $m ) use ( &$placeholders ) {
			$key = '<!--PC_PROTECT_' . count( $placeholders ) . '-->';
			$placeholders[ $key ] = $m[0];
			return $key;
		}, $html );

		$html = preg_replace_callback( '#((?:src|srcset)\s*=\s*["\'])([^"\']+)(["\'])#i', function( $m ) use ( $pick ) {
			$attr  = $m[1];
			$value = $m[2];
			$close = $m[3];

			if ( stripos( $m[0], 'srcset' ) !== false ) {
				$parts = explode( ',', $value );
				$changed = false;
				foreach ( $parts as &$part ) {
					$part = trim( $part );
					// Match URL(.jpg|.png) with optional ?query, then optional descriptor.
					if ( preg_match( '#^(.+\.(jpe?g|png))(\?[^\s]*)?\s*(.*)$#i', $part, $pm ) ) {
						$url_base = $pm[1];
						$url_qs   = $pm[3] ?? '';
						$desc     = $pm[4] ?? '';
						$ext      = $pick( $url_base );
						if ( $ext ) {
							$part = $url_base . '.' . $ext . $url_qs . ( $desc ? ' ' . $desc : '' );
							$changed = true;
						}
					}
				}
				unset( $part );
				return $changed ? $attr . implode( ', ', $parts ) . $close : $m[0];
			}

			// Split URL from query string so the variant ext is inserted before ?query.
			$url_base = strtok( $value, '?' );
			$url_qs   = ( false !== strpos( $value, '?' ) ) ? '?' . strtok( '' ) : '';
			if ( preg_match( '#\.(jpe?g|png)$#i', $url_base ) ) {
				$ext = $pick( $url_base );
				if ( $ext ) {
					return $attr . $url_base . '.' . $ext . $url_qs . $close;
				}
			}

			return $m[0];
		}, $html );

		if ( ! empty( $placeholders ) ) {
			$html = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $html );
		}

		return $html;
	}

	/**
	 * Rewrite img tags to <picture> with WebP <source> elements.
	 *
	 * The prime_cache_picture_extra_sources filter lets an add-on prepend
	 * higher-priority <source> elements (e.g. AVIF) before Free's WebP source.
	 */
	public function rewrite_to_picture_tags( $html ) {
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		$webp_active = ! empty( $this->settings['webp_enabled'] );

		// Protect blocks that should not be processed: existing <picture> elements,
		// <template>, <script>, and <noscript> blocks. Replace with placeholders.
		$placeholders = array();
		$html = preg_replace_callback( '#<(?:picture|template|script|noscript|style)[^>]*>.*?</(?:picture|template|script|noscript|style)>#is', function( $m ) use ( &$placeholders ) {
			$key = '<!--PC_PROTECT_' . count( $placeholders ) . '-->';
			$placeholders[ $key ] = $m[0];
			return $key;
		}, $html );

		$html = preg_replace_callback( '#<img\s[^>]+>#i', function( $m ) use ( $webp_active ) {
			$tag = $m[0];

			// Skip data URIs, template bindings, and placeholder images.
			if ( preg_match( '#src=["\']data:|src=["\']{{|src=["\']$|data-skip-webp#i', $tag ) ) {
				return $tag;
			}

			// Match src, supporting query strings (e.g. image.jpg?ver=1.2).
			if ( ! preg_match( '#src=["\']([^"\'?]+\.(jpe?g|png))(\?[^"\']*)?["\']#i', $tag, $src_m ) ) {
				return $tag;
			}

			$src     = $src_m[1];
			$src_qs  = $src_m[3] ?? '';
			$sources = '';

			$has_srcset = preg_match( '#srcset=["\']([^"\']+)["\']#i', $tag, $ss_m );
			// Carry over sizes from <img> to <source> so browsers select the right
			// candidate from width-descriptor srcsets (e.g. 768w, 1536w).
			$sizes_attr = '';
			if ( preg_match( '#sizes=["\']([^"\']+)["\']#i', $tag, $sz_m ) ) {
				$sizes_attr = ' sizes="' . esc_attr( $sz_m[1] ) . '"';
			}

			// Extension point: prepend higher-priority <source> elements (e.g.
			// AVIF) from an add-on BEFORE Free's WebP source so they win
			// negotiation. The raw srcset is passed so the add-on can emit a
			// matching width-descriptor srcset (responsive selection). Free
			// passes '' so by default nothing is prepended.
			$srcset_raw = $has_srcset ? $ss_m[1] : '';
			$sources .= apply_filters( 'prime_cache_picture_extra_sources', '', $src, $src_qs, $sizes_attr, $srcset_raw );

			// WebP source.
			if ( $webp_active && $this->variant_exists( $src, 'webp' ) ) {
				if ( $has_srcset ) {
					$webp_srcset = $this->rewrite_srcset( $ss_m[1], 'webp' );
					if ( $webp_srcset ) {
						$sources .= '<source srcset="' . esc_attr( $webp_srcset ) . '"' . $sizes_attr . ' type="image/webp">';
					} else {
						$sources .= '<source srcset="' . esc_url( $src . '.webp' . $src_qs ) . '"' . $sizes_attr . ' type="image/webp">';
					}
				} else {
					$sources .= '<source srcset="' . esc_url( $src . '.webp' . $src_qs ) . '"' . $sizes_attr . ' type="image/webp">';
				}
			}

			if ( empty( $sources ) ) {
				return $tag;
			}

			return '<picture>' . $sources . $tag . '</picture>';
		}, $html );

		// Restore protected <picture> elements.
		if ( ! empty( $placeholders ) ) {
			$html = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $html );
		}

		return $html;
	}

	private function rewrite_srcset( $srcset, $format ) {
		$parts = explode( ',', $srcset );
		$changed = false;
		foreach ( $parts as &$part ) {
			$part = trim( $part );
			// Match: URL(.jpg|.png) optionally with ?query, then optional descriptor (768w, 2x).
			if ( preg_match( '#^(.+\.(jpe?g|png))(\?[^\s]*)?\s*(.*)$#i', $part, $pm ) ) {
				$url_base = $pm[1];
				$url_qs   = $pm[3] ?? '';
				$desc     = $pm[4] ?? '';
				if ( $this->variant_exists( $url_base, $format ) ) {
					$part = $url_base . '.' . $format . $url_qs . ( $desc ? ' ' . $desc : '' );
					$changed = true;
				}
			}
		}
		unset( $part );
		return $changed ? implode( ', ', $parts ) : false;
	}

	private function variant_exists( $url, $format ) {
		// Generic on-disk variant check. Strip query string before resolving
		// to a filesystem path.
		$clean_url = strtok( $url, '?' );
		$path = $this->url_to_path( $clean_url );
		return $path && file_exists( $path . '.' . $format );
	}

	// ── Batch Processing (AJAX) ──────────────────────────────

	/**
	 * AJAX: Scan for unconverted images.
	 */
	public function ajax_scan() {
		check_ajax_referer( 'pc_img_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		if ( empty( $this->settings['img_conversion_enabled'] ) ) {
			wp_send_json_error( array( 'message' => 'Image conversion is disabled.' ) );
		}

		// Free scans for missing WebP variants. An add-on can OR-in additional
		// formats via the prime_cache_image_needs_conversion filter.
		$want_webp = ! empty( $this->settings['webp_enabled'] );

		$to_convert = array();

		// Cap the number of items returned in one scan so the AJAX response stays
		// small and the request can't time out / exhaust memory on large media
		// libraries. The user re-runs "Scan" to pick up the next batch; the folder
		// scan below uses the same ceiling.
		$max = 2000;

		// 1. Media Library attachments (tracked by ID). The collected result set
		// is bounded by the $max cap below (we stop adding at $max), so the AJAX
		// response stays small on any library size. Re-running Scan picks up the
		// next batch because already-converted images drop out of
		// needs_conversion(), so the whole library is covered across runs without
		// a permanent LIMIT hiding older attachments. Only lightweight ID ints
		// are loaded here; the per-file work is what the cap bounds.
		global $wpdb;
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off admin bulk-scan over all image attachments; not cacheable.
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png') ORDER BY ID DESC"
		);

		foreach ( $ids as $id ) {
			if ( count( $to_convert ) >= $max ) {
				break;
			}
			$file = get_attached_file( $id );
			if ( ! $file || ! is_readable( $file ) || $this->is_excluded( $file ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			if ( 'png' === $ext && ! empty( $this->settings['img_exclude_png'] ) ) {
				continue;
			}
			$needs = $this->needs_conversion( $file, $want_webp );

			// Also check thumbnails for missing conversions.
			if ( ! $needs ) {
				$metadata = wp_get_attachment_metadata( $id );
				if ( ! empty( $metadata['sizes'] ) ) {
					$dir = dirname( $file );
					foreach ( $metadata['sizes'] as $size ) {
						if ( empty( $size['file'] ) ) continue;
						$thumb = $dir . '/' . $size['file'];
						if ( ! is_readable( $thumb ) ) continue;
						if ( $this->needs_conversion( $thumb, $want_webp ) ) { $needs = true; break; }
					}
				}
			}

			if ( $needs ) {
				$to_convert[] = array( 'type' => 'id', 'value' => (int) $id );
			}
		}

		// 2. Theme/plugin/custom folder images (tracked by path).
		$extra_dirs = array();
		if ( ! empty( $this->settings['img_include_themes'] ) ) {
			$extra_dirs[] = get_theme_root();
		}
		if ( ! empty( $this->settings['img_include_plugins'] ) ) {
			$extra_dirs[] = WP_PLUGIN_DIR;
		}
		$custom = trim( $this->settings['img_include_custom'] ?? '' );
		if ( $custom ) {
			foreach ( array_filter( array_map( 'trim', preg_split( '#[\r\n]+#', $custom ) ) ) as $d ) {
				if ( is_dir( $d ) ) $extra_dirs[] = rtrim( $d, '/' );
			}
		}

		foreach ( $extra_dirs as $dir ) {
			$this->scan_directory( $dir, $to_convert );
		}

		wp_send_json_success( array(
			'total' => count( $to_convert ),
			'items' => $to_convert,
		) );
	}

	/**
	 * Recursively scan a directory for unconverted images.
	 */
	private function scan_directory( $dir, &$items, $depth = 0 ) {
		if ( $depth > 5 || ! is_dir( $dir ) || count( $items ) > 2000 ) {
			return;
		}
		$handle = opendir( $dir );
		if ( ! $handle ) return;

		$want_webp = ! empty( $this->settings['webp_enabled'] );

		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry ) continue;
			$path = $dir . '/' . $entry;

			if ( is_dir( $path ) ) {
				$this->scan_directory( $path, $items, $depth + 1 );
				continue;
			}

			$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) continue;
			if ( 'png' === $ext && ! empty( $this->settings['img_exclude_png'] ) ) continue;
			if ( $this->is_excluded( $path ) ) continue;

			if ( $this->needs_conversion( $path, $want_webp ) ) {
				$items[] = array( 'type' => 'path', 'value' => $path );
			}
		}
		closedir( $handle );
	}

	/**
	 * AJAX: Process a batch of images.
	 */
	public function ajax_batch() {
		check_ajax_referer( 'pc_img_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		if ( empty( $this->settings['img_conversion_enabled'] ) ) {
			wp_send_json_error( array( 'message' => 'Image conversion is disabled.' ) );
		}

		$raw_items = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();
		$items = array();
		foreach ( $raw_items as $ri ) {
			if ( is_array( $ri ) && isset( $ri['type'], $ri['value'] ) ) {
				$items[] = $ri;
			}
		}
		if ( empty( $items ) ) {
			wp_send_json_success( array( 'processed' => 0, 'saved' => 0 ) );
		}

		$processed = 0;
		$saved     = 0;
		$start     = time();

		foreach ( $items as $item ) {
			if ( ( time() - $start ) > 25 ) break;

			$type  = sanitize_key( $item['type'] ?? '' );
			$value = $item['value'] ?? '';

			if ( 'id' === $type ) {
				// Media Library attachment.
				$id   = (int) $value;
				$file = get_attached_file( $id );
				if ( ! $file || ! is_readable( $file ) ) { $processed++; continue; }

				$orig_size = filesize( $file );
				$this->convert_image( $file );

				// Thumbnails.
				$metadata = wp_get_attachment_metadata( $id );
				if ( ! empty( $metadata['sizes'] ) ) {
					$dir = dirname( $file );
					foreach ( $metadata['sizes'] as $size ) {
						if ( ! empty( $size['file'] ) ) {
							$thumb = $dir . '/' . $size['file'];
							if ( is_readable( $thumb ) ) $this->convert_image( $thumb );
						}
					}
				}

				$this->calculate_savings( $file, $orig_size, $saved, $id );
				$old_meta = get_post_meta( $id, '_prime_cache_img_opt', true );
				// Only count it in the stats when a variant was actually written.
				if ( $this->save_attachment_meta( $id, $file ) ) {
					$this->update_stats( $id, $old_meta );
				}

			} elseif ( 'path' === $type ) {
				// Theme/plugin/custom file.
				$file = sanitize_text_field( $value );
				// Security: must be within ABSPATH or a configured custom include dir.
				$real = realpath( $file );
				if ( ! $real || ! is_readable( $real ) ) {
					$processed++;
					continue;
				}
				$allowed = false;
				if ( 0 === strpos( $real, realpath( ABSPATH ) ) ) {
					$allowed = true;
				} else {
					// Check against configured custom include directories.
					$custom_dirs = trim( $this->settings['img_include_custom'] ?? '' );
					if ( $custom_dirs ) {
						foreach ( preg_split( '#[\r\n]+#', $custom_dirs ) as $cdir ) {
							$cdir = trim( $cdir );
							if ( $cdir && is_dir( $cdir ) ) {
								$creal = realpath( $cdir );
								if ( $creal && 0 === strpos( $real, rtrim( $creal, '/' ) . '/' ) ) {
									$allowed = true;
									break;
								}
							}
						}
					}
				}
				if ( ! $allowed ) {
					$processed++;
					continue;
				}

				$orig_size = filesize( $real );
				$this->convert_image( $real );
				$this->calculate_savings( $real, $orig_size, $saved );
			}

			$processed++;
		}

		wp_send_json_success( array(
			'processed' => $processed,
			'saved'     => $saved,
		) );
	}

	private function calculate_savings( $file, $orig_size, &$saved, $attachment_id = 0 ) {
		// Main file savings.
		$best = $orig_size;
		if ( file_exists( $file . '.webp' ) ) {
			$best = min( $best, filesize( $file . '.webp' ) );
		}
		if ( file_exists( $file . '.avif' ) ) {
			$best = min( $best, filesize( $file . '.avif' ) );
		}
		if ( $best < $orig_size ) {
			$saved += $orig_size - $best;
		}

		// Thumbnail savings (Media Library attachments only).
		if ( $attachment_id > 0 ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $metadata['sizes'] ) ) {
				$dir = dirname( $file );
				foreach ( $metadata['sizes'] as $size ) {
					if ( empty( $size['file'] ) ) continue;
					$thumb = $dir . '/' . $size['file'];
					if ( ! is_readable( $thumb ) ) continue;
					$t_orig = filesize( $thumb );
					$t_best = $t_orig;
					if ( file_exists( $thumb . '.webp' ) ) {
						$t_best = min( $t_best, filesize( $thumb . '.webp' ) );
					}
					if ( file_exists( $thumb . '.avif' ) ) {
						$t_best = min( $t_best, filesize( $thumb . '.avif' ) );
					}
					if ( $t_best < $t_orig ) {
						$saved += $t_orig - $t_best;
					}
				}
			}
		}
	}

	/**
	 * Save optimization metadata for a media library attachment.
	 */
	private function save_attachment_meta( $attachment_id, $file ) {
		$orig = filesize( $file );
		$best = $orig;
		$has_webp = file_exists( $file . '.webp' );
		$has_avif = file_exists( $file . '.avif' );

		if ( $has_webp ) $best = min( $best, filesize( $file . '.webp' ) );
		if ( $has_avif ) $best = min( $best, filesize( $file . '.avif' ) );

		// Include thumbnail savings and check their conversion status too.
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $metadata['sizes'] ) ) {
			$dir = dirname( $file );
			foreach ( $metadata['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) continue;
				$thumb = $dir . '/' . $size['file'];
				if ( ! is_readable( $thumb ) ) continue;
				$t_orig = filesize( $thumb );
				$t_best = $t_orig;
				if ( file_exists( $thumb . '.webp' ) ) {
					$t_best = min( $t_best, filesize( $thumb . '.webp' ) );
					$has_webp = true; // Any file (main or thumb) converted = badge
				}
				if ( file_exists( $thumb . '.avif' ) ) {
					$t_best = min( $t_best, filesize( $thumb . '.avif' ) );
					$has_avif = true;
				}
				$orig += $t_orig;
				$best += $t_best;
			}
		}

		// Only record as optimized when at least one variant was actually
		// produced (same rule as on_upload). Otherwise a failed bulk conversion
		// — unsupported encoder, too-large image, or every variant removed by
		// Delete Larger Conversions — would be marked "0% optimized" and inflate
		// the global converted counter. Return whether anything was recorded so
		// the caller can skip update_stats() for failures.
		if ( ! $has_webp && ! $has_avif ) {
			return false;
		}

		$savings = $orig > 0 ? round( ( 1 - $best / $orig ) * 100, 1 ) : 0;

		update_post_meta( $attachment_id, '_prime_cache_img_opt', array(
			'original'  => $orig,
			'optimized' => $best,
			'savings'   => $savings,
			'webp'      => $has_webp,
			'avif'      => $has_avif,
			'time'      => time(),
		) );

		return true;
	}

	/**
	 * AJAX: Get optimization statistics.
	 */
	public function ajax_stats() {
		check_ajax_referer( 'pc_img_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		// Cache stats in a transient to avoid heavy DB + filesystem scan on every call.
		$cached = get_transient( 'prime_cache_img_stats_cache' );
		if ( false !== $cached ) {
			wp_send_json_success( $cached );
			return;
		}

		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin stats count over attachments; not cacheable.

		$webp_count = 0;
		$avif_count = 0;
		$total_saved = 0;

		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png') LIMIT 1000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin stats scan over attachments; not cacheable.
		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );
			if ( ! $file ) continue;

			// Collect all files: main + thumbnails.
			$all_files = array( $file );
			$meta = wp_get_attachment_metadata( $id );
			if ( ! empty( $meta['sizes'] ) ) {
				$dir = dirname( $file ) . '/';
				foreach ( $meta['sizes'] as $size_data ) {
					if ( ! empty( $size_data['file'] ) ) {
						$all_files[] = $dir . $size_data['file'];
					}
				}
			}

			$attachment_has_webp = false;
			$attachment_has_avif = false;

			foreach ( $all_files as $f ) {
				$has_webp = file_exists( $f . '.webp' );
				$has_avif = file_exists( $f . '.avif' );
				if ( $has_webp ) $attachment_has_webp = true;
				if ( $has_avif ) $attachment_has_avif = true;
				if ( $has_webp || $has_avif ) {
					$orig = @filesize( $f );
					if ( ! $orig ) continue;
					$best = $orig;
					if ( $has_webp ) $best = min( $best, filesize( $f . '.webp' ) );
					if ( $has_avif ) $best = min( $best, filesize( $f . '.avif' ) );
					$total_saved += $orig - $best;
				}
			}

			if ( $attachment_has_webp ) $webp_count++;
			if ( $attachment_has_avif ) $avif_count++;
		}

		$sampled = count( $ids );
		$result  = array(
			'total'      => $total,
			'sampled'    => $sampled,
			'webp'       => $webp_count,
			'avif'       => $avif_count,
			'saved'      => max( 0, $total_saved ),
			'saved_fmt'  => size_format( max( 0, $total_saved ) ),
			'is_sample'  => $sampled < $total,
		);

		set_transient( 'prime_cache_img_stats_cache', $result, 60 );
		wp_send_json_success( $result );
	}

	// ── Media Library Column ─────────────────────────────────

	/**
	 * Add "Prime Cache" column to the media library list table.
	 */
	public function add_media_column( $columns ) {
		$columns['prime_cache_opt'] = 'Prime Cache';
		return $columns;
	}

	/**
	 * Render the optimization info for each attachment in the media list.
	 */
	public function render_media_column( $column_name, $attachment_id ) {
		if ( 'prime_cache_opt' !== $column_name ) {
			return;
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			echo '<span style="color:#94a3b8">—</span>';
			return;
		}

		$meta = get_post_meta( $attachment_id, '_prime_cache_img_opt', true );

		if ( empty( $meta ) ) {
			// Not yet optimized — check if files exist anyway.
			$file = get_attached_file( $attachment_id );
			if ( $file ) {
				$has_webp = file_exists( $file . '.webp' );
				$has_avif = file_exists( $file . '.avif' );
				if ( $has_webp || $has_avif ) {
					$orig = filesize( $file );
					$best = $orig;
					if ( $has_webp ) $best = min( $best, filesize( $file . '.webp' ) );
					if ( $has_avif ) $best = min( $best, filesize( $file . '.avif' ) );
					$pct = $orig > 0 ? round( ( 1 - $best / $orig ) * 100, 1 ) : 0;
					$this->render_column_badge( $pct, $has_webp, $has_avif, $orig, $best );
					return;
				}
			}
			echo '<span style="color:#94a3b8;font-size:12px">' . esc_html__( 'Not optimized', 'prime-cache' ) . '</span>';
			return;
		}

		$this->render_column_badge(
			$meta['savings'] ?? 0,
			$meta['webp'] ?? false,
			$meta['avif'] ?? false,
			$meta['original'] ?? 0,
			$meta['optimized'] ?? 0
		);
	}

	/**
	 * Render the optimization badge HTML.
	 */
	private function render_column_badge( $savings, $has_webp, $has_avif, $orig, $opt ) {
		// Color based on savings percentage.
		if ( $savings >= 30 ) {
			$color = '#15803d'; $bg = '#dcfce7';
		} elseif ( $savings >= 10 ) {
			$color = '#a16207'; $bg = '#fef9c3';
		} else {
			$color = '#6b7280'; $bg = '#f3f4f6';
		}

		echo '<div style="font-size:12px;line-height:1.4">';
		echo '<span style="display:inline-block;padding:2px 7px;border-radius:8px;font-weight:600;background:' . esc_attr( $bg ) . ';color:' . esc_attr( $color ) . '">';
		echo esc_html( $savings ) . '%';
		echo '</span>';

		// Format badges.
		$formats = array();
		if ( $has_webp ) $formats[] = 'WebP';
		if ( $has_avif ) $formats[] = 'AVIF';
		if ( $formats ) {
			echo ' <span style="color:#94a3b8;font-size:11px">' . esc_html( implode( ' + ', $formats ) ) . '</span>';
		}

		// Size info.
		if ( $orig > 0 ) {
			$saved_bytes = $orig - $opt;
			echo '<br><span style="color:#94a3b8;font-size:11px">';
			echo esc_html( size_format( $orig ) ) . ' → ' . esc_html( size_format( $opt ) );
			if ( $saved_bytes > 0 ) {
				echo ' <span style="color:' . esc_attr( $color ) . '">(-' . esc_html( size_format( $saved_bytes ) ) . ')</span>';
			}
			echo '</span>';
		}

		echo '</div>';
	}

	// ── Stats ────────────────────────────────────────────────

	/**
	 * Update global image optimization stats.
	 *
	 * IMPORTANT: $old_meta must be read BEFORE save_attachment_meta() overwrites
	 * the post meta. Callers must pass the pre-update meta to this method.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|false $old_meta     Meta from BEFORE save_attachment_meta() ran.
	 */
	private function update_stats( $attachment_id, $old_meta = false ) {
		$new_meta = get_post_meta( $attachment_id, '_prime_cache_img_opt', true );

		$stats = get_option( 'prime_cache_img_stats', array( 'converted' => 0, 'saved' => 0 ) );

		// Calculate new saved bytes.
		$new_saved = 0;
		if ( ! empty( $new_meta['original'] ) && ! empty( $new_meta['optimized'] ) ) {
			$new_saved = max( 0, $new_meta['original'] - $new_meta['optimized'] );
		}

		// Check previous state from old_meta (passed by caller before overwrite).
		$was_counted = ! empty( $old_meta['stats_counted'] );
		$old_saved   = $was_counted && ! empty( $old_meta['stats_saved'] ) ? (int) $old_meta['stats_saved'] : 0;

		// Adjust: subtract old, add new.
		$stats['saved'] = max( 0, ( $stats['saved'] ?? 0 ) - $old_saved + $new_saved );

		if ( ! $was_counted ) {
			$stats['converted'] = ( $stats['converted'] ?? 0 ) + 1;
		}

		update_option( 'prime_cache_img_stats', $stats, false );

		// Persist stats tracking in the NEW meta.
		if ( is_array( $new_meta ) ) {
			$new_meta['stats_counted'] = true;
			$new_meta['stats_saved']   = $new_saved;
			update_post_meta( $attachment_id, '_prime_cache_img_opt', $new_meta );
		}
	}

	// ── Helpers ───────────────────────────────────────────────

	private function is_excluded( $file_path ) {
		// Check include folders first — file must be in at least one.
		if ( ! $this->is_in_included_folder( $file_path ) ) {
			return true;
		}

		// Then check exclude folders.
		// Use trailing slash to prevent /uploads matching /uploads-old.
		$folders = $this->get_exclude_folders();
		foreach ( $folders as $folder ) {
			if ( empty( $folder ) ) continue;
			$folder = rtrim( $folder, '/' ) . '/';
			if ( false !== strpos( $file_path, $folder ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if a file is inside one of the included folders.
	 * Uses trailing slash comparison to prevent /uploads matching /uploads-old.
	 */
	private function is_in_included_folder( $file_path ) {
		$dirs = $this->get_include_dirs();

		// If no dirs configured, allow all (backward compat).
		if ( empty( $dirs ) ) {
			return true;
		}

		foreach ( $dirs as $dir ) {
			$dir = rtrim( $dir, '/' ) . '/';
			if ( 0 === strpos( $file_path, $dir ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get all included directories based on settings.
	 */
	private function get_include_dirs() {
		$dirs = array();

		if ( ! empty( $this->settings['img_include_uploads'] ) ) {
			$upload = wp_upload_dir();
			if ( ! empty( $upload['basedir'] ) ) {
				$dirs[] = $upload['basedir'];
			}
		}

		if ( ! empty( $this->settings['img_include_themes'] ) ) {
			$dirs[] = get_theme_root();
		}

		if ( ! empty( $this->settings['img_include_plugins'] ) ) {
			$dirs[] = WP_PLUGIN_DIR;
		}

		// Custom folders.
		$custom = trim( $this->settings['img_include_custom'] ?? '' );
		if ( $custom ) {
			$custom_dirs = array_filter( array_map( 'trim', preg_split( '#[\r\n]+#', $custom ) ) );
			foreach ( $custom_dirs as $d ) {
				if ( is_dir( $d ) ) {
					$dirs[] = rtrim( $d, '/' );
				}
			}
		}

		return $dirs;
	}

	private function get_exclude_folders() {
		$raw = trim( $this->settings['img_exclude_folders'] ?? '' );
		if ( empty( $raw ) ) {
			return array();
		}
		return array_filter( array_map( 'trim', preg_split( '#[\r\n,]+#', $raw ) ) );
	}

	/**
	 * Get available conversion engines.
	 */
	public static function get_capabilities() {
		return array(
			'gd_webp'      => function_exists( 'imagewebp' ),
			'gd_avif'      => function_exists( 'imageavif' ),
			'imagick'      => class_exists( 'Imagick' ),
			'imagick_webp' => class_exists( 'Imagick' ) && in_array( 'WEBP', Imagick::queryFormats(), true ),
			'imagick_avif' => class_exists( 'Imagick' ) && count( @Imagick::queryFormats( 'AVIF' ) ) > 0,
		);
	}

	/**
	 * Whether the server can encode AVIF (GD imageavif() or Imagick AVIF support).
	 *
	 * Used by the admin to warn when AVIF is requested but the server cannot
	 * produce it. Independent of the Pro gate — this only reports raw server
	 * capability.
	 *
	 * @return bool
	 */
	public static function avif_supported() {
		if ( function_exists( 'imageavif' ) ) { return true; }
		if ( class_exists( 'Imagick' ) ) {
			try { $f = @Imagick::queryFormats( 'AVIF' ); if ( ! empty( $f ) ) return true; } catch ( \Exception $e ) {}
		}
		return false;
	}

	/**
	 * Resolve a URL to an absolute filesystem path within ABSPATH.
	 *
	 * This is a lightweight utility intentionally kept local to avoid coupling
	 * the converter to the Free plugin's file optimizer or media optimizer.
	 * The implementation is trivially identical to the url_to_path() helpers
	 * in Prime_Cache_File_Optimizer and Prime_Cache_Media_Optimizer.
	 */
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
}
