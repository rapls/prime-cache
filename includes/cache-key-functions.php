<?php
/**
 * Shared cache key generation functions.
 *
 * This file is included by both the dropin (pre-WordPress) and the main plugin.
 * It must not use any WordPress functions — only pure PHP.
 */

defined( 'ABSPATH' ) || exit;

// Shared pure-PHP helpers used by both the pre-WordPress drop-in and the plugin.
// They intentionally use a leading-underscore "_prime_cache_" prefix to mark them
// internal while still namespacing against collisions, so the prefix sniff is
// disabled for this file.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if ( function_exists( '_prime_cache_normalize_host' ) ) {
	return; // Already loaded.
}

/**
 * Normalize a hostname for use as a cache directory name.
 *
 * - Lowercased (HTTP Host is case-insensitive)
 * - Port stripped (e.g. example.com:8080 → example.com)
 * - Unsafe characters removed
 *
 * @param string $host Raw hostname (e.g. from HTTP_HOST).
 * @return string Normalized hostname.
 */
function _prime_cache_normalize_host( $host ) {
	$host = strtolower( trim( $host ) );

	if ( '' === $host ) {
		return '';
	}

	// Handle bracketed IPv6: [2001:db8::1] or [2001:db8::1]:8080
	if ( 0 === strpos( $host, '[' ) ) {
		$bracket_end = strpos( $host, ']' );
		if ( false !== $bracket_end ) {
			$host = substr( $host, 1, $bracket_end - 1 );
		}
	} else {
		// Detect bare IPv6 (multiple colons = IPv6, not host:port).
		$colon_count = substr_count( $host, ':' );
		if ( $colon_count > 1 ) {
			// Bare IPv6 address — keep as-is (no port stripping).
		} elseif ( 1 === $colon_count ) {
			// Single colon = host:port — strip port.
			$host = substr( $host, 0, strrpos( $host, ':' ) );
		}
	}

	// IDN normalization: convert Unicode hostnames to Punycode (UTS#46) so that
	// `日本.example` and `xn--wgv71a.example` map to the same cache bucket. Without
	// this, the allow-list (built from home_url() in WP context as Punycode) would
	// fail to match a Unicode Host: header, and vice versa.
	// Requires the intl extension; without it we keep the lowercased ASCII fallback
	// (Unicode chars then get hex-encoded by the unsafe-char step below — still
	// collision-free, but won't match a Punycode allow-list entry).
	if ( function_exists( 'idn_to_ascii' ) && preg_match( '#[^\x00-\x7f]#', $host ) ) {
		$variant = defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0;
		$ascii   = @idn_to_ascii( $host, IDNA_DEFAULT, $variant );
		if ( is_string( $ascii ) && '' !== $ascii ) {
			$host = $ascii;
		}
	}

	// Encode unsafe chars with underscore-hex (same approach as path segments).
	// This preserves IPv6 colons as _3a instead of stripping them, preventing
	// distinct addresses from collapsing to the same string.
	return preg_replace_callback(
		'#[^a-z0-9.\-]#',
		function( $m ) { return '_' . bin2hex( $m[0] ); },
		$host
	);
}

/**
 * Normalize a URL path into safe, collision-free filesystem segments.
 *
 * - Does NOT rawurldecode (preserves %2F vs / distinction)
 * - Each segment is encoded with underscore-hex for unsafe chars
 * - Traversal attempts (.. and encoded variants) are blocked
 *
 * @param string $path Raw URL path.
 * @return string Normalized path with trailing slash.
 */
/**
 * Generate a config filename key from a hostname.
 *
 * Uses the same normalization as cache directory host, ensuring that
 * config write (WP context), config read (dropin), and config delete
 * all produce the same filename.
 *
 * @param string $host Raw hostname.
 * @return string Safe filename (without extension).
 */
function _prime_cache_config_host_key( $host ) {
	return _prime_cache_normalize_host( $host );
}

/**
 * Normalize a URL path into safe, collision-free filesystem segments.
 *
 * - Does NOT rawurldecode (preserves %2F vs / distinction)
 * - Each segment is encoded with underscore-hex for unsafe chars
 * - Traversal attempts (.. and encoded variants) are blocked
 *
 * @param string $path Raw URL path.
 * @return string Normalized path with trailing slash.
 */
/**
 * Detect whether a User-Agent string represents a mobile device.
 *
 * Single source of truth shared between:
 *  - dropins/page-cache.php (cache key — picks the mobile bucket)
 *  - includes/class-file-optimizer.php (HTML transforms — wraps inline jQuery,
 *    applies delay JS — must apply to exactly the same requests the cache key
 *    treats as mobile, otherwise a desktop-rendered HTML can land in the mobile
 *    bucket or vice versa)
 *
 * The Apache .htaccess fast-path uses an equivalent literal regex (it can't
 * call PHP); keep both lists in sync when adding agents.
 *
 * @param string $ua Raw User-Agent string ($_SERVER['HTTP_USER_AGENT']).
 * @return bool True for mobile/tablet UAs.
 */
function _prime_cache_is_mobile_ua( $ua ) {
	if ( ! is_string( $ua ) || '' === $ua ) {
		return false;
	}
	return (bool) preg_match(
		'#(Mobile|Android|Silk/|Kindle|BlackBerry|Opera Mini|Opera Mobi|webOS)#i',
		$ua
	);
}

function _prime_cache_normalize_path( $path ) {
	$segments = explode( '/', $path );
	$safe = array();
	foreach ( $segments as $seg ) {
		if ( '' === $seg ) {
			continue;
		}
		// Block traversal (literal and encoded forms).
		$lower = strtolower( $seg );
		if ( '..' === $seg || '.' === $seg || '%2e%2e' === $lower || '%2e' === $lower ) {
			continue;
		}
		$safe[] = preg_replace_callback(
			'#[^a-zA-Z0-9_\-]#',
			function( $m ) { return '_' . bin2hex( $m[0] ); },
			$seg
		);
	}
	return empty( $safe ) ? '/' : '/' . implode( '/', $safe ) . '/';
}
