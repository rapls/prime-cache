<?php
/**
 * Main plugin class.
 *
 * Orchestrates initialization, activation, and deactivation.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache {

	/**
	 * @var Prime_Cache|null
	 */
	private static $instance = null;

	/**
	 * @var Prime_Cache_Purge|null
	 */
	private $purge = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Prime_Cache
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->purge = new Prime_Cache_Purge();
		new Prime_Cache_File_Optimizer();
		new Prime_Cache_Preload();
		new Prime_Cache_LazyLoad();
		new Prime_Cache_Media_Optimizer();

		// Pro classes — initialized by prime-cache-pro add-on:
		// Database_Optimizer, Varnish, Sucuri, Heartbeat, CDN, Cloudflare, WebP
		new Prime_Cache_Post_Metabox();
		new Prime_Cache_Compatibility();
		new Prime_Cache_Performance_Tweaks();

		// Dashboard widget.
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );

		// Disable WordPress emoji.
		if ( ! empty( prime_cache_get_settings()['disable_emoji'] ) ) {
			add_action( 'init', array( $this, 'disable_emoji' ) );
		}

		if ( is_admin() ) {
			new Prime_Cache_Admin_Settings();
		}

		// Admin bar menu.
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 100 );

		// Action handlers — use closure to avoid OPcache method resolution issues.
		$self = $this;
		add_action( 'init', function() use ( $self ) {
			$self->maybe_handle_actions();
		} );

		add_action( 'admin_init', array( $this, 'handle_stats_reset' ) );
		add_action( 'admin_init', array( $this, 'handle_export' ) );
		add_action( 'admin_init', array( $this, 'handle_import' ) );
		add_action( 'admin_init', array( $this, 'handle_object_cache_switch' ) );

		// Expired cache cleanup cron (handler only — scheduling done on activation).
		add_action( 'prime_cache_cleanup_expired', array( $this, 'cleanup_expired_cache' ) );
	}

	/**
	 * Plugin activation.
	 */
	public static function activate() {
		$settings = prime_cache_get_settings();
		$warnings = array();

		// Multisite: page caching is not supported (pre-WP blog_id resolution
		// is too complex to do safely). Warn and skip dropin installation.
		if ( is_multisite() ) {
			$warnings[] = __( 'Prime Cache page caching is not supported on WordPress multisite. Other features (file optimization, lazy load, etc.) work normally.', 'prime-cache' );
			set_transient( 'prime_cache_activation_warnings', $warnings, 120 );
			return;
		}

		// Create cache directory.
		wp_mkdir_p( PRIME_CACHE_CACHE_DIR );

		// Write config file.
		Prime_Cache_Config::write_config_file( $settings );

		// Install advanced-cache.php dropin.
		$ac_result = Prime_Cache_Config::install_advanced_cache();
		if ( ! $ac_result ) {
			$owner = Prime_Cache_Config::get_advanced_cache_owner();
			// Only warn if an external plugin owns the file.
			// If the file is already ours, install may have failed due to
			// permissions but the dropin is still functional — skip warning.
			if ( 'external' === $owner ) {
				$warnings[] = __( 'advanced-cache.php is managed by another plugin. Prime Cache page caching will not work until the other plugin is deactivated.', 'prime-cache' );
			}
		}

		// Enable WP_CACHE in wp-config.php.
		// If already defined as true at runtime, verify the file too.
		if ( defined( 'WP_CACHE' ) && WP_CACHE && Prime_Cache_Config::verify_wp_cache_enabled() ) {
			// Already correct — no action needed.
		} else {
			$wp_cache_result = Prime_Cache_Config::set_wp_cache( true );
			if ( ! $wp_cache_result ) {
				$warnings[] = __( 'WP_CACHE could not be set to true. wp-config.php may not be writable.', 'prime-cache' );
			} elseif ( ! Prime_Cache_Config::verify_wp_cache_enabled() ) {
				$warnings[] = __( 'WP_CACHE is defined as false by another source in wp-config.php. Page caching will not work until this is corrected.', 'prime-cache' );
			}
		}

		// Schedule cron events.
		if ( ! wp_next_scheduled( 'prime_cache_cleanup_expired' ) ) {
			wp_schedule_event( time(), 'hourly', 'prime_cache_cleanup_expired' );
		}

		// Write .htaccess rules if enabled.
		if ( ! empty( $settings['htaccess_enabled'] ) ) {
			Prime_Cache_Htaccess::add_rules( $settings );
		}

		if ( ! empty( $warnings ) ) {
			set_transient( 'prime_cache_activation_warnings', $warnings, 120 );
		}
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate() {
		// Unschedule crons.
		$timestamp = wp_next_scheduled( 'prime_cache_cleanup_expired' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'prime_cache_cleanup_expired' );
		}
		wp_clear_scheduled_hook( 'prime_cache_preload_batch' );
		wp_clear_scheduled_hook( 'prime_cache_cf_deferred_purge' );
		wp_clear_scheduled_hook( 'prime_cache_cf_retry_full_purge' );
		delete_option( 'prime_cache_cf_full_purge_retries' );
		delete_option( 'prime_cache_cf_purge_retries' );
		delete_option( 'prime_cache_cf_purge_failed' );
		if ( class_exists( 'Prime_Cache_Database_Optimizer' ) ) {
			Prime_Cache_Database_Optimizer::unschedule();
		}

		// Remove .htaccess rules.
		Prime_Cache_Htaccess::remove_rules();

		// Remove advanced-cache.php.
		Prime_Cache_Config::uninstall_advanced_cache();

		// Remove object-cache.php if ours.
		Prime_Cache_Config::setup_object_cache( 'off' );

		// Disable WP_CACHE.
		Prime_Cache_Config::set_wp_cache( false );

		// Delete config file.
		Prime_Cache_Config::delete_config_file();

		// Clear all cached files.
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $host ) {
			Prime_Cache_Storage::delete_host( $host );
		}
	}

	/**
	 * Add purge button to admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function admin_bar_menu( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$n   = wp_create_nonce( 'prime_cache_admin_action' );
		$s   = prime_cache_get_settings();
		$url = function( $action, $extra = '' ) use ( $n ) {
			return admin_url( 'admin.php?pc_action=' . $action . $extra . '&_wpnonce=' . $n );
		};

		// ── Parent ───────────────────────────────────────────
		$wp_admin_bar->add_node( array(
			'id'    => 'prime-cache',
			'title' => '<span class="ab-icon dashicons dashicons-performance" style="font:normal 20px/1 dashicons;margin-right:6px;vertical-align:middle;opacity:.9"></span>Prime Cache',
			'href'  => admin_url( 'admin.php?page=prime-cache' ),
		) );

		// ── Cache clearing ───────────────────────────────────

		// Clear All Cache.
		$wp_admin_bar->add_node( array(
			'id' => 'pc-clear-all', 'parent' => 'prime-cache',
			'title' => __( 'Clear All Cache', 'prime-cache' ),
			'href'  => $url( 'clear_all' ),
		) );

		// Clear and Preload (WP-Rocket style).
		if ( $s['preload_enabled'] ) {
			$wp_admin_bar->add_node( array(
				'id' => 'pc-clear-preload', 'parent' => 'prime-cache',
				'title' => __( 'Clear Cache & Preload', 'prime-cache' ),
				'href'  => $url( 'clear_and_preload' ),
			) );
		}

		// Clear Page Cache Only.
		$wp_admin_bar->add_node( array(
			'id' => 'pc-clear-page', 'parent' => 'prime-cache',
			'title' => __( 'Clear Page Cache Only', 'prime-cache' ),
			'href'  => $url( 'clear_page_cache' ),
		) );

		// Clear Minified CSS/JS.
		$wp_admin_bar->add_node( array(
			'id' => 'pc-clear-minified', 'parent' => 'prime-cache',
			'title' => __( 'Clear Minified CSS/JS', 'prime-cache' ),
			'href'  => $url( 'clear_minified' ),
		) );

		// Clear Critical CSS.
		if ( $s['critical_css_auto'] || $s['async_css'] ) {
			$wp_admin_bar->add_node( array(
				'id' => 'pc-clear-ccss', 'parent' => 'prime-cache',
				'title' => __( 'Clear Critical CSS', 'prime-cache' ),
				'href'  => $url( 'clear_critical_css' ),
			) );
		}

		// Clear Object Cache.
		$oc = Prime_Cache_Config::get_active_object_cache();
		if ( 'off' !== $oc && 'external' !== $oc ) {
			$wp_admin_bar->add_node( array(
				'id' => 'pc-clear-oc', 'parent' => 'prime-cache',
				'title' => __( 'Clear Object Cache', 'prime-cache' ),
				'href'  => $url( 'clear_object_cache' ),
			) );
		}

		// Purge Varnish Cache.
		if ( $s['varnish_enabled'] ) {
			$wp_admin_bar->add_node( array(
				'id' => 'pc-clear-varnish', 'parent' => 'prime-cache',
				'title' => __( 'Purge Varnish Cache', 'prime-cache' ),
				'href'  => $url( 'purge_varnish' ),
			) );
		}

		// Purge Sucuri Cache.
		if ( $s['sucuri_enabled'] ) {
			$wp_admin_bar->add_node( array(
				'id' => 'pc-clear-sucuri', 'parent' => 'prime-cache',
				'title' => __( 'Purge Sucuri Cache', 'prime-cache' ),
				'href'  => $url( 'purge_sucuri' ),
			) );
		}

		// Purge Cloudflare Cache.
		if ( $s['cloudflare_enabled'] ) {
			$wp_admin_bar->add_node( array(
				'id' => 'pc-clear-cf', 'parent' => 'prime-cache',
				'title' => __( 'Purge Cloudflare Cache', 'prime-cache' ),
				'href'  => $url( 'purge_cloudflare' ),
			) );
		}

		// ── Context-specific ─────────────────────────────────

		// Clear This Page (frontend).
		if ( ! is_admin() ) {
			global $wp;
			$page_url = home_url( add_query_arg( array(), $wp->request ) );
			$wp_admin_bar->add_node( array(
				'id' => 'pc-clear-this', 'parent' => 'prime-cache',
				'title' => __( 'Clear This Page', 'prime-cache' ),
				'href'  => $url( 'clear_url', '&pc_url=' . rawurlencode( $page_url ) ),
			) );
		}

		// Clear This Post (admin post editor).
		if ( is_admin() ) {
			global $post;
			if ( $post && 'publish' === get_post_status( $post ) ) {
				$wp_admin_bar->add_node( array(
					'id' => 'pc-clear-post', 'parent' => 'prime-cache',
					'title' => __( 'Clear This Post', 'prime-cache' ),
					'href'  => $url( 'clear_post', '&pc_post_id=' . (int) $post->ID ),
				) );
			}
		}

		// ── Preload ──────────────────────────────────────────
		if ( $s['preload_enabled'] ) {
			$wp_admin_bar->add_node( array(
				'id' => 'pc-sep1', 'parent' => 'prime-cache',
				'title' => '<hr style="margin:4px 0;border:none;border-top:1px solid rgba(255,255,255,.15)">',
			) );
			$wp_admin_bar->add_node( array(
				'id' => 'pc-preload', 'parent' => 'prime-cache',
				'title' => __( 'Start Preload', 'prime-cache' ),
				'href'  => $url( 'start_preload' ),
			) );
		}

		// ── Settings ─────────────────────────────────────────
		$wp_admin_bar->add_node( array(
			'id' => 'pc-sep2', 'parent' => 'prime-cache',
			'title' => '<hr style="margin:4px 0;border:none;border-top:1px solid rgba(255,255,255,.15)">',
		) );
		$wp_admin_bar->add_node( array(
			'id' => 'pc-settings', 'parent' => 'prime-cache',
			'title' => __( 'Settings', 'prime-cache' ),
			'href'  => admin_url( 'admin.php?page=prime-cache' ),
		) );
	}

	/**
	 * Handle all admin bar actions. Called directly from constructor, not via hook.
	 */
	public function maybe_handle_actions() {
		if ( ! isset( $_GET['pc_action'] ) ) {
			// Backward compat: old purge URL.
			if ( isset( $_GET['prime_cache_purge'] ) ) {
				if ( current_user_can( 'manage_options' ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'prime_cache_purge' ) ) {
					$this->purge->purge_all();
					wp_safe_redirect( add_query_arg( 'prime_cache_cleared', '1', remove_query_arg( array( 'prime_cache_purge', '_wpnonce' ) ) ) );
					exit;
				}
			}
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'prime_cache_admin_action' ) ) {
			return;
		}

		$action = sanitize_key( $_GET['pc_action'] );
		$msg    = '';

		switch ( $action ) {
			case 'clear_all':
				self::sync_stats_to_db(); // Persist stats before cache dir is cleared.
				$this->purge->purge_all();
				$this->clear_minified_files();
				$this->clear_critical_css_files();
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
				$msg = 'all';
				break;

			case 'clear_and_preload':
				self::sync_stats_to_db();
				$this->purge->purge_all();
				$this->clear_minified_files();
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
				// Preload will start via the prime_cache_after_purge_all hook.
				$msg = 'preload';
				break;

			case 'clear_page_cache':
				$this->purge->purge_all();
				$msg = 'page';
				break;

			case 'clear_minified':
				$this->clear_minified_files();
				$msg = 'minified';
				break;

			case 'clear_critical_css':
				$this->clear_critical_css_files();
				$msg = 'ccss';
				break;

			case 'clear_object_cache':
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
				$msg = 'object';
				break;

			case 'clear_url':
				$url = isset( $_GET['pc_url'] ) ? esc_url_raw( rawurldecode( $_GET['pc_url'] ) ) : '';
				if ( $url ) {
					Prime_Cache_Storage::delete_url( $url );
				}
				$msg = 'url';
				break;

			case 'clear_post':
				$post_id = isset( $_GET['pc_post_id'] ) ? (int) $_GET['pc_post_id'] : 0;
				if ( $post_id ) {
					$this->purge->purge_post_and_related( $post_id );
				}
				$msg = 'post';
				break;

			case 'purge_cloudflare':
				if ( class_exists( 'Prime_Cache_Cloudflare' ) ) {
					$cf = new Prime_Cache_Cloudflare();
					$cf->purge_everything();
				}
				$msg = 'cloudflare';
				break;

			case 'purge_sucuri':
				if ( class_exists( 'Prime_Cache_Sucuri' ) ) {
					$sucuri = new Prime_Cache_Sucuri();
					$result = $sucuri->purge();
				}
				$msg = isset( $result ) && is_wp_error( $result ) ? 'sucuri_error' : 'sucuri';
				break;

			case 'purge_varnish':
				if ( class_exists( 'Prime_Cache_Varnish' ) ) {
					$varnish = new Prime_Cache_Varnish();
					$varnish->purge_all();
				}
				$msg = 'varnish';
				break;

			case 'start_preload':
				wp_clear_scheduled_hook( 'prime_cache_preload_batch' );
				wp_schedule_single_event( time() + 3, 'prime_cache_preload_batch' );
				$s_tmp = prime_cache_get_settings();
				$msg = ! empty( trim( $s_tmp['cache_vary_cookies'] ?? '' ) ) ? 'preload_started_partial' : 'preload_started';
				break;

			case 'reset_settings':
				delete_option( 'prime_cache_settings' );
				$defaults = prime_cache_get_settings( true );
				if ( ! is_multisite() ) {
					Prime_Cache_Config::write_config_file( $defaults );
					$ac_ok = Prime_Cache_Config::install_advanced_cache();
					Prime_Cache_Htaccess::remove_rules();
					if ( ! $ac_ok && 'external' === Prime_Cache_Config::get_advanced_cache_owner() ) {
						set_transient( 'prime_cache_env_warnings', array(
							__( 'Settings reset but advanced-cache.php is managed by another plugin. Page caching will not work until the other plugin is deactivated.', 'prime-cache' ),
						), 60 );
					}
				}
				$redirect = add_query_arg( array( 'tab' => 'tools', 'pc_cleared' => 'reset' ), admin_url( 'admin.php?page=prime-cache' ) );
				wp_safe_redirect( $redirect );
				exit;

			case 'apply_preset':
				$preset = sanitize_key( $_GET['preset'] ?? '' );
				$preset_settings = self::get_preset( $preset );
				if ( $preset_settings ) {
					$current = prime_cache_get_settings();
					$merged  = array_merge( $current, $preset_settings );
					update_option( 'prime_cache_settings', $merged );
					if ( ! is_multisite() ) {
						Prime_Cache_Config::write_config_file( $merged );
						$ac_ok = Prime_Cache_Config::install_advanced_cache();
						if ( $merged['htaccess_enabled'] ) {
							Prime_Cache_Htaccess::add_rules( $merged );
						} else {
							Prime_Cache_Htaccess::remove_rules();
						}
						if ( ! $ac_ok && 'external' === Prime_Cache_Config::get_advanced_cache_owner() ) {
							set_transient( 'prime_cache_env_warnings', array(
								__( 'Preset applied but advanced-cache.php is managed by another plugin. Page caching will not work until the other plugin is deactivated.', 'prime-cache' ),
							), 60 );
						}
					}
				}
				$redirect = add_query_arg( array( 'tab' => 'tools', 'pc_preset' => $preset ), admin_url( 'admin.php?page=prime-cache' ) );
				wp_safe_redirect( $redirect );
				exit;

			case 'toggle_cache':
				$s = prime_cache_get_settings();
				$s['cache_enabled'] = empty( $s['cache_enabled'] );
				update_option( 'prime_cache_settings', $s );
				if ( ! is_multisite() ) {
					Prime_Cache_Config::write_config_file( $s );
				}
				$tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );
				wp_safe_redirect( admin_url( 'admin.php?page=prime-cache&tab=' . $tab ) );
				exit;

			default:
				return;
		}

		$redirect = remove_query_arg( array( 'pc_action', 'pc_url', '_wpnonce' ) );
		$redirect = add_query_arg( 'pc_cleared', $msg, $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Get preset settings for a given mode.
	 *
	 * @param string $preset Preset slug: safe, balanced, aggressive.
	 * @return array|false Settings overrides, or false if invalid.
	 */
	public static function get_preset( $preset ) {
		$common = array(
			'cache_enabled'    => true,
			'cache_mobile'     => true,
			'gzip_compression' => true,
			'cache_footprint'  => true,
		);

		switch ( $preset ) {
			case 'safe':
				return array_merge( $common, array(
					'lazyload_images'     => true,
					'lazyload_iframes'    => true,
					'browser_cache'       => true,
					'htaccess_enabled'    => true,
					'preload_links'       => true,
					// Keep everything else at defaults (off).
					'minify_html'         => false,
					'minify_css'          => false,
					'minify_js'           => false,
					'combine_css'         => false,
					'combine_js'          => false,
					'defer_js'            => false,
					'delay_js'            => false,
					'optimize_css_delivery' => false,
				) );

			case 'balanced':
				return array_merge( $common, array(
					'lazyload_images'      => true,
					'lazyload_iframes'     => true,
					'lazyload_videos'      => true,
					'browser_cache'        => true,
					'htaccess_enabled'     => true,
					'preload_links'        => true,
					'minify_html'          => true,
					'minify_css'           => true,
					'minify_js'            => true,
					'remove_html_comments' => true,
					'disable_emoji'        => true,
					'disable_wp_embed'     => true,
					'remove_query_strings' => true,
					'preload_fonts'        => true,
					// Keep combining/deferring off for stability.
					'combine_css'          => false,
					'combine_js'           => false,
					'defer_js'             => false,
					'delay_js'             => false,
				) );

			case 'aggressive':
				return array_merge( $common, array(
					'lazyload_images'       => true,
					'lazyload_iframes'      => true,
					'lazyload_videos'       => true,
					'browser_cache'         => true,
					'htaccess_enabled'      => true,
					'preload_links'         => true,
					'minify_html'           => true,
					'minify_css'            => true,
					'minify_js'             => true,
					'remove_html_comments'  => true,
					'combine_css'           => true,
					'combine_js'            => true,
					'defer_js'              => true,
					'delay_js'              => true,
					'delay_js_safe_mode'    => true,
					'optimize_css_delivery' => true,
					'css_delivery_method'   => 'async_css',
					'critical_css_auto'     => true,
					'inline_small_css'      => true,
					'disable_emoji'         => true,
					'disable_wp_embed'      => true,
					'disable_dashicons'     => true,
					'remove_query_strings'  => true,
					'preload_fonts'         => true,
					'preload_enabled'       => true,
					'preload_homepage'      => true,
					'preload_public_posts'  => true,
					'lcp_optimization'      => true,
					'speculation_rules'     => true,
				) );

			case 'auto':
				return self::get_preset_auto();

			default:
				return false;
		}
	}

	/**
	 * Build an optimised preset by inspecting the server & WordPress environment.
	 *
	 * Detection order:
	 *  1. Start from Balanced as a safe-but-useful baseline.
	 *  2. Promote features the environment can handle.
	 *  3. Demote features that would conflict.
	 */
	private static function get_preset_auto() {

		// ── Environment probes ───────────────────────────────────────
		$htaccess_ok = is_writable( ABSPATH . '.htaccess' ) || ( file_exists( ABSPATH . '.htaccess' ) && is_writable( ABSPATH ) );

		// HTTP/2 detection: check via headers_list() or SERVER_PROTOCOL.
		$http2 = false;
		if ( function_exists( 'apache_get_modules' ) && in_array( 'mod_http2', apache_get_modules(), true ) ) {
			$http2 = true;
		} elseif ( ! empty( $_SERVER['SERVER_PROTOCOL'] ) && version_compare( str_replace( 'HTTP/', '', $_SERVER['SERVER_PROTOCOL'] ), '2', '>=' ) ) {
			$http2 = true;
		} elseif ( ! empty( $_SERVER['HTTP2'] ) || ! empty( $_SERVER['H2'] ) ) {
			$http2 = true;
		}

		// PHP extensions for object cache.
		$has_redis     = class_exists( 'Redis' );
		$has_memcached = class_exists( 'Memcached' );
		$has_apcu      = function_exists( 'apcu_add' );

		// Image conversion capability.
		$gd_webp      = function_exists( 'imagewebp' );
		$imagick_webp = extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) && in_array( 'WEBP', \Imagick::queryFormats(), true );
		$can_webp     = $gd_webp || $imagick_webp;

		// WordPress / plugin detection.
		$has_woo       = class_exists( 'WooCommerce' );
		$is_block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$post_count    = (int) wp_count_posts()->publish;
		$is_pro        = prime_cache_is_pro();

		// ── Build settings ───────────────────────────────────────────
		$s = array(
			// Core — always on.
			'cache_enabled'         => true,
			'cache_mobile'          => true,
			'gzip_compression'      => true,
			'cache_footprint'       => false, // production default

			// Lazy load — universally safe.
			'lazyload_images'       => true,
			'lazyload_iframes'      => true,
			'lazyload_videos'       => true,

			// Browser cache — universally beneficial.
			'browser_cache'         => true,

			// .htaccess — only when writable (works on Xserver Nginx too).
			'htaccess_enabled'      => $htaccess_ok,

			// Minification — safe on all environments.
			'minify_html'           => true,
			'minify_css'            => true,
			'minify_js'             => true,
			'remove_html_comments'  => true,

			// Combine — only when NOT HTTP/2 (HTTP/2 multiplexing makes combining counterproductive).
			'combine_css'           => ! $http2 && $is_pro,
			'combine_js'            => ! $http2 && $is_pro,

			// Defer JS — safe with safe-mode.
			'defer_js'              => $is_pro,
			'delay_js'              => false, // too risky for auto
			'delay_js_safe_mode'    => true,

			// CSS delivery — async with auto critical CSS when Pro.
			'optimize_css_delivery' => $is_pro,
			'css_delivery_method'   => 'async_css',
			'critical_css_auto'     => $is_pro,

			// Inline small CSS.
			'inline_small_css'      => $is_pro,

			// Cleanup — safe tweaks.
			'disable_emoji'         => true,
			'disable_wp_embed'      => true,
			'remove_query_strings'  => true,

			// Block CSS — keep enabled for block themes.
			'disable_block_css'     => ! $is_block_theme,

			// Links / speculation.
			'preload_links'         => true,
			'speculation_rules'     => $is_pro,

			// Preload.
			'preload_enabled'       => $is_pro,
			'preload_homepage'      => $is_pro,
			'preload_public_posts'  => $is_pro,
			'preload_public_tax'    => $is_pro,

			// Font preloading.
			'preload_fonts'         => $is_pro,

			// LCP.
			'lcp_optimization'      => $is_pro,
		);

		// ── Preload tuning by site size ──────────────────────────────
		if ( $post_count > 2000 ) {
			$s['preload_max_posts'] = 2000;
			$s['preload_interval']  = 3;
		} elseif ( $post_count > 500 ) {
			$s['preload_max_posts'] = 1000;
			$s['preload_interval']  = 2;
		}

		// ── WooCommerce optimizations ────────────────────────────────
		if ( $has_woo ) {
			$s['woo_disable_scripts']   = true;
			$s['woo_disable_cart_frag'] = true;
			// Never delay JS on WooCommerce — too many inline scripts.
			$s['delay_js']              = false;
			// Add standard WooCommerce exclusions.
			$s['cache_reject_uri']      = 'cart|checkout|my-account|wc-api|add-to-cart';
		}

		// ── Image conversion (Pro) ───────────────────────────────────
		if ( $is_pro && $can_webp ) {
			$s['img_conversion_enabled'] = true;
			$s['webp_enabled']           = true;
			$s['img_auto_optimize']      = true;
			$s['img_auto_remove_larger'] = true;
			$s['img_include_uploads']    = true;
		}

		// ── Object cache (Pro) ───────────────────────────────────────
		// Prefer Redis > Memcached > APCu based on available extensions.
		if ( $is_pro ) {
			if ( $has_redis ) {
				$s['object_cache'] = 'redis';
			} elseif ( $has_memcached ) {
				$s['object_cache'] = 'memcached';
			} elseif ( $has_apcu ) {
				$s['object_cache'] = 'apcu';
			}
		}

		return $s;
	}

	/**
	 * Return a human-readable summary of the detected environment
	 * for display in the Auto preset card.
	 */
	public static function get_auto_environment_summary() {
		$info = array();

		// Web server.
		$server = 'Unknown';
		$sw     = $_SERVER['SERVER_SOFTWARE'] ?? '';
		if ( stripos( $sw, 'apache' ) !== false ) {
			$server = 'Apache';
		} elseif ( stripos( $sw, 'nginx' ) !== false ) {
			$server = 'Nginx';
		} elseif ( stripos( $sw, 'litespeed' ) !== false ) {
			$server = 'LiteSpeed';
		} elseif ( $sw ) {
			$server = strtok( $sw, '/' );
		}
		$info[ __( 'Web Server', 'prime-cache' ) ] = $server;

		// .htaccess.
		$ht_ok = is_writable( ABSPATH . '.htaccess' ) || ( file_exists( ABSPATH . '.htaccess' ) && is_writable( ABSPATH ) );
		$info[ '.htaccess' ] = $ht_ok ? __( 'Writable', 'prime-cache' ) : __( 'Not writable', 'prime-cache' );

		// HTTP protocol.
		$proto = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
		if ( ! empty( $_SERVER['HTTP2'] ) || ! empty( $_SERVER['H2'] ) ) {
			$proto = 'HTTP/2';
		}
		$info[ __( 'Protocol', 'prime-cache' ) ] = $proto;

		// PHP.
		$info[ 'PHP' ] = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

		// Image libraries.
		$libs = array();
		if ( function_exists( 'imagewebp' ) )  $libs[] = 'GD';
		if ( extension_loaded( 'imagick' ) )    $libs[] = 'Imagick';
		$info[ __( 'Image Library', 'prime-cache' ) ] = $libs ? implode( ', ', $libs ) : __( 'None', 'prime-cache' );

		// Object cache backends.
		$oc = array();
		if ( class_exists( 'Redis' ) )       $oc[] = 'Redis';
		if ( class_exists( 'Memcached' ) )   $oc[] = 'Memcached';
		if ( function_exists( 'apcu_add' ) ) $oc[] = 'APCu';
		$info[ __( 'Object Cache', 'prime-cache' ) ] = $oc ? implode( ', ', $oc ) : __( 'None', 'prime-cache' );

		// Theme.
		$theme = wp_get_theme();
		$type  = ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() )
			? __( 'Block Theme', 'prime-cache' )
			: __( 'Classic Theme', 'prime-cache' );
		$info[ __( 'Theme', 'prime-cache' ) ] = $theme->get( 'Name' ) . ' (' . $type . ')';

		// Posts.
		$info[ __( 'Published Posts', 'prime-cache' ) ] = number_format_i18n( (int) wp_count_posts()->publish );

		// WooCommerce.
		if ( class_exists( 'WooCommerce' ) ) {
			$info[ 'WooCommerce' ] = WC()->version;
		}

		// Pro.
		$info[ 'Prime Cache Pro' ] = prime_cache_is_pro()
			? __( 'Active', 'prime-cache' )
			: __( 'Inactive', 'prime-cache' );

		return $info;
	}

	/**
	 * Delete all minified/combined CSS/JS files in the file optimizer cache.
	 */
	private function clear_minified_files() {
		$fo_dir = WP_CONTENT_DIR . '/cache/prime-cache-fo/';
		if ( ! is_dir( $fo_dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $fo_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}
		delete_transient( 'prime_cache_dir_stats' );
	}

	/**
	 * Delete generated critical CSS cache files.
	 */
	private function clear_critical_css_files() {
		$ccss_dir = WP_CONTENT_DIR . '/cache/prime-cache-fo/ccss/';
		if ( ! is_dir( $ccss_dir ) ) {
			return;
		}
		$files = glob( $ccss_dir . '*.css' );
		if ( $files ) {
			foreach ( $files as $file ) {
				@unlink( $file );
			}
		}
	}

	/**
	 * Handle stats reset request.
	 */
	public function handle_stats_reset() {
		if ( ! isset( $_GET['prime_cache_reset_stats'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'prime_cache_reset_stats' ) ) {
			return;
		}

		// Reset DB stats.
		update_option( 'prime_cache_stats', array( 'hit' => 0, 'miss' => 0, 'since' => time() ), false );

		// Reset file-based stats.
		$stats_file = PRIME_CACHE_CACHE_DIR . 'stats.json';
		$data = wp_json_encode( array( 'hit' => 0, 'miss' => 0, 'since' => time() ) );
		$fp = fopen( $stats_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $fp ) {
			flock( $fp, LOCK_EX );
			ftruncate( $fp, 0 );
			fseek( $fp, 0 );
			fwrite( $fp, $data );
			flock( $fp, LOCK_UN );
			fclose( $fp );
		}

		$redirect = remove_query_arg( array( 'prime_cache_reset_stats', '_wpnonce' ) );
		$redirect = add_query_arg( 'prime_cache_stats_reset', '1', $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Sync file-based stats into the database and reset the file counters.
	 *
	 * Called before cache directory deletion so stats survive purge_all.
	 */
	public static function sync_stats_to_db() {
		$stats_file = PRIME_CACHE_CACHE_DIR . 'stats.json';
		if ( ! is_readable( $stats_file ) ) {
			return;
		}

		$fp = fopen( $stats_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fp ) {
			return;
		}

		flock( $fp, LOCK_EX );
		fseek( $fp, 0 );
		$raw = stream_get_contents( $fp );
		$file_stats = $raw ? json_decode( $raw, true ) : null;

		if ( is_array( $file_stats ) ) {
			$db = get_option( 'prime_cache_stats', array( 'hit' => 0, 'miss' => 0, 'since' => 0 ) );
			$db['hit']  = (int) ( $db['hit'] ?? 0 ) + (int) ( $file_stats['hit'] ?? 0 );
			$db['miss'] = (int) ( $db['miss'] ?? 0 ) + (int) ( $file_stats['miss'] ?? 0 );
			if ( ! $db['since'] && ! empty( $file_stats['since'] ) ) {
				$db['since'] = (int) $file_stats['since'];
			}
			update_option( 'prime_cache_stats', $db, false );

			// Reset file counters to zero (keep since).
			ftruncate( $fp, 0 );
			fseek( $fp, 0 );
			fwrite( $fp, json_encode( array( 'hit' => 0, 'miss' => 0, 'since' => $db['since'] ) ) );
		}

		flock( $fp, LOCK_UN );
		fclose( $fp );
	}

	/**
	 * Handle object cache backend switch.
	 */
	public function handle_object_cache_switch() {
		if ( ! isset( $_GET['prime_cache_object_cache'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'prime_cache_object_cache' ) ) {
			return;
		}

		$backend = sanitize_key( $_GET['prime_cache_object_cache'] );
		$allowed = array_merge( array( 'off' ), array_keys( Prime_Cache_Config::get_available_object_caches() ) );

		if ( ! in_array( $backend, $allowed, true ) ) {
			return;
		}

		$result = Prime_Cache_Config::setup_object_cache( $backend );

		if ( $result ) {
			// Flush cache when switching backends.
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
			$redirect = admin_url( 'admin.php?page=prime-cache&tab=object-cache&prime_cache_oc_switched=1' );
		} else {
			$redirect = admin_url( 'admin.php?page=prime-cache&tab=object-cache&prime_cache_oc_switch_failed=1' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Register dashboard widget.
	 */
	public function add_dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		wp_add_dashboard_widget( 'prime_cache_dashboard', 'Prime Cache', array( $this, 'render_dashboard_widget' ) );
	}

	/**
	 * Render dashboard widget content.
	 */
	public function render_dashboard_widget() {
		// Merge DB baseline + file increments.
		$db = get_option( 'prime_cache_stats', array( 'hit' => 0, 'miss' => 0, 'since' => 0 ) );
		$hs = wp_parse_args( $db, array( 'hit' => 0, 'miss' => 0, 'since' => 0 ) );
		$stats_file = PRIME_CACHE_CACHE_DIR . 'stats.json';
		if ( is_readable( $stats_file ) ) {
			$d = json_decode( file_get_contents( $stats_file ), true ); // phpcs:ignore
			if ( is_array( $d ) ) {
				$hs['hit']  += (int) ( $d['hit'] ?? 0 );
				$hs['miss'] += (int) ( $d['miss'] ?? 0 );
				if ( ! $hs['since'] && ! empty( $d['since'] ) ) {
					$hs['since'] = (int) $d['since'];
				}
			}
		}
		$total = $hs['hit'] + $hs['miss'];
		$rate  = $total > 0 ? round( ( $hs['hit'] / $total ) * 100, 1 ) : 0;

		// Use cached stats to avoid full directory scan on every dashboard load.
		$dir_stats = get_transient( 'prime_cache_dir_stats' );
		if ( false === $dir_stats ) {
			$dir_stats = array( 'files' => 0, 'size' => 0 );
			if ( is_dir( PRIME_CACHE_CACHE_DIR ) ) {
				$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( PRIME_CACHE_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS ) );
				foreach ( $it as $f ) { if ( $f->isFile() && 'html' === $f->getExtension() ) $dir_stats['files']++; if ( $f->isFile() ) $dir_stats['size'] += $f->getSize(); }
			}
			set_transient( 'prime_cache_dir_stats', $dir_stats, 60 );
		}
		$files = $dir_stats['files'];
		$size  = $dir_stats['size'];

		$s  = prime_cache_get_settings();
		$oc = Prime_Cache_Config::get_active_object_cache();
		$n  = wp_create_nonce( 'prime_cache_admin_action' );
		?>
		<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px">
			<div style="text-align:center;padding:10px;background:#f0fdf4;border-radius:8px"><b style="font-size:20px;color:#15803d"><?php echo esc_html( $rate ); ?>%</b><br><span style="font-size:11px;color:#6b7280"><?php esc_html_e( 'Hit Rate', 'prime-cache' ); ?></span></div>
			<div style="text-align:center;padding:10px;background:#f0f9ff;border-radius:8px"><b style="font-size:20px;color:#1d4ed8"><?php echo esc_html( number_format( $files ) ); ?></b><br><span style="font-size:11px;color:#6b7280"><?php esc_html_e( 'Pages', 'prime-cache' ); ?></span></div>
		</div>
		<ul style="margin:0;font-size:13px;line-height:2">
			<li><?php printf( 'HIT: <b>%s</b> / MISS: <b>%s</b>', esc_html( number_format( $hs['hit'] ) ), esc_html( number_format( $hs['miss'] ) ) ); ?></li>
			<li><?php printf( esc_html__( 'Size: %s', 'prime-cache' ), '<b>' . esc_html( size_format( $size ) ) . '</b>' ); ?></li>
			<li><?php esc_html_e( 'Object Cache', 'prime-cache' ); ?>: <b><?php echo 'off' === $oc ? esc_html__( 'Inactive', 'prime-cache' ) : esc_html( strtoupper( $oc ) ); ?></b></li>
			<li><?php esc_html_e( 'Page Cache', 'prime-cache' ); ?>: <b><?php echo $s['cache_enabled'] ? esc_html__( 'Active', 'prime-cache' ) : esc_html__( 'Inactive', 'prime-cache' ); ?></b></li>
		</ul>
		<p style="margin:12px 0 0;display:flex;gap:8px">
			<a href="<?php echo esc_url( admin_url( 'admin.php?pc_action=clear_all&_wpnonce=' . $n ) ); ?>" class="button button-small"><?php esc_html_e( 'Clear Cache', 'prime-cache' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=prime-cache' ) ); ?>" class="button button-small"><?php esc_html_e( 'Settings', 'prime-cache' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Remove WordPress emoji inline CSS, JS, and DNS prefetch.
	 */
	public function disable_emoji() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

		add_filter( 'tiny_mce_plugins', function( $plugins ) {
			return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
		} );

		add_filter( 'wp_resource_hints', function( $urls, $relation_type ) {
			if ( 'dns-prefetch' === $relation_type ) {
				$urls = array_filter( $urls, function( $url ) {
					return false === strpos( $url, 'https://s.w.org/images/core/emoji/' );
				} );
			}
			return $urls;
		}, 10, 2 );
	}

	/**
	 * Export settings as JSON download.
	 */
	public function handle_export() {
		if ( ! isset( $_GET['pc_export_settings'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pc_export' ) ) {
			return;
		}

		$settings = get_option( 'prime_cache_settings', array() );

		// Mask sensitive API keys/secrets before export.
		// Remove API keys/secrets from export. Import will preserve existing
		// values when the key is absent. To intentionally clear a key via import,
		// manually add the key with an empty string to the JSON file.
		$sensitive_keys = array( 'cloudflare_api_key', 'sucuri_api_key' );
		foreach ( $sensitive_keys as $sk ) {
			unset( $settings[ $sk ] );
		}

		$data = wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="prime-cache-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		header( 'Content-Length: ' . strlen( $data ) );
		echo $data;
		exit;
	}

	/**
	 * Import settings from uploaded JSON file.
	 */
	public function handle_import() {
		if ( ! isset( $_POST['pc_import_settings'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'pc_import' ) ) {
			return;
		}

		$error_url = add_query_arg( array( 'tab' => 'tools', 'pc_imported' => 'error' ), admin_url( 'admin.php?page=prime-cache' ) );

		// Validate upload.
		if ( empty( $_FILES['pc_import_file']['tmp_name'] )
			|| ! isset( $_FILES['pc_import_file']['error'] )
			|| UPLOAD_ERR_OK !== (int) $_FILES['pc_import_file']['error']
		) {
			wp_safe_redirect( $error_url );
			exit;
		}

		// Verify it is a real upload (prevents local file inclusion).
		if ( ! is_uploaded_file( $_FILES['pc_import_file']['tmp_name'] ) ) {
			wp_safe_redirect( $error_url );
			exit;
		}

		// Size limit: 256 KB max for a settings JSON.
		if ( $_FILES['pc_import_file']['size'] > 262144 ) {
			wp_safe_redirect( $error_url );
			exit;
		}

		// Extension check.
		$ext = strtolower( pathinfo( $_FILES['pc_import_file']['name'], PATHINFO_EXTENSION ) );
		if ( 'json' !== $ext ) {
			wp_safe_redirect( $error_url );
			exit;
		}

		// MIME type validation via finfo (content-based, not trust client header).
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = finfo_file( $finfo, $_FILES['pc_import_file']['tmp_name'] );
			finfo_close( $finfo );
			// JSON files may report as application/json or text/plain.
			if ( $mime && ! in_array( $mime, array( 'application/json', 'text/plain', 'text/json' ), true ) ) {
				wp_safe_redirect( $error_url );
				exit;
			}
		}

		$content = file_get_contents( $_FILES['pc_import_file']['tmp_name'] ); // phpcs:ignore
		$data    = json_decode( $content, true );

		if ( ! is_array( $data ) ) {
			wp_safe_redirect( $error_url );
			exit;
		}

		// Preserve existing sensitive keys when importing:
		// - Key absent from JSON (our export removes them) → keep existing value
		// - Key present with empty string → intentional clear, allow it
		// - Key present with value → use imported value
		$current  = prime_cache_get_settings();
		$preserve = array( 'cloudflare_api_key', 'sucuri_api_key' );
		foreach ( $preserve as $pk ) {
			if ( ! array_key_exists( $pk, $data ) && ! empty( $current[ $pk ] ) ) {
				$data[ $pk ] = $current[ $pk ];
			}
		}

		// Sanitize imported data through the same pipeline as normal saves.
		$admin = new Prime_Cache_Admin_Settings();
		$sanitized = $admin->sanitize_settings( $data );

		// Save sanitized settings to database.
		update_option( 'prime_cache_settings', $sanitized );

		// Regenerate config files (skip on multisite — page caching not supported).
		$config_ok = true;
		$ac_ok     = true;
		if ( ! is_multisite() ) {
			$config_ok = Prime_Cache_Config::write_config_file( $sanitized );
			$ac_ok     = Prime_Cache_Config::install_advanced_cache();
		}

		// Force refresh the settings cache.
		prime_cache_get_settings( true );

		// Determine import result status.
		$result = 'ok';
		if ( ! $config_ok || ! $ac_ok ) {
			$result = 'partial';
			$warnings = array();
			if ( ! $config_ok ) {
				$warnings[] = __( 'Settings imported but config file could not be written. Cache may not reflect the new settings until the next save.', 'prime-cache' );
			}
			if ( ! $ac_ok ) {
				$ac_owner = Prime_Cache_Config::get_advanced_cache_owner();
				if ( 'external' === $ac_owner ) {
					$warnings[] = __( 'Settings imported but advanced-cache.php is managed by another plugin. Page caching will not work until the other plugin is deactivated.', 'prime-cache' );
				} else {
					$warnings[] = __( 'Settings imported but advanced-cache.php could not be updated.', 'prime-cache' );
				}
			}
			set_transient( 'prime_cache_import_warnings', $warnings, 60 );
		}

		wp_safe_redirect( add_query_arg( array( 'tab' => 'tools', 'pc_imported' => $result ), admin_url( 'admin.php?page=prime-cache' ) ) );
		exit;
	}

	/**
	 * Delete cache files that have exceeded their lifespan.
	 *
	 * Runs via WP-Cron (hourly). Only acts when cache_lifespan > 0.
	 */
	public function cleanup_expired_cache() {
		$settings = prime_cache_get_settings();
		$lifespan = (int) $settings['cache_lifespan'];

		if ( $lifespan <= 0 || ! is_dir( PRIME_CACHE_CACHE_DIR ) ) {
			return;
		}

		$now      = time();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( PRIME_CACHE_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			// Skip stats.json and debug.log — only clean cache content files.
			$basename = $item->getBasename();
			if ( 'stats.json' === $basename || 'debug.log' === $basename ) {
				continue;
			}

			$ext = $item->getExtension();
			if ( ! in_array( $ext, array( 'html', 'gz', 'json' ), true ) ) {
				continue;
			}

			if ( ( $now - $item->getMTime() ) > $lifespan ) {
				@unlink( $item->getPathname() );
			}
		}

		// Remove empty directories.
		$dir_iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( PRIME_CACHE_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $dir_iterator as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			}
		}
	}
}
