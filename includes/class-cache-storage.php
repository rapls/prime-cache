<?php
/**
 * Cache file storage: read, write, delete operations.
 */

defined( 'ABSPATH' ) || exit;

// Prime Cache manages its own cache files directly for performance; the
// WP_Filesystem API is not used on these cache paths. Disable the direct-file
// sniff for this module.
// phpcs:disable WordPress.WP.AlternativeFunctions

class Prime_Cache_Storage {

	/**
	 * Build the cache directory path for a given URL.
	 *
	 * @param string $url Full URL.
	 * @return string Directory path.
	 */
	public static function get_cache_dir( $url ) {
		require_once __DIR__ . '/cache-key-functions.php';

		$parsed = wp_parse_url( $url );
		$host   = _prime_cache_normalize_host( isset( $parsed['host'] ) ? $parsed['host'] : '' );
		$path   = _prime_cache_normalize_path( isset( $parsed['path'] ) ? $parsed['path'] : '/' );

		// Empty host → fail-close. Without a host segment the path collapses
		// onto the cache root and adjacent paths (`stats.json`, other hosts'
		// buckets) become reachable. Callers should treat this as "skip" — a
		// caller can log the bad URL but must not delete or write through here.
		if ( '' === $host ) {
			return false;
		}

		return PRIME_CACHE_CACHE_DIR . $host . $path;
	}

	/**
	 * Build the cache filename based on request properties.
	 *
	 * Matches the logic in dropins/page-cache.php _prime_cache_get_filename().
	 *
	 * @param bool   $is_ssl       Whether the request is HTTPS.
	 * @param bool   $is_mobile    Whether to add -mobile suffix. Only pass true when cache_mobile_separate is enabled.
	 * @param bool   $gzip         Whether to add .gz extension.
	 * @param string $vary_suffix  Vary cookie suffix (e.g. '-vc_abc12345').
	 * @param string $qs_suffix    Query string suffix (e.g. '-qs_def67890').
	 * @param int    $status       HTTP status code (200 default; 404 yields a 404-index filename).
	 * @return string Filename.
	 */
	public static function get_cache_filename( $is_ssl = false, $is_mobile = false, $gzip = false, $vary_suffix = '', $qs_suffix = '', $status = 200 ) {
		$name = ( 404 === $status ) ? '404-index' : 'index';

		if ( $is_ssl ) {
			$name .= '-https';
		}

		if ( $is_mobile ) {
			$name .= '-mobile';
		}

		if ( '' !== $vary_suffix ) {
			$name .= $vary_suffix;
		}

		if ( '' !== $qs_suffix ) {
			$name .= $qs_suffix;
		}

		$name .= '.html';

		if ( $gzip ) {
			$name .= '.gz';
		}

		return $name;
	}

	/**
	 * Write a cache file using atomic write (temp + rename).
	 *
	 * @param string $dir      Cache directory path.
	 * @param string $filename Cache filename.
	 * @param string $content  HTML content.
	 * @param bool   $gzip     Also write a gzip variant.
	 * @return bool
	 */
	public static function write( $dir, $filename, $content, $gzip = true ) {
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Verify the resolved path is within cache directory.
		$real_dir = realpath( $dir );
		$real_cache = realpath( PRIME_CACHE_CACHE_DIR );
		if ( false === $real_dir || false === $real_cache || 0 !== strpos( rtrim( $real_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR, rtrim( $real_cache, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
			return false;
		}

		$filepath      = $dir . $filename;
		$temp_filepath = $filepath . '.tmp.' . uniqid();

		// Atomic write: write to temp, then rename.
		if ( false === file_put_contents( $temp_filepath, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return false;
		}

		if ( ! rename( $temp_filepath, $filepath ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			@unlink( $temp_filepath );
			return false;
		}

		// Gzip variant: append .gz to the original filename to preserve all suffixes.
		if ( $gzip && function_exists( 'gzencode' ) ) {
			$gz_filename  = $filename . '.gz';
			$gz_filepath  = $dir . $gz_filename;
			$gz_temp      = $gz_filepath . '.tmp.' . uniqid();
			$gz_content   = gzencode( $content, 6 );

			if ( false !== file_put_contents( $gz_temp, $gz_content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				if ( ! rename( $gz_temp, $gz_filepath ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
					@unlink( $gz_temp );
				}
			}
		}

		return true;
	}

	/**
	 * Write meta file with response headers (per-variant).
	 *
	 * @param string $dir      Cache directory path.
	 * @param array  $headers  Response headers.
	 * @param string $filename Cache filename (e.g. index-https.html).
	 * @return bool
	 */
	public static function write_meta( $dir, $headers, $filename = '' ) {
		// Mirror write()'s boundary check: refuse to write outside the cache
		// directory even if a caller hands us a malformed $dir.
		$real_dir   = realpath( $dir );
		$real_cache = realpath( PRIME_CACHE_CACHE_DIR );
		if ( false === $real_dir || false === $real_cache || 0 !== strpos( rtrim( $real_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR, rtrim( $real_cache, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
			return false;
		}

		$meta_path = $dir . self::get_meta_filename( $filename );
		$temp_path = $meta_path . '.tmp.' . uniqid();
		$data      = array( 'headers' => $headers );

		if ( false === file_put_contents( $temp_path, wp_json_encode( $data ) ) ) { // phpcs:ignore
			return false;
		}
		if ( ! rename( $temp_path, $meta_path ) ) { // phpcs:ignore
			@unlink( $temp_path );
			return false;
		}
		return true;
	}

	/**
	 * Read meta file (per-variant).
	 *
	 * @param string $dir      Cache directory path.
	 * @param string $filename Cache filename (e.g. index-https.html).
	 * @return array|false
	 */
	public static function read_meta( $dir, $filename = '' ) {
		$meta_path = $dir . self::get_meta_filename( $filename );
		if ( ! is_readable( $meta_path ) ) {
			return false;
		}

		$content = file_get_contents( $meta_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return false;
		}

		return json_decode( $content, true );
	}

	/**
	 * Get meta filename for a cache variant.
	 *
	 * @param string $cache_filename Cache filename (e.g. index-https-mobile.html).
	 * @return string Meta filename (e.g. index-https-mobile.html.meta.json).
	 */
	private static function get_meta_filename( $cache_filename ) {
		if ( empty( $cache_filename ) ) {
			return 'meta.json';
		}
		// Strip .gz extension for meta — gz shares meta with its html variant.
		$cache_filename = preg_replace( '#\.gz$#', '', $cache_filename );
		return $cache_filename . '.meta.json';
	}

	/**
	 * Delete all cache files for a specific URL.
	 *
	 * @param string $url Full URL.
	 * @return bool
	 */
	public static function delete_url( $url ) {
		$dir = self::get_cache_dir( $url );
		if ( false === $dir ) {
			return false;
		}

		if ( ! is_dir( $dir ) ) {
			do_action( 'prime_cache_url_purged', $url );
			return true;
		}

		$result = self::delete_directory_files( $dir );

		delete_transient( 'prime_cache_dir_stats' );
		do_action( 'prime_cache_url_purged', $url );

		return $result;
	}

	/**
	 * Recursively delete all cache files under a URL's path.
	 *
	 * Use for archive URLs whose pagination lives in subdirectories
	 * (/category/foo/page/2/, /author/bar/page/3/, etc.). delete_url() only
	 * clears the first page; this clears the whole subtree.
	 *
	 * @param string $url Full URL.
	 * @return bool
	 */
	public static function delete_url_tree( $url ) {
		$dir = self::get_cache_dir( $url );
		if ( false === $dir ) {
			return false;
		}

		// Refuse to recurse over the host bucket root. URLs that resolve to
		// `/` (plain permalinks like `/?p=123`, `/?author=1`, or a static
		// front page) would otherwise tree-delete every cached page under
		// the host — effectively purge_all from a single-URL purge call.
		// Fall back to single-URL delete in that case so the caller still
		// gets the home page cleared without the collateral damage.
		require_once __DIR__ . '/cache-key-functions.php';
		$parsed   = wp_parse_url( $url );
		$raw_host = isset( $parsed['host'] ) ? $parsed['host'] : '';
		$host     = _prime_cache_normalize_host( $raw_host );
		if ( '' !== $host ) {
			$host_root  = PRIME_CACHE_CACHE_DIR . $host . '/';
			$normalized = rtrim( $dir, '/' ) . '/';
			if ( $normalized === $host_root ) {
				return self::delete_url( $url );
			}
		}

		if ( ! is_dir( $dir ) ) {
			do_action( 'prime_cache_url_purged', $url );
			return true;
		}

		$result = self::delete_directory_recursive( $dir );

		delete_transient( 'prime_cache_dir_stats' );
		do_action( 'prime_cache_url_purged', $url );

		return $result;
	}

	/**
	 * Delete all cache files for a host.
	 *
	 * @param string $host Hostname.
	 * @return bool
	 */
	public static function delete_host( $host ) {
		require_once __DIR__ . '/cache-key-functions.php';
		$host = _prime_cache_normalize_host( $host );
		// Storage-layer guard: an empty normalized host would resolve to the
		// cache root and recursively wipe every install's bucket on shared
		// wp-content. Any caller that hands us an empty/garbage value is
		// almost certainly buggy — refuse rather than nuke the directory.
		if ( '' === $host ) {
			return false;
		}
		$dir = PRIME_CACHE_CACHE_DIR . $host . '/';

		if ( ! is_dir( $dir ) ) {
			return true;
		}

		return self::delete_directory_recursive( $dir );
	}

	/**
	 * Delete all files in a directory (non-recursive).
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private static function delete_directory_files( $dir ) {
		$files = scandir( $dir );
		if ( false === $files ) {
			return false;
		}

		$ok = true;
		foreach ( $files as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}

			$filepath = $dir . $file;
			if ( is_file( $filepath ) && ! @unlink( $filepath ) ) {
				$ok = false;
			}
		}

		// Remove directory if now empty.
		if ( self::is_dir_empty( $dir ) ) {
			@rmdir( $dir );
		}

		return $ok;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private static function delete_directory_recursive( $dir ) {
		// Verify the path is within cache directory.
		$real_dir   = realpath( $dir );
		$real_cache = realpath( PRIME_CACHE_CACHE_DIR );
		if ( false === $real_dir || false === $real_cache || 0 !== strpos( rtrim( $real_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR, rtrim( $real_cache, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) ) {
			return false;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		$ok = true;
		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() ) {
				// Empty subdirs may legitimately fail to rmdir if a sibling
				// file failed to unlink first — only count it as a failure
				// when nothing else has already failed in this run.
				if ( ! @rmdir( $path ) && $ok ) {
					$ok = false;
				}
			} elseif ( ! @unlink( $path ) ) {
				$ok = false;
			}
		}

		// Top-level rmdir: only succeeds if all children were removed. Treat
		// a failure here as authoritative regardless of inner-loop state.
		if ( ! @rmdir( $dir ) ) {
			$ok = false;
		}

		return $ok;
	}

	/**
	 * Check if a directory is empty.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private static function is_dir_empty( $dir ) {
		$handle = opendir( $dir );
		if ( false === $handle ) {
			return true;
		}

		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				closedir( $handle );
				return false;
			}
		}

		closedir( $handle );
		return true;
	}

}
