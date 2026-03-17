<?php
/**
 * WP-CLI commands for Prime Cache.
 *
 * Usage:
 *   wp prime-cache flush          — Clear all caches
 *   wp prime-cache flush page     — Clear page cache only
 *   wp prime-cache flush minified — Clear minified CSS/JS
 *   wp prime-cache flush object   — Flush object cache
 *   wp prime-cache flush url <url> — Clear cache for a specific URL
 *   wp prime-cache preload        — Start cache preloading
 *   wp prime-cache status         — Show cache status and statistics
 *   wp prime-cache db-cleanup     — Run database cleanup
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	return;
}

class Prime_Cache_CLI extends WP_CLI_Command {

	/**
	 * Clear cache.
	 *
	 * ## OPTIONS
	 *
	 * [<type>]
	 * : Type of cache to clear. Default: all.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - page
	 *   - minified
	 *   - object
	 *   - url
	 * ---
	 *
	 * [<url>]
	 * : URL to clear (only for type=url).
	 *
	 * ## EXAMPLES
	 *
	 *     wp prime-cache flush
	 *     wp prime-cache flush page
	 *     wp prime-cache flush url https://example.com/sample-page/
	 *
	 * @subcommand flush
	 */
	public function flush( $args, $assoc_args ) {
		$type = isset( $args[0] ) ? $args[0] : 'all';

		$pc = Prime_Cache::get_instance();

		switch ( $type ) {
			case 'all':
				$host = wp_parse_url( home_url(), PHP_URL_HOST );
				if ( $host ) {
					Prime_Cache_Storage::delete_host( $host );
				}
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
				do_action( 'prime_cache_after_purge_all' );
				WP_CLI::success( 'All caches cleared.' );
				break;

			case 'page':
				$host = wp_parse_url( home_url(), PHP_URL_HOST );
				if ( $host ) {
					Prime_Cache_Storage::delete_host( $host );
				}
				do_action( 'prime_cache_after_purge_all' );
				WP_CLI::success( 'Page cache cleared.' );
				break;

			case 'minified':
				$fo_dir = WP_CONTENT_DIR . '/cache/prime-cache-fo/';
				if ( is_dir( $fo_dir ) ) {
					$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fo_dir, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
					foreach ( $it as $item ) {
						$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
					}
				}
				WP_CLI::success( 'Minified CSS/JS files cleared.' );
				break;

			case 'object':
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
				WP_CLI::success( 'Object cache flushed.' );
				break;

			case 'url':
				$url = isset( $args[1] ) ? $args[1] : '';
				if ( empty( $url ) ) {
					WP_CLI::error( 'Please provide a URL. Usage: wp prime-cache flush url <url>' );
				}
				Prime_Cache_Storage::delete_url( $url );
				WP_CLI::success( "Cache cleared for: {$url}" );
				break;

			default:
				WP_CLI::error( "Unknown type: {$type}. Use: all, page, minified, object, url" );
		}
	}

	/**
	 * Start cache preloading.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prime-cache preload
	 *
	 * @subcommand preload
	 */
	public function preload( $args, $assoc_args ) {
		wp_clear_scheduled_hook( 'prime_cache_preload_batch' );
		wp_schedule_single_event( time() + 3, 'prime_cache_preload_batch' );
		WP_CLI::success( 'Cache preloading scheduled.' );

		$s = prime_cache_get_settings();
		if ( ! empty( trim( $s['cache_vary_cookies'] ?? '' ) ) ) {
			WP_CLI::warning( 'Vary Cookies active — preload warms default variant only. Cookie-specific variants are generated on first visitor request.' );
		}
	}

	/**
	 * Show cache status and statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prime-cache status
	 *
	 * @subcommand status
	 */
	public function status( $args, $assoc_args ) {
		$s = prime_cache_get_settings();

		WP_CLI::line( '=== Prime Cache Status ===' );
		WP_CLI::line( 'Cache Enabled: ' . ( $s['cache_enabled'] ? 'Yes' : 'No' ) );
		WP_CLI::line( 'WP_CACHE:      ' . ( defined( 'WP_CACHE' ) && WP_CACHE ? 'Yes' : 'No' ) );

		$dropin = WP_CONTENT_DIR . '/advanced-cache.php';
		WP_CLI::line( 'Dropin:        ' . ( file_exists( $dropin ) && false !== strpos( file_get_contents( $dropin ), 'PRIME_CACHE_DROPIN_SIGNATURE' ) ? 'Installed' : 'Missing' ) );

		// Cache stats (DB baseline + file increments).
		$db_stats = get_option( 'prime_cache_stats', array( 'hit' => 0, 'miss' => 0, 'since' => 0 ) );
		$hit  = (int) ( $db_stats['hit'] ?? 0 );
		$miss = (int) ( $db_stats['miss'] ?? 0 );
		$stats_file = PRIME_CACHE_CACHE_DIR . 'stats.json';
		if ( is_readable( $stats_file ) ) {
			$file_data = json_decode( file_get_contents( $stats_file ), true );
			if ( is_array( $file_data ) ) {
				$hit  += (int) ( $file_data['hit'] ?? 0 );
				$miss += (int) ( $file_data['miss'] ?? 0 );
			}
		}
		$total = $hit + $miss;
		$rate  = $total > 0 ? round( ( $hit / $total ) * 100, 1 ) : 0;
		WP_CLI::line( '' );
		WP_CLI::line( 'Hit Rate:  ' . $rate . '%' );
		WP_CLI::line( 'HIT:       ' . number_format( $hit ) );
		WP_CLI::line( 'MISS:      ' . number_format( $miss ) );

		// Disk usage.
		$files = 0; $size = 0;
		if ( is_dir( PRIME_CACHE_CACHE_DIR ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( PRIME_CACHE_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( $f->isFile() && 'html' === $f->getExtension() ) $files++;
				if ( $f->isFile() ) $size += $f->getSize();
			}
		}
		WP_CLI::line( '' );
		WP_CLI::line( 'Cached Pages:  ' . number_format( $files ) );
		WP_CLI::line( 'Cache Size:    ' . size_format( $size ) );

		// Object cache.
		$oc = Prime_Cache_Config::get_active_object_cache();
		WP_CLI::line( '' );
		WP_CLI::line( 'Object Cache:  ' . ( 'off' === $oc ? 'Disabled' : strtoupper( $oc ) ) );
	}

	/**
	 * Run database cleanup.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prime-cache db-cleanup
	 *
	 * @subcommand db-cleanup
	 */
	public function db_cleanup( $args, $assoc_args ) {
		$s = prime_cache_get_settings();
		$optimizer = new Prime_Cache_Database_Optimizer();
		$results = $optimizer->execute_cleanup( $s );
		$total = array_sum( $results );

		foreach ( $results as $key => $count ) {
			if ( $count > 0 ) {
				WP_CLI::line( ucfirst( str_replace( '_', ' ', $key ) ) . ": {$count} items" );
			}
		}

		WP_CLI::success( "Database cleanup processed {$total} items (max 1,000 per task). Run again if more remain." );
	}
}

WP_CLI::add_command( 'prime-cache', 'Prime_Cache_CLI' );
