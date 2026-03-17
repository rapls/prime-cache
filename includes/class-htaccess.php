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

		return insert_with_markers( $htaccess, self::MARKER, $rules );
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

		return insert_with_markers( $htaccess, self::MARKER, array() );
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

		// WebP/AVIF rewrite.
		if ( ( ! empty( $settings['webp_enabled'] ) || ! empty( $settings['avif_enabled'] ) ) && 'rewrite' === ( $settings['img_delivery_method'] ?? '' ) ) {
			$lines = array_merge( $lines, self::build_image_rewrite_rules( $settings ) );
			$lines[] = '';
		}

		$lines = array_merge( $lines, self::build_rewrite_rules( $settings ) );
		$lines[] = '';
		$lines = array_merge( $lines, self::build_deflate_rules() );

		// Brotli compression.
		if ( ! empty( $settings['brotli_compression'] ) ) {
			$lines[] = '';
			$lines = array_merge( $lines, self::build_brotli_rules() );
		}

		$lines[] = '';
		$lines = array_merge( $lines, self::build_expires_rules() );

		// Browser cache (Cache-Control headers).
		if ( ! empty( $settings['browser_cache'] ) ) {
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
			'    AddType font/woff                             .woff',
			'    AddType font/woff2                            .woff2',
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
	 * Rewrite rules to serve WebP/AVIF images when browser supports them.
	 */
	private static function build_image_rewrite_rules( $settings ) {
		$r = array();
		$r[] = '# WebP/AVIF image serving';
		$r[] = '<IfModule mod_rewrite.c>';
		$r[] = '    RewriteEngine On';

		// AVIF: serve .avif if browser accepts and file exists.
		if ( ! empty( $settings['avif_enabled'] ) ) {
			$r[] = '';
			$r[] = '    # AVIF';
			$r[] = '    RewriteCond %{HTTP_ACCEPT} image/avif';
			$r[] = '    RewriteCond %{REQUEST_FILENAME} \.(jpe?g|png)$';
			$r[] = '    RewriteCond %{REQUEST_FILENAME}.avif -f';
			$r[] = '    RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.avif [T=image/avif,E=REQUEST_image,L]';
		}

		// WebP: serve .webp if browser accepts and file exists.
		if ( ! empty( $settings['webp_enabled'] ) ) {
			$r[] = '';
			$r[] = '    # WebP';
			$r[] = '    RewriteCond %{HTTP_ACCEPT} image/webp';
			$r[] = '    RewriteCond %{REQUEST_FILENAME} \.(jpe?g|png)$';
			$r[] = '    RewriteCond %{REQUEST_FILENAME}.webp -f';
			$r[] = '    RewriteRule ^(.+)\.(jpe?g|png)$ $1.$2.webp [T=image/webp,E=REQUEST_image,L]';
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

		if ( $has_vary_cookies ) {
			return array(
				'# .htaccess fast-path disabled: cache_vary_cookies is active.',
				'# Cookie-based variants require PHP (drop-in) to determine the correct cache file.',
			);
		}

		$cache_path   = self::get_relative_cache_path();
		$use_gzip     = ! empty( $settings['gzip_compression'] );
		$sep_mobile   = ! empty( $settings['cache_mobile_separate'] );
		// Sanitize regex patterns for safe .htaccess injection (strip newlines and null bytes).
		$sanitize_htaccess = function( $v ) {
			return preg_replace( '#[\r\n\x00]#', '', trim( $v ?? '' ) );
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

		// Detect HTTPS → set environment variable for filename variant.
		$r[] = '    # SSL detection';
		$r[] = '    RewriteCond %{HTTPS} on [OR]';
		$r[] = '    RewriteCond %{SERVER_PORT} ^443$ [OR]';
		$r[] = '    RewriteCond %{HTTP:X-Forwarded-Proto} https';
		$r[] = '    RewriteRule .* - [E=PC_SSL:-https]';
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

		// Only match ASCII-safe URL paths (no percent-encoding, no special chars).
		// URLs with encoded characters use different cache directory names (underscore-hex)
		// which Apache rewrite cannot reproduce, so they fall through to the drop-in.
		$r[] = '    RewriteCond %{REQUEST_URI} ^[a-zA-Z0-9/_\-\.]+$';

		// Exclude logged-in users and comment authors.
		$r[] = '    RewriteCond %{HTTP:Cookie} !wordpress_logged_in_ [NC]';
		$r[] = '    RewriteCond %{HTTP:Cookie} !comment_author_ [NC]';
		$r[] = '    RewriteCond %{HTTP:Cookie} !wp-postpass_ [NC]';

		// Exclude WooCommerce session cookies.
		$r[] = '    RewriteCond %{HTTP:Cookie} !woocommerce_cart_hash [NC]';
		$r[] = '    RewriteCond %{HTTP:Cookie} !wp_woocommerce_session_ [NC]';
		$r[] = '    RewriteCond %{HTTP:Cookie} !woocommerce_items_in_cart [NC]';

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

		// Cache directories are stored with lowercase host. Apache cannot lowercase
		// variables in .htaccess, so we use SERVER_NAME (always lowercase in Apache)
		// with an HTTP_HOST port-strip fallback. Uppercase Host headers will miss
		// the fast-path and fall through to the PHP drop-in which normalizes correctly.
		$r[] = '';
		$r[] = '    # Extract host: strip port from HTTP_HOST, require lowercase';
		$r[] = '    RewriteCond %{HTTP_HOST} ^([a-z0-9.\-]+)(:[0-9]+)?$';
		$r[] = '    RewriteRule .* - [E=PC_HOST:%1]';
		$r[] = '';
		$r[] = '    # Check cached file exists and serve it';
		$r[] = '    RewriteCond %{ENV:PC_HOST} !^$';
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
	private static function build_expires_rules() {
		return array(
			'# Browser caching',
			'<IfModule mod_expires.c>',
			'    ExpiresActive On',
			'    ExpiresByType text/html                     "access plus 0 seconds"',
			'    ExpiresByType text/xml                      "access plus 0 seconds"',
			'    ExpiresByType application/xml               "access plus 0 seconds"',
			'    ExpiresByType application/json              "access plus 0 seconds"',
			'    ExpiresByType text/cache-manifest           "access plus 0 seconds"',
			'    ExpiresByType application/rss+xml           "access plus 1 hour"',
			'    ExpiresByType application/atom+xml          "access plus 1 hour"',
			'    ExpiresByType image/x-icon                  "access plus 1 week"',
			'    ExpiresByType image/gif                     "access plus 6 months"',
			'    ExpiresByType image/jpeg                    "access plus 6 months"',
			'    ExpiresByType image/png                     "access plus 6 months"',
			'    ExpiresByType image/webp                    "access plus 6 months"',
			'    ExpiresByType image/svg+xml                 "access plus 6 months"',
			'    ExpiresByType video/mp4                     "access plus 6 months"',
			'    ExpiresByType video/webm                    "access plus 6 months"',
			'    ExpiresByType audio/ogg                     "access plus 6 months"',
			'    ExpiresByType font/ttf                      "access plus 6 months"',
			'    ExpiresByType font/otf                      "access plus 6 months"',
			'    ExpiresByType font/woff                     "access plus 6 months"',
			'    ExpiresByType font/woff2                    "access plus 6 months"',
			'    ExpiresByType application/vnd.ms-fontobject "access plus 6 months"',
			'    ExpiresByType text/css                      "access plus 1 year"',
			'    ExpiresByType text/javascript               "access plus 1 year"',
			'    ExpiresByType application/javascript        "access plus 1 year"',
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
		return array(
			'# HSTS',
			'<IfModule mod_headers.c>',
			'    Header always set Strict-Transport-Security "max-age=' . $max_age . '; includeSubDomains; preload" env=HTTPS',
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
