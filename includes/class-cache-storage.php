<?php
/**
 * Cache file storage: read, write, delete operations.
 */

defined( 'ABSPATH' ) || exit;

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

		return PRIME_CACHE_CACHE_DIR . $host . $path;
	}

	/**
	 * Build the cache filename based on request properties.
	 *
	 * @param bool $is_ssl     Whether the request is HTTPS.
	 * @param bool $is_mobile  Whether the request is from a mobile device.
	 * @param bool $gzip       Whether to add .gz extension.
	 * @return string Filename.
	 */
	public static function get_cache_filename( $is_ssl = false, $is_mobile = false, $gzip = false ) {
		$name = 'index';

		if ( $is_ssl ) {
			$name .= '-https';
		}

		if ( $is_mobile ) {
			$name .= '-mobile';
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
		if ( false === $real_dir || false === $real_cache || strpos( $real_dir, $real_cache ) !== 0 ) {
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

		// Gzip variant.
		if ( $gzip && function_exists( 'gzencode' ) ) {
			$gz_filename  = self::get_cache_filename(
				strpos( $filename, '-https' ) !== false,
				strpos( $filename, '-mobile' ) !== false,
				true
			);
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

		if ( ! is_dir( $dir ) ) {
			do_action( 'prime_cache_url_purged', $url );
			return true;
		}

		$result = self::delete_directory_files( $dir );

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
		$dir  = PRIME_CACHE_CACHE_DIR . $host . '/';

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

		foreach ( $files as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}

			$filepath = $dir . $file;
			if ( is_file( $filepath ) ) {
				@unlink( $filepath );
			}
		}

		// Remove directory if now empty.
		if ( self::is_dir_empty( $dir ) ) {
			@rmdir( $dir );
		}

		return true;
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
		if ( false === $real_dir || false === $real_cache || strpos( $real_dir, $real_cache ) !== 0 ) {
			return false;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}

		@rmdir( $dir );

		return true;
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
