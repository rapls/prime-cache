<?php
/**
 * Page Cache Dropin.
 *
 * This file is loaded by wp-content/advanced-cache.php BEFORE WordPress initializes.
 * It must not use any WordPress functions — only pure PHP.
 *
 * Flow:
 * 1. Load config file for the current host.
 * 2. Check if the request is cacheable.
 * 3. If a cached file exists, serve it and exit.
 * 4. Otherwise, start output buffering to capture and cache the page.
 */

defined( 'ABSPATH' ) || exit;

// Bail if another caching system is active.
if ( defined( 'PRIME_CACHE_SERVING' ) ) {
	return;
}
define( 'PRIME_CACHE_SERVING', true );

// ----- Load Config -----

$prime_cache_config = array(
	'cache_enabled'         => true,
	'cache_mobile'          => true,
	'cache_mobile_separate' => false,
	'cache_logged_in'       => false,
	'gzip_compression'      => true,
	'cache_reject_uri'      => '',
	'cache_reject_cookies'  => '',
	'cache_reject_ua'       => '',
	'cache_reject_referrer' => '',
	'cache_vary_cookies'    => '',
	'cache_query_strings'   => '',
	'cache_404'             => false,
	'cache_lifespan'        => 0,
	'cache_footprint'       => true,
	'cache_ignore_qs'       => 'utm_source, utm_medium, utm_campaign, utm_term, utm_content, utm_expid, fbclid, gclid, ga_source, ga_medium, ga_campaign, ga_term, ga_content',
);

if ( defined( 'PRIME_CACHE_CONFIG_DIR' ) ) {
	$_pc_host        = isset( $_SERVER['HTTP_HOST'] ) ? preg_replace( '#[^a-zA-Z0-9.\-]#', '', $_SERVER['HTTP_HOST'] ) : '';
	$_pc_config_file = PRIME_CACHE_CONFIG_DIR . $_pc_host . '.php';
	if ( is_readable( $_pc_config_file ) ) {
		include $_pc_config_file;
	}
}

// ----- Cache enabled check -----

if ( empty( $prime_cache_config['cache_enabled'] ) ) {
	return;
}

/**
 * Record a HIT or MISS to the stats file.
 *
 * Uses a lightweight append-based counter file.
 *
 * @param string $type 'hit' or 'miss'.
 */
function _prime_cache_record_stat( $type ) {
	$stats_file = PRIME_CACHE_CACHE_DIR . 'stats.json';
	$stats      = array( 'hit' => 0, 'miss' => 0, 'since' => time() );

	// Use a lock to prevent race conditions (read inside lock only).
	$fp = fopen( $stats_file, 'c' );
	if ( $fp && flock( $fp, LOCK_EX | LOCK_NB ) ) {
		fseek( $fp, 0 );
		$current = stream_get_contents( $fp );
		if ( $current ) {
			$current_data = json_decode( $current, true );
			if ( is_array( $current_data ) ) {
				$stats = $current_data;
			}
		}
		// Always increment the counter (fixes first-run miss).
		$stats[ $type ] = isset( $stats[ $type ] ) ? $stats[ $type ] + 1 : 1;
		ftruncate( $fp, 0 );
		fseek( $fp, 0 );
		fwrite( $fp, json_encode( $stats ) );
		flock( $fp, LOCK_UN );
	}
	if ( $fp ) {
		fclose( $fp );
	}
}

// ----- Early Request Tests (no WordPress functions) -----

// Only GET requests.
if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
	return;
}

$_pc_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

// Skip admin, login, cron, xmlrpc.
if ( preg_match( '#(/wp-admin|/wp-login\.php|/wp-cron\.php|/xmlrpc\.php)#', $_pc_request_uri ) ) {
	return;
}

// Skip non-HTML extensions.
$_pc_path = strtok( $_pc_request_uri, '?' );
if ( $_pc_path && preg_match( '#\.(php|xml|xsl|json|css|js|map|txt)$#i', $_pc_path ) && ! preg_match( '#/index\.php$#', $_pc_path ) ) {
	return;
}

// Query string handling.
// - cache_ignore_qs: these params are stripped (same cache as without them).
// - cache_query_strings: these params create unique cache entries per value.
// - Any other param → skip caching entirely.
$_pc_qs_suffix = '';
if ( ! empty( $_GET ) ) {
	$_pc_ignored    = array_map( 'trim', explode( ',', $prime_cache_config['cache_ignore_qs'] ) );
	$_pc_ignored    = array_filter( $_pc_ignored );
	$_pc_cached_qs  = array_map( 'trim', explode( ',', $prime_cache_config['cache_query_strings'] ) );
	$_pc_cached_qs  = array_filter( $_pc_cached_qs );
	$_pc_remaining  = array_diff_key( $_GET, array_flip( $_pc_ignored ) );

	if ( ! empty( $_pc_remaining ) ) {
		if ( empty( $_pc_cached_qs ) ) {
			return; // Unknown params, don't cache.
		}
		// Keep only params that are in the cache_query_strings list.
		$_pc_qs_to_cache = array_intersect_key( $_pc_remaining, array_flip( $_pc_cached_qs ) );
		$_pc_unknown     = array_diff_key( $_pc_remaining, $_pc_qs_to_cache );
		if ( ! empty( $_pc_unknown ) ) {
			return; // Has params not in ignore or cache list.
		}
		// Build a deterministic suffix for the filename.
		if ( ! empty( $_pc_qs_to_cache ) ) {
			ksort( $_pc_qs_to_cache );
			$_pc_qs_suffix = '-qs_' . substr( md5( http_build_query( $_pc_qs_to_cache ) ), 0, 8 );
		}
	}
}

// Logged-in cookie check.
if ( ! $prime_cache_config['cache_logged_in'] && ! empty( $_COOKIE ) ) {
	foreach ( array_keys( $_COOKIE ) as $_pc_cookie_name ) {
		if ( strpos( $_pc_cookie_name, 'wordpress_logged_in_' ) === 0 ) {
			return;
		}
	}
}

// Rejected cookies.
if ( ! empty( $prime_cache_config['cache_reject_cookies'] ) && ! empty( $_COOKIE ) ) {
	foreach ( array_keys( $_COOKIE ) as $_pc_cookie_name ) {
		if ( @preg_match( '#' . $prime_cache_config['cache_reject_cookies'] . '#i', $_pc_cookie_name ) ) {
			return;
		}
	}
}

// Rejected URI patterns.
if ( ! empty( $prime_cache_config['cache_reject_uri'] ) ) {
	if ( @preg_match( '#(' . $prime_cache_config['cache_reject_uri'] . ')#i', $_pc_path ) ) {
		return;
	}
}

// Rejected user agents.
if ( ! empty( $prime_cache_config['cache_reject_ua'] ) ) {
	$_pc_ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
	if ( $_pc_ua && @preg_match( '#' . $prime_cache_config['cache_reject_ua'] . '#i', $_pc_ua ) ) {
		return;
	}
}

// Rejected referrers.
if ( ! empty( $prime_cache_config['cache_reject_referrer'] ) && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
	if ( @preg_match( '#(' . $prime_cache_config['cache_reject_referrer'] . ')#i', $_SERVER['HTTP_REFERER'] ) ) {
		return;
	}
}

// Mobile detection.
$_pc_is_mobile = false;
if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
	$_pc_is_mobile = (bool) preg_match( '#(Mobile|Android|Silk/|Kindle|BlackBerry|Opera Mini|Opera Mobi|webOS)#i', $_SERVER['HTTP_USER_AGENT'] );
}

// Skip mobile if mobile caching is disabled.
if ( $_pc_is_mobile && ! $prime_cache_config['cache_mobile'] ) {
	return;
}

// ----- Determine Cache Path -----

$_pc_is_ssl = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
	|| ( ! empty( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] )
	|| ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );

/**
 * Build cache directory path from host + request path.
 */
function _prime_cache_get_cache_dir() {
	global $_pc_request_uri;

	$host = isset( $_SERVER['HTTP_HOST'] ) ? preg_replace( '#[^a-zA-Z0-9.\-]#', '', $_SERVER['HTTP_HOST'] ) : '';
	$path = strtok( $_pc_request_uri, '?' );
	$path = str_replace( '..', '', $path );
	$path = preg_replace( '#[^a-zA-Z0-9/_\-\.]#', '', $path );
	$path = rtrim( $path, '/' ) . '/';

	return PRIME_CACHE_CACHE_DIR . $host . $path;
}

/**
 * Build cache filename from request properties.
 *
 * @param bool   $is_ssl               HTTPS request.
 * @param bool   $is_mobile            Mobile device.
 * @param bool   $use_mobile_separate  Separate mobile cache files.
 * @param bool   $gzip                 Gzip variant.
 * @param string $vary_suffix          Vary cookie suffix.
 * @param string $qs_suffix            Query string suffix.
 * @return string
 */
function _prime_cache_get_filename( $is_ssl, $is_mobile, $use_mobile_separate, $gzip = false, $vary_suffix = '', $qs_suffix = '' ) {
	$name = 'index';

	if ( $is_ssl ) {
		$name .= '-https';
	}

	if ( $is_mobile && $use_mobile_separate ) {
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

// Vary Cookies — create separate cache per cookie value combination.
$_pc_vary_suffix = '';
if ( ! empty( $prime_cache_config['cache_vary_cookies'] ) && ! empty( $_COOKIE ) ) {
	$_pc_vary_names = array_map( 'trim', explode( ',', $prime_cache_config['cache_vary_cookies'] ) );
	$_pc_vary_names = array_filter( $_pc_vary_names );
	$_pc_vary_vals  = array();
	foreach ( $_pc_vary_names as $_pc_vn ) {
		if ( isset( $_COOKIE[ $_pc_vn ] ) ) {
			$_pc_vary_vals[ $_pc_vn ] = $_COOKIE[ $_pc_vn ];
		}
	}
	if ( ! empty( $_pc_vary_vals ) ) {
		ksort( $_pc_vary_vals );
		$_pc_vary_suffix = '-vc_' . substr( md5( json_encode( $_pc_vary_vals ) ), 0, 8 );
	}
}

$_pc_cache_dir  = _prime_cache_get_cache_dir();
$_pc_filename   = _prime_cache_get_filename( $_pc_is_ssl, $_pc_is_mobile, $prime_cache_config['cache_mobile_separate'], false, $_pc_vary_suffix, $_pc_qs_suffix );

// ----- Try to Serve Cached File -----

$_pc_cache_file = $_pc_cache_dir . $_pc_filename;

if ( is_readable( $_pc_cache_file ) ) {
	$_pc_modified_time = filemtime( $_pc_cache_file );
	$_pc_lifespan      = isset( $prime_cache_config['cache_lifespan'] ) ? (int) $prime_cache_config['cache_lifespan'] : 0;

	// Skip serving if cache file has expired — let WordPress regenerate it.
	if ( $_pc_lifespan > 0 && ( time() - $_pc_modified_time ) > $_pc_lifespan ) {
		// Fall through to output buffering below.
	} else {

	// HTTP 304 Not Modified support.
	if ( ! empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
		$_pc_since = strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
		if ( $_pc_since && $_pc_since >= $_pc_modified_time ) {
			header( $_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified', true, 304 );
			header( 'Cache-Control: no-cache, must-revalidate' );
			header( 'X-Prime-Cache: HIT-304' );
			exit;
		}
	}

	// Restore meta headers and status code if available.
	$_pc_meta_file = $_pc_cache_dir . 'meta.json';
	if ( is_readable( $_pc_meta_file ) ) {
		$_pc_meta = json_decode( file_get_contents( $_pc_meta_file ), true );
		if ( ! empty( $_pc_meta['headers'] ) ) {
			foreach ( $_pc_meta['headers'] as $_pc_header ) {
				header( $_pc_header );
			}
		}
		// Restore original HTTP status code (e.g., 404 for cached 404 pages).
		if ( ! empty( $_pc_meta['status'] ) && 200 !== (int) $_pc_meta['status'] ) {
			http_response_code( (int) $_pc_meta['status'] );
		}
	}

	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $_pc_modified_time ) . ' GMT' );
	header( 'X-Prime-Cache: HIT' );

	_prime_cache_record_stat( 'hit' );

	// Serve gzip version if available and accepted.
	$_pc_accept_gzip = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) && strpos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' ) !== false;
	$_pc_gz_file     = $_pc_cache_dir . _prime_cache_get_filename( $_pc_is_ssl, $_pc_is_mobile, $prime_cache_config['cache_mobile_separate'], true, $_pc_vary_suffix, $_pc_qs_suffix );

	if ( $_pc_accept_gzip && is_readable( $_pc_gz_file ) ) {
		header( 'Content-Encoding: gzip' );
		readfile( $_pc_gz_file );
		exit;
	}

	readfile( $_pc_cache_file );
	exit;

	} // end lifespan check
}

// ----- Start Output Buffering -----

ob_start( function ( $buffer ) {
	global $prime_cache_config, $_pc_is_ssl, $_pc_is_mobile, $_pc_vary_suffix, $_pc_qs_suffix;

	// Post-buffering tests.
	if ( strlen( $buffer ) < 255 ) {
		return $buffer;
	}

	if ( http_response_code() !== 200 ) {
		return $buffer;
	}

	if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
		return $buffer;
	}

	if ( false === stripos( $buffer, '</html>' ) ) {
		return $buffer;
	}

	// Skip 404 (unless cache_404 is enabled) and search results.
	if ( function_exists( 'is_404' ) && is_404() && empty( $prime_cache_config['cache_404'] ) ) {
		return $buffer;
	}
	if ( function_exists( 'is_search' ) && is_search() ) {
		return $buffer;
	}

	// Skip password-protected posts.
	if ( function_exists( 'get_queried_object' ) ) {
		$obj = get_queried_object();
		if ( $obj && isset( $obj->post_password ) && '' !== $obj->post_password ) {
			return $buffer;
		}
	}

	// Skip posts with per-post cache disable metabox.
	if ( function_exists( 'get_queried_object_id' ) ) {
		$_pc_qid = get_queried_object_id();
		if ( $_pc_qid && function_exists( 'get_post_meta' ) ) {
			$_pc_disabled = get_post_meta( $_pc_qid, '_prime_cache_disabled', true );
			if ( $_pc_disabled ) {
				return $buffer;
			}
		}
	}

	// Build cache path.
	$cache_dir = _prime_cache_get_cache_dir();
	$filename  = _prime_cache_get_filename(
		$_pc_is_ssl,
		$_pc_is_mobile,
		$prime_cache_config['cache_mobile_separate'],
		false,
		$_pc_vary_suffix,
		$_pc_qs_suffix
	);

	// Create directory.
	if ( ! is_dir( $cache_dir ) ) {
		if ( ! mkdir( $cache_dir, 0755, true ) ) {
			return $buffer;
		}
	}

	// Security: verify path is within cache directory.
	$real_dir   = realpath( $cache_dir );
	$real_cache = realpath( PRIME_CACHE_CACHE_DIR );
	if ( false === $real_dir || false === $real_cache || strpos( $real_dir, $real_cache ) !== 0 ) {
		return $buffer;
	}

	// Add footprint.
	$content = $buffer;
	if ( $prime_cache_config['cache_footprint'] ) {
		$content .= "\n<!-- Cached by Prime Cache on " . gmdate( 'Y-m-d H:i:s' ) . ' UTC -->';
	}

	// Atomic write: temp file → rename.
	$filepath = $cache_dir . $filename;
	$tempfile = $filepath . '.tmp.' . getmypid();

	if ( false === file_put_contents( $tempfile, $content ) ) {
		return $buffer;
	}

	if ( ! rename( $tempfile, $filepath ) ) {
		@unlink( $tempfile );
		return $buffer;
	}

	// Gzip variant.
	if ( $prime_cache_config['gzip_compression'] && function_exists( 'gzencode' ) ) {
		$gz_filename = _prime_cache_get_filename(
			$_pc_is_ssl,
			$_pc_is_mobile,
			$prime_cache_config['cache_mobile_separate'],
			true,
			$_pc_vary_suffix,
			$_pc_qs_suffix
		);
		$gz_filepath = $cache_dir . $gz_filename;
		$gz_tempfile = $gz_filepath . '.tmp.' . getmypid();
		$gz_content  = gzencode( $content, 6 );

		if ( false !== file_put_contents( $gz_tempfile, $gz_content ) ) {
			if ( ! rename( $gz_tempfile, $gz_filepath ) ) {
				@unlink( $gz_tempfile );
			}
		}
	}

	// Save response headers as meta.
	$headers_list_raw = headers_list();
	if ( ! empty( $headers_list_raw ) ) {
		$meta_headers = array();
		foreach ( $headers_list_raw as $header ) {
			// Only preserve Content-Type and custom headers, not cache control.
			if ( preg_match( '#^(Content-Type|X-|Link)#i', $header ) ) {
				$meta_headers[] = $header;
			}
		}
		if ( ! empty( $meta_headers ) || http_response_code() !== 200 ) {
			$meta_data = json_encode( array( 'headers' => $meta_headers, 'status' => http_response_code() ) );
			$meta_tmp = $cache_dir . 'meta.json.tmp.' . getmypid();
			if ( false !== file_put_contents( $meta_tmp, $meta_data ) ) {
				rename( $meta_tmp, $cache_dir . 'meta.json' );
			}
		}
	}

	header( 'X-Prime-Cache: MISS' );

	_prime_cache_record_stat( 'miss' );

	return $buffer;
} );
