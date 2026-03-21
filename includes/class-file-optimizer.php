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
