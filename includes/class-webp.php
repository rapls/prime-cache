<?php
/**
 * Image Optimizer — WebP/AVIF conversion, EXIF removal, resize, delivery.
 *
 * Features:
 *  - Convert JPG/PNG to WebP and/or AVIF on upload (all thumbnail sizes)
 *  - Lossy / Lossless / Custom quality modes
 *  - Strip EXIF metadata
 *  - Resize oversized images on upload
 *  - Auto-remove converted files if larger than original
 *  - Serve WebP/AVIF via .htaccess rewrite or <picture> tag
 *  - Batch conversion of existing images via AJAX
 *  - Per-folder exclusion
 *  - Conversion statistics
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_WebP {

	/** @var array */
	private $settings;

	public function __construct() {
		$this->settings = prime_cache_get_settings();

		$conversion_on = ! empty( $this->settings['img_conversion_enabled'] );
		$active = $conversion_on && ( $this->settings['webp_enabled'] || $this->settings['avif_enabled'] );

		// Auto-optimize on upload.
		if ( $active && $this->settings['img_auto_optimize'] ) {
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_upload' ), 10, 2 );
		}

		// Resize on upload (priority 5 — runs first).
		if ( $this->settings['img_resize'] ) {
			add_filter( 'wp_handle_upload', array( $this, 'maybe_resize_upload' ), 5 );
		}

		// Strip EXIF on upload (priority 15 — runs after resize to avoid double re-encode).
		if ( $this->settings['img_strip_exif'] ) {
			add_filter( 'wp_handle_upload', array( $this, 'maybe_strip_exif' ), 15 );
		}

		// Frontend delivery.
		if ( $active && ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() ) {
			if ( 'picture' === $this->settings['img_delivery_method'] ) {
				add_action( 'template_redirect', function() { ob_start( array( $this, 'rewrite_to_picture_tags' ) ); }, 3 );
			} else {
				add_action( 'template_redirect', function() { ob_start( array( $this, 'rewrite_html' ) ); }, 3 );
			}
		}

		// AJAX batch handlers.
		add_action( 'wp_ajax_pc_img_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_pc_img_batch', array( $this, 'ajax_batch' ) );
		add_action( 'wp_ajax_pc_img_stats', array( $this, 'ajax_stats' ) );

		// Media Library column.
		add_filter( 'manage_media_columns', array( $this, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
	}

	// ── Upload Hooks ─────────────────────────────────────────

	/**
	 * Convert uploaded image and all thumbnails to WebP/AVIF.
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
		if ( 'png' === $ext && $this->settings['img_exclude_png'] ) {
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

		// Save per-attachment optimization data.
		$savings = $orig_total > 0 ? round( ( 1 - $conv_total / $orig_total ) * 100, 1 ) : 0;
		update_post_meta( $attachment_id, '_prime_cache_img_opt', array(
			'original' => $orig_total,
			'optimized' => $conv_total,
			'savings'   => $savings,
			'webp'      => file_exists( $file . '.webp' ),
			'avif'      => file_exists( $file . '.avif' ),
			'time'      => time(),
		) );

		$this->update_stats( $attachment_id );

		return $metadata;
	}

	/**
	 * Resize oversized images on upload.
	 */
	public function maybe_resize_upload( $upload ) {
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return $upload;
		}

		$ext = strtolower( pathinfo( $upload['file'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) {
			return $upload;
		}

		$max_w = (int) $this->settings['img_max_width'];
		$max_h = (int) $this->settings['img_max_height'];
		if ( $max_w <= 0 && $max_h <= 0 ) {
			return $upload;
		}

		$size = @getimagesize( $upload['file'] );
		if ( ! $size ) {
			return $upload;
		}

		$orig_w = $size[0];
		$orig_h = $size[1];

		if ( ( $max_w <= 0 || $orig_w <= $max_w ) && ( $max_h <= 0 || $orig_h <= $max_h ) ) {
			return $upload;
		}

		$editor = wp_get_image_editor( $upload['file'] );
		if ( is_wp_error( $editor ) ) {
			return $upload;
		}

		$editor->resize( $max_w ?: null, $max_h ?: null, false );
		$editor->save( $upload['file'] );

		return $upload;
	}

	/**
	 * Strip EXIF data from uploaded images.
	 */
	public function maybe_strip_exif( $upload ) {
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return $upload;
		}

		$ext = strtolower( pathinfo( $upload['file'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg' ), true ) ) {
			return $upload;
		}

		// Prefer Imagick (strips EXIF without re-encoding — no quality loss).
		if ( class_exists( 'Imagick' ) ) {
			try {
				$img = new Imagick( $upload['file'] );
				$img->stripImage();
				$img->writeImage( $upload['file'] );
				$img->destroy();
			} catch ( Exception $e ) {
				// Fallback below.
			}
			return $upload;
		}

		// GD fallback — re-encodes at quality 100 (slight generation loss).
		if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
			return $upload;
		}

		$img = @imagecreatefromjpeg( $upload['file'] );
		if ( $img ) {
			imagejpeg( $img, $upload['file'], 100 );
			imagedestroy( $img );
		}

		return $upload;
	}

	// ── Conversion ───────────────────────────────────────────

	/**
	 * Convert a single image file to WebP and/or AVIF.
	 */
	public function convert_image( $source_path ) {
		$results = array();

		if ( $this->settings['webp_enabled'] ) {
			$webp = $this->create_variant( $source_path, 'webp' );
			if ( $webp ) {
				$results['webp'] = $webp;
			}
		}

		if ( $this->settings['avif_enabled'] ) {
			$avif = $this->create_variant( $source_path, 'avif' );
			if ( $avif ) {
				$results['avif'] = $avif;
			}
		}

		return $results;
	}

	/**
	 * Create a WebP or AVIF variant of an image.
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

		// Try preferred method, then fallback.
		if ( 'imagick' === $method || ( 'auto' === $method && class_exists( 'Imagick' ) ) ) {
			$success = $this->convert_imagick( $source, $dest, $format, $quality );
		}

		if ( ! $success && ( 'gd' === $method || 'auto' === $method ) ) {
			$success = $this->convert_gd( $source, $dest, $format, $quality );
		}

		if ( ! $success ) {
			@unlink( $dest );
			return false;
		}

		// Auto-remove if larger than original.
		if ( $this->settings['img_auto_remove_larger'] && file_exists( $dest ) ) {
			if ( filesize( $dest ) >= filesize( $source ) ) {
				@unlink( $dest );
				return false;
			}
		}

		return $dest;
	}

	private function get_quality( $format ) {
		$mode = $this->settings['img_quality_mode'];
		if ( 'lossless' === $mode ) {
			return 'webp' === $format ? 100 : 100;
		}
		return 'webp' === $format
			? max( 1, min( 100, (int) $this->settings['webp_quality'] ) )
			: max( 1, min( 100, (int) $this->settings['avif_quality'] ) );
	}

	private function convert_gd( $source, $dest, $format, $quality ) {
		$ext = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );

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
		if ( 'webp' === $format && function_exists( 'imagewebp' ) ) {
			$result = imagewebp( $img, $dest, $quality );
		} elseif ( 'avif' === $format && function_exists( 'imageavif' ) ) {
			$result = imageavif( $img, $dest, $quality );
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

		try {
			$img = new Imagick( $source );

			// Strip EXIF if enabled.
			if ( $this->settings['img_strip_exif'] ) {
				$img->stripImage();
			}

			$img->setImageFormat( $format );
			$img->setImageCompressionQuality( $quality );

			if ( 'webp' === $format ) {
				$img->setOption( 'webp:method', '4' );
				if ( 100 === $quality ) {
					$img->setOption( 'webp:lossless', 'true' );
				}
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
	 * Rewrite img src/srcset to WebP/AVIF (URL swap method).
	 */
	public function rewrite_html( $html ) {
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		$accept  = isset( $_SERVER['HTTP_ACCEPT'] ) ? $_SERVER['HTTP_ACCEPT'] : '';
		$use_avif = $this->settings['avif_enabled'] && false !== strpos( $accept, 'image/avif' );
		$use_webp = $this->settings['webp_enabled'] && false !== strpos( $accept, 'image/webp' );

		if ( ! $use_avif && ! $use_webp ) {
			return $html;
		}

		// URL rewrite mode changes HTML based on Accept header. This creates a
		// variant mismatch with page cache — a WebP-rewritten page could be served
		// to a browser that doesn't support WebP. Disable page caching for safety.
		if ( 'url' === $this->settings['img_delivery_method'] ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			header( 'Vary: Accept' );
		}

		$target_ext = $use_avif ? 'avif' : 'webp';

		return preg_replace_callback( '#((?:src|srcset)\s*=\s*["\'])([^"\']+)(["\'])#i', function( $m ) use ( $target_ext ) {
			$attr  = $m[1];
			$value = $m[2];
			$close = $m[3];

			if ( stripos( $m[0], 'srcset' ) !== false ) {
				$parts = explode( ',', $value );
				$changed = false;
				foreach ( $parts as &$part ) {
					$part = trim( $part );
					if ( preg_match( '#^(.+\.(jpe?g|png))\s*(.*)$#i', $part, $pm ) ) {
						if ( $this->variant_exists( $pm[1], $target_ext ) ) {
							$part = $pm[1] . '.' . $target_ext . ( $pm[3] ? ' ' . $pm[3] : '' );
							$changed = true;
						}
					}
				}
				unset( $part );
				return $changed ? $attr . implode( ', ', $parts ) . $close : $m[0];
			}

			// Split URL from query string so .webp/.avif is inserted before ?query.
			$url_base = strtok( $value, '?' );
			$url_qs   = ( false !== strpos( $value, '?' ) ) ? '?' . strtok( '' ) : '';
			if ( preg_match( '#\.(jpe?g|png)$#i', $url_base ) ) {
				if ( $this->variant_exists( $url_base, $target_ext ) ) {
					return $attr . $url_base . '.' . $target_ext . $url_qs . $close;
				}
			}

			return $m[0];
		}, $html );
	}

	/**
	 * Rewrite img tags to <picture> with WebP/AVIF <source> elements.
	 */
	public function rewrite_to_picture_tags( $html ) {
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		// Protect blocks that should not be processed: existing <picture> elements,
		// <template>, <script>, and <noscript> blocks. Replace with placeholders.
		$placeholders = array();
		$html = preg_replace_callback( '#<(?:picture|template|script|noscript)[^>]*>.*?</(?:picture|template|script|noscript)>#is', function( $m ) use ( &$placeholders ) {
			$key = '<!--PC_PROTECT_' . count( $placeholders ) . '-->';
			$placeholders[ $key ] = $m[0];
			return $key;
		}, $html );

		$html = preg_replace_callback( '#<img\s[^>]+>#i', function( $m ) {
			$tag = $m[0];

			// Match src, supporting query strings (e.g. image.jpg?ver=1.2).
			if ( ! preg_match( '#src=["\']([^"\'?]+\.(jpe?g|png))(\?[^"\']*)?["\']#i', $tag, $src_m ) ) {
				return $tag;
			}

			$src     = $src_m[1];
			$src_qs  = $src_m[3] ?? '';
			$sources = '';

			$has_srcset = preg_match( '#srcset=["\']([^"\']+)["\']#i', $tag, $ss_m );

			// AVIF source.
			if ( $this->settings['avif_enabled'] && $this->variant_exists( $src, 'avif' ) ) {
				if ( $has_srcset ) {
					$avif_srcset = $this->rewrite_srcset( $ss_m[1], 'avif' );
					if ( $avif_srcset ) {
						$sources .= '<source srcset="' . esc_attr( $avif_srcset ) . '" type="image/avif">';
					}
				} else {
					$sources .= '<source srcset="' . esc_url( $src . '.avif' . $src_qs ) . '" type="image/avif">';
				}
			}

			// WebP source.
			if ( $this->settings['webp_enabled'] && $this->variant_exists( $src, 'webp' ) ) {
				if ( $has_srcset ) {
					$webp_srcset = $this->rewrite_srcset( $ss_m[1], 'webp' );
					if ( $webp_srcset ) {
						$sources .= '<source srcset="' . esc_attr( $webp_srcset ) . '" type="image/webp">';
					}
				} else {
					$sources .= '<source srcset="' . esc_url( $src . '.webp' . $src_qs ) . '" type="image/webp">';
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
		// Strip query string before resolving to filesystem path.
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

		$to_convert = array();

		// 1. Media Library attachments (tracked by ID).
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png') ORDER BY ID DESC"
		);

		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );
			if ( ! $file || ! is_readable( $file ) || $this->is_excluded( $file ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			if ( 'png' === $ext && $this->settings['img_exclude_png'] ) {
				continue;
			}
			$needs = false;
			if ( $this->settings['webp_enabled'] && ! file_exists( $file . '.webp' ) ) $needs = true;
			if ( $this->settings['avif_enabled'] && ! file_exists( $file . '.avif' ) ) $needs = true;
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

		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry ) continue;
			$path = $dir . '/' . $entry;

			if ( is_dir( $path ) ) {
				$this->scan_directory( $path, $items, $depth + 1 );
				continue;
			}

			$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) ) continue;
			if ( 'png' === $ext && $this->settings['img_exclude_png'] ) continue;
			if ( $this->is_excluded( $path ) ) continue;

			$needs = false;
			if ( $this->settings['webp_enabled'] && ! file_exists( $path . '.webp' ) ) $needs = true;
			if ( $this->settings['avif_enabled'] && ! file_exists( $path . '.avif' ) ) $needs = true;
			if ( $needs ) {
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

				$this->calculate_savings( $file, $orig_size, $saved );
				$this->save_attachment_meta( $id, $file );
				$this->update_stats( $id );

			} elseif ( 'path' === $type ) {
				// Theme/plugin/custom file.
				$file = sanitize_text_field( $value );
				// Security: must be within ABSPATH.
				$real = realpath( $file );
				if ( ! $real || 0 !== strpos( $real, realpath( ABSPATH ) ) || ! is_readable( $real ) ) {
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

	private function calculate_savings( $file, $orig_size, &$saved ) {
		if ( file_exists( $file . '.webp' ) ) {
			$saved += max( 0, $orig_size - filesize( $file . '.webp' ) );
		} elseif ( file_exists( $file . '.avif' ) ) {
			$saved += max( 0, $orig_size - filesize( $file . '.avif' ) );
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

		$savings = $orig > 0 ? round( ( 1 - $best / $orig ) * 100, 1 ) : 0;

		update_post_meta( $attachment_id, '_prime_cache_img_opt', array(
			'original'  => $orig,
			'optimized' => $best,
			'savings'   => $savings,
			'webp'      => $has_webp,
			'avif'      => $has_avif,
			'time'      => time(),
		) );
	}

	/**
	 * AJAX: Get optimization statistics.
	 */
	public function ajax_stats() {
		check_ajax_referer( 'pc_img_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$stats = get_option( 'prime_cache_img_stats', array( 'converted' => 0, 'saved' => 0 ) );

		// Count total images and converted.
		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png')" );

		$webp_count = 0;
		$avif_count = 0;
		$total_saved = 0;

		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png') LIMIT 1000" );
		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );
			if ( ! $file ) continue;
			if ( file_exists( $file . '.webp' ) ) {
				$webp_count++;
				$total_saved += filesize( $file ) - filesize( $file . '.webp' );
			}
			if ( file_exists( $file . '.avif' ) ) {
				$avif_count++;
				if ( ! file_exists( $file . '.webp' ) ) {
					$total_saved += filesize( $file ) - filesize( $file . '.avif' );
				}
			}
		}

		$sampled = count( $ids );
		wp_send_json_success( array(
			'total'      => $total,
			'sampled'    => $sampled,
			'webp'       => $webp_count,
			'avif'       => $avif_count,
			'saved'      => max( 0, $total_saved ),
			'saved_fmt'  => size_format( max( 0, $total_saved ) ),
			'is_sample'  => $sampled < $total,
		) );
	}

	// ── Helpers ───────────────────────────────────────────────

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

	private function update_stats( $attachment_id ) {
		$stats = get_option( 'prime_cache_img_stats', array( 'converted' => 0, 'saved' => 0 ) );
		$stats['converted'] = ( $stats['converted'] ?? 0 ) + 1;
		update_option( 'prime_cache_img_stats', $stats, false );
	}

	private function is_excluded( $file_path ) {
		// Check include folders first — file must be in at least one.
		if ( ! $this->is_in_included_folder( $file_path ) ) {
			return true;
		}

		// Then check exclude folders.
		$folders = $this->get_exclude_folders();
		foreach ( $folders as $folder ) {
			if ( ! empty( $folder ) && false !== strpos( $file_path, $folder ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if a file is inside one of the included folders.
	 */
	private function is_in_included_folder( $file_path ) {
		$dirs = $this->get_include_dirs();

		// If no dirs configured, allow all (backward compat).
		if ( empty( $dirs ) ) {
			return true;
		}

		foreach ( $dirs as $dir ) {
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
