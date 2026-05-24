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

// Fail-close if the bootstrapping constants advanced-cache.php normally
// defines are missing. A corrupted, partially-rewritten, or hand-edited
// advanced-cache.php would otherwise let this dropin run and Error in
// PHP 8 the first time it concatenates an undefined constant — taking the
// whole site down on every request. Returning here lets WordPress boot
// normally; admin warnings will surface the broken dropin separately.
if ( ! defined( 'PRIME_CACHE_CACHE_DIR' ) || ! is_string( PRIME_CACHE_CACHE_DIR ) || '' === PRIME_CACHE_CACHE_DIR ) {
	return;
}

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
	'cache_mixed_scheme'    => false,
	'cache_lifespan'        => 0,
	'cache_footprint'       => true,
	'cache_ignore_qs'       => 'utm_source, utm_medium, utm_campaign, utm_term, utm_content, utm_expid, fbclid, gclid, ga_source, ga_medium, ga_campaign, ga_term, ga_content',
);
// Allowed-host list (written by Prime_Cache_Config::write_config_file). Empty = allow any host
// (back-compat for config files generated before host validation existed).
$prime_cache_allowed_hosts = array();
// Canonical site scheme (written by write_config_file from home_url()). Empty string means
// "use header detection" — the case for back-compat with old config files or for sites
// where the user enabled cache_mixed_scheme.
$prime_cache_site_scheme = '';

$_pc_using_legacy_config = false;
if ( defined( 'PRIME_CACHE_CONFIG_DIR' ) ) {
	// Install-unique config file (ABSPATH + DB_NAME + table_prefix prevents
	// shared wp-content collision while staying stable across salt rotation).
	$_pc_install_seed = ABSPATH . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' )
		. '|' . ( isset( $GLOBALS['table_prefix'] ) ? (string) $GLOBALS['table_prefix'] : '' );
	$_pc_install_key  = substr( md5( $_pc_install_seed ), 0, 8 );
	$_pc_config_file  = PRIME_CACHE_CONFIG_DIR . 'site-config-' . $_pc_install_key . '.php';
	if ( is_readable( $_pc_config_file ) ) {
		include $_pc_config_file;
	} else {
		// Fall back to the legacy AUTH_SALT-keyed filename so an upgrade
		// where prime-cache-config/ is read-only doesn't disable caching
		// until the admin manually fixes permissions. The PHP-side write
		// path will eventually replace this file once writes succeed.
		$_pc_legacy_seed = ABSPATH . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' )
			. '|' . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
		$_pc_legacy_key  = substr( md5( $_pc_legacy_seed ), 0, 8 );
		if ( $_pc_legacy_key !== $_pc_install_key ) {
			$_pc_legacy_file = PRIME_CACHE_CONFIG_DIR . 'site-config-' . $_pc_legacy_key . '.php';
			if ( is_readable( $_pc_legacy_file ) ) {
				include $_pc_legacy_file;
				$_pc_using_legacy_config = true;
			}
		}
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
$_pc_deferred_stat = null;

function _prime_cache_record_stat( $type ) {
	global $_pc_deferred_stat;
	// 1/10 sampling to reduce I/O. Stats are approximate.
	if ( mt_rand( 1, 10 ) !== 1 ) {
		return;
	}
	// Defer write to shutdown — keeps it off the critical TTFB path.
	if ( null === $_pc_deferred_stat ) {
		register_shutdown_function( '_prime_cache_flush_stat' );
	}
	$_pc_deferred_stat = $type;
}

function _prime_cache_flush_stat() {
	global $_pc_deferred_stat;
	if ( ! $_pc_deferred_stat ) return;

	$stats_file = PRIME_CACHE_CACHE_DIR . 'stats.json';
	$stats      = array( 'hit' => 0, 'miss' => 0, 'since' => time() );

	// Mode 'c+' (read+write), not 'c' (write-only): the stream_get_contents()
	// read below silently returns false on a write-only handle, which would
	// stop the hit/miss counters from ever accumulating past one increment.
	$fp = @fopen( $stats_file, 'c+' );
	if ( $fp && flock( $fp, LOCK_EX | LOCK_NB ) ) {
		fseek( $fp, 0 );
		$current = @stream_get_contents( $fp );
		if ( $current ) {
			$current_data = json_decode( $current, true );
			if ( is_array( $current_data ) ) {
				$stats = $current_data;
			}
		}
		$stats[ $_pc_deferred_stat ] = isset( $stats[ $_pc_deferred_stat ] ) ? $stats[ $_pc_deferred_stat ] + 10 : 10;
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

// Only GET and HEAD requests.
if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ), true ) ) {
	return;
}
$_pc_is_head = 'HEAD' === $_SERVER['REQUEST_METHOD'];

// Never cache authenticated requests (Basic Auth, Bearer tokens, Digest).
if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] )
	|| ! empty( $_SERVER['PHP_AUTH_USER'] ) || ! empty( $_SERVER['PHP_AUTH_DIGEST'] ) ) {
	return;
}

$_pc_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

// Validate Host against the allowed list written by Prime_Cache_Config::write_config_file().
// Fail-close: an empty list (config missing or pre-allowed-hosts schema) means we cannot
// validate the request, so we skip caching rather than risk per-Host disk-fill DoS.
// The plugin's plugins_loaded hook regenerates the config file early in the request
// lifecycle so the first cacheable request after a plugin update is protected.
require_once dirname( __FILE__ ) . '/../includes/cache-key-functions.php';
$_pc_host = _prime_cache_normalize_host( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' );
if ( '' === $_pc_host ) {
	return;
}
// Pre-allowlist legacy config files don't define $prime_cache_allowed_hosts,
// so a fail-close here would permanently break caching whenever we fall back
// to a legacy file (read-only `prime-cache-config/` after upgrade). When we
// know the host check can't be enforced (legacy config), skip it — that's
// the same security posture the legacy version had. Modern config flows
// always populate the array and stay fail-close.
if ( ! $_pc_using_legacy_config ) {
	if ( empty( $prime_cache_allowed_hosts ) || ! in_array( $_pc_host, $prime_cache_allowed_hosts, true ) ) {
		return;
	}
}

// Skip admin, login, cron, xmlrpc. Boundary-anchored so legitimate post URLs
// like /category/wp-admin-tutorials/ aren't accidentally excluded — match
// these as a path segment (start-of-string or `/`) followed by the literal
// trailed by `/`, end-of-string, or `?`.
if ( preg_match( '#(?:^|/)(?:wp-admin|wp-login\.php|wp-cron\.php|xmlrpc\.php)(?:/|$|\?)#', $_pc_request_uri ) ) {
	return;
}

// WooCommerce: always skip cart, checkout, account, and AJAX endpoints.
// Boundary-aware to avoid matching /cartoon/, /checkout-guide/, /my-accounting/ etc.
if ( preg_match( '#(?:^|/)(?:cart|checkout|my-account)(?:/|$|\?)|(?:^|/)wc-api(?:/|$)|(?:[?&](?:wc-ajax|add-to-cart)=)#i', $_pc_request_uri ) ) {
	return;
}
// Cookies that always bypass cache regardless of cache_logged_in:
// - WooCommerce cart/session: response varies per cart
// - comment_author_*: post page shows pre-filled form fields and pending-moderation notice
// - wp-postpass_*: response is the unlocked post body, must not be served to others
if ( ! empty( $_COOKIE ) ) {
	foreach ( array_keys( $_COOKIE ) as $_pc_bypass_ck ) {
		if ( 0 === strpos( $_pc_bypass_ck, 'woocommerce_cart_hash' )
			|| 0 === strpos( $_pc_bypass_ck, 'wp_woocommerce_session_' )
			|| 0 === strpos( $_pc_bypass_ck, 'woocommerce_items_in_cart' )
			|| 0 === strpos( $_pc_bypass_ck, 'comment_author_' )
			|| 0 === strpos( $_pc_bypass_ck, 'wp-postpass_' ) ) {
			return;
		}
	}
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
			// 16 hex (64-bit) so an attacker can't cheaply craft a query-string
			// value that collides with a victim's variant to poison its cache.
			$_pc_qs_suffix = '-qs_' . substr( md5( http_build_query( $_pc_qs_to_cache ) ), 0, 16 );
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

// Rejected cookies. preg_match returns false on regex compile error — for a
// REJECT pattern that means "don't know if this should be excluded", and the
// safe default for a "should I skip caching this" check is YES (fail-close).
if ( ! empty( $prime_cache_config['cache_reject_cookies'] ) && ! empty( $_COOKIE ) ) {
	foreach ( array_keys( $_COOKIE ) as $_pc_cookie_name ) {
		$_pc_match = @preg_match( '#' . $prime_cache_config['cache_reject_cookies'] . '#i', $_pc_cookie_name );
		if ( false === $_pc_match || 1 === $_pc_match ) {
			return;
		}
	}
}

// Rejected URI patterns.
if ( ! empty( $prime_cache_config['cache_reject_uri'] ) ) {
	$_pc_match = @preg_match( '#(' . $prime_cache_config['cache_reject_uri'] . ')#i', $_pc_path );
	if ( false === $_pc_match || 1 === $_pc_match ) {
		return;
	}
}

// Rejected user agents.
if ( ! empty( $prime_cache_config['cache_reject_ua'] ) ) {
	$_pc_ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
	if ( $_pc_ua ) {
		$_pc_match = @preg_match( '#' . $prime_cache_config['cache_reject_ua'] . '#i', $_pc_ua );
		if ( false === $_pc_match || 1 === $_pc_match ) {
			return;
		}
	}
}

// Rejected referrers.
if ( ! empty( $prime_cache_config['cache_reject_referrer'] ) && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
	$_pc_match = @preg_match( '#(' . $prime_cache_config['cache_reject_referrer'] . ')#i', $_SERVER['HTTP_REFERER'] );
	if ( false === $_pc_match || 1 === $_pc_match ) {
		return;
	}
}

// Mobile detection — single source of truth shared with file-optimizer's HTML
// transforms (wrap_inline_jquery / delay_all_scripts). cache-key-functions.php
// was already required above for normalize_host/normalize_path.
$_pc_is_mobile = _prime_cache_is_mobile_ua( $_SERVER['HTTP_USER_AGENT'] ?? '' );

// Skip mobile if mobile caching is disabled.
if ( $_pc_is_mobile && ! $prime_cache_config['cache_mobile'] ) {
	return;
}

// ----- Determine Cache Path -----

// SSL detection.
//
// Default path (single-scheme sites): trust the site scheme written from home_url() at config
// time. This auto-handles reverse proxies (the proxy terminates SSL, but home_url() still
// reflects the public scheme) and is immune to X-Forwarded-Proto spoofing.
//
// Fallback path: header sniffing — used when the site explicitly enables cache_mixed_scheme
// (intentional http://+https:// coexistence) or when the config file is too old to contain
// $prime_cache_site_scheme. In this mode X-Forwarded-Proto is honored only when the operator
// opts in via PRIME_CACHE_TRUST_X_FORWARDED_PROTO in wp-config.php (set only behind a trusted
// reverse proxy that always normalizes the header).
// Always compute the actual request scheme from headers first. A mismatch
// between the canonical site_scheme and the real request scheme means the
// request is reaching PHP without the expected scheme — typically a real
// http:// request to an https:// site whose redirect runs *inside*
// WordPress (after this drop-in). Serving the https cache to that request
// would short-circuit the redirect and leak the secure response over plain
// http. Bail in that case so WP can run and redirect.
$_pc_request_is_ssl = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
	|| ( ! empty( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] )
	|| ( defined( 'PRIME_CACHE_TRUST_X_FORWARDED_PROTO' ) && PRIME_CACHE_TRUST_X_FORWARDED_PROTO
		&& ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
		&& 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );

if ( '' !== $prime_cache_site_scheme && empty( $prime_cache_config['cache_mixed_scheme'] ) ) {
	$_pc_is_ssl = ( 'https' === $prime_cache_site_scheme );
	if ( $_pc_is_ssl && ! $_pc_request_is_ssl ) {
		// site_scheme says https but the request shows no https signal.
		// Default behavior: bail and let WP run (it will issue the
		// http→https redirect). Serving the https cache to an unsignal-
		// https request would short-circuit that redirect and leak the
		// secure response over plaintext.
		//
		// TLS-terminating proxies that strip both `HTTPS` and
		// `X-Forwarded-Proto` cannot be auto-detected — at PHP level the
		// request looks identical to a direct http request. Operators in
		// that situation must opt in via either:
		//   - configure the proxy to send X-Forwarded-Proto + define
		//     PRIME_CACHE_TRUST_X_FORWARDED_PROTO (preferred; per-request
		//     accuracy)
		//   - define PRIME_CACHE_PROXY_NO_XFP for "trust site_scheme
		//     unconditionally" (back-compat with pre-fix behavior)
		//   - enable cache_mixed_scheme=true (cache both schemes
		//     separately based on request signals)
		if ( ! ( defined( 'PRIME_CACHE_PROXY_NO_XFP' ) && PRIME_CACHE_PROXY_NO_XFP ) ) {
			return;
		}
	}
} else {
	$_pc_is_ssl = $_pc_request_is_ssl;
}

/**
 * Build cache directory path from host + request path.
 * Uses the host validated and normalized at the top of this file.
 */
function _prime_cache_get_cache_dir() {
	global $_pc_request_uri, $_pc_host;

	$path = _prime_cache_normalize_path( strtok( $_pc_request_uri, '?' ) );

	return PRIME_CACHE_CACHE_DIR . $_pc_host . $path;
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
 * @param int    $status               HTTP status code (200 default; 404 yields a 404-index filename).
 * @return string
 */
function _prime_cache_get_filename( $is_ssl, $is_mobile, $use_mobile_separate, $gzip = false, $vary_suffix = '', $qs_suffix = '', $status = 200 ) {
	$name = ( 404 === $status ) ? '404-index' : 'index';

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
		// serialize() is binary-safe; json_encode() returns false on invalid
		// UTF-8 cookie values, and md5(false) === md5('') would collapse
		// distinct cookie sets into the same vary bucket and serve the wrong
		// variant.
		// 16 hex (64-bit) so a colliding cookie value can't be crafted to overwrite
		// another visitor's cached variant.
		$_pc_vary_suffix = '-vc_' . substr( md5( serialize( $_pc_vary_vals ) ), 0, 16 );
	}
}

$_pc_cache_dir  = _prime_cache_get_cache_dir();
$_pc_filename   = _prime_cache_get_filename( $_pc_is_ssl, $_pc_is_mobile, $prime_cache_config['cache_mobile_separate'], false, $_pc_vary_suffix, $_pc_qs_suffix );

// ----- Try to Serve Cached File -----

$_pc_cache_file = $_pc_cache_dir . $_pc_filename;

// Also check for 404-prefixed cache file when cache_404 is enabled.
$_pc_serving_404 = false;
if ( ! is_readable( $_pc_cache_file ) && ! empty( $prime_cache_config['cache_404'] ) ) {
	$_pc_404_filename = _prime_cache_get_filename( $_pc_is_ssl, $_pc_is_mobile, $prime_cache_config['cache_mobile_separate'], false, $_pc_vary_suffix, $_pc_qs_suffix, 404 );
	$_pc_404_file     = $_pc_cache_dir . $_pc_404_filename;
	if ( is_readable( $_pc_404_file ) ) {
		$_pc_cache_file  = $_pc_404_file;
		$_pc_filename    = $_pc_404_filename;
		$_pc_serving_404 = true;
	}
}

if ( is_readable( $_pc_cache_file ) ) {
	// TOCTOU guard: a concurrent purge between is_readable() and filemtime()
	// would yield false here. Treat that as "no cache" so WordPress regenerates
	// rather than emitting Last-Modified=1970 from a false mtime.
	$_pc_modified_time = @filemtime( $_pc_cache_file );
	$_pc_lifespan      = isset( $prime_cache_config['cache_lifespan'] ) ? (int) $prime_cache_config['cache_lifespan'] : 0;

	// Skip serving if mtime read failed or cache file has expired — let WordPress regenerate it.
	if ( false === $_pc_modified_time || ( $_pc_lifespan > 0 && ( time() - $_pc_modified_time ) > $_pc_lifespan ) ) {
		// Fall through to output buffering below.
	} else {

	// Read meta FIRST to determine original status code before 304 processing.
	// TOCTOU: file_get_contents may return false if the meta file is purged
	// between is_readable() and the read. PHP 8 json_decode( false, true )
	// throws TypeError, so guard with is_string() before decoding.
	$_pc_meta_file = $_pc_cache_dir . $_pc_filename . '.meta.json';
	$_pc_meta           = null;
	$_pc_original_status = 200;
	if ( is_readable( $_pc_meta_file ) ) {
		$_pc_meta_raw = @file_get_contents( $_pc_meta_file );
		if ( is_string( $_pc_meta_raw ) ) {
			$_pc_meta = json_decode( $_pc_meta_raw, true );
			if ( is_array( $_pc_meta ) && ! empty( $_pc_meta['status'] ) ) {
				$_pc_original_status = (int) $_pc_meta['status'];
			}
		}
	}

	// Determine response file BEFORE sending any headers. Doing it up here means
	// a TOCTOU bail (cache file vanished mid-flight) falls through to the OB
	// path with NO Prime-Cache headers leaking onto the regenerated response.
	$_pc_accept_gzip = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) && strpos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' ) !== false;
	$_pc_gz_status   = $_pc_serving_404 ? 404 : 200;
	$_pc_gz_file     = $_pc_cache_dir . _prime_cache_get_filename( $_pc_is_ssl, $_pc_is_mobile, $prime_cache_config['cache_mobile_separate'], true, $_pc_vary_suffix, $_pc_qs_suffix, $_pc_gz_status );
	$_pc_use_gz      = $_pc_accept_gzip && is_readable( $_pc_gz_file );
	$_pc_serve_file  = $_pc_use_gz ? $_pc_gz_file : $_pc_cache_file;
	$_pc_serve_size  = @filesize( $_pc_serve_file );
	if ( false === $_pc_serve_size && $_pc_use_gz && is_readable( $_pc_cache_file ) ) {
		// gz vanished but HTML still there — serve uncompressed instead of
		// punting to a wasteful regeneration.
		$_pc_use_gz     = false;
		$_pc_serve_file = $_pc_cache_file;
		$_pc_serve_size = @filesize( $_pc_serve_file );
	}

	if ( false !== $_pc_serve_size ) {
		// Restore meta headers FIRST — needed for both 304 and 200 responses.
		if ( is_array( $_pc_meta ) && ! empty( $_pc_meta['headers'] ) ) {
			foreach ( $_pc_meta['headers'] as $_pc_header ) {
				// Defensive validation: meta.json could be tampered with on
				// disk. Reject non-strings and any value containing CR/LF or
				// NUL bytes — emitting them via header() would let an attacker
				// who can write to the cache directory inject response headers.
				if ( ! is_string( $_pc_header ) ) {
					continue;
				}
				if ( false !== strpbrk( $_pc_header, "\r\n\0" ) ) {
					continue;
				}
				// Require Name: value shape so a stray data line cannot become
				// a header by accident.
				if ( false === strpos( $_pc_header, ':' ) ) {
					continue;
				}
				$_pc_replace = true;
				if ( 0 === strncasecmp( $_pc_header, 'Link:', 5 ) || 0 === strncasecmp( $_pc_header, 'X-', 2 ) ) {
					$_pc_replace = false;
				}
				header( $_pc_header, $_pc_replace );
			}
		}
		if ( is_array( $_pc_meta ) && 200 !== $_pc_original_status ) {
			http_response_code( $_pc_original_status );
		}

		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $_pc_modified_time ) . ' GMT' );

		// All Vary headers BEFORE 304 — must be consistent with 200 responses.
		if ( ! empty( $prime_cache_config['cache_vary_cookies'] ) ) {
			header( 'Vary: Cookie', false );
		}
		if ( ! empty( $prime_cache_config['gzip_compression'] ) ) {
			header( 'Vary: Accept-Encoding', false );
		}

		// HTTP 304 Not Modified — only for 200 responses.
		// All cache-semantics headers (Vary, Last-Modified, meta) are already set above.
		if ( 200 === $_pc_original_status && ! empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$_pc_since = strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
			if ( $_pc_since && $_pc_since >= $_pc_modified_time ) {
				header( $_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified', true, 304 );
				header( 'Cache-Control: no-cache, must-revalidate' );
				header( 'X-Prime-Cache: HIT-304' );
				_prime_cache_record_stat( 'hit' );
				exit;
			}
		}

		header( 'X-Prime-Cache: HIT' );

		// Browser cache: allow browsers to cache the HTML for a short period.
		$_pc_html_maxage = isset( $prime_cache_config['browser_cache_html'] ) ? (int) $prime_cache_config['browser_cache_html'] : 0;
		if ( $_pc_html_maxage > 0 && ! empty( $prime_cache_config['browser_cache'] ) ) {
			header( 'Cache-Control: public, max-age=' . $_pc_html_maxage . ', must-revalidate' );
		} else {
			header( 'Cache-Control: no-cache, must-revalidate' );
		}

		_prime_cache_record_stat( 'hit' );
		if ( $_pc_use_gz ) {
			header( 'Content-Encoding: gzip' );
		}
		header( 'Content-Length: ' . $_pc_serve_size );

		// Output body (GET only — HEAD gets headers without body).
		if ( ! $_pc_is_head ) {
			@readfile( $_pc_serve_file );
		}
		exit;
	}
	// Else: fall through to OB path so WordPress regenerates the response.
	// No Prime-Cache headers were sent, so the regenerated response stays clean.

	} // end lifespan check
}

// ----- Start Output Buffering -----

ob_start( function ( $buffer ) {
	global $prime_cache_config, $_pc_is_ssl, $_pc_is_mobile, $_pc_vary_suffix, $_pc_qs_suffix;

	// Post-buffering tests.
	if ( strlen( $buffer ) < 255 ) {
		return $buffer;
	}

	// Check HTTP status code — allow 404 if cache_404 is enabled.
	$_pc_status = http_response_code();
	if ( 404 === $_pc_status ) {
		if ( empty( $prime_cache_config['cache_404'] ) ) {
			return $buffer;
		}
	} elseif ( 200 !== $_pc_status ) {
		return $buffer;
	}

	if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
		return $buffer;
	}

	// Check response headers for cache-inhibiting signals.
	$_pc_resp_headers = headers_list();
	foreach ( $_pc_resp_headers as $_pc_rh ) {
		$_pc_rh_lower = strtolower( $_pc_rh );
		// Never cache responses that set cookies (session, consent, A/B, CSRF).
		if ( 0 === strpos( $_pc_rh_lower, 'set-cookie:' ) ) {
			return $buffer;
		}
		// Respect Cache-Control: private, no-store.
		// Note: no-cache means "revalidate before use" — it does NOT prohibit
		// server-side page caching. Plugins and themes commonly set no-cache
		// as a default; only no-store and private genuinely prevent caching.
		if ( 0 === strpos( $_pc_rh_lower, 'cache-control:' ) ) {
			if ( preg_match( '#\b(private|no-store)\b#i', $_pc_rh ) ) {
				return $buffer;
			}
		}
		// Vary header: only Accept-Encoding is safe — we already key by gzip variant.
		// Any other token (Accept-Language, User-Agent, Cookie, etc.) means the response
		// varies on a dimension we don't bucket by, so caching it would serve the wrong
		// variant to the next visitor. Vary: Cookie is included because cache_vary_cookies
		// only buckets specific named cookies, not "any cookie".
		if ( 0 === stripos( $_pc_rh, 'vary:' ) ) {
			$_pc_vary_value = trim( substr( $_pc_rh, 5 ) );
			if ( '*' === $_pc_vary_value ) {
				return $buffer;
			}
			$_pc_vary_tokens = preg_split( '#\s*,\s*#', strtolower( $_pc_vary_value ), -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $_pc_vary_tokens as $_pc_vary_token ) {
				if ( 'accept-encoding' === $_pc_vary_token ) {
					continue;
				}
				return $buffer;
			}
		}
	}

	if ( false === stripos( $buffer, '</html>' ) ) {
		return $buffer;
	}

	// Skip search results.
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
		$_pc_qs_suffix,
		$_pc_status
	);

	// Create directory.
	if ( ! is_dir( $cache_dir ) ) {
		if ( ! @mkdir( $cache_dir, 0755, true ) ) {
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

	$filepath = $cache_dir . $filename;

	// Capture status and headers BEFORE any file write so meta can be persisted
	// before the HTML becomes visible. Order matters: a HIT that sees the HTML
	// file but no meta would default $_pc_original_status to 200 and skip the
	// stored security headers — so meta must land first.
	$_pc_current_status = http_response_code();
	$headers_list_raw   = headers_list();
	$meta_headers       = array();
	if ( ! empty( $headers_list_raw ) ) {
		foreach ( $headers_list_raw as $header ) {
			// Preserve Content-Type, security headers, custom headers, and Link.
			// Excludes Set-Cookie, Cache-Control, Pragma (already checked above).
			if ( preg_match( '#^(Content-Type|Content-Security-Policy|Referrer-Policy|Permissions-Policy|Cross-Origin-|X-Frame-Options|X-Content-Type-Options|X-|Link)#i', $header ) ) {
				$meta_headers[] = $header;
			}
		}
	}

	$meta_needed     = ( ! empty( $meta_headers ) || 200 !== $_pc_current_status );
	$meta_file       = $cache_dir . $filename . '.meta.json';
	$meta_old_backup = null; // Holds the prior meta body so we can roll back if HTML fails.
	if ( $meta_needed ) {
		// Snapshot the existing meta (if any) before we overwrite it so we
		// can restore the (old HTML + old meta) pair on HTML write failure.
		// Without this, an HTML-failure leaves (old HTML + new meta) — the
		// served body would be stale but the response status/headers would
		// describe what new HTML was supposed to be.
		if ( file_exists( $meta_file ) ) {
			$_pc_old_meta = @file_get_contents( $meta_file );
			if ( is_string( $_pc_old_meta ) ) {
				$meta_old_backup = $_pc_old_meta;
			}
		}
		$meta_data = json_encode( array( 'headers' => $meta_headers, 'status' => $_pc_current_status ) );
		$meta_tmp  = $meta_file . '.tmp.' . getmypid();
		$meta_ok   = false;
		if ( false !== file_put_contents( $meta_tmp, $meta_data ) ) {
			$meta_ok = rename( $meta_tmp, $meta_file );
			if ( ! $meta_ok ) {
				@unlink( $meta_tmp );
			}
		}
		if ( ! $meta_ok ) {
			return $buffer;
		}
	}

	$_pc_rollback_meta = function () use ( $meta_needed, $meta_file, $meta_old_backup ) {
		if ( ! $meta_needed ) {
			return;
		}
		if ( null !== $meta_old_backup ) {
			// Restore the prior meta so HIT serves (old HTML + old meta).
			$restore_tmp = $meta_file . '.tmp.restore.' . getmypid();
			if ( false !== @file_put_contents( $restore_tmp, $meta_old_backup ) ) {
				if ( ! @rename( $restore_tmp, $meta_file ) ) {
					@unlink( $restore_tmp );
				}
			}
		} else {
			// No prior meta — remove the new one rather than leaving (no HTML
			// + new meta) for the next regeneration to puzzle over.
			@unlink( $meta_file );
		}
	};

	// Atomic write: temp file → rename.
	$tempfile = $filepath . '.tmp.' . getmypid();

	if ( false === file_put_contents( $tempfile, $content ) ) {
		$_pc_rollback_meta();
		return $buffer;
	}

	if ( ! rename( $tempfile, $filepath ) ) {
		@unlink( $tempfile );
		$_pc_rollback_meta();
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
			$_pc_qs_suffix,
			$_pc_status
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

	header( 'X-Prime-Cache: MISS' );

	_prime_cache_record_stat( 'miss' );

	return $buffer;
} );
