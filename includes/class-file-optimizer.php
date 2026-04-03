<?php
/**
 * File Optimizer — HTML/CSS/JS optimization via output buffer processing.
 *
 * Hooks into template_redirect to capture final HTML and applies:
 *  - HTML minification & comment removal
 *  - CSS minification, combining, async loading
 *  - JS minification, combining, defer, delay
 *  - Google Fonts optimization
 *  - Query string removal from static resources
 *  - DNS prefetch injection
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_File_Optimizer {

	/** @var array */
	private $settings;

	/** @var string Cache directory for combined files. */
	private $cache_dir;

	/** @var string Cache URL for combined files. */
	private $cache_url;

	public function __construct() {
		$this->settings  = prime_cache_get_settings();
		$this->cache_dir = WP_CONTENT_DIR . '/cache/prime-cache-fo/';
		$this->cache_url = content_url( '/cache/prime-cache-fo/' );

		// Pro cron handlers are registered by class-file-optimizer-pro.php.

		// Flush rewrite rules on next request after setting toggle (deferred from save).
		if ( get_option( 'prime_cache_flush_rewrite' ) ) {
			delete_option( 'prime_cache_flush_rewrite' );
			add_action( 'init', function() { flush_rewrite_rules( false ); }, 99 );
		}

		// Defer JS via WordPress filter (safe, no ob_start needed).
		if ( ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() ) {
			if ( ! empty( $this->settings['defer_js'] ) ) {
				add_filter( 'script_loader_tag', array( $this, 'filter_defer_script' ), 10, 3 );
			}
			// Delay JS: now processed via HTML pipeline (ob_start) for full coverage.
			// This captures ALL scripts (inline + external + CDN), not just enqueued.
		}

		if ( ! $this->should_optimize_html() ) {
			return;
		}

		// Register with unified HTML pipeline instead of individual ob_start.
		global $prime_cache_html_pipeline;
		if ( $prime_cache_html_pipeline ) {
			$prime_cache_html_pipeline->register( 'file_optimizer', array( $this, 'process_html' ), 60 );
		} else {
			add_action( 'template_redirect', array( $this, 'start_buffer' ), -1 );
		}

		// Debug logging.
		if ( ! empty( $this->settings['debug_log'] ) ) {
			add_action( 'prime_cache_after_purge_all', function() {
				self::debug_log( 'PURGE ALL' );
			} );
			add_action( 'prime_cache_url_purged', function( $url ) {
				self::debug_log( 'PURGE URL: ' . $url );
			} );
		}

		// Rewrite file optimizer: serve combined files via clean URL.
		if ( $this->settings['rewrite_file_optimizer'] ) {
			add_action( 'init', array( $this, 'register_rewrite_rules' ) );
			add_action( 'parse_request', array( $this, 'handle_rewrite_request' ) );
		}
	}

	/**
	 * Get a normalized cache URI aligned with page cache query handling.
	 *
	 * - Strips cache_ignore_qs params (utm_*, fbclid, etc.)
	 * - Keeps cache_query_strings params as part of the key
	 * - Returns false if unknown params exist (page cache wouldn't cache this)
	 *
	 * @return string|false Normalized URI, or false if request has unknown query params.
	 */
	public function get_normalized_cache_uri() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
		$path = strtok( $uri, '?' );

		if ( empty( $_GET ) ) {
			return $path;
		}

		$s          = $this->settings;
		$ignored    = array_filter( array_map( 'trim', explode( ',', $s['cache_ignore_qs'] ?? '' ) ) );
		$cached_qs  = array_filter( array_map( 'trim', explode( ',', $s['cache_query_strings'] ?? '' ) ) );
		$remaining  = array_diff_key( $_GET, array_flip( $ignored ) );

		if ( empty( $remaining ) ) {
			return $path; // All params were ignored — same as no query.
		}

		if ( empty( $cached_qs ) ) {
			return false; // Unknown params exist, no whitelist — page cache skips this.
		}

		$to_cache = array_intersect_key( $remaining, array_flip( $cached_qs ) );
		$unknown  = array_diff_key( $remaining, $to_cache );

		if ( ! empty( $unknown ) ) {
			return false; // Has params not in ignore or cache list — page cache skips.
		}

		if ( empty( $to_cache ) ) {
			return $path;
		}

		ksort( $to_cache );
		return $path . '?qs_' . substr( md5( http_build_query( $to_cache ) ), 0, 8 );
	}

	/**
	 * Whether optimization should run on this request.
	 */
	/**
	 * Whether HTML pipeline (ob_start) should run.
	 */
	private function should_optimize_html() {
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) || defined( 'XMLRPC_REQUEST' ) || defined( 'REST_REQUEST' ) ) {
			return false;
		}

		$s = $this->settings;
		return $s['minify_html'] || $s['remove_html_comments'] || $s['minify_css'] || $s['minify_js']
			|| $s['remove_query_strings'] || $s['delay_js']
			|| ! empty( $s['inline_small_css'] ) || ! empty( $s['async_css_free'] )
			|| ! empty( $s['defer_js'] )
			|| apply_filters( 'prime_cache_should_optimize_html', false );
	}

	public function start_buffer() {
		ob_start( array( $this, 'process_html' ) );
	}

	/**
	 * Main HTML processing pipeline.
	 */
	public function process_html( $html ) {
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		$s = $this->settings;

		// Pro hook: runs before Free optimizations (DNS prefetch, analytics, fonts, UCSS, critical CSS).
		$html = apply_filters( 'prime_cache_before_optimize', $html, $s );

		// Remove query strings from static resources.
		if ( $s['remove_query_strings'] ) {
			$html = $this->strip_query_strings( $html );
		}

		// CSS optimizations (Free: minify, inline small, async non-first).
		$html = $this->process_css( $html );

		// Make Google Fonts CSS non-render-blocking (Free feature).
		// Font CSS is never above-fold critical — text renders with fallback font
		// via font-display: swap, then swaps when font loads.
		$html = $this->async_google_fonts( $html );

		// Pro hook: CSS combine, async, critical CSS.
		$html = apply_filters( 'prime_cache_process_css', $html, $s );

		// JS optimizations (Free: minify only via ob_start pipeline).
		if ( $s['minify_js'] ) {
			$html = $this->process_js( $html );
		}

		// Pro hook: JS combine.
		$html = apply_filters( 'prime_cache_process_js', $html, $s );

		// Wrap inline jQuery scripts with DOMContentLoaded so jQuery can be deferred.
		// Without this, deferred jQuery would break inline $(document).ready() calls
		// that execute immediately after the script tag.
		if ( $s['defer_js'] ) {
			$html = $this->wrap_inline_jquery( $html );
		}

		// Delay JS: transform ALL script tags via HTML pipeline.
		// Mobile only — desktop suffers CLS regression when scripts are delayed
		// because layout-dependent JS (sliders, menus) runs late.
		if ( $s['delay_js'] && wp_is_mobile() ) {
			$html = $this->delay_all_scripts( $html );
		}

		// HTML minification (last step).
		if ( $s['remove_html_comments'] ) {
			$html = $this->remove_html_comments( $html );
		}
		if ( $s['minify_html'] ) {
			$html = $this->minify_html( $html );
		}

		return $html;
	}

	// ── HTML ─────────────────────────────────────────────────

	private function minify_html( $html ) {
		if ( $this->settings['minify_html_dom'] ) {
			return $this->minify_html_dom( $html );
		}
		return $this->minify_html_regex( $html );
	}

	/**
	 * Regex-based HTML minification (fast, safe).
	 */
	private function minify_html_regex( $html ) {
		$preserved = array();
		$html = preg_replace_callback( '#<(pre|script|style|textarea)[^>]*>.*?</\\1>#si', function( $m ) use ( &$preserved ) {
			$key = '<!--PC_PRESERVE_' . count( $preserved ) . '-->';
			$preserved[ $key ] = $m[0];
			return $key;
		}, $html );

		$html = preg_replace( '#>\s+<#', '> <', $html );
		$html = preg_replace( '#\s{2,}#', ' ', $html );
		// Only remove whitespace around = inside HTML tags (not in text content).
		$html = preg_replace_callback( '#<[^>]+>#', function( $m ) {
			return preg_replace( '#\s*=\s*#', '=', $m[0] );
		}, $html );

		$html = str_replace( array_keys( $preserved ), array_values( $preserved ), $html );
		return $html;
	}

	/**
	 * DOM-based HTML minification (deeper optimization).
	 *
	 * Parses HTML via DOMDocument for more aggressive whitespace removal
	 * between block-level elements while preserving inline formatting.
	 */
	private function minify_html_dom( $html ) {
		// Preserve pre/script/style/textarea first.
		$preserved = array();
		$html = preg_replace_callback( '#<(pre|script|style|textarea)[^>]*>.*?</\\1>#si', function( $m ) use ( &$preserved ) {
			$key = '<!--PC_DOM_' . count( $preserved ) . '-->';
			$preserved[ $key ] = $m[0];
			return $key;
		}, $html );

		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR );
		libxml_clear_errors();

		if ( ! $loaded ) {
			// Fallback to regex if DOM parsing fails.
			$html = str_replace( array_keys( $preserved ), array_values( $preserved ), $html );
			return $this->minify_html_regex( $html );
		}

		// Walk text nodes and collapse whitespace.
		$this->dom_collapse_whitespace( $doc );

		$result = $doc->saveHTML();
		// Remove the XML encoding declaration.
		$result = preg_replace( '#^<\?xml encoding="UTF-8"\>#', '', $result );

		// Additional regex pass for remaining whitespace between tags.
		$result = preg_replace( '#>\s+<#', '> <', $result );
		$result = preg_replace( '#\s{2,}#', ' ', $result );

		// Remove redundant boolean attribute values added by DOMDocument.
		$result = preg_replace( '#\s(defer|async|disabled|checked|selected|readonly|required|autofocus|autoplay|controls|loop|muted|hidden|novalidate)="(\\1|)"#i', ' $1', $result );

		// Restore preserved blocks.
		$result = str_replace( array_keys( $preserved ), array_values( $preserved ), $result );
		return $result;
	}

	/**
	 * Recursively collapse whitespace in DOM text nodes.
	 */
	private function dom_collapse_whitespace( $node ) {
		if ( ! $node->hasChildNodes() ) {
			return;
		}

		$block_tags = array( 'div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th', 'section', 'article', 'aside', 'nav', 'header', 'footer', 'main', 'figure', 'figcaption', 'blockquote', 'form', 'fieldset', 'details', 'summary', 'dl', 'dt', 'dd', 'hr', 'br' );

		foreach ( $node->childNodes as $child ) {
			if ( $child->nodeType === XML_TEXT_NODE ) {
				$parent_tag = strtolower( $child->parentNode->nodeName ?? '' );
				// Don't touch text inside inline elements that need precise spacing.
				if ( ! in_array( $parent_tag, array( 'pre', 'code', 'script', 'style', 'textarea' ), true ) ) {
					$text = preg_replace( '#\s+#', ' ', $child->nodeValue );
					$child->nodeValue = $text;
				}
			} elseif ( $child->nodeType === XML_ELEMENT_NODE ) {
				$this->dom_collapse_whitespace( $child );
			}
		}
	}

	private function remove_html_comments( $html ) {
		// Remove HTML comments but keep IE conditionals and preserved markers.
		return preg_replace( '#<!--(?!\[if\s|!?\[endif|PC_PRESERVE_).*?-->#s', '', $html );
	}

	// ── CSS ──────────────────────────────────────────────────

	private function process_css( $html ) {
		$s = $this->settings;
		$excludes   = $this->parse_list( $s['exclude_css'] );
		$is_pro     = prime_cache_is_pro();
		$do_minify  = ! empty( $s['minify_css'] );
		// Free-only async: skip when Pro handles CSS (async_css, combine_css, critical_css).
		$do_inline  = ! $is_pro && ! empty( $s['inline_small_css'] );
		$do_async   = ! $is_pro && ! empty( $s['async_css_free'] );
		$threshold  = (int) ( $s['inline_css_threshold'] ?? 8192 );
		$async_all  = false;

		// Find all <link rel="stylesheet"> tags.
		if ( ! preg_match_all( '#<link\s[^>]*rel=["\']stylesheet["\'][^>]*/?\s*>#i', $html, $matches, PREG_SET_ORDER ) ) {
			return $html;
		}

		$css_index  = 0;
		$has_asyncd = false;
		foreach ( $matches as $match ) {
			$tag = $match[0];
			if ( ! preg_match( '#href=["\']([^"\']+)["\']#i', $tag, $href_match ) ) {
				continue;
			}
			$href = $href_match[1];
			if ( $this->matches_patterns( $href, $excludes ) ) {
				continue;
			}

			$is_local = $this->is_local_url( $href );
			$css_index++;

			// Inline small CSS files (eliminate HTTP request).
			if ( $do_inline && $is_local ) {
				$path = $this->url_to_path( $href );
				if ( $path && is_readable( $path ) && filesize( $path ) <= $threshold ) {
					$css = file_get_contents( $path ); // phpcs:ignore
					if ( false !== $css ) {
						$css = $this->rebase_css_urls( $css, $path );
						if ( $do_minify ) {
							$css = $this->minify_css_content( $css );
						}
						$html = str_replace( $tag, '<style>' . $css . '</style>', $html );
						continue;
					}
				}
			}

			// Minify individual CSS files (skip already minified .min.css).
			if ( $do_minify && $is_local && false === strpos( $href, '.min.css' ) ) {
				$minified_url = $this->minify_css_file( $href );
				if ( $minified_url ) {
					$new_tag = str_replace( $href, $minified_url, $tag );
					$html = str_replace( $tag, $new_tag, $html );
					$tag  = $new_tag;
				}
			}

			// Async CSS: on mobile async ALL, on desktop async non-first only.
			$should_async = $do_async && ( $async_all || $css_index > 1 );
			if ( $should_async ) {
				// Skip if already has non-"all" media (already non-blocking).
				if ( preg_match( '#media=["\']([^"\']+)["\']#i', $tag, $media_m ) && 'all' !== strtolower( trim( $media_m[1] ) ) ) {
					continue;
				}
				$async_tag = preg_replace( '#\s*media=["\'][^"\']*["\']#i', '', $tag );
				$async_tag = preg_replace( '#(/?\s*>)$#', ' media="print" onload="this.media=\'all\'"$1', $async_tag );
				$html = str_replace( $tag, $async_tag . '<noscript>' . $tag . '</noscript>', $html );
				$has_asyncd = true;
			}
		}

		// On mobile with all-async: inject minimal inline CSS to prevent worst FOUC.
		// Provides base font, background, box-sizing, and image max-width.
		if ( $async_all && $has_asyncd ) {
			$reset = '<style id="pc-css-reset">'
				. 'body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans",sans-serif;background:#fff;color:#333;line-height:1.8}'
				. '*,::before,::after{box-sizing:border-box}'
				. 'img{max-width:100%;height:auto}'
				. 'a{color:#1967d2}'
				. '</style>';
			$html = str_replace( '</head>', $reset . "\n</head>", $html );
		}

		return $html;
	}

	public function minify_css_content( $css ) {
		// Preserve strings and calc() to avoid corrupting content values.
		$preserved = array();
		$css = preg_replace_callback( '#(content\s*:\s*["\'])([^"\']*)["\']|calc\([^)]+\)#i', function( $m ) use ( &$preserved ) {
			$key = '/*PC_P' . count( $preserved ) . '*/';
			$preserved[ $key ] = $m[0];
			return $key;
		}, $css );

		// Remove comments (but not our placeholders).
		$css = preg_replace( '#/\*(?!PC_P).*?\*/#s', '', $css );
		// Collapse whitespace.
		$css = preg_replace( '#\s+#', ' ', $css );
		// Remove spaces around { } ; : , but preserve space before ( for media queries.
		// "and (" must keep the space — "and(" is invalid CSS.
		$css = preg_replace( '#\s*([{};:,])\s*#', '$1', $css );
		// Fix: restore space before ( after keywords like "and", "not", "or", "only".
		$css = preg_replace( '#\b(and|not|or|only)\(#i', '$1 (', $css );
		// Remove last semicolon before }.
		$css = str_replace( ';}', '}', $css );

		// Restore preserved values.
		if ( $preserved ) {
			$css = str_replace( array_keys( $preserved ), array_values( $preserved ), $css );
		}
		return trim( $css );
	}

	private function minify_css_file( $url ) {
		$path = $this->url_to_path( $url );
		if ( ! $path || ! is_readable( $path ) ) {
			return false;
		}

		$hash = md5( $url . filemtime( $path ) );
		$out  = $this->cache_dir . 'css/' . $hash . '.css';
		$out_url = $this->cache_url . 'css/' . $hash . '.css';

		// Use clean /_pc-static/ URL when rewrite is enabled.
		if ( ! empty( $this->settings['rewrite_file_optimizer'] ) ) {
			$out_url = home_url( '/_pc-static/' . $hash . '.css' );
		}

		if ( file_exists( $out ) ) {
			return $out_url;
		}

		$css = file_get_contents( $path ); // phpcs:ignore
		if ( false === $css ) {
			return false;
		}

		$css = $this->rebase_css_urls( $css, $path );
		$css = $this->minify_css_content( $css );

		wp_mkdir_p( dirname( $out ) );
		self::atomic_write( $out, $css );

		return $out_url;
	}

	public function rebase_css_urls( $css, $css_path ) {
		$css_dir = dirname( $css_path );
		return preg_replace_callback( '#url\(\s*["\']?(?!data:|https?://|//)([^"\')\s]+)["\']?\s*\)#i', function( $m ) use ( $css_dir ) {
			$abs = realpath( $css_dir . '/' . $m[1] );
			if ( $abs && strpos( $abs, ABSPATH ) === 0 ) {
				return 'url(' . site_url( '/' . ltrim( str_replace( ABSPATH, '', $abs ), '/' ) ) . ')';
			}
			return $m[0];
		}, $css );
	}

	// ── Google Fonts Async ──────────────────────────────────

	/**
	 * Make Google Fonts <link> tags non-render-blocking.
	 *
	 * Uses media="print" onload="this.media='all'" pattern.
	 * Also adds display=swap to the URL if not already present.
	 *
	 * @param string $html Full HTML.
	 * @return string HTML with async Google Fonts.
	 */
	private function async_google_fonts( $html ) {
		$pattern = '#<link\s[^>]*href=["\'](?:https?:)?//fonts\.googleapis\.com/css2?\?[^"\']+["\'][^>]*/?>#i';

		if ( ! preg_match( $pattern, $html ) ) {
			return $html;
		}

		// Inject early preconnect for font file downloads.
		$preconnect = '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
		if ( false === strpos( $html, 'fonts.gstatic.com' ) || false === strpos( $html, 'preconnect' ) ) {
			$html = str_replace( '<head>', '<head>' . "\n" . $preconnect, $html );
		}

		$add_swap = ! empty( $this->settings['google_fonts_display'] );

		return preg_replace_callback( $pattern, function ( $m ) use ( $add_swap ) {
			$tag = $m[0];
			// Skip if already async.
			if ( false !== strpos( $tag, 'media="print"' ) ) {
				return $tag;
			}
			// Add display=swap if setting enabled and not already present.
			if ( $add_swap && false === strpos( $tag, 'display=' ) ) {
				$tag = preg_replace( '#(href=["\'][^"\']+)(["\'])#i', '$1&display=swap$2', $tag );
			}
			// Remove existing media attribute.
			$tag = preg_replace( '#\s*media=["\'][^"\']*["\']#i', '', $tag );
			// Add async loading pattern.
			$tag = preg_replace( '#(/?\s*>)$#', ' media="print" onload="this.media=\'all\'"$1', $tag );
			// Add noscript fallback.
			return $tag . '<noscript>' . $m[0] . '</noscript>';
		}, $html );
	}

	// ── Defer JS (filter-based, no ob_start) ────────────────

	/**
	 * Wrap inline scripts that depend on jQuery with DOMContentLoaded.
	 *
	 * When jQuery is deferred, inline scripts like $(document).ready() or
	 * jQuery(...) that execute synchronously will fail because jQuery isn't
	 * loaded yet. This wraps them so they wait for DOMContentLoaded, at which
	 * point deferred jQuery has already executed.
	 *
	 * @param string $html Full HTML output.
	 * @return string HTML with inline jQuery scripts wrapped.
	 */
	private function wrap_inline_jquery( $html ) {
		return preg_replace_callback(
			'#<script\b(?![^>]*\bsrc\b)[^>]*>(.*?)</script>#si',
			function ( $m ) {
				$code = $m[1];
				$trimmed = trim( $code );
				if ( '' === $trimmed ) {
					return $m[0];
				}
				// Skip chatbot and other excluded inline scripts.
				if ( false !== stripos( $code, 'raplsaich' ) ) {
					return $m[0];
				}
				// Only wrap if it contains jQuery patterns.
				if ( ! preg_match( '/\bjQuery\s*\(|\$\s*\(/', $code ) ) {
					return $m[0];
				}
				// Skip if already wrapped in DOMContentLoaded or deferred.
				if ( false !== stripos( $code, 'DOMContentLoaded' ) || false !== stripos( $code, 'addEventListener' ) ) {
					return $m[0];
				}
				// Skip JSON-LD and other non-JS types.
				if ( preg_match( '#type=["\'](?!text/javascript|module)[^"\']+["\']#i', $m[0] ) ) {
					return $m[0];
				}
				return '<script>window.addEventListener("DOMContentLoaded",function(){' . $trimmed . '});</script>';
			},
			$html
		);
	}

	/**
	 * Add defer attribute to enqueued scripts via script_loader_tag filter.
	 *
	 * This approach avoids ob_start buffering entirely, which is critical
	 * for servers with Nginx-level caching (Xserver Xアクセラレータ etc.)
	 * where ob_start delays output and causes CLS.
	 *
	 * @param string $tag    The <script> tag HTML.
	 * @param string $handle The script handle.
	 * @param string $src    The script source URL.
	 * @return string Modified tag with defer attribute.
	 */
	/**
	 * Scripts that must NEVER be deferred.
	 * jQuery is no longer excluded — inline jQuery code is wrapped with
	 * DOMContentLoaded by wrap_inline_jquery() to allow safe deferral.
	 */
	private static $defer_never = array(
		'raplsaich-chatbot',
		'raplsaich-recaptcha',
	);

	public function filter_defer_script( $tag, $handle, $src ) {
		// Skip if already has defer or async.
		if ( false !== strpos( $tag, 'defer' ) || false !== strpos( $tag, 'async' ) ) {
			return $tag;
		}

		// Never defer jQuery and critical dependencies.
		if ( in_array( $handle, self::$defer_never, true ) ) {
			return $tag;
		}

		// Skip non-JS types (e.g. application/ld+json).
		if ( preg_match( '#type=["\'](?!text/javascript|module)[^"\']+["\']#i', $tag ) ) {
			return $tag;
		}

		// Skip data-no-defer scripts.
		if ( false !== strpos( $tag, 'data-no-defer' ) ) {
			return $tag;
		}

		// Check defer exclusion list.
		$defer_excl = $this->parse_list( $this->settings['exclude_defer_js'] );
		if ( $this->matches_patterns( $src, $defer_excl ) ) {
			return $tag;
		}

		// Add defer attribute.
		return str_replace( ' src=', ' defer src=', $tag );
	}

	// ── Delay JS (filter-based, no ob_start) ────────────────

	/**
	 * Scripts that must NEVER be delayed (break core functionality).
	 */
	private static $delay_never = array(
		'jquery-core',
		'jquery',
		'jquery-migrate',
		'wp-hooks',
		'wp-i18n',
		'wp-element',
		'wp-dom-ready',
		'raplsaich-chatbot',
		'raplsaich-recaptcha',
	);

	/**
	 * Delay enqueued scripts via script_loader_tag filter.
	 * Changes script type to prevent execution, adds data-src.
	 * A tiny loader script restores them on user interaction.
	 *
	 * No ob_start needed — compatible with Nginx-level caching.
	 */
	public function filter_delay_script( $tag, $handle, $src ) {
		// Skip if already deferred/async (might be handled by filter_defer_script).
		if ( false !== strpos( $tag, 'data-pc-delayed' ) ) {
			return $tag;
		}

		// Never delay critical scripts.
		if ( in_array( $handle, self::$delay_never, true ) ) {
			return $tag;
		}

		// Skip non-JS types.
		if ( preg_match( '#type=["\'](?!text/javascript|module)[^"\']+["\']#i', $tag ) ) {
			return $tag;
		}

		// Skip data-no-delay scripts.
		if ( false !== strpos( $tag, 'data-no-delay' ) ) {
			return $tag;
		}

		// Check delay exclusion list + presets.
		$delay_excl = $this->parse_list( $this->settings['exclude_delay_js'] );
		$preset_excl = $this->get_delay_preset_patterns();
		$all_excl = array_merge( $delay_excl, $preset_excl );
		if ( $this->matches_patterns( $src, $all_excl ) ) {
			return $tag;
		}

		// Safe mode: only delay external (third-party) scripts.
		// Scripts from the site's own domain load immediately.
		if ( ! empty( $this->settings['delay_js_safe_mode'] ) ) {
			if ( $this->is_local_url( $src ) ) {
				return $tag;
			}
		}

		// Replace type and src to prevent execution.
		if ( false === strpos( $tag, 'type=' ) ) {
			$tag = str_replace( '<script ', '<script type="pc-delay/js" ', $tag );
		} else {
			$tag = preg_replace( '#type=["\'][^"\']*["\']#i', 'type="pc-delay/js"', $tag );
		}
		$tag = str_replace( ' src=', ' data-pc-delayed data-src=', $tag );

		return $tag;
	}

	/**
	 * Get URL patterns from delay JS presets.
	 *
	 * Maps preset keys (e.g. 'google_analytics', 'facebook_pixel') to
	 * script URL patterns that should be excluded from delay.
	 *
	 * @return array URL patterns to exclude.
	 */
	private function get_delay_preset_patterns() {
		$presets_value = trim( $this->settings['delay_js_presets'] ?? '' );
		if ( empty( $presets_value ) ) {
			return array();
		}

		// Keys must match the UI checkbox values in class-admin-settings.php (hyphenated).
		$preset_map = array(
			'google-analytics'  => array( 'google-analytics.com/analytics.js', 'googletagmanager.com/gtag/js' ),
			'google-tag-manager' => array( 'googletagmanager.com/gtm.js' ),
			'facebook-pixel'    => array( 'connect.facebook.net' ),
			'google-adsense'    => array( 'pagead2.googlesyndication.com' ),
			'recaptcha'         => array( 'google.com/recaptcha', 'gstatic.com/recaptcha' ),
			'hotjar'            => array( 'static.hotjar.com' ),
			'clarity'           => array( 'clarity.ms' ),
			'intercom'          => array( 'widget.intercom.io' ),
			'crisp'             => array( 'client.crisp.chat' ),
			'tawk'              => array( 'embed.tawk.to' ),
			'hubspot'           => array( 'js.hs-scripts.com', 'js.hs-analytics.net' ),
			'pinterest'         => array( 'assets.pinterest.com/js/pinit' ),
			'twitter'           => array( 'platform.twitter.com/widgets.js' ),
		);

		$active = array_filter( array_map( 'trim', explode( ',', $presets_value ) ) );
		$patterns = array();
		foreach ( $active as $key ) {
			if ( isset( $preset_map[ $key ] ) ) {
				$patterns = array_merge( $patterns, $preset_map[ $key ] );
			}
		}
		return $patterns;
	}

	/**
	 * Print the tiny delay loader script in the footer.
	 * Restores delayed scripts on first user interaction.
	 */
	public function print_delay_loader() {
		$timeout = (int) ( $this->settings['delay_js_timeout'] ?? 0 );
		$timeout_js = $timeout > 0 ? "setTimeout(run,{$timeout});" : '';
		?>
		<script id="pc-delay-loader">
		(function(){
			var done=false;
			function run(){
				if(done)return;done=true;
				document.querySelectorAll('script[type="pc-delay/js"]').forEach(function(el){
					var n=document.createElement('script');
					Array.from(el.attributes).forEach(function(a){
						if(a.name==='type')return;
						if(a.name==='data-src'){n.src=a.value;return;}
						if(a.name==='data-pc-delayed')return;
						n.setAttribute(a.name,a.value);
					});
					if(!n.src&&el.textContent)n.textContent=el.textContent;
					el.parentNode.replaceChild(n,el);
				});
			}
			['scroll','click','keydown','touchstart','mousemove'].forEach(function(e){
				window.addEventListener(e,run,{once:true,passive:true});
			});
			<?php echo $timeout_js; ?>
		})();
		</script>
		<?php
	}

	// ── Delay JS (ob_start pipeline — full HTML processing) ──

	/** @var string|null Cached loader script content. */
	private static $delay_loader_cache = null;

	/**
	 * Delay ALL JavaScript via HTML pipeline processing.
	 *
	 * Transforms every <script> tag in the HTML output:
	 * - External: type → pc-delay/javascript, src → data-pc-src
	 * - Inline: type → pc-delay/javascript
	 * Injects the delay loader script right after <head>.
	 *
	 * This is the WP-Rocket-style approach: full HTML processing via ob_start
	 * captures ALL scripts (inline + external + CDN), not just enqueued.
	 */
	private function delay_all_scripts( $html ) {
		$s = $this->settings;

		// Build exclusion patterns.
		$excl = $this->parse_list( $s['exclude_delay_js'] );
		$excl = array_merge( $excl, $this->get_delay_preset_patterns() );
		// Built-in exclusions: chat widgets and interactive plugins that
		// break when their initialization is delayed.
		$excl[] = 'raplsaich';
		$excl[] = 'raplsaichConfig';

		// Safe mode: exclude local scripts.
		$safe_mode = ! empty( $s['delay_js_safe_mode'] );

		// Non-JS types to skip.
		$skip_types = array(
			'application/json', 'application/ld+json', 'text/template',
			'text/html', 'text/x-template', 'text/x-handlebars-template',
			'text/x-custom-template',
		);

		// Protect SVG content from script matching.
		$svg_placeholders = array();
		$html = preg_replace_callback( '#<svg[^>]*>.*?</svg>#si', function( $m ) use ( &$svg_placeholders ) {
			$key = '<!--PC_SVG_' . count( $svg_placeholders ) . '-->';
			$svg_placeholders[ $key ] = $m[0];
			return $key;
		}, $html );

		// Transform script tags.
		$html = preg_replace_callback(
			'#<\s*script(?<attr>\s[^>]*?)?>(?<content>.*?)?<\s*/\s*script\s*>#ims',
			function( $m ) use ( $excl, $safe_mode, $skip_types ) {
				$full = $m[0];
				$attr = isset( $m['attr'] ) ? $m['attr'] : '';
				$content = isset( $m['content'] ) ? $m['content'] : '';

				// Skip if already delayed.
				if ( false !== strpos( $attr, 'data-pc-delayed' ) ) return $full;
				if ( false !== strpos( $attr, 'pc-delay' ) ) return $full;

				// Skip data-no-delay.
				if ( false !== strpos( $attr, 'data-no-delay' ) ) return $full;

				// Check type — skip non-JS types.
				if ( preg_match( '#type\s*=\s*["\']([^"\']*)["\']#i', $attr, $type_m ) ) {
					$type = strtolower( trim( $type_m[1] ) );
					if ( $type && in_array( $type, $skip_types, true ) ) {
						return $full;
					}
				}

				// Extract src if present.
				$src = '';
				if ( preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', $attr, $src_m ) ) {
					$src = $src_m[1];
				}

				// Check exclusion patterns (match against src or inline content).
				$match_target = $src ? $src : $content;
				foreach ( $excl as $pattern ) {
					if ( false !== stripos( $match_target, $pattern ) ) {
						return $full;
					}
					// Try as regex.
					if ( @preg_match( '#' . $pattern . '#i', $match_target ) ) {
						return $full;
					}
				}

				// Safe mode: skip local scripts.
				if ( $safe_mode && $src && $this->is_local_url( $src ) ) {
					return $full;
				}

				// Skip the delay loader itself.
				if ( false !== strpos( $attr, 'id="pc-delay-loader"' ) ) return $full;
				if ( false !== strpos( $content, 'pcDelayTimeout' ) ) return $full;

				// ── Transform ──
				// Rename type to data-pc-type, set type to delay marker.
				if ( preg_match( '#type\s*=\s*["\']([^"\']*)["\']#i', $attr, $type_m ) ) {
					$attr = preg_replace(
						'/type\s*=\s*["\'][^"\']*["\']/i',
						'data-pc-type="' . esc_attr( $type_m[1] ) . '" type="pc-delay/javascript"',
						$attr
					);
				} else {
					$attr = ' type="pc-delay/javascript"' . $attr;
				}

				// Rename src to data-pc-src.
				if ( $src ) {
					$attr = preg_replace(
						'/src\s*=\s*["\']([^"\']+)["\']/i',
						'data-pc-src="$1"',
						$attr
					);
				}

				// Add marker.
				$attr .= ' data-pc-delayed';

				return '<script' . $attr . '>' . $content . '</script>';
			},
			$html
		);

		// Restore SVG.
		if ( $svg_placeholders ) {
			$html = str_replace( array_keys( $svg_placeholders ), array_values( $svg_placeholders ), $html );
		}

		// Inject loader script right after <head>.
		$loader = $this->get_delay_loader_script();
		if ( $loader ) {
			$html = preg_replace(
				'/<head([^>]*)>/i',
				'<head$1>' . "\n" . '<script id="pc-delay-loader" data-no-delay>' . $loader . '</script>',
				$html,
				1
			);
		}

		return $html;
	}

	/**
	 * Get the delay loader JS content (cached).
	 */
	private function get_delay_loader_script() {
		if ( null !== self::$delay_loader_cache ) {
			return self::$delay_loader_cache;
		}

		$file = PRIME_CACHE_PATH . 'assets/js/pc-delay-loader.js';
		if ( ! is_readable( $file ) ) {
			self::$delay_loader_cache = '';
			return '';
		}

		$js = file_get_contents( $file ); // phpcs:ignore

		// Embed timeout config.
		$timeout = (int) ( $this->settings['delay_js_timeout'] ?? 0 );
		$js = 'window.pcDelayTimeout=' . $timeout . ';' . "\n" . $js;

		self::$delay_loader_cache = $js;
		return $js;
	}

	// ── JS (ob_start pipeline) ───────────────────────────────

	private function process_js( $html ) {
		$s = $this->settings;
		$excludes       = $this->parse_list( $s['exclude_js'] );
		$inline_excl    = $this->parse_list( $s['exclude_inline_js'] );

		// Note: Delay JS is now handled by delay_all_scripts() in the HTML pipeline.
		// Defer JS is handled by filter_defer_script() via script_loader_tag filter.

		// Process <script> tags.
		$html = preg_replace_callback( '#<script\b([^>]*)>(.*?)</script>#si', function( $m ) use ( $s, $excludes, $inline_excl ) {
			$attrs   = $m[1];
			$content = $m[2];
			$full    = $m[0];

			$has_src = preg_match( '#src=["\']([^"\']+)["\']#i', $attrs, $src_match );
			$src     = $has_src ? $src_match[1] : '';

			// Skip type="application/ld+json", type="text/template" etc.
			if ( preg_match( '#type=["\'](?!text/javascript|module)[^"\']+["\']#i', $attrs ) ) {
				return $full;
			}

			// Skip already-delayed scripts (processed by filter_delay_script).
			if ( false !== strpos( $attrs, 'data-pc-delayed' ) || false !== strpos( $attrs, 'pc-delay/' ) ) {
				return $full;
			}

			// Check exclusions.
			if ( $has_src && $this->matches_patterns( $src, $excludes ) ) {
				return $full;
			}
			if ( ! $has_src && $this->matches_inline_patterns( $content, $inline_excl ) ) {
				return $full;
			}

			// Minify inline JS.
			if ( $s['minify_js'] && ! $has_src && ! empty( trim( $content ) ) ) {
				$content = $this->minify_js_content( $content );
				return '<script' . $attrs . '>' . $content . '</script>';
			}

			// Minify external JS file.
			if ( $s['minify_js'] && $has_src && $this->is_local_url( $src ) ) {
				$min_url = $this->minify_js_file( $src );
				if ( $min_url ) {
					return str_replace( $src, $min_url, $full );
				}
			}

			return $full;
		}, $html );

		return $html;
	}

	public function minify_js_content( $js ) {
		// Preserve string literals and template literals.
		$strings = array();
		$js = preg_replace_callback( '#(["\'])(?:\\\\.|(?!\1).)*\1|`(?:\\\\.|[^`])*`#s', function( $m ) use ( &$strings ) {
			$key = '"PC_STR_' . count( $strings ) . '"';
			$strings[ $key ] = $m[0];
			return $key;
		}, $js );

		// Remove multi-line comments.
		$js = preg_replace( '#/\*.*?\*/#s', '', $js );
		// Remove single-line comments (only at line start or after semicolons/braces).
		$js = preg_replace( '#(^|[;{}()\n])\s*//[^\n]*#m', '$1', $js );
		// Collapse whitespace.
		$js = preg_replace( '#[\t ]+#', ' ', $js );
		$js = preg_replace( '#\n+#', "\n", $js );

		// Restore string literals.
		if ( $strings ) {
			$js = str_replace( array_keys( $strings ), array_values( $strings ), $js );
		}
		return trim( $js );
	}

	/**
	 * Combine local JS files into a single file.
	 */
	private function minify_js_file( $url ) {
		$path = $this->url_to_path( $url );
		if ( ! $path || ! is_readable( $path ) ) {
			return false;
		}

		// Already minified.
		if ( preg_match( '#\.min\.js$#', $path ) ) {
			return false;
		}

		$hash = md5( $url . filemtime( $path ) );
		$out  = $this->cache_dir . 'js/' . $hash . '.js';
		$out_url = $this->cache_url . 'js/' . $hash . '.js';

		// Use clean /_pc-static/ URL when rewrite is enabled.
		if ( ! empty( $this->settings['rewrite_file_optimizer'] ) ) {
			$out_url = home_url( '/_pc-static/' . $hash . '.js' );
		}

		if ( file_exists( $out ) ) {
			return $out_url;
		}

		$js = file_get_contents( $path ); // phpcs:ignore
		if ( false === $js ) {
			return false;
		}

		$js = $this->minify_js_content( $js );
		wp_mkdir_p( dirname( $out ) );
		self::atomic_write( $out, $js );

		return $out_url;
	}

	// Delay JS is handled entirely by filter_delay_script() + print_delay_loader().
	// Convention: type="pc-delay/js", data-src, data-pc-delayed.

	// ── Query String Removal ─────────────────────────────────

	private function strip_query_strings( $html ) {
		// Strip ?ver= / ?v= parameters from local CSS/JS URLs regardless of position.
		// Handles: only param, first param, middle param, last param.
		return preg_replace_callback(
			'#((?:href|src)=["\'])([^"\']+\.(?:css|js))\?([^"\']+)(["\'])#i',
			function( $m ) {
				$url   = $m[2];
				$query = $m[3];

				// Only strip from local (relative or same-host) URLs.
				if ( 0 !== strpos( $url, '/' ) && ! $this->is_local_url( $url ) ) {
					return $m[0]; // External — keep query string.
				}

				// Parse query parameters and remove ver/v.
				$params = array();
				foreach ( explode( '&', $query ) as $part ) {
					$key = strtok( $part, '=' );
					if ( 'ver' !== $key && 'v' !== $key ) {
						$params[] = $part;
					}
				}

				if ( empty( $params ) ) {
					return $m[1] . $url . $m[4];
				}
				return $m[1] . $url . '?' . implode( '&', $params ) . $m[4];
			},
			$html
		);
	}

	// ── DNS Prefetch ─────────────────────────────────────────

	public function inject_dns_prefetch( $html ) {
		$domains = $this->parse_list( $this->settings['prefetch_dns'] );
		if ( empty( $domains ) ) {
			return $html;
		}

		$tags = '';
		foreach ( $domains as $domain ) {
			$domain = trim( $domain );
			if ( empty( $domain ) ) {
				continue;
			}
			if ( 0 !== strpos( $domain, '//' ) && 0 !== strpos( $domain, 'http' ) ) {
				$domain = '//' . $domain;
			}
			$tags .= '<link rel="dns-prefetch" href="' . esc_url( $domain ) . '">' . "\n";
		}

		if ( $tags ) {
			$html = str_replace( '</head>', $tags . '</head>', $html );
		}

		return $html;
	}

	// ── Rewrite File Optimizer ────────────────────────────────

	/**
	 * Register rewrite rule for serving optimized files via /_pc-static/ URL.
	 */
	public function register_rewrite_rules() {
		add_rewrite_rule( '^_pc-static/(.+)$', 'index.php?pc_static_file=$1', 'top' );
		add_filter( 'query_vars', function( $vars ) {
			$vars[] = 'pc_static_file';
			return $vars;
		} );
	}

	/**
	 * Handle requests for /_pc-static/ optimized files.
	 */
	public function handle_rewrite_request( $wp ) {
		if ( empty( $wp->query_vars['pc_static_file'] ) ) {
			return;
		}

		$file = sanitize_file_name( $wp->query_vars['pc_static_file'] );
		$ext  = pathinfo( $file, PATHINFO_EXTENSION );

		$type_map = array( 'css' => 'text/css', 'js' => 'application/javascript' );
		if ( ! isset( $type_map[ $ext ] ) ) {
			status_header( 404 );
			exit;
		}

		// Look up in cache subdirectories.
		$search_dirs = array( 'css/', 'js/', 'ucss/', 'ccss/', 'fonts/' );
		$found = false;
		foreach ( $search_dirs as $sub ) {
			$path = $this->cache_dir . $sub . $file;
			if ( file_exists( $path ) ) {
				$found = $path;
				break;
			}
		}

		if ( ! $found ) {
			status_header( 404 );
			exit;
		}

		// Verify path is within cache dir.
		$real = realpath( $found );
		$real_cache = realpath( $this->cache_dir );
		if ( ! $real || ! $real_cache || 0 !== strpos( $real, $real_cache ) ) {
			status_header( 403 );
			exit;
		}

		header( 'Content-Type: ' . $type_map[ $ext ] . '; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		header( 'X-Prime-Cache-FO: HIT' );
		readfile( $found );
		exit;
	}

	// ── Shared Utility ──────────────────────────────────────

	/**
	 * Atomic file write: temp file + rename to prevent serving partial files.
	 */
	public static function atomic_write( $path, $content ) {
		$tmp = $path . '.tmp.' . getmypid();
		if ( false === file_put_contents( $tmp, $content ) ) { // phpcs:ignore
			return false;
		}
		if ( ! rename( $tmp, $path ) ) { // phpcs:ignore
			@unlink( $tmp );
			return false;
		}
		return true;
	}

	public function parse_list( $value ) {
		if ( empty( $value ) ) {
			return array();
		}
		// Support comma-separated and newline-separated.
		$items = preg_split( '#[\r\n,]+#', $value );
		return array_filter( array_map( 'trim', $items ) );
	}

	public function matches_patterns( $subject, $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( empty( $pattern ) ) {
				continue;
			}
			if ( false !== strpos( $subject, $pattern ) ) {
				return true;
			}
			// Support wildcard.
			if ( false !== strpos( $pattern, '*' ) ) {
				$regex = '#' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '#i';
				if ( @preg_match( $regex, $subject ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function matches_inline_patterns( $content, $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( ! empty( $pattern ) && false !== strpos( $content, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Write a debug log entry.
	 */
	public static function debug_log( $message ) {
		if ( ! defined( 'PRIME_CACHE_CACHE_DIR' ) ) return;
		$log_file = PRIME_CACHE_CACHE_DIR . 'debug.log';
		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] ' . $message . "\n";
		// Cap log at 1 MB — rotate with LOCK_EX to prevent corruption.
		if ( file_exists( $log_file ) && filesize( $log_file ) > 1048576 ) {
			file_put_contents( $log_file, $line, LOCK_EX ); // phpcs:ignore -- overwrite
		} else {
			file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore
		}
	}

	public function is_local_url( $url ) {
		$home = home_url();
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			return true;
		}
		return 0 === strpos( $url, $home );
	}

	public function url_to_path( $url ) {
		$home_url  = home_url( '/' );
		$home_path = ABSPATH;

		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			$path = ABSPATH . ltrim( $url, '/' );
		} elseif ( 0 === strpos( $url, $home_url ) ) {
			$path = $home_path . substr( $url, strlen( $home_url ) );
		} else {
			return false;
		}

		// Strip query string.
		$path = strtok( $path, '?' );

		$real = realpath( $path );
		if ( $real && 0 === strpos( $real, realpath( ABSPATH ) ) ) {
			return $real;
		}

		return false;
	}
}
