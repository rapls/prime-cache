<?php
/**
 * .htaccess rule management for Prime Cache.
 *
 * Generates Apache directives for:
 * - Serving cached HTML/gz files directly via mod_rewrite (bypasses PHP)
 * - Gzip compression via mod_deflate
 * - Browser caching via mod_expires
 * - ETag removal for static assets
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Htaccess {

	const MARKER = 'Prime Cache';

	/**
	 * Write Prime Cache rules into .htaccess.
	 *
	 * @param array $settings Plugin settings.
	 * @return bool
	 */
	public static function add_rules( $settings ) {
		$htaccess = self::get_htaccess_path();
		if ( ! $htaccess ) {
			return false;
		}

		$rules = self::build_rules( $settings );

		// Prime Cache rules MUST appear before # BEGIN WordPress in .htaccess.
		// WordPress's rewrite rules convert permalink URLs to index.php with [L],
		// which stops all further rule processing. If our rules come after,
		// Apache never checks for cached files.
		return self::insert_before_wordpress( $htaccess, self::MARKER, $rules );
	}

	/**
	 * Insert rules BEFORE # BEGIN WordPress in .htaccess.
	 *
	 * WordPress's insert_with_markers() always appends after WordPress rules,
	 * which prevents our rewrite rules from ever matching permalink URLs.
	 *
	 * @param string $htaccess Path to .htaccess file.
	 * @param string $marker   Marker name.
	 * @param array  $rules    Lines of rules.
	 * @return bool
	 */
	private static function insert_before_wordpress( $htaccess, $marker, $rules ) {
		// Settings save / activation / self-heal can race against each other.
		// Without serialization the last writer wins and silently drops
		// concurrent edits. Lock on a sibling file in WP_CONTENT_DIR (we may
		// not have write permission to ABSPATH where .htaccess usually lives).
		$lock_path = WP_CONTENT_DIR . '/.prime-cache-htaccess.lock';
		$lock_fp   = @fopen( $lock_path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $lock_fp ) {
			@flock( $lock_fp, LOCK_EX );
		}

		try {
			if ( ! file_exists( $htaccess ) ) {
				// No .htaccess yet — use standard insert_with_markers.
				return insert_with_markers( $htaccess, $marker, $rules );
			}

			$content = file_get_contents( $htaccess ); // phpcs:ignore
			if ( false === $content ) {
				return false;
			}

			$begin_marker = '# BEGIN ' . $marker;
			$end_marker   = '# END ' . $marker;
			$begin_wp     = '# BEGIN WordPress';

			// Build the new block.
			$block_lines   = array();
			$block_lines[] = $begin_marker;
			foreach ( $rules as $line ) {
				$block_lines[] = $line;
			}
			$block_lines[] = $end_marker;
			$new_block     = implode( "\n", $block_lines ) . "\n";

			// Remove existing Prime Cache block if present.
			$pattern = '#\s*' . preg_quote( $begin_marker, '#' ) . '.*?' . preg_quote( $end_marker, '#' ) . '\s*#si';
			$content = preg_replace( $pattern, "\n", $content );

			// Insert before the FIRST '# BEGIN WordPress'. str_replace would inject
			// our block before every occurrence on a malformed .htaccess that lists
			// the marker more than once.
			$wp_pos = strpos( $content, $begin_wp );
			if ( false !== $wp_pos ) {
				$content = substr_replace( $content, $new_block . "\n", $wp_pos, 0 );
			} else {
				// No WordPress block — prepend to file.
				$content = $new_block . "\n" . $content;
			}

			// Clean up multiple blank lines.
			$content = preg_replace( "#\n{3,}#", "\n\n", $content );

			// Atomic write.
			$tempfile = $htaccess . '.tmp.' . getmypid();
			if ( false === file_put_contents( $tempfile, $content ) ) { // phpcs:ignore
				return false;
			}
			if ( ! rename( $tempfile, $htaccess ) ) { // phpcs:ignore
				@unlink( $tempfile );
				return false;
			}
			return true;
		} finally {
			if ( $lock_fp ) {
				@flock( $lock_fp, LOCK_UN );
				@fclose( $lock_fp );
			}
		}
	}

	/**
	 * Remove Prime Cache rules from .htaccess.
	 *
	 * @return bool
	 */
	public static function remove_rules() {
		$htaccess = self::get_htaccess_path();
		if ( ! $htaccess || ! file_exists( $htaccess ) ) {
			return true;
		}

		// Share the same lock with insert_before_wordpress() so a concurrent
		// add+remove (e.g. settings save racing against deactivation) cannot
		// clobber each other's read-modify-write.
		$lock_path = WP_CONTENT_DIR . '/.prime-cache-htaccess.lock';
		$lock_fp   = @fopen( $lock_path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $lock_fp ) {
			@flock( $lock_fp, LOCK_EX );
		}

		try {
			$content = file_get_contents( $htaccess ); // phpcs:ignore
			if ( false === $content ) {
				return false;
			}

			$begin = '# BEGIN ' . self::MARKER;
			$end   = '# END ' . self::MARKER;

			// Remove block wherever it is (before or after WordPress rules).
			$pattern = '#\s*' . preg_quote( $begin, '#' ) . '.*?' . preg_quote( $end, '#' ) . '\s*#si';
			$content = preg_replace( $pattern, "\n", $content );
			$content = preg_replace( "#\n{3,}#", "\n\n", $content );

			// Atomic write.
			$tempfile = $htaccess . '.tmp.' . getmypid();
			if ( false === file_put_contents( $tempfile, $content ) ) { // phpcs:ignore
				return false;
			}
			if ( ! rename( $tempfile, $htaccess ) ) { // phpcs:ignore
				@unlink( $tempfile );
				return false;
			}
			return true;
		} finally {
			if ( $lock_fp ) {
				@flock( $lock_fp, LOCK_UN );
				@fclose( $lock_fp );
			}
		}
	}

	/**
	 * Check if .htaccess is writable.
	 *
	 * @return bool
	 */
	public static function is_writable() {
		$htaccess = self::get_htaccess_path();
		if ( ! $htaccess ) {
			return false;
		}

		if ( file_exists( $htaccess ) ) {
			return wp_is_writable( $htaccess );
		}

		return wp_is_writable( dirname( $htaccess ) );
	}

	/**
	 * Check if Prime Cache rules are currently present in .htaccess.
	 *
	 * @return bool
	 */
	public static function has_rules() {
		$htaccess = self::get_htaccess_path();
		if ( ! $htaccess || ! file_exists( $htaccess ) ) {
			return false;
		}

		$content = file_get_contents( $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return false;
		}

		return false !== strpos( $content, '# BEGIN ' . self::MARKER );
	}

	/**
	 * Build the complete set of .htaccess rules.
	 *
	 * @param array $settings Plugin settings.
	 * @return array Lines of rules.
	 */
	private static function build_rules( $settings ) {
		$lines = array();

		// MIME types (always first — other rules depend on correct types).
		$lines = array_merge( $lines, self::build_mime_rules() );
		$lines[] = '';

		// Image rewrite (server-level next-gen image serving). Free emits WebP
		// rules; an add-on may add more formats via the
		// prime_cache_image_htaccess_rules filter inside build_image_rewrite_rules().
		// The avif_enabled clause keeps the block reachable so that filter can
		// still fire when an add-on drives an additional format on its own.
		if ( ( ! empty( $settings['webp_enabled'] ) || ! empty( $settings['avif_enabled'] ) )
			&& ! empty( $settings['img_conversion_enabled'] )
			&& 'rewrite' === ( $settings['img_delivery_method'] ?? '' ) ) {
			$lines = array_merge( $lines, self::build_image_rewrite_rules( $settings ) );
			$lines[] = '';
		}

		// Page cache rewrite rules (only when caching is enabled).
		if ( ! empty( $settings['cache_enabled'] ) ) {
			$lines = array_merge( $lines, self::build_rewrite_rules( $settings ) );
		} else {
			$lines[] = '# Page cache rewrite rules disabled (cache is off).';
		}
		$lines[] = '';
		$lines = array_merge( $lines, self::build_deflate_rules() );

		// Brotli compression.
		if ( ! empty( $settings['brotli_compression'] ) ) {
			$lines[] = '';
			$lines = array_merge( $lines, self::build_brotli_rules() );
		}

		// Browser cache: Expires + Cache-Control headers are both gated behind the
		// single "Enable Browser Cache Headers" toggle and use the same per-asset
		// lifetime settings, so the two headers don't disagree on the same response.
		if ( ! empty( $settings['browser_cache'] ) ) {
			$lines[] = '';
			$lines = array_merge( $lines, self::build_expires_rules( $settings ) );
			$lines[] = '';
			$lines = array_merge( $lines, self::build_cache_control_rules( $settings ) );
		}

		$lines[] = '';
		$lines = array_merge( $lines, self::build_etag_rules() );

		// HSTS.
		if ( ! empty( $settings['hsts_enabled'] ) ) {
			$lines[] = '';
			$lines = array_merge( $lines, self::build_hsts_rules( $settings ) );
		}

		// Security headers.
		if ( ! empty( $settings['security_headers'] ) ) {
			$lines[] = '';
			$lines = array_merge( $lines, self::build_security_headers() );
		}

		return $lines;
	}

	/**
	 * Comprehensive MIME type definitions.
	 *
	 * Ensures Apache serves correct Content-Type headers for all modern formats.
	 * Without these, some servers return application/octet-stream for newer types.
	 */
	private static function build_mime_rules() {
		return array(
			'# MIME types',
			'<IfModule mod_mime.c>',
			'    # Images',
			'    AddType image/webp                            .webp',
			'    AddType image/avif                            .avif',
			'    AddType image/svg+xml                         .svg .svgz',
			'    AddType image/x-icon                          .ico',
			'',
			'    # Fonts',
			'    AddType application/font-woff                 .woff',
			'    AddType application/font-woff2                .woff2',
			'    AddType font/ttf                              .ttf',
			'    AddType font/otf                              .otf',
			'    AddType application/vnd.ms-fontobject         .eot',
			'',
			'    # JavaScript & JSON',
			'    AddType application/javascript                .js .mjs',
			'    AddType application/json                      .json',
			'    AddType application/manifest+json             .webmanifest',
			'    AddType application/ld+json                   .jsonld',
			'',
			'    # Media',
			'    AddType video/mp4                             .mp4',
			'    AddType video/webm                            .webm',
			'    AddType audio/ogg                             .ogg .oga',
			'    AddType video/ogg                             .ogv',
			'    AddType audio/mp4                             .m4a',
			'    AddType audio/webm                            .weba',
			'',
			'    # Web',
			'    AddType application/xml                       .xml',
			'    AddType application/rss+xml                   .rss',
			'    AddType application/atom+xml                  .atom',
			'    AddType text/cache-manifest                   .appcache',
			'    AddType text/calendar                         .ics',
			'    AddType text/vcard                            .vcf .vcard',
			'    AddType text/markdown                         .md .markdown',
			'',
			'    # Archives',
			'    AddType application/wasm                      .wasm',
			'    AddType application/zip                       .zip',
			'    AddType application/gzip                      .gz',
			'',
			'    # Encoding',
			'    AddEncoding gzip                              .gz',
			'    AddEncoding gzip                              .svgz',
			'',
			'    # UTF-8 charset',
			'    AddCharset UTF-8 .atom .css .js .json .jsonld .rss .xml .webmanifest .mjs .markdown .md .ics .vcf .vcard',
			'</IfModule>',
		);
	}

	/**
	 * Rewrite rules to serve WebP images when the browser supports them.
	 *
	 * The prime_cache_image_htaccess_rules filter lets an add-on prepend
	 * higher-priority rewrite rules (e.g. AVIF) before Free's WebP rules.
	 */
	private static function build_image_rewrite_rules( $settings ) {
		$r = array();
		$r[] = '# WebP image serving';
		$r[] = '# Only rewrites when the converted file actually exists on disk.';
		$r[] = '# Falls through to serve the original image if no converted version is found.';
		$r[] = '<IfModule mod_rewrite.c>';
		$r[] = '    RewriteEngine On';

		// Collect the per-format image rewrite rule lines so they can be filtered
		// before emission. Free contributes the WebP rules; an add-on may prepend
		// additional-format rules (e.g. AVIF) so they win negotiation.
		$rules = array();

		// WebP: serve .webp if browser accepts and file exists.
		if ( ! empty( $settings['webp_enabled'] ) ) {
			$rules[] = '';
			$rules[] = '    # WebP';
			$rules[] = '    RewriteCond %{HTTP_ACCEPT} image/webp';
			$rules[] = '    RewriteCond %{REQUEST_URI} \.(jpe?g|png)$ [NC]';
			$rules[] = '    RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.webp -f';
			$rules[] = '    RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.webp [T=image/webp,E=REQUEST_image,L]';
		}

		$rules = apply_filters( 'prime_cache_image_htaccess_rules', $rules, $settings );

		foreach ( (array) $rules as $line ) {
			$r[] = $line;
		}

		$r[] = '</IfModule>';
		$r[] = '';

		// Vary header for content negotiation.
		$r[] = '<IfModule mod_headers.c>';
		$r[] = '    Header append Vary Accept env=REQUEST_image';
		$r[] = '</IfModule>';

		return $r;
	}

	/**
	 * mod_rewrite rules to serve cached HTML files directly from disk.
	 *
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	private static function build_rewrite_rules( $settings ) {
		// When cache_vary_cookies or cache_query_strings are active, Apache cannot
		// reproduce the PHP-side variant filename logic (-vc_xxx, -qs_xxx).
		// Disable .htaccess fast-path to prevent serving wrong content.
		$has_vary_cookies  = ! empty( trim( $settings['cache_vary_cookies'] ?? '' ) );
		$has_cache_qs      = ! empty( trim( $settings['cache_query_strings'] ?? '' ) );

		// Reject patterns containing meta chars the .htaccess sanitizer cannot
		// preserve will diverge from the drop-in's regex. Disable the fast-path
		// in those cases so the drop-in is the single source of truth.
		//
		// `\-` and `\.` are common preg_quote() artifacts from ordinary slugs
		// (`my-account`, `wp-cron.php`) — both characters survive the .htaccess
		// allowlist verbatim, and (for `.`, the only true regex difference) the
		// resulting Apache regex matches the same URLs in practice. Strip those
		// before checking so common WP slugs don't silently disable fast-path.
		// Beyond `*` and `\\`, any quantifier or grouping construct (`+`, `?`,
		// `(`, `)`, `{`, `}`, `|`, `[`, `]`, `^`, `$`) means Apache mod_rewrite
		// could match a different URL set than PHP preg_match — drop the
		// fast-path for those too.
		$reject_fields   = array( 'cache_reject_uri', 'cache_reject_cookies', 'cache_reject_ua', 'cache_reject_referrer' );
		$has_unsafe_meta = false;
		foreach ( $reject_fields as $f ) {
			$v = (string) ( $settings[ $f ] ?? '' );
			if ( '' === $v ) {
				continue;
			}
			$normalized = str_replace( array( '\\-', '\\.' ), array( '-', '.' ), $v );
			if ( false !== strpbrk( $normalized, '*\\+?(){}|[]^$' ) ) {
				$has_unsafe_meta = true;
				break;
			}
		}

		if ( $has_vary_cookies || $has_cache_qs || $has_unsafe_meta ) {
			$reasons = array();
			if ( $has_vary_cookies ) {
				$reasons[] = 'cache_vary_cookies';
			}
			if ( $has_cache_qs ) {
				$reasons[] = 'cache_query_strings';
			}
			if ( $has_unsafe_meta ) {
				$reasons[] = 'wildcard or escape in reject pattern';
			}
			return array(
				'# .htaccess fast-path disabled: ' . implode( ' and ', $reasons ) . ' active.',
				'# Drop-in handles these to ensure consistent variant selection / pattern matching.',
			);
		}

		$cache_path   = self::get_relative_cache_path();
		$use_gzip     = ! empty( $settings['gzip_compression'] );
		$sep_mobile   = ! empty( $settings['cache_mobile_separate'] );
		// Sanitize patterns for safe .htaccess embedding.
		// Tight allowlist: pipe-separated literals with . ^ $ as only meta-chars.
		// No quantifiers (* + ? ), no groups ( ), no backslash escapes.
		$sanitize_htaccess = function( $v ) {
			$v = trim( $v ?? '' );
			if ( '' === $v ) return '';
			$v = preg_replace( '#[\x00\r\n]#', '', $v );
			// Strip anything not in the safe set.
			$v = preg_replace( '#[^a-zA-Z0-9.|^$_\-/]#', '', $v );
			// Reject empty alternation branches (||, leading |, trailing |).
			if ( '' === $v || preg_match( '#\|\||^\||\|$|^$#', $v ) ) return '';
			if ( false === @preg_match( '#' . $v . '#', '' ) ) return '';
			return $v;
		};
		$reject_uri      = $sanitize_htaccess( $settings['cache_reject_uri'] ?? '' );
		$reject_ua       = $sanitize_htaccess( $settings['cache_reject_ua'] ?? '' );
		$reject_cookies  = $sanitize_htaccess( $settings['cache_reject_cookies'] ?? '' );
		$reject_referrer = $sanitize_htaccess( $settings['cache_reject_referrer'] ?? '' );

		$r = array();

		// Register .html.gz MIME type so Apache sends correct headers.
		if ( $use_gzip ) {
			$r[] = '<IfModule mod_mime.c>';
			$r[] = '    AddType text/html .html.gz';
			$r[] = '    AddEncoding gzip .gz';
			$r[] = '</IfModule>';
			$r[] = '<IfModule mod_setenvif.c>';
			$r[] = '    SetEnvIfNoCase Request_URI \\.html\\.gz$ no-gzip';
			$r[] = '</IfModule>';
			$r[] = '';
		}

		$r[] = '<IfModule mod_rewrite.c>';
		$r[] = '    RewriteEngine On';
		$r[] = '    RewriteBase /';
		$r[] = '';

		// SSL detection — must agree with the drop-in's _pc_is_ssl logic, otherwise
		// .htaccess and PHP would pick different cache filenames for the same request.
		$site_scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		$mixed       = ! empty( $settings['cache_mixed_scheme'] );
		if ( ! $mixed && 'https' === $site_scheme ) {
			// Single-scheme HTTPS site. Match the drop-in scheme detection:
			// emit -https only when the request shows an actual HTTPS signal;
			// otherwise mark PC_SKIP so the fast-path is bypassed and WP can
			// issue its http→https redirect.
			//
			// Proxies that strip both `HTTPS` and `X-Forwarded-Proto` look
			// identical to direct http at this layer. Operators must opt in:
			//   - PRIME_CACHE_TRUST_X_FORWARDED_PROTO + proxy sending XFP
			//   - PRIME_CACHE_PROXY_NO_XFP for "trust site_scheme blindly"
			//   - cache_mixed_scheme=true for per-request scheme-aware cache
			$trust_xfp    = defined( 'PRIME_CACHE_TRUST_X_FORWARDED_PROTO' ) && PRIME_CACHE_TRUST_X_FORWARDED_PROTO;
			$proxy_no_xfp = defined( 'PRIME_CACHE_PROXY_NO_XFP' ) && PRIME_CACHE_PROXY_NO_XFP;
			$r[] = '    # SSL detection (single-scheme HTTPS site)';
			if ( $proxy_no_xfp ) {
				// Back-compat: trust site_scheme regardless of request signals.
				$r[] = '    RewriteRule .* - [E=PC_SSL:-https]';
			} else {
				// Default: only mark -https when the request actually signals https.
				if ( $trust_xfp ) {
					$r[] = '    RewriteCond %{HTTPS} on [OR]';
					$r[] = '    RewriteCond %{SERVER_PORT} ^443$ [OR]';
					$r[] = '    RewriteCond %{HTTP:X-Forwarded-Proto} https';
				} else {
					$r[] = '    RewriteCond %{HTTPS} on [OR]';
					$r[] = '    RewriteCond %{SERVER_PORT} ^443$';
				}
				$r[] = '    RewriteRule .* - [E=PC_SSL:-https]';
				// No HTTPS signal → skip the fast-path (let PHP/WP redirect).
				$r[] = '    RewriteCond %{HTTPS} !on';
				$r[] = '    RewriteCond %{SERVER_PORT} !^443$';
				if ( $trust_xfp ) {
					$r[] = '    RewriteCond %{HTTP:X-Forwarded-Proto} !https';
				}
				$r[] = '    RewriteRule .* - [E=PC_SKIP:1]';
			}
		} elseif ( ! $mixed && 'http' === $site_scheme ) {
			// Single-scheme HTTP site: never set the -https variant.
			$r[] = '    # SSL detection (single-scheme HTTP site — no -https variant)';
		} else {
			// Mixed-scheme site or unknown scheme: fall back to header detection.
			// X-Forwarded-Proto is gated by the same constant the drop-in uses, so
			// .htaccess and PHP agree on which requests count as HTTPS. The constant
			// is read at rule-generation time (settings save) and baked in.
			$trust_xfp = defined( 'PRIME_CACHE_TRUST_X_FORWARDED_PROTO' ) && PRIME_CACHE_TRUST_X_FORWARDED_PROTO;
			$r[] = '    # SSL detection (mixed-scheme fallback)';
			if ( $trust_xfp ) {
				$r[] = '    RewriteCond %{HTTPS} on [OR]';
				$r[] = '    RewriteCond %{SERVER_PORT} ^443$ [OR]';
				$r[] = '    RewriteCond %{HTTP:X-Forwarded-Proto} https';
			} else {
				$r[] = '    RewriteCond %{HTTPS} on [OR]';
				$r[] = '    RewriteCond %{SERVER_PORT} ^443$';
			}
			$r[] = '    RewriteRule .* - [E=PC_SSL:-https]';
		}
		$r[] = '';

		// Detect mobile UA → set environment variable for filename variant.
		if ( $sep_mobile ) {
			$r[] = '    # Mobile detection';
			$r[] = '    RewriteCond %{HTTP_USER_AGENT} (Mobile|Android|Silk/|Kindle|BlackBerry|Opera\sMini|Opera\sMobi|webOS) [NC]';
			$r[] = '    RewriteRule .* - [E=PC_MOBILE:-mobile]';
			$r[] = '';
		}

		// Detect gzip support.
		if ( $use_gzip ) {
			$r[] = '    # Gzip support';
			$r[] = '    RewriteCond %{HTTP:Accept-Encoding} gzip';
			$r[] = '    RewriteRule .* - [E=PC_GZ:.gz]';
			$r[] = '';
		}

		// Conditions: only GET, no query string.
		$r[] = '    # Serve cached file only for GET without query string';
		$r[] = '    RewriteCond %{REQUEST_METHOD} GET';
		if ( $has_cache_qs ) {
			$r[] = '    # Note: query string variants are handled by the drop-in, not .htaccess';
		}
		$r[] = '    RewriteCond %{QUERY_STRING} ^$';

		// Skip authenticated requests (matches drop-in: never cache responses to
		// Authorization-bearing requests — Bearer tokens, Basic, Digest).
		$r[] = '    RewriteCond %{HTTP:Authorization} ^$';
		$r[] = '    RewriteCond %{ENV:REDIRECT_HTTP_AUTHORIZATION} ^$';

		// Mobile bypass when cache_mobile=false: the drop-in already returns early
		// for mobile UAs in this case, but without this rule .htaccess would still
		// serve the desktop-generated cache file to mobile visitors directly.
		if ( empty( $settings['cache_mobile'] ) ) {
			$r[] = '    # Mobile cache disabled — bypass fast-path for mobile UAs (drop-in handles them)';
			$r[] = '    RewriteCond %{HTTP_USER_AGENT} !(Mobile|Android|Silk/|Kindle|BlackBerry|Opera\sMini|Opera\sMobi|webOS) [NC]';
		}

		// Only match ASCII-safe URL paths (no percent-encoding, no special chars).
		// URLs with encoded characters use different cache directory names (underscore-hex)
		// which Apache rewrite cannot reproduce, so they fall through to the drop-in.
		$r[] = '    RewriteCond %{REQUEST_URI} ^[a-zA-Z0-9/_\-\.]+$';

		// Reject path traversal. The pattern above permits "." and "/", so a literal
		// ".." could otherwise be concatenated into the served file path and resolve
		// a file outside the cache directory. Apache normally collapses "..", but
		// config/encoding edge cases must not be relied on, so refuse it explicitly
		// (such requests fall through to the drop-in, which hex-encodes path segments).
		$r[] = '    RewriteCond %{REQUEST_URI} !\.\.';

		// Mirror the drop-in's admin/login/cron/xmlrpc bypass. The drop-in already
		// returns early for these so no cache file should exist, but defense-in-
		// depth: refuse to serve any stale or manually-placed file under those
		// paths via mod_rewrite. Boundary-anchored to avoid false positives on
		// /category/wp-admin-tutorials/ or similar.
		$r[] = '    RewriteCond %{REQUEST_URI} !(?:^|/)wp-admin(?:/|$) [NC]';
		$r[] = '    RewriteCond %{REQUEST_URI} !(?:^|/)wp-login\.php(?:$|\?) [NC]';
		$r[] = '    RewriteCond %{REQUEST_URI} !(?:^|/)wp-cron\.php(?:$|\?) [NC]';
		$r[] = '    RewriteCond %{REQUEST_URI} !(?:^|/)xmlrpc\.php(?:$|\?) [NC]';

		// Exclude logged-in users (only when cache_logged_in is off — when it's on,
		// the drop-in serves a single shared cache to logged-in and anonymous alike,
		// so the fast-path should do the same).
		if ( empty( $settings['cache_logged_in'] ) ) {
			$r[] = '    RewriteCond %{HTTP:Cookie} !wordpress_logged_in_ [NC]';
		}
		// comment_author_ and wp-postpass_ always bypass — they alter the rendered
		// page (pre-filled comment fields / unlocked post body) regardless of
		// cache_logged_in's setting.
		$r[] = '    RewriteCond %{HTTP:Cookie} !comment_author_ [NC]';
		$r[] = '    RewriteCond %{HTTP:Cookie} !wp-postpass_ [NC]';

		// Exclude WooCommerce session cookies.
		$r[] = '    RewriteCond %{HTTP:Cookie} !woocommerce_cart_hash [NC]';
		$r[] = '    RewriteCond %{HTTP:Cookie} !wp_woocommerce_session_ [NC]';
		$r[] = '    RewriteCond %{HTTP:Cookie} !woocommerce_items_in_cart [NC]';

		// Exclude WooCommerce URIs (cart / checkout / my-account / wc-api). The drop-in
		// always returns early for these; without the same rule here, a stale cache
		// file could be served by Apache directly. Boundary-matched to avoid hitting
		// unrelated slugs like /cartoon/ or /my-accounting/. (wc-ajax / add-to-cart
		// are query-string params and are already excluded by the QUERY_STRING ^$
		// condition above, so no extra rule is needed for them.)
		$r[] = '    RewriteCond %{REQUEST_URI} !(?:^|/)(?:cart|checkout|my-account)(?:/|$) [NC]';
		$r[] = '    RewriteCond %{REQUEST_URI} !(?:^|/)wc-api(?:/|$) [NC]';

		// Exclude rejected cookies (user-configured).
		if ( $reject_cookies ) {
			$r[] = '    RewriteCond %{HTTP:Cookie} !(' . $reject_cookies . ') [NC]';
		}

		// Exclude rejected URIs (user-configured).
		if ( $reject_uri ) {
			$r[] = '    RewriteCond %{REQUEST_URI} !(' . $reject_uri . ') [NC]';
		}

		// Exclude rejected user agents (user-configured).
		if ( $reject_ua ) {
			$r[] = '    RewriteCond %{HTTP_USER_AGENT} !(' . $reject_ua . ') [NC]';
		}

		// Exclude rejected referrers (user-configured).
		if ( $reject_referrer ) {
			$r[] = '    RewriteCond %{HTTP_REFERER} !(' . $reject_referrer . ') [NC]';
		}

		// Mobile suffix (empty string if not separating).
		$mobile_env = $sep_mobile ? '%{ENV:PC_MOBILE}' : '';

		// Cache directories use lowercase host names. Apache .htaccess cannot
		// lowercase variables, so only lowercase Host headers use the fast-path.
		// Uppercase hosts (rare in production) fall through to the PHP drop-in
		// which normalizes correctly — this is by design, not a bug.
		//
		// Enforce the same allowlist the drop-in fail-closes against. Without
		// this gate, an attacker-supplied Host header that happens to match an
		// orphaned cache directory (left over from a domain change, manual file
		// drop, or a previous install on shared wp-content) would be served by
		// Apache directly without going through PHP validation.
		// Build raw → normalized host pairs. Apache compares HTTP_HOST in raw
		// form (`[2001:db8::1]` for IPv6, Punycode for IDN), but cache files
		// live under the normalized directory name (`_5b2001_3a...` etc.). We
		// emit one match-and-set rule per host so the regex matches the raw
		// header while PC_HOST gets the literal normalized form for the path
		// resolution below. For Unicode IDN hosts, browsers send Punycode in
		// the Host header even if `home_url()` returns Unicode; emit both
		// surface forms pointing at the same normalized PC_HOST value.
		require_once PRIME_CACHE_PATH . 'includes/cache-key-functions.php';
		// Collect built-in hosts, then ask the same filter the drop-in's
		// allowed-hosts list uses so additional aliases get a fast-path
		// rule too. Filter values may arrive in raw form; normalize each
		// before consuming.
		$builtin_hosts = array();
		foreach ( array( home_url(), site_url() ) as $u ) {
			$h = wp_parse_url( $u, PHP_URL_HOST );
			if ( $h ) {
				$builtin_hosts[] = $h;
			}
		}
		$filtered_hosts = apply_filters( 'prime_cache_allowed_hosts', $builtin_hosts );
		if ( ! is_array( $filtered_hosts ) ) {
			$filtered_hosts = $builtin_hosts;
		}
		$host_pairs = array();
		foreach ( $filtered_hosts as $raw ) {
			if ( ! is_string( $raw ) || '' === $raw ) {
				continue;
			}
			$normalized = _prime_cache_normalize_host( $raw );
			if ( '' === $normalized ) {
				continue;
			}
			// IPv6: wp_parse_url strips the surrounding `[ ]`, but Apache's
			// %{HTTP_HOST} keeps them for bracketed-literal IPv6 requests.
			// Re-add them when the raw host contains a colon so the
			// RewriteCond regex actually matches inbound traffic.
			$apache_host = ( false !== strpos( $raw, ':' ) ) ? '[' . $raw . ']' : $raw;
			$key         = $apache_host . '|' . $normalized;
			$host_pairs[ $key ] = array( 'raw' => $apache_host, 'normalized' => $normalized );

			// IDN: also emit the Punycode form so a Unicode home_url()
			// matches a Punycode Host header (and vice versa).
			if ( function_exists( 'idn_to_ascii' ) && preg_match( '#[^\x00-\x7f]#', $raw ) ) {
				$variant = defined( 'INTL_IDNA_VARIANT_UTS46' ) ? INTL_IDNA_VARIANT_UTS46 : 0;
				$puny    = @idn_to_ascii( $raw, IDNA_DEFAULT, $variant );
				if ( is_string( $puny ) && '' !== $puny && $puny !== $raw ) {
					$puny_key = $puny . '|' . $normalized;
					$host_pairs[ $puny_key ] = array( 'raw' => $puny, 'normalized' => $normalized );
				}
			}
		}

		$r[] = '';
		$emitted = 0;
		foreach ( $host_pairs as $pair ) {
			$raw_host = strtolower( $pair['raw'] );
			// Restrict the normalized form to characters that are safe both
			// inside an Apache regex (as a literal env-var value) and as a
			// directory name. Normalized hosts already conform, but treat
			// anything outside [a-z0-9._\-] as a belt-and-braces guard
			// (e.g. against unexpected unicode survivors).
			if ( ! preg_match( '#^[a-z0-9._\-]+$#', $pair['normalized'] ) ) {
				continue;
			}
			// Validate the raw form too — IPv6 brackets and colons are
			// expected here, IDN Punycode adds nothing surprising. Reject
			// anything outside that printable set.
			if ( ! preg_match( '#^[a-z0-9._:\-\[\]]+$#i', $raw_host ) ) {
				continue;
			}
			// preg_quote with `#` covers the regex characters that appear in
			// IPv6 (`:`, `[`, `]`) and IDN Punycode hosts (`-`, `.`).
			$raw_pattern = preg_quote( $raw_host, '#' );
			if ( 0 === $emitted ) {
				$r[] = '    # Extract host: each known site host gets its normalized cache-dir name';
			}
			$r[] = '    RewriteCond %{HTTP_HOST} ^' . $raw_pattern . '(:[0-9]+)?$ [NC]';
			$r[] = '    RewriteRule .* - [E=PC_HOST:' . $pair['normalized'] . ']';
			$emitted++;
		}
		// No fallback: when no allowed hosts could be enumerated, fail closed —
		// PC_HOST stays unset and the file-existence check below fails. Better
		// to drop the fast-path entirely than serve a stale cache file under
		// an attacker-supplied Host. The drop-in (which validates against
		// $prime_cache_allowed_hosts) handles the request safely either way.
		$r[] = '';
		$r[] = '    # Check cached file exists and serve it';
		$r[] = '    RewriteCond %{ENV:PC_HOST} !^$';
		$r[] = '    RewriteCond %{ENV:PC_SKIP} !^1$';
		$r[] = '    RewriteCond "%{DOCUMENT_ROOT}/' . $cache_path . '%{ENV:PC_HOST}%{REQUEST_URI}index%{ENV:PC_SSL}' . $mobile_env . '.html%{ENV:PC_GZ}" -f';
		$r[] = '    RewriteRule .* "/' . $cache_path . '%{ENV:PC_HOST}%{REQUEST_URI}index%{ENV:PC_SSL}' . $mobile_env . '.html%{ENV:PC_GZ}" [L]';

		$r[] = '</IfModule>';

		return $r;
	}

	/**
	 * mod_deflate rules for gzip compression of dynamic content.
	 *
	 * @return array
	 */
	private static function build_deflate_rules() {
		return array(
			'# Gzip compression',
			'<IfModule mod_deflate.c>',
			'    <IfModule mod_filter.c>',
			'        AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml',
			'        AddOutputFilterByType DEFLATE text/javascript application/javascript application/json',
			'        AddOutputFilterByType DEFLATE application/xml application/xhtml+xml application/rss+xml',
			'        AddOutputFilterByType DEFLATE application/atom+xml application/vnd.ms-fontobject',
			'        AddOutputFilterByType DEFLATE font/opentype font/ttf font/otf',
			'        AddOutputFilterByType DEFLATE image/svg+xml image/x-icon',
			'    </IfModule>',
			'    <IfModule mod_headers.c>',
			'        Header append Vary Accept-Encoding',
			'    </IfModule>',
			'</IfModule>',
		);
	}

	/**
	 * mod_expires rules for browser caching of static assets.
	 *
	 * @return array
	 */
	private static function build_expires_rules( $settings = array() ) {
		// Lifetime values come from the same UI fields that drive Cache-Control
		// max-age, so the two headers agree on every response.
		$css_js = max( 0, (int) ( $settings['browser_cache_css_js'] ?? 31536000 ) );
		$images = max( 0, (int) ( $settings['browser_cache_images'] ?? 15552000 ) );
		$fonts  = max( 0, (int) ( $settings['browser_cache_fonts']  ?? 15552000 ) );
		$html   = max( 0, (int) ( $settings['browser_cache_html']   ?? 0 ) );
		$css_js_e = '"access plus ' . $css_js . ' seconds"';
		$images_e = '"access plus ' . $images . ' seconds"';
		$fonts_e  = '"access plus ' . $fonts  . ' seconds"';
		$html_e   = '"access plus ' . $html   . ' seconds"';

		return array(
			'# Browser caching',
			'<IfModule mod_expires.c>',
			'    ExpiresActive On',
			'    ExpiresByType text/html                     ' . $html_e,
			'    ExpiresByType text/xml                      "access plus 0 seconds"',
			'    ExpiresByType application/xml               "access plus 0 seconds"',
			'    ExpiresByType application/json              "access plus 0 seconds"',
			'    ExpiresByType text/cache-manifest           "access plus 0 seconds"',
			'    ExpiresByType application/rss+xml           "access plus 1 hour"',
			'    ExpiresByType application/atom+xml          "access plus 1 hour"',
			'    ExpiresByType image/x-icon                  ' . $images_e,
			'    ExpiresByType image/gif                     ' . $images_e,
			'    ExpiresByType image/jpeg                    ' . $images_e,
			'    ExpiresByType image/png                     ' . $images_e,
			'    ExpiresByType image/webp                    ' . $images_e,
			'    ExpiresByType image/svg+xml                 ' . $images_e,
			'    ExpiresByType video/mp4                     ' . $images_e,
			'    ExpiresByType video/webm                    ' . $images_e,
			'    ExpiresByType audio/ogg                     ' . $images_e,
			'    ExpiresByType font/ttf                      ' . $fonts_e,
			'    ExpiresByType font/otf                      ' . $fonts_e,
			'    ExpiresByType font/woff                     ' . $fonts_e,
			'    ExpiresByType font/woff2                    ' . $fonts_e,
			'    ExpiresByType application/vnd.ms-fontobject ' . $fonts_e,
			'    ExpiresByType text/css                      ' . $css_js_e,
			'    ExpiresByType text/javascript               ' . $css_js_e,
			'    ExpiresByType application/javascript        ' . $css_js_e,
			'</IfModule>',
		);
	}

	/**
	 * ETag removal rules — avoids redundant validation when Expires is set.
	 *
	 * @return array
	 */
	private static function build_etag_rules() {
		return array(
			'# Disable ETag',
			'<IfModule mod_headers.c>',
			'    Header unset ETag',
			'</IfModule>',
			'FileETag None',
		);
	}

	/**
	 * Brotli compression rules (mod_brotli on Apache 2.4+).
	 *
	 * @return array
	 */
	private static function build_brotli_rules() {
		return array(
			'# Brotli compression',
			'<IfModule mod_brotli.c>',
			'    AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/css text/xml',
			'    AddOutputFilterByType BROTLI_COMPRESS text/javascript application/javascript application/json',
			'    AddOutputFilterByType BROTLI_COMPRESS application/xml application/xhtml+xml application/rss+xml',
			'    AddOutputFilterByType BROTLI_COMPRESS application/atom+xml application/vnd.ms-fontobject',
			'    AddOutputFilterByType BROTLI_COMPRESS font/opentype font/ttf font/otf font/woff font/woff2',
			'    AddOutputFilterByType BROTLI_COMPRESS image/svg+xml image/x-icon',
			'    AddOutputFilterByType BROTLI_COMPRESS application/manifest+json application/ld+json',
			'</IfModule>',
		);
	}

	/**
	 * Cache-Control header rules for fine-grained browser cache control.
	 *
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	private static function build_cache_control_rules( $settings ) {
		$css_js  = max( 0, (int) ( $settings['browser_cache_css_js'] ?? 31536000 ) );
		$images  = max( 0, (int) ( $settings['browser_cache_images'] ?? 15552000 ) );
		$fonts   = max( 0, (int) ( $settings['browser_cache_fonts'] ?? 15552000 ) );
		$html    = max( 0, (int) ( $settings['browser_cache_html'] ?? 0 ) );
		$immutable = ! empty( $settings['cache_control_immutable'] ) ? ', immutable' : '';

		$r = array();
		$r[] = '# Cache-Control headers';
		$r[] = '<IfModule mod_headers.c>';

		// HTML.
		if ( $html > 0 ) {
			$r[] = '    <FilesMatch "\\.(html|htm)$">';
			$r[] = '        Header set Cache-Control "public, max-age=' . $html . '"';
			$r[] = '    </FilesMatch>';
		} else {
			$r[] = '    <FilesMatch "\\.(html|htm)$">';
			$r[] = '        Header set Cache-Control "no-cache, must-revalidate"';
			$r[] = '    </FilesMatch>';
		}

		// CSS & JS.
		$r[] = '    <FilesMatch "\\.(css|js)$">';
		$r[] = '        Header set Cache-Control "public, max-age=' . $css_js . $immutable . '"';
		$r[] = '    </FilesMatch>';

		// Images.
		$r[] = '    <FilesMatch "\\.(gif|jpe?g|png|webp|avif|ico|bmp|tiff?|svg)$">';
		$r[] = '        Header set Cache-Control "public, max-age=' . $images . $immutable . '"';
		$r[] = '    </FilesMatch>';

		// Fonts.
		$r[] = '    <FilesMatch "\\.(woff2?|ttf|otf|eot)$">';
		$r[] = '        Header set Cache-Control "public, max-age=' . $fonts . $immutable . '"';
		$r[] = '    </FilesMatch>';

		// Media.
		$r[] = '    <FilesMatch "\\.(mp4|webm|ogg|mp3|wav|flac)$">';
		$r[] = '        Header set Cache-Control "public, max-age=' . $images . '"';
		$r[] = '    </FilesMatch>';

		// PDF & docs.
		$r[] = '    <FilesMatch "\\.(pdf|doc|docx|xls|xlsx|ppt|pptx|zip)$">';
		$r[] = '        Header set Cache-Control "public, max-age=' . $images . '"';
		$r[] = '    </FilesMatch>';

		$r[] = '</IfModule>';

		return $r;
	}

	/**
	 * HSTS (HTTP Strict Transport Security) header.
	 */
	private static function build_hsts_rules( $settings ) {
		$max_age = max( 0, (int) ( $settings['hsts_max_age'] ?? 31536000 ) );
		$header  = 'max-age=' . $max_age . '; includeSubDomains; preload';

		// On a single-scheme HTTPS site every request is HTTPS by definition, so
		// omit `env=HTTPS`. That guard exists to avoid sending HSTS over HTTP, but
		// behind a TLS-terminating reverse proxy Apache often doesn't see HTTPS=on
		// even when the visitor connection is HTTPS — the env-gated rule would
		// silently drop. Guarding by the canonical site scheme avoids both pitfalls.
		$site_scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		$mixed       = ! empty( $settings['cache_mixed_scheme'] );
		$single_https = ( ! $mixed && 'https' === $site_scheme );

		$rule = $single_https
			? '    Header always set Strict-Transport-Security "' . $header . '"'
			: '    Header always set Strict-Transport-Security "' . $header . '" env=HTTPS';

		return array(
			'# HSTS',
			'<IfModule mod_headers.c>',
			$rule,
			'</IfModule>',
		);
	}

	/**
	 * Security headers (X-Content-Type-Options, X-Frame-Options, etc.).
	 */
	private static function build_security_headers() {
		return array(
			'# Security headers',
			'<IfModule mod_headers.c>',
			'    Header set X-Content-Type-Options "nosniff"',
			'    Header set X-Frame-Options "SAMEORIGIN"',
			'    Header set X-XSS-Protection "1; mode=block"',
			'    Header set Referrer-Policy "strict-origin-when-cross-origin"',
			'    Header set Permissions-Policy "geolocation=(), microphone=(), camera=()"',
			'</IfModule>',
		);
	}

	/**
	 * Get the relative path from document root to the cache directory.
	 *
	 * @return string Path like "wp-content/cache/prime-cache/"
	 */
	private static function get_relative_cache_path() {
		$abs = PRIME_CACHE_CACHE_DIR;
		$doc = realpath( $_SERVER['DOCUMENT_ROOT'] ?? ABSPATH );

		if ( $doc && strpos( $abs, $doc ) === 0 ) {
			return ltrim( substr( $abs, strlen( $doc ) ), '/' );
		}

		// Fallback: derive from WP_CONTENT_DIR.
		$content_dir = basename( WP_CONTENT_DIR );

		return $content_dir . '/cache/prime-cache/';
	}

	/**
	 * Get the path to .htaccess.
	 *
	 * @return string|false
	 */
	private static function get_htaccess_path() {
		$path = ABSPATH . '.htaccess';

		// Only relevant on Apache.
		if ( ! function_exists( 'got_mod_rewrite' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		return $path;
	}
}
