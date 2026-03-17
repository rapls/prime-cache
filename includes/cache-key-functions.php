<?php
/**
 * Shared cache key generation functions.
 *
 * This file is included by both the dropin (pre-WordPress) and the main plugin.
 * It must not use any WordPress functions — only pure PHP.
 */

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
	$host = strtolower( $host );
	// Strip port.
	if ( false !== ( $colon = strrpos( $host, ':' ) ) ) {
		$host = substr( $host, 0, $colon );
	}
	return preg_replace( '#[^a-z0-9.\-]#', '', $host );
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
