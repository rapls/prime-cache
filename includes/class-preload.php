<?php
/**
 * Preload — cache warming, link prefetching, font preloading, preconnect.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Preload {

	/** @var array */
	private $settings;

	public function __construct() {
		$this->settings = prime_cache_get_settings();
		$s = $this->settings;

		// Cache preload (warm cache via cron).
		if ( $s['preload_enabled'] ) {
			add_action( 'prime_cache_preload_batch', array( $this, 'run_preload_batch' ) );
			add_action( 'prime_cache_after_purge_all', array( $this, 'schedule_preload' ) );
		}

		// Link prefetching (frontend JS).
		if ( $s['preload_links'] ) {
			add_action( 'wp_footer', array( $this, 'inject_link_prefetch_script' ), 99 );
		}

		// Font preloading.
		if ( $s['preload_fonts'] ) {
			// Priority 99 to ensure $wp_styles is fully populated.
			add_action( 'wp_head', array( $this, 'inject_font_preload' ), 99 );
		}

		// Preconnect resource hints.
		if ( ! empty( $s['preconnect'] ) ) {
			add_filter( 'wp_resource_hints', array( $this, 'add_preconnect_hints' ), 10, 2 );
		}

		// Manual preload resources.
		if ( ! empty( $s['preload_resources'] ) ) {
			add_action( 'wp_head', array( $this, 'inject_manual_preloads' ), 1 );
		}

		// DNS Prefetch resource hints.
		if ( ! empty( $s['prefetch_dns'] ) ) {
			add_filter( 'wp_resource_hints', array( $this, 'add_dns_prefetch_hints' ), 10, 2 );
		}

		// LCP Optimization (preload hero image + fetchpriority).
		if ( $s['lcp_optimization'] ) {
			add_action( 'template_redirect', array( $this, 'start_lcp_buffer' ), 0 );
		}

		// Speculation Rules API (prerender on hover).
		if ( $s['speculation_rules'] ) {
			add_action( 'wp_footer', array( $this, 'inject_speculation_rules' ), 99 );
		}
	}

	// ── Cache Preload (Warm Cache) ───────────────────────────

	/**
	 * Schedule the preload batch to start.
	 */
	public function schedule_preload() {
		// Clear existing schedule and queue so URLs are re-collected fresh.
		wp_clear_scheduled_hook( 'prime_cache_preload_batch' );
		delete_option( 'prime_cache_preload_queue' );
		wp_schedule_single_event( time() + 5, 'prime_cache_preload_batch' );
	}

	/**
	 * Run a batch of preload requests.
	 */
	public function run_preload_batch() {
		// Prevent concurrent execution (cron overlap, manual trigger overlap).
		if ( get_transient( 'prime_cache_preload_lock' ) ) {
			return;
		}
		set_transient( 'prime_cache_preload_lock', 1, 120 ); // 2-minute TTL.

		$interval = max( 1, (int) $this->settings['preload_interval'] );
		$limit    = 10;

		// Use a persistent queue to avoid re-collecting URLs every batch.
		$queue = get_option( 'prime_cache_preload_queue', array() );
		if ( empty( $queue ) ) {
			$urls = $this->collect_preload_urls();
			if ( empty( $urls ) ) {
				return;
			}
			// Filter exclusions once at queue creation.
			$excludes = $this->parse_patterns( $this->settings['preload_excluded_uri'] );
			$queue = array();
			foreach ( $urls as $url ) {
				$path = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
				if ( ! $this->matches_exclude( $path, $excludes ) ) {
					$queue[] = $url;
				}
			}
			if ( empty( $queue ) ) {
				return;
			}
			update_option( 'prime_cache_preload_queue', $queue, false );
		}

		$count = 0;
		$total = count( $queue );
		$idx   = 0;

		while ( $idx < $total && $count < $limit ) {
			$url = $queue[ $idx ];

			// Skip already cached URLs — advance past them.
			if ( $this->is_url_cached( $url ) ) {
				$idx++;
				continue;
			}

			// Check server load before sending request.
			if ( ! $this->server_load_ok() ) {
				break; // Keep current $idx and everything after in queue.
			}

			// Non-blocking request to warm desktop cache.
			wp_remote_get( $url, array(
				'timeout'   => 0.5,
				'blocking'  => false,
				'sslverify' => true,
				'headers'   => array( 'X-Prime-Cache-Preload' => '1' ),
			) );

			// If mobile separate is enabled, also warm the mobile variant.
			if ( ! empty( $this->settings['cache_mobile_separate'] ) ) {
				wp_remote_get( $url, array(
					'timeout'    => 0.5,
					'blocking'   => false,
					'sslverify'  => true,
					'user-agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
					'headers'    => array( 'X-Prime-Cache-Preload' => '1' ),
				) );
			}

			$count++;
			$idx++;
		}

		// Keep all URLs from current position onward for the next batch.
		$remaining = ( $idx < $total ) ? array_slice( $queue, $idx ) : array();

		if ( ! empty( $remaining ) ) {
			update_option( 'prime_cache_preload_queue', array_values( $remaining ), false );
			// Schedule next batch after interval — no sleep() needed.
			wp_schedule_single_event( time() + $interval, 'prime_cache_preload_batch' );
		} else {
			// Queue exhausted — cleanup.
			delete_option( 'prime_cache_preload_queue' );
		}

		delete_transient( 'prime_cache_preload_lock' );
	}

	/**
	 * Collect all URLs to preload.
	 */
	private function collect_preload_urls() {
		$urls = array();
		$s    = $this->settings;

		// Sitemap-based preload.
		$sitemap_url = trim( $s['preload_sitemap'] );
		if ( ! empty( $s['preload_sitemap_enabled'] ) && $sitemap_url ) {
			$sitemap_urls = $this->parse_sitemap( $sitemap_url );
			if ( ! empty( $sitemap_urls ) ) {
				return $sitemap_urls; // Sitemap takes priority.
			}
		}

		// Homepage.
		if ( $s['preload_homepage'] ) {
			$urls[] = home_url( '/' );
			$front = (int) get_option( 'page_on_front' );
			if ( $front ) {
				$urls[] = get_permalink( $front );
			}
			$posts_page = (int) get_option( 'page_for_posts' );
			if ( $posts_page ) {
				$urls[] = get_permalink( $posts_page );
			}
		}

		// Public posts.
		if ( $s['preload_public_posts'] ) {
			$post_types = get_post_types( array( 'public' => true ), 'names' );
			$max_posts = isset( $s['preload_max_posts'] ) ? (int) $s['preload_max_posts'] : 500;
			$posts = get_posts( array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => $max_posts,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
			) );
			foreach ( $posts as $pid ) {
				$urls[] = get_permalink( $pid );
			}
		}

		// Public taxonomies.
		if ( $s['preload_public_tax'] ) {
			$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
			$max_terms = isset( $s['preload_max_terms'] ) ? (int) $s['preload_max_terms'] : 200;
			$terms = get_terms( array(
				'taxonomy'   => $taxonomies,
				'hide_empty' => true,
				'number'     => $max_terms,
			) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$urls[] = $link;
					}
				}
			}
		}

		return array_unique( array_filter( $urls ) );
	}

	/**
	 * Parse a sitemap XML (supports sitemap index) and extract URLs.
	 */
	private function parse_sitemap( $sitemap_url, $depth = 0 ) {
		// Prevent infinite recursion.
		if ( $depth > 3 ) {
			return array();
		}

		// Same-host restriction: only fetch sitemaps from the site's own host.
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host  = wp_parse_url( $sitemap_url, PHP_URL_HOST );
		if ( ! $url_host || strtolower( $url_host ) !== strtolower( $site_host ) ) {
			return array();
		}

		// Reject private/loopback IPs via wp_http_validate_url().
		if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $sitemap_url ) ) {
			return array();
		}

		$response = wp_remote_get( $sitemap_url, array( 'timeout' => 15, 'sslverify' => true ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return array();
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		libxml_clear_errors();

		if ( ! $xml ) {
			return array();
		}

		$urls = array();

		// Sitemap index — recurse into child sitemaps.
		if ( isset( $xml->sitemap ) ) {
			foreach ( $xml->sitemap as $entry ) {
				if ( isset( $entry->loc ) ) {
					$child_urls = $this->parse_sitemap( (string) $entry->loc, $depth + 1 );
					$urls = array_merge( $urls, $child_urls );
					if ( count( $urls ) > 1000 ) {
						break;
					}
				}
			}
		}

		// Standard sitemap — collect <loc> URLs (same-host only).
		if ( isset( $xml->url ) ) {
			foreach ( $xml->url as $entry ) {
				if ( isset( $entry->loc ) ) {
					$loc      = (string) $entry->loc;
					$loc_host = wp_parse_url( $loc, PHP_URL_HOST );
					if ( $loc_host && strtolower( $loc_host ) === strtolower( $site_host ) ) {
						$urls[] = $loc;
					}
					if ( count( $urls ) > 1000 ) {
						break;
					}
				}
			}
		}

		return $urls;
	}

	/**
	 * Check if a URL is already cached.
	 */
	private function is_url_cached( $url ) {
		// When vary cookies are active, preload can only warm the default (no-cookie)
		// variant. Don't claim "fully cached" — always allow preload to run for the
		// base variant, but accept that cookie variants are generated on first real visit.
		// The check below only validates base + mobile variants, not cookie variants.

		$dir = Prime_Cache_Storage::get_cache_dir( $url );

		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$parsed = wp_parse_url( $url );
		$is_ssl = isset( $parsed['scheme'] ) && 'https' === $parsed['scheme'];
		$s      = $this->settings;

		// Build query-string suffix (aligned with dropin logic).
		$qs_suffix = '';
		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $qs_params );
			$ignored   = array_filter( array_map( 'trim', explode( ',', $s['cache_ignore_qs'] ?? '' ) ) );
			$cached_qs = array_filter( array_map( 'trim', explode( ',', $s['cache_query_strings'] ?? '' ) ) );
			$remaining = array_diff_key( $qs_params, array_flip( $ignored ) );

			if ( ! empty( $remaining ) ) {
				if ( empty( $cached_qs ) ) {
					return false; // Unknown params, no whitelist — page cache skips.
				}
				$to_cache = array_intersect_key( $remaining, array_flip( $cached_qs ) );
				$unknown  = array_diff_key( $remaining, $to_cache );
				if ( ! empty( $unknown ) ) {
					return false; // Has params not in ignore or cache list.
				}
				if ( ! empty( $to_cache ) ) {
					ksort( $to_cache );
					$qs_suffix = '-qs_' . substr( md5( http_build_query( $to_cache ) ), 0, 8 );
				}
			}
		}

		// Desktop/base variant MUST exist — this is the primary cache.
		$base = 'index';
		if ( $is_ssl ) {
			$base .= '-https';
		}
		$base .= $qs_suffix . '.html';

		if ( ! is_readable( $dir . $base ) ) {
			return false; // Desktop not cached — needs preloading.
		}

		// If mobile separate is enabled, mobile variant must also exist.
		if ( ! empty( $s['cache_mobile_separate'] ) ) {
			$mobile_base = 'index';
			if ( $is_ssl ) {
				$mobile_base .= '-https';
			}
			$mobile_base .= '-mobile' . $qs_suffix . '.html';
			if ( ! is_readable( $dir . $mobile_base ) ) {
				return false; // Mobile not cached — needs preloading.
			}
		}

		return true; // All required variants exist.
	}

	/**
	 * Check server load is acceptable for preloading.
	 */
	private function server_load_ok() {
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return true;
		}

		$load = sys_getloadavg();
		if ( ! is_array( $load ) || empty( $load ) ) {
			return true;
		}

		// Detect CPU core count for load normalization.
		$cores = 1;
		if ( is_readable( '/proc/cpuinfo' ) ) {
			$cpuinfo = file_get_contents( '/proc/cpuinfo' ); // phpcs:ignore
			$cores   = max( 1, substr_count( $cpuinfo, 'processor' ) );
		}

		// Weighted average: 50% 1min, 30% 5min, 20% 15min.
		$weighted = ( $load[0] * 0.5 ) + ( $load[1] * 0.3 ) + ( $load[2] * 0.2 );
		// Default max: 80% of core count (e.g. 4-core → 3.2, 8-core → 6.4).
		$default_max = max( 2.0, $cores * 0.8 );
		$max = apply_filters( 'prime_cache_preload_max_load', $default_max, $load );

		// Detect spikes.
		$spike = ( $load[0] > $load[1] * 2 ) || ( $load[1] > $load[2] * 2 );

		return $weighted <= $max && ! $spike;
	}

	// ── Link Prefetching (Frontend JS) ───────────────────────

	/**
	 * Inject a lightweight script that prefetches links on hover/viewport.
	 */
	public function inject_link_prefetch_script() {
		if ( is_admin() ) {
			return;
		}

		$exclude_patterns = array( '/wp-admin', '/wp-login', '/cart', '/checkout', '#', 'mailto:', 'tel:', 'javascript:' );
		$exclude_json = wp_json_encode( $exclude_patterns );
		$site_url = esc_js( home_url() );
		?>
		<script id="pc-preload-links">
		(function(){
			if(!window.IntersectionObserver||navigator.connection&&navigator.connection.saveData)return;
			var q={},exc=<?php echo $exclude_json; ?>,max=3,n=0,t=null;
			function ok(u){
				if(!u||q[u]||u.indexOf('<?php echo $site_url; ?>')!==0)return false;
				for(var i=0;i<exc.length;i++){if(u.indexOf(exc[i])!==-1)return false;}
				return true;
			}
			function pf(u){
				if(!ok(u))return;q[u]=1;n++;
				if(n>max){clearTimeout(t);t=setTimeout(function(){n=0;},1000);}
				var l=document.createElement('link');l.rel='prefetch';l.href=u;document.head.appendChild(l);
			}
			var delay;
			document.addEventListener('pointerover',function(e){
				var a=e.target.closest('a');if(!a)return;
				delay=setTimeout(function(){pf(a.href);},100);
			});
			document.addEventListener('pointerout',function(e){clearTimeout(delay);});
			if(window.IntersectionObserver){
				var obs=new IntersectionObserver(function(entries){
					entries.forEach(function(en){if(en.isIntersecting){var a=en.target;pf(a.href);obs.unobserve(a);}});
				},{rootMargin:'200px'});
				document.querySelectorAll('a[href^="<?php echo $site_url; ?>"]').forEach(function(a){obs.observe(a);});
			}
		})();
		</script>
		<?php
	}

	// ── Manual Resource Preloading ────────────────────────────

	/**
	 * Inject <link rel="preload"> for manually specified resources.
	 */
	public function inject_manual_preloads() {
		$raw = trim( $this->settings['preload_resources'] );
		if ( empty( $raw ) ) return;

		$lines = array_filter( array_map( 'trim', preg_split( '#[\r\n]+#', $raw ) ) );

		foreach ( $lines as $url ) {
			$url = esc_url( $url );
			if ( empty( $url ) ) continue;

			// Auto-detect type from extension.
			$ext = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?: '', PATHINFO_EXTENSION ) );
			$as_attr  = '';
			$type_attr = '';
			$cross     = '';

			switch ( $ext ) {
				case 'css':
					$as_attr = ' as="style"';
					break;
				case 'js': case 'mjs':
					$as_attr = ' as="script"';
					break;
				case 'woff2':
					$as_attr = ' as="font"'; $type_attr = ' type="font/woff2"'; $cross = ' crossorigin';
					break;
				case 'woff':
					$as_attr = ' as="font"'; $type_attr = ' type="font/woff"'; $cross = ' crossorigin';
					break;
				case 'ttf':
					$as_attr = ' as="font"'; $type_attr = ' type="font/ttf"'; $cross = ' crossorigin';
					break;
				case 'jpg': case 'jpeg': case 'png': case 'webp': case 'avif': case 'gif': case 'svg':
					$as_attr = ' as="image"';
					break;
				default:
					$as_attr = ' as="fetch"'; $cross = ' crossorigin';
					break;
			}

			echo '<link rel="preload" href="' . $url . '"' . $as_attr . $type_attr . $cross . '>' . "\n";
		}
	}

	// ── Font Preloading ──────────────────────────────────────

	/**
	 * Detect font files in enqueued stylesheets and inject preload hints.
	 */
	public function inject_font_preload() {
		$fonts = $this->detect_fonts();
		foreach ( $fonts as $font_url ) {
			$type = '';
			if ( preg_match( '#\.woff2$#i', $font_url ) ) {
				$type = 'font/woff2';
			} elseif ( preg_match( '#\.woff$#i', $font_url ) ) {
				$type = 'font/woff';
			} elseif ( preg_match( '#\.ttf$#i', $font_url ) ) {
				$type = 'font/ttf';
			}
			echo '<link rel="preload" href="' . esc_url( $font_url ) . '" as="font"'
				. ( $type ? ' type="' . esc_attr( $type ) . '"' : '' )
				. ' crossorigin>' . "\n";
		}
	}

	/**
	 * Scan registered stylesheets for @font-face declarations with local font files.
	 */
	private function detect_fonts() {
		$fonts = array();
		$cache_key = 'prime_cache_preload_fonts';
		$cached = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		// Scan theme stylesheet.
		$style_path = get_stylesheet_directory() . '/style.css';
		if ( is_readable( $style_path ) ) {
			$css = file_get_contents( $style_path ); // phpcs:ignore
			$fonts = array_merge( $fonts, $this->extract_font_urls( $css, $style_path ) );
		}

		// Scan registered stylesheets.
		global $wp_styles;
		if ( $wp_styles && ! empty( $wp_styles->registered ) ) {
			foreach ( $wp_styles->registered as $handle => $dep ) {
				if ( empty( $dep->src ) ) {
					continue;
				}
				$path = $this->style_url_to_path( $dep->src );
				if ( $path && is_readable( $path ) ) {
					$css = file_get_contents( $path ); // phpcs:ignore
					if ( $css ) {
						$fonts = array_merge( $fonts, $this->extract_font_urls( $css, $path ) );
					}
				}
			}
		}

		$fonts = array_unique( array_slice( $fonts, 0, 10 ) ); // Cap at 10 fonts.
		set_transient( $cache_key, $fonts, DAY_IN_SECONDS );

		return $fonts;
	}

	/**
	 * Extract font file URLs from CSS @font-face blocks.
	 */
	private function extract_font_urls( $css, $css_path ) {
		$urls = array();
		$css_dir = dirname( $css_path );

		if ( ! preg_match_all( '#@font-face\s*\{[^}]+\}#si', $css, $blocks ) ) {
			return $urls;
		}

		foreach ( $blocks[0] as $block ) {
			// Prefer woff2.
			if ( preg_match( '#url\(\s*["\']?([^"\')\s]+\.woff2)["\']?\s*\)#i', $block, $m ) ) {
				$urls[] = $this->resolve_font_url( $m[1], $css_dir );
			} elseif ( preg_match( '#url\(\s*["\']?([^"\')\s]+\.woff)["\']?\s*\)#i', $block, $m ) ) {
				$urls[] = $this->resolve_font_url( $m[1], $css_dir );
			}
		}

		return array_filter( $urls );
	}

	/**
	 * Resolve a font URL (relative or absolute) to a full URL.
	 */
	private function resolve_font_url( $url, $css_dir ) {
		// Already absolute.
		if ( preg_match( '#^https?://#i', $url ) || 0 === strpos( $url, '//' ) ) {
			return $url;
		}
		// Data URI.
		if ( 0 === strpos( $url, 'data:' ) ) {
			return '';
		}
		// Absolute path.
		if ( 0 === strpos( $url, '/' ) ) {
			return home_url( $url );
		}
		// Relative path.
		$abs = realpath( $css_dir . '/' . $url );
		if ( $abs && 0 === strpos( $abs, ABSPATH ) ) {
			return home_url( '/' . ltrim( str_replace( ABSPATH, '', $abs ), '/' ) );
		}
		return '';
	}

	/**
	 * Convert a style URL to local file path.
	 */
	private function style_url_to_path( $url ) {
		$home_url = home_url( '/' );
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			$path = ABSPATH . ltrim( $url, '/' );
		} elseif ( 0 === strpos( $url, $home_url ) ) {
			$path = ABSPATH . substr( $url, strlen( $home_url ) );
		} else {
			return false;
		}
		$path = strtok( $path, '?' );
		$real = realpath( $path );
		return ( $real && 0 === strpos( $real, realpath( ABSPATH ) ) ) ? $real : false;
	}

	// ── Preconnect ───────────────────────────────────────────

	/**
	 * Add preconnect resource hints.
	 */
	public function add_preconnect_hints( $hints, $relation_type ) {
		if ( 'preconnect' !== $relation_type ) {
			return $hints;
		}

		$domains = preg_split( '#[\r\n,]+#', $this->settings['preconnect'] );
		$domains = array_filter( array_map( 'trim', $domains ) );

		foreach ( $domains as $domain ) {
			if ( empty( $domain ) ) {
				continue;
			}
			// Ensure scheme.
			if ( 0 !== strpos( $domain, 'http' ) && 0 !== strpos( $domain, '//' ) ) {
				$domain = 'https://' . $domain;
			}
			$hints[] = array( 'href' => $domain, 'crossorigin' => '' );
		}

		return $hints;
	}

	// ── Speculation Rules API ─────────────────────────────────

	/**
	 * Inject Speculation Rules JSON for instant page navigation.
	 *
	 * The browser prerenders pages when the user hovers over internal links,
	 * making subsequent navigations nearly instant. Supported in Chrome 109+.
	 * Falls back gracefully — browsers without support simply ignore the script tag.
	 */
	public function inject_speculation_rules() {
		if ( is_admin() ) {
			return;
		}

		$site_url = home_url();
		$parsed   = wp_parse_url( $site_url );
		$host     = $parsed['host'] ?? '';

		$rules = array(
			'prerender' => array(
				array(
					'where' => array(
						'and' => array(
							// Only same-origin URLs.
							array( 'href_matches' => $site_url . '/*' ),
							// Exclude admin, login, cart, checkout, feed, wp-json.
							array( 'not' => array(
								'href_matches' => array(
									$site_url . '/wp-admin/*',
									$site_url . '/wp-login.php*',
									$site_url . '/cart/*',
									$site_url . '/checkout/*',
									$site_url . '/my-account/*',
									$site_url . '/feed/*',
									$site_url . '/wp-json/*',
									$site_url . '/*?*add-to-cart=*',
								),
							) ),
						),
					),
					'eagerness' => 'moderate',
				),
			),
		);

		$json = wp_json_encode( $rules, JSON_UNESCAPED_SLASHES );
		?>
		<script type="speculationrules"><?php echo $json; // phpcs:ignore ?></script>
		<?php
	}

	// ── DNS Prefetch ─────────────────────────────────────────

	/**
	 * Add dns-prefetch resource hints via wp_resource_hints filter.
	 */
	public function add_dns_prefetch_hints( $hints, $relation_type ) {
		if ( 'dns-prefetch' !== $relation_type ) {
			return $hints;
		}

		$domains = preg_split( '#[\r\n,]+#', $this->settings['prefetch_dns'] );
		$domains = array_filter( array_map( 'trim', $domains ) );

		foreach ( $domains as $domain ) {
			if ( empty( $domain ) ) {
				continue;
			}
			// Strip scheme and trailing slash for dns-prefetch.
			$domain = preg_replace( '#^https?://#i', '', $domain );
			$domain = rtrim( $domain, '/' );
			$hints[] = '//' . $domain;
		}

		return $hints;
	}

	// ── LCP Optimization ─────────────────────────────────────

	/**
	 * Start output buffering for LCP image detection and optimization.
	 */
	public function start_lcp_buffer() {
		if ( is_admin() ) {
			return;
		}
		ob_start( array( $this, 'optimize_lcp' ) );
	}

	/**
	 * Process HTML to optimize LCP:
	 * 1. Add fetchpriority="high" to the first large image in the viewport.
	 * 2. Inject <link rel="preload"> for that image.
	 * 3. Add loading="lazy" to non-LCP images.
	 */
	public function optimize_lcp( $html ) {
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		$excludes = $this->parse_patterns( $this->settings['lcp_excluded'] ?? '' );

		// Find all <img> tags.
		if ( ! preg_match_all( '#<img\s[^>]+>#i', $html, $img_matches ) ) {
			return $html;
		}

		$lcp_img    = null;
		$lcp_src    = '';
		$lcp_tag    = '';
		$count      = 0;
		$atf_limit  = 3; // Consider first N images as candidates for above-the-fold.

		foreach ( $img_matches[0] as $tag ) {
			// Extract src.
			if ( ! preg_match( '#src=["\']([^"\']+)["\']#i', $tag, $src_m ) ) {
				continue;
			}
			$src = $src_m[1];

			// Skip tiny images (icons, spacers).
			$is_small = false;
			if ( preg_match( '#width=["\']?(\d+)#i', $tag, $w_m ) && (int) $w_m[1] < 100 ) {
				$is_small = true;
			}
			if ( preg_match( '#height=["\']?(\d+)#i', $tag, $h_m ) && (int) $h_m[1] < 100 ) {
				$is_small = true;
			}

			// Skip excluded patterns.
			$skip = false;
			foreach ( $excludes as $pat ) {
				if ( ! empty( $pat ) && false !== strpos( $src, $pat ) ) {
					$skip = true;
					break;
				}
			}

			$count++;

			// The first non-tiny, non-excluded, above-the-fold image is the LCP candidate.
			if ( ! $lcp_img && ! $is_small && ! $skip && $count <= $atf_limit ) {
				$lcp_img = $tag;
				$lcp_src = $src;
				$lcp_tag = $tag;
			}
		}

		if ( ! $lcp_img ) {
			return $html;
		}

		// Replace ONLY the first occurrence of the LCP tag (not all identical tags).
		$new_lcp = $lcp_tag;
		$new_lcp = preg_replace( '#\s*loading=["\'][^"\']*["\']#i', '', $new_lcp );
		$new_lcp = preg_replace( '#\s*fetchpriority=["\'][^"\']*["\']#i', '', $new_lcp );
		$new_lcp = str_replace( '<img ', '<img fetchpriority="high" ', $new_lcp );

		$lcp_pos = strpos( $html, $lcp_tag );
		if ( false !== $lcp_pos ) {
			$html = substr_replace( $html, $new_lcp, $lcp_pos, strlen( $lcp_tag ) );
		}

		// Inject <link rel="preload"> for LCP image in head.
		$preload_src = $lcp_src;
		$type_attr   = '';

		// Check if LCP image is inside a <picture> element.
		// Find the complete <picture>...</picture> block containing this <img>.
		$picture_block = '';
		$best_source   = null;
		$best_type     = '';
		if ( false !== $lcp_pos ) {
			$search_start = max( 0, $lcp_pos - 3000 );
			$prefix       = substr( $html, $search_start, $lcp_pos - $search_start );
			$pic_open_rel = strrpos( $prefix, '<picture' );
			if ( false !== $pic_open_rel ) {
				$between = substr( $prefix, $pic_open_rel );
				if ( false === stripos( $between, '</picture>' ) ) {
					// Find </picture> after the <img> to get the complete block.
					$pic_close = stripos( $html, '</picture>', $lcp_pos );
					if ( false !== $pic_close ) {
						$abs_open      = $search_start + $pic_open_rel;
						$picture_block = substr( $html, $abs_open, $pic_close + 10 - $abs_open );
					} else {
						$picture_block = $between;
					}
				}
			}
		}

		// Extract best <source> from the picture block (attribute-order independent).
		$skip_preload = false;
		if ( ! empty( $picture_block ) ) {
			if ( preg_match_all( '#<source\s[^>]+>#i', $picture_block, $source_tags ) ) {
				// Check if any source has media= (art-direction pattern).
				$has_art_direction = false;
				foreach ( $source_tags[0] as $source_tag ) {
					if ( preg_match( '#\bmedia\s*=#i', $source_tag ) ) {
						$has_art_direction = true;
						break;
					}
				}

				if ( $has_art_direction ) {
					// Art-direction <picture>: skip preload entirely.
					// PHP can't determine which media query applies without viewport info.
					$skip_preload = true;
				} else {
					// Non-art-direction: find best next-gen source (AVIF > WebP).
					foreach ( $source_tags[0] as $source_tag ) {
						$s_type = '';
						if ( preg_match( '#type=["\']([^"\']+)["\']#i', $source_tag, $tm ) ) {
							$s_type = strtolower( $tm[1] );
						}
						if ( 'image/avif' === $s_type && ! $best_source ) {
							$best_source = $source_tag;
							$best_type   = $s_type;
						} elseif ( 'image/webp' === $s_type && 'image/avif' !== $best_type ) {
							$best_source = $source_tag;
							$best_type   = $s_type;
						}
					}
					// Set type to match the source series. href stays as <img src> (the
					// format-agnostic fallback) while imagesrcset carries the actual
					// next-gen candidates for the browser to pick from.
					if ( $best_source && preg_match( '#srcset=["\']([^"\']+)["\']#i', $best_source, $bs_m ) ) {
						$type_attr = ' type="' . $best_type . '"';
						// href = first srcset candidate to match the type attribute.
						// This ensures href and type are the same format series.
						$preload_src = strtok( $bs_m[1], ' ' );
					}
				}
			}
		}

		if ( ! $skip_preload ) {
			if ( empty( $type_attr ) ) {
				if ( preg_match( '#\.(webp)$#i', strtok( $preload_src, '?' ) ) ) {
					$type_attr = ' type="image/webp"';
				} elseif ( preg_match( '#\.(avif)$#i', strtok( $preload_src, '?' ) ) ) {
					$type_attr = ' type="image/avif"';
				}
			}

			$preload_tag = '<link rel="preload" as="image" href="' . esc_url( $preload_src ) . '"' . $type_attr;

			// Add imagesrcset/imagesizes from the same source, or fall back to <img>.
			$srcset_val = '';
			$sizes_val  = '';
			if ( ! empty( $best_source ) ) {
				if ( preg_match( '#srcset=["\']([^"\']+)["\']#i', $best_source, $ss_m ) ) {
					$srcset_val = $ss_m[1];
				}
				if ( preg_match( '#sizes=["\']([^"\']+)["\']#i', $best_source, $sz_m ) ) {
					$sizes_val = $sz_m[1];
				}
			}

			if ( $srcset_val ) {
				$preload_tag .= ' imagesrcset="' . esc_attr( $srcset_val ) . '"';
				if ( $sizes_val ) {
					$preload_tag .= ' imagesizes="' . esc_attr( $sizes_val ) . '"';
				} elseif ( preg_match( '#sizes=["\']([^"\']+)["\']#i', $lcp_tag, $sizes_m ) ) {
					$preload_tag .= ' imagesizes="' . esc_attr( $sizes_m[1] ) . '"';
				}
			} elseif ( preg_match( '#srcset=["\']([^"\']+)["\']#i', $lcp_tag, $srcset_m ) ) {
				$preload_tag .= ' imagesrcset="' . esc_attr( $srcset_m[1] ) . '"';
				if ( preg_match( '#sizes=["\']([^"\']+)["\']#i', $lcp_tag, $sizes_m ) ) {
					$preload_tag .= ' imagesizes="' . esc_attr( $sizes_m[1] ) . '"';
				}
			}

			$preload_tag .= ' fetchpriority="high">';
			$html = str_replace( '</head>', $preload_tag . "\n</head>", $html );
		}

		return $html;
	}

	// ── Utility ──────────────────────────────────────────────

	private function parse_patterns( $value ) {
		if ( empty( $value ) ) {
			return array();
		}
		return array_filter( array_map( 'trim', preg_split( '#[\r\n]+#', $value ) ) );
	}

	private function matches_exclude( $path, $patterns ) {
		foreach ( $patterns as $pat ) {
			if ( empty( $pat ) ) {
				continue;
			}
			if ( false !== strpos( $path, $pat ) ) {
				return true;
			}
			if ( @preg_match( '#' . $pat . '#i', $path ) ) {
				return true;
			}
		}
		return false;
	}
}
