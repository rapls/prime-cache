<?php
defined( 'ABSPATH' ) || exit;

class Prime_Cache_Admin_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_cf_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'show_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function handle_cf_dismiss() {
		if ( isset( $_GET['pc_dismiss_cf_alert'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'pc_dismiss_cf' ) && current_user_can( 'manage_options' ) ) {
			delete_option( 'prime_cache_cf_purge_failed' );
			wp_safe_redirect( remove_query_arg( array( 'pc_dismiss_cf_alert', '_wpnonce' ) ) );
			exit;
		}
	}

	public function add_menu() {
		add_menu_page( 'Prime Cache', 'Prime Cache', 'manage_options', 'prime-cache', array( $this, 'render_page' ), 'dashicons-performance', 80 );
	}

	public function register_settings() {
		register_setting( 'prime_cache_settings_group', 'prime_cache_settings', array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
	}

	public function enqueue_assets( $h ) {
		if ( 'toplevel_page_prime-cache' !== $h ) return;
		wp_add_inline_style( 'wp-admin', $this->get_inline_css() );
	}

	public function sanitize_settings( $input ) {
		$defaults  = prime_cache_get_settings();
		$s = array();
		$s['cache_enabled']         = ! empty( $input['cache_enabled'] );
		$s['cache_mobile']          = ! empty( $input['cache_mobile'] );
		// Mobile separate only makes sense when mobile caching is enabled.
		$s['cache_mobile_separate'] = ! empty( $input['cache_mobile_separate'] ) && $s['cache_mobile'];
		$s['cache_logged_in']       = ! empty( $input['cache_logged_in'] );
		$s['gzip_compression']      = ! empty( $input['gzip_compression'] );
		$s['htaccess_enabled']      = ! empty( $input['htaccess_enabled'] );
		$s['cache_lifespan']        = isset( $input['cache_lifespan'] ) ? max( 0, (int) $input['cache_lifespan'] ) : 0;
		$s['cache_footprint']       = ! empty( $input['cache_footprint'] );
		$s['cache_reject_uri']      = $this->sanitize_regex_field( $input['cache_reject_uri'] ?? '' );
		$s['cache_reject_cookies']  = $this->sanitize_regex_field( $input['cache_reject_cookies'] ?? '' );
		$s['cache_reject_ua']       = $this->sanitize_regex_field( $input['cache_reject_ua'] ?? '' );
		$s['cache_reject_referrer'] = $this->sanitize_regex_field( $input['cache_reject_referrer'] ?? '' );
		$s['cache_vary_cookies']    = sanitize_textarea_field( $input['cache_vary_cookies'] ?? '' );
		$s['cache_query_strings']   = sanitize_textarea_field( $input['cache_query_strings'] ?? '' );
		$s['purge_additional_urls'] = sanitize_textarea_field( $input['purge_additional_urls'] ?? '' );
		$s['purge_on_post_update']  = ! empty( $input['purge_on_post_update'] );
		$s['purge_on_post_delete']  = ! empty( $input['purge_on_post_delete'] );
		$s['purge_on_comment']      = ! empty( $input['purge_on_comment'] );
		$s['purge_on_term_change']  = ! empty( $input['purge_on_term_change'] );
		$s['purge_on_theme_switch'] = ! empty( $input['purge_on_theme_switch'] );
		$s['purge_on_permalink']    = ! empty( $input['purge_on_permalink'] );
		$s['purge_on_plugin_change'] = ! empty( $input['purge_on_plugin_change'] );
		$s['purge_on_customizer']   = ! empty( $input['purge_on_customizer'] );
		$s['purge_on_widget']       = ! empty( $input['purge_on_widget'] );
		$s['purge_on_nav_menu']     = ! empty( $input['purge_on_nav_menu'] );
		$s['purge_on_core_update']  = ! empty( $input['purge_on_core_update'] );
		$s['purge_on_user_update']  = ! empty( $input['purge_on_user_update'] );
		$s['minify_html']           = ! empty( $input['minify_html'] );
		$s['minify_html_dom']       = ! empty( $input['minify_html_dom'] );
		$s['remove_html_comments']  = ! empty( $input['remove_html_comments'] );
		$s['minify_css']            = ! empty( $input['minify_css'] );
		$s['combine_css']           = ! empty( $input['combine_css'] );
		$s['optimize_css_delivery']  = ! empty( $input['optimize_css_delivery'] );
		$s['css_delivery_method']   = isset( $input['css_delivery_method'] ) && in_array( $input['css_delivery_method'], array( 'remove_unused_css', 'async_css' ), true ) ? $input['css_delivery_method'] : 'remove_unused_css';
		// Derive the individual flags from the master toggle + method.
		$s['remove_unused_css']     = $s['optimize_css_delivery'] && 'remove_unused_css' === $s['css_delivery_method'];
		$s['async_css']             = $s['optimize_css_delivery'] && 'async_css' === $s['css_delivery_method'];
		$s['critical_css']          = sanitize_textarea_field( $input['critical_css'] ?? '' );
		$s['critical_css_auto']     = ! empty( $input['critical_css_auto'] );
		$s['ucss_safelist']         = sanitize_textarea_field( $input['ucss_safelist'] ?? '' );
		$s['exclude_css']           = sanitize_textarea_field( $input['exclude_css'] ?? '' );
		$s['minify_js']             = ! empty( $input['minify_js'] );
		$s['combine_js']            = ! empty( $input['combine_js'] );
		$s['defer_js']              = ! empty( $input['defer_js'] );
		$s['delay_js']              = ! empty( $input['delay_js'] );
		$s['delay_js_timeout']      = isset( $input['delay_js_timeout'] ) ? max( 0, (int) $input['delay_js_timeout'] ) : 0;
		$s['exclude_js']            = sanitize_textarea_field( $input['exclude_js'] ?? '' );
		$s['exclude_inline_js']     = sanitize_textarea_field( $input['exclude_inline_js'] ?? '' );
		$s['exclude_defer_js']      = sanitize_textarea_field( $input['exclude_defer_js'] ?? '' );
		$s['exclude_delay_js']      = sanitize_textarea_field( $input['exclude_delay_js'] ?? '' );
		$s['combine_google_fonts']  = ! empty( $input['combine_google_fonts'] );
		$s['self_host_google_fonts'] = ! empty( $input['self_host_google_fonts'] );
		$s['google_fonts_display']  = ! empty( $input['google_fonts_display'] );
		$s['remove_query_strings']  = ! empty( $input['remove_query_strings'] );
		$s['rewrite_file_optimizer'] = ! empty( $input['rewrite_file_optimizer'] );
		// Schedule rewrite flush for next request (after new settings are active).
		$old = prime_cache_get_settings();
		if ( $s['rewrite_file_optimizer'] !== ( $old['rewrite_file_optimizer'] ?? false ) ) {
			update_option( 'prime_cache_flush_rewrite', 1, false );
		}
		$s['preload_enabled']       = ! empty( $input['preload_enabled'] );
		$s['preload_homepage']      = ! empty( $input['preload_homepage'] );
		$s['preload_public_posts']  = ! empty( $input['preload_public_posts'] );
		$s['preload_public_tax']    = ! empty( $input['preload_public_tax'] );
		$s['preload_sitemap_enabled'] = ! empty( $input['preload_sitemap_enabled'] );
		$s['preload_sitemap']       = esc_url_raw( $input['preload_sitemap'] ?? '' );
		$s['preload_interval']      = isset( $input['preload_interval'] ) ? max( 1, (int) $input['preload_interval'] ) : 2;
		$s['preload_max_posts']     = isset( $input['preload_max_posts'] ) ? max( 50, min( 5000, (int) $input['preload_max_posts'] ) ) : 500;
		$s['preload_max_terms']     = isset( $input['preload_max_terms'] ) ? max( 50, min( 2000, (int) $input['preload_max_terms'] ) ) : 200;
		$s['preload_excluded_uri']  = sanitize_textarea_field( $input['preload_excluded_uri'] ?? '' );
		$s['preload_links']         = ! empty( $input['preload_links'] );
		$s['preload_fonts']         = ! empty( $input['preload_fonts'] );
		$s['lcp_optimization']      = ! empty( $input['lcp_optimization'] );
		$s['lcp_excluded']          = sanitize_textarea_field( $input['lcp_excluded'] ?? '' );
		$s['prefetch_dns']          = sanitize_textarea_field( $input['prefetch_dns'] ?? '' );
		$s['preconnect']            = sanitize_textarea_field( $input['preconnect'] ?? '' );
		$s['varnish_enabled']       = ! empty( $input['varnish_enabled'] );
		$s['varnish_ip']            = sanitize_textarea_field( $input['varnish_ip'] ?? '' );
		$s['sucuri_enabled']        = ! empty( $input['sucuri_enabled'] );
		$s['sucuri_api_key']        = sanitize_text_field( $input['sucuri_api_key'] ?? '' );
		$s['heartbeat_enabled']     = ! empty( $input['heartbeat_enabled'] );
		$hb_valid = array( 'enable', 'disable', 'modify' );
		$s['heartbeat_frontend']    = in_array( $input['heartbeat_frontend'] ?? '', $hb_valid, true ) ? $input['heartbeat_frontend'] : 'disable';
		$s['heartbeat_admin']       = in_array( $input['heartbeat_admin'] ?? '', $hb_valid, true ) ? $input['heartbeat_admin'] : 'modify';
		$s['heartbeat_editor']      = in_array( $input['heartbeat_editor'] ?? '', $hb_valid, true ) ? $input['heartbeat_editor'] : 'enable';
		$s['heartbeat_admin_interval']    = isset( $input['heartbeat_admin_interval'] ) ? max( 15, min( 300, (int) $input['heartbeat_admin_interval'] ) ) : 120;
		$s['heartbeat_frontend_interval'] = isset( $input['heartbeat_frontend_interval'] ) ? max( 15, min( 300, (int) $input['heartbeat_frontend_interval'] ) ) : 60;
		$s['lazyload_images']       = ! empty( $input['lazyload_images'] );
		$s['lazyload_iframes']      = ! empty( $input['lazyload_iframes'] );
		$s['lazyload_videos']       = ! empty( $input['lazyload_videos'] );
		$s['lazyload_disable_native'] = ! empty( $input['lazyload_disable_native'] );
		$s['lazyload_exclude']      = sanitize_textarea_field( $input['lazyload_exclude'] ?? '' );

		$s['cdn_enabled']           = ! empty( $input['cdn_enabled'] );
		$s['cdn_hostname']          = sanitize_textarea_field( $input['cdn_hostname'] ?? '' );
		$s['cdn_include_dirs']      = sanitize_text_field( $input['cdn_include_dirs'] ?? 'wp-content,wp-includes' );
		$s['cdn_exclude']           = sanitize_textarea_field( $input['cdn_exclude'] ?? '.php' );
		$s['cdn_relative']          = ! empty( $input['cdn_relative'] );
		$s['cloudflare_enabled']    = ! empty( $input['cloudflare_enabled'] );
		$s['cloudflare_email']      = sanitize_email( $input['cloudflare_email'] ?? '' );
		// Preserve DB-stored key if input is disabled (PRIME_CACHE_CF_API_TOKEN active).
		if ( defined( 'PRIME_CACHE_CF_API_TOKEN' ) && ! isset( $input['cloudflare_api_key'] ) ) {
			$s['cloudflare_api_key'] = $defaults['cloudflare_api_key'] ?? '';
			$existing = get_option( 'prime_cache_settings', array() );
			if ( ! empty( $existing['cloudflare_api_key'] ) ) {
				$s['cloudflare_api_key'] = $existing['cloudflare_api_key'];
			}
		} else {
			$s['cloudflare_api_key'] = sanitize_text_field( $input['cloudflare_api_key'] ?? '' );
		}
		$s['cloudflare_auth_mode']  = in_array( $input['cloudflare_auth_mode'] ?? '', array( 'token', 'global_key' ), true ) ? $input['cloudflare_auth_mode'] : 'token';
		$s['cloudflare_zone_id']    = sanitize_text_field( $input['cloudflare_zone_id'] ?? '' );
		$s['browser_cache']         = ! empty( $input['browser_cache'] );
		$s['browser_cache_css_js']  = isset( $input['browser_cache_css_js'] ) ? max( 0, (int) $input['browser_cache_css_js'] ) : 31536000;
		$s['browser_cache_images']  = isset( $input['browser_cache_images'] ) ? max( 0, (int) $input['browser_cache_images'] ) : 15552000;
		$s['browser_cache_fonts']   = isset( $input['browser_cache_fonts'] ) ? max( 0, (int) $input['browser_cache_fonts'] ) : 15552000;
		$s['browser_cache_html']    = isset( $input['browser_cache_html'] ) ? max( 0, (int) $input['browser_cache_html'] ) : 0;
		$s['brotli_compression']    = ! empty( $input['brotli_compression'] );
		$s['cache_control_immutable'] = ! empty( $input['cache_control_immutable'] );
		$s['img_conversion_enabled'] = ! empty( $input['img_conversion_enabled'] );
		$s['webp_enabled']          = ! empty( $input['webp_enabled'] );
		$s['avif_enabled']          = ! empty( $input['avif_enabled'] );
		$s['img_quality_mode']      = in_array( $input['img_quality_mode'] ?? '', array( 'lossy', 'lossless', 'custom' ), true ) ? $input['img_quality_mode'] : 'lossy';
		$s['webp_quality']          = isset( $input['webp_quality'] ) ? max( 1, min( 100, (int) $input['webp_quality'] ) ) : 80;
		$s['avif_quality']          = isset( $input['avif_quality'] ) ? max( 1, min( 100, (int) $input['avif_quality'] ) ) : 60;
		$s['img_strip_exif']        = ! empty( $input['img_strip_exif'] );
		$s['img_resize']            = ! empty( $input['img_resize'] );
		$s['img_max_width']         = isset( $input['img_max_width'] ) ? max( 0, (int) $input['img_max_width'] ) : 2560;
		$s['img_max_height']        = isset( $input['img_max_height'] ) ? max( 0, (int) $input['img_max_height'] ) : 2560;
		$s['img_auto_optimize']     = ! empty( $input['img_auto_optimize'] );
		$s['img_auto_remove_larger'] = ! empty( $input['img_auto_remove_larger'] );
		$s['img_exclude_png']       = ! empty( $input['img_exclude_png'] );
		$s['img_include_uploads']   = ! empty( $input['img_include_uploads'] );
		$s['img_include_themes']    = ! empty( $input['img_include_themes'] );
		$s['img_include_plugins']   = ! empty( $input['img_include_plugins'] );
		$s['img_include_custom']    = sanitize_textarea_field( $input['img_include_custom'] ?? '' );
		$s['img_exclude_folders']   = sanitize_textarea_field( $input['img_exclude_folders'] ?? '' );
		$s['img_delivery_method']   = in_array( $input['img_delivery_method'] ?? '', array( 'rewrite', 'picture', 'url' ), true ) ? $input['img_delivery_method'] : 'rewrite';
		$s['img_converter']         = in_array( $input['img_converter'] ?? '', array( 'auto', 'gd', 'imagick' ), true ) ? $input['img_converter'] : 'auto';
		$s['youtube_thumbnail']     = ! empty( $input['youtube_thumbnail'] );
		$s['add_missing_dimensions'] = ! empty( $input['add_missing_dimensions'] );
		$s['hsts_enabled']          = ! empty( $input['hsts_enabled'] );
		$s['hsts_max_age']          = isset( $input['hsts_max_age'] ) ? max( 0, (int) $input['hsts_max_age'] ) : 31536000;
		$s['security_headers']      = ! empty( $input['security_headers'] );
		$s['disable_emoji']         = ! empty( $input['disable_emoji'] );
		$s['disable_jquery_migrate'] = ! empty( $input['disable_jquery_migrate'] );
		$s['disable_wp_embed']      = ! empty( $input['disable_wp_embed'] );
		$s['disable_dashicons']     = ! empty( $input['disable_dashicons'] );
		$s['disable_wp_version']    = ! empty( $input['disable_wp_version'] );
		$s['disable_xmlrpc']        = ! empty( $input['disable_xmlrpc'] );
		$s['disable_self_pingback'] = ! empty( $input['disable_self_pingback'] );
		$s['limit_revisions']       = ! empty( $input['limit_revisions'] );
		$s['revisions_max']         = isset( $input['revisions_max'] ) ? max( 0, (int) $input['revisions_max'] ) : 5;
		$s['disable_rss_feeds']     = ! empty( $input['disable_rss_feeds'] );
		$s['disable_oembed']        = ! empty( $input['disable_oembed'] );
		$s['disable_block_css']     = ! empty( $input['disable_block_css'] );
		$s['disable_google_fonts']  = ! empty( $input['disable_google_fonts'] );
		$s['disable_global_styles'] = ! empty( $input['disable_global_styles'] );
		$s['disable_shortlink']     = ! empty( $input['disable_shortlink'] );
		$s['disable_rsd_wlw']       = ! empty( $input['disable_rsd_wlw'] );
		$s['disable_rest_api_link'] = ! empty( $input['disable_rest_api_link'] );
		$s['disable_wp_sitemap']    = ! empty( $input['disable_wp_sitemap'] );
		$s['add_blank_favicon']     = ! empty( $input['add_blank_favicon'] );
		$s['woo_disable_scripts']   = ! empty( $input['woo_disable_scripts'] );
		$s['woo_disable_cart_frag'] = ! empty( $input['woo_disable_cart_frag'] );
		$s['delay_js_safe_mode']    = ! empty( $input['delay_js_safe_mode'] );
		$s['delay_js_presets']      = sanitize_textarea_field( $input['delay_js_presets'] ?? '' );
		$s['inline_small_css']      = ! empty( $input['inline_small_css'] );
		$s['inline_css_threshold']  = isset( $input['inline_css_threshold'] ) ? max( 0, (int) $input['inline_css_threshold'] ) : 8192;
		$s['local_analytics']       = ! empty( $input['local_analytics'] );
		$s['preload_resources']     = sanitize_textarea_field( $input['preload_resources'] ?? '' );
		$s['speculation_rules']     = ! empty( $input['speculation_rules'] );
		$s['cache_404']             = ! empty( $input['cache_404'] );
		$s['debug_log']             = ! empty( $input['debug_log'] );
		$s['db_revisions']          = ! empty( $input['db_revisions'] );
		$s['db_auto_drafts']        = ! empty( $input['db_auto_drafts'] );
		$s['db_trashed_posts']      = ! empty( $input['db_trashed_posts'] );
		$s['db_spam_comments']      = ! empty( $input['db_spam_comments'] );
		$s['db_trashed_comments']   = ! empty( $input['db_trashed_comments'] );
		$s['db_expired_transients'] = ! empty( $input['db_expired_transients'] );
		$s['db_all_transients']     = ! empty( $input['db_all_transients'] );
		$s['db_optimize_tables']    = ! empty( $input['db_optimize_tables'] );
		$s['db_auto_cleanup']       = ! empty( $input['db_auto_cleanup'] );
		$s['db_cleanup_frequency']  = isset( $input['db_cleanup_frequency'] ) && in_array( $input['db_cleanup_frequency'], array( 'daily', 'weekly', 'monthly' ), true ) ? $input['db_cleanup_frequency'] : 'weekly';
		$s['cache_ignore_qs']       = sanitize_textarea_field( $input['cache_ignore_qs'] ?? $defaults['cache_ignore_qs'] );

		// Reschedule DB cleanup cron if settings changed (Pro feature).
		if ( class_exists( 'Prime_Cache_Database_Optimizer' ) ) {
			if ( $s['db_auto_cleanup'] ) {
				Prime_Cache_Database_Optimizer::reschedule_cron( $s['db_cleanup_frequency'] );
			} else {
				Prime_Cache_Database_Optimizer::unschedule();
			}
		}

		// Environment pre-checks for high-risk features.
		$warnings = array();

		// WebP/AVIF: check image library availability.
		if ( $s['webp_enabled'] && ! ( function_exists( 'imagecreatefromjpeg' ) || ( extension_loaded( 'imagick' ) && in_array( 'WEBP', \Imagick::queryFormats(), true ) ) ) ) {
			$s['webp_enabled'] = false;
			$warnings[] = __( 'WebP conversion disabled: no compatible image library (GD or Imagick with WebP support) found.', 'prime-cache' );
		}
		if ( $s['avif_enabled'] && ! ( ( function_exists( 'imageavif' ) ) || ( extension_loaded( 'imagick' ) && in_array( 'AVIF', \Imagick::queryFormats(), true ) ) ) ) {
			$s['avif_enabled'] = false;
			$warnings[] = __( 'AVIF conversion disabled: no compatible image library (GD with AVIF or Imagick with AVIF support) found.', 'prime-cache' );
		}

		// Combine JS: warn about potential breakage.
		if ( $s['combine_js'] && ! $defaults['combine_js'] ) {
			$warnings[] = __( 'Combine JavaScript enabled. This is an advanced feature — please test your site thoroughly, as some scripts may break when combined.', 'prime-cache' );
		}

		// Delay JS: warn about potential breakage.
		if ( $s['delay_js'] && ! $defaults['delay_js'] ) {
			$warnings[] = __( 'Delay JavaScript enabled. This is an advanced feature — some interactive elements may not work until user interaction. Add problematic scripts to the exclusion list if needed.', 'prime-cache' );
		}

		// Object cache: verify the PHP extension is available.
		if ( ! empty( $input['object_cache'] ) && 'off' !== $input['object_cache'] ) {
			$backend = sanitize_key( $input['object_cache'] );
			$ext_ok = true;
			if ( 'redis' === $backend && ! class_exists( 'Redis' ) ) {
				$ext_ok = false;
				$warnings[] = __( 'Redis object cache not enabled: the Redis PHP extension is not installed.', 'prime-cache' );
			} elseif ( 'memcached' === $backend && ! class_exists( 'Memcached' ) ) {
				$ext_ok = false;
				$warnings[] = __( 'Memcached object cache not enabled: the Memcached PHP extension is not installed.', 'prime-cache' );
			} elseif ( 'apcu' === $backend && ! function_exists( 'apcu_add' ) ) {
				$ext_ok = false;
				$warnings[] = __( 'APCu object cache not enabled: the APCu PHP extension is not installed.', 'prime-cache' );
			}
			if ( ! $ext_ok ) {
				$input['object_cache'] = 'off';
			}
		}

		// Multisite: do not touch advanced-cache.php, config file, or .htaccess
		// page-cache rules. Page caching is not supported on multisite.
		if ( ! is_multisite() ) {
			$ac_result = Prime_Cache_Config::install_advanced_cache();
			if ( ! $ac_result && 'external' === Prime_Cache_Config::get_advanced_cache_owner() ) {
				$warnings[] = __( 'advanced-cache.php is managed by another plugin. Prime Cache page caching cannot be enabled until the other plugin is deactivated.', 'prime-cache' );
			}

			Prime_Cache_Config::write_config_file( $s );
			$s['htaccess_enabled'] ? Prime_Cache_Htaccess::add_rules( $s ) : Prime_Cache_Htaccess::remove_rules();
		}

		// Set transient AFTER all warnings are collected (including install_advanced_cache result).
		if ( ! empty( $warnings ) ) {
			set_transient( 'prime_cache_env_warnings', $warnings, 60 );
		}

		// Schedule immediate async fetch of local analytics files on save (Pro only).
		if ( prime_cache_is_pro() && ! empty( $s['local_analytics'] ) ) {
			if ( ! wp_next_scheduled( 'prime_cache_refresh_local_analytics' ) ) {
				wp_schedule_single_event( time(), 'prime_cache_refresh_local_analytics' );
			}
		}

		return $s;
	}

	/* ── helpers ───────────────────────────────────────────── */

	private function get_cache_stats() {
		// Cache stats in a short transient to avoid full directory scan on every page load.
		$cached = get_transient( 'prime_cache_dir_stats' );
		if ( false !== $cached ) {
			return $cached;
		}
		$r = array( 'files' => 0, 'size' => 0 );
		if ( ! is_dir( PRIME_CACHE_CACHE_DIR ) ) return $r;
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( PRIME_CACHE_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) { if ( $f->isFile() && 'html' === $f->getExtension() ) $r['files']++; if ( $f->isFile() ) $r['size'] += $f->getSize(); }
		set_transient( 'prime_cache_dir_stats', $r, 60 );
		return $r;
	}
	private function get_hit_stats() {
		// DB stores the persisted baseline; file stores increments since last sync.
		$db = get_option( 'prime_cache_stats', array() );
		$db = wp_parse_args( $db, array( 'hit' => 0, 'miss' => 0, 'since' => 0 ) );

		$f = PRIME_CACHE_CACHE_DIR . 'stats.json';
		if ( is_readable( $f ) ) {
			$j = json_decode( file_get_contents( $f ), true ); // phpcs:ignore
			if ( is_array( $j ) ) {
				$db['hit']  += (int) ( $j['hit'] ?? 0 );
				$db['miss'] += (int) ( $j['miss'] ?? 0 );
				if ( ! $db['since'] && ! empty( $j['since'] ) ) {
					$db['since'] = (int) $j['since'];
				}
			}
		}

		return $db;
	}
	private function fmt( $b ) { if ( $b < 1024 ) return $b . ' B'; if ( $b < 1048576 ) return round( $b / 1024, 1 ) . ' KB'; return round( $b / 1048576, 1 ) . ' MB'; }
	private function get_system_status() {
		$s = array();
		$s['wp_cache'] = defined( 'WP_CACHE' ) && WP_CACHE;
		$ac_owner = Prime_Cache_Config::get_advanced_cache_owner();
		$s['advanced_cache']          = 'ours' === $ac_owner;
		$s['advanced_cache_external'] = 'external' === $ac_owner;
		$s['advanced_cache_abandoned'] = 'abandoned' === $ac_owner;
		$s['cache_dir_writable'] = wp_is_writable( PRIME_CACHE_CACHE_DIR ) || wp_is_writable( dirname( PRIME_CACHE_CACHE_DIR ) );
		$s['gzip_available'] = function_exists( 'gzencode' );
		$cfg = prime_cache_get_settings();
		if ( ! empty( $cfg['htaccess_enabled'] ) ) $s['htaccess'] = Prime_Cache_Htaccess::has_rules();
		return $s;
	}
	/**
	 * Sanitize a simple pattern field (pipe-separated substrings with . ^ $ as wildcards).
	 */
	private function sanitize_regex_field( $value ) {
		$value = sanitize_textarea_field( $value );
		if ( empty( $value ) ) {
			return '';
		}
		// Length limit: prevent excessively long patterns.
		if ( strlen( $value ) > 512 ) {
			return '';
		}
		// Strip null bytes and control characters (except printable ASCII).
		$value = preg_replace( '#[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]#', '', $value );
		// Remove carriage returns and newlines.
		$value = preg_replace( '#[\r\n]#', '', $value );
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		// Escape the delimiter character (#) in the input to prevent regex injection.
		$value = str_replace( '#', '\\#', $value );
		// Block empty alternation branches (||, leading |, trailing |).
		if ( preg_match( '/\|\||^\||\|$/', $value ) ) {
			return '';
		}
		// Validate the complete pattern compiles as valid regex.
		$test = @preg_match( '#' . $value . '#', '' );
		if ( false === $test ) {
			return '';
		}
		return $value;
	}

	private static function seconds_to_human( $s ) {
		$s = (int) $s;
		if ( $s <= 0 ) return '(no-cache)';
		if ( $s >= 31536000 ) return '= ' . round( $s / 31536000, 1 ) . ' ' . __( 'year(s)', 'prime-cache' );
		if ( $s >= 2592000 ) return '= ' . round( $s / 2592000, 1 ) . ' ' . __( 'month(s)', 'prime-cache' );
		if ( $s >= 86400 ) return '= ' . round( $s / 86400, 1 ) . ' ' . __( 'day(s)', 'prime-cache' );
		if ( $s >= 3600 ) return '= ' . round( $s / 3600, 1 ) . ' ' . __( 'hour(s)', 'prime-cache' );
		return '= ' . $s . ' ' . __( 'sec', 'prime-cache' );
	}

	private function hidden( $settings, $visible ) {
		foreach ( $settings as $k => $v ) {
			if ( in_array( $k, $visible, true ) ) continue;
			printf( '<input type="hidden" name="prime_cache_settings[%s]" value="%s">', esc_attr( $k ), esc_attr( is_bool( $v ) ? ( $v ? '1' : '0' ) : $v ) );
		}
	}

	/* ── router ───────────────────────────────────────────── */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
		$settings = prime_cache_get_settings();
		$on = ! empty( $settings['cache_enabled'] );

		$is_pro = prime_cache_is_pro();
		// Third element: true = Pro-only tab.
		$tabs = array(
			'dashboard'     => array( 'dashicons-dashboard',       __( 'Dashboard', 'prime-cache' ),     false ),
			'page-cache'    => array( 'dashicons-admin-page',     __( 'Page Cache', 'prime-cache' ),     false ),
			'object-cache'  => array( 'dashicons-database',      __( 'Object Cache', 'prime-cache' ),   true ),
			'file-opt'      => array( 'dashicons-editor-code',     __( 'File Optimization', 'prime-cache' ), false ),
			'media'         => array( 'dashicons-format-image',     __( 'Media', 'prime-cache' ),         false ),
			'cdn'           => array( 'dashicons-cloud-saved',     __( 'CDN', 'prime-cache' ),           true ),
			'preload'       => array( 'dashicons-controls-forward', __( 'Preload', 'prime-cache' ),       false ),
			'cache-control' => array( 'dashicons-admin-generic',  __( 'Cache Control', 'prime-cache' ),  false ),
			'heartbeat'     => array( 'dashicons-heart',             __( 'Heartbeat', 'prime-cache' ),     true ),
			'database'      => array( 'dashicons-database-view',    __( 'Database', 'prime-cache' ),      true ),
			'auto-purge'    => array( 'dashicons-update',          __( 'Auto Purge', 'prime-cache' ),    false ),
			'exclusions'    => array( 'dashicons-dismiss',        __( 'Exclusion Rules', 'prime-cache' ), false ),
			'tools'         => array( 'dashicons-admin-tools',    __( 'Tools', 'prime-cache' ),          false ),
		);
		?>
		<div class="pc">
			<!-- Sidebar -->
			<aside class="pc-side">
				<div class="pc-side__brand">
					<span class="pc-side__logo dashicons dashicons-performance"></span>
					<span class="pc-side__name">Prime Cache</span>
					<span class="pc-side__ver">v<?php echo esc_html( PRIME_CACHE_VERSION ); ?></span>
				</div>
				<nav class="pc-nav">
					<?php foreach ( $tabs as $slug => $t ) :
						$is_pro_tab = ! empty( $t[2] );
						$locked     = $is_pro_tab && ! $is_pro;
						$cls        = ( $slug === $tab ) ? ' pc-nav__item--on' : '';
						if ( $locked ) $cls .= ' pc-nav__item--pro';
					?>
					<a href="<?php echo $locked ? '#' : esc_url( admin_url( 'admin.php?page=prime-cache&tab=' . $slug ) ); ?>" class="pc-nav__item<?php echo $cls; ?>"<?php echo $locked ? ' onclick="return false;"' : ''; ?>>
						<span class="dashicons <?php echo esc_attr( $t[0] ); ?>"></span><?php echo esc_html( $t[1] ); ?>
						<?php if ( $locked ) : ?><span class="pc-pro-badge">PRO</span><?php endif; ?>
					</a>
					<?php endforeach; ?>
				</nav>
				<div class="pc-side__foot">
					<?php $toggle_url = wp_nonce_url( admin_url( 'admin.php?pc_action=toggle_cache&tab=' . $tab ), 'prime_cache_admin_action' ); ?>
					<a href="<?php echo esc_url( $toggle_url ); ?>" class="pc-pw" id="pc-power-toggle" style="text-decoration:none">
						<span class="pc-pw__sw <?php echo $on ? 'is-on' : ''; ?>"><span class="pc-pw__knob"></span></span>
						<span class="pc-pw__label"><?php echo $on ? esc_html__( 'Cache Enabled', 'prime-cache' ) : esc_html__( 'Cache Disabled', 'prime-cache' ); ?></span>
					</a>
				</div>
			</aside>

			<!-- Main -->
			<main class="pc-main">
				<?php
				switch ( $tab ) {
					case 'object-cache':  $this->tab_object(); break;
					case 'file-opt':      $this->tab_file_opt( $settings ); break;
					case 'media':         $this->tab_media( $settings ); break;
					case 'cdn':           $this->tab_cdn( $settings ); break;
					case 'preload':       $this->tab_preload( $settings ); break;
					case 'cache-control': $this->tab_control( $settings ); break;
					case 'heartbeat':     $this->tab_heartbeat( $settings ); break;
					case 'database':      $this->tab_database( $settings ); break;
					case 'auto-purge':    $this->tab_auto_purge( $settings ); break;
					case 'exclusions':    $this->tab_exclusions( $settings ); break;
					case 'tools':         $this->tab_tools( $settings ); break;
					case 'dashboard':     $this->tab_dashboard( $settings ); break;
					default:              $this->tab_page( $settings, $on ); break;
				}
				?>
			</main>
		</div>
		<?php
	}

	/* ── tab: dashboard ──────────────────────────────────── */

	private function tab_dashboard( $settings ) {
		$is_pro = prime_cache_is_pro();
		$hs = $this->get_hit_stats();
		$st = $this->get_cache_stats();
		$sys = $this->get_system_status();
		$total = $hs['hit'] + $hs['miss'];
		$rate  = $total > 0 ? round( ( $hs['hit'] / $total ) * 100, 1 ) : 0;
		$oc    = Prime_Cache_Config::get_active_object_cache();
		$n     = wp_create_nonce( 'prime_cache_admin_action' );

		// Feature counts.
		$fo_size = 0;
		$fo_dir = WP_CONTENT_DIR . '/cache/prime-cache-fo/';
		if ( is_dir( $fo_dir ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fo_dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) { if ( $f->isFile() ) $fo_size += $f->getSize(); }
		}
		?>
		<h2 class="pc-title"><?php esc_html_e( 'Dashboard', 'prime-cache' ); ?></h2>

		<!-- KPI Row -->
		<div class="pc-grid pc-grid--4">
			<div class="pc-kpi"><span class="pc-kpi__val"><?php echo esc_html( $rate ); ?>%</span><span class="pc-kpi__lbl"><?php esc_html_e( 'Hit Rate', 'prime-cache' ); ?></span></div>
			<div class="pc-kpi"><span class="pc-kpi__val pc-kpi__val--g"><?php echo esc_html( number_format( $hs['hit'] ) ); ?></span><span class="pc-kpi__lbl">HIT</span></div>
			<div class="pc-kpi"><span class="pc-kpi__val pc-kpi__val--a"><?php echo esc_html( number_format( $hs['miss'] ) ); ?></span><span class="pc-kpi__lbl">MISS</span></div>
			<div class="pc-kpi"><span class="pc-kpi__val"><?php echo esc_html( number_format( $st['files'] ) ); ?></span><span class="pc-kpi__lbl"><?php esc_html_e( 'Pages', 'prime-cache' ); ?></span></div>
		</div>

		<!-- Hit Rate Bar -->
		<div class="pc-card">
			<div class="pc-card__row">
				<span class="pc-card__h"><?php esc_html_e( 'Cache Hit Rate', 'prime-cache' ); ?></span>
				<div style="display:flex;align-items:center;gap:12px">
					<span class="pc-meta"><?php if ( $hs['since'] ) printf( esc_html__( 'Since: %s', 'prime-cache' ), esc_html( wp_date( 'Y/m/d H:i', $hs['since'] ) ) ); ?></span>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=prime-cache&prime_cache_reset_stats=1' ), 'prime_cache_reset_stats' ) ); ?>" class="pc-btn pc-btn--o pc-btn--sm" style="font-size:11px;padding:3px 10px" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Reset all hit/miss statistics to zero?', 'prime-cache' ) ) ); ?>)"><span class="dashicons dashicons-image-rotate" style="font-size:13px;width:13px;height:13px;line-height:13px"></span><?php esc_html_e( 'Reset', 'prime-cache' ); ?></a>
				</div>
			</div>
			<div class="pc-bar"><div class="pc-bar__fill" style="width:<?php echo esc_attr( $rate ); ?>%"></div></div>
			<div class="pc-bar__info">
				<span><?php printf( esc_html__( 'Total: %s', 'prime-cache' ), '<b>' . esc_html( number_format( $total ) ) . '</b>' ); ?></span>
				<span><?php printf( esc_html__( 'Size: %s', 'prime-cache' ), '<b>' . esc_html( $this->fmt( $st['size'] ) ) . '</b>' ); ?></span>
			</div>
		</div>

		<!-- Quick Actions -->
		<div class="pc-card">
			<span class="pc-card__h"><?php esc_html_e( 'Quick Actions', 'prime-cache' ); ?></span>
			<div style="display:flex;gap:8px;flex-wrap:wrap">
				<a href="<?php echo esc_url( admin_url( 'admin.php?pc_action=clear_all&_wpnonce=' . $n ) ); ?>" class="pc-btn pc-btn--r pc-btn--sm"><span class="dashicons dashicons-trash"></span><?php esc_html_e( 'Clear All Cache', 'prime-cache' ); ?></a>
				<?php if ( $settings['preload_enabled'] ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?pc_action=clear_and_preload&_wpnonce=' . $n ) ); ?>" class="pc-btn pc-btn--p pc-btn--sm"><span class="dashicons dashicons-controls-forward"></span><?php esc_html_e( 'Clear Cache & Preload', 'prime-cache' ); ?></a>
				<?php if ( ! empty( trim( $settings['cache_vary_cookies'] ?? '' ) ) ) : ?>
					<span class="pc-meta" style="align-self:center"><?php esc_html_e( 'Default variant only', 'prime-cache' ); ?></span>
				<?php endif; ?>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?pc_action=clear_minified&_wpnonce=' . $n ) ); ?>" class="pc-btn pc-btn--o pc-btn--sm"><?php esc_html_e( 'Clear Minified CSS/JS', 'prime-cache' ); ?></a>
				<?php if ( 'off' !== $oc && 'external' !== $oc ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?pc_action=clear_object_cache&_wpnonce=' . $n ) ); ?>" class="pc-btn pc-btn--o pc-btn--sm"><?php esc_html_e( 'Clear Object Cache', 'prime-cache' ); ?></a>
				<?php endif; ?>
			</div>
		</div>

		<div class="pc-grid pc-grid--2" style="grid-template-columns:1fr 1fr">
			<!-- System Status -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'System Status', 'prime-cache' ); ?></span>
				<div class="pc-sys">
					<?php
					$checks = array(
						array( 'wp_cache', __( 'WP_CACHE Constant', 'prime-cache' ) ),
						array( 'advanced_cache', 'advanced-cache.php' ),
						array( 'cache_dir_writable', __( 'Cache Directory', 'prime-cache' ) ),
						array( 'gzip_available', __( 'Gzip Support', 'prime-cache' ) ),
					);
					if ( isset( $sys['htaccess'] ) ) {
						$checks[] = array( 'htaccess', __( '.htaccess Rules', 'prime-cache' ) );
					}
					foreach ( $checks as $chk ) :
						$ok = $sys[ $chk[0] ] ?? false;
						$is_ext = ( 'advanced_cache' === $chk[0] && ! empty( $sys['advanced_cache_external'] ) );
					?>
					<div class="pc-sys__row"><span class="pc-dot pc-dot--<?php echo $ok ? 'g' : ( $is_ext ? 'a' : 'r' ); ?>"></span><span class="pc-sys__lbl"><?php echo esc_html( $chk[1] ); ?></span><span class="pc-sys__val"><?php echo $is_ext ? esc_html__( 'External', 'prime-cache' ) : ( $ok ? esc_html__( 'Active', 'prime-cache' ) : esc_html__( 'Inactive', 'prime-cache' ) ); ?></span></div>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Feature Status -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Feature Status', 'prime-cache' ); ?></span>
				<div class="pc-sys">
					<?php
					$features = array(
						array( $settings['cache_enabled'], __( 'Page Cache', 'prime-cache' ) ),
						array( $is_pro && 'off' !== $oc, __( 'Object Cache', 'prime-cache' ) . ( $is_pro && 'off' !== $oc && 'external' !== $oc ? ' (' . strtoupper( $oc ) . ')' : '' ) ),
						array( $settings['minify_html'] || $settings['minify_css'] || $settings['minify_js'], __( 'File Optimization', 'prime-cache' ) ),
						array( $settings['defer_js'] || $settings['delay_js'], __( 'JS Optimization', 'prime-cache' ) ),
						array( $settings['lazyload_images'], __( 'Lazy Load', 'prime-cache' ) ),
						array( $is_pro && ! empty( $settings['img_conversion_enabled'] ) && ( $settings['webp_enabled'] || $settings['avif_enabled'] ), 'WebP / AVIF' ),
						array( $is_pro && $settings['cdn_enabled'], __( 'CDN', 'prime-cache' ) ),
						array( $is_pro && $settings['preload_enabled'], __( 'Cache Preload', 'prime-cache' ) ),
						array( $settings['htaccess_enabled'], '.htaccess' ),
						array( $settings['browser_cache'], __( 'Browser Cache', 'prime-cache' ) ),
						array( $is_pro && $settings['heartbeat_enabled'], __( 'Heartbeat', 'prime-cache' ) ),
						array( $is_pro && $settings['varnish_enabled'], 'Varnish' ),
						array( $is_pro && $settings['cloudflare_enabled'], 'Cloudflare' ),
						array( $is_pro && $settings['sucuri_enabled'], 'Sucuri' ),
					);
					foreach ( $features as $f ) :
					?>
					<div class="pc-sys__row"><span class="pc-dot pc-dot--<?php echo $f[0] ? 'g' : 'm'; ?>"></span><span class="pc-sys__lbl"><?php echo esc_html( $f[1] ); ?></span><span class="pc-sys__val"><?php echo $f[0] ? esc_html__( 'Active', 'prime-cache' ) : esc_html__( 'Inactive', 'prime-cache' ); ?></span></div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<!-- Storage -->
		<div class="pc-card">
			<span class="pc-card__h"><?php esc_html_e( 'Cache Storage', 'prime-cache' ); ?></span>
			<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px">
				<div style="text-align:center;padding:14px;background:var(--c-subtle);border-radius:8px">
					<b style="font-size:18px"><?php echo esc_html( $this->fmt( $st['size'] ) ); ?></b>
					<br><span class="pc-meta"><?php esc_html_e( 'Page Cache', 'prime-cache' ); ?></span>
				</div>
				<div style="text-align:center;padding:14px;background:var(--c-subtle);border-radius:8px">
					<b style="font-size:18px"><?php echo esc_html( size_format( $fo_size ) ); ?></b>
					<br><span class="pc-meta"><?php esc_html_e( 'Minified Files', 'prime-cache' ); ?></span>
				</div>
				<div style="text-align:center;padding:14px;background:var(--c-subtle);border-radius:8px">
					<b style="font-size:18px"><?php echo esc_html( $this->fmt( $st['size'] + $fo_size ) ); ?></b>
					<br><span class="pc-meta"><?php esc_html_e( 'Total', 'prime-cache' ); ?></span>
				</div>
			</div>
		</div>

		<!-- Environment -->
		<div class="pc-card">
			<span class="pc-card__h"><?php esc_html_e( 'Environment', 'prime-cache' ); ?></span>
			<div class="pc-sys" style="grid-template-columns:1fr 1fr">
				<?php
				global $wp_version;
				$env = array(
					'WordPress'  => $wp_version,
					'PHP'        => PHP_VERSION,
					'Server'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? preg_replace( '#/.*#', '', $_SERVER['SERVER_SOFTWARE'] ) : 'Unknown',
					'MySQL'      => $GLOBALS['wpdb']->db_version(),
					'Memory'     => ini_get( 'memory_limit' ),
					__( 'Theme', 'prime-cache' ) => wp_get_theme()->get( 'Name' ),
				);
				foreach ( $env as $label => $value ) :
				?>
				<div class="pc-sys__row"><span class="pc-sys__lbl"><?php echo esc_html( $label ); ?></span><span class="pc-sys__val"><?php echo esc_html( $value ); ?></span></div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/* ── tab: page cache ──────────────────────────────────── */

	private function tab_page( $settings, $on ) {
		$is_pro = prime_cache_is_pro();
		$purge = wp_nonce_url( admin_url( 'admin.php?prime_cache_purge=all' ), 'prime_cache_purge' );
		?>
		<h2 class="pc-title"><?php esc_html_e( 'Page Cache', 'prime-cache' ); ?></h2>

		<form method="post" action="options.php" id="pc-settings-form">
			<?php settings_fields( 'prime_cache_settings_group' ); ?>
			<input type="hidden" name="prime_cache_settings[cache_enabled]" value="<?php echo $on ? '1' : '0'; ?>" id="pc-ei">
			<?php $this->hidden( $settings, array( 'cache_enabled','cache_mobile','cache_mobile_separate','cache_logged_in','cache_404','gzip_compression','htaccess_enabled','browser_cache','browser_cache_css_js','browser_cache_images','browser_cache_fonts','browser_cache_html','brotli_compression','cache_control_immutable','varnish_enabled','varnish_ip','sucuri_enabled','sucuri_api_key','cache_lifespan','cache_footprint' ) ); ?>

			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'General Settings', 'prime-cache' ); ?></span>
				<?php
				$tg = array(
					array( 'cache_mobile',          __( 'Mobile Cache','prime-cache' ),           __( 'Serve cached pages to smartphones and tablets. When disabled, WordPress dynamically generates every page for mobile visitors.','prime-cache' ) ),
					array( 'cache_mobile_separate',  __( 'Separate Mobile Cache','prime-cache' ),  __( 'For themes that output different HTML for desktop and mobile. Maintains separate cache files per device type. Not needed for responsive themes.','prime-cache' ) ),
					array( 'cache_logged_in',        __( 'Logged-in User Cache','prime-cache' ),   __( 'Serve cached pages to logged-in users. Disable if your site shows admin bars or user-specific content. Useful for membership sites serving identical content to all users.','prime-cache' ) ),
					array( 'gzip_compression',       __( 'Gzip Compression','prime-cache' ),       __( 'Pre-compress cache files with gzip. Supported browsers receive the compressed version, reducing transfer size by 60-80%. Recommended for most environments.','prime-cache' ) ),
					array( 'brotli_compression',     __( 'Brotli Compression','prime-cache' ),     __( 'Enable Brotli compression via mod_brotli (Apache 2.4+). Brotli achieves 15-25% better compression than gzip for text-based assets. Requires mod_brotli to be installed on the server. Falls back to gzip if unavailable.','prime-cache' ) ),
					array( 'htaccess_enabled',       __( '.htaccess Optimization','prime-cache' ), __( 'Write optimization rules to .htaccess. Apache serves cached files directly without invoking PHP, significantly improving response time. Also enables mod_deflate compression, mod_expires browser caching, and ETag removal. No effect on Nginx.','prime-cache' ) ),
					array( 'cache_404',              __( 'Cache 404 Pages','prime-cache' ),        __( 'Cache 404 (Not Found) pages. Reduces server load from repeated requests to non-existent URLs. The cached 404 page is served with proper 404 HTTP status code via the PHP drop-in. Note: .htaccess Optimization does not serve cached 404 pages (they always go through PHP to ensure the correct status code).','prime-cache' ) ),
					array( 'cache_footprint',        __( 'Cache Footprint','prime-cache' ),        __( 'Append an HTML comment with cache generation time to the source. Useful for verifying cache behavior via "View Source". Can be disabled in production.','prime-cache' ) ),
				);
				foreach ( $tg as $t ) : ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[<?php echo esc_attr( $t[0] ); ?>]" value="1" <?php checked( $settings[ $t[0] ] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php echo esc_html( $t[1] ); ?></b><small><?php echo esc_html( $t[2] ); ?></small></span></label>
				<?php endforeach; ?>
				<?php if ( ! empty( $settings['htaccess_enabled'] ) && Prime_Cache_Htaccess::has_rules() ) : ?><span class="pc-badge pc-badge--g"><?php esc_html_e( '.htaccess Active','prime-cache' ); ?></span>
				<?php elseif ( ! empty( $settings['htaccess_enabled'] ) && ! Prime_Cache_Htaccess::is_writable() ) : ?><span class="pc-badge pc-badge--r"><?php esc_html_e( '.htaccess Not Writable','prime-cache' ); ?></span><?php endif; ?>
			</div>

			<!-- Varnish -->
			<div class="pc-card<?php echo $is_pro ? '' : ' pc-card--pro'; ?>">
				<span class="pc-card__h"><?php esc_html_e( 'Varnish', 'prime-cache' ); ?><?php if ( ! $is_pro ) : ?> <span class="pc-pro-badge">PRO</span><?php endif; ?></span>
				<label class="pc-sw">
					<input type="checkbox" name="prime_cache_settings[varnish_enabled]" value="1" <?php checked( $settings['varnish_enabled'] ); ?>>
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php esc_html_e( 'Varnish Cache Auto-Purge', 'prime-cache' ); ?></b>
						<small><?php esc_html_e( 'Automatically send HTTP PURGE requests to your Varnish reverse proxy when page cache is cleared. Supports exact URL purge and regex-based full-site purge. Enable if your server uses Varnish as a caching layer in front of WordPress.', 'prime-cache' ); ?></small>
					</span>
				</label>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Varnish Server IP', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[varnish_ip]" rows="2" class="pc-ta" placeholder="127.0.0.1"><?php echo esc_textarea( $settings['varnish_ip'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'IP address of your Varnish server. One per line for multiple servers. Leave empty to send PURGE requests to the site hostname directly. Can also be set via the PRIME_CACHE_VARNISH_IP constant in wp-config.php.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<!-- Sucuri -->
			<div class="pc-card<?php echo $is_pro ? '' : ' pc-card--pro'; ?>">
				<span class="pc-card__h">Sucuri<?php if ( ! $is_pro ) : ?> <span class="pc-pro-badge">PRO</span><?php endif; ?></span>
				<label class="pc-sw">
					<input type="checkbox" name="prime_cache_settings[sucuri_enabled]" value="1" <?php checked( $settings['sucuri_enabled'] ); ?>>
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php esc_html_e( 'Sucuri Firewall Cache Sync', 'prime-cache' ); ?></b>
						<small><?php esc_html_e( 'Automatically clear Sucuri WAF/CDN cache when Prime Cache is purged. Provide your Firewall API key below. Sucuri cache is purged on every full cache clear and debounced on individual URL purges.', 'prime-cache' ); ?></small>
					</span>
				</label>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Firewall API Key', 'prime-cache' ); ?></label>
					<input type="text" name="prime_cache_settings[sucuri_api_key]" value="<?php echo esc_attr( $settings['sucuri_api_key'] ); ?>" class="pc-ta" style="font-family:monospace" placeholder="abcdef0123456789abcdef0123456789/abcdef0123456789abcdef0123456789"
						<?php echo defined( 'PRIME_CACHE_SUCURI_API_KEY' ) ? 'disabled' : ''; ?>>
					<p class="pc-help"><?php esc_html_e( 'Firewall API key in the format: 32-character-key/32-character-secret. Find this in your Sucuri dashboard under Firewall > Settings > API. Can also be set via the PRIME_CACHE_SUCURI_API_KEY constant in wp-config.php.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<!-- Browser Cache -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Browser Cache', 'prime-cache' ); ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[browser_cache]" value="1" <?php checked( $settings['browser_cache'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Enable Browser Cache Headers', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Add Cache-Control headers to static assets via .htaccess. Instructs browsers to cache files locally for the specified duration, eliminating repeat downloads on subsequent page views.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[cache_control_immutable]" value="1" <?php checked( $settings['cache_control_immutable'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Immutable Cache-Control', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Add "immutable" directive to Cache-Control for CSS, JS, images, and fonts. Tells the browser the file will never change during its lifetime, preventing conditional revalidation requests (304 checks).', 'prime-cache' ); ?></small></span></label>

				<?php
				$bc_fields = array(
					array( 'browser_cache_css_js',  __( 'CSS & JS Cache Lifetime', 'prime-cache' ), '' ),
					array( 'browser_cache_images',  __( 'Images Cache Lifetime', 'prime-cache' ), '' ),
					array( 'browser_cache_fonts',   __( 'Fonts Cache Lifetime', 'prime-cache' ), '' ),
					array( 'browser_cache_html',    __( 'HTML Cache Lifetime', 'prime-cache' ), __( '0 = no-cache (recommended for HTML). Non-zero values cache HTML in the browser.', 'prime-cache' ) ),
				);
				foreach ( $bc_fields as $idx => $bf ) :
					$secs = (int) $settings[ $bf[0] ];
					if ( $secs <= 0 )            { $bv = 0; $bu = 86400; }
					elseif ( $secs % 31536000 === 0 ) { $bv = $secs / 31536000; $bu = 31536000; }
					elseif ( $secs % 2592000 === 0 )  { $bv = $secs / 2592000;  $bu = 2592000; }
					elseif ( $secs % 604800 === 0 )   { $bv = $secs / 604800;   $bu = 604800; }
					elseif ( $secs % 86400 === 0 )    { $bv = $secs / 86400;    $bu = 86400; }
					elseif ( $secs % 3600 === 0 )     { $bv = $secs / 3600;     $bu = 3600; }
					elseif ( $secs % 60 === 0 )       { $bv = $secs / 60;       $bu = 60; }
					else                              { $bv = $secs;            $bu = 1; }
					$fid = 'pc-bc-' . $idx;
				?>
				<div class="pc-field">
					<label class="pc-lbl"><?php echo esc_html( $bf[1] ); ?></label>
					<div class="pc-ls-row">
						<input type="number" value="<?php echo esc_attr( $bv ); ?>" min="0" class="pc-inp" style="width:80px" data-pc-bc-val="<?php echo esc_attr( $fid ); ?>">
						<select class="pc-sel" data-pc-bc-unit="<?php echo esc_attr( $fid ); ?>">
							<option value="31536000" <?php selected( $bu, 31536000 ); ?>><?php esc_html_e( 'Years', 'prime-cache' ); ?></option>
							<option value="2592000" <?php selected( $bu, 2592000 ); ?>><?php esc_html_e( 'Months', 'prime-cache' ); ?></option>
							<option value="604800" <?php selected( $bu, 604800 ); ?>><?php esc_html_e( 'Weeks', 'prime-cache' ); ?></option>
							<option value="86400" <?php selected( $bu, 86400 ); ?>><?php esc_html_e( 'Days', 'prime-cache' ); ?></option>
							<option value="3600" <?php selected( $bu, 3600 ); ?>><?php esc_html_e( 'Hours', 'prime-cache' ); ?></option>
							<option value="60" <?php selected( $bu, 60 ); ?>><?php esc_html_e( 'Minutes', 'prime-cache' ); ?></option>
							<option value="1" <?php selected( $bu, 1 ); ?>><?php esc_html_e( 'Seconds', 'prime-cache' ); ?></option>
						</select>
						<span class="pc-meta" data-pc-bc-eq="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( self::seconds_to_human( $secs ) ); ?></span>
						<input type="hidden" name="prime_cache_settings[<?php echo esc_attr( $bf[0] ); ?>]" value="<?php echo esc_attr( $secs ); ?>" data-pc-bc-hidden="<?php echo esc_attr( $fid ); ?>">
					</div>
					<?php if ( $bf[2] ) : ?><p class="pc-help"><?php echo esc_html( $bf[2] ); ?></p><?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>

			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Cache Lifespan','prime-cache' ); ?></span>
				<?php $ls = (int) $settings['cache_lifespan'];
				$pre = array( array(0,__('Unlimited','prime-cache')), array(3600,__('1 Hour','prime-cache')), array(21600,__('6 Hours','prime-cache')), array(43200,__('12 Hours','prime-cache')), array(86400,__('1 Day','prime-cache')), array(172800,__('2 Days','prime-cache')), array(604800,__('7 Days','prime-cache')) );
				if($ls<=0){$dv=0;$du=3600;}
				elseif($ls%31536000===0){$dv=$ls/31536000;$du=31536000;}
				elseif($ls%2592000===0){$dv=$ls/2592000;$du=2592000;}
				elseif($ls%604800===0){$dv=$ls/604800;$du=604800;}
				elseif($ls%86400===0){$dv=$ls/86400;$du=86400;}
				elseif($ls%3600===0){$dv=$ls/3600;$du=3600;}
				elseif($ls%60===0){$dv=$ls/60;$du=60;}
				else{$dv=$ls;$du=1;} ?>
				<div class="pc-chips"><?php foreach($pre as $p): ?><button type="button" class="pc-chip<?php echo $ls===$p[0]?' is-on':''; ?>" data-s="<?php echo esc_attr($p[0]); ?>"><?php echo esc_html($p[1]); ?></button><?php endforeach; ?></div>
				<div class="pc-ls-row">
					<input type="number" id="pc-lv" value="<?php echo esc_attr($dv); ?>" min="0" class="pc-inp">
					<select id="pc-lu" class="pc-sel">
						<option value="31536000" <?php selected($du,31536000); ?>><?php esc_html_e('Years','prime-cache'); ?></option>
						<option value="2592000" <?php selected($du,2592000); ?>><?php esc_html_e('Months','prime-cache'); ?></option>
						<option value="604800" <?php selected($du,604800); ?>><?php esc_html_e('Weeks','prime-cache'); ?></option>
						<option value="86400" <?php selected($du,86400); ?>><?php esc_html_e('Days','prime-cache'); ?></option>
						<option value="3600" <?php selected($du,3600); ?>><?php esc_html_e('Hours','prime-cache'); ?></option>
						<option value="60" <?php selected($du,60); ?>><?php esc_html_e('Minutes','prime-cache'); ?></option>
						<option value="1" <?php selected($du,1); ?>><?php esc_html_e('Seconds','prime-cache'); ?></option>
					</select>
					<span class="pc-meta" id="pc-leq"><?php echo $ls<=0?'= '.esc_html__('Unlimited','prime-cache'):'= '.number_format($ls).' '.esc_html__('sec','prime-cache'); ?></span>
					<input type="hidden" id="pc-lh" name="prime_cache_settings[cache_lifespan]" value="<?php echo esc_attr($ls); ?>">
				</div>
				<p class="pc-help"><?php esc_html_e( 'How long until cache files are automatically discarded. Expired caches are regenerated on the next visit and cleaned up hourly by WP-Cron. Set to 0 for unlimited (purge only on post updates, comments, and term changes). For frequently updated news sites, use 1-6 hours. For less active sites, use 1-7 days or unlimited. Note: When .htaccess Optimization is enabled, Apache serves cached files directly and cannot check file age. Expired files may continue to be served until the next WP-Cron cleanup (runs hourly).','prime-cache' ); ?></p>
			</div>

			<div class="pc-actions">
				<?php submit_button( __( 'Save Settings','prime-cache' ), 'primary large', 'submit', false ); ?>
				<a href="<?php echo esc_url($purge); ?>" class="pc-btn pc-btn--r"><span class="dashicons dashicons-trash"></span><?php esc_html_e('Clear Cache','prime-cache'); ?></a>
			</div>
		</form>

		<script>
		(function(){
			var vi=document.getElementById('pc-lv'),ui=document.getElementById('pc-lu'),hi=document.getElementById('pc-lh'),eq=document.getElementById('pc-leq'),cs=document.querySelectorAll('.pc-chip');
			if(!vi||!ui||!hi)return;
			var U=<?php echo wp_json_encode(__('Unlimited','prime-cache'), JSON_HEX_TAG);?>;
			var uL={31536000:<?php echo wp_json_encode(__('year(s)','prime-cache'), JSON_HEX_TAG);?>,2592000:<?php echo wp_json_encode(__('month(s)','prime-cache'), JSON_HEX_TAG);?>,604800:<?php echo wp_json_encode(__('week(s)','prime-cache'), JSON_HEX_TAG);?>,86400:<?php echo wp_json_encode(__('day(s)','prime-cache'), JSON_HEX_TAG);?>,3600:<?php echo wp_json_encode(__('hour(s)','prime-cache'), JSON_HEX_TAG);?>,60:<?php echo wp_json_encode(__('minute(s)','prime-cache'), JSON_HEX_TAG);?>,1:<?php echo wp_json_encode(__('sec','prime-cache'), JSON_HEX_TAG);?>};
			function hum(s){if(s<=0)return'= '+U;var k=[31536000,2592000,604800,86400,3600,60,1];for(var i=0;i<k.length;i++){if(s>=k[i]&&s%k[i]===0)return'= '+(s/k[i])+' '+uL[k[i]];}return'= '+s+' '+uL[1];}
			function C(){var s=(parseInt(vi.value,10)||0)*(parseInt(ui.value,10)||1);hi.value=s;eq.textContent=hum(s);cs.forEach(function(b){b.classList.toggle('is-on',parseInt(b.dataset.s,10)===s);});}
			vi.addEventListener('input',C);ui.addEventListener('change',C);
			cs.forEach(function(b){b.addEventListener('click',function(){var s=parseInt(b.dataset.s,10);if(s===0){vi.value=0;}
				else if(s%31536000===0){ui.value='31536000';vi.value=s/31536000;}
				else if(s%2592000===0){ui.value='2592000';vi.value=s/2592000;}
				else if(s%604800===0){ui.value='604800';vi.value=s/604800;}
				else if(s%86400===0){ui.value='86400';vi.value=s/86400;}
				else if(s%3600===0){ui.value='3600';vi.value=s/3600;}
				else if(s%60===0){ui.value='60';vi.value=s/60;}
				else{ui.value='1';vi.value=s;}C();});});

			/* Browser Cache lifetime fields */
			document.querySelectorAll('[data-pc-bc-val]').forEach(function(valEl){
				var fid=valEl.dataset.pcBcVal;
				var unitEl=document.querySelector('[data-pc-bc-unit="'+fid+'"]');
				var hidEl=document.querySelector('[data-pc-bc-hidden="'+fid+'"]');
				var eqEl=document.querySelector('[data-pc-bc-eq="'+fid+'"]');
				if(!unitEl||!hidEl)return;
				function upd(){
					var s=(parseInt(valEl.value,10)||0)*(parseInt(unitEl.value,10)||1);
					hidEl.value=s;
					if(eqEl){
						if(s<=0) eqEl.textContent='(no-cache)';
						else if(s>=31536000) eqEl.textContent='= '+(s/31536000)+' '+<?php echo wp_json_encode(__('year(s)','prime-cache'), JSON_HEX_TAG);?>;
						else if(s>=2592000) eqEl.textContent='= '+Math.round(s/2592000*10)/10+' '+<?php echo wp_json_encode(__('month(s)','prime-cache'), JSON_HEX_TAG);?>;
						else if(s>=86400) eqEl.textContent='= '+Math.round(s/86400*10)/10+' '+<?php echo wp_json_encode(__('day(s)','prime-cache'), JSON_HEX_TAG);?>;
						else if(s>=3600) eqEl.textContent='= '+Math.round(s/3600*10)/10+' '+<?php echo wp_json_encode(__('hour(s)','prime-cache'), JSON_HEX_TAG);?>;
						else eqEl.textContent='= '+s+' '+<?php echo wp_json_encode(__('sec','prime-cache'), JSON_HEX_TAG);?>;
					}
				}
				valEl.addEventListener('input',upd);
				unitEl.addEventListener('change',upd);
			});
		})();
		</script>
		<?php
	}

	/* ── tab: file optimization ────────────────────────────── */

	private function tab_file_opt( $settings ) {
		$is_pro = prime_cache_is_pro();
		$fo_keys = array(
			'minify_html','minify_html_dom','remove_html_comments','disable_emoji',
			'minify_css','combine_css','optimize_css_delivery','css_delivery_method',
			'async_css','critical_css','critical_css_auto','remove_unused_css','ucss_safelist','exclude_css',
			'minify_js','combine_js','defer_js','delay_js','delay_js_timeout',
			'exclude_js','exclude_inline_js','exclude_defer_js','exclude_delay_js',
			'combine_google_fonts','self_host_google_fonts','google_fonts_display',
			'remove_query_strings','rewrite_file_optimizer',
			'disable_emoji','disable_jquery_migrate','disable_wp_embed','disable_dashicons',
			'disable_wp_version','disable_xmlrpc','disable_self_pingback',
			'limit_revisions','revisions_max','disable_rss_feeds','disable_oembed',
			'disable_block_css','disable_google_fonts','disable_global_styles',
			'disable_shortlink','disable_rsd_wlw','disable_rest_api_link',
			'disable_wp_sitemap','add_blank_favicon',
			'woo_disable_scripts','woo_disable_cart_frag',
			'delay_js_safe_mode','delay_js_presets',
			'inline_small_css','inline_css_threshold','local_analytics',
		);
		?>
		<h2 class="pc-title"><?php esc_html_e( 'File Optimization', 'prime-cache' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'prime_cache_settings_group' ); ?>
			<?php $this->hidden( $settings, $fo_keys ); ?>

			<!-- HTML -->
			<div class="pc-card">
				<span class="pc-card__h">HTML</span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[minify_html]" value="1" <?php checked( $settings['minify_html'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Minify HTML', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Remove unnecessary whitespace from the HTML output to reduce page size. Content inside pre, script, style, and textarea tags is preserved.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[minify_html_dom]" value="1" <?php checked( $settings['minify_html_dom'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Optimize HTML via DOM Parser', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Use DOMDocument for deeper HTML optimization. Parses the document tree to more aggressively collapse whitespace between block-level elements while preserving inline formatting. Falls back to regex if DOM parsing fails.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[remove_html_comments]" value="1" <?php checked( $settings['remove_html_comments'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Remove HTML Comments', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Strip HTML comments from the output. IE conditional comments are preserved.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[disable_emoji]" value="1" <?php checked( $settings['disable_emoji'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Disable WordPress Emoji', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Remove the emoji inline CSS, wp-emoji-release.min.js script, and DNS prefetch for s.w.org from all pages. Reduces 2 HTTP requests and ~16 KB from every page load. Does not affect actual emoji display in modern browsers.', 'prime-cache' ); ?></small></span></label>
			</div>

			<!-- CSS -->
			<div class="pc-card">
				<span class="pc-card__h">CSS</span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[minify_css]" value="1" <?php checked( $settings['minify_css'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Minify CSS', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Remove whitespace, comments, and unnecessary characters from CSS files to reduce file size.', 'prime-cache' ); ?></small></span></label>
				<?php if ( ! $is_pro ) : ?><div class="pc-pro-wrap"><span class="pc-pro-badge">PRO</span><?php endif; ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[combine_css]" value="1" <?php checked( $settings['combine_css'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Combine CSS Files', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Merge multiple CSS files into a single file to reduce HTTP requests. Not recommended on HTTP/2 servers.', 'prime-cache' ); ?></small></span></label>
			<?php if ( ! $is_pro ) : ?></div><?php endif; ?>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Excluded CSS Files', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[exclude_css]" rows="3" class="pc-ta" placeholder="/wp-content/plugins/some-plugin/(.*).css"><?php echo esc_textarea( $settings['exclude_css'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'One pattern per line. These CSS files will not be minified, combined, or loaded asynchronously. Supports wildcards (*).', 'prime-cache' ); ?></p>
				</div>
				<?php if ( ! $is_pro ) : ?><div class="pc-pro-wrap"><span class="pc-pro-badge">PRO</span><?php endif; ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[inline_small_css]" value="1" <?php checked( $settings['inline_small_css'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Inline Small CSS Files', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Inline CSS files smaller than the threshold directly into the HTML as <style> tags, eliminating HTTP requests for small stylesheets.', 'prime-cache' ); ?></small></span></label>
				<div class="pc-field" style="margin-left:52px">
					<label class="pc-lbl"><?php esc_html_e( 'Threshold (bytes)', 'prime-cache' ); ?></label>
					<input type="number" name="prime_cache_settings[inline_css_threshold]" value="<?php echo esc_attr( $settings['inline_css_threshold'] ); ?>" min="0" max="65536" class="pc-inp" style="width:100px">
					<span class="pc-meta"><?php echo esc_html( size_format( $settings['inline_css_threshold'] ) ); ?></span>
				</div>
			<?php if ( ! $is_pro ) : ?></div><?php endif; ?>
			</div>

			<!-- Optimize CSS Delivery -->
			<div class="pc-card<?php echo $is_pro ? '' : ' pc-card--pro'; ?>">
				<span class="pc-card__h"><?php esc_html_e( 'Optimize CSS Delivery', 'prime-cache' ); ?><?php if ( ! $is_pro ) : ?> <span class="pc-pro-badge">PRO</span><?php endif; ?></span>
				<p class="pc-help" style="margin:-4px 0 16px"><?php esc_html_e( 'Eliminates render-blocking CSS on your website. Only one method can be selected. Remove Unused CSS is recommended for optimal performance.', 'prime-cache' ); ?></p>

				<?php
				$ocd_on  = ! empty( $settings['optimize_css_delivery'] );
				$method  = $settings['css_delivery_method'] ?? 'remove_unused_css';
				?>

				<label class="pc-sw" style="margin-bottom:12px">
					<input type="checkbox" name="prime_cache_settings[optimize_css_delivery]" value="1" <?php checked( $ocd_on ); ?> id="pc-ocd-toggle">
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body"><b><?php esc_html_e( 'Enable Optimize CSS Delivery', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Turn on CSS delivery optimization. Choose a method below.', 'prime-cache' ); ?></small></span>
				</label>

				<div class="pc-ocd-methods" id="pc-ocd-methods" style="<?php echo $ocd_on ? '' : 'opacity:.4;pointer-events:none;'; ?>">

					<!-- Method: Remove Unused CSS -->
					<label class="pc-radio <?php echo 'remove_unused_css' === $method ? 'pc-radio--on' : ''; ?>">
						<input type="radio" name="prime_cache_settings[css_delivery_method]" value="remove_unused_css" <?php checked( $method, 'remove_unused_css' ); ?>>
						<span class="pc-radio__mark"></span>
						<span class="pc-radio__body">
							<b><?php esc_html_e( 'Remove Unused CSS', 'prime-cache' ); ?></b> <span class="pc-badge pc-badge--a" style="font-size:10px"><?php esc_html_e( 'Advanced', 'prime-cache' ); ?></span>
							<small><?php esc_html_e( 'Removes unused CSS per page (URL-specific). Can significantly reduce page size but may break layouts on pages with dynamic content, JS-injected classes, or complex selectors. Add affected selectors to the safelist below if issues occur. Test thoroughly on all page types!', 'prime-cache' ); ?></small>
						</span>
					</label>

					<div class="pc-radio-sub" id="pc-ucss-sub" style="<?php echo 'remove_unused_css' === $method ? '' : 'display:none;'; ?>">
						<div class="pc-field">
							<label class="pc-lbl"><?php esc_html_e( 'CSS Safelist', 'prime-cache' ); ?></label>
							<textarea name="prime_cache_settings[ucss_safelist]" rows="4" class="pc-ta" placeholder="/wp-content/plugins/some-plugin/(.*).css&#10;.btn&#10;.modal&#10;.dropdown&#10;#main-menu"><?php echo esc_textarea( $settings['ucss_safelist'] ); ?></textarea>
							<p class="pc-help"><?php esc_html_e( 'CSS selectors and file patterns that should never be removed (one per line). Add selectors for elements that appear via JavaScript interaction (modals, dropdowns, tabs, accordions), AJAX-loaded content, or hover/focus states. Common examples: .modal, .dropdown-menu, .tab-content, .accordion, .wp-block-*.', 'prime-cache' ); ?></p>
						</div>
					</div>

					<!-- Method: Load CSS Asynchronously -->
					<label class="pc-radio <?php echo 'async_css' === $method ? 'pc-radio--on' : ''; ?>">
						<input type="radio" name="prime_cache_settings[css_delivery_method]" value="async_css" <?php checked( $method, 'async_css' ); ?>>
						<span class="pc-radio__mark"></span>
						<span class="pc-radio__body">
							<b><?php esc_html_e( 'Load CSS Asynchronously', 'prime-cache' ); ?></b>
							<small><?php esc_html_e( 'Load stylesheets asynchronously via media="print" onload swap. Eliminates render-blocking CSS. Provide critical CSS to prevent flash of unstyled content.', 'prime-cache' ); ?></small>
						</span>
					</label>

					<div class="pc-radio-sub" id="pc-async-sub" style="<?php echo 'async_css' === $method ? '' : 'display:none;'; ?>">
						<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[critical_css_auto]" value="1" <?php checked( $settings['critical_css_auto'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Auto-generate Critical CSS', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Automatically extract above-the-fold CSS rules per page. Cached for 7 days per URL. Skipped if manual critical CSS is provided below. Critical CSS is injected into the HTML head independently. Best combined with "Load CSS Asynchronously" to prevent flash of unstyled content.', 'prime-cache' ); ?></small></span></label>
						<div class="pc-field">
							<label class="pc-lbl"><?php esc_html_e( 'Fallback Critical CSS', 'prime-cache' ); ?></label>
							<textarea name="prime_cache_settings[critical_css]" rows="4" class="pc-ta" placeholder="body{margin:0}header{...}"><?php echo esc_textarea( $settings['critical_css'] ); ?></textarea>
							<p class="pc-help"><?php esc_html_e( 'CSS to inline in the head for above-the-fold content. Prevents flash of unstyled content while stylesheets load. If auto-generate is enabled and this field is empty, critical CSS will be generated automatically. Both options require "Load CSS Asynchronously" to be selected.', 'prime-cache' ); ?></p>
						</div>
					</div>

				</div>
			</div>

			<!-- JavaScript -->
			<div class="pc-card">
				<span class="pc-card__h">JavaScript</span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[minify_js]" value="1" <?php checked( $settings['minify_js'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Minify JavaScript', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Remove whitespace and comments from JavaScript files to reduce file size. Already minified files (.min.js) are skipped.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[defer_js]" value="1" <?php checked( $settings['defer_js'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Load JavaScript Deferred', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Add the defer attribute to enqueued scripts (wp_enqueue_script) to eliminate render-blocking JavaScript. Scripts are downloaded in parallel and executed after HTML parsing. Manually inserted scripts in theme templates are not affected.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[delay_js]" value="1" <?php checked( $settings['delay_js'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Delay JavaScript Execution', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Delay loading of enqueued JavaScript (wp_enqueue_script) until user interaction (scroll, click, keypress, touch). Significantly improves initial page load metrics but may cause a brief delay on first interaction. Manually inserted scripts in theme templates are not affected.', 'prime-cache' ); ?></small></span></label>

				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Delay Timeout (ms)', 'prime-cache' ); ?></label>
					<input type="number" name="prime_cache_settings[delay_js_timeout]" value="<?php echo esc_attr( $settings['delay_js_timeout'] ); ?>" min="0" max="30000" class="pc-inp" style="width:140px">
					<p class="pc-help"><?php esc_html_e( 'Auto-load delayed scripts after this many milliseconds even without user interaction. 0 = wait for interaction only.', 'prime-cache' ); ?></p>
				</div>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[delay_js_safe_mode]" value="1" <?php checked( $settings['delay_js_safe_mode'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Delay JS Safe Mode', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Only delay external (third-party) scripts. All internal scripts from your site (wp-includes, wp-content) load immediately. Reduces performance gains but prevents most compatibility issues.', 'prime-cache' ); ?></small></span></label>
				<?php if ( ! $is_pro ) : ?><div class="pc-pro-wrap"><span class="pc-pro-badge">PRO</span><?php endif; ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[combine_js]" value="1" <?php checked( $settings['combine_js'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Combine JavaScript Files', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Merge multiple JS files into a single file to reduce HTTP requests. Not recommended on HTTP/2 servers. May cause issues — test thoroughly.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[local_analytics]" value="1" <?php checked( $settings['local_analytics'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Local Google Analytics', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Download gtag.js, analytics.js, and gtm.js to your server and serve them locally. Eliminates external connections to Google domains and improves PageSpeed scores. Files are refreshed every 24 hours.', 'prime-cache' ); ?></small></span></label>
			<?php if ( ! $is_pro ) : ?></div><?php endif; ?>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Excluded JS Files', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[exclude_js]" rows="3" class="pc-ta" placeholder="jquery.min.js&#10;/wp-includes/js/wp-embed.min.js"><?php echo esc_textarea( $settings['exclude_js'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'One pattern per line. These JS files will not be minified or combined. Supports wildcards (*).', 'prime-cache' ); ?></p>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Excluded Inline JavaScript', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[exclude_inline_js]" rows="2" class="pc-ta" placeholder="recaptcha&#10;gtag"><?php echo esc_textarea( $settings['exclude_inline_js'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'Keywords to identify inline scripts to exclude from optimization. One per line.', 'prime-cache' ); ?></p>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Excluded from Defer', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[exclude_defer_js]" rows="2" class="pc-ta" placeholder="jquery.min.js"><?php echo esc_textarea( $settings['exclude_defer_js'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'JS files that should not be deferred. One pattern per line.', 'prime-cache' ); ?></p>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Excluded from Delay', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[exclude_delay_js]" rows="2" class="pc-ta" placeholder="jquery.min.js&#10;wp-includes/js/dist/interactivity"><?php echo esc_textarea( $settings['exclude_delay_js'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'JS files that should not be delayed. One pattern per line. Scripts with data-no-delay attribute are always excluded.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<!-- Fonts & Other -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Fonts & Other', 'prime-cache' ); ?></span>
				<?php if ( ! $is_pro ) : ?><div class="pc-pro-wrap"><span class="pc-pro-badge">PRO</span><?php endif; ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[combine_google_fonts]" value="1" <?php checked( $settings['combine_google_fonts'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Combine Google Fonts', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Merge multiple Google Fonts API requests into a single request to reduce external connections.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[self_host_google_fonts]" value="1" <?php checked( $settings['self_host_google_fonts'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Self-host Google Fonts', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Download Google Fonts CSS and font files to your server and serve them locally. Eliminates external connections to fonts.googleapis.com and fonts.gstatic.com, improving privacy and reducing DNS lookups and connection overhead.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[google_fonts_display]" value="1" <?php checked( $settings['google_fonts_display'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Google Fonts Display Swap', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Add display=swap to Google Fonts URLs so text remains visible while web fonts load (prevents FOIT).', 'prime-cache' ); ?></small></span></label>
				<?php if ( ! $is_pro ) : ?></div><?php endif; ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[remove_query_strings]" value="1" <?php checked( $settings['remove_query_strings'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Remove Query Strings', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Remove version query strings (?ver=) from CSS and JS file URLs. Improves cacheability by CDNs and proxies that ignore query strings.', 'prime-cache' ); ?></small></span></label>

			</div>

			<!-- Advanced -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Advanced', 'prime-cache' ); ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[rewrite_file_optimizer]" value="1" <?php checked( $settings['rewrite_file_optimizer'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Enable Rewrite for File Optimizer', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Serve optimized CSS/JS files via clean URLs (/_pc-static/) instead of direct file paths. Uses WordPress rewrite rules for portability. Adds long-lived Cache-Control headers (1 year, immutable) for optimal browser caching. Permalinks are flushed automatically when this setting is changed.', 'prime-cache' ); ?></small></span></label>
			</div>

			<!-- Performance Tweaks -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Performance Tweaks', 'prime-cache' ); ?></span>
				<?php
				$tweaks = array(
					array( 'disable_jquery_migrate', __( 'Disable jQuery Migrate', 'prime-cache' ), __( 'Remove jquery-migrate.min.js from the frontend. Most modern jQuery code and plugins no longer need it. Saves ~10 KB per page.', 'prime-cache' ) ),
					array( 'disable_wp_embed', __( 'Disable WP Embed', 'prime-cache' ), __( 'Remove wp-embed.min.js and oEmbed discovery links. Prevents other sites from embedding your content and removes ~6 KB of JavaScript.', 'prime-cache' ) ),
					array( 'disable_dashicons', __( 'Disable Dashicons (Frontend)', 'prime-cache' ), __( 'Remove the Dashicons stylesheet for non-logged-in visitors. Saves ~46 KB. Icons remain available for logged-in users and the admin area.', 'prime-cache' ) ),
					array( 'disable_wp_version', __( 'Remove WordPress Version', 'prime-cache' ), __( 'Remove the WordPress version meta tag and header. Minor security improvement — prevents version fingerprinting.', 'prime-cache' ) ),
					array( 'disable_xmlrpc', __( 'Disable XML-RPC', 'prime-cache' ), __( 'Disable the XML-RPC API via the xmlrpc_enabled filter and remove the X-Pingback header. This does not block access to xmlrpc.php at the server level — use .htaccess or server configuration for full blocking. Not needed if you use the REST API.', 'prime-cache' ) ),
					array( 'disable_self_pingback', __( 'Disable Self-Pingbacks', 'prime-cache' ), __( 'Prevent WordPress from sending pingback requests to your own site when you link to your own posts.', 'prime-cache' ) ),
					array( 'disable_rss_feeds', __( 'Disable RSS Feeds', 'prime-cache' ), __( 'Disable all RSS/Atom feeds and redirect feed URLs to the homepage. Use only if your site does not need feeds.', 'prime-cache' ) ),
					array( 'disable_oembed', __( 'Disable oEmbed', 'prime-cache' ), __( 'Remove oEmbed discovery links, host JavaScript, and REST API route. Prevents remote embedding of your content.', 'prime-cache' ) ),
					array( 'disable_block_css', __( 'Disable Gutenberg Block CSS', 'prime-cache' ), __( 'Remove wp-block-library and wp-block-library-theme stylesheets. Use only if you are using the Classic Editor and not using any Gutenberg blocks.', 'prime-cache' ) ),
					array( 'disable_google_fonts', __( 'Disable Google Fonts', 'prime-cache' ), __( 'Dequeue all Google Fonts (fonts.googleapis.com and fonts.bunny.net) loaded by themes and plugins. Use if you self-host fonts or do not need external fonts.', 'prime-cache' ) ),
					array( 'disable_global_styles', __( 'Disable Global Styles (SVG)', 'prime-cache' ), __( 'Remove the global-styles inline CSS and SVG filters added by WordPress 6.1+. Saves ~2 KB of inline markup on every page.', 'prime-cache' ) ),
				);
				foreach ( $tweaks as $t ) :
				?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[<?php echo esc_attr( $t[0] ); ?>]" value="1" <?php checked( $settings[ $t[0] ] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php echo esc_html( $t[1] ); ?></b><small><?php echo esc_html( $t[2] ); ?></small></span></label>
				<?php endforeach; ?>

				<!-- Limit Revisions -->
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[limit_revisions]" value="1" <?php checked( $settings['limit_revisions'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Limit Post Revisions', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Limit the maximum number of revisions stored per post. Reduces database bloat over time.', 'prime-cache' ); ?></small></span></label>
				<div class="pc-field" style="margin-left:52px">
					<label class="pc-lbl"><?php esc_html_e( 'Max Revisions', 'prime-cache' ); ?></label>
					<input type="number" name="prime_cache_settings[revisions_max]" value="<?php echo esc_attr( $settings['revisions_max'] ); ?>" min="0" max="100" class="pc-inp" style="width:80px">
					<span class="pc-meta"><?php esc_html_e( '0 = disable revisions entirely', 'prime-cache' ); ?></span>
				</div>

				<!-- WooCommerce -->
				<?php if ( class_exists( 'WooCommerce' ) || file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ) : ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[woo_disable_scripts]" value="1" <?php checked( $settings['woo_disable_scripts'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'WooCommerce Script Optimization', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Disable WooCommerce CSS and JavaScript on non-WooCommerce pages (product pages, cart, checkout, and account pages are excluded). Saves ~100 KB+ on regular blog/pages.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[woo_disable_cart_frag]" value="1" <?php checked( $settings['woo_disable_cart_frag'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Disable Cart Fragments AJAX', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Disable WooCommerce cart fragments AJAX request (wc-ajax=get_refreshed_fragments) on non-cart/checkout pages. This AJAX call runs on every page load and is one of the biggest WooCommerce performance bottlenecks.', 'prime-cache' ); ?></small></span></label>
				<?php endif; ?>

				<!-- WP Head Cleanup -->
				<div style="border-top:1px solid var(--c-subtle);padding-top:14px;margin-top:6px">
					<label class="pc-lbl" style="margin-bottom:8px"><?php esc_html_e( 'WP Head Cleanup', 'prime-cache' ); ?></label>
					<?php
					$head_tweaks = array(
						array( 'disable_shortlink',     __( 'Remove Shortlink', 'prime-cache' ),     __( 'Remove the shortlink tag and HTTP header. Not needed if you use pretty permalinks.', 'prime-cache' ) ),
						array( 'disable_rsd_wlw',       __( 'Remove RSD & WLW Manifest', 'prime-cache' ), __( 'Remove Really Simple Discovery and Windows Live Writer manifest links. Only needed for XML-RPC clients.', 'prime-cache' ) ),
						array( 'disable_rest_api_link',  __( 'Remove REST API Link', 'prime-cache' ), __( 'Remove the REST API discovery link tag and HTTP header from the frontend.', 'prime-cache' ) ),
						array( 'disable_wp_sitemap',     __( 'Disable WordPress Sitemap', 'prime-cache' ), __( 'Disable the built-in WordPress XML sitemap (/wp-sitemap.xml). Use if you have a sitemap plugin like Yoast SEO or Rank Math.', 'prime-cache' ) ),
						array( 'add_blank_favicon',      __( 'Add Blank Favicon', 'prime-cache' ),   __( 'Add an inline SVG favicon to prevent the browser from requesting a missing favicon.ico (404). Only applied when no site icon is set.', 'prime-cache' ) ),
					);
					foreach ( $head_tweaks as $t ) :
					?>
					<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[<?php echo esc_attr( $t[0] ); ?>]" value="1" <?php checked( $settings[ $t[0] ] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php echo esc_html( $t[1] ); ?></b><small><?php echo esc_html( $t[2] ); ?></small></span></label>
					<?php endforeach; ?>
				</div>

				<!-- Delay JS Presets -->
				<div class="pc-field" style="border-top:1px solid var(--c-subtle);padding-top:14px;margin-top:6px">
					<label class="pc-lbl"><?php esc_html_e( '3rd-Party Script Delay Presets', 'prime-cache' ); ?></label>
					<p class="pc-help" style="margin:0 0 10px"><?php esc_html_e( 'Select known third-party scripts to exclude from JavaScript delay execution. These scripts will load immediately instead of waiting for user interaction.', 'prime-cache' ); ?></p>
					<?php
					$presets = array(
						'google-analytics' => 'Google Analytics (gtag.js / analytics.js)',
						'google-tag-manager' => 'Google Tag Manager (gtm.js)',
						'facebook-pixel' => 'Facebook Pixel (fbevents.js)',
						'hotjar' => 'Hotjar (hotjar.com)',
						'recaptcha' => 'Google reCAPTCHA (recaptcha)',
						'clarity' => 'Microsoft Clarity (clarity.ms)',
						'intercom' => 'Intercom',
						'crisp' => 'Crisp Chat',
						'tawk' => 'Tawk.to',
					);
					$active_presets = array_filter( array_map( 'trim', explode( ',', $settings['delay_js_presets'] ) ) );
					foreach ( $presets as $key => $label ) :
					?>
					<label style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;cursor:pointer">
						<input type="checkbox" name="pc_delay_presets[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $active_presets, true ) ); ?> style="margin:0">
						<?php echo esc_html( $label ); ?>
					</label>
					<?php endforeach; ?>
					<input type="hidden" name="prime_cache_settings[delay_js_presets]" value="<?php echo esc_attr( implode( ',', $active_presets ) ); ?>" id="pc-delay-presets-hidden">
				</div>
			</div>

			<div class="pc-actions"><?php submit_button( __( 'Save Settings', 'prime-cache' ), 'primary large', 'submit', false ); ?></div>
		</form>

		<script>
		(function(){
			var toggle=document.getElementById('pc-ocd-toggle'),
				methods=document.getElementById('pc-ocd-methods'),
				radios=methods?methods.querySelectorAll('input[type="radio"]'):[];
			if(!toggle||!methods)return;

			function syncToggle(){
				methods.style.opacity=toggle.checked?'1':'.4';
				methods.style.pointerEvents=toggle.checked?'':'none';
			}
			toggle.addEventListener('change',syncToggle);

			function syncMethod(){
				var val='';
				radios.forEach(function(r){if(r.checked)val=r.value;});
				var ucss=document.getElementById('pc-ucss-sub'),
					async=document.getElementById('pc-async-sub');
				if(ucss) ucss.style.display=(val==='remove_unused_css')?'':'none';
				if(async) async.style.display=(val==='async_css')?'':'none';
				// Update radio label highlight.
				methods.querySelectorAll('.pc-radio').forEach(function(el){
					el.classList.toggle('pc-radio--on',el.querySelector('input').checked);
				});
			}
			radios.forEach(function(r){r.addEventListener('change',syncMethod);});

			/* Delay JS presets → hidden field */
			var cbs=document.querySelectorAll('input[name="pc_delay_presets[]"]'),hid=document.getElementById('pc-delay-presets-hidden');
			if(cbs.length&&hid){
				function syncPresets(){var v=[];cbs.forEach(function(c){if(c.checked)v.push(c.value);});hid.value=v.join(',');}
				cbs.forEach(function(c){c.addEventListener('change',syncPresets);});
			}
		})();
		</script>
		<?php
	}

	/* ── tab: media ───────────────────────────────────────── */

	private function tab_media( $settings ) {
		$vis = array(
			'lazyload_images','lazyload_iframes','lazyload_videos','lazyload_disable_native','lazyload_exclude',			'youtube_thumbnail','add_missing_dimensions',
			'img_conversion_enabled','webp_enabled','avif_enabled','img_quality_mode','webp_quality','avif_quality',
			'img_strip_exif','img_resize','img_max_width','img_max_height',
			'img_auto_optimize','img_auto_remove_larger','img_exclude_png',
			'img_include_uploads','img_include_themes','img_include_plugins','img_include_custom','img_exclude_folders',
			'img_delivery_method','img_converter',
		);
		$caps = class_exists( 'Prime_Cache_WebP' ) ? Prime_Cache_WebP::get_capabilities() : array( 'gd_webp' => false, 'imagick_webp' => false, 'gd_avif' => false, 'imagick_avif' => false );
		?>
		<h2 class="pc-title"><?php esc_html_e( 'Media', 'prime-cache' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'prime_cache_settings_group' ); ?>
			<?php $this->hidden( $settings, $vis ); ?>

			<!-- Lazy Load -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Lazy Load', 'prime-cache' ); ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[lazyload_images]" value="1" <?php checked( $settings['lazyload_images'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Lazy Load Images', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Add loading="lazy" attribute to images below the fold. The first 2 images are skipped to preserve above-the-fold LCP performance. Images with fetchpriority="high" are never lazy loaded.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[lazyload_iframes]" value="1" <?php checked( $settings['lazyload_iframes'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Lazy Load Iframes', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Add loading="lazy" to iframes (YouTube embeds, Google Maps, etc.). Significantly reduces initial page weight for embed-heavy pages.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[lazyload_videos]" value="1" <?php checked( $settings['lazyload_videos'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Lazy Load Videos', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Set preload="none" on video elements to prevent auto-downloading video files until playback is requested.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[lazyload_disable_native]" value="1" <?php checked( $settings['lazyload_disable_native'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Disable WordPress Native Lazy Load', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Disable the built-in lazy loading added by WordPress 5.5+. Use this if you want Prime Cache to have full control over which images are lazy loaded, or to prevent double lazy-load attributes. When disabled, WordPress will not automatically add loading="lazy" to images and iframes.', 'prime-cache' ); ?></small></span></label>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Excluded Patterns', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[lazyload_exclude]" rows="2" class="pc-ta" placeholder="logo&#10;hero-image"><?php echo esc_textarea( $settings['lazyload_exclude'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'One pattern per line. Images/iframes containing these strings will not be lazy loaded.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<!-- Image Optimization -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Image Optimization', 'prime-cache' ); ?></span>
				<?php if ( ! prime_cache_is_pro() ) : ?><div class="pc-pro-wrap"><span class="pc-pro-badge">PRO</span><?php endif; ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[youtube_thumbnail]" value="1" <?php checked( $settings['youtube_thumbnail'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Replace YouTube Iframes with Thumbnails', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Replace YouTube embed iframes with a lightweight thumbnail image and play button. The actual video player loads only when clicked, saving 500KB-1MB per embed on initial page load.', 'prime-cache' ); ?></small></span></label>
				<?php if ( ! prime_cache_is_pro() ) : ?></div><?php endif; ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[add_missing_dimensions]" value="1" <?php checked( $settings['add_missing_dimensions'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Add Missing Image Dimensions', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Automatically add width and height attributes to images that are missing them. Prevents Cumulative Layout Shift (CLS) — a Core Web Vitals metric. Only works for locally hosted images.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[img_strip_exif]" value="1" <?php checked( $settings['img_strip_exif'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Strip EXIF Data', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Remove EXIF metadata (camera model, GPS coordinates, timestamps) from JPEG images on upload. Only JPEG images are processed. Images are re-encoded at quality 92, which may slightly change file size. Improves privacy by stripping location data.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[img_resize]" value="1" <?php checked( $settings['img_resize'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Resize Oversized Images', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Automatically resize JPEG and PNG images that exceed the maximum dimensions on upload. WebP, GIF, and AVIF images are not affected. Prevents unnecessarily large images from being stored and served.', 'prime-cache' ); ?></small></span></label>
				<div class="pc-field" style="display:flex;gap:16px;flex-wrap:wrap">
					<div>
						<label class="pc-lbl"><?php esc_html_e( 'Max Width (px)', 'prime-cache' ); ?></label>
						<input type="number" name="prime_cache_settings[img_max_width]" value="<?php echo esc_attr( $settings['img_max_width'] ); ?>" min="0" class="pc-inp" style="width:100px">
					</div>
					<div>
						<label class="pc-lbl"><?php esc_html_e( 'Max Height (px)', 'prime-cache' ); ?></label>
						<input type="number" name="prime_cache_settings[img_max_height]" value="<?php echo esc_attr( $settings['img_max_height'] ); ?>" min="0" class="pc-inp" style="width:100px">
					</div>
				</div>
			</div>

			<!-- Format Conversion -->
			<div class="pc-card<?php echo prime_cache_is_pro() ? '' : ' pc-card--pro'; ?>">
				<span class="pc-card__h"><?php esc_html_e( 'Format Conversion', 'prime-cache' ); ?><?php if ( ! prime_cache_is_pro() ) : ?> <span class="pc-pro-badge">PRO</span><?php endif; ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[img_conversion_enabled]" value="1" <?php checked( $settings['img_conversion_enabled'] ); ?> id="pc-fc-toggle"><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Enable Format Conversion', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Master switch for all image format conversion features (WebP, AVIF, auto-convert, delivery, bulk optimization). Disable to turn off all conversion at once without losing individual settings.', 'prime-cache' ); ?></small></span></label>
				<div id="pc-fc-options" style="<?php echo $settings['img_conversion_enabled'] ? '' : 'opacity:0.45;pointer-events:none;'; ?>">
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[webp_enabled]" value="1" <?php checked( $settings['webp_enabled'] ); ?> <?php echo ( $caps['gd_webp'] || $caps['imagick_webp'] ) ? '' : 'disabled'; ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b>WebP</b> <small><?php esc_html_e( 'Convert JPG/PNG to WebP. Reduces file size by 25-35%.', 'prime-cache' ); ?></small></span></label>
				<?php if ( $caps['gd_webp'] || $caps['imagick_webp'] ) : ?><span class="pc-badge pc-badge--g" style="margin-left:52px"><?php echo $caps['gd_webp'] ? 'GD' : 'Imagick'; ?></span>
				<?php else : ?><span class="pc-badge pc-badge--r" style="margin-left:52px"><?php esc_html_e( 'Not available', 'prime-cache' ); ?></span><?php endif; ?>

				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[avif_enabled]" value="1" <?php checked( $settings['avif_enabled'] ); ?> <?php echo ( $caps['gd_avif'] || $caps['imagick_avif'] ) ? '' : 'disabled'; ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b>AVIF</b> <small><?php esc_html_e( 'Convert JPG/PNG to AVIF. Better compression than WebP (40-50% smaller) but slower to encode. Requires PHP 8.1+ with GD or Imagick AVIF support.', 'prime-cache' ); ?></small></span></label>
				<?php if ( $caps['gd_avif'] || $caps['imagick_avif'] ) : ?><span class="pc-badge pc-badge--g" style="margin-left:52px"><?php echo $caps['gd_avif'] ? 'GD' : 'Imagick'; ?></span>
				<?php else : ?><span class="pc-badge pc-badge--r" style="margin-left:52px"><?php esc_html_e( 'Not available', 'prime-cache' ); ?></span><?php endif; ?>

				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[img_auto_optimize]" value="1" <?php checked( $settings['img_auto_optimize'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Auto-convert on Upload', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Automatically convert new images to the enabled formats (WebP/AVIF) when uploaded. All thumbnail sizes are also converted.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[img_auto_remove_larger]" value="1" <?php checked( $settings['img_auto_remove_larger'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Remove if Larger', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Automatically delete the converted file if it is larger than the original. This can happen with small icons or already-optimized images.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[img_exclude_png]" value="1" <?php checked( $settings['img_exclude_png'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Exclude PNG', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Do not convert PNG images. Useful if your PNGs contain transparency that may not be preserved correctly.', 'prime-cache' ); ?></small></span></label>

				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Quality Mode', 'prime-cache' ); ?></label>
					<select name="prime_cache_settings[img_quality_mode]" class="pc-sel" style="width:180px">
						<option value="lossy" <?php selected( $settings['img_quality_mode'], 'lossy' ); ?>><?php esc_html_e( 'Lossy (recommended)', 'prime-cache' ); ?></option>
						<option value="lossless" <?php selected( $settings['img_quality_mode'], 'lossless' ); ?>><?php esc_html_e( 'Lossless (quality 100)', 'prime-cache' ); ?></option>
						<option value="custom" <?php selected( $settings['img_quality_mode'], 'custom' ); ?>><?php esc_html_e( 'Custom', 'prime-cache' ); ?></option>
					</select>
					<p class="pc-help"><?php esc_html_e( 'Lossless mode sets quality to 100 (maximum). For WebP, this produces true lossless output with Imagick. For AVIF, this produces near-lossless output as true lossless depends on encoder support.', 'prime-cache' ); ?></p>
				</div>
				<div class="pc-field" style="display:flex;gap:16px;flex-wrap:wrap">
					<div>
						<label class="pc-lbl"><?php esc_html_e( 'WebP Quality', 'prime-cache' ); ?> (1-100)</label>
						<input type="number" name="prime_cache_settings[webp_quality]" value="<?php echo esc_attr( $settings['webp_quality'] ); ?>" min="1" max="100" class="pc-inp" style="width:80px">
					</div>
					<div>
						<label class="pc-lbl"><?php esc_html_e( 'AVIF Quality', 'prime-cache' ); ?> (1-100)</label>
						<input type="number" name="prime_cache_settings[avif_quality]" value="<?php echo esc_attr( $settings['avif_quality'] ); ?>" min="1" max="100" class="pc-inp" style="width:80px">
					</div>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Converter Engine', 'prime-cache' ); ?></label>
					<select name="prime_cache_settings[img_converter]" class="pc-sel" style="width:180px">
						<option value="auto" <?php selected( $settings['img_converter'], 'auto' ); ?>><?php esc_html_e( 'Auto (Imagick > GD)', 'prime-cache' ); ?></option>
						<option value="gd" <?php selected( $settings['img_converter'], 'gd' ); ?>>GD</option>
						<option value="imagick" <?php selected( $settings['img_converter'], 'imagick' ); ?>>Imagick</option>
					</select>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Delivery Method', 'prime-cache' ); ?></label>
					<select name="prime_cache_settings[img_delivery_method]" class="pc-sel" style="width:240px">
						<option value="rewrite" <?php selected( $settings['img_delivery_method'], 'rewrite' ); ?>><?php esc_html_e( '.htaccess Rewrite (recommended)', 'prime-cache' ); ?></option>
						<option value="picture" <?php selected( $settings['img_delivery_method'], 'picture' ); ?>><?php esc_html_e( '<picture> Tag', 'prime-cache' ); ?></option>
						<option value="url" <?php selected( $settings['img_delivery_method'], 'url' ); ?>><?php esc_html_e( 'URL Rewrite (PHP)', 'prime-cache' ); ?></option>
					</select>
					<p class="pc-help"><?php esc_html_e( '.htaccess: Apache serves the correct format via Accept header (fastest). <picture>: Wraps images in HTML picture elements with source fallback. URL Rewrite: Swaps src/srcset URLs in PHP output buffer.', 'prime-cache' ); ?></p>
				</div>
				<div class="pc-field" style="border-top:1px solid var(--c-subtle);padding-top:14px;margin-top:6px">
					<label class="pc-lbl"><?php esc_html_e( 'Target Folders', 'prime-cache' ); ?></label>
					<p class="pc-help" style="margin:0 0 10px"><?php esc_html_e( 'Choose which folders to include for image conversion. Uploads is enabled by default. Enable themes/plugins to convert images bundled with them.', 'prime-cache' ); ?></p>
					<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[img_include_uploads]" value="1" <?php checked( $settings['img_include_uploads'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Uploads', 'prime-cache' ); ?></b><small><?php echo esc_html( wp_upload_dir()['basedir'] ); ?></small></span></label>
					<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[img_include_themes]" value="1" <?php checked( $settings['img_include_themes'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Themes', 'prime-cache' ); ?></b><small><?php echo esc_html( get_theme_root() ); ?></small></span></label>
					<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[img_include_plugins]" value="1" <?php checked( $settings['img_include_plugins'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Plugins', 'prime-cache' ); ?></b><small><?php echo esc_html( WP_PLUGIN_DIR ); ?></small></span></label>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Custom Folders', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[img_include_custom]" rows="2" class="pc-ta" placeholder="/var/www/html/wp-content/custom-images/"><?php echo esc_textarea( $settings['img_include_custom'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'Additional absolute paths to include (one per line). Use for custom directories outside the standard WordPress structure.', 'prime-cache' ); ?></p>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Exclude Folders', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[img_exclude_folders]" rows="2" class="pc-ta" placeholder="uploads/avatars&#10;uploads/logos"><?php echo esc_textarea( $settings['img_exclude_folders'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'One folder path per line. Images in these folders will not be converted, even if they are inside an included folder above.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<!-- Bulk Optimization -->
			<div class="pc-card<?php echo prime_cache_is_pro() ? '' : ' pc-card--pro'; ?>">
				<span class="pc-card__h"><?php esc_html_e( 'Bulk Optimization', 'prime-cache' ); ?><?php if ( ! prime_cache_is_pro() ) : ?> <span class="pc-pro-badge">PRO</span><?php endif; ?></span>
				<p class="pc-help" style="margin:0 0 12px"><?php esc_html_e( 'Scan your media library for images that have not yet been converted to WebP/AVIF and process them in batches. Statistics are based on a sample of up to 1,000 images.', 'prime-cache' ); ?></p>
				<div id="pc-bulk-area">
					<button type="button" class="pc-btn pc-btn--p pc-btn--sm" id="pc-bulk-scan"><?php esc_html_e( 'Scan Unconverted Images', 'prime-cache' ); ?></button>
					<div id="pc-bulk-status" style="margin-top:12px;display:none">
						<div class="pc-bar" style="margin-bottom:8px"><div class="pc-bar__fill" id="pc-bulk-bar" style="width:0%"></div></div>
						<p class="pc-meta" id="pc-bulk-text"></p>
					</div>
				</div>
			</div>

			</div><!-- /#pc-fc-options -->

			<div class="pc-actions"><?php submit_button( __( 'Save Settings', 'prime-cache' ), 'primary large', 'submit', false ); ?></div>
		</form>

		<script>
		(function(){
			var fcToggle=document.getElementById('pc-fc-toggle'),fcOpts=document.getElementById('pc-fc-options');
			if(fcToggle&&fcOpts){fcToggle.addEventListener('change',function(){fcOpts.style.opacity=this.checked?'':'0.45';fcOpts.style.pointerEvents=this.checked?'':'none';});}
		})();
		</script>

		<script>
		(function(){
			var nonce=<?php echo wp_json_encode( wp_create_nonce( 'pc_img_nonce' ), JSON_HEX_TAG ); ?>;
			var scanBtn=document.getElementById('pc-bulk-scan'),status=document.getElementById('pc-bulk-status'),bar=document.getElementById('pc-bulk-bar'),txt=document.getElementById('pc-bulk-text');
			if(!scanBtn)return;

			scanBtn.addEventListener('click',function(){
				scanBtn.disabled=true;scanBtn.textContent=<?php echo wp_json_encode(__('Scanning...','prime-cache'), JSON_HEX_TAG); ?>;
				fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=pc_img_scan&nonce='+nonce})
				.then(function(r){return r.json();})
				.then(function(d){
					if(!d.success||!d.data.total){scanBtn.textContent=<?php echo wp_json_encode(__('No images to convert','prime-cache'), JSON_HEX_TAG); ?>;scanBtn.disabled=false;return;}
					var items=d.data.items,total=items.length,done=0,saved=0,batch=5;
					status.style.display='';txt.textContent='0 / '+total;
					scanBtn.textContent=<?php echo wp_json_encode(__('Processing...','prime-cache'), JSON_HEX_TAG); ?>;

					function next(){
						if(done>=total){
							bar.style.width='100%';
							txt.textContent=<?php echo wp_json_encode(__('Done!','prime-cache'), JSON_HEX_TAG); ?>+' '+total+' '+<?php echo wp_json_encode(__('images processed.','prime-cache'), JSON_HEX_TAG); ?>;
							scanBtn.textContent=<?php echo wp_json_encode(__('Scan Unconverted Images','prime-cache'), JSON_HEX_TAG); ?>;scanBtn.disabled=false;return;
						}
						var chunk=items.slice(done,done+batch);
						var fd=new FormData();fd.append('action','pc_img_batch');fd.append('nonce',nonce);
						chunk.forEach(function(item,i){fd.append('items['+i+'][type]',item.type);fd.append('items['+i+'][value]',item.value);});
						fetch(ajaxurl,{method:'POST',body:fd})
						.then(function(r){return r.json();})
						.then(function(d){
							done+=chunk.length;saved+=(d.data&&d.data.saved)||0;
							bar.style.width=Math.round(done/total*100)+'%';
							txt.textContent=done+' / '+total;
							next();
						});
					}
					next();
				});
			});
		})();
		</script>
		<?php
	}

	/* ── tab: cdn ─────────────────────────────────────────── */

	private function tab_cdn( $settings ) {
		$vis = array( 'cdn_enabled','cdn_hostname','cdn_include_dirs','cdn_exclude','cdn_relative','cloudflare_enabled','cloudflare_email','cloudflare_api_key','cloudflare_auth_mode','cloudflare_zone_id' );
		?>
		<h2 class="pc-title"><?php esc_html_e( 'CDN', 'prime-cache' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'prime_cache_settings_group' ); ?>
			<?php $this->hidden( $settings, $vis ); ?>

			<!-- CDN URL Rewriting -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'CDN URL Rewriting', 'prime-cache' ); ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[cdn_enabled]" value="1" <?php checked( $settings['cdn_enabled'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Enable CDN', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Rewrite URLs for static assets (images, CSS, JS) to be served from your CDN hostname. Works with any pull-zone CDN (BunnyCDN, CloudFront, KeyCDN, StackPath, etc.).', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[cdn_relative]" value="1" <?php checked( $settings['cdn_relative'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Rewrite Relative URLs', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Also rewrite relative URLs (starting with /) in src and href attributes. Disable if your theme uses relative URLs for non-asset links.', 'prime-cache' ); ?></small></span></label>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'CDN Hostname(s)', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[cdn_hostname]" rows="2" class="pc-ta" placeholder="cdn.example.com&#10;cdn2.example.com"><?php echo esc_textarea( $settings['cdn_hostname'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'One hostname per line (without https://). Multiple hostnames enable domain sharding — assets are distributed across them in round-robin.', 'prime-cache' ); ?></p>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Include Directories', 'prime-cache' ); ?></label>
					<input type="text" name="prime_cache_settings[cdn_include_dirs]" value="<?php echo esc_attr( $settings['cdn_include_dirs'] ); ?>" class="pc-ta" style="font-family:inherit" placeholder="wp-content,wp-includes">
					<p class="pc-help"><?php esc_html_e( 'Comma-separated directory names. Only URLs containing these paths will be rewritten. Default: wp-content,wp-includes', 'prime-cache' ); ?></p>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Exclude Patterns', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[cdn_exclude]" rows="2" class="pc-ta" placeholder=".php&#10;dynamic-image"><?php echo esc_textarea( $settings['cdn_exclude'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'One pattern per line. URLs containing these strings will not be rewritten to the CDN.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<!-- Cloudflare -->
			<div class="pc-card">
				<span class="pc-card__h">Cloudflare</span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[cloudflare_enabled]" value="1" <?php checked( $settings['cloudflare_enabled'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Cloudflare Cache Sync', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Automatically purge Cloudflare cache when Prime Cache is cleared. Supports full zone purge and per-URL purge (up to 30 URLs per API call, batched automatically). Small batches are sent immediately; larger batches are deferred to a background task.', 'prime-cache' ); ?></small></span></label>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Zone ID', 'prime-cache' ); ?></label>
					<input type="text" name="prime_cache_settings[cloudflare_zone_id]" value="<?php echo esc_attr( $settings['cloudflare_zone_id'] ); ?>" class="pc-ta" style="font-family:monospace" placeholder="32-character zone ID">
					<p class="pc-help"><?php esc_html_e( 'Find this in Cloudflare dashboard > your domain > Overview (right sidebar).', 'prime-cache' ); ?></p>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Authentication Method', 'prime-cache' ); ?></label>
					<select name="prime_cache_settings[cloudflare_auth_mode]" class="pc-sel" style="width:220px">
						<option value="token" <?php selected( $settings['cloudflare_auth_mode'] ?? 'token', 'token' ); ?>><?php esc_html_e( 'API Token (recommended)', 'prime-cache' ); ?></option>
						<option value="global_key" <?php selected( $settings['cloudflare_auth_mode'] ?? 'token', 'global_key' ); ?>><?php esc_html_e( 'Global API Key + Email', 'prime-cache' ); ?></option>
					</select>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'API Token or Global API Key', 'prime-cache' ); ?></label>
					<input type="text" name="prime_cache_settings[cloudflare_api_key]" value="<?php echo esc_attr( $settings['cloudflare_api_key'] ); ?>" class="pc-ta" style="font-family:monospace" placeholder="<?php echo 'global_key' === ( $settings['cloudflare_auth_mode'] ?? 'token' ) ? 'Global API Key' : 'API Token'; ?>"
						<?php echo defined( 'PRIME_CACHE_CF_API_TOKEN' ) ? 'disabled' : ''; ?>>
					<p class="pc-help"><?php esc_html_e( 'API Token: Create a custom token with "Zone > Cache Purge > Purge" permission. Global API Key: Use with the email address below. Can also be set via PRIME_CACHE_CF_API_TOKEN constant.', 'prime-cache' ); ?></p>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Cloudflare Account Email', 'prime-cache' ); ?></label>
					<input type="email" name="prime_cache_settings[cloudflare_email]" value="<?php echo esc_attr( $settings['cloudflare_email'] ); ?>" class="pc-ta" style="font-family:inherit" placeholder="you@example.com">
					<p class="pc-help"><?php esc_html_e( 'Required only when using Global API Key authentication. Not needed for API Token.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<div class="pc-actions"><?php submit_button( __( 'Save Settings', 'prime-cache' ), 'primary large', 'submit', false ); ?></div>
		</form>
		<?php
	}

	/* ── tab: preload ─────────────────────────────────────── */

	private function tab_preload( $settings ) {
		$vis = array(
			'preload_enabled','preload_homepage','preload_public_posts','preload_public_tax',
			'preload_sitemap_enabled','preload_sitemap','preload_interval','preload_max_posts','preload_max_terms','preload_excluded_uri',
			'preload_links','speculation_rules','preload_fonts','lcp_optimization','lcp_excluded','preload_resources','prefetch_dns','preconnect',
		);
		?>
		<h2 class="pc-title"><?php esc_html_e( 'Preload', 'prime-cache' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'prime_cache_settings_group' ); ?>
			<?php $this->hidden( $settings, $vis ); ?>

			<!-- Cache Preloading -->
			<div class="pc-card<?php echo prime_cache_is_pro() ? '' : ' pc-card--pro'; ?>">
				<span class="pc-card__h"><?php esc_html_e( 'Cache Preloading', 'prime-cache' ); ?><?php if ( ! prime_cache_is_pro() ) : ?> <span class="pc-pro-badge">PRO</span><?php endif; ?></span>
				<?php if ( ! empty( trim( $settings['cache_vary_cookies'] ?? '' ) ) ) : ?>
				<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin:0 0 12px;font-size:13px;color:#92400e">
					<strong><?php esc_html_e( 'Partial preload:', 'prime-cache' ); ?></strong> <?php esc_html_e( 'Vary Cookies are active. Preloading can only warm the default (no-cookie) variant for each URL. Cookie-specific cache files (e.g. for currency, country, A/B tests) cannot be preloaded and will be generated on the first real visitor request with that cookie value. This is a technical limitation — not a bug.', 'prime-cache' ); ?>
				</div>
				<?php endif; ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[preload_enabled]" value="1" <?php checked( $settings['preload_enabled'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Enable Cache Preloading', 'prime-cache' ); ?></b><small><?php
				if ( ! empty( trim( $settings['cache_vary_cookies'] ?? '' ) ) ) {
					esc_html_e( 'Automatically crawl your site to warm the default cache variant (desktop and mobile). Cookie-specific variants cannot be preloaded and are generated on the first real visitor request with that cookie value.', 'prime-cache' );
				} else {
					esc_html_e( 'Automatically crawl your site in the background to warm the page cache. Pages are preloaded via non-blocking HTTP requests so visitors always receive cached pages. Preloading restarts after a full cache purge.', 'prime-cache' );
				}
			?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[preload_homepage]" value="1" <?php checked( $settings['preload_homepage'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Preload Homepage', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Include the front page, posts page, and home URL in the preload queue.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[preload_public_posts]" value="1" <?php checked( $settings['preload_public_posts'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Preload Public Posts', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Preload published posts, pages, and custom post types (most recently modified first).', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[preload_public_tax]" value="1" <?php checked( $settings['preload_public_tax'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Preload Taxonomies', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Preload category, tag, and custom taxonomy archive pages.', 'prime-cache' ); ?></small></span></label>

				<div class="pc-field" style="display:flex;gap:16px;flex-wrap:wrap">
					<div>
						<label class="pc-lbl"><?php esc_html_e( 'Max Posts', 'prime-cache' ); ?></label>
						<input type="number" name="prime_cache_settings[preload_max_posts]" value="<?php echo esc_attr( $settings['preload_max_posts'] ); ?>" min="50" max="5000" class="pc-inp" style="width:100px">
					</div>
					<div>
						<label class="pc-lbl"><?php esc_html_e( 'Max Terms', 'prime-cache' ); ?></label>
						<input type="number" name="prime_cache_settings[preload_max_terms]" value="<?php echo esc_attr( $settings['preload_max_terms'] ); ?>" min="50" max="2000" class="pc-inp" style="width:100px">
					</div>
				</div>
				<p class="pc-help"><?php esc_html_e( 'Maximum number of posts and taxonomy terms to include in each preload cycle. Higher values warm more pages but take longer to complete.', 'prime-cache' ); ?></p>

				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Preloader Request Interval (seconds)', 'prime-cache' ); ?></label>
					<input type="number" name="prime_cache_settings[preload_interval]" value="<?php echo esc_attr( $settings['preload_interval'] ); ?>" min="1" max="60" class="pc-inp" style="width:100px">
					<p class="pc-help"><?php esc_html_e( 'Delay between each preload request. Higher values reduce server load but take longer to complete. Preloading automatically pauses if server load is too high (weighted average > 16.0 or load spike detected).', 'prime-cache' ); ?></p>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Preload Excluded URLs', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[preload_excluded_uri]" rows="3" class="pc-ta" placeholder="/sample-page/&#10;/private-area/(.*)"><?php echo esc_textarea( $settings['preload_excluded_uri'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'URL path patterns to skip during preloading (one per line). Supports simple patterns with pipe (|) for alternatives. These pages will not be crawled by the preloader.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<!-- Sitemap Preloading -->
			<div class="pc-card<?php echo prime_cache_is_pro() ? '' : ' pc-card--pro'; ?>">
				<span class="pc-card__h"><?php esc_html_e( 'Sitemap Preloading', 'prime-cache' ); ?><?php if ( ! prime_cache_is_pro() ) : ?> <span class="pc-pro-badge">PRO</span><?php endif; ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[preload_sitemap_enabled]" value="1" <?php checked( $settings['preload_sitemap_enabled'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Enable Sitemap Preloading', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Discover URLs from your XML sitemap instead of database queries. When enabled, the sitemap is the primary source for the preload URL list. The homepage, posts, and taxonomy toggles above serve as fallback if the sitemap is unreachable.', 'prime-cache' ); ?></small></span></label>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Sitemap URL', 'prime-cache' ); ?></label>
					<input type="url" name="prime_cache_settings[preload_sitemap]" value="<?php echo esc_attr( $settings['preload_sitemap'] ); ?>" class="pc-ta" style="font-family:inherit" placeholder="<?php echo esc_attr( home_url( '/wp-sitemap.xml' ) ); ?>">
					<p class="pc-help"><?php esc_html_e( 'Full URL to your XML sitemap. Supports sitemap index files — child sitemaps are parsed recursively (up to 1,000 URLs). WordPress generates a built-in sitemap at /wp-sitemap.xml.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<!-- Link Prefetching -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Preload Links', 'prime-cache' ); ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[preload_links]" value="1" <?php checked( $settings['preload_links'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Enable Link Prefetching', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Inject a lightweight JavaScript that prefetches internal links when the user hovers over them or when they enter the viewport. The browser downloads the page in advance so the next click feels instant. Rate-limited to 3 links per second. Excluded: admin, login, cart, checkout, and external links.', 'prime-cache' ); ?></small></span></label>
			</div>

			<!-- Speculation Rules -->
			<div class="pc-card<?php echo prime_cache_is_pro() ? '' : ' pc-card--pro'; ?>">
				<span class="pc-card__h"><?php esc_html_e( 'Speculation Rules', 'prime-cache' ); ?><?php if ( ! prime_cache_is_pro() ) : ?> <span class="pc-pro-badge">PRO</span><?php endif; ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[speculation_rules]" value="1" <?php checked( $settings['speculation_rules'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Enable Speculation Rules API', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Use the Speculation Rules API (Chrome 109+) to prerender pages when the user is likely to navigate to them. Much faster than prefetch — the entire page is rendered in the background, making navigation virtually instant. Admin, login, cart, checkout, and account pages are automatically excluded. Browsers without support simply ignore the rules.', 'prime-cache' ); ?></small></span></label>
			</div>

			<!-- Font Preloading -->
			<div class="pc-card<?php echo prime_cache_is_pro() ? '' : ' pc-card--pro'; ?>">
				<span class="pc-card__h"><?php esc_html_e( 'Preload Fonts', 'prime-cache' ); ?><?php if ( ! prime_cache_is_pro() ) : ?> <span class="pc-pro-badge">PRO</span><?php endif; ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[preload_fonts]" value="1" <?php checked( $settings['preload_fonts'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Auto-detect & Preload Fonts', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Scan theme and plugin stylesheets for @font-face declarations and inject <link rel="preload"> hints in the head. Prioritizes woff2 format. Preloading fonts reduces layout shifts and improves Largest Contentful Paint (LCP) for text-heavy pages. Results are cached for 24 hours.', 'prime-cache' ); ?></small></span></label>
			</div>

			<!-- LCP Optimization -->
			<div class="pc-card<?php echo prime_cache_is_pro() ? '' : ' pc-card--pro'; ?>">
				<span class="pc-card__h"><?php esc_html_e( 'LCP Optimization', 'prime-cache' ); ?><?php if ( ! prime_cache_is_pro() ) : ?> <span class="pc-pro-badge">PRO</span><?php endif; ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[lcp_optimization]" value="1" <?php checked( $settings['lcp_optimization'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Optimize Largest Contentful Paint', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Automatically detect the most likely LCP image (first large above-the-fold image) and apply optimizations: add fetchpriority="high" attribute, inject <link rel="preload"> with imagesrcset support, and remove loading="lazy" from the LCP element. Improves Core Web Vitals LCP score.', 'prime-cache' ); ?></small></span></label>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'LCP Excluded Patterns', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[lcp_excluded]" rows="2" class="pc-ta" placeholder="logo.png&#10;icon-"><?php echo esc_textarea( $settings['lcp_excluded'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'Image URL patterns to exclude from LCP detection (one per line). Use for logos, icons, or decorative images that should not be treated as the LCP element.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<!-- Preload Resources -->
			<div class="pc-card<?php echo prime_cache_is_pro() ? '' : ' pc-card--pro'; ?>">
				<span class="pc-card__h"><?php esc_html_e( 'Preload Critical Resources', 'prime-cache' ); ?><?php if ( ! prime_cache_is_pro() ) : ?> <span class="pc-pro-badge">PRO</span><?php endif; ?></span>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Preload URLs', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[preload_resources]" rows="3" class="pc-ta" placeholder="https://example.com/wp-content/themes/theme/fonts/custom.woff2&#10;/wp-content/themes/theme/css/critical.css"><?php echo esc_textarea( $settings['preload_resources'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'One URL per line. These resources will be injected as <link rel="preload"> in the HTML head. The browser downloads them with high priority before they are discovered in the page. The resource type (font, style, script, image) is auto-detected from the file extension. Use for critical fonts, above-the-fold CSS, or hero images.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<!-- Prefetch DNS -->
			<div class="pc-card<?php echo prime_cache_is_pro() ? '' : ' pc-card--pro'; ?>">
				<span class="pc-card__h"><?php esc_html_e( 'Prefetch DNS', 'prime-cache' ); ?><?php if ( ! prime_cache_is_pro() ) : ?> <span class="pc-pro-badge">PRO</span><?php endif; ?></span>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'DNS Prefetch Domains', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[prefetch_dns]" rows="3" class="pc-ta" placeholder="fonts.googleapis.com&#10;cdn.example.com&#10;www.google-analytics.com"><?php echo esc_textarea( $settings['prefetch_dns'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'One domain per line (without https://). The browser resolves DNS for these domains early in the page load, reducing latency when external resources are later requested. Lightweight and safe to use for any external domain.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<!-- Preconnect -->
			<div class="pc-card<?php echo prime_cache_is_pro() ? '' : ' pc-card--pro'; ?>">
				<span class="pc-card__h"><?php esc_html_e( 'Preconnect', 'prime-cache' ); ?><?php if ( ! prime_cache_is_pro() ) : ?> <span class="pc-pro-badge">PRO</span><?php endif; ?></span>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Preconnect Origins', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[preconnect]" rows="3" class="pc-ta" placeholder="https://fonts.gstatic.com&#10;https://cdn.example.com"><?php echo esc_textarea( $settings['preconnect'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'One origin per line (include https://). The browser performs DNS lookup, TCP connection, and TLS handshake in advance for these origins. Use for critical third-party resources like font providers, CDNs, or API endpoints. More aggressive than DNS Prefetch — use sparingly for origins you will definitely connect to.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<div class="pc-actions"><?php submit_button( __( 'Save Settings', 'prime-cache' ), 'primary large', 'submit', false ); ?></div>
		</form>
		<?php
	}

	/* ── tab: cache control ───────────────────────────────── */

	private function tab_control( $settings ) {
		$vis = array( 'cache_ignore_qs','cache_query_strings','cache_vary_cookies','purge_additional_urls' );
		?>
		<h2 class="pc-title"><?php esc_html_e( 'Cache Control','prime-cache' ); ?></h2>
		<form method="post" action="options.php"><?php settings_fields('prime_cache_settings_group'); $this->hidden($settings,$vis); ?>
			<div class="pc-card"><span class="pc-card__h"><?php esc_html_e('Query Parameters','prime-cache'); ?></span>
				<div class="pc-field"><label class="pc-lbl"><?php esc_html_e('Ignored Query Parameters','prime-cache'); ?></label><textarea name="prime_cache_settings[cache_ignore_qs]" rows="3" class="pc-ta"><?php echo esc_textarea($settings['cache_ignore_qs']); ?></textarea><p class="pc-help"><?php esc_html_e('Comma-separated parameter names. These parameters are stripped and the same cache is served as if they were absent. Register ad-tracking parameters (utm_source, fbclid, gclid, etc.) to prevent unnecessary cache duplication.','prime-cache'); ?></p></div>
				<div class="pc-field"><label class="pc-lbl"><?php esc_html_e('Cached Query Parameters','prime-cache'); ?></label><textarea name="prime_cache_settings[cache_query_strings]" rows="3" class="pc-ta" placeholder="lang, currency, color"><?php echo esc_textarea($settings['cache_query_strings']); ?></textarea><p class="pc-help"><?php echo wp_kses(__('Comma-separated parameter names. Each unique value generates a separate cache file. For example, specifying <code>lang</code> creates separate caches for <code>?lang=en</code> and <code>?lang=ja</code>. Use for multilingual plugins or currency switchers. URLs with parameters not in either list are not cached. <strong>Note:</strong> .htaccess fast-path does not serve query string variants — they are served via the drop-in (still very fast, but not zero-PHP).','prime-cache'),array('code'=>array(),'strong'=>array())); ?></p></div>
			</div>
			<div class="pc-card"><span class="pc-card__h"><?php esc_html_e('Cookie-based Cache Splitting','prime-cache'); ?></span>
				<div class="pc-field"><label class="pc-lbl"><?php esc_html_e('Vary Cookie Names','prime-cache'); ?></label><textarea name="prime_cache_settings[cache_vary_cookies]" rows="3" class="pc-ta" placeholder="currency, country"><?php echo esc_textarea($settings['cache_vary_cookies']); ?></textarea><p class="pc-help"><?php echo wp_kses(__('Comma-separated cookie names. Different cache files are generated based on each cookie\'s value, even for the same URL. For example, specifying the <code>currency</code> cookie serves separate caches to users with <code>currency=USD</code> vs <code>currency=JPY</code>. Use for e-commerce currency/country display or A/B testing. <strong>Note:</strong> When active, .htaccess fast-path is automatically disabled — all requests are served via the drop-in to ensure correct variant selection.','prime-cache'),array('code'=>array(),'strong'=>array())); ?></p></div>
			</div>
			<div class="pc-card"><span class="pc-card__h"><?php esc_html_e('Purge Settings','prime-cache'); ?></span>
				<div class="pc-field"><label class="pc-lbl"><?php esc_html_e('Always Purge URLs','prime-cache'); ?></label><textarea name="prime_cache_settings[purge_additional_urls]" rows="4" class="pc-ta" placeholder="https://example.com/custom-page/"><?php echo esc_textarea($settings['purge_additional_urls']); ?></textarea><p class="pc-help"><?php esc_html_e('One URL per line. Whenever any post is updated, caches for these URLs are also cleared in addition to the standard related pages (home, categories, tags, author, date archives). Use for sitemaps, custom landing pages, or shortcode-based post listing pages that are not auto-detected.','prime-cache'); ?></p></div>
			</div>
			<div class="pc-actions"><?php submit_button(__('Save Settings','prime-cache'),'primary large','submit',false); ?></div>
		</form>
		<?php
	}

	/* ── tab: heartbeat ───────────────────────────────────── */

	private function tab_heartbeat( $settings ) {
		$vis = array(
			'heartbeat_enabled','heartbeat_frontend','heartbeat_admin','heartbeat_editor',
			'heartbeat_admin_interval','heartbeat_frontend_interval',
		);
		$behaviors = array(
			'enable'  => __( 'Allow', 'prime-cache' ),
			'modify'  => __( 'Reduce Frequency', 'prime-cache' ),
			'disable' => __( 'Disable', 'prime-cache' ),
		);
		?>
		<h2 class="pc-title"><?php esc_html_e( 'Heartbeat', 'prime-cache' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'prime_cache_settings_group' ); ?>
			<?php $this->hidden( $settings, $vis ); ?>

			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Heartbeat API Control', 'prime-cache' ); ?></span>
				<label class="pc-sw" style="margin-bottom:16px">
					<input type="checkbox" name="prime_cache_settings[heartbeat_enabled]" value="1" <?php checked( $settings['heartbeat_enabled'] ); ?> id="pc-hb-toggle">
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php esc_html_e( 'Control Heartbeat API', 'prime-cache' ); ?></b>
						<small><?php esc_html_e( 'The WordPress Heartbeat API sends periodic AJAX requests (wp-admin/admin-ajax.php) to keep sessions alive, auto-save drafts, and show real-time notifications. Reducing or disabling it lowers server load and CPU usage, especially on shared hosting.', 'prime-cache' ); ?></small>
					</span>
				</label>

				<div id="pc-hb-options" style="<?php echo $settings['heartbeat_enabled'] ? '' : 'opacity:.4;pointer-events:none;'; ?>">

					<!-- Frontend -->
					<div class="pc-hb-location">
						<div class="pc-hb-location__head">
							<span class="dashicons dashicons-admin-site-alt3" style="color:var(--c-pri)"></span>
							<b><?php esc_html_e( 'Frontend', 'prime-cache' ); ?></b>
						</div>
						<p class="pc-help" style="margin:0 0 10px"><?php esc_html_e( 'Heartbeat on public-facing pages. Usually unnecessary — disabling is recommended for most sites.', 'prime-cache' ); ?></p>
						<div class="pc-hb-controls">
							<select name="prime_cache_settings[heartbeat_frontend]" class="pc-sel" id="pc-hb-fe-sel">
								<?php foreach ( $behaviors as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['heartbeat_frontend'], $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<div class="pc-hb-interval" id="pc-hb-fe-int" style="<?php echo 'modify' === $settings['heartbeat_frontend'] ? '' : 'display:none;'; ?>">
								<label class="pc-meta"><?php esc_html_e( 'Interval:', 'prime-cache' ); ?></label>
								<input type="number" name="prime_cache_settings[heartbeat_frontend_interval]" value="<?php echo esc_attr( $settings['heartbeat_frontend_interval'] ); ?>" min="15" max="300" class="pc-inp" style="width:80px">
								<span class="pc-meta"><?php esc_html_e( 'sec', 'prime-cache' ); ?></span>
							</div>
						</div>
					</div>

					<!-- Admin -->
					<div class="pc-hb-location">
						<div class="pc-hb-location__head">
							<span class="dashicons dashicons-dashboard" style="color:var(--c-pri)"></span>
							<b><?php esc_html_e( 'Admin Dashboard', 'prime-cache' ); ?></b>
						</div>
						<p class="pc-help" style="margin:0 0 10px"><?php esc_html_e( 'Heartbeat on wp-admin pages (excluding the post editor). Reducing to 120 seconds is a good balance between functionality and server load.', 'prime-cache' ); ?></p>
						<div class="pc-hb-controls">
							<select name="prime_cache_settings[heartbeat_admin]" class="pc-sel" id="pc-hb-ad-sel">
								<?php foreach ( $behaviors as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['heartbeat_admin'], $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<div class="pc-hb-interval" id="pc-hb-ad-int" style="<?php echo 'modify' === $settings['heartbeat_admin'] ? '' : 'display:none;'; ?>">
								<label class="pc-meta"><?php esc_html_e( 'Interval:', 'prime-cache' ); ?></label>
								<input type="number" name="prime_cache_settings[heartbeat_admin_interval]" value="<?php echo esc_attr( $settings['heartbeat_admin_interval'] ); ?>" min="15" max="300" class="pc-inp" style="width:80px">
								<span class="pc-meta"><?php esc_html_e( 'sec', 'prime-cache' ); ?></span>
							</div>
						</div>
					</div>

					<!-- Editor -->
					<div class="pc-hb-location">
						<div class="pc-hb-location__head">
							<span class="dashicons dashicons-edit" style="color:var(--c-pri)"></span>
							<b><?php esc_html_e( 'Post Editor', 'prime-cache' ); ?></b>
						</div>
						<p class="pc-help" style="margin:0 0 10px"><?php esc_html_e( 'Heartbeat on the post/page editor. Required for auto-save, post locking, and real-time collaboration. Disabling may cause data loss — "Allow" is strongly recommended.', 'prime-cache' ); ?></p>
						<div class="pc-hb-controls">
							<select name="prime_cache_settings[heartbeat_editor]" class="pc-sel">
								<?php foreach ( $behaviors as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $settings['heartbeat_editor'], $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>

				</div>
			</div>

			<div class="pc-actions"><?php submit_button( __( 'Save Settings', 'prime-cache' ), 'primary large', 'submit', false ); ?></div>
		</form>

		<script>
		(function(){
			var toggle=document.getElementById('pc-hb-toggle'),opts=document.getElementById('pc-hb-options');
			if(toggle&&opts) toggle.addEventListener('change',function(){opts.style.opacity=toggle.checked?'1':'.4';opts.style.pointerEvents=toggle.checked?'':'none';});

			function bindInterval(selId,intId){
				var sel=document.getElementById(selId),box=document.getElementById(intId);
				if(!sel||!box)return;
				sel.addEventListener('change',function(){box.style.display=sel.value==='modify'?'':'none';});
			}
			bindInterval('pc-hb-fe-sel','pc-hb-fe-int');
			bindInterval('pc-hb-ad-sel','pc-hb-ad-int');
		})();
		</script>
		<?php
	}

	/* ── tab: database ────────────────────────────────────── */

	private function tab_database( $settings ) {
		$db_keys = array(
			'db_revisions','db_auto_drafts','db_trashed_posts',
			'db_spam_comments','db_trashed_comments',
			'db_expired_transients','db_all_transients','db_optimize_tables',
			'db_auto_cleanup','db_cleanup_frequency',
		);

		if ( ! class_exists( 'Prime_Cache_Database_Optimizer' ) ) {
			echo '<h2 class="pc-title">' . esc_html__( 'Database', 'prime-cache' ) . '</h2>';
			echo '<div class="pc-card" style="opacity:0.5"><p>' . esc_html__( 'Database optimization requires Prime Cache Pro.', 'prime-cache' ) . '</p></div>';
			return;
		}
		$optimizer = new Prime_Cache_Database_Optimizer();
		$counts    = $optimizer->get_counts();
		$cleanup_url = wp_nonce_url( admin_url( 'admin.php?prime_cache_db_cleanup=1' ), 'prime_cache_db_cleanup' );
		?>
		<h2 class="pc-title"><?php esc_html_e( 'Database', 'prime-cache' ); ?></h2>

		<?php if ( isset( $_GET['prime_cache_db_cleaned'] ) ) : ?>
			<div class="notice notice-success is-dismissible" style="margin:0 0 16px">
				<p><?php printf( esc_html__( 'Database cleanup processed %s items (max 1,000 per task). Run again if more remain.', 'prime-cache' ), '<b>' . esc_html( (int) $_GET['prime_cache_db_cleaned'] ) . '</b>' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'prime_cache_settings_group' ); ?>
			<?php $this->hidden( $settings, $db_keys ); ?>

			<!-- Post Cleanup -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Post Cleanup', 'prime-cache' ); ?></span>
				<?php
				$post_items = array(
					array( 'db_revisions',     __( 'Revisions', 'prime-cache' ),     __( 'Delete all post revisions. Revisions are saved every time you update a post and can accumulate over time.', 'prime-cache' ), $counts['revisions'] ),
					array( 'db_auto_drafts',   __( 'Auto Drafts', 'prime-cache' ),   __( 'Delete auto-draft posts created automatically by WordPress when you start writing a new post. These are never published.', 'prime-cache' ), $counts['auto_drafts'] ),
					array( 'db_trashed_posts', __( 'Trashed Posts', 'prime-cache' ),  __( 'Permanently delete posts that are in the trash. These are already removed from the site but still occupy database space.', 'prime-cache' ), $counts['trashed_posts'] ),
				);
				foreach ( $post_items as $item ) :
				?>
				<label class="pc-sw">
					<input type="checkbox" name="prime_cache_settings[<?php echo esc_attr( $item[0] ); ?>]" value="1" <?php checked( $settings[ $item[0] ] ); ?>>
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php echo esc_html( $item[1] ); ?> <span class="pc-badge pc-badge--m"><?php echo esc_html( number_format( $item[3] ) ); ?></span></b>
						<small><?php echo esc_html( $item[2] ); ?></small>
					</span>
				</label>
				<?php endforeach; ?>
			</div>

			<!-- Comments Cleanup -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Comments Cleanup', 'prime-cache' ); ?></span>
				<?php
				$comment_items = array(
					array( 'db_spam_comments',    __( 'Spam Comments', 'prime-cache' ),    __( 'Permanently delete all comments marked as spam. These comments have already been filtered out but remain in the database.', 'prime-cache' ), $counts['spam_comments'] ),
					array( 'db_trashed_comments', __( 'Trashed Comments', 'prime-cache' ), __( 'Permanently delete comments that are in the trash.', 'prime-cache' ), $counts['trashed_comments'] ),
				);
				foreach ( $comment_items as $item ) :
				?>
				<label class="pc-sw">
					<input type="checkbox" name="prime_cache_settings[<?php echo esc_attr( $item[0] ); ?>]" value="1" <?php checked( $settings[ $item[0] ] ); ?>>
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php echo esc_html( $item[1] ); ?> <span class="pc-badge pc-badge--m"><?php echo esc_html( number_format( $item[3] ) ); ?></span></b>
						<small><?php echo esc_html( $item[2] ); ?></small>
					</span>
				</label>
				<?php endforeach; ?>
			</div>

			<!-- Transients Cleanup -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Transients Cleanup', 'prime-cache' ); ?></span>
				<?php
				$transient_items = array(
					array( 'db_expired_transients', __( 'Expired Transients', 'prime-cache' ), __( 'Delete only expired transients. These are temporary options that have passed their timeout and are no longer needed. Safe to clean.', 'prime-cache' ), $counts['expired_transients'] ),
					array( 'db_all_transients',     __( 'All Transients', 'prime-cache' ),     __( 'Delete all transients, including active ones. Transients are recreated as needed by plugins and WordPress core. Use with caution — may briefly slow down the site while they regenerate.', 'prime-cache' ), $counts['all_transients'] ),
				);
				foreach ( $transient_items as $item ) :
				?>
				<label class="pc-sw">
					<input type="checkbox" name="prime_cache_settings[<?php echo esc_attr( $item[0] ); ?>]" value="1" <?php checked( $settings[ $item[0] ] ); ?>>
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php echo esc_html( $item[1] ); ?> <span class="pc-badge pc-badge--m"><?php echo esc_html( number_format( $item[3] ) ); ?></span></b>
						<small><?php echo esc_html( $item[2] ); ?></small>
					</span>
				</label>
				<?php endforeach; ?>
			</div>

			<!-- Table Optimization -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Database Tables', 'prime-cache' ); ?></span>
				<label class="pc-sw">
					<input type="checkbox" name="prime_cache_settings[db_optimize_tables]" value="1" <?php checked( $settings['db_optimize_tables'] ); ?>>
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php esc_html_e( 'Optimize Tables', 'prime-cache' ); ?> <span class="pc-badge pc-badge--m"><?php echo esc_html( number_format( $counts['tables'] ) ); ?></span></b>
						<small><?php esc_html_e( 'Run OPTIMIZE TABLE on non-InnoDB WordPress tables with fragmented space. InnoDB tables are excluded because they handle fragmentation internally and OPTIMIZE can cause heavy table rebuilds on large tables.', 'prime-cache' ); ?></small>
					</span>
				</label>
			</div>

			<!-- Automatic Cleanup -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Automatic Cleanup', 'prime-cache' ); ?></span>
				<label class="pc-sw" style="margin-bottom:12px">
					<input type="checkbox" name="prime_cache_settings[db_auto_cleanup]" value="1" <?php checked( $settings['db_auto_cleanup'] ); ?> id="pc-db-auto">
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php esc_html_e( 'Schedule Automatic Cleanup', 'prime-cache' ); ?></b>
						<small><?php esc_html_e( 'Automatically run all enabled cleanup tasks above on a schedule via WP-Cron. Only the options toggled on above will be cleaned.', 'prime-cache' ); ?></small>
					</span>
				</label>
				<div class="pc-field" id="pc-db-freq" style="<?php echo $settings['db_auto_cleanup'] ? '' : 'opacity:.4;pointer-events:none;'; ?>">
					<label class="pc-lbl"><?php esc_html_e( 'Cleanup Frequency', 'prime-cache' ); ?></label>
					<select name="prime_cache_settings[db_cleanup_frequency]" class="pc-sel" style="width:180px">
						<option value="daily" <?php selected( $settings['db_cleanup_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'prime-cache' ); ?></option>
						<option value="weekly" <?php selected( $settings['db_cleanup_frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'prime-cache' ); ?></option>
						<option value="monthly" <?php selected( $settings['db_cleanup_frequency'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'prime-cache' ); ?></option>
					</select>
				</div>
			</div>

			<div class="pc-card" style="background:#fffbeb;border-color:#fbbf24">
				<div style="display:flex;align-items:flex-start;gap:12px">
					<span class="dashicons dashicons-warning" style="color:#d97706;font-size:22px;margin-top:2px;flex-shrink:0"></span>
					<div>
						<b style="color:#92400e"><?php esc_html_e( 'Please back up your database before running cleanup.', 'prime-cache' ); ?></b>
						<p class="pc-help" style="margin:4px 0 0;color:#a16207"><?php esc_html_e( 'Database cleanup operations permanently delete data such as revisions, drafts, and comments. These actions cannot be undone. We strongly recommend creating a full database backup using your hosting control panel or a backup plugin before proceeding.', 'prime-cache' ); ?></p>
					</div>
				</div>
			</div>

			<div class="pc-actions">
				<?php submit_button( __( 'Save Settings', 'prime-cache' ), 'primary large', 'submit', false ); ?>
				<a href="<?php echo esc_url( $cleanup_url ); ?>" class="pc-btn pc-btn--p" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Run database cleanup now? Up to 1,000 items will be processed per run. This cannot be undone.', 'prime-cache' ) ) ); ?>)">
					<span class="dashicons dashicons-database-remove"></span><?php esc_html_e( 'Run Cleanup Now', 'prime-cache' ); ?>
				</a>
			</div>
		</form>

		<script>
		(function(){
			var cb=document.getElementById('pc-db-auto'),fr=document.getElementById('pc-db-freq');
			if(!cb||!fr)return;
			cb.addEventListener('change',function(){fr.style.opacity=cb.checked?'1':'.4';fr.style.pointerEvents=cb.checked?'':'none';});
		})();
		</script>
		<?php
	}

	/* ── tab: auto purge ──────────────────────────────────── */

	private function tab_auto_purge( $settings ) {
		$purge_keys = array(
			'purge_on_post_update','purge_on_post_delete','purge_on_comment','purge_on_term_change',
			'purge_on_theme_switch','purge_on_permalink','purge_on_plugin_change','purge_on_customizer',
			'purge_on_widget','purge_on_nav_menu','purge_on_core_update','purge_on_user_update',
		);
		$triggers = array(
			array( 'purge_on_post_update',   __( 'Post Publish / Update', 'prime-cache' ),     __( 'Clear related caches when a post, page, or custom post type is published or updated. Purges the post URL, home page, archives, taxonomy pages, and date archives.', 'prime-cache' ) ),
			array( 'purge_on_post_delete',   __( 'Post Trash / Delete', 'prime-cache' ),       __( 'Clear related caches when a post is moved to trash or permanently deleted.', 'prime-cache' ) ),
			array( 'purge_on_comment',       __( 'Comment Changes', 'prime-cache' ),            __( 'Clear the post page cache when a comment is posted, approved, edited, trashed, or deleted.', 'prime-cache' ) ),
			array( 'purge_on_term_change',   __( 'Term Changes', 'prime-cache' ),               __( 'Clear the term archive and home page when a category, tag, or custom taxonomy term is created, edited, or deleted.', 'prime-cache' ) ),
			array( 'purge_on_theme_switch',  __( 'Theme Switch', 'prime-cache' ),               __( 'Clear the entire cache when the active theme is changed. Theme changes typically affect all pages.', 'prime-cache' ) ),
			array( 'purge_on_permalink',     __( 'Permalink Change', 'prime-cache' ),           __( 'Clear the entire cache when the permalink structure is updated. All cached URLs become invalid.', 'prime-cache' ) ),
			array( 'purge_on_plugin_change', __( 'Plugin Activate / Deactivate', 'prime-cache' ), __( 'Clear the entire cache when any plugin is activated or deactivated. Plugin changes may alter page output, menus, or widgets.', 'prime-cache' ) ),
			array( 'purge_on_customizer',    __( 'Customizer Save', 'prime-cache' ),            __( 'Clear the entire cache when theme customizer settings are saved. Changes to site identity, colors, layouts, and header/footer affect all pages.', 'prime-cache' ) ),
			array( 'purge_on_widget',        __( 'Widget Update', 'prime-cache' ),              __( 'Clear the entire cache when widgets are added, removed, or rearranged. Widgets appear on multiple pages via sidebars and footers.', 'prime-cache' ) ),
			array( 'purge_on_nav_menu',      __( 'Navigation Menu Update', 'prime-cache' ),     __( 'Clear the entire cache when a navigation menu is created, edited, or deleted. Menus are typically displayed on every page.', 'prime-cache' ) ),
			array( 'purge_on_core_update',   __( 'WordPress Core Update', 'prime-cache' ),      __( 'Clear the entire cache after WordPress core is updated. Core updates may change HTML output, scripts, and styles.', 'prime-cache' ) ),
			array( 'purge_on_user_update',   __( 'User Profile Update', 'prime-cache' ),        __( 'Clear the author archive page and home page when a user profile is updated or a user is deleted.', 'prime-cache' ) ),
		);
		?>
		<h2 class="pc-title"><?php esc_html_e( 'Auto Purge', 'prime-cache' ); ?></h2>
		<p class="pc-help" style="margin:0 0 20px;font-size:13px"><?php esc_html_e( 'Choose which WordPress events automatically clear the cache. Disabling a trigger means that event will no longer purge cached pages.', 'prime-cache' ); ?></p>

		<form method="post" action="options.php">
			<?php settings_fields( 'prime_cache_settings_group' ); ?>
			<?php $this->hidden( $settings, $purge_keys ); ?>

			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Purge Triggers', 'prime-cache' ); ?></span>
				<?php foreach ( $triggers as $t ) : ?>
				<label class="pc-sw">
					<input type="checkbox" name="prime_cache_settings[<?php echo esc_attr( $t[0] ); ?>]" value="1" <?php checked( $settings[ $t[0] ] ); ?>>
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body"><b><?php echo esc_html( $t[1] ); ?></b><small><?php echo esc_html( $t[2] ); ?></small></span>
				</label>
				<?php endforeach; ?>
			</div>

			<div class="pc-actions"><?php submit_button( __( 'Save Settings', 'prime-cache' ), 'primary large', 'submit', false ); ?></div>
		</form>
		<?php
	}

	/* ── tab: exclusions ──────────────────────────────────── */

	private function tab_exclusions( $settings ) {
		$vis = array('cache_reject_uri','cache_reject_cookies','cache_reject_ua','cache_reject_referrer');
		$htaccess_note = __('Note: When .htaccess Optimization is enabled, only simple patterns (letters, numbers, pipes, dots, slashes) are used in the .htaccess fast-path. Complex regex patterns work in the PHP drop-in but may not be applied by Apache.','prime-cache');
		$rows = array(
			array('cache_reject_uri',     __('Excluded URLs','prime-cache'),         __('URL patterns to never cache','prime-cache'),       '/cart|/checkout|/my-account', __('Enter URL path regex patterns separated by <code>|</code>. Matched URLs are always dynamically generated by WordPress. Specify WooCommerce cart/checkout, my-account pages, or any page with user-specific content or dynamic API endpoints.','prime-cache') . ' ' . $htaccess_note),
			array('cache_reject_cookies', __('Excluded Cookies','prime-cache'),      __('Cookies that disable caching','prime-cache'),      'woocommerce_cart_hash|wp_woocommerce_session', __('Enter cookie name regex patterns separated by <code>|</code>. When any listed cookie is present in the browser, the request is not cached. Specify WooCommerce cart sessions, form plugin tokens, or any cookies holding user-specific state. WordPress login cookies (wordpress_logged_in_) are excluded automatically.','prime-cache') . ' ' . $htaccess_note),
			array('cache_reject_ua',      __('Excluded User Agents','prime-cache'),  __('User agents to never cache','prime-cache'),        'bot|crawler|spider', __('Enter user-agent regex patterns separated by <code>|</code>. Matched browsers or crawlers receive uncached responses. Use to always show fresh content to specific bots or to exclude monitoring tool requests from the cache.','prime-cache') . ' ' . $htaccess_note),
			array('cache_reject_referrer',__('Excluded Referrers','prime-cache'),    __('Referrers to never cache','prime-cache'),          'example\\.com|spam\\.site', __('Enter referrer URL regex patterns separated by <code>|</code>. Requests from matching referrers are excluded from the cache. Use to block spam referrers or to always show fresh content on specific ad landing flows.','prime-cache') . ' ' . $htaccess_note),
		);
		?>
		<h2 class="pc-title"><?php esc_html_e('Exclusion Rules','prime-cache'); ?></h2>
		<form method="post" action="options.php"><?php settings_fields('prime_cache_settings_group'); $this->hidden($settings,$vis); ?>
			<?php foreach($rows as $r): ?>
			<div class="pc-card"><span class="pc-card__h"><?php echo esc_html($r[1]); ?></span>
				<div class="pc-field"><label class="pc-lbl"><?php echo esc_html($r[2]); ?> <span class="pc-tag"><?php esc_html_e('Regex','prime-cache'); ?></span></label><textarea name="prime_cache_settings[<?php echo esc_attr($r[0]); ?>]" rows="3" class="pc-ta" placeholder="<?php echo esc_attr($r[3]); ?>"><?php echo esc_textarea($settings[$r[0]]); ?></textarea><p class="pc-help"><?php echo wp_kses($r[4],array('code'=>array())); ?></p></div>
			</div>
			<?php endforeach; ?>
			<div class="pc-actions"><?php submit_button(__('Save Settings','prime-cache'),'primary large','submit',false); ?></div>
		</form>
		<?php
	}

	/* ── tab: tools ───────────────────────────────────────── */

	private function tab_tools( $settings ) {
		$vis = array( 'hsts_enabled','hsts_max_age','security_headers','debug_log' );
		$export_url = wp_nonce_url( admin_url( 'admin.php?pc_export_settings=1' ), 'pc_export' );
		?>
		<h2 class="pc-title"><?php esc_html_e( 'Tools', 'prime-cache' ); ?></h2>

		<?php if ( isset( $_GET['pc_imported'] ) ) : ?>
			<?php if ( 'ok' === $_GET['pc_imported'] ) : ?>
				<div class="notice notice-success is-dismissible" style="margin:0 0 16px"><p><?php esc_html_e( 'Settings imported successfully.', 'prime-cache' ); ?></p></div>
			<?php elseif ( 'partial' === $_GET['pc_imported'] ) : ?>
				<div class="notice notice-warning is-dismissible" style="margin:0 0 16px"><p><?php esc_html_e( 'Settings imported with warnings.', 'prime-cache' ); ?></p>
				<?php
				$import_warnings = get_transient( 'prime_cache_import_warnings' );
				if ( ! empty( $import_warnings ) && is_array( $import_warnings ) ) {
					delete_transient( 'prime_cache_import_warnings' );
					echo '<ul style="margin:4px 0 0 16px;list-style:disc">';
					foreach ( $import_warnings as $iw ) {
						echo '<li>' . esc_html( $iw ) . '</li>';
					}
					echo '</ul>';
				}
				?>
				</div>
			<?php else : ?>
				<div class="notice notice-error is-dismissible" style="margin:0 0 16px"><p><?php esc_html_e( 'Import failed. Please upload a valid Prime Cache settings JSON file.', 'prime-cache' ); ?></p></div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( isset( $_GET['pc_preset'] ) ) : ?>
			<div class="notice notice-success is-dismissible" style="margin:0 0 16px"><p><?php printf( esc_html__( '"%s" preset applied successfully.', 'prime-cache' ), esc_html( ucfirst( sanitize_key( $_GET['pc_preset'] ) ) ) ); ?></p></div>
		<?php endif; ?>

		<!-- Presets -->
		<div class="pc-card">
			<span class="pc-card__h"><?php esc_html_e( 'Optimization Presets', 'prime-cache' ); ?></span>
			<p class="pc-help" style="margin:0 0 16px"><?php esc_html_e( 'Quickly apply a preset configuration based on your comfort level. You can customize individual settings afterward.', 'prime-cache' ); ?></p>

			<!-- Auto Preset (featured) -->
			<?php
			$auto_url = wp_nonce_url( admin_url( 'admin.php?pc_action=apply_preset&preset=auto' ), 'prime_cache_admin_action' );
			$auto_env = Prime_Cache::get_auto_environment_summary();
			?>
			<div style="border:2px solid var(--c-pri);border-radius:var(--radius);padding:20px 24px;margin-bottom:16px;background:linear-gradient(135deg,#f5f3ff,#ede9fe)">
				<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
					<span class="dashicons dashicons-admin-site-alt3" style="font-size:28px;width:28px;height:28px;color:var(--c-pri)"></span>
					<div>
						<h3 style="margin:0;font-size:16px;color:var(--c-pri)"><?php esc_html_e( 'Auto', 'prime-cache' ); ?></h3>
						<span style="font-size:11px;color:#64748b"><?php esc_html_e( 'Recommended — analyzes your environment and applies optimal settings automatically.', 'prime-cache' ); ?></span>
					</div>
				</div>
				<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 20px;font-size:12px;color:#475569;margin-bottom:14px">
					<?php foreach ( $auto_env as $label => $value ) : ?>
					<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid rgba(0,0,0,.05)">
						<span><?php echo esc_html( $label ); ?></span>
						<strong style="color:#1e293b"><?php echo esc_html( $value ); ?></strong>
					</div>
					<?php endforeach; ?>
				</div>
				<a href="<?php echo esc_url( $auto_url ); ?>" class="pc-btn pc-btn--p pc-btn--sm" style="width:100%" onclick="return confirm(<?php echo esc_attr( wp_json_encode( sprintf( __( 'Apply the "%s" preset? This will overwrite your current settings.', 'prime-cache' ), __( 'Auto', 'prime-cache' ) ) ) ); ?>)">
					<?php esc_html_e( 'Apply Auto Preset', 'prime-cache' ); ?>
				</a>
			</div>

			<!-- Manual Presets -->
			<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px">
				<?php
				$presets = array(
					'safe' => array(
						__( 'Safe', 'prime-cache' ),
						__( 'Minimal risk. Enables page caching, gzip compression, browser caching, and lazy loading. No file optimization or advanced features. Recommended for beginners or production sites that need stability.', 'prime-cache' ),
						'#22c55e',
						'dashicons-shield',
					),
					'balanced' => array(
						__( 'Balanced', 'prime-cache' ),
						__( 'Good performance with low risk. Adds HTML/CSS/JS minification, DNS prefetch, link prefetching, emoji removal, and .htaccess optimization on top of Safe settings.', 'prime-cache' ),
						'#f59e0b',
						'dashicons-performance',
					),
					'aggressive' => array(
						__( 'Aggressive', 'prime-cache' ),
						__( 'Maximum performance. Adds CSS/JS combining, defer/delay JS, async CSS, critical CSS auto-generation, and preloading. May require testing and exclusion rules for compatibility.', 'prime-cache' ),
						'#ef4444',
						'dashicons-controls-forward',
					),
				);
				foreach ( $presets as $pk => $pv ) :
					$preset_url = wp_nonce_url( admin_url( 'admin.php?pc_action=apply_preset&preset=' . $pk ), 'prime_cache_admin_action' );
				?>
				<div style="border:2px solid <?php echo esc_attr( $pv[2] ); ?>;border-radius:var(--radius);padding:20px;text-align:center;display:flex;flex-direction:column;align-items:center">
					<span class="dashicons <?php echo esc_attr( $pv[3] ); ?>" style="font-size:32px;width:32px;height:32px;color:<?php echo esc_attr( $pv[2] ); ?>;margin-bottom:8px"></span>
					<h3 style="margin:0 0 8px;font-size:16px"><?php echo esc_html( $pv[0] ); ?></h3>
					<p class="pc-help" style="margin:0 0 14px;font-size:12px;flex:1"><?php echo esc_html( $pv[1] ); ?></p>
					<a href="<?php echo esc_url( $preset_url ); ?>" class="pc-btn pc-btn--p pc-btn--sm" style="width:100%" onclick="return confirm(<?php echo esc_attr( wp_json_encode( sprintf( __( 'Apply the "%s" preset? This will overwrite your current settings.', 'prime-cache' ), $pv[0] ) ) ); ?>)">
						<?php esc_html_e( 'Apply', 'prime-cache' ); ?>
					</a>
				</div>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Import / Export -->
		<div class="pc-card">
			<span class="pc-card__h"><?php esc_html_e( 'Import / Export Settings', 'prime-cache' ); ?></span>
			<div class="pc-field">
				<label class="pc-lbl"><?php esc_html_e( 'Export', 'prime-cache' ); ?></label>
				<a href="<?php echo esc_url( $export_url ); ?>" class="pc-btn pc-btn--p pc-btn--sm"><span class="dashicons dashicons-download" style="font-size:15px;width:15px;height:15px;line-height:15px"></span><?php esc_html_e( 'Download Settings (JSON)', 'prime-cache' ); ?></a>
				<p class="pc-help"><?php esc_html_e( 'Export all Prime Cache settings as a JSON file. Use this to back up your configuration or transfer it to another site.', 'prime-cache' ); ?></p>
			</div>
			<div class="pc-field">
				<label class="pc-lbl"><?php esc_html_e( 'Import', 'prime-cache' ); ?></label>
				<form method="post" enctype="multipart/form-data" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
					<?php wp_nonce_field( 'pc_import' ); ?>
					<input type="file" name="pc_import_file" accept=".json" class="pc-inp" style="width:auto">
					<button type="submit" name="pc_import_settings" value="1" class="pc-btn pc-btn--o pc-btn--sm" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Import will overwrite all current settings. Continue?', 'prime-cache' ) ) ); ?>)">
						<span class="dashicons dashicons-upload" style="font-size:15px;width:15px;height:15px;line-height:15px"></span><?php esc_html_e( 'Import', 'prime-cache' ); ?>
					</button>
				</form>
				<p class="pc-help"><?php esc_html_e( 'Upload a previously exported Prime Cache settings JSON file. This will overwrite all current settings.', 'prime-cache' ); ?></p>
			</div>
		</div>

		<!-- Reset -->
		<div class="pc-card" style="background:#fef2f2;border-color:#fca5a5">
			<span class="pc-card__h" style="color:#b91c1c"><?php esc_html_e( 'Reset All Settings', 'prime-cache' ); ?></span>
			<p class="pc-help" style="margin:0 0 12px;color:#991b1b"><?php esc_html_e( 'Delete all Prime Cache settings and restore them to factory defaults. This does not delete cached files — use "Clear All Cache" from the admin bar for that.', 'prime-cache' ); ?></p>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?pc_action=reset_settings' ), 'prime_cache_admin_action' ) ); ?>" class="pc-btn pc-btn--r pc-btn--sm" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'This will reset ALL settings to defaults. This cannot be undone. Continue?', 'prime-cache' ) ) ); ?>)">
				<span class="dashicons dashicons-image-rotate" style="font-size:15px;width:15px;height:15px;line-height:15px"></span><?php esc_html_e( 'Reset to Defaults', 'prime-cache' ); ?>
			</a>
		</div>

		<!-- Security -->
		<form method="post" action="options.php">
			<?php settings_fields( 'prime_cache_settings_group' ); ?>
			<?php $this->hidden( $settings, $vis ); ?>

			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Security Headers', 'prime-cache' ); ?></span>
				<?php if ( empty( $settings['htaccess_enabled'] ) ) : ?>
				<div class="notice notice-warning inline" style="margin:0 0 12px"><p><?php esc_html_e( 'Security headers require .htaccess Optimization to be enabled on the Page Cache tab. Without it, these headers will not be added to responses.', 'prime-cache' ); ?></p></div>
				<?php endif; ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[hsts_enabled]" value="1" <?php checked( $settings['hsts_enabled'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'HTTP Strict Transport Security (HSTS)', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Force browsers to use HTTPS for all future connections. Only enable if your site fully supports HTTPS. Includes includeSubDomains and preload directives. Requires .htaccess Optimization on the Page Cache tab.', 'prime-cache' ); ?></small></span></label>
				<?php
				$hsts_s = (int) $settings['hsts_max_age'];
				if ( $hsts_s <= 0 )               { $hv = 0; $hu = 86400; }
				elseif ( $hsts_s % 31536000 === 0 ) { $hv = $hsts_s / 31536000; $hu = 31536000; }
				elseif ( $hsts_s % 2592000 === 0 )  { $hv = $hsts_s / 2592000;  $hu = 2592000; }
				elseif ( $hsts_s % 604800 === 0 )   { $hv = $hsts_s / 604800;   $hu = 604800; }
				elseif ( $hsts_s % 86400 === 0 )    { $hv = $hsts_s / 86400;    $hu = 86400; }
				elseif ( $hsts_s % 3600 === 0 )     { $hv = $hsts_s / 3600;     $hu = 3600; }
				elseif ( $hsts_s % 60 === 0 )       { $hv = $hsts_s / 60;       $hu = 60; }
				else                                { $hv = $hsts_s;            $hu = 1; }
				?>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'HSTS Max-Age', 'prime-cache' ); ?></label>
					<div class="pc-ls-row">
						<input type="number" value="<?php echo esc_attr( $hv ); ?>" min="0" class="pc-inp" style="width:80px" id="pc-hsts-val">
						<select class="pc-sel" id="pc-hsts-unit">
							<option value="31536000" <?php selected( $hu, 31536000 ); ?>><?php esc_html_e( 'Years', 'prime-cache' ); ?></option>
							<option value="2592000" <?php selected( $hu, 2592000 ); ?>><?php esc_html_e( 'Months', 'prime-cache' ); ?></option>
							<option value="604800" <?php selected( $hu, 604800 ); ?>><?php esc_html_e( 'Weeks', 'prime-cache' ); ?></option>
							<option value="86400" <?php selected( $hu, 86400 ); ?>><?php esc_html_e( 'Days', 'prime-cache' ); ?></option>
							<option value="3600" <?php selected( $hu, 3600 ); ?>><?php esc_html_e( 'Hours', 'prime-cache' ); ?></option>
							<option value="60" <?php selected( $hu, 60 ); ?>><?php esc_html_e( 'Minutes', 'prime-cache' ); ?></option>
							<option value="1" <?php selected( $hu, 1 ); ?>><?php esc_html_e( 'Seconds', 'prime-cache' ); ?></option>
						</select>
						<span class="pc-meta" id="pc-hsts-eq"><?php echo esc_html( self::seconds_to_human( $hsts_s ) ); ?></span>
						<input type="hidden" name="prime_cache_settings[hsts_max_age]" value="<?php echo esc_attr( $hsts_s ); ?>" id="pc-hsts-hidden">
					</div>
				</div>
				<script>
				(function(){
					var v=document.getElementById('pc-hsts-val'),u=document.getElementById('pc-hsts-unit'),h=document.getElementById('pc-hsts-hidden'),e=document.getElementById('pc-hsts-eq');
					if(!v||!u||!h)return;
					var uL={31536000:<?php echo wp_json_encode(__('year(s)','prime-cache'), JSON_HEX_TAG);?>,2592000:<?php echo wp_json_encode(__('month(s)','prime-cache'), JSON_HEX_TAG);?>,604800:<?php echo wp_json_encode(__('week(s)','prime-cache'), JSON_HEX_TAG);?>,86400:<?php echo wp_json_encode(__('day(s)','prime-cache'), JSON_HEX_TAG);?>,3600:<?php echo wp_json_encode(__('hour(s)','prime-cache'), JSON_HEX_TAG);?>,60:<?php echo wp_json_encode(__('minute(s)','prime-cache'), JSON_HEX_TAG);?>,1:<?php echo wp_json_encode(__('sec','prime-cache'), JSON_HEX_TAG);?>};
					function calc(){var s=(parseInt(v.value,10)||0)*(parseInt(u.value,10)||1);h.value=s;if(s<=0){e.textContent='(0)';}else{var k=[31536000,2592000,604800,86400,3600,60,1];for(var i=0;i<k.length;i++){if(s>=k[i]&&s%k[i]===0){e.textContent='= '+(s/k[i])+' '+uL[k[i]];return;}}e.textContent='= '+s+' '+uL[1];}}
					v.addEventListener('input',calc);u.addEventListener('change',calc);
				})();
				</script>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[security_headers]" value="1" <?php checked( $settings['security_headers'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Security Response Headers', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Add X-Content-Type-Options (nosniff), X-Frame-Options (SAMEORIGIN), X-XSS-Protection, Referrer-Policy, and Permissions-Policy headers. Protects against clickjacking, MIME-type sniffing, and XSS attacks. Requires .htaccess Optimization on the Page Cache tab.', 'prime-cache' ); ?></small></span></label>
			</div>

			<!-- Debug -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Debug', 'prime-cache' ); ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[debug_log]" value="1" <?php checked( $settings['debug_log'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Enable Debug Logging', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Log cache purge operations (PURGE ALL, PURGE URL) to wp-content/cache/prime-cache/debug.log. Useful for troubleshooting cache invalidation. Disable in production as it increases disk I/O.', 'prime-cache' ); ?></small></span></label>
				<?php
				$log_file = PRIME_CACHE_CACHE_DIR . 'debug.log';
				if ( file_exists( $log_file ) ) :
					$log_size = filesize( $log_file );
				?>
				<div class="pc-field">
					<p class="pc-meta"><?php printf( esc_html__( 'Log file size: %s', 'prime-cache' ), esc_html( size_format( $log_size ) ) ); ?></p>
				</div>
				<?php endif; ?>
			</div>

			<div class="pc-actions"><?php submit_button( __( 'Save Settings', 'prime-cache' ), 'primary large', 'submit', false ); ?></div>
		</form>

		<!-- System Info -->
		<div style="margin-top:24px"></div>
		<div class="pc-card">
			<span class="pc-card__h"><?php esc_html_e( 'System Information', 'prime-cache' ); ?></span>
			<p class="pc-help" style="margin:0 0 14px"><?php esc_html_e( 'Copy and paste this information when reporting bugs or requesting support.', 'prime-cache' ); ?></p>
			<?php $this->render_system_info(); ?>
			<div style="margin-top:12px">
				<button type="button" class="pc-btn pc-btn--o pc-btn--sm" onclick="var t=document.getElementById('pc-sysinfo');t.select();document.execCommand('copy');this.textContent=<?php echo esc_attr( wp_json_encode( __( 'Copied!', 'prime-cache' ) ) ); ?>;setTimeout(function(){this.textContent=<?php echo esc_attr( wp_json_encode( __( 'Copy to Clipboard', 'prime-cache' ) ) ); ?>;}.bind(this),2000);">
					<span class="dashicons dashicons-clipboard" style="font-size:15px;width:15px;height:15px;line-height:15px"></span><?php esc_html_e( 'Copy to Clipboard', 'prime-cache' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render system information textarea.
	 */
	private function render_system_info() {
		global $wpdb, $wp_version;

		$caps = class_exists( 'Prime_Cache_WebP' ) ? Prime_Cache_WebP::get_capabilities() : array( 'gd_webp' => false, 'imagick_webp' => false, 'gd_avif' => false, 'imagick_avif' => false );
		$s    = prime_cache_get_settings();
		$oc   = Prime_Cache_Config::get_active_object_cache();

		// GD info.
		$gd_info = function_exists( 'gd_info' ) ? gd_info() : array();
		$gd_version = $gd_info['GD Version'] ?? 'N/A';

		// Imagick info.
		$imagick_version = 'N/A';
		if ( class_exists( 'Imagick' ) ) {
			$iv = Imagick::getVersion();
			$imagick_version = $iv['versionString'] ?? 'Installed';
		}

		// Imagick formats.
		$imagick_formats = array();
		if ( class_exists( 'Imagick' ) ) {
			$all_fmt = @Imagick::queryFormats();
			foreach ( array( 'WEBP', 'AVIF', 'PNG', 'JPEG', 'GIF', 'SVG', 'HEIC' ) as $fmt ) {
				$imagick_formats[ $fmt ] = in_array( $fmt, $all_fmt, true );
			}
		}

		// Server.
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown';
		$sapi = php_sapi_name();

		// Memory.
		$mem_limit = ini_get( 'memory_limit' );
		$max_exec  = ini_get( 'max_execution_time' );
		$upload_max = ini_get( 'upload_max_filesize' );
		$post_max   = ini_get( 'post_max_size' );

		// PHP extensions.
		$extensions = array(
			'curl'      => extension_loaded( 'curl' ),
			'mbstring'  => extension_loaded( 'mbstring' ),
			'xml'       => extension_loaded( 'xml' ),
			'dom'       => extension_loaded( 'dom' ),
			'gd'        => extension_loaded( 'gd' ),
			'imagick'   => extension_loaded( 'imagick' ),
			'redis'     => extension_loaded( 'redis' ),
			'memcached' => extension_loaded( 'memcached' ),
			'apcu'      => extension_loaded( 'apcu' ),
			'zlib'      => extension_loaded( 'zlib' ),
			'opcache'   => extension_loaded( 'Zend OPcache' ),
			'intl'      => extension_loaded( 'intl' ),
			'exif'      => extension_loaded( 'exif' ),
		);

		// Filesystem.
		$cache_writable  = wp_is_writable( PRIME_CACHE_CACHE_DIR ) || wp_is_writable( dirname( PRIME_CACHE_CACHE_DIR ) );
		$htaccess_exists = file_exists( ABSPATH . '.htaccess' );
		$htaccess_write  = $htaccess_exists ? wp_is_writable( ABSPATH . '.htaccess' ) : wp_is_writable( ABSPATH );

		// Cache sizes.
		$page_size = 0; $fo_size = 0;
		if ( is_dir( PRIME_CACHE_CACHE_DIR ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( PRIME_CACHE_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) { if ( $f->isFile() ) $page_size += $f->getSize(); }
		}
		$fo_dir = WP_CONTENT_DIR . '/cache/prime-cache-fo/';
		if ( is_dir( $fo_dir ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fo_dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) { if ( $f->isFile() ) $fo_size += $f->getSize(); }
		}

		// Active theme.
		$theme = wp_get_theme();

		// Active plugins.
		$active_plugins = get_option( 'active_plugins', array() );

		// Build text.
		$lines = array();
		$lines[] = '### Prime Cache System Info ###';
		$lines[] = '';
		$lines[] = '## Plugin';
		$lines[] = 'Prime Cache Version: ' . PRIME_CACHE_VERSION;
		$lines[] = 'Cache Enabled: ' . ( $s['cache_enabled'] ? 'Yes' : 'No' );
		$lines[] = 'Object Cache: ' . ( 'off' === $oc ? 'Disabled' : strtoupper( $oc ) );
		$lines[] = 'Page Cache Size: ' . size_format( $page_size );
		$lines[] = 'FO Cache Size: ' . size_format( $fo_size );
		$lines[] = '';
		$lines[] = '## WordPress';
		$lines[] = 'WordPress Version: ' . $wp_version;
		$lines[] = 'Multisite: ' . ( is_multisite() ? 'Yes' : 'No' );
		$lines[] = 'Site URL: ' . home_url();
		$lines[] = 'WP_CACHE: ' . ( defined( 'WP_CACHE' ) && WP_CACHE ? 'true' : 'false' );
		$lines[] = 'WP_DEBUG: ' . ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'true' : 'false' );
		$lines[] = 'ABSPATH: ' . ABSPATH;
		$lines[] = '';
		$lines[] = '## Server';
		$lines[] = 'Web Server: ' . $server_software;
		$lines[] = 'PHP Version: ' . PHP_VERSION;
		$lines[] = 'PHP SAPI: ' . $sapi;
		$lines[] = 'PHP Memory Limit: ' . $mem_limit;
		$lines[] = 'PHP Max Execution Time: ' . $max_exec . 's';
		$lines[] = 'Upload Max Filesize: ' . $upload_max;
		$lines[] = 'Post Max Size: ' . $post_max;
		$lines[] = 'MySQL Version: ' . $wpdb->db_version();
		$lines[] = 'OS: ' . PHP_OS . ' (' . php_uname( 'r' ) . ')';
		$lines[] = '';
		$lines[] = '## Image Processing';
		$lines[] = 'GD Library: ' . ( extension_loaded( 'gd' ) ? $gd_version : 'Not installed' );
		$lines[] = '  GD WebP: ' . ( $caps['gd_webp'] ? 'Supported' : 'Not supported' );
		$lines[] = '  GD AVIF: ' . ( $caps['gd_avif'] ? 'Supported' : 'Not supported' );
		$lines[] = 'Imagick: ' . ( class_exists( 'Imagick' ) ? $imagick_version : 'Not installed' );
		if ( $imagick_formats ) {
			foreach ( $imagick_formats as $fmt => $ok ) {
				$lines[] = '  Imagick ' . $fmt . ': ' . ( $ok ? 'Supported' : 'Not supported' );
			}
		}
		$lines[] = 'EXIF Extension: ' . ( extension_loaded( 'exif' ) ? 'Loaded' : 'Not loaded' );
		$lines[] = '';
		$lines[] = '## PHP Extensions';
		foreach ( $extensions as $ext => $loaded ) {
			$lines[] = '  ' . str_pad( $ext, 12 ) . ': ' . ( $loaded ? 'Loaded' : 'Not loaded' );
		}
		$lines[] = '';
		$lines[] = '## Filesystem';
		$lines[] = 'Cache Dir Writable: ' . ( $cache_writable ? 'Yes' : 'No' );
		$lines[] = '.htaccess Exists: ' . ( $htaccess_exists ? 'Yes' : 'No' );
		$lines[] = '.htaccess Writable: ' . ( $htaccess_write ? 'Yes' : 'No' );
		$lines[] = 'Advanced-cache.php: ' . ( file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ? 'Installed' : 'Missing' );
		$lines[] = 'Object-cache.php: ' . ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ? 'Installed' : 'Missing' );
		$lines[] = '';
		$lines[] = '## Theme';
		$lines[] = 'Active Theme: ' . $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' );
		if ( is_child_theme() ) {
			$parent = $theme->parent();
			$lines[] = 'Parent Theme: ' . ( $parent ? $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ) : 'N/A' );
		}
		$lines[] = '';
		$lines[] = '## Active Plugins (' . count( $active_plugins ) . ')';
		foreach ( $active_plugins as $plugin ) {
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );
			$lines[] = '  ' . ( $data['Name'] ?: $plugin ) . ' ' . ( $data['Version'] ?: '' );
		}
		$lines[] = '';
		$lines[] = '### End System Info ###';

		$text = implode( "\n", $lines );
		?>
		<textarea id="pc-sysinfo" readonly class="pc-ta" rows="20" style="font-size:12px;line-height:1.5;background:var(--c-subtle);cursor:text"><?php echo esc_textarea( $text ); ?></textarea>
		<?php
	}

	/* ── tab: object cache ────────────────────────────────── */

	private function tab_object() {
		$act = Prime_Cache_Config::get_active_object_cache();
		$be = array(
			'apcu'=>array('APCu',__('Shared memory within PHP processes. Ideal for single-server environments.','prime-cache'),'apcu',function_exists('apcu_add'),'dashicons-performance'),
			'redis'=>array('Redis',__('High-performance in-memory data store. Supports persistence and replication.','prime-cache'),'redis (PhpRedis)',class_exists('Redis'),'dashicons-cloud'),
			'memcached'=>array('Memcached',__('Distributed memory cache. Ideal for sharing across multiple servers.','prime-cache'),'memcached (PECL)',class_exists('Memcached'),'dashicons-networking'),
		);
		?>
		<h2 class="pc-title"><?php esc_html_e('Object Cache','prime-cache'); ?></h2>
		<?php if(isset($_GET['prime_cache_oc_switched'])): ?><div class="notice notice-success is-dismissible" style="margin:0 0 16px"><p><?php esc_html_e('Object cache settings have been updated.','prime-cache'); ?></p></div><?php endif; ?>
		<?php if(isset($_GET['prime_cache_oc_switch_failed'])): ?><div class="notice notice-error is-dismissible" style="margin:0 0 16px"><p><?php esc_html_e('Object cache switch failed. Another plugin may be managing object-cache.php, or the required PHP extension is not available.','prime-cache'); ?></p></div><?php endif; ?>

		<div class="pc-card pc-oc-banner">
			<span class="pc-dot pc-dot--<?php echo 'off'===$act?'m':'g'; ?> pc-dot--xl"></span>
			<div class="pc-oc-banner__body">
				<b><?php if('off'===$act) esc_html_e('Object Cache: Disabled','prime-cache'); elseif('external'===$act) esc_html_e('Object Cache: Managed by another plugin','prime-cache'); else printf(esc_html__('Object Cache: Active via %s','prime-cache'),esc_html(strtoupper($act))); ?></b>
				<small><?php if('off'===$act) esc_html_e('Select a backend to enable object caching.','prime-cache'); elseif('external'===$act) esc_html_e("Another plugin's object-cache.php was detected.",'prime-cache'); else esc_html_e('Database query results are being cached in memory.','prime-cache'); ?></small>
			</div>
			<?php if('off'!==$act&&'external'!==$act): ?><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?prime_cache_object_cache=off'),'prime_cache_object_cache')); ?>" class="pc-btn pc-btn--r pc-btn--sm"><?php esc_html_e('Disable','prime-cache'); ?></a><?php endif; ?>
		</div>

		<div class="pc-grid pc-grid--3">
		<?php foreach($be as $slug=>$i): $av=$i[3]; $on=($slug===$act); $eu=wp_nonce_url(admin_url('admin.php?prime_cache_object_cache='.$slug),'prime_cache_object_cache'); ?>
			<div class="pc-card pc-oc <?php echo $on?'pc-oc--on':''; ?> <?php echo !$av?'pc-oc--off':''; ?>">
				<div class="pc-oc__top"><span class="pc-oc__ico dashicons <?php echo esc_attr($i[4]); ?>"></span><b><?php echo esc_html($i[0]); ?></b>
					<?php if($on): ?><span class="pc-badge pc-badge--g"><?php esc_html_e('Active','prime-cache'); ?></span>
					<?php elseif(!$av): ?><span class="pc-badge pc-badge--m"><?php esc_html_e('Not Found','prime-cache'); ?></span>
					<?php else: ?><span class="pc-badge pc-badge--b"><?php esc_html_e('Available','prime-cache'); ?></span><?php endif; ?></div>
				<p class="pc-oc__desc"><?php echo esc_html($i[1]); ?></p>
				<div class="pc-oc__foot"><span class="pc-meta"><?php printf(esc_html__('Ext: %s','prime-cache'),'<code>'.esc_html($i[2]).'</code>'); ?> <span class="pc-dot pc-dot--<?php echo $av?'g':'r'; ?> pc-dot--in"></span></span>
					<?php if($on): ?><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?prime_cache_object_cache=off'),'prime_cache_object_cache')); ?>" class="pc-btn pc-btn--o pc-btn--sm"><?php esc_html_e('Disable','prime-cache'); ?></a>
					<?php elseif($av): ?><a href="<?php echo esc_url($eu); ?>" class="pc-btn pc-btn--p pc-btn--sm"><?php esc_html_e('Enable','prime-cache'); ?></a>
					<?php else: ?><span class="pc-btn pc-btn--o pc-btn--sm pc-btn--dis"><?php esc_html_e('PHP Extension Required','prime-cache'); ?></span><?php endif; ?></div>
			</div>
		<?php endforeach; ?>
		</div>

		<div class="pc-card"><span class="pc-card__h"><?php esc_html_e('What is Object Cache?','prime-cache'); ?></span><p class="pc-help" style="margin:0;line-height:1.7"><?php esc_html_e('WordPress executes many database queries during page generation. Object cache stores query results in memory, returning subsequent requests for the same data without hitting the database. Especially effective for sites with many logged-in users or WooCommerce environments with uncacheable pages.','prime-cache'); ?></p></div>
		<?php
	}

	/* ── notices ───────────────────────────────────────────── */

	public function show_notices() {
		// Cloudflare purge failure alert — persists until dismissed or next success.
		$cf_fail = get_option( 'prime_cache_cf_purge_failed' );
		if ( $cf_fail ) {
			$dismiss_url = wp_nonce_url( add_query_arg( 'pc_dismiss_cf_alert', '1' ), 'pc_dismiss_cf' );
			$fail_time   = is_array( $cf_fail ) && isset( $cf_fail['time'] ) ? wp_date( 'Y/m/d H:i', $cf_fail['time'] ) : '';
			$fail_type   = is_array( $cf_fail ) && isset( $cf_fail['type'] ) ? $cf_fail['type'] : '';
			$type_label  = 'full_purge' === $fail_type ? __( 'Full zone purge', 'prime-cache' ) : __( 'URL purge', 'prime-cache' );
			$detail      = $fail_time ? sprintf( ' (%s — %s)', esc_html( $type_label ), esc_html( $fail_time ) ) : '';
			echo '<div class="notice notice-error"><p><strong>Prime Cache:</strong> '
				. esc_html__( 'Cloudflare cache purge failed after multiple retries. Cloudflare may still be serving stale content. Please check your API credentials and try purging again.', 'prime-cache' )
				. $detail
				. ' <a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'prime-cache' ) . '</a>'
				. '</p></div>';
		}

		// Multisite: page caching is not supported.
		if ( is_multisite() ) {
			$screen = get_current_screen();
			if ( $screen && 'toplevel_page_prime-cache' === $screen->id ) {
				echo '<div class="notice notice-warning"><p><strong>Prime Cache:</strong> '
					. esc_html__( 'Page caching is not supported on WordPress multisite. Other features (file optimization, lazy load, CDN, etc.) work normally.', 'prime-cache' )
					. '</p></div>';
			}
		}

		// Persistent warning: WP_CACHE is not true but Prime Cache expects it.
		$screen = get_current_screen();
		if ( $screen && 'toplevel_page_prime-cache' === $screen->id ) {
			$cfg = prime_cache_get_settings();
			if ( ! empty( $cfg['cache_enabled'] ) && ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) ) {
				echo '<div class="notice notice-error"><p><strong>Prime Cache:</strong> '
					. esc_html__( 'WP_CACHE is not set to true. Page caching will not work. Please check wp-config.php — another define( \'WP_CACHE\', false ) may exist.', 'prime-cache' )
					. '</p></div>';
			}
			$ac_owner = Prime_Cache_Config::get_advanced_cache_owner();
			if ( ! empty( $cfg['cache_enabled'] ) && 'external' === $ac_owner ) {
				echo '<div class="notice notice-error"><p><strong>Prime Cache:</strong> '
					. esc_html__( 'advanced-cache.php is managed by another plugin. Prime Cache page caching will not work until the other plugin is deactivated or its drop-in is removed.', 'prime-cache' )
					. '</p></div>';
			}
		}

		if ( isset( $_GET['prime_cache_cleared'] ) && '1' === $_GET['prime_cache_cleared'] )
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cache cleared successfully.', 'prime-cache' ) . '</p></div>';

		if ( isset( $_GET['pc_cleared'] ) ) {
			$cfg = prime_cache_get_settings();
			$extra_notes = array();
			if ( ! empty( $cfg['varnish_enabled'] ) ) {
				$extra_notes[] = __( 'Varnish cache also purged.', 'prime-cache' );
			}
			if ( ! empty( $cfg['sucuri_enabled'] ) ) {
				$extra_notes[] = __( 'Sucuri cache also purged.', 'prime-cache' );
			}
			if ( ! empty( $cfg['cloudflare_enabled'] ) ) {
				$extra_notes[] = __( 'Cloudflare cache also purged.', 'prime-cache' );
			}
			$sync_note = $extra_notes ? ' ' . implode( ' ', $extra_notes ) : '';
			$msgs = array(
				'all'             => __( 'All caches cleared (page cache, minified files, critical CSS, object cache).', 'prime-cache' ) . $sync_note,
				'preload'         => __( 'All caches cleared. Preloading will start shortly.', 'prime-cache' ) . $sync_note,
				'page'            => __( 'Page cache cleared successfully.', 'prime-cache' ) . $sync_note,
				'minified'        => __( 'Minified CSS/JS files cleared successfully.', 'prime-cache' ),
				'ccss'            => __( 'Critical CSS cache cleared successfully.', 'prime-cache' ),
				'object'          => __( 'Object cache flushed successfully.', 'prime-cache' ),
				'url'             => __( 'This page cache cleared successfully.', 'prime-cache' ) . $sync_note,
				'post'            => __( 'Post cache and related pages cleared successfully.', 'prime-cache' ) . $sync_note,
				'varnish'         => __( 'Varnish cache purge request sent.', 'prime-cache' ),
				'sucuri'          => __( 'Sucuri firewall cache cleared successfully.', 'prime-cache' ),
				'cloudflare'      => __( 'Cloudflare cache purged successfully.', 'prime-cache' ),
				'sucuri_error'    => __( 'Failed to clear Sucuri cache. Check your API key.', 'prime-cache' ),
				'preload_started' => __( 'Cache preloading has been scheduled and will start shortly.', 'prime-cache' ),
				'preload_started_partial' => __( 'Cache preloading scheduled (default variant only). Cookie-specific variants will be generated on first visitor request.', 'prime-cache' ),
				'reset'           => __( 'All settings have been reset to defaults.', 'prime-cache' ),
			);
			$key   = sanitize_key( $_GET['pc_cleared'] );
			$msg   = $msgs[ $key ] ?? __( 'Cache cleared successfully.', 'prime-cache' );
			$class = ( 'sucuri_error' === $key ) ? 'notice-warning' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		if ( isset( $_GET['prime_cache_stats_reset'] ) && '1' === $_GET['prime_cache_stats_reset'] )
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Statistics have been reset.', 'prime-cache' ) . '</p></div>';

		// Activation warnings (advanced-cache.php / WP_CACHE issues).
		$act_warnings = get_transient( 'prime_cache_activation_warnings' );
		if ( ! empty( $act_warnings ) && is_array( $act_warnings ) ) {
			delete_transient( 'prime_cache_activation_warnings' );
			foreach ( $act_warnings as $warning ) {
				echo '<div class="notice notice-error is-dismissible"><p><strong>Prime Cache:</strong> ' . esc_html( $warning ) . '</p></div>';
			}
		}

		// Environment pre-check warnings from sanitize_settings().
		$env_warnings = get_transient( 'prime_cache_env_warnings' );
		if ( ! empty( $env_warnings ) && is_array( $env_warnings ) ) {
			delete_transient( 'prime_cache_env_warnings' );
			foreach ( $env_warnings as $warning ) {
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $warning ) . '</p></div>';
			}
		}
	}

	/* ── css ───────────────────────────────────────────────── */

	private function get_inline_css() {
		return '
/* vars */
:root{--c-pri:#6366f1;--c-pri-h:#4f46e5;--c-grn:#22c55e;--c-red:#ef4444;--c-amb:#f59e0b;--c-bg:#f8fafc;--c-surface:#fff;--c-border:#e5e7eb;--c-text:#0f172a;--c-muted:#94a3b8;--c-subtle:#f1f5f9;--radius:12px;--shadow:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04)}

/* layout */
.pc{display:grid;grid-template-columns:240px 1fr;min-height:calc(100vh - 32px);background:var(--c-bg);margin-left:-20px}

/* sidebar */
.pc-side{background:var(--c-surface);border-right:1px solid var(--c-border);display:flex;flex-direction:column;padding:0;position:sticky;top:32px;height:calc(100vh - 32px);overflow-y:auto}
.pc-side__brand{display:flex;align-items:center;gap:10px;padding:24px 20px 20px;border-bottom:1px solid var(--c-subtle)}
.pc-side__logo{font-size:24px;width:24px;height:24px;color:var(--c-pri);background:#ede9fe;padding:8px;border-radius:10px;line-height:24px}
.pc-side__name{font-size:16px;font-weight:700;letter-spacing:-.02em;color:var(--c-text)}
.pc-side__ver{font-size:11px;color:var(--c-muted);margin-left:auto}

/* nav */
.pc-nav{flex:1;padding:12px 10px}
.pc-nav__item{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;color:#64748b;text-decoration:none;font-size:13px;font-weight:500;transition:all .15s;margin-bottom:2px}
.pc-nav__item .dashicons{font-size:18px;width:18px;height:18px;line-height:18px;color:#94a3b8}
.pc-nav__item:hover{background:var(--c-subtle);color:var(--c-text)}
.pc-nav__item:hover .dashicons{color:#64748b}
.pc-nav__item--on{background:#ede9fe;color:var(--c-pri);font-weight:600}
.pc-nav__item--on .dashicons{color:var(--c-pri)}
.pc-nav__item--pro{opacity:0.45;cursor:default;pointer-events:none}
.pc-nav__item--pro:hover{background:transparent;color:#94a3b8}
.pc-pro-badge{display:inline-block;font-size:9px;font-weight:700;letter-spacing:.5px;background:linear-gradient(135deg,#6366f1,#a855f7);color:#fff;padding:1px 6px;border-radius:4px;margin-left:auto;line-height:16px}
.pc-card--pro{position:relative;opacity:0.45;pointer-events:none}
.pc-card--pro .pc-card__h .pc-pro-badge{margin-left:8px}
.pc-pro-wrap{position:relative;opacity:0.45;pointer-events:none;padding:4px 0}
.pc-pro-wrap .pc-pro-badge{margin-left:8px;vertical-align:middle}

/* power toggle in sidebar */
.pc-side__foot{padding:16px 20px;border-top:1px solid var(--c-subtle)}
.pc-pw{display:flex;align-items:center;gap:10px;cursor:pointer;user-select:none}
.pc-pw__sw{width:42px;height:24px;background:#cbd5e1;border-radius:12px;position:relative;transition:background .2s;flex-shrink:0}
.pc-pw__sw.is-on{background:var(--c-grn)}
.pc-pw__knob{position:absolute;top:3px;left:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.pc-pw__sw.is-on .pc-pw__knob{transform:translateX(18px)}
.pc-pw__label{font-size:12px;font-weight:500;color:#64748b}

/* main */
.pc-main{padding:32px 40px 48px;max-width:860px}
.pc-title{font-size:22px;font-weight:700;margin:0 0 24px;color:var(--c-text);letter-spacing:-.02em}

/* grids */
.pc-grid{display:grid;gap:14px;margin-bottom:20px}
.pc-grid--2{grid-template-columns:repeat(2,1fr)}
.pc-grid--3{grid-template-columns:repeat(3,1fr)}
.pc-grid--4{grid-template-columns:repeat(4,1fr)}

/* kpi cards */
.pc-kpi{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--radius);padding:18px;text-align:center;box-shadow:var(--shadow)}
.pc-kpi__val{display:block;font-size:24px;font-weight:800;line-height:1.1;color:var(--c-text)}
.pc-kpi__val--g{color:var(--c-grn)}
.pc-kpi__val--a{color:var(--c-amb)}
.pc-kpi__lbl{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--c-muted);margin-top:4px;display:block}

/* cards */
.pc-card{background:var(--c-surface);border:1px solid var(--c-border);border-radius:var(--radius);padding:22px 26px;margin-bottom:16px;box-shadow:var(--shadow)}
.pc-card__h{display:block;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--c-muted);margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--c-subtle)}
.pc-card__row{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
.pc-card__row .pc-card__h{margin:0;padding:0;border:none}

/* hit bar */
.pc-bar{height:20px;background:var(--c-subtle);border-radius:10px;overflow:hidden}
.pc-bar__fill{height:100%;background:linear-gradient(90deg,var(--c-pri),var(--c-grn));border-radius:10px;transition:width .5s;min-width:2px}
.pc-bar__info{display:flex;gap:20px;margin-top:8px;font-size:12px;color:var(--c-muted)}
.pc-bar__info b{color:var(--c-text)}

/* system status */
.pc-sys{display:grid;grid-template-columns:1fr 1fr;gap:2px 20px}
.pc-sys__row{display:flex;align-items:center;gap:8px;padding:7px 0}
.pc-sys__lbl{font-size:13px;color:#475569;flex:1}
.pc-sys__val{font-size:13px;font-weight:600;color:var(--c-text)}

/* dots */
.pc-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;display:inline-block}
.pc-dot--g{background:var(--c-grn)}
.pc-dot--r{background:var(--c-red)}
.pc-dot--m{background:var(--c-muted)}
.pc-dot--xl{width:14px;height:14px;box-shadow:0 0 0 4px rgba(34,197,94,.15)}
.pc-dot--in{vertical-align:middle;margin-left:3px}

/* toggle switch */
.pc-sw{display:flex;align-items:flex-start;gap:14px;cursor:pointer;padding:12px 0;border-bottom:1px solid var(--c-subtle)}
.pc-sw:last-of-type{border-bottom:none}
.pc-sw input{display:none}
.pc-sw__track{width:36px;height:20px;background:#cbd5e1;border-radius:10px;position:relative;transition:background .2s;flex-shrink:0;margin-top:2px}
.pc-sw__track::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 2px rgba(0,0,0,.1)}
.pc-sw input:checked+.pc-sw__track{background:var(--c-pri)}
.pc-sw input:checked+.pc-sw__track::after{transform:translateX(16px)}
.pc-sw__body{display:flex;flex-direction:column;gap:2px}
.pc-sw__body b{font-size:13px;font-weight:600;color:var(--c-text)}
.pc-sw__body small{font-size:12px;color:var(--c-muted);line-height:1.5}

/* badges */
.pc-badge{display:inline-block;font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px}
.pc-badge--g{background:#dcfce7;color:#15803d}
.pc-badge--r{background:#fee2e2;color:#b91c1c}
.pc-badge--m{background:var(--c-subtle);color:var(--c-muted)}
.pc-badge--b{background:#ede9fe;color:var(--c-pri)}

/* tag */
.pc-tag{display:inline-block;font-size:10px;font-weight:600;padding:1px 6px;border-radius:3px;background:#ede9fe;color:var(--c-pri);vertical-align:middle;margin-left:4px}

/* radio method selector */
.pc-radio{display:flex;align-items:flex-start;gap:12px;cursor:pointer;padding:14px 16px;border:1px solid var(--c-border);border-radius:10px;margin-bottom:8px;transition:border-color .15s,background .15s}
.pc-radio:hover{border-color:var(--c-pri)}
.pc-radio--on{border-color:var(--c-pri);background:#f5f3ff}
.pc-radio input{display:none}
.pc-radio__mark{width:20px;height:20px;border:2px solid #cbd5e1;border-radius:50%;flex-shrink:0;position:relative;margin-top:2px;transition:border-color .15s}
.pc-radio--on .pc-radio__mark{border-color:var(--c-pri)}
.pc-radio__mark::after{content:"";position:absolute;top:5px;left:5px;width:10px;height:10px;border-radius:50%;background:var(--c-pri);transform:scale(0);transition:transform .15s}
.pc-radio--on .pc-radio__mark::after{transform:scale(1)}
.pc-radio__body{display:flex;flex-direction:column;gap:2px}
.pc-radio__body b{font-size:13px;font-weight:600;color:var(--c-text)}
.pc-radio__body small{font-size:12px;color:var(--c-muted);line-height:1.5}
.pc-radio-sub{padding:4px 0 8px 32px;margin-bottom:8px}

/* fields */
.pc-field{padding:12px 0}.pc-field+.pc-field{border-top:1px solid var(--c-subtle)}
.pc-lbl{display:block;font-size:13px;font-weight:600;color:var(--c-text);margin-bottom:6px}
.pc-ta{width:100%;padding:10px 14px;border:1px solid var(--c-border);border-radius:8px;font-size:13px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;resize:vertical;box-sizing:border-box;transition:border-color .15s,box-shadow .15s;background:var(--c-subtle)}
.pc-ta::placeholder,.pc-inp::placeholder,input[type="text"].pc-ta::placeholder,input[type="url"].pc-ta::placeholder,input[type="email"].pc-ta::placeholder{color:#c0c5cc;opacity:1}
.pc-ta:focus{border-color:var(--c-pri);box-shadow:0 0 0 3px rgba(99,102,241,.12);outline:none;background:#fff}
.pc-help{font-size:12px;color:var(--c-muted);margin:6px 0 0;line-height:1.6}
.pc-help code{font-size:11px;padding:2px 6px;background:var(--c-subtle);border-radius:4px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.pc-meta{font-size:12px;color:var(--c-muted)}
.pc-link{color:var(--c-pri);text-decoration:none;font-weight:600}.pc-link:hover{text-decoration:underline}

/* lifespan */
.pc-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px}
.pc-chip{padding:6px 16px;border:1px solid var(--c-border);border-radius:20px;background:var(--c-surface);color:#475569;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s}
.pc-chip:hover{border-color:var(--c-pri);color:var(--c-pri)}
.pc-chip.is-on{background:var(--c-pri);border-color:var(--c-pri);color:#fff}
.pc-ls-row{display:flex;align-items:center;gap:8px}
.pc-inp{width:90px;padding:8px 12px;border:1px solid var(--c-border);border-radius:8px;font-size:14px;font-family:monospace;background:var(--c-subtle)}.pc-inp:focus{border-color:var(--c-pri);box-shadow:0 0 0 3px rgba(99,102,241,.12);outline:none;background:#fff}
.pc-sel{padding:8px 12px;border:1px solid var(--c-border);border-radius:8px;font-size:14px;background:var(--c-subtle);cursor:pointer}.pc-sel:focus{border-color:var(--c-pri);box-shadow:0 0 0 3px rgba(99,102,241,.12);outline:none;background:#fff}

/* buttons */
.pc-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:9px 20px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;cursor:pointer;transition:all .15s;border:none;box-sizing:border-box}
.pc-btn .dashicons{font-size:15px;width:15px;height:15px;line-height:15px}
.pc-btn--p{background:var(--c-pri);color:#fff}.pc-btn--p:hover{background:var(--c-pri-h);color:#fff}
.pc-btn--r{background:var(--c-red);color:#fff}.pc-btn--r:hover{background:#dc2626;color:#fff}
.pc-btn--o{background:transparent;color:var(--c-pri);border:1px solid var(--c-border)}.pc-btn--o:hover{background:var(--c-subtle);color:var(--c-pri)}
.pc-btn--sm{padding:6px 14px;font-size:12px;border-radius:6px}
.pc-btn--dis{opacity:.4;pointer-events:none}
.pc-actions{display:flex;align-items:center;gap:14px;margin-top:8px}
.pc-actions .button-primary{border-radius:8px;padding:6px 28px;height:42px;font-size:14px;background:var(--c-pri);border-color:var(--c-pri-h)}.pc-actions .button-primary:hover{background:var(--c-pri-h)}
.pc-actions .pc-btn{height:42px;box-sizing:border-box;line-height:24px}

/* object cache */
.pc-oc-banner{display:flex;align-items:center;gap:18px}
.pc-oc-banner__body{flex:1;display:flex;flex-direction:column;gap:3px}
.pc-oc-banner__body b{font-size:14px;color:var(--c-text)}
.pc-oc-banner__body small{font-size:12px;color:var(--c-muted)}
.pc-oc{display:flex;flex-direction:column;transition:border-color .2s,box-shadow .2s}
.pc-oc--on{border-color:var(--c-grn);box-shadow:0 0 0 1px var(--c-grn),var(--shadow)}
.pc-oc--off{opacity:.5}
.pc-oc__top{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.pc-oc__ico{font-size:18px;width:34px;height:34px;line-height:34px;text-align:center;background:#ede9fe;color:var(--c-pri);border-radius:8px}
.pc-oc--on .pc-oc__ico{background:#dcfce7;color:var(--c-grn)}
.pc-oc__desc{font-size:13px;color:#475569;margin:0 0 auto;padding-bottom:14px;line-height:1.5}
.pc-oc__foot{display:flex;align-items:center;justify-content:space-between;padding-top:14px;border-top:1px solid var(--c-subtle)}

/* heartbeat */
.pc-hb-location{padding:16px 0;border-bottom:1px solid var(--c-subtle)}.pc-hb-location:last-child{border-bottom:none;padding-bottom:0}
.pc-hb-location__head{display:flex;align-items:center;gap:8px;margin-bottom:4px}.pc-hb-location__head .dashicons{font-size:18px;width:18px;height:18px}
.pc-hb-controls{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.pc-hb-interval{display:flex;align-items:center;gap:6px}

/* responsive */
@media(max-width:960px){
	.pc{grid-template-columns:1fr}
	.pc-side{position:static;height:auto;flex-direction:row;flex-wrap:wrap;align-items:center;padding:12px 16px;gap:8px;border-right:none;border-bottom:1px solid var(--c-border)}
	.pc-side__brand{border:none;padding:0}
	.pc-nav{display:flex;flex-wrap:wrap;gap:4px;padding:0;flex:none;width:100%}
	.pc-nav__item{padding:6px 12px;font-size:12px}
	.pc-side__foot{border:none;padding:0;margin-left:auto}
	.pc-main{padding:20px 16px 32px}
	.pc-grid--2,.pc-grid--3,.pc-grid--4{grid-template-columns:1fr}
	.pc-sys{grid-template-columns:1fr}
}
';
	}
}
