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
 *
 * (DNS prefetch and resource hints are handled by Prime_Cache_Preload, not here.)
 */

defined( 'ABSPATH' ) || exit;

// Prime Cache manages its own cache files directly for performance; the
// WP_Filesystem API is not used on these cache paths. Disable the direct-file
// sniff for this module.
// phpcs:disable WordPress.WP.AlternativeFunctions

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

		// Add-on cron handlers are registered by class-file-optimizer-pro.php.

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
			// Delay JS is wired in the HTML pipeline (process_html →
			// delay_all_scripts), which captures inline / external / CDN
			// scripts that script_loader_tag does not see.
		}

		// Debug logging hooks must register independently of HTML optimization
		// state — purges happen everywhere (admin actions, cron, CLI), so gating
		// these behind should_optimize_html() (which returns false in admin and
		// when no optimization is active) would silently lose most events.
		if ( ! empty( $this->settings['debug_log'] ) ) {
			add_action( 'prime_cache_after_purge_all', function() {
				self::debug_log( 'PURGE ALL' );
			} );
			add_action( 'prime_cache_url_purged', function( $url ) {
				self::debug_log( 'PURGE URL: ' . $url );
			} );
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
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = strtok( $uri, '?' );

		// This computes the page-cache key from the current request on every
		// front-end hit; it is not form processing, so no nonce applies. Only
		// query-string keys are inspected here (values are not output).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET ) ) {
			return $path;
		}

		$s          = $this->settings;
		$ignored    = array_filter( array_map( 'trim', explode( ',', $s['cache_ignore_qs'] ?? '' ) ) );
		$cached_qs  = array_filter( array_map( 'trim', explode( ',', $s['cache_query_strings'] ?? '' ) ) );
		$remaining  = array_diff_key( $_GET, array_flip( $ignored ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

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
			|| ! empty( $s['google_fonts_display'] )
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

		// Mark scripts that must never be combined or delayed. Runs before
		// minify/combine rewrites the src URL to a hashed cache filename,
		// while the original handle/URL is still identifiable.
		$html = $this->mark_preserved_scripts( $html );

		// Add-on hook: runs before Free optimizations (DNS prefetch, analytics, fonts, UCSS, critical CSS).
		$html = apply_filters( 'prime_cache_before_optimize', $html, $s );

		// Remove query strings from static resources.
		if ( $s['remove_query_strings'] ) {
			$html = $this->strip_query_strings( $html );
		}

		// CSS optimizations (Free: minify, inline small, async non-first).
		$html = $this->process_css( $html );

		// Google Fonts optimization (Free feature) — gated behind google_fonts_display
		// so the toggle's effect matches what the UI advertises. Font CSS is never
		// above-fold critical — text renders with fallback font via font-display: swap,
		// then swaps when the font loads.
		if ( ! empty( $s['google_fonts_display'] ) ) {
			$html = $this->async_google_fonts( $html );
		}

		// Add-on hook: CSS combine, async, critical CSS.
		$html = apply_filters( 'prime_cache_process_css', $html, $s );

		// JS optimizations (Free: minify only via ob_start pipeline).
		if ( $s['minify_js'] ) {
			$html = $this->process_js( $html );
		}

		// Add-on hook: JS combine.
		$html = apply_filters( 'prime_cache_process_js', $html, $s );

		// Mobile detection must match the cache-key side (dropin / .htaccess) so
		// the HTML we generate ends up in the bucket the next request will look
		// up. wp_is_mobile() differs slightly (no webOS, str_contains semantics)
		// and is filterable, which would let a desktop-rendered HTML land in the
		// mobile bucket on a webOS visitor or vice versa.
		require_once PRIME_CACHE_PATH . 'includes/cache-key-functions.php';
		$is_mobile = _prime_cache_is_mobile_ua( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) );

		// Wrap inline jQuery scripts with DOMContentLoaded so jQuery can be deferred.
		// Mobile only — on desktop jQuery is synchronous (not deferred) and inline
		// scripts must execute immediately to avoid CLS from delayed layout changes.
		if ( $s['defer_js'] && $is_mobile ) {
			$html = $this->wrap_inline_jquery( $html );
		}

		// Delay JS: transform every external <script src="..."> tag via HTML pipeline.
		// Inline scripts are intentionally left alone (see delay_all_scripts docblock).
		// Mobile only — desktop suffers CLS regression when scripts are delayed
		// because layout-dependent JS (sliders, menus) runs late.
		if ( $s['delay_js'] && $is_mobile ) {
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
		// Free-only async: skip when the add-on handles CSS (async_css, combine_css, critical_css).
		$do_inline  = ! $is_pro && ! empty( $s['inline_small_css'] );
		$do_async   = ! $is_pro && ! empty( $s['async_css_free'] );
		$threshold  = (int) ( $s['inline_css_threshold'] ?? 8192 );

		// One preg_replace_callback so each <link> tag is replaced exactly
		// once in document order. The previous str_replace / strpos approach
		// could clobber an earlier identical-string tag (theme that enqueues
		// the same href twice) when processing a later tag, retroactively
		// async-converting the sync stylesheet we wanted to preserve.
		$async_eligible_index = 0;
		$self                 = $this;
		$original_html        = $html;
		$html = preg_replace_callback(
			'#<link\s[^>]*rel=["\']stylesheet["\'][^>]*/?\s*>#i',
			function ( $m ) use ( $self, $excludes, $do_minify, $do_inline, $do_async, $threshold, &$async_eligible_index ) {
				$tag = $m[0];
				if ( ! preg_match( '#href=["\']([^"\']+)["\']#i', $tag, $href_match ) ) {
					return $tag;
				}
				$href = $href_match[1];
				if ( $self->matches_patterns( $href, $excludes ) ) {
					return $tag;
				}

				$is_local = $self->is_local_url( $href );

				// Inline small CSS files (eliminate HTTP request).
				if ( $do_inline && $is_local ) {
					$path = $self->url_to_path( $href );
					if ( $path && is_readable( $path ) && filesize( $path ) <= $threshold ) {
						$css = file_get_contents( $path ); // phpcs:ignore
						if ( false !== $css ) {
							$css = $self->rebase_css_urls( $css, $path );
							if ( $do_minify ) {
								$css = $self->minify_css_content( $css );
							}
							// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Inline-CSS replacement for an existing <link> tag during HTML buffer transformation. Cannot use wp_enqueue_style() because the HTML has already left WordPress's enqueue pipeline (we are rewriting the rendered HTML during ob_start to inline small stylesheets) and we are deliberately replacing the original <link> with the inlined content to eliminate an HTTP request.
							return '<style>' . $css . '</style>';
						}
					}
				}

				// Minify individual CSS files (skip already minified .min.css).
				if ( $do_minify && $is_local && false === strpos( $href, '.min.css' ) ) {
					$minified_url = $self->minify_css_file( $href );
					if ( $minified_url ) {
						$tag = str_replace( $href, $minified_url, $tag );
					}
				}

				if ( $do_async ) {
					// Already non-blocking (media=print, media=screen and (...), etc.)
					// counts as neither eligible nor a candidate — leave as-is.
					if ( preg_match( '#media=["\']([^"\']+)["\']#i', $tag, $media_m ) && 'all' !== strtolower( trim( $media_m[1] ) ) ) {
						return $tag;
					}
					// First render-blocking stylesheet stays sync (likely critical /
					// above-the-fold) to preserve LCP and avoid an unstyled flash.
					$async_eligible_index++;
					if ( $async_eligible_index > 1 ) {
						$async_tag = preg_replace( '#\s*media=["\'][^"\']*["\']#i', '', $tag );
						$async_tag = preg_replace( '#(/?\s*>)$#', ' media="print" onload="this.media=\'all\'"$1', $async_tag );
						return $async_tag . '<noscript>' . $tag . '</noscript>';
					}
				}

				return $tag;
			},
			$html
		);

		return null === $html ? $original_html : $html;
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

		// Same-second redeploy with different content shares (url, mtime); add
		// filesize so that path produces a fresh hash and doesn't serve the
		// previous build from the cached output file.
		$hash = md5( $url . '|' . filemtime( $path ) . '|' . filesize( $path ) );
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

		// If we cannot create the cache dir or persist the file atomically,
		// fall back to the original URL — returning $out_url would point to a
		// 404 and break the page's stylesheet.
		if ( ! wp_mkdir_p( dirname( $out ) ) ) {
			return false;
		}
		if ( ! self::atomic_write( $out, $css ) ) {
			return false;
		}

		return $out_url;
	}

	public function rebase_css_urls( $css, $css_path ) {
		$css_dir = dirname( $css_path );

		$rebase = function ( $relative ) use ( $css_dir ) {
			// Fragment-only references — `url(#mask)`, `url(#clipPath)` —
			// point at an in-document SVG element, not a sibling file.
			// Resolving them through realpath() would silently rewrite the
			// fragment to a CSS-dir-rooted URL and break SVG masks/filters.
			if ( '' !== $relative && '#' === $relative[0] ) {
				return null;
			}
			// Strip ?query / #fragment before resolving on disk — realpath
			// would otherwise fail on common cache-buster URLs like
			// `font.woff2?ver=1.2`. Re-attach the suffix to the rebased URL
			// so the browser still receives the original query/fragment.
			$suffix = '';
			$path   = $relative;
			if ( false !== strpbrk( $relative, '?#' ) ) {
				$pos    = strcspn( $relative, '?#' );
				$path   = substr( $relative, 0, $pos );
				$suffix = substr( $relative, $pos );
			}
			if ( '' === $path ) {
				return null; // Nothing to resolve (e.g. `?query` only).
			}
			$abs = realpath( $css_dir . '/' . $path );
			if ( $abs && self::path_within( $abs, ABSPATH ) ) {
				return site_url( '/' . ltrim( str_replace( ABSPATH, '', $abs ), '/' ) ) . $suffix;
			}
			return null;
		};

		// url(...) references in declarations.
		$css = preg_replace_callback( '#url\(\s*["\']?(?!data:|https?://|//)([^"\')\s]+)["\']?\s*\)#i', function ( $m ) use ( $rebase ) {
			$abs_url = $rebase( $m[1] );
			return null !== $abs_url ? 'url(' . $abs_url . ')' : $m[0];
		}, $css );

		// @import "foo.css" / @import 'foo.css' (without url()). When the
		// minified CSS is moved to /cache/prime-cache-fo/css/<hash>.css the
		// relative @import target no longer resolves; rebase to an absolute
		// site URL so the import keeps working from the new location.
		$css = preg_replace_callback( '#@import\s+(["\'])(?!https?://|//|data:)([^"\']+)\1#i', function ( $m ) use ( $rebase ) {
			$abs_url = $rebase( $m[2] );
			return null !== $abs_url ? '@import ' . $m[1] . $abs_url . $m[1] : $m[0];
		}, $css );

		return $css;
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

		// Inject early preconnect for font file downloads. Skip only when a
		// preconnect link to fonts.gstatic.com specifically is already present —
		// the previous coarse check (any fonts.gstatic.com substring + any
		// preconnect tag) suppressed injection on sites that had unrelated
		// preconnect tags (e.g. to www.google-analytics.com), even though no
		// preconnect to fonts.gstatic.com existed.
		// Two regexes handle both attribute orders (rel-first, href-first) and
		// both quote styles since `<link>` attributes have no fixed order.
		$preconnect = '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
		$has_gstatic_preconnect = (bool) preg_match(
			'#<link[^>]+rel\s*=\s*["\'][^"\']*preconnect[^"\']*["\'][^>]+href\s*=\s*["\'][^"\']*fonts\.gstatic\.com[^"\']*["\']#i',
			$html
		) || (bool) preg_match(
			'#<link[^>]+href\s*=\s*["\'][^"\']*fonts\.gstatic\.com[^"\']*["\'][^>]+rel\s*=\s*["\'][^"\']*preconnect[^"\']*["\']#i',
			$html
		);
		if ( ! $has_gstatic_preconnect ) {
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
		// Honor the user's "Excluded Inline JavaScript" list — process_js() already
		// uses it for minify, so the wrap step (which is a transformation, not a
		// no-op) should respect the same opt-out. Without this, an inline script
		// matching e.g. `wp_localize_script-some-handle` still gets wrapped.
		$inline_excl = $this->parse_list( $this->settings['exclude_inline_js'] ?? '' );
		return preg_replace_callback(
			'#<script\b(?![^>]*\bsrc\b)[^>]*>(.*?)</script>#si',
			function ( $m ) use ( $inline_excl ) {
				$code = $m[1];
				$trimmed = trim( $code );
				if ( '' === $trimmed ) {
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
				// Skip if any user-configured inline-JS exclusion pattern appears
				// in the script body. process_js() uses strpos (case-sensitive) for
				// the same setting; match that semantics so users only have to
				// learn one rule about their exclusion list.
				foreach ( $inline_excl as $pattern ) {
					if ( '' !== $pattern && false !== strpos( $code, $pattern ) ) {
						return $m[0];
					}
				}
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Rewriting an existing inline jQuery <script> tag during HTML buffer transformation so its body is deferred to DOMContentLoaded. wp_add_inline_script() cannot be used because the HTML has already left WordPress's enqueue pipeline; we are replacing the matched original tag in-place.
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
		'divi-custom-script',
		'et-builder-modules-script',
		'et-frontend-builder',
		'wp-consent-api',
	);

	/**
	 * Get the full defer-never list, including theme-specific entries.
	 * jQuery is only excluded from defer on Divi (webpack bundle requires
	 * synchronous jQuery). On other themes, wrap_inline_jquery() handles
	 * inline jQuery code safely with DOMContentLoaded wrapping.
	 */
	private function get_defer_never() {
		static $list = null;
		if ( null !== $list ) {
			return $list;
		}
		$list = self::$defer_never;

		// jQuery defer causes CLS ~1.0 on desktop (menus, sliders shift on load).
		// On mobile, jQuery defer is safe (TBT improvement outweighs CLS risk
		// because mobile layout is simpler and delay_all_scripts handles timing).
		// Requires cache_mobile_separate for correct per-device caching.
		// Use the cache-key-side helper so this decision matches what the dropin
		// puts in the mobile bucket (otherwise a desktop-defer'd script set could
		// be served to a mobile UA the dropin already cached as "mobile").
		require_once PRIME_CACHE_PATH . 'includes/cache-key-functions.php';
		$is_mobile = _prime_cache_is_mobile_ua( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) );
		if ( ! $is_mobile ) {
			$list = array_merge( $list, array( 'jquery-core', 'jquery', 'jquery-migrate' ) );
		}

		// Divi theme: always block jQuery defer (webpack bundle requires sync jQuery).
		$theme = wp_get_theme();
		$is_divi = ( 'Divi' === $theme->get( 'Name' ) || 'Divi' === $theme->parent_theme );
		if ( $is_divi ) {
			$list = array_merge( $list, array( 'jquery-core', 'jquery', 'jquery-migrate' ) );
		}

		$list = array_unique( $list );
		return $list;
	}

	public function filter_defer_script( $tag, $handle, $src ) {
		// Skip if already has defer or async as an actual attribute. The
		// previous strpos check matched src URLs containing "async" (e.g.
		// /js/async-loader.js) and falsely declared them already-async,
		// suppressing the intended defer transform.
		if ( preg_match( '#\s(?:defer|async)(?:\s|=|/?>)#i', $tag ) ) {
			return $tag;
		}

		// Never defer critical scripts (theme-aware list).
		if ( in_array( $handle, $this->get_defer_never(), true ) ) {
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

		// Skip when the script has wp_add_inline_script() data attached. The
		// 'after' bucket emits a synchronous <script> right after the external
		// tag and assumes the external script has already executed; deferring
		// the external script breaks that dependency with a ReferenceError.
		// 'before' is theoretically safe but plenty of plugins rely on it
		// running in tight order with the script body, so be conservative.
		$wp_scripts = wp_scripts();
		if ( $handle && isset( $wp_scripts->registered[ $handle ] ) ) {
			$reg   = $wp_scripts->registered[ $handle ];
			$after = isset( $reg->extra['after'] ) ? array_filter( (array) $reg->extra['after'] ) : array();
			$before = isset( $reg->extra['before'] ) ? array_filter( (array) $reg->extra['before'] ) : array();
			if ( ! empty( $after ) || ! empty( $before ) ) {
				return $tag;
			}
		}

		// Check defer exclusion list.
		$defer_excl = $this->parse_list( $this->settings['exclude_defer_js'] );
		if ( $this->matches_patterns( $src, $defer_excl ) ) {
			return $tag;
		}

		// Add defer attribute.
		return str_replace( ' src=', ' defer src=', $tag );
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

	// ── Delay JS (ob_start pipeline — full HTML processing) ──

	/** @var string|null Cached loader script content. */
	private static $delay_loader_cache = null;

	/**
	 * Mark scripts that must never be combined or delayed.
	 *
	 * Adds `data-no-delay` to script tags whose id or src matches a safelist.
	 * Runs BEFORE minify/combine rewrites the src URL to a hashed cache
	 * filename, so matching is done against the original handle / URL while
	 * they are still identifiable.
	 *
	 * The safelist is built from two sources:
	 *
	 *  1. Auto-detected `wp_localize_script` / `wp_add_inline_script` pairs.
	 *     Any external script whose handle has an associated inline block
	 *     (`id="{handle}-js-before|extra|after"`) is protected, because
	 *     combining or delaying it decouples the external from its inline
	 *     config and breaks the config snapshot at module init time. This
	 *     covers WooCommerce, Elementor, Contact Form 7, Yoast, and any
	 *     other plugin that follows the standard WordPress enqueue pattern.
	 *
	 *  2. Hardcoded patterns for scripts known to have timing constraints
	 *     that aren't expressible via the inline-block convention (e.g.
	 *     jQuery core, where plugin code assumes its presence at parse time).
	 *
	 * Subsequent pipeline stages already honor `data-no-delay`:
	 *   - The add-on combine_js_files() skips any script with data-* attributes.
	 *   - delay_all_scripts() skips any script with data-no-delay.
	 */
	private function mark_preserved_scripts( $html ) {
		// Exact handle names — compared against the {handle} prefix of an
		// extracted id attribute (`id="{handle}-js"`), so substring noise such
		// as `id="xfoo-js-y"` cannot trigger a false positive on `foo`.
		$exact_handles = array(
			// Core jQuery — many plugins assume it's loaded at parse time.
			'jquery',
			'jquery-migrate',
		);

		// URL substring patterns — case-insensitive match against the src
		// attribute value only (not the whole tag), for scripts identified
		// by path rather than enqueue handle.
		$url_substrings = array(
			'/jquery.min.js',
			'/jquery-migrate.min.js',
			// WP Consent API (third-party scripts gate on its readiness).
			'wp-consent-api',
		);

		// Auto-detect external scripts paired with inline localize / add-inline
		// blocks. WordPress outputs these with id="{handle}-js-{before|extra|after}"
		// (either single- or double-quoted). The matched handle is added to
		// $exact_handles so the corresponding external script id gets protected
		// regardless of which quote style the renderer uses.
		if ( preg_match_all(
			'#<script\b[^>]*\bid\s*=\s*["\']([^"\']+)-js-(?:before|extra|after)["\'][^>]*>#i',
			$html,
			$m
		) ) {
			foreach ( array_unique( $m[1] ) as $handle ) {
				$exact_handles[] = $handle;
			}
		}
		$exact_handles = array_values( array_unique( $exact_handles ) );

		return preg_replace_callback(
			'#<script\b[^>]*>#i',
			function ( $tag_match ) use ( $exact_handles, $url_substrings ) {
				$tag = $tag_match[0];

				// Already marked — leave as-is (idempotent). Case-insensitive
				// to tolerate manually authored `DATA-NO-DELAY` variants.
				if ( false !== stripos( $tag, 'data-no-delay' ) ) {
					return $tag;
				}

				// Inline extra/before/after blocks — must execute immediately
				// so the paired external script sees the localized globals.
				if ( preg_match( '#\bid\s*=\s*["\'][^"\']+-js-(?:before|extra|after)["\']#i', $tag ) ) {
					return preg_replace( '#^<script\b#i', '<script data-no-delay', $tag, 1 );
				}

				// Strict id-attribute match: extract the id value, then compare
				// the {handle} prefix exactly. Avoids the substring false-match
				// possible with stripos on the raw tag, and handles both quote
				// styles uniformly.
				if ( preg_match( '#\bid\s*=\s*["\']([^"\']+)["\']#i', $tag, $id_m ) ) {
					$id = $id_m[1];
					if ( strlen( $id ) > 3 && substr( $id, -3 ) === '-js' ) {
						$handle = substr( $id, 0, -3 );
						if ( in_array( $handle, $exact_handles, true ) ) {
							return preg_replace( '#^<script\b#i', '<script data-no-delay', $tag, 1 );
						}
					}
				}

				// URL substring match — scoped to the src attribute value only,
				// so unrelated attributes containing similar text cannot trigger.
				if ( preg_match( '#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $tag, $src_m ) ) {
					$src = $src_m[1];
					foreach ( $url_substrings as $needle ) {
						if ( false !== stripos( $src, $needle ) ) {
							return preg_replace( '#^<script\b#i', '<script data-no-delay', $tag, 1 );
						}
					}
				}

				return $tag;
			},
			$html
		);
	}

	/**
	 * Delay every external JavaScript via HTML pipeline processing.
	 *
	 * Transforms external <script src="..."> tags so they only execute after user
	 * interaction. Inline scripts (no src) are intentionally left alone — they
	 * usually carry localize_script payloads / consent config / widget setup that
	 * the external scripts depend on, so delaying them would break dependencies.
	 *
	 * Compared to filter_defer_script (which only sees wp_enqueue_script handles),
	 * this captures every external <script> in the HTML output, including
	 * theme-template-injected and CDN-hosted ones.
	 */
	private function delay_all_scripts( $html ) {
		$s = $this->settings;

		// Build exclusion patterns.
		$excl = $this->parse_list( $s['exclude_delay_js'] );
		$excl = array_merge( $excl, $this->get_delay_preset_patterns() );
		// Built-in exclusions: URL patterns for scripts that must never be
		// delayed (the HTML pipeline doesn't see WP enqueue handles).
		// jQuery and core dependencies.
		$excl[] = 'jquery';
		$excl[] = 'jquery-migrate';
		$excl[] = 'jquery.min.js';
		$excl[] = 'jquery-migrate.min.js';
		// Divi theme.
		$excl[] = 'et-builder';
		$excl[] = 'scripts.min.js';
		// WP Consent API.
		$excl[] = 'wp-consent-api';
		$excl[] = 'consent_api';
		// Cocoon theme.
		$excl[] = 'cocoon_localize_script_options';

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

		// Transform script tags. Track how many were actually delayed.
		$delayed_count = 0;
		$html = preg_replace_callback(
			'#<\s*script(?<attr>\s[^>]*?)?>(?<content>.*?)?<\s*/\s*script\s*>#ims',
			function( $m ) use ( $excl, $safe_mode, $skip_types, &$delayed_count ) {
				$full = $m[0];
				$attr = isset( $m['attr'] ) ? $m['attr'] : '';
				$content = isset( $m['content'] ) ? $m['content'] : '';

				// Skip if already delayed.
				if ( false !== strpos( $attr, 'data-pc-delayed' ) ) return $full;
				if ( false !== strpos( $attr, 'pc-delay' ) ) return $full;

				// Skip data-no-delay (case-insensitive — front-stage marker
				// that may be authored manually in any case).
				if ( false !== stripos( $attr, 'data-no-delay' ) ) return $full;

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

				// Never delay inline scripts (no src attribute).
				// Inline scripts include wp_localize_script config (et_pb_custom,
				// consent_api, etc.) and wp_add_inline_script output. These must
				// execute immediately to set up variables that their corresponding
				// external scripts depend on.
				// Only external scripts (with src) are delayed.
				if ( empty( $src ) ) {
					return $full;
				}

				// Check exclusion patterns (match against src URL).
				foreach ( $excl as $pattern ) {
					if ( false !== stripos( $src, $pattern ) ) {
						return $full;
					}
				}

				// Safe mode: skip local scripts.
				if ( $safe_mode && $src && $this->is_local_url( $src ) ) {
					return $full;
				}

				// Skip the delay loader itself. Use a regex to tolerate either
				// quote style if a downstream transformer rewrites quotes.
				if ( preg_match( '#\bid\s*=\s*["\']pc-delay-loader["\']#i', $attr ) ) return $full;
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
				$delayed_count++;

				return '<script' . $attr . '>' . $content . '</script>';
			},
			$html
		);

		// Restore SVG.
		if ( $svg_placeholders ) {
			$html = str_replace( array_keys( $svg_placeholders ), array_values( $svg_placeholders ), $html );
		}

		// Only inject loader if at least one script was actually delayed.
		// Without delayed scripts, the loader's DOMContentLoaded interception
		// breaks jQuery's ready mechanism for no benefit.
		$loader = ( $delayed_count > 0 ) ? $this->get_delay_loader_script() : '';
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

		// Defer JS is handled by filter_defer_script() via script_loader_tag.
		// Delay JS is handled by delay_all_scripts() in the HTML pipeline.

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

			// Skip already-delayed scripts (processed by delay_all_scripts).
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
		// Ultra-conservative JS minification: only collapse blank lines
		// and trim trailing whitespace. No comment removal at all.
		//
		// Regex-based JS minification is fundamentally unsafe because
		// regex literals (/pattern/) are indistinguishable from division
		// operators and comment starts (// and /*) without a full JS parser.
		// Gzip compression handles the bulk of size reduction anyway.
		$js = preg_replace( '#[\t ]+$#m', '', $js );
		$js = preg_replace( '#\n{3,}#', "\n\n", $js );
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

		$hash = md5( $url . '|' . filemtime( $path ) . '|' . filesize( $path ) );
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
		if ( ! wp_mkdir_p( dirname( $out ) ) ) {
			return false;
		}
		if ( ! self::atomic_write( $out, $js ) ) {
			return false;
		}

		return $out_url;
	}

	// ── Query String Removal ─────────────────────────────────

	private function strip_query_strings( $html ) {
		// Strip ?ver= / ?v= parameters from local CSS/JS URLs regardless of position.
		// Handles: only param, first param, middle param, last param.
		return preg_replace_callback(
			'#((?:href|src)=["\'])([^"\']+\.(?:css|js))\?([^"\']+)(["\'])#i',
			function( $m ) {
				$url   = $m[2];
				$query = $m[3];

				// Only strip from local URLs. The previous "starts with /" shortcut
				// also accepted protocol-relative `//cdn.example.com/foo.js`, which
				// is external. Defer to is_local_url() — it handles root-relative,
				// protocol-relative, and absolute (any scheme) via host comparison.
				if ( ! $this->is_local_url( $url ) ) {
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
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

		// search_dirs below also looks under fonts/ (add-on local-font cache).
		// type_map must cover those extensions or font URLs 404 with no
		// indication that the file actually existed on disk.
		$type_map = array(
			'css'   => 'text/css',
			'js'    => 'application/javascript',
			'woff2' => 'font/woff2',
			'woff'  => 'font/woff',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
			'eot'   => 'application/vnd.ms-fontobject',
		);
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
		if ( ! self::path_within( $real, $real_cache ) ) {
			status_header( 403 );
			exit;
		}

		// charset only applies to text payloads; appending it to binary font
		// MIME types is technically harmless but noisy. Limit to css/js.
		$is_text     = in_array( $ext, array( 'css', 'js' ), true );
		$content_type = $type_map[ $ext ] . ( $is_text ? '; charset=UTF-8' : '' );
		header( 'Content-Type: ' . $content_type );
		// `immutable` honors the global Immutable Cache-Control toggle even though
		// the /_pc-static/ filename is content-hashed (so immutable is technically
		// always safe). Users who turn the toggle off expect no immutable directive
		// anywhere in the response set.
		$immutable_suffix = ! empty( $this->settings['cache_control_immutable'] ) ? ', immutable' : '';
		header( 'Cache-Control: public, max-age=31536000' . $immutable_suffix );
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
		// Root-relative path (/foo, /wp-content/...) — always local.
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			return true;
		}
		// Host + port comparison handles all the cases below uniformly:
		// - https://example.com/...        (absolute, matching scheme)
		// - http://example.com/...         (absolute, mismatched scheme on HTTPS site)
		// - //example.com/...              (protocol-relative)
		// Port matters: `https://example.com:5173/app.js` (Vite/dev server on
		// alt port) is technically same-host but a different origin we should
		// not treat as local — minifying / stripping cache-busters from it
		// would break the dev pipeline.
		$home_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$home_scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
		$home_port   = wp_parse_url( home_url(), PHP_URL_PORT );
		$url_host    = wp_parse_url( $url, PHP_URL_HOST );
		$url_scheme  = wp_parse_url( $url, PHP_URL_SCHEME ) ?: $home_scheme;
		$url_port    = wp_parse_url( $url, PHP_URL_PORT );

		// Collapse the scheme's default port to "no port" so an explicit
		// `:443` on https or `:80` on http compares equal to the bare host
		// form. Without this, themes/plugins that emit
		// `https://example.com:443/app.css` would be falsely marked
		// external on a site whose home_url is `https://example.com/`.
		$normalize_port = function ( $port, $scheme ) {
			$port = (int) $port;
			if ( 0 === $port ) {
				return 0;
			}
			if ( 'https' === $scheme && 443 === $port ) {
				return 0;
			}
			if ( 'http' === $scheme && 80 === $port ) {
				return 0;
			}
			return $port;
		};
		$home_port_n = $normalize_port( $home_port, $home_scheme );
		$url_port_n  = $normalize_port( $url_port, $url_scheme );

		if ( $home_host && $url_host && 0 === strcasecmp( $home_host, $url_host )
			&& $home_port_n === $url_port_n ) {
			return true;
		}
		return false;
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
		if ( $real && self::path_within( $real, realpath( ABSPATH ) ) ) {
			return $real;
		}

		return false;
	}

	/**
	 * Directory-boundary "is $candidate inside $root" check.
	 *
	 * A naked strpos prefix match accepts `/var/www/site2` when $root is
	 * `/var/www/site` because realpath strips trailing slashes. Append the
	 * separator on both sides so the comparison only matches at a directory
	 * boundary.
	 */
	public static function path_within( $candidate, $root ) {
		if ( ! $candidate || ! $root ) {
			return false;
		}
		$root      = rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		$candidate = rtrim( $candidate, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return 0 === strpos( $candidate, $root );
	}
}
