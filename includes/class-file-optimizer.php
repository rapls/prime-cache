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

		// Cron handlers for async external resource fetching.
		add_action( 'prime_cache_refresh_local_analytics', array( $this, 'cron_refresh_local_analytics' ) );
		add_action( 'prime_cache_refresh_google_fonts', array( $this, 'cron_refresh_google_fonts' ) );
		add_action( 'prime_cache_cleanup_gf_options', array( $this, 'cleanup_stale_gf_options' ) );

		// Schedule daily cleanup of stale Google Fonts options.
		if ( ! wp_next_scheduled( 'prime_cache_cleanup_gf_options' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'prime_cache_cleanup_gf_options' );
		}

		// Flush rewrite rules on next request after setting toggle (deferred from save).
		if ( get_option( 'prime_cache_flush_rewrite' ) ) {
			delete_option( 'prime_cache_flush_rewrite' );
			add_action( 'init', function() { flush_rewrite_rules( false ); }, 99 );
		}

		// Defer/Delay JS via WordPress filter (no ob_start needed).
		// This avoids buffering the entire HTML which causes CLS on
		// servers with Nginx-level caching (e.g. Xserver Xアクセラレータ).
		if ( ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() ) {
			if ( ! empty( $this->settings['defer_js'] ) ) {
				add_filter( 'script_loader_tag', array( $this, 'filter_defer_script' ), 10, 3 );
			}
			// Delay JS: change script type to prevent execution until user interaction.
			// Works via filter (no ob_start) — compatible with Xserver Xアクセラレータ.
			if ( ! empty( $this->settings['delay_js'] ) ) {
				add_filter( 'script_loader_tag', array( $this, 'filter_delay_script' ), 11, 3 );
				add_action( 'wp_footer', array( $this, 'print_delay_loader' ), 999 );
			}
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
	private function get_normalized_cache_uri() {
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
	 * Defer JS no longer needs ob_start — it uses script_loader_tag filter.
	 */
	private function should_optimize_html() {
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'DOING_AUTOSAVE' ) || defined( 'XMLRPC_REQUEST' ) || defined( 'REST_REQUEST' ) ) {
			return false;
		}

		$s = $this->settings;
		// Free: minify HTML/CSS/JS, remove comments, strip query strings.
		// Pro features use apply_filters hooks — they register via the pipeline independently.
		return $s['minify_html'] || $s['remove_html_comments'] || $s['minify_css'] || $s['minify_js'] || $s['remove_query_strings']
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

		// CSS optimizations (Free: minify only).
		if ( $s['minify_css'] ) {
			$html = $this->process_css( $html );
		}

		// Pro hook: CSS combine, async, critical CSS.
		$html = apply_filters( 'prime_cache_process_css', $html, $s );

		// JS optimizations (Free: minify only via ob_start pipeline).
		if ( $s['minify_js'] ) {
			$html = $this->process_js( $html );
		}

		// Pro hook: JS combine.
		$html = apply_filters( 'prime_cache_process_js', $html, $s );

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
		$excludes = $this->parse_list( $s['exclude_css'] );

		// Find all <link rel="stylesheet"> tags.
		if ( ! preg_match_all( '#<link\s[^>]*rel=["\']stylesheet["\'][^>]*/?\s*>#i', $html, $matches, PREG_SET_ORDER ) ) {
			return $html;
		}

		foreach ( $matches as $match ) {
			$tag = $match[0];
			if ( ! preg_match( '#href=["\']([^"\']+)["\']#i', $tag, $href_match ) ) {
				continue;
			}
			$href = $href_match[1];
			if ( $this->matches_patterns( $href, $excludes ) ) {
				continue;
			}

			// Minify individual CSS files (skip already minified .min.css).
			if ( $s['minify_css'] && $this->is_local_url( $href ) && false === strpos( $href, '.min.css' ) ) {
				$minified_url = $this->minify_css_file( $href );
				if ( $minified_url ) {
					$html = str_replace( $tag, str_replace( $href, $minified_url, $tag ), $html );
				}
			}
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

	public function combine_css_files( $html, $entries ) {
		$contents = '';
		$hash_src = '';
		$first_tag = null;

		foreach ( $entries as $entry ) {
			$path = $this->url_to_path( $entry['href'] );
			if ( ! $path || ! is_readable( $path ) ) {
				continue;
			}

			$css = file_get_contents( $path ); // phpcs:ignore
			if ( false === $css ) {
				continue;
			}

			$css = $this->rebase_css_urls( $css, $path );
			if ( $this->settings['minify_css'] ) {
				$css = $this->minify_css_content( $css );
			}

			$contents .= "/* {$entry['href']} */\n" . $css . "\n";
			$hash_src .= $entry['href'] . filemtime( $path );

			if ( null === $first_tag ) {
				$first_tag = $entry['tag'];
			} else {
				$html = str_replace( $entry['tag'], '', $html );
			}
		}

		if ( empty( $contents ) || null === $first_tag ) {
			return $html;
		}

		$hash = md5( $hash_src );
		$out  = $this->cache_dir . 'css/' . $hash . '.css';
		$out_url = $this->cache_url . 'css/' . $hash . '.css';

		wp_mkdir_p( dirname( $out ) );
		self::atomic_write( $out, $contents );

		$combined_tag = '<link rel="stylesheet" href="' . esc_url( $out_url ) . '">';
		$pos = strpos( $html, $first_tag ); if ( false !== $pos ) { $html = substr_replace( $html, $combined_tag, $pos, strlen( $first_tag ) ); }

		return $html;
	}

	public function async_css( $html, $excludes ) {
		$critical = trim( $this->settings['critical_css'] );

		// Inject critical CSS.
		if ( $critical ) {
			$html = str_replace( '</head>', '<style id="pc-critical-css">' . $this->minify_css_content( $critical ) . '</style>' . "\n</head>", $html );
		}

		// Convert stylesheet links to async loading.
		$html = preg_replace_callback( '#<link\s([^>]*rel=["\']stylesheet["\'][^>]*)(/?\s*>)#i', function( $m ) use ( $excludes ) {
			$attrs = $m[1];
			if ( preg_match( '#href=["\']([^"\']+)["\']#i', $attrs, $href_m ) ) {
				if ( $this->matches_patterns( $href_m[1], $excludes ) ) {
					return $m[0];
				}
			}
			// media="print" onload="this.media='all'" pattern.
			$attrs = preg_replace( '#media=["\'][^"\']*["\']#i', '', $attrs );
			return '<link ' . $attrs . ' media="print" onload="this.media=\'all\'"' . $m[2]
				. '<noscript>' . $m[0] . '</noscript>';
		}, $html );

		return $html;
	}

	// ── Remove Unused CSS ────────────────────────────────────

	/**
	 * Remove CSS rules that don't match any element in the current page HTML.
	 *
	 * Parses each local stylesheet, extracts selectors, checks them against
	 * the page DOM, and rebuilds CSS with only matched rules.
	 */
	public function remove_unused_css( $html ) {
		$safelist = $this->parse_list( $this->settings['ucss_safelist'] );
		$excludes = $this->parse_list( $this->settings['exclude_css'] );

		// Build a map of selectors present in the HTML.
		$body_html = $html;

		if ( ! preg_match_all( '#<link\s[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\'][^>]*/?\s*>#i', $html, $links, PREG_SET_ORDER ) ) {
			return $html;
		}

		foreach ( $links as $link ) {
			$tag  = $link[0];
			$href = $link[1];

			if ( $this->matches_patterns( $href, $excludes ) || ! $this->is_local_url( $href ) ) {
				continue;
			}

			$path = $this->url_to_path( $href );
			if ( ! $path || ! is_readable( $path ) ) {
				continue;
			}

			// UCSS cache key: aligned with page cache query normalization.
			// Skip UCSS for requests with unknown query params (page cache wouldn't cache these).
			$ucss_uri = $this->get_normalized_cache_uri();
			if ( false === $ucss_uri ) {
				continue; // Unknown query — don't create/use UCSS cache.
			}
			$hash     = md5( 'ucss_' . $href . filemtime( $path ) . $ucss_uri );
			$out     = $this->cache_dir . 'ucss/' . $hash . '.css';
			$out_url = $this->cache_url . 'ucss/' . $hash . '.css';

			if ( ! file_exists( $out ) ) {
				$css = file_get_contents( $path ); // phpcs:ignore
				if ( false === $css ) {
					continue;
				}

				$css = $this->rebase_css_urls( $css, $path );
				$cleaned = $this->filter_used_rules( $css, $body_html, $safelist );

				wp_mkdir_p( dirname( $out ) );
				self::atomic_write( $out, $cleaned );
			}

			$html = str_replace( $href, $out_url, $html );
		}

		return $html;
	}

	/**
	 * Filter CSS to keep only rules whose selectors match something in the HTML.
	 */
	public function filter_used_rules( $css, $html, $safelist ) {
		// Remove CSS comments.
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );

		// Split into rule blocks: selector { ... }
		// This handles simple cases; deeply nested @media queries are preserved.
		$output = '';

		// Preserve @media, @font-face, @keyframes, @import blocks entirely.
		$css = preg_replace_callback( '#(@(?:media|supports)[^{]*\{)((?:[^{}]*\{[^}]*\})*\s*)\}#si', function( $m ) use ( $html, $safelist, &$output ) {
			$inner = $this->filter_used_rules( $m[2], $html, $safelist );
			if ( ! empty( trim( $inner ) ) ) {
				$output .= $m[1] . $inner . "}\n";
			}
			return '';
		}, $css );

		// Preserve @font-face, @keyframes, @import unconditionally.
		$css = preg_replace_callback( '#@(font-face|keyframes|import)[^{]*(\{[^}]*(\{[^}]*\}[^}]*)?\}|[^;]*;)#si', function( $m ) use ( &$output ) {
			$output .= $m[0] . "\n";
			return '';
		}, $css );

		// Process remaining selector { property } blocks.
		preg_match_all( '#([^{}]+)\{([^}]*)\}#s', $css, $rules, PREG_SET_ORDER );

		foreach ( $rules as $rule ) {
			$selectors_raw = trim( $rule[1] );
			$body          = $rule[2];

			if ( empty( $selectors_raw ) || empty( trim( $body ) ) ) {
				continue;
			}

			$selectors = $this->split_selectors( $selectors_raw );
			$keep      = false;

			foreach ( $selectors as $sel ) {
				if ( $this->selector_used_in_html( $sel, $html, $safelist ) ) {
					$keep = true;
					break;
				}
			}

			if ( $keep ) {
				$output .= $selectors_raw . '{' . $body . "}\n";
			}
		}

		return $output;
	}

	/**
	 * Check if a CSS selector matches anything in the page HTML.
	 */
	/**
	 * Split CSS selector list by top-level commas only.
	 *
	 * Respects parentheses depth so :is(.a, .b) and :not(.x, .y) are not
	 * broken apart by the comma inside the pseudo-class.
	 *
	 * @param string $raw Raw selector string.
	 * @return array Individual selectors.
	 */
	public function split_selectors( $raw ) {
		$result   = array();
		$depth    = 0;
		$start    = 0;
		$len      = strlen( $raw );
		$in_quote = false;
		$quote_ch = '';

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $raw[ $i ];

			// Handle escape sequences (e.g. \, inside selectors).
			if ( '\\' === $ch ) {
				$i++; // Skip next character.
				continue;
			}

			// Handle quoted strings (attribute selectors like [data-x="a,b"]).
			if ( $in_quote ) {
				if ( $ch === $quote_ch ) {
					$in_quote = false;
				}
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$in_quote = true;
				$quote_ch = $ch;
				continue;
			}

			if ( '(' === $ch || '[' === $ch ) {
				$depth++;
			} elseif ( ')' === $ch || ']' === $ch ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ',' === $ch && 0 === $depth ) {
				$sel = trim( substr( $raw, $start, $i - $start ) );
				if ( '' !== $sel ) {
					$result[] = $sel;
				}
				$start = $i + 1;
			}
		}
		$last = trim( substr( $raw, $start ) );
		if ( '' !== $last ) {
			$result[] = $last;
		}

		return $result;
	}

	public function selector_used_in_html( $selector, $html, $safelist ) {
		// Always keep safelisted patterns.
		foreach ( $safelist as $safe ) {
			if ( false !== strpos( $selector, $safe ) ) {
				return true;
			}
		}

		// Keep pseudo-element/class selectors (::before, :hover, etc.) — check base selector.
		$base = preg_replace( '#::?[a-z\-]+(\(.*?\))?$#i', '', $selector );
		$base = trim( $base );
		if ( empty( $base ) || '*' === $base ) {
			return true; // Universal or pseudo-only — keep.
		}

		// Extract tag, class, id from the last segment of the selector.
		$parts = preg_split( '#[\s>+~]+#', $base );
		$last  = end( $parts );

		// ID selector: #foo.
		if ( preg_match( '#\#([a-zA-Z0-9_-]+)#', $last, $id_m ) ) {
			return false !== strpos( $html, 'id="' . $id_m[1] . '"' ) || false !== strpos( $html, "id='" . $id_m[1] . "'" );
		}

		// Class selector: .foo.
		if ( preg_match( '#\.([a-zA-Z0-9_-]+)#', $last, $cls_m ) ) {
			return (bool) preg_match( '#class=["\'][^"\']*\b' . preg_quote( $cls_m[1], '#' ) . '\b#i', $html );
		}

		// Tag selector.
		$tag = preg_replace( '#[\[\.#:].*#', '', $last );
		if ( $tag && preg_match( '#^[a-zA-Z][a-zA-Z0-9]*$#', $tag ) ) {
			return false !== stripos( $html, '<' . $tag );
		}

		// Attribute selectors or complex — keep to be safe.
		return true;
	}

	// ── Auto Critical CSS ────────────────────────────────────

	/**
	 * Auto-extract critical CSS by collecting styles for above-the-fold elements.
	 *
	 * Generates a per-URL critical CSS file by extracting rules that match common
	 * above-the-fold selectors (body, header, nav, hero, h1, etc.).
	 */
	public function auto_critical_css( $html ) {
		// Skip if manual critical CSS is already provided.
		if ( ! empty( trim( $this->settings['critical_css'] ) ) ) {
			return $html;
		}

		// Normalized URI aligned with page cache query handling.
		// Skip CCSS caching for requests with unknown query params.
		$request_uri = $this->get_normalized_cache_uri();
		if ( false === $request_uri ) {
			return $html; // Unknown query — don't generate/use cached critical CSS.
		}

		// Phase 1: Collect ONLY metadata for cache key (no full CSS reads yet).
		$css_filetimes  = '';
		$css_link_paths = array();
		if ( preg_match_all( '#<link\s[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\'][^>]*/?\s*>#i', $html, $links ) ) {
			foreach ( $links[1] as $href ) {
				if ( ! $this->is_local_url( $href ) ) {
					continue;
				}
				$path = $this->url_to_path( $href );
				if ( $path && is_readable( $path ) ) {
					$css_filetimes .= filemtime( $path ) . '|';
					$css_link_paths[] = $path;
				}
			}
		}

		// Lightweight inline <style> fingerprint for cache key.
		// Only scan <head> section for inline styles (above-the-fold relevant).
		// Body styles don't affect critical CSS selection and are much larger on builder pages.
		$inline_fingerprint   = '';
		$inline_style_matches = array();
		$head_end = stripos( $html, '</head>' );
		$head_html = $head_end ? substr( $html, 0, $head_end ) : $html;
		if ( false !== stripos( $head_html, '<style' ) ) {
			if ( preg_match_all( '#<style[^>]*>(.*?)</style>#si', $head_html, $styles ) ) {
				$inline_style_matches = $styles[1];
				foreach ( $inline_style_matches as $inline ) {
					$inline_fingerprint .= crc32( $inline ) . ':' . strlen( $inline ) . '|';
				}
			}
		}

		$inline_hash = $inline_fingerprint ? md5( $inline_fingerprint ) : '';
		$hash        = md5( 'ccss_' . $request_uri . $css_filetimes . $inline_hash );
		$ccss_file   = $this->cache_dir . 'ccss/' . $hash . '.css';

		// Check cache — on HIT, skip expensive full CSS reads.
		if ( file_exists( $ccss_file ) ) {
			$critical = file_get_contents( $ccss_file ); // phpcs:ignore
			if ( $critical ) {
				$this->settings['critical_css'] = $critical;
			}
			return $html;
		}

		// Phase 2: Cache MISS — now read full CSS for critical extraction.
		$all_css = '';
		foreach ( $css_link_paths as $path ) {
			$css = file_get_contents( $path ); // phpcs:ignore
			if ( $css ) {
				$all_css .= $this->rebase_css_urls( $css, $path ) . "\n";
			}
		}
		// Concatenate ALL inline CSS (head + body) for critical extraction.
		// Head styles were captured in fingerprint phase; now also grab body styles.
		if ( preg_match_all( '#<style[^>]*>(.*?)</style>#si', $html, $all_styles ) ) {
			foreach ( $all_styles[1] as $inline ) {
				$all_css .= $inline . "\n";
			}
		}

		if ( empty( $all_css ) ) {
			return $html;
		}

		// Extract rules matching above-the-fold selectors.
		$atf_patterns = array(
			'html', 'body', ':root',
			'header', 'nav', '.nav', '#nav', '.menu', '.navbar', '.site-header',
			'h1', 'h2', '.hero', '.banner', '.masthead', '.jumbotron',
			'.logo', '.site-title', '.site-branding',
			'.container', '.wrapper', '.row', '.col',
			'img', 'figure', '.wp-block-image',
			'a', 'p', 'ul', 'ol', 'li',
			'@font-face', '@keyframes',
		);

		$all_css  = preg_replace( '#/\*.*?\*/#s', '', $all_css );
		$critical = '';

		// Keep @font-face and @keyframes unconditionally.
		preg_match_all( '#@(font-face|keyframes)[^{]*\{[^}]*(\{[^}]*\}[^}]*)?\}#si', $all_css, $at_rules );
		if ( ! empty( $at_rules[0] ) ) {
			$critical .= implode( "\n", $at_rules[0] ) . "\n";
		}

		// Extract matching rules.
		preg_match_all( '#([^{}]+)\{([^}]*)\}#s', $all_css, $rules, PREG_SET_ORDER );
		foreach ( $rules as $rule ) {
			$selector = trim( $rule[1] );
			foreach ( $atf_patterns as $pattern ) {
				if ( false !== stripos( $selector, $pattern ) ) {
					$critical .= $selector . '{' . $rule[2] . "}\n";
					break;
				}
			}
		}

		$critical = $this->minify_css_content( $critical );

		// Cap at ~50KB to avoid bloating the HTML.
		if ( strlen( $critical ) > 51200 ) {
			// Truncate at the last complete rule (closing brace) within the limit.
			$critical = substr( $critical, 0, 51200 );
			$last_brace = strrpos( $critical, '}' );
			if ( false !== $last_brace ) {
				$critical = substr( $critical, 0, $last_brace + 1 );
			}
		}

		wp_mkdir_p( dirname( $ccss_file ) );
		self::atomic_write( $ccss_file, $critical );

		$this->settings['critical_css'] = $critical;

		return $html;
	}

	// ── Defer JS (filter-based, no ob_start) ────────────────

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
	 * jQuery and jQuery Migrate must load synchronously because
	 * Cocoon and many plugins use inline jQuery code ($(document).ready etc.)
	 * that executes immediately after the script tag.
	 */
	private static $defer_never = array(
		'jquery-core',
		'jquery',
		'jquery-migrate',
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

		// Check delay exclusion list.
		$delay_excl = $this->parse_list( $this->settings['exclude_delay_js'] );
		if ( $this->matches_patterns( $src, $delay_excl ) ) {
			return $tag;
		}

		// Replace type and src to prevent execution.
		$tag = preg_replace( '#type=["\'][^"\']*["\']#i', 'type="pc-delay/js"', $tag );
		if ( false === strpos( $tag, 'type=' ) ) {
			$tag = str_replace( '<script ', '<script type="pc-delay/js" ', $tag );
		} else {
			$tag = preg_replace( '#type=["\'][^"\']*["\']#i', 'type="pc-delay/js"', $tag );
		}
		$tag = str_replace( ' src=', ' data-pc-delayed data-src=', $tag );

		return $tag;
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

	// ── JS (ob_start pipeline) ───────────────────────────────

	private function process_js( $html ) {
		$s = $this->settings;
		$excludes       = $this->parse_list( $s['exclude_js'] );
		$inline_excl    = $this->parse_list( $s['exclude_inline_js'] );
		$defer_excl     = $this->parse_list( $s['exclude_defer_js'] );
		$delay_excl     = $this->parse_list( $s['exclude_delay_js'] );

		// Process <script> tags.
		$html = preg_replace_callback( '#<script\b([^>]*)>(.*?)</script>#si', function( $m ) use ( $s, $excludes, $inline_excl, $defer_excl, $delay_excl ) {
			$attrs   = $m[1];
			$content = $m[2];
			$full    = $m[0];

			$has_src = preg_match( '#src=["\']([^"\']+)["\']#i', $attrs, $src_match );
			$src     = $has_src ? $src_match[1] : '';

			// Skip type="application/ld+json", type="text/template" etc.
			if ( preg_match( '#type=["\'](?!text/javascript|module)[^"\']+["\']#i', $attrs ) ) {
				return $full;
			}

			// Check exclusions.
			if ( $has_src && $this->matches_patterns( $src, $excludes ) ) {
				return $full;
			}
			if ( ! $has_src && $this->matches_inline_patterns( $content, $inline_excl ) ) {
				return $full;
			}

			// Skip data-no-defer and data-no-delay attributes.
			$no_defer = false !== strpos( $attrs, 'data-no-defer' );
			$no_delay = false !== strpos( $attrs, 'data-no-delay' );

			// Delay JS execution.
			if ( $s['delay_js'] && ! $no_delay && ! $this->matches_patterns( $src ?: $content, $delay_excl ) ) {
				// Safe mode: only skip critical WP infrastructure scripts that
				// break if delayed (e.g. wp-hooks, wp-i18n used by CF7/block editor).
				// All other scripts (including theme JS) are delayed.
				if ( ! empty( $s['delay_js_safe_mode'] ) && $has_src ) {
					// Scripts that must NOT be delayed (break core functionality).
					$critical_patterns = array(
						'/wp-includes/js/dist/hooks',
						'/wp-includes/js/dist/i18n',
						'/wp-includes/js/dist/element',
						'/wp-includes/js/dist/dom-ready',
						'/wp-includes/js/jquery/jquery.min',
						'/wp-includes/js/jquery/jquery.js',
					);
					$is_critical = false;
					foreach ( $critical_patterns as $pat ) {
						if ( false !== strpos( $src, $pat ) ) {
							$is_critical = true;
							break;
						}
					}
					if ( ! $is_critical ) {
						return $this->delay_script( $attrs, $content, $has_src, $src );
					}
				} else {
					return $this->delay_script( $attrs, $content, $has_src, $src );
				}
			}

			// Defer JS.
			if ( $s['defer_js'] && $has_src && ! $no_defer && ! $this->matches_patterns( $src, $defer_excl ) ) {
				if ( false === strpos( $attrs, 'defer' ) && false === strpos( $attrs, 'async' ) ) {
					$attrs .= ' defer';
					return '<script' . $attrs . '>' . $content . '</script>';
				}
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

		// Combine JS files.
		if ( $s['combine_js'] && ! $s['delay_js'] ) {
			$html = $this->combine_js_files( $html, $excludes );
		}

		// Inject delay JS loader script.
		if ( $s['delay_js'] ) {
			$html = $this->inject_delay_loader( $html );
		}

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
	public function combine_js_files( $html, $excludes ) {
		if ( ! preg_match_all( '#<script\s[^>]*src=["\']([^"\']+)["\'][^>]*>\s*</script>#i', $html, $matches, PREG_SET_ORDER ) ) {
			return $html;
		}

		$to_combine = array();
		foreach ( $matches as $match ) {
			$tag = $match[0];
			$src = $match[1];

			if ( ! $this->is_local_url( $src ) ) continue;
			if ( $this->matches_patterns( $src, $excludes ) ) continue;
			if ( false !== strpos( $tag, 'async' ) || false !== strpos( $tag, 'defer' ) ) continue;
			if ( preg_match( '#type=["\'](?!text/javascript|module)[^"\']+["\']#i', $tag ) ) continue;

			$to_combine[] = array( 'tag' => $tag, 'src' => $src );
		}

		if ( count( $to_combine ) < 2 ) {
			return $html;
		}

		$contents = '';
		$hash_src = '';
		$first_tag = null;

		foreach ( $to_combine as $entry ) {
			$path = $this->url_to_path( $entry['src'] );
			if ( ! $path || ! is_readable( $path ) ) continue;

			$js = file_get_contents( $path ); // phpcs:ignore
			if ( false === $js ) continue;

			if ( $this->settings['minify_js'] && ! preg_match( '#\.min\.js$#', $path ) ) {
				$js = $this->minify_js_content( $js );
			}

			$contents .= "/* " . basename( $entry['src'] ) . " */\n" . $js . ";\n";
			$hash_src .= $entry['src'] . filemtime( $path );

			if ( null === $first_tag ) {
				$first_tag = $entry['tag'];
			} else {
				$html = str_replace( $entry['tag'], '', $html );
			}
		}

		if ( empty( $contents ) || null === $first_tag ) {
			return $html;
		}

		$hash = md5( $hash_src );
		$out  = $this->cache_dir . 'js/' . $hash . '.js';
		$out_url = $this->cache_url . 'js/' . $hash . '.js';

		wp_mkdir_p( dirname( $out ) );
		self::atomic_write( $out, $contents );

		$combined_tag = '<script src="' . esc_url( $out_url ) . '"></script>';
		$pos = strpos( $html, $first_tag ); if ( false !== $pos ) { $html = substr_replace( $html, $combined_tag, $pos, strlen( $first_tag ) ); }

		return $html;
	}

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

	private function delay_script( $attrs, $content, $has_src, $src ) {
		if ( $has_src ) {
			// Replace src with data-pc-src, set type to pc-delay.
			$attrs = str_replace( $src, '', $attrs );
			$attrs = preg_replace( '#src=["\']["\']#i', '', $attrs );
			$attrs = preg_replace( '#type=["\'][^"\']*["\']#i', '', $attrs );
			return '<script type="pc-delay/javascript" data-pc-src="' . esc_attr( $src ) . '"' . $attrs . '></script>';
		}

		// Inline script — replace type.
		$attrs = preg_replace( '#type=["\'][^"\']*["\']#i', '', $attrs );
		return '<script type="pc-delay/javascript"' . $attrs . '>' . $content . '</script>';
	}

	private function inject_delay_loader( $html ) {
		$timeout = (int) $this->settings['delay_js_timeout'];
		$timeout_js = $timeout > 0 ? "setTimeout(run,{$timeout});" : '';

		$loader = <<<JS
<script id="pc-delay-loader">
(function(){
	var done=false;
	function run(){
		if(done)return;done=true;
		document.querySelectorAll('script[type="pc-delay/javascript"]').forEach(function(el){
			var n=document.createElement('script');
			Array.from(el.attributes).forEach(function(a){
				if(a.name==='type')return;
				if(a.name==='data-pc-src'){n.src=a.value;return;}
				n.setAttribute(a.name,a.value);
			});
			if(!n.src&&el.textContent)n.textContent=el.textContent;
			el.parentNode.replaceChild(n,el);
		});
	}
	['mousemove','touchstart','scroll','keydown','click'].forEach(function(e){
		window.addEventListener(e,run,{once:true,passive:true});
	});
	{$timeout_js}
})();
</script>
JS;

		return str_replace( '</body>', $loader . "\n</body>", $html );
	}

	// ── Google Fonts ─────────────────────────────────────────

	public function optimize_google_fonts( $html ) {
		$pattern = '#<link[^>]+href=["\']https?://fonts\.googleapis\.com/css2?\?([^"\']+)["\'][^>]*/?\s*>#i';

		if ( ! preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER ) ) {
			return $html;
		}

		$families = array();
		$first_tag = null;

		foreach ( $matches as $match ) {
			$query = $match[1];
			parse_str( $query, $params );

			if ( isset( $params['family'] ) ) {
				$fams = is_array( $params['family'] ) ? $params['family'] : explode( '|', $params['family'] );
				$families = array_merge( $families, $fams );
			}

			if ( null === $first_tag ) {
				$first_tag = $match[0];
			} else {
				$html = str_replace( $match[0], '', $html );
			}
		}

		if ( empty( $families ) || null === $first_tag ) {
			return $html;
		}

		$families = array_unique( $families );
		$display  = $this->settings['google_fonts_display'] ? '&display=swap' : '';
		$combined = 'https://fonts.googleapis.com/css2?family=' . implode( '&family=', array_map( 'urlencode', $families ) ) . $display;

		$tag = '<link rel="stylesheet" href="' . esc_url( $combined ) . '">';
		$html = str_replace( $first_tag, $tag, $html );

		return $html;
	}

	// ── Self-host Google Fonts ────────────────────────────────

	/**
	 * Download Google Fonts CSS and font files, rewrite URLs to serve locally.
	 *
	 * Flow:
	 * 1. Find all <link> tags pointing to fonts.googleapis.com.
	 * 2. For each unique URL, fetch the CSS (with a modern UA to get woff2).
	 * 3. Parse @font-face src URLs (fonts.gstatic.com), download each font file.
	 * 4. Rewrite the CSS to point to local font file URLs.
	 * 5. Save the rewritten CSS locally.
	 * 6. Replace the original <link> tag with the local CSS URL.
	 */
	public function self_host_google_fonts( $html ) {
		$pattern = '#<link[^>]+href=["\'](?P<url>https?://fonts\.googleapis\.com/css2?\?[^"\']+)["\'][^>]*/?\s*>#i';

		if ( ! preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER ) ) {
			return $html;
		}

		$fonts_dir = $this->cache_dir . 'fonts/';
		$fonts_url = $this->cache_url . 'fonts/';

		wp_mkdir_p( $fonts_dir );

		foreach ( $matches as $match ) {
			$original_tag = $match[0];
			$gf_url       = html_entity_decode( $match['url'] );

			$local_css_url = $this->fetch_and_localize_gf_css( $gf_url, $fonts_dir, $fonts_url );
			if ( ! $local_css_url ) {
				continue;
			}

			$new_tag = '<link rel="stylesheet" href="' . esc_url( $local_css_url ) . '">';
			$html    = str_replace( $original_tag, $new_tag, $html );
		}

		return $html;
	}

	/**
	 * Fetch a Google Fonts CSS file, download its font files, rewrite URLs.
	 *
	 * @param string $gf_url    Google Fonts CSS URL.
	 * @param string $fonts_dir Local directory for font files.
	 * @param string $fonts_url Local URL base for font files.
	 * @return string|false Local CSS URL or false on failure.
	 */
	public function fetch_and_localize_gf_css( $gf_url, $fonts_dir, $fonts_url ) {
		// Use URL hash as cache key so we only fetch once.
		$hash     = md5( $gf_url );
		$css_file = $fonts_dir . $hash . '.css';
		$css_url  = $fonts_url . $hash . '.css';

		// Return cached version if it exists and is less than 30 days old.
		if ( file_exists( $css_file ) && ( time() - filemtime( $css_file ) ) < 30 * DAY_IN_SECONDS ) {
			return $css_url;
		}

		// Schedule async download. Use individual option keys per URL hash to
		// avoid read-modify-write race on a shared pending array.
		$url_hash   = md5( $gf_url );
		$option_key = 'prime_cache_gf_' . $url_hash;
		if ( false === get_option( $option_key ) ) {
			update_option( $option_key, wp_json_encode( array( 'url' => $gf_url, 'created' => time() ) ), false );
			if ( ! wp_next_scheduled( 'prime_cache_refresh_google_fonts' ) ) {
				wp_schedule_single_event( time(), 'prime_cache_refresh_google_fonts' );
			}
		}

		// If local file doesn't exist yet, return false (use original Google URL).
		if ( ! file_exists( $css_file ) ) {
			return false;
		}

		return $css_url;
	}

	/**
	 * Download a single font file to the local cache.
	 *
	 * @param string $remote_url Remote font file URL.
	 * @param string $fonts_dir  Local directory.
	 * @param string $fonts_url  Local URL base.
	 * @return string|false Local URL or false on failure.
	 */
	public function download_font_file( $remote_url, $fonts_dir, $fonts_url ) {
		// Derive a stable filename from the URL.
		$ext  = pathinfo( wp_parse_url( $remote_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: 'woff2';
		$name = md5( $remote_url ) . '.' . $ext;
		$path = $fonts_dir . $name;
		$url  = $fonts_url . $name;

		// Already downloaded.
		if ( file_exists( $path ) ) {
			return $url;
		}

		$response = wp_remote_get( $remote_url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return false;
		}

		self::atomic_write( $path, $body );

		return $url;
	}

	// ── Local Google Analytics ────────────────────────────────

	/**
	 * Download gtag.js / analytics.js and serve locally.
	 */
	public function localize_analytics( $html ) {
		$scripts = array(
			'gtag'      => array( 'https://www.googletagmanager.com/gtag/js', 'gtag.js' ),
			'analytics' => array( 'https://www.google-analytics.com/analytics.js', 'analytics.js' ),
			'gtm'       => array( 'https://www.googletagmanager.com/gtm.js', 'gtm.js' ),
		);

		$cache_dir = $this->cache_dir . 'analytics/';
		$cache_url = $this->cache_url . 'analytics/';

		foreach ( $scripts as $key => $info ) {
			if ( false === strpos( $html, $info[0] ) ) {
				continue;
			}

			$local_path = $cache_dir . $info[1];
			$local_url  = $cache_url . $info[1];

			// Download if missing or older than 24 hours.
			// Use a non-blocking approach: schedule a cron job for the download
			// instead of fetching synchronously during page generation.
			if ( ! file_exists( $local_path ) || ( time() - filemtime( $local_path ) ) > DAY_IN_SECONDS ) {
				if ( ! wp_next_scheduled( 'prime_cache_refresh_local_analytics' ) ) {
					wp_schedule_single_event( time() + 10, 'prime_cache_refresh_local_analytics' );
				}
				// If file doesn't exist at all, skip local replacement — use original URL.
				if ( ! file_exists( $local_path ) ) {
					continue;
				}
			}

			// Replace remote URL with local.
			if ( file_exists( $local_path ) ) {
				$html = preg_replace(
					'#' . preg_quote( $info[0], '#' ) . '[^"\']*#',
					$local_url . '?' . filemtime( $local_path ),
					$html
				);
			}
		}

		return $html;
	}

	/**
	 * Cron handler: refresh local analytics files asynchronously.
	 */
	public function cron_refresh_local_analytics() {
		// Must match the subdirectory used in localize_analytics().
		$cache_dir = $this->cache_dir . 'analytics/';
		$scripts = array(
			'gtag'      => array( 'https://www.googletagmanager.com/gtag/js', 'gtag.js' ),
			'analytics' => array( 'https://www.google-analytics.com/analytics.js', 'analytics.js' ),
			'gtm'       => array( 'https://www.googletagmanager.com/gtm.js', 'gtm.js' ),
		);

		wp_mkdir_p( $cache_dir );

		foreach ( $scripts as $info ) {
			$local_path = $cache_dir . $info[1];
			if ( file_exists( $local_path ) && ( time() - filemtime( $local_path ) ) <= DAY_IN_SECONDS ) {
				continue;
			}
			$response = wp_remote_get( $info[0], array( 'timeout' => 15, 'sslverify' => true ) );
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$body = wp_remote_retrieve_body( $response );
				if ( ! empty( $body ) ) {
					// Atomic write.
					$tmp = $local_path . '.tmp.' . getmypid();
					if ( false !== file_put_contents( $tmp, $body ) ) { // phpcs:ignore
						if ( ! rename( $tmp, $local_path ) ) { // phpcs:ignore
							@unlink( $tmp );
						}
					}
				}
			}
		}
	}

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

	/**
	 * Cron handler: download Google Fonts CSS and font files asynchronously.
	 */
	public function cron_refresh_google_fonts() {
		global $wpdb;
		// Find pending Google Font URL options, excluding those still in backoff.
		$now = time();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT o.option_name, o.option_value,
			        COALESCE(a.option_value, 0) AS attempts
			 FROM {$wpdb->options} o
			 LEFT JOIN {$wpdb->options} a ON a.option_name = CONCAT(o.option_name, '_attempts')
			 LEFT JOIN {$wpdb->options} t ON t.option_name = CONCAT(o.option_name, '_attempts_time')
			 WHERE o.option_name LIKE 'prime\_cache\_gf\_%'
			   AND o.option_name NOT LIKE '%%\_attempts'
			   AND o.option_name NOT LIKE '%%\_time'
			   AND (t.option_value IS NULL OR (%d - CAST(t.option_value AS UNSIGNED)) > CASE
			     WHEN COALESCE(a.option_value, 0) = 0 THEN 0
			     WHEN COALESCE(a.option_value, 0) = 1 THEN 30
			     WHEN COALESCE(a.option_value, 0) = 2 THEN 300
			     ELSE 1800
			   END)
			 ORDER BY attempts ASC, o.option_id ASC
			 LIMIT 20",
			$now
		) );
		if ( empty( $rows ) ) {
			return;
		}

		// Shuffle within same-attempt-count buckets to avoid option_id bias
		// while preserving low-attempts-first priority.
		$buckets = array();
		foreach ( $rows as $row ) {
			$a = isset( $row->attempts ) ? (int) $row->attempts : 0;
			$buckets[ $a ][] = $row;
		}
		ksort( $buckets );
		$rows = array();
		foreach ( $buckets as $bucket ) {
			shuffle( $bucket );
			$rows = array_merge( $rows, $bucket );
		}

		$fonts_dir = $this->cache_dir . 'fonts/';
		$fonts_url = $this->cache_url . 'fonts/';
		wp_mkdir_p( $fonts_dir );

		foreach ( $rows as $row ) {
			$data = json_decode( $row->option_value, true );
			$gf_url = ( is_array( $data ) && isset( $data['url'] ) ) ? $data['url'] : $row->option_value;

			$attempt_key  = $row->option_name . '_attempts';
			$url_attempts = (int) ( $row->attempts ?? get_option( $attempt_key, 0 ) );

			// Drop after 5 failures.
			if ( $url_attempts >= 5 ) {
				delete_option( $row->option_name );
				delete_option( $attempt_key );
				delete_option( $attempt_key . '_time' );
				continue;
			}

			// Backoff is enforced in the SQL WHERE clause — URLs here are ready.
			$ok = $this->fetch_google_font_css( $gf_url, $fonts_dir, $fonts_url );
			if ( false !== $ok ) {
				delete_option( $row->option_name );
				delete_option( $attempt_key );
				delete_option( $attempt_key . '_time' );
			} else {
				update_option( $attempt_key, $url_attempts + 1, false );
				update_option( $attempt_key . '_time', (string) time(), false );
			}
		}

		// Re-schedule if more pending URLs remain.
		$still_pending = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'prime\_cache\_gf\_%' AND option_name NOT LIKE '%\_attempts' AND option_name NOT LIKE '%\_time'"
		);
		if ( $still_pending > 0 && ! wp_next_scheduled( 'prime_cache_refresh_google_fonts' ) ) {
			wp_schedule_single_event( time() + 30, 'prime_cache_refresh_google_fonts' );
		}
	}

	/**
	 * Daily cleanup of stale Google Fonts pending options.
	 *
	 * Deletes pending URLs where:
	 * - created timestamp is older than 24 hours, OR
	 * - last_attempt is older than 24 hours (for legacy entries without created), OR
	 * - no timestamp AND no recent attempt (truly abandoned)
	 */
	public function cleanup_stale_gf_options() {
		global $wpdb;
		$cutoff = time() - DAY_IN_SECONDS;

		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			 WHERE option_name LIKE 'prime\_cache\_gf\_%'
			   AND option_name NOT LIKE '%\_attempts'
			   AND option_name NOT LIKE '%\_time'"
		);

		foreach ( $rows as $row ) {
			$data    = json_decode( $row->option_value, true );
			$created = is_array( $data ) && isset( $data['created'] ) ? (int) $data['created'] : 0;

			// Check last_attempt_time for legacy entries without created timestamp.
			$last_attempt = (int) get_option( $row->option_name . '_attempts_time', 0 );

			$is_stale = false;
			if ( $created > 0 && $created < $cutoff ) {
				$is_stale = true; // Created > 24h ago.
			} elseif ( 0 === $created && $last_attempt > 0 && $last_attempt < $cutoff ) {
				$is_stale = true; // Legacy format, last attempt > 24h ago.
			} elseif ( 0 === $created && 0 === $last_attempt ) {
				// One-time migration: stamp created=now. After this, the entry has
				// created > 0 so future cleanups use the created < cutoff path.
				// If still unprocessed after 24h, the next cleanup deletes it.
				$url = ( is_array( $data ) && isset( $data['url'] ) ) ? $data['url'] : $row->option_value;
				update_option( $row->option_name, wp_json_encode( array(
					'url'     => $url,
					'created' => time(),
				) ), false );
			}

			if ( $is_stale ) {
				delete_option( $row->option_name );
				delete_option( $row->option_name . '_attempts' );
				delete_option( $row->option_name . '_attempts_time' );
			}
		}

		// If pending entries remain after cleanup, ensure the processing cron is scheduled.
		$still = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'prime\_cache\_gf\_%' AND option_name NOT LIKE '%\_attempts' AND option_name NOT LIKE '%\_time'"
		);
		if ( $still > 0 && ! wp_next_scheduled( 'prime_cache_refresh_google_fonts' ) ) {
			wp_schedule_single_event( time() + 60, 'prime_cache_refresh_google_fonts' );
		}
	}

	public function fetch_google_font_css( $gf_url, $fonts_dir, $fonts_url ) {
		$hash     = md5( $gf_url );
		$css_file = $fonts_dir . $hash . '.css';

		$response = wp_remote_get( $gf_url, array(
			'timeout'    => 15,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$css = wp_remote_retrieve_body( $response );
		if ( empty( $css ) ) {
			return false;
		}

		$css = preg_replace_callback( '#url\(\s*(?P<url>https?://fonts\.gstatic\.com/[^\s\)]+)\s*\)#i', function( $m ) use ( $fonts_dir, $fonts_url ) {
			$local = $this->download_font_file( $m['url'], $fonts_dir, $fonts_url );
			return $local ? 'url(' . $local . ')' : $m[0];
		}, $css );

		if ( $this->settings['google_fonts_display'] && false === strpos( $css, 'font-display' ) ) {
			$css = preg_replace( '#(\{[^}]*)(})#', '$1font-display:swap;$2', $css );
		}

		return self::atomic_write( $css_file, $css );
	}

	// ── Query String Removal ─────────────────────────────────

	private function strip_query_strings( $html ) {
		// Only strip ?ver= / ?v= from local CSS/JS URLs.
		return preg_replace_callback(
			'#((?:href|src)=["\'])([^"\']+\.(css|js))\?(?:ver|v)=[^&"\']*(["\'])#i',
			function( $m ) {
				$url = $m[2];
				// Only strip from local (relative or same-host) URLs.
				if ( 0 === strpos( $url, '/' ) || $this->is_local_url( $url ) ) {
					return $m[1] . $url . $m[4];
				}
				return $m[0]; // External — keep query string.
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

	// ── Utility ──────────────────────────────────────────────

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
