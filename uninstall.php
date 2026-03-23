<?php
/**
 * Prime Cache uninstall.
 *
 * Runs when the plugin is deleted via WordPress admin.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin option.
delete_option( 'prime_cache_settings' );

// Remove .htaccess rules.
$htaccess = ABSPATH . '.htaccess';
if ( file_exists( $htaccess ) && function_exists( 'insert_with_markers' ) ) {
	insert_with_markers( $htaccess, 'Prime Cache', array() );
}

// Remove advanced-cache.php if ours.
$dropin = WP_CONTENT_DIR . '/advanced-cache.php';
if ( file_exists( $dropin ) ) {
	$content = file_get_contents( $dropin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false !== strpos( $content, 'PRIME_CACHE_DROPIN_SIGNATURE' ) ) {
		@unlink( $dropin );
	}
}

// Remove object-cache.php if ours.
$object_dropin = WP_CONTENT_DIR . '/object-cache.php';
if ( file_exists( $object_dropin ) ) {
	$content = file_get_contents( $object_dropin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( false !== strpos( $content, 'PRIME_CACHE_DROPIN_SIGNATURE' ) ) {
		@unlink( $object_dropin );
	}
}

// Remove WP_CACHE from wp-config.php — only the line Prime Cache added.
$config_paths = array(
	ABSPATH . 'wp-config.php',
	dirname( ABSPATH ) . '/wp-config.php',
);

foreach ( $config_paths as $config_path ) {
	if ( file_exists( $config_path ) && is_writable( $config_path ) ) {
		$config_content = file_get_contents( $config_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === strpos( $config_content, 'Added by Prime Cache' ) ) {
			continue; // Not our line in this file — check next candidate.
		}
		$config_content = preg_replace(
			'#^\s*define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*[^)]+\)\s*;\s*//\s*Added by Prime Cache[^\n]*\n?#mi',
			'',
			$config_content
		);
		if ( null === $config_content ) {
			break; // PCRE error — leave wp-config.php untouched.
		}
		$config_content = preg_replace( "#\n{3,}#", "\n\n", $config_content );
		// Atomic write: temp file + rename.
		$tempfile = $config_path . '.tmp.' . getmypid();
		if ( null !== $config_content && false !== file_put_contents( $tempfile, $config_content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( ! rename( $tempfile, $config_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				@unlink( $tempfile );
			}
		}
		break; // Found and cleaned — done.
	}
}

// Remove cache directory.
$cache_dir = WP_CONTENT_DIR . '/cache/prime-cache/';
if ( is_dir( $cache_dir ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $cache_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			@rmdir( $item->getPathname() );
		} else {
			@unlink( $item->getPathname() );
		}
	}
	@rmdir( $cache_dir );
}

// Remove config directory.
$config_dir = WP_CONTENT_DIR . '/prime-cache-config/';
if ( is_dir( $config_dir ) ) {
	$files = glob( $config_dir . '*' );
	foreach ( $files as $file ) {
		@unlink( $file );
	}
	@rmdir( $config_dir );
}

// Remove file optimizer cache directory (#17).
$fo_dir = WP_CONTENT_DIR . '/cache/prime-cache-fo/';
if ( is_dir( $fo_dir ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $fo_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
	}
	@rmdir( $fo_dir );
}

// Remove stats, preload queue, and image optimization metadata.
delete_option( 'prime_cache_stats' );
delete_option( 'prime_cache_cf_purge_queue' );
delete_option( 'prime_cache_cf_purge_retries' );
delete_option( 'prime_cache_preload_queue' );
delete_option( 'prime_cache_preload_attempts' );
delete_option( 'prime_cache_flush_rewrite' );
delete_option( 'prime_cache_img_stats' );
global $wpdb;
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_prime_cache_img_opt' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_prime_cache_disabled' ) );

// Remove scheduled cron events (#19).
wp_clear_scheduled_hook( 'prime_cache_cleanup_expired' );
wp_clear_scheduled_hook( 'prime_cache_preload_batch' );
wp_clear_scheduled_hook( 'prime_cache_db_cleanup' );
wp_clear_scheduled_hook( 'prime_cache_refresh_local_analytics' );
wp_clear_scheduled_hook( 'prime_cache_refresh_google_fonts' );
wp_clear_scheduled_hook( 'prime_cache_cf_deferred_purge' );
wp_clear_scheduled_hook( 'prime_cache_cf_retry_full_purge' );
delete_option( 'prime_cache_cf_full_purge_retries' );
delete_option( 'prime_cache_cf_purge_failed' );
wp_clear_scheduled_hook( 'prime_cache_cleanup_gf_options' );
// Clean up any remaining Google Fonts pending options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'prime\_cache\_gf\_%'" );

// Remove transients (#20).
delete_transient( 'prime_cache_preload_fonts' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pc_imgdim_%' OR option_name LIKE '_transient_timeout_pc_imgdim_%'" );
