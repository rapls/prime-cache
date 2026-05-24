<?php
/**
 * Preload — cache warming and link prefetching.
 *
 * The crawl URL queue is filterable via prime_cache_preload_urls so an add-on
 * can extend it (e.g. with sitemap-discovered URLs).
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Preload {

	/** @var array */
	private $settings;

	public function __construct() {
		$this->settings = prime_cache_get_settings();
		$s   = $this->settings;

		// Cache preload (warm cache via cron).
		if ( $s['preload_enabled'] ) {
			add_action( 'prime_cache_preload_batch', array( $this, 'run_preload_batch' ) );
			add_action( 'prime_cache_after_purge_all', array( $this, 'schedule_preload' ) );
		}

		// [Free] Link prefetching (frontend JS).
		if ( $s['preload_links'] ) {
			add_action( 'wp_footer', array( $this, 'inject_link_prefetch_script' ), 99 );
		}
	}

	// ── Cache Preload (Warm Cache) ───────────────────────────

	/**
	 * Schedule the preload batch to start.
	 *
	 * Action callback for `prime_cache_after_purge_all`. Delegates to the static
	 * `request()` so callers outside this class (settings save, activation, CLI,
	 * admin actions) can trigger an identical reset+schedule without depending
	 * on a Prime_Cache_Preload instance being constructed with preload_enabled=true
	 * (which gates listener registration).
	 */
	public function schedule_preload() {
		self::request();
	}

	/**
	 * Reset preload state and schedule a fresh batch run.
	 *
	 * Use from anywhere a "start preload now" intent originates. This is the
	 * single source of truth for what "starting preload" means: clear the queue,
	 * clear attempt history, drop any stale 2-minute lock, then schedule the
	 * batch handler 5 seconds out so the next WP-Cron tick picks it up.
	 *
	 * Returns false (without scheduling) when the request would be a no-op:
	 * cache_enabled=false makes the drop-in return early so warming does
	 * nothing, and preload_enabled=false leaves the batch handler unregistered.
	 * This is belt-and-braces — `prime_cache_after_purge_all` listener gating
	 * already prevents most no-op calls, but settings can change between
	 * bootstrap and purge within the same request.
	 *
	 * @param array|null $settings Optional. Settings to validate against. When
	 *                             omitted, reads the current saved settings.
	 *                             Pass the new (about-to-be-saved) settings from
	 *                             sanitize_settings() so a false→true toggle
	 *                             works on the same request — the memoized
	 *                             cache still holds the OLD value at that point.
	 * @return bool True when scheduled, false when preconditions fail.
	 */
	public static function request( $settings = null ) {
		$s = is_array( $settings ) ? $settings : prime_cache_get_settings();
		if ( empty( $s['cache_enabled'] ) || empty( $s['preload_enabled'] ) ) {
			return false;
		}

		// Schedule first; the queue/attempts/lock reset only happens when a
		// new event lands cleanly. wp_schedule_single_event() returns false
		// for two distinct reasons:
		//   (a) `pre_schedule_event` filter rejected (real failure, e.g.
		//       DISABLE_WP_CRON or third-party blocker)
		//   (b) an event for the same hook+args is already queued in the
		//       dedup window (transient "already scheduled" — not a failure
		//       for our purposes; the existing batch already honors the
		//       request)
		// Never clear an existing event when (a) might be in play —
		// removing it would leave the install with no scheduled preload at
		// all, which is exactly the failure mode this helper is supposed
		// to prevent.
		$scheduled = wp_schedule_single_event( time() + 5, 'prime_cache_preload_batch' );
		if ( false === $scheduled ) {
			$already = wp_next_scheduled( 'prime_cache_preload_batch' );
			if ( ! $already ) {
				// No event queued and we couldn't schedule one — real rejection.
				return false;
			}
			// Existing batch will run — request implicitly honored. Fall
			// through to the state reset below so a post-purge request()
			// still picks up the fresh URL set instead of replaying the
			// stale queue from before the purge.
		}

		// Reset queue/attempts so the next batch starts clean against
		// whatever state the caller just changed (purge, settings save).
		// The scheduled event itself is preserved either way: a fresh
		// schedule above, or the existing one that the dedup short-circuit
		// returned to us. The lock — if held by an in-flight
		// `run_preload_batch()` — must NOT be deleted here; clearing a
		// live lock would let a second batch start in parallel and
		// trample queue/attempts updates. The lock TTL (120s) means a
		// stale lock self-recovers on the next run.
		$existing_lock = (int) get_option( 'prime_cache_preload_lock', 0 );
		$lock_is_live  = ( $existing_lock > 0 ) && ( ( time() - $existing_lock ) < 120 );
		delete_option( 'prime_cache_preload_queue' );
		delete_option( 'prime_cache_preload_attempts' );
		if ( ! $lock_is_live ) {
			delete_option( 'prime_cache_preload_lock' );
		}
		return true;
	}

	/**
	 * Run a batch of preload requests.
	 */
	public function run_preload_batch() {
		// Prevent concurrent execution (cron overlap, manual trigger overlap).
		// Use add_option() for atomic acquire — wp_options.option_name has a
		// UNIQUE index so concurrent inserts cannot both succeed. A plain
		// get-then-set transient race could let two batches run side-by-side
		// and double-write the queue/attempts options.
		$lock_key = 'prime_cache_preload_lock';
		$lock_ttl = 120; // 2-minute TTL.
		$now      = time();
		if ( ! add_option( $lock_key, $now, '', 'no' ) ) {
			$existing = (int) get_option( $lock_key, 0 );
			if ( $existing > 0 && ( $now - $existing ) < $lock_ttl ) {
				return; // Another batch holds a fresh lock.
			}
			// Stale lock — drop and retry once. Two concurrent stale-claimants
			// could both pass here, but stale claims are rare and TTL bounds
			// the damage.
			delete_option( $lock_key );
			if ( ! add_option( $lock_key, $now, '', 'no' ) ) {
				return;
			}
		}

		$interval = max( 1, (int) $this->settings['preload_interval'] );
		$limit    = 10;

		// Use a persistent queue to avoid re-collecting URLs every batch.
		$queue = get_option( 'prime_cache_preload_queue', array() );
		if ( empty( $queue ) ) {
			$urls = $this->collect_preload_urls();
			if ( empty( $urls ) ) {
				delete_option( $lock_key );
				return;
			}
			$excludes = $this->parse_patterns( $this->settings['preload_excluded_uri'] );
			$queue = array();
			foreach ( $urls as $url ) {
				$path = wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
				if ( ! $this->matches_exclude( $path, $excludes ) ) {
					$queue[] = $url;
				}
			}
			if ( empty( $queue ) ) {
				delete_option( $lock_key );
				return;
			}
			update_option( 'prime_cache_preload_queue', $queue, false );
		}

		// Track per-URL attempts with exponential backoff for convergence.
		// Structure: $attempts[$url] = [ 'count' => int, 'time' => int ]
		$attempts = get_option( 'prime_cache_preload_attempts', array() );
		$max_attempts = 3;
		$now = time();
		$deferred = array();

		// Pre-filter: separate URLs that are still in cooldown before the main loop.
		// This avoids iterating through cooldown URLs in the batch loop entirely.
		$eligible = array();
		foreach ( $queue as $url ) {
			$next_key = $url . ':next';
			if ( isset( $attempts[ $next_key ] ) && $now < (int) $attempts[ $next_key ] ) {
				$deferred[] = $url;
			} else {
				unset( $attempts[ $next_key ] );
				$eligible[] = $url;
			}
		}

		$count      = 0;
		$total      = count( $eligible );
		$idx        = 0;
		$mobile_sep = ! empty( $this->settings['cache_mobile_separate'] );

		while ( $idx < $total && $count < $limit ) {
			$url        = $eligible[ $idx ];
			$mobile_key = $url . ':m';

			// Check which variants still need warming.
			$need_desktop = ! $this->is_variant_cached( $url, false );
			$need_mobile  = $mobile_sep && ! $this->is_variant_cached( $url, true );

			// Fully cached — skip permanently. Drop the cooldown marker too
			// or it lingers in $attempts indefinitely on long-running sites.
			if ( ! $need_desktop && ! $need_mobile ) {
				unset( $attempts[ $url ], $attempts[ $mobile_key ], $attempts[ $url . ':next' ] );
				$idx++;
				continue;
			}

			// Check per-variant attempt limits with exponential backoff.
			$d_info = isset( $attempts[ $url ] ) ? $attempts[ $url ] : array( 'count' => 0, 'time' => 0 );
			$m_info = isset( $attempts[ $mobile_key ] ) ? $attempts[ $mobile_key ] : array( 'count' => 0, 'time' => 0 );
			// Normalize legacy format (plain int → array).
			if ( ! is_array( $d_info ) ) $d_info = array( 'count' => (int) $d_info, 'time' => 0 );
			if ( ! is_array( $m_info ) ) $m_info = array( 'count' => (int) $m_info, 'time' => 0 );

			$d_exhausted = $d_info['count'] >= $max_attempts;
			$m_exhausted = ! $mobile_sep || $m_info['count'] >= $max_attempts;

			if ( $d_exhausted && $m_exhausted ) {
				unset( $attempts[ $url ], $attempts[ $mobile_key ], $attempts[ $url . ':next' ] );
				$idx++;
				continue;
			}

			// Exponential backoff with jitter to prevent synchronized retry spikes.
			$backoffs = array( 5, 30, 300 );
			$d_base = isset( $backoffs[ $d_info['count'] ] ) ? $backoffs[ $d_info['count'] ] : 300;
			$m_base = isset( $backoffs[ $m_info['count'] ] ) ? $backoffs[ $m_info['count'] ] : 300;
			$d_cooldown = $d_base + mt_rand( 0, (int) ( $d_base * 0.3 ) );
			$m_cooldown = $m_base + mt_rand( 0, (int) ( $m_base * 0.3 ) );
			$d_ready = ( $now - $d_info['time'] ) >= $d_cooldown;
			$m_ready = ( $now - $m_info['time'] ) >= $m_cooldown;

			if ( ! $this->server_load_ok() ) {
				break;
			}

			// Only warm the variant that's missing AND past its cooldown.
			$sent = false;
			if ( $need_desktop && ! $d_exhausted && $d_ready ) {
				wp_remote_get( $url, array(
					'timeout'   => 0.5,
					'blocking'  => false,
					'sslverify' => true,
					'headers'   => array( 'X-Prime-Cache-Preload' => '1' ),
				) );
				$attempts[ $url ] = array( 'count' => $d_info['count'] + 1, 'time' => $now );
				$sent = true;
			}

			if ( $need_mobile && ! $m_exhausted && $m_ready ) {
				wp_remote_get( $url, array(
					'timeout'    => 0.5,
					'blocking'   => false,
					'sslverify'  => true,
					'user-agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
					'headers'    => array( 'X-Prime-Cache-Preload' => '1' ),
				) );
				$attempts[ $mobile_key ] = array( 'count' => $m_info['count'] + 1, 'time' => $now );
				$sent = true;
			}

			if ( ! $sent ) {
				// Both variants in cooldown — move to end of queue and record
				// next eligible time so future batches can skip quickly.
				$next_d = $d_info['time'] + $d_cooldown;
				$next_m = $m_info['time'] + $m_cooldown;
				$attempts[ $url . ':next' ] = max( $next_d, $next_m );
				$deferred[] = $url;
				$idx++;
				continue;
			}

			$count++;
			$idx++;
		}

		// Build remaining queue: keep URLs that were attempted but may not yet be
		// cached (non-blocking request). They'll be verified via is_variant_cached()
		// in the next batch and skipped if confirmed cached.
		$unprocessed = ( $idx < $total ) ? array_slice( $eligible, $idx ) : array();
		$still_needed = array();
		// Track which URLs are already queued to prevent duplicates.
		$seen = array_flip( $deferred ); // Deferred URLs already accounted for.
		foreach ( $unprocessed as $u ) {
			$seen[ $u ] = true;
		}
		// Re-check attempted URLs — keep those not fully cached yet (skip deferred).
		for ( $j = 0; $j < $idx; $j++ ) {
			$u = $eligible[ $j ];
			if ( isset( $seen[ $u ] ) ) {
				continue; // Already in deferred or unprocessed — no duplicate.
			}
			$d_ok = $this->is_variant_cached( $u, false );
			$m_ok = $mobile_sep ? $this->is_variant_cached( $u, true ) : true;
			if ( ! $d_ok || ! $m_ok ) {
				$d_a = isset( $attempts[ $u ] ) ? ( is_array( $attempts[ $u ] ) ? $attempts[ $u ]['count'] : (int) $attempts[ $u ] ) : 0;
				$m_a = isset( $attempts[ $u . ':m' ] ) ? ( is_array( $attempts[ $u . ':m' ] ) ? $attempts[ $u . ':m' ]['count'] : (int) $attempts[ $u . ':m' ] ) : 0;
				if ( $d_a < $max_attempts || ( $mobile_sep && $m_a < $max_attempts ) ) {
					$still_needed[] = $u;
				}
			}
		}
		// Deferred URLs go to end of queue (cooldown — don't block front).
		$remaining = array_merge( $still_needed, $unprocessed, $deferred );
		update_option( 'prime_cache_preload_attempts', $attempts, false );

		if ( ! empty( $remaining ) ) {
			update_option( 'prime_cache_preload_queue', array_values( $remaining ), false );
			// Schedule next batch after interval — no sleep() needed. Honor the
			// return value so we can surface a stuck queue when WP-Cron is
			// disabled or a `pre_schedule_event` filter rejects (otherwise the
			// queue would silently halt mid-preload with no signal).
			$next = wp_schedule_single_event( time() + $interval, 'prime_cache_preload_batch' );
			if ( false === $next && class_exists( 'Prime_Cache_File_Optimizer' )
				&& ! empty( $this->settings['debug_log'] ) ) {
				Prime_Cache_File_Optimizer::debug_log(
					'PRELOAD CONTINUATION SCHEDULE FAILED — queue size: ' . count( $remaining )
				);
			}
		} else {
			// Queue exhausted — cleanup.
			delete_option( 'prime_cache_preload_queue' );
			delete_option( 'prime_cache_preload_attempts' );
		}

		delete_option( $lock_key );
	}

	/**
	 * Collect all URLs to preload.
	 */
	private function collect_preload_urls() {
		$urls = array();
		$s    = $this->settings;

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

		$urls = array_unique( array_filter( $urls ) );

		// Extension point: an add-on may add URLs to warm (e.g. sitemap-discovered
		// URLs). Free contributes the homepage + public posts/taxonomies above.
		$urls = apply_filters( 'prime_cache_preload_urls', $urls, $s );

		return array_values( array_unique( array_filter( (array) $urls ) ) );
	}

	/**
	 * Check if a specific variant (desktop or mobile) is cached.
	 */
	private function is_variant_cached( $url, $is_mobile = false ) {
		$dir = Prime_Cache_Storage::get_cache_dir( $url );
		if ( false === $dir || ! is_dir( $dir ) ) {
			return false;
		}

		$parsed = wp_parse_url( $url );
		$is_ssl = isset( $parsed['scheme'] ) && 'https' === $parsed['scheme'];
		$s      = $this->settings;

		$qs_suffix = '';
		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $qs_params );
			$ignored   = array_filter( array_map( 'trim', explode( ',', $s['cache_ignore_qs'] ?? '' ) ) );
			$cached_qs = array_filter( array_map( 'trim', explode( ',', $s['cache_query_strings'] ?? '' ) ) );
			$remaining = array_diff_key( $qs_params, array_flip( $ignored ) );
			if ( ! empty( $remaining ) ) {
				if ( empty( $cached_qs ) ) return false;
				$to_cache = array_intersect_key( $remaining, array_flip( $cached_qs ) );
				$unknown  = array_diff_key( $remaining, $to_cache );
				if ( ! empty( $unknown ) ) return false;
				if ( ! empty( $to_cache ) ) {
					ksort( $to_cache );
					// Must match the dropin's page-cache filename suffix width (16 hex)
					// or this existence check never finds query-string variants and
					// preload retries them forever.
					$qs_suffix = '-qs_' . substr( md5( http_build_query( $to_cache ) ), 0, 16 );
				}
			}
		}

		$base = 'index';
		if ( $is_ssl ) $base .= '-https';
		if ( $is_mobile ) $base .= '-mobile';
		$base .= $qs_suffix . '.html';

		return is_readable( $dir . $base );
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

		// Detect CPU core count for load normalization (memoized).
		static $cores = null;
		if ( null === $cores ) {
			$cores = 1;
			if ( is_readable( '/proc/cpuinfo' ) ) {
				$cpuinfo = file_get_contents( '/proc/cpuinfo' ); // phpcs:ignore
				$cores   = max( 1, substr_count( $cpuinfo, 'processor' ) );
			}
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

		// Substring patterns matched against the absolute URL.
		// `/cart`, `/checkout`, `/my-account` mirror the page-cache WooCommerce
		// URI guard. Query-string entries cover state-changing GET endpoints
		// (add-to-cart, wc-ajax, wp_logout, action=) — prefetching these would
		// silently submit cart adds, trigger logouts, etc. since browsers honor
		// `<link rel="prefetch">` as a real GET.
		$exclude_patterns = array(
			'/wp-admin', '/wp-login', '/cart', '/checkout', '/my-account', '/wc-api',
			'#', 'mailto:', 'tel:', 'javascript:',
			'add-to-cart=', 'wc-ajax=', 'remove_item=', 'undo_item=',
			'wp_logout', 'action=logout', '_wpnonce=',
		);
		// Build "host[:port]" + path prefix so the JS can compare URL.host
		// directly and also confirm the URL falls under our WordPress base
		// path. For subdirectory installs (`https://example.com/blog/`) a
		// bare host check would happily prefetch `/shop/checkout` on the
		// same domain, hitting an unrelated sibling app.
		$home_parsed     = wp_parse_url( home_url() );
		$home_host_only  = isset( $home_parsed['host'] ) ? $home_parsed['host'] : '';
		$home_port       = isset( $home_parsed['port'] ) ? (int) $home_parsed['port'] : 0;
		$home_host_full  = $home_host_only . ( $home_port > 0 ? ':' . $home_port : '' );
		$home_path       = isset( $home_parsed['path'] ) ? $home_parsed['path'] : '/';
		if ( '' === $home_path || '/' !== $home_path[0] ) {
			$home_path = '/' . ltrim( $home_path, '/' );
		}
		if ( '/' !== substr( $home_path, -1 ) ) {
			$home_path .= '/';
		}
		$exclude_json    = wp_json_encode( $exclude_patterns, JSON_HEX_TAG );
		$site_host_json  = wp_json_encode( $home_host_full, JSON_HEX_TAG );
		$site_proto_json = wp_json_encode( ( wp_parse_url( home_url(), PHP_URL_SCHEME ) ?: 'https' ) . ':', JSON_HEX_TAG );
		$site_path_json  = wp_json_encode( $home_path, JSON_HEX_TAG );
		?>
		<script id="pc-preload-links">
		(function(){
			if(navigator.connection&&navigator.connection.saveData)return;
			<?php
			// The four values below are all wp_json_encode() output (JSON_HEX_TAG),
			// already safe for embedding directly in an inline <script>.
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			var done={},exc=<?php echo $exclude_json; ?>,rate=3,sent=0,queue=[],timer=null,siteHost=<?php echo $site_host_json; ?>,siteProto=<?php echo $site_proto_json; ?>,sitePath=<?php echo $site_path_json; ?>;
			<?php // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			// Strict same-origin check via URL parser. URL.host carries the
			// non-default port automatically, so comparing it directly catches
			// both host and port mismatches. Scheme is compared separately to
			// catch http vs https mixed-content cases. The path prefix check
			// keeps subdirectory installs (e.g. `/blog/`) from prefetching
			// sibling apps (`/shop/...`) on the same host.
			function sameHost(u){
				try{
					var p=new URL(u,location.href);
					if(p.host!==siteHost&&p.host!==location.host)return false;
					if(p.protocol!==siteProto&&p.protocol!==location.protocol)return false;
					if(sitePath!=='/'){
						var pp=p.pathname||'/';
						if(pp.indexOf(sitePath)!==0&&(pp+'/').indexOf(sitePath)!==0)return false;
					}
					return true;
				}catch(e){return false;}
			}
			function ok(u){
				if(!u||done[u])return false;
				if(!sameHost(u))return false;
				for(var i=0;i<exc.length;i++){if(u.indexOf(exc[i])!==-1)return false;}
				return true;
			}
			function send(u){
				done[u]=1;
				var l=document.createElement('link');l.rel='prefetch';l.href=u;document.head.appendChild(l);
			}
			function drain(){
				sent=0;
				var batch=Math.min(rate,queue.length);
				for(var i=0;i<batch;i++){send(queue.shift());sent++;}
				if(queue.length>0){timer=setTimeout(drain,1000);}else{timer=null;}
			}
			function pf(u){
				if(!ok(u))return;done[u]=1;
				queue.push(u);
				if(!timer){timer=setTimeout(drain,0);}
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
				// Observe every <a> with an href; sameHost() in pf()→ok() does the
				// real filtering. Earlier code narrowed the selector via the
				// (now-removed) siteUrl prefix; keeping observation broad and
				// rejecting in pf() keeps the JS simpler and host-equality strict.
				document.querySelectorAll('a[href]').forEach(function(a){obs.observe(a);});
			}
		})();
		</script>
		<?php
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
