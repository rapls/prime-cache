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
				// Same workflow as admin "Clear All Cache" button — multi-host page
				// cache + minified CSS/JS + critical CSS + object cache flush.
				$pc->clear_all_caches();
				WP_CLI::success( 'All caches cleared.' );
				break;

			case 'page':
				// Multi-host page cache purge (matches Prime_Cache_Purge::purge_all()).
				$hosts = array();
				foreach ( array( home_url(), site_url() ) as $u ) {
					$h = wp_parse_url( $u, PHP_URL_HOST );
					if ( $h ) {
						$hosts[] = $h;
					}
				}
				/** This filter is documented in includes/class-config.php */
				$hosts = apply_filters( 'prime_cache_allowed_hosts', $hosts );
				if ( ! is_array( $hosts ) ) {
					$hosts = array();
				}
				// Same defense-in-depth as Prime_Cache_Purge::purge_all() — a
				// filter callback returning '' / null would otherwise pass
				// straight through to delete_host('').
				require_once PRIME_CACHE_PATH . 'includes/cache-key-functions.php';
				$hosts = array_map(
					function ( $h ) {
						return is_string( $h ) ? _prime_cache_normalize_host( $h ) : '';
					},
					$hosts
				);
				$hosts = array_values( array_unique( array_filter( $hosts ) ) );
				foreach ( $hosts as $host ) {
					Prime_Cache_Storage::delete_host( $host );
				}
				delete_transient( 'prime_cache_dir_stats' );
				do_action( 'prime_cache_after_purge_all' );
				WP_CLI::success( 'Page cache cleared.' );
				break;

			case 'minified':
				$fo_dir     = WP_CONTENT_DIR . '/cache/prime-cache-fo/';
				$cache_root = WP_CONTENT_DIR . '/cache/';
				if ( is_dir( $fo_dir ) ) {
					// Boundary check: refuse to recurse if $fo_dir resolves
					// outside our cache root (symlink target leak protection).
					if ( ! class_exists( 'Prime_Cache_File_Optimizer' )
						|| ! Prime_Cache_File_Optimizer::path_within( realpath( $fo_dir ), realpath( $cache_root ) ) ) {
						WP_CLI::error( 'Refusing to clear: ' . $fo_dir . ' resolves outside ' . $cache_root . ' (symlink protection).' );
					}
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
				$url = isset( $args[1] ) ? trim( (string) $args[1] ) : '';
				if ( '' === $url ) {
					WP_CLI::error( 'Please provide a URL. Usage: wp prime-cache flush url <url>' );
				}
				$parsed_host   = wp_parse_url( $url, PHP_URL_HOST );
				$parsed_scheme = wp_parse_url( $url, PHP_URL_SCHEME );
				if ( empty( $parsed_host ) || empty( $parsed_scheme )
					|| ( 'http' !== $parsed_scheme && 'https' !== $parsed_scheme ) ) {
					WP_CLI::error( "Invalid URL: {$url}. Provide an absolute URL with scheme and host (e.g. https://example.com/page/)." );
				}
				// Mirror the admin pc_action=clear_url same-host check. In a
				// shared wp-content setup an unrestricted CLI flush could
				// reach into another install's cache bucket. Use the shared
				// host normalizer so IDN (Unicode vs Punycode) matches.
				require_once PRIME_CACHE_PATH . 'includes/cache-key-functions.php';
				$site_hosts = array();
				foreach ( array( home_url(), site_url() ) as $u ) {
					$h = wp_parse_url( $u, PHP_URL_HOST );
					if ( $h ) {
						$site_hosts[] = $h;
					}
				}
				/** This filter is documented in includes/class-config.php */
				$site_hosts = apply_filters( 'prime_cache_allowed_hosts', $site_hosts );
				if ( ! is_array( $site_hosts ) ) {
					$site_hosts = array();
				}
				$site_hosts = array_map(
					function ( $h ) {
						return is_string( $h ) ? _prime_cache_normalize_host( $h ) : '';
					},
					$site_hosts
				);
				$site_hosts = array_values( array_unique( array_filter( $site_hosts ) ) );
				$norm_host  = _prime_cache_normalize_host( $parsed_host );
				if ( '' === $norm_host || ! in_array( $norm_host, $site_hosts, true ) ) {
					WP_CLI::error( "URL host {$parsed_host} does not match this install (" . implode( ', ', $site_hosts ) . "). Refusing to clear cross-host cache." );
				}
				// Path-prefix gate for shared-host multi-install setups
				// (`/site-a/`, `/site-b/` on the same domain). Without this,
				// `wp prime-cache flush url` from site-a could clear site-b's
				// cached entries. Mirror the admin pc_action=clear_url guard.
				$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
				$home_path = is_string( $home_path ) && '' !== $home_path ? $home_path : '/';
				if ( '/' !== substr( $home_path, -1 ) ) {
					$home_path .= '/';
				}
				$url_path = wp_parse_url( $url, PHP_URL_PATH );
				$url_path = is_string( $url_path ) && '' !== $url_path ? $url_path : '/';
				if ( '/' !== $home_path && 0 !== strpos( $url_path . '/', $home_path ) ) {
					WP_CLI::error( "URL path {$url_path} is outside this install's base path ({$home_path}). Refusing to clear cross-install cache." );
				}
				if ( ! Prime_Cache_Storage::delete_url( $url ) ) {
					WP_CLI::error( "Failed to clear cache for: {$url}. Check filesystem permissions on the cache directory." );
				}
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
		$s = prime_cache_get_settings();
		// Without these, the cron event fires but the batch handler is not
		// registered (preload_enabled gates listener registration in
		// Prime_Cache_Preload::__construct), or the dropin returns early before
		// writing any cache file. Either way, scheduling would be a silent no-op.
		if ( empty( $s['cache_enabled'] ) ) {
			WP_CLI::error( 'Page caching is disabled. Enable "Cache Enabled" in Page Cache settings before starting preload.' );
			return;
		}
		if ( empty( $s['preload_enabled'] ) ) {
			WP_CLI::error( 'Preload is disabled. Enable "Cache Preload" in Preload settings before starting preload.' );
			return;
		}

		if ( ! Prime_Cache_Preload::request() ) {
			WP_CLI::error( 'Failed to schedule preload (request() returned false). Verify cache_enabled and preload_enabled in saved settings.' );
			return;
		}
		WP_CLI::success( 'Cache preloading scheduled. Will start on the next WP-Cron tick.' );

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			WP_CLI::warning( 'DISABLE_WP_CRON is set — the scheduled batch will not fire until your system cron runs `wp cron event run --due-now` (or you trigger it manually).' );
		}
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

		// Distinguish Installed/External/Orphaned/Abandoned/Missing so operators
		// can see when another caching plugin owns advanced-cache.php (the old
		// strpos check reported External as Missing).
		$dropin_owner_label = array(
			'ours'      => 'Installed',
			'external'  => 'External (another plugin owns advanced-cache.php)',
			'orphaned'  => 'Orphaned (deactivated plugin left a dropin behind)',
			'abandoned' => 'Abandoned (empty/near-empty dropin)',
			'none'      => 'Missing',
		);
		$dropin_owner = class_exists( 'Prime_Cache_Config' )
			? Prime_Cache_Config::get_advanced_cache_owner()
			: 'none';
		WP_CLI::line( 'Dropin:        ' . ( $dropin_owner_label[ $dropin_owner ] ?? $dropin_owner ) );

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
		$oc_label = array(
			'off'      => 'Disabled',
			'external' => 'External (managed by another plugin)',
			'broken'   => 'Broken (backend file missing — disable and re-enable to repair)',
		);
		WP_CLI::line( '' );
		WP_CLI::line( 'Object Cache:  ' . ( $oc_label[ $oc ] ?? strtoupper( $oc ) ) );
	}
}

WP_CLI::add_command( 'prime-cache', 'Prime_Cache_CLI' );
