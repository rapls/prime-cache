<?php
defined( 'ABSPATH' ) || exit;

class Prime_Cache_Admin_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_cf_dismiss' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy_addons_tab' ) );
		add_action( 'admin_notices', array( $this, 'show_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Send legacy "?page=prime-cache&tab=upgrade" requests to the new Pro Features
	 * submenu so bookmarked URLs keep working after the in-settings tab is retired.
	 */
	public function redirect_legacy_addons_tab() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['page'], $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL inspection.
			return;
		}
		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = sanitize_key( wp_unslash( $_GET['tab'] ) );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'prime-cache' !== $page || 'upgrade' !== $tab ) {
			return;
		}
		if ( prime_cache_is_pro() ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=prime-cache-pro-features' ) );
		exit;
	}

	public function handle_cf_dismiss() {
		if ( isset( $_GET['pc_dismiss_cf_alert'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'pc_dismiss_cf' ) && current_user_can( 'manage_options' ) ) {
			delete_option( 'prime_cache_cf_purge_failed' );
			wp_safe_redirect( remove_query_arg( array( 'pc_dismiss_cf_alert', '_wpnonce' ) ) );
			exit;
		}
	}

	public function add_menu() {
		add_menu_page( 'Prime Cache', 'Prime Cache', 'manage_options', 'prime-cache', array( $this, 'render_page' ), 'dashicons-performance', 80 );

		// "Pro Features" submenu — informational landing page for the optional
		// add-on. Hidden when the add-on is already active. The page contains no
		// saveable settings and no disabled feature controls; it is a static
		// description plus links to the add-on's external sales page.
		if ( ! prime_cache_is_pro() ) {
			add_submenu_page(
				'prime-cache',
				__( 'Pro Features', 'prime-cache' ),
				__( 'Pro Features', 'prime-cache' ) . ' <span class="pc-pro-menu-badge">PRO</span>',
				'manage_options',
				'prime-cache-pro-features',
				array( $this, 'render_pro_features_page' )
			);
		}
	}

	public function register_settings() {
		register_setting( 'prime_cache_settings_group', 'prime_cache_settings', array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
	}

	public function enqueue_assets( $h ) {
		if ( 'toplevel_page_prime-cache' !== $h && 'prime-cache_page_prime-cache-pro-features' !== $h ) return;
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
		// Track rejections so we can surface them in an admin notice — without
		// this the user thinks their carefully-crafted exclusion is in effect
		// while the drop-in actually has an empty (no-op) pattern.
		$reject_field_labels = array(
			'cache_reject_uri'      => __( 'Excluded URLs', 'prime-cache' ),
			'cache_reject_cookies'  => __( 'Excluded Cookies', 'prime-cache' ),
			'cache_reject_ua'       => __( 'Excluded User Agents', 'prime-cache' ),
			'cache_reject_referrer' => __( 'Excluded Referrers', 'prime-cache' ),
		);
		$rejected_regex_fields = array();
		foreach ( $reject_field_labels as $key => $label ) {
			$raw       = (string) ( $input[ $key ] ?? '' );
			$sanitized = $this->sanitize_regex_field( $raw );
			$s[ $key ]  = $sanitized;
			if ( '' !== trim( $raw ) && '' === $sanitized ) {
				$rejected_regex_fields[] = $label;
			}
		}
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
		$s['combine_mobile_only']   = ! empty( $input['combine_mobile_only'] );
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
		// Delay JS is mobile-only and writes a transformed cache variant. It needs
		// (a) cache_mobile=true so the drop-in actually caches mobile responses,
		// and (b) cache_mobile_separate=true so the mobile-transformed HTML doesn't
		// leak to desktop visitors. Without (a) the preload pass would warm a
		// mobile bucket the drop-in never writes to, wasting work.
		if ( $s['delay_js'] ) {
			$s['cache_mobile']          = true;
			$s['cache_mobile_separate'] = true;
		}
		$s['delay_js_timeout']      = isset( $input['delay_js_timeout'] ) ? max( 0, min( 30000, (int) $input['delay_js_timeout'] ) ) : 0;
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
		$s['preload_interval']      = isset( $input['preload_interval'] ) ? max( 1, min( 60, (int) $input['preload_interval'] ) ) : 2;
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
		// Secret key: not emitted as a hidden input (see hidden()), so it is
		// absent from $input unless its own tab is the one being saved. The field
		// also never pre-fills the stored secret, so an empty submission means
		// "keep the current key" — only a non-blank value replaces it.
		$s['sucuri_api_key']        = ( isset( $input['sucuri_api_key'] ) && '' !== trim( (string) $input['sucuri_api_key'] ) )
			? sanitize_text_field( $input['sucuri_api_key'] )
			: ( $old['sucuri_api_key'] ?? '' );
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
		$s['lazyload_skip_first']   = isset( $input['lazyload_skip_first'] ) ? max( 0, min( 10, (int) $input['lazyload_skip_first'] ) ) : 3;
		$s['lazyload_exclude']      = sanitize_textarea_field( $input['lazyload_exclude'] ?? '' );

		$s['cdn_enabled']           = ! empty( $input['cdn_enabled'] );
		$s['cdn_hostname']          = sanitize_textarea_field( $input['cdn_hostname'] ?? '' );
		$s['cdn_include_dirs']      = sanitize_text_field( $input['cdn_include_dirs'] ?? 'wp-content,wp-includes' );
		$s['cdn_exclude']           = sanitize_textarea_field( $input['cdn_exclude'] ?? '.php' );
		$s['cdn_relative']          = ! empty( $input['cdn_relative'] );
		$s['cloudflare_enabled']    = ! empty( $input['cloudflare_enabled'] );
		$s['cloudflare_email']      = sanitize_email( $input['cloudflare_email'] ?? '' );
		// Secret key: not emitted as a hidden input (see hidden()) and also
		// suppressed when PRIME_CACHE_CF_API_TOKEN is defined. The field never
		// pre-fills the stored secret, so it is absent when another tab is saved
		// and blank when its own tab is saved without a change — both mean "keep
		// the current key". Only a non-blank value replaces the stored secret.
		if ( isset( $input['cloudflare_api_key'] ) && '' !== trim( (string) $input['cloudflare_api_key'] ) && ! defined( 'PRIME_CACHE_CF_API_TOKEN' ) ) {
			$s['cloudflare_api_key'] = sanitize_text_field( $input['cloudflare_api_key'] );
		} else {
			$s['cloudflare_api_key'] = $old['cloudflare_api_key'] ?? '';
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
		$s['revisions_max']         = isset( $input['revisions_max'] ) ? max( 0, min( 100, (int) $input['revisions_max'] ) ) : 5;
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
		$s['inline_css_threshold']  = isset( $input['inline_css_threshold'] ) ? max( 0, min( 65536, (int) $input['inline_css_threshold'] ) ) : 8192;
		$s['local_analytics']       = ! empty( $input['local_analytics'] );
		$s['async_css_free']        = ! empty( $input['async_css_free'] );
		$s['local_jquery']          = ! empty( $input['local_jquery'] );
		$s['limit_dns_prefetch']    = ! empty( $input['limit_dns_prefetch'] );
		$s['preload_resources']     = sanitize_textarea_field( $input['preload_resources'] ?? '' );
		$s['speculation_rules']     = ! empty( $input['speculation_rules'] );
		$s['cache_404']             = ! empty( $input['cache_404'] );
		$s['cache_mixed_scheme']    = ! empty( $input['cache_mixed_scheme'] );
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

		// Reschedule DB cleanup cron if settings changed (add-on feature).
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

		// Add-on features are implemented by a separate add-on, not by this plugin.
		// When the add-on is not installed, force their options to off / empty
		// BEFORE the config file and .htaccess are written below, so a POST or
		// imported settings file can never leave add-on settings stored — or
		// written into the generated config / .htaccess — by the free plugin.
		//
		// The add-on advertises its presence via the `prime_cache_addon_active`
		// filter regardless of license state, so an installed-but-unlicensed
		// add-on keeps its saved settings (the add-on itself gates whether the
		// features run). With no add-on installed the filter stays false and the
		// lock below clears everything, leaving free-only behavior unchanged.
		if ( ! apply_filters( 'prime_cache_addon_active', false ) ) {
			$addon_bool_keys = array(
				'combine_css', 'combine_mobile_only', 'optimize_css_delivery',
				'remove_unused_css', 'async_css', 'critical_css_auto',
				'combine_js', 'combine_google_fonts', 'self_host_google_fonts',
				'preload_sitemap_enabled', 'preload_fonts', 'lcp_optimization',
				'speculation_rules', 'varnish_enabled', 'sucuri_enabled',
				'cloudflare_enabled', 'cdn_enabled', 'avif_enabled',
				'youtube_thumbnail', 'local_analytics', 'heartbeat_enabled',
				'db_auto_cleanup',
			);
			foreach ( $addon_bool_keys as $k ) {
				$s[ $k ] = false;
			}
			$addon_text_keys = array(
				'critical_css', 'ucss_safelist', 'preload_sitemap', 'lcp_excluded',
				'prefetch_dns', 'preconnect', 'preload_resources', 'varnish_ip',
				'sucuri_api_key', 'cloudflare_email', 'cloudflare_api_key',
				'cloudflare_zone_id', 'cdn_hostname',
			);
			foreach ( $addon_text_keys as $k ) {
				$s[ $k ] = '';
			}
		}

		// (Object cache extension validation lives in handle_object_cache_switch()
		// — that path uses Prime_Cache_Config::get_available_object_caches() to
		// only allow backends whose PHP extension is loaded. The settings form
		// does not submit prime_cache_settings[object_cache], so no sanitize
		// branch is needed here.)

		// Multisite: do not touch advanced-cache.php, config file, or .htaccess
		// page-cache rules. Page caching is not supported on multisite.
		if ( ! is_multisite() ) {
			$ac_result = Prime_Cache_Config::install_advanced_cache();
			if ( ! $ac_result && 'external' === Prime_Cache_Config::get_advanced_cache_owner() ) {
				$warnings[] = __( 'advanced-cache.php is managed by another plugin. Prime Cache page caching cannot be enabled until the other plugin is deactivated.', 'prime-cache' );
			}

			// Surface failures from the dropin config write — without this the DB
			// option is updated but the file the dropin reads stays stale, and the
			// site silently behaves as if the setting save did nothing. The most
			// common cause is a non-writable wp-content/prime-cache-config/ directory.
			if ( ! Prime_Cache_Config::write_config_file( $s ) ) {
				$warnings[] = __( 'Settings saved to the database, but the drop-in config file could not be written. Page caching may not reflect your changes until the file is writable. Check that wp-content/prime-cache-config/ exists and is writable by PHP.', 'prime-cache' );
			}

			// Only touch .htaccess when the toggle or any rule-affecting setting
			// actually changed. Saving unrelated tabs (e.g. media, preload) no
			// longer rewrites .htaccess.
			$htaccess_keys = array(
				'htaccess_enabled', 'cache_enabled', 'cache_mobile', 'cache_mobile_separate',
				'cache_logged_in', 'cache_mixed_scheme',
				'gzip_compression', 'brotli_compression',
				'browser_cache', 'browser_cache_css_js', 'browser_cache_images',
				'browser_cache_fonts', 'browser_cache_html', 'cache_control_immutable',
				'hsts_enabled', 'hsts_max_age', 'security_headers',
				'webp_enabled', 'avif_enabled', 'img_conversion_enabled', 'img_delivery_method',
				'cache_vary_cookies', 'cache_query_strings',
				'cache_reject_uri', 'cache_reject_ua', 'cache_reject_cookies', 'cache_reject_referrer',
			);
			$htaccess_was_on = ! empty( $old['htaccess_enabled'] );
			$htaccess_now_on = ! empty( $s['htaccess_enabled'] );
			if ( $htaccess_now_on ) {
				$rules_dirty = false;
				foreach ( $htaccess_keys as $k ) {
					if ( ( $old[ $k ] ?? null ) !== ( $s[ $k ] ?? null ) ) {
						$rules_dirty = true;
						break;
					}
				}
				// Surface a non-writable .htaccess. Without this the DB option is
				// saved but the rewrite rules the fast-path relies on are never
				// written, and the admin still sees a "settings saved" message.
				if ( $rules_dirty && ! Prime_Cache_Htaccess::add_rules( $s ) ) {
					$warnings[] = __( 'Settings saved, but the .htaccess optimization rules could not be written. Check that the .htaccess file in your site root exists and is writable by PHP, or turn off .htaccess Optimization on the Page Cache tab.', 'prime-cache' );
				}
			} elseif ( $htaccess_was_on && ! Prime_Cache_Htaccess::remove_rules() ) {
				$warnings[] = __( 'Settings saved, but the Prime Cache rules could not be removed from .htaccess. Check that the .htaccess file in your site root is writable by PHP, or remove the block between "# BEGIN Prime Cache" and "# END Prime Cache" manually.', 'prime-cache' );
			}
		}

		// Set transient AFTER all warnings are collected (including install_advanced_cache result).
		if ( ! empty( $warnings ) ) {
			set_transient( 'prime_cache_env_warnings', $warnings, 60 );
		}

		// Schedule immediate async fetch of local analytics files on save (add-on only).
		if ( prime_cache_is_pro() && ! empty( $s['local_analytics'] ) ) {
			if ( ! wp_next_scheduled( 'prime_cache_refresh_local_analytics' ) ) {
				$ok = wp_schedule_single_event( time(), 'prime_cache_refresh_local_analytics' );
				if ( false === $ok && ! empty( $s['debug_log'] ) && class_exists( 'Prime_Cache_File_Optimizer' ) ) {
					Prime_Cache_File_Optimizer::debug_log( 'LOCAL ANALYTICS REFRESH SCHEDULE FAILED' );
				}
			}
		}

		// Trigger cache preloading after settings save when enabled. Use the
		// dedicated request() helper instead of firing prime_cache_after_purge_all:
		// (1) no purge actually happened — reusing the purge action would log a
		//     false "PURGE ALL" entry in the debug log; (2) on a false→true toggle
		//     of preload_enabled, Prime_Cache_Preload's listener was not registered
		//     at bootstrap, so the action would no-op. request() schedules the cron
		//     event directly, which fires on the next request after new settings load.
		// Pass $s explicitly: the option hasn't been saved yet, so the memoized
		// settings cache still reflects the OLD values and request()'s precondition
		// check would falsely refuse a false→true toggle.
		if ( ! empty( $s['preload_enabled'] ) && ! empty( $s['cache_enabled'] ) ) {
			if ( ! Prime_Cache_Preload::request( $s ) ) {
				// wp_schedule_single_event was rejected (e.g. DISABLE_WP_CRON,
				// pre_schedule_event filter). Stack alongside any existing config-
				// write warning so both surface in the same admin notice.
				$existing  = get_transient( 'prime_cache_env_warnings' );
				$existing  = is_array( $existing ) ? $existing : array();
				$existing[] = __( 'Settings saved, but the preload event could not be scheduled. Another plugin or filter may be blocking WP-Cron event registration. Run preload manually with WP-CLI if needed.', 'prime-cache' );
				set_transient( 'prime_cache_env_warnings', $existing, 60 );
			}
		}

		// Surface dropped exclusion patterns (rejected by sanitize_regex_field
		// for length > 512 or regex compile failure). Whitespace-only inputs are
		// treated as deliberate clears and don't trigger a warning here.
		if ( ! empty( $rejected_regex_fields ) ) {
			$existing = get_transient( 'prime_cache_env_warnings' );
			$existing = is_array( $existing ) ? $existing : array();
			$existing[] = sprintf(
				/* translators: %s: comma-separated list of exclusion field labels */
				__( 'These exclusion patterns were dropped because the value was too long (over 512 chars) or did not compile as a regex: %s. Saved value is empty for these fields — open the field, fix the pattern, and save again.', 'prime-cache' ),
				implode( ', ', $rejected_regex_fields )
			);
			set_transient( 'prime_cache_env_warnings', $existing, 60 );
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
	 * Sanitize a pattern field using safe wildcard syntax.
	 *
	 * Users enter pipe-separated patterns where * is a wildcard.
	 * All regex metacharacters are escaped — only * (→ .*) and | (OR)
	 * have special meaning. This eliminates ReDoS risk entirely because
	 * the generated regex contains no quantifier nesting, groups, or
	 * backreferences.
	 *
	 * Input:  /cart*|/checkout|my-account
	 * Output: /cart.*|/checkout|my\-account
	 *
	 * The output is stored in the config and used in preg_match() by
	 * the dropin and cache tests.
	 */
	private function sanitize_regex_field( $value ) {
		$value = sanitize_textarea_field( $value );
		if ( empty( $value ) ) {
			return '';
		}
		// Length limit.
		if ( strlen( $value ) > 512 ) {
			return '';
		}
		// Strip control characters.
		$value = preg_replace( '#[\x00-\x1F\x7F]#', '', $value );
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		// Split by pipe, process each pattern independently.
		$parts = explode( '|', $value );
		$safe  = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			// Migration: strip regex escape sequences from legacy patterns.
			// e.g. example\.com → example.com, /path\-name → /path-name
			$part = stripslashes( $part );
			// Collapse runs of `*` so adjacent wildcards cannot expand to .*.*.*
			// (which compiles fine but causes polynomial backtracking on long inputs).
			$part = preg_replace( '#\*+#', '*', $part );
			// Split on * (wildcard), escape each segment, rejoin with .*
			$segments = explode( '*', $part );
			$escaped  = array_map( function( $seg ) {
				return preg_quote( $seg, '#' );
			}, $segments );
			$safe[] = implode( '.*', $escaped );
		}

		if ( empty( $safe ) ) {
			return '';
		}

		$result = implode( '|', $safe );

		// Final validation: ensure the generated regex compiles.
		if ( false === @preg_match( '#' . $result . '#', '' ) ) {
			return '';
		}

		return $result;
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

	/**
	 * Output non-visible settings as hidden inputs so a per-tab form submit does
	 * not drop options that belong to other tabs. Public so an add-on can reuse
	 * it when rendering its own settings tabs via prime_cache_render_admin_tab.
	 */
	public function hidden( $settings, $visible ) {
		// Never carry secret values (API keys) across tabs as hidden inputs —
		// they would otherwise be written into the form HTML of unrelated tabs.
		// When a secret field is not part of the submitted form, sanitize_settings()
		// preserves its stored value instead of clearing it.
		$secret_keys = array( 'cloudflare_api_key', 'sucuri_api_key' );
		foreach ( $settings as $k => $v ) {
			if ( in_array( $k, $visible, true ) ) continue;
			if ( in_array( $k, $secret_keys, true ) ) continue;
			$value = is_bool( $v ) ? ( $v ? '1' : '0' ) : $v;
			printf( '<input type="hidden" name="prime_cache_settings[%s]" value="%s">', esc_attr( $k ), esc_attr( $value ) );
		}
	}

	/* ── router ───────────────────────────────────────────── */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab routing for the settings screen; value is sanitized with sanitize_key().
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
		$settings = prime_cache_get_settings();
		$on = ! empty( $settings['cache_enabled'] );

		$tabs = array(
			'dashboard'     => array( 'dashicons-dashboard',       __( 'Dashboard', 'prime-cache' ) ),
			'page-cache'    => array( 'dashicons-admin-page',     __( 'Page Cache', 'prime-cache' ) ),
			'file-opt'      => array( 'dashicons-editor-code',     __( 'File Optimization', 'prime-cache' ) ),
			'media'         => array( 'dashicons-format-image',     __( 'Media', 'prime-cache' ) ),
			'preload'       => array( 'dashicons-controls-forward', __( 'Preload', 'prime-cache' ) ),
			'cache-control' => array( 'dashicons-admin-generic',  __( 'Cache Control', 'prime-cache' ) ),
			'auto-purge'    => array( 'dashicons-update',          __( 'Auto Purge', 'prime-cache' ) ),
			'exclusions'    => array( 'dashicons-dismiss',        __( 'Exclusion Rules', 'prime-cache' ) ),
			'tools'         => array( 'dashicons-admin-tools',    __( 'Tools', 'prime-cache' ) ),
		);
		$tabs = apply_filters( 'prime_cache_admin_tabs', $tabs );
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
						$cls = ( $slug === $tab ) ? ' pc-nav__item--on' : '';
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=prime-cache&tab=' . $slug ) ); ?>" class="pc-nav__item<?php echo esc_attr( $cls ); ?>">
						<span class="dashicons <?php echo esc_attr( $t[0] ); ?>"></span><?php echo esc_html( $t[1] ); ?>
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
					case 'page-cache':    $this->tab_page( $settings, $on ); break;
					case 'file-opt':      $this->tab_file_opt( $settings ); break;
					case 'media':         $this->tab_media( $settings ); break;
					case 'preload':       $this->tab_preload( $settings ); break;
					case 'cache-control': $this->tab_control( $settings ); break;
					case 'auto-purge':    $this->tab_auto_purge( $settings ); break;
					case 'exclusions':    $this->tab_exclusions( $settings ); break;
					case 'tools':         $this->tab_tools( $settings ); break;
					case 'dashboard':     $this->tab_dashboard( $settings ); break;
					default:
						// Tabs contributed by an add-on via the prime_cache_admin_tabs filter
						// are rendered by the add-on (the free plugin ships no body for them).
						// If this is a registered add-on tab, let the add-on render it; otherwise
						// fall back to the dashboard (e.g. a bookmarked add-on tab URL with no
						// add-on active).
						if ( isset( $tabs[ $tab ] ) && false !== has_action( 'prime_cache_render_admin_tab' ) ) {
							do_action( 'prime_cache_render_admin_tab', $tab, $settings, $this );
						} else {
							$this->tab_dashboard( $settings );
						}
						break;
				}
				?>
			</main>
		</div>
		<?php
	}

	/* ── Pro Features landing page ──────────────────────── */

	/**
	 * Render the dedicated "Pro Features" submenu page.
	 *
	 * Purely informational landing page: a hero, a free/pro growth-steps table,
	 * result-based value cards, a recommended-for list, and a final CTA. No
	 * settings are saved here and no feature controls (toggles, inputs) appear,
	 * so this page does not introduce Trialware patterns. External purchase
	 * links are limited to two well-marked CTAs (hero and footer); every other
	 * upsell entry point in the admin links here internally.
	 */
	public function render_pro_features_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prime-cache' ) );
		}

		$buy_url = 'https://raplsworks.com/prime-cache-pro/';
		?>
		<div class="wrap pc-admin pc-pro-features-page">

			<section class="pc-pro-hero">
				<span class="pc-pro-eyebrow"><?php esc_html_e( 'Prime Cache Pro', 'prime-cache' ); ?></span>
				<h1><?php esc_html_e( 'Go beyond page caching.', 'prime-cache' ); ?></h1>
				<p><?php esc_html_e( 'Prime Cache Free covers the essentials: page cache, browser cache, minification, lazy loading, WebP, and preload.', 'prime-cache' ); ?></p>
				<p><?php esc_html_e( 'Prime Cache Pro adds advanced optimization for production sites — Critical CSS, unused CSS cleanup, object cache, AVIF, external cache purge, sitemap preload, and database cleanup.', 'prime-cache' ); ?></p>
				<p>
					<a class="pc-pro-cta" href="<?php echo esc_url( $buy_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Get Prime Cache Pro', 'prime-cache' ); ?>
					</a>
				</p>
			</section>

			<section class="pc-pro-section">
				<h2 class="pc-pro-section__h"><?php esc_html_e( 'Free handles the foundation. Pro handles the bottlenecks.', 'prime-cache' ); ?></h2>
				<table class="pc-pro-steps">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Prime Cache Free', 'prime-cache' ); ?></th>
							<th><?php esc_html_e( 'Prime Cache Pro', 'prime-cache' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr><td><?php esc_html_e( 'Page cache', 'prime-cache' ); ?></td><td><?php esc_html_e( 'Object cache', 'prime-cache' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Browser cache', 'prime-cache' ); ?></td><td><?php esc_html_e( 'Critical CSS', 'prime-cache' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Minification', 'prime-cache' ); ?></td><td><?php esc_html_e( 'Remove unused CSS', 'prime-cache' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'WebP conversion', 'prime-cache' ); ?></td><td><?php esc_html_e( 'AVIF conversion', 'prime-cache' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Basic preload', 'prime-cache' ); ?></td><td><?php esc_html_e( 'Sitemap and resource preload', 'prime-cache' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Manual purge', 'prime-cache' ); ?></td><td><?php esc_html_e( 'Cloudflare, Sucuri, and Varnish purge', 'prime-cache' ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Basic tools', 'prime-cache' ); ?></td><td><?php esc_html_e( 'Scheduled database cleanup', 'prime-cache' ); ?></td></tr>
					</tbody>
				</table>
			</section>

			<section class="pc-pro-section">
				<h2 class="pc-pro-section__h"><?php esc_html_e( 'What Pro adds, by outcome', 'prime-cache' ); ?></h2>
				<div class="pc-pro-card-grid">
					<div class="pc-pro-card">
						<h3><?php esc_html_e( 'Improve first paint', 'prime-cache' ); ?></h3>
						<p><?php esc_html_e( 'Generate Critical CSS and optimize CSS delivery for above-the-fold rendering.', 'prime-cache' ); ?></p>
					</div>
					<div class="pc-pro-card">
						<h3><?php esc_html_e( 'Reduce unused weight', 'prime-cache' ); ?></h3>
						<p><?php esc_html_e( 'Remove unused CSS so each page loads less unnecessary stylesheet code.', 'prime-cache' ); ?></p>
					</div>
					<div class="pc-pro-card">
						<h3><?php esc_html_e( 'Keep external caches in sync', 'prime-cache' ); ?></h3>
						<p><?php esc_html_e( 'Automatically purge Cloudflare, Sucuri, and Varnish when content changes.', 'prime-cache' ); ?></p>
					</div>
					<div class="pc-pro-card">
						<h3><?php esc_html_e( 'Go beyond WebP', 'prime-cache' ); ?></h3>
						<p><?php esc_html_e( 'Add AVIF conversion for even smaller modern image delivery.', 'prime-cache' ); ?></p>
					</div>
					<div class="pc-pro-card">
						<h3><?php esc_html_e( 'Warm important pages automatically', 'prime-cache' ); ?></h3>
						<p><?php esc_html_e( 'Use sitemap and resource preloading to prepare key pages before visitors arrive.', 'prime-cache' ); ?></p>
					</div>
					<div class="pc-pro-card">
						<h3><?php esc_html_e( 'Clean long-running sites', 'prime-cache' ); ?></h3>
						<p><?php esc_html_e( 'Schedule database cleanup for revisions, transients, expired data, and overhead.', 'prime-cache' ); ?></p>
					</div>
					<div class="pc-pro-card">
						<h3><?php esc_html_e( 'Add persistent object cache', 'prime-cache' ); ?></h3>
						<p><?php esc_html_e( 'Use Redis, Memcached, or APCu for dynamic workloads and admin-heavy sites.', 'prime-cache' ); ?></p>
					</div>
				</div>
			</section>

			<section class="pc-pro-section">
				<h2 class="pc-pro-section__h"><?php esc_html_e( 'Recommended for', 'prime-cache' ); ?></h2>
				<ul class="pc-pro-list">
					<li><?php esc_html_e( 'Sites pushing for higher Core Web Vitals', 'prime-cache' ); ?></li>
					<li><?php esc_html_e( 'Sites running Cloudflare, Sucuri, or Varnish', 'prime-cache' ); ?></li>
					<li><?php esc_html_e( 'Long-running sites with database overhead', 'prime-cache' ); ?></li>
					<li><?php esc_html_e( 'Image-heavy sites that want AVIF on top of WebP', 'prime-cache' ); ?></li>
					<li><?php esc_html_e( 'Production workflows that need automated purging and preloading', 'prime-cache' ); ?></li>
					<li><?php esc_html_e( 'Servers with Redis, Memcached, or APCu available', 'prime-cache' ); ?></li>
				</ul>
			</section>

			<section class="pc-pro-footer-cta">
				<h2><?php esc_html_e( 'Ready to go beyond page caching?', 'prime-cache' ); ?></h2>
				<p>
					<a class="pc-pro-cta" href="<?php echo esc_url( $buy_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Get Prime Cache Pro', 'prime-cache' ); ?>
					</a>
				</p>
			</section>

		</div>
		<?php
	}

	/* ── tab: dashboard ──────────────────────────────────── */

	private function tab_dashboard( $settings ) {
		$hs = $this->get_hit_stats();
		$st = $this->get_cache_stats();
		$sys = $this->get_system_status();
		$total = $hs['hit'] + $hs['miss'];
		$rate  = $total > 0 ? round( ( $hs['hit'] / $total ) * 100, 1 ) : 0;
		// Detect fast-path serving (htaccess or Xアクセラレータ) where PHP-based
		// hit stats are not recorded because pages are served without PHP.
		$fast_path = ! empty( $settings['htaccess_enabled'] ) && Prime_Cache_Htaccess::has_rules();
		$stats_limited = $fast_path && 0 === $hs['hit'] && $st['files'] > 0;
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
			<?php if ( $stats_limited ) : ?>
			<div class="pc-kpi"><span class="pc-kpi__val pc-kpi__val--g" style="font-size:16px">✓ <?php esc_html_e( 'Active', 'prime-cache' ); ?></span><span class="pc-kpi__lbl"><?php esc_html_e( 'Cache Status', 'prime-cache' ); ?></span></div>
			<?php else : ?>
			<div class="pc-kpi"><span class="pc-kpi__val"><?php echo esc_html( $rate ); ?>%</span><span class="pc-kpi__lbl"><?php esc_html_e( 'Hit Rate', 'prime-cache' ); ?></span></div>
			<?php endif; ?>
			<div class="pc-kpi"><span class="pc-kpi__val pc-kpi__val--g"><?php echo esc_html( number_format( $hs['hit'] ) ); ?></span><span class="pc-kpi__lbl">HIT</span></div>
			<div class="pc-kpi"><span class="pc-kpi__val pc-kpi__val--a"><?php echo esc_html( number_format( $hs['miss'] ) ); ?></span><span class="pc-kpi__lbl">MISS</span></div>
			<div class="pc-kpi"><span class="pc-kpi__val"><?php echo esc_html( number_format( $st['files'] ) ); ?></span><span class="pc-kpi__lbl"><?php esc_html_e( 'Pages', 'prime-cache' ); ?></span></div>
		</div>

		<!-- Hit Rate Bar -->
		<div class="pc-card">
			<div class="pc-card__row">
				<span class="pc-card__h"><?php esc_html_e( 'Cache Hit Rate', 'prime-cache' ); ?></span>
				<div style="display:flex;align-items:center;gap:12px">
					<span class="pc-meta"><?php
					if ( $hs['since'] ) {
						/* translators: %s: date when stats tracking started (e.g. "2024/01/15 09:30") */
						printf( esc_html__( 'Since: %s', 'prime-cache' ), esc_html( wp_date( 'Y/m/d H:i', $hs['since'] ) ) );
					}
					?></span>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=prime-cache&prime_cache_reset_stats=1' ), 'prime_cache_reset_stats' ) ); ?>" class="pc-btn pc-btn--o pc-btn--sm" style="font-size:11px;padding:3px 10px" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Reset all hit/miss statistics to zero?', 'prime-cache' ) ) ); ?>)"><span class="dashicons dashicons-image-rotate" style="font-size:13px;width:13px;height:13px;line-height:13px"></span><?php esc_html_e( 'Reset', 'prime-cache' ); ?></a>
				</div>
			</div>
			<?php if ( $stats_limited ) : ?>
			<div style="display:flex;align-items:center;gap:8px;padding:10px 0">
				<span class="dashicons dashicons-yes-alt" style="color:#22c55e;font-size:20px"></span>
				<span style="font-size:13px;color:#475569"><?php
				/* translators: %s: number of cached pages */
				echo esc_html( sprintf( __( 'Cache is serving %s pages via .htaccess fast-path (PHP-free). Hit/miss stats are not tracked in this mode because pages are served directly by Apache without running PHP.', 'prime-cache' ), number_format( $st['files'] ) ) );
				?></span>
			</div>
			<?php else : ?>
			<div class="pc-bar"><div class="pc-bar__fill" style="width:<?php echo esc_attr( $rate ); ?>%"></div></div>
			<?php endif; ?>
			<div class="pc-bar__info">
				<span><?php esc_html_e( 'Total', 'prime-cache' ); ?>: <b><?php echo esc_html( number_format( $total ) ); ?></b></span>
				<span><?php esc_html_e( 'Size', 'prime-cache' ); ?>: <b><?php echo esc_html( $this->fmt( $st['size'] ) ); ?></b></span>
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
				<?php if ( 'off' !== $oc && 'external' !== $oc && 'broken' !== $oc ) : ?>
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
					<div class="pc-sys__row"><span class="pc-dot pc-dot--<?php echo esc_attr( $ok ? 'g' : ( $is_ext ? 'a' : 'r' ) ); ?>"></span><span class="pc-sys__lbl"><?php echo esc_html( $chk[1] ); ?></span><span class="pc-sys__val"><?php echo esc_html( $is_ext ? __( 'External', 'prime-cache' ) : ( $ok ? __( 'Active', 'prime-cache' ) : __( 'Inactive', 'prime-cache' ) ) ); ?></span></div>
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
						array( $settings['minify_html'] || $settings['minify_css'] || $settings['minify_js'], __( 'File Optimization', 'prime-cache' ) ),
						array( $settings['defer_js'] || $settings['delay_js'], __( 'JS Optimization', 'prime-cache' ) ),
						array( $settings['lazyload_images'], __( 'Lazy Load', 'prime-cache' ) ),
						array( $settings['preload_enabled'], __( 'Cache Preload', 'prime-cache' ) ),
						array( $settings['htaccess_enabled'], '.htaccess' ),
						array( $settings['browser_cache'], __( 'Browser Cache', 'prime-cache' ) ),
					);
					// The optional add-on adds its feature status entries (Object Cache, CDN, WebP, Heartbeat, etc.).
					$features = apply_filters( 'prime_cache_dashboard_features', $features, $settings );
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
					'Server'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? preg_replace( '#/.*#', '', sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : 'Unknown',
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
		// One discreet informational card pointing at the Pro Features submenu.
		// Sits at the very end of the dashboard so it cannot interrupt KPI or
		// system blocks; hidden when the optional add-on is active; links only
		// to the in-admin Pro Features page (no external purchase URL).
		$this->render_pro_dashboard_card();
	}

	/**
	 * One-card Pro Features pointer shown at the bottom of the dashboard.
	 *
	 * Hidden when the optional add-on is active, gated on manage_options. Pure
	 * information: no pricing, no countdown, no Unlock/Locked language, and the
	 * only link is the internal Pro Features submenu.
	 */
	private function render_pro_dashboard_card() {
		if ( prime_cache_is_pro() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$href = admin_url( 'admin.php?page=prime-cache-pro-features' );
		?>
		<div class="pc-card pc-pro-dashboard-card">
			<h3 class="pc-pro-dashboard-card__h"><?php esc_html_e( 'Looking for advanced optimization?', 'prime-cache' ); ?></h3>
			<p class="pc-pro-dashboard-card__body"><?php esc_html_e( 'Prime Cache Free covers the essentials. Prime Cache Pro adds advanced CSS optimization, object cache, AVIF, external cache purge, and database cleanup for production sites.', 'prime-cache' ); ?></p>
			<p class="pc-pro-dashboard-card__cta">
				<a class="pc-pro-cta pc-pro-cta--sm" href="<?php echo esc_url( $href ); ?>">
					<?php esc_html_e( 'View Pro Features', 'prime-cache' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * One informational card placed at the very end of a related settings tab,
	 * describing the optional add-on's matching higher-tier features. Hidden when
	 * the add-on is active or the viewer lacks manage_options. Always links
	 * internally to the Pro Features submenu — no external purchase URL, no
	 * pricing, no countdown, no disabled controls.
	 *
	 * Call sites pass already-translated $args['title'] and $args['body'] so
	 * each card's strings are extractable via __()/esc_html__() at the call site.
	 *
	 * @param array $args Required: 'title' (string), 'body' (string).
	 */
	private function render_pro_context_card( $args ) {
		if ( prime_cache_is_pro() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$title = isset( $args['title'] ) ? (string) $args['title'] : '';
		$body  = isset( $args['body'] ) ? (string) $args['body'] : '';
		if ( '' === $title && '' === $body ) {
			return;
		}
		$href = admin_url( 'admin.php?page=prime-cache-pro-features' );
		?>
		<div class="pc-card pc-pro-context-card">
			<h3 class="pc-pro-context-card__h"><?php echo esc_html( $title ); ?></h3>
			<p class="pc-pro-context-card__body"><?php echo esc_html( $body ); ?></p>
			<p class="pc-pro-context-card__cta">
				<a class="pc-pro-cta pc-pro-cta--sm" href="<?php echo esc_url( $href ); ?>">
					<?php esc_html_e( 'View Pro Features', 'prime-cache' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/* ── tab: page cache ──────────────────────────────────── */

	private function tab_page( $settings, $on ) {
		$purge = wp_nonce_url( admin_url( 'admin.php?prime_cache_purge=all' ), 'prime_cache_purge' );
		?>
		<h2 class="pc-title"><?php esc_html_e( 'Page Cache', 'prime-cache' ); ?></h2>

		<form method="post" action="options.php" id="pc-settings-form">
			<?php settings_fields( 'prime_cache_settings_group' ); ?>
			<input type="hidden" name="prime_cache_settings[cache_enabled]" value="<?php echo $on ? '1' : '0'; ?>" id="pc-ei">
			<?php
			$page_vis = array( 'cache_enabled','cache_mobile','cache_mobile_separate','cache_logged_in','cache_404','cache_mixed_scheme','gzip_compression','htaccess_enabled','browser_cache','browser_cache_css_js','browser_cache_images','browser_cache_fonts','browser_cache_html','brotli_compression','cache_control_immutable','cache_lifespan','cache_footprint' );
			if ( prime_cache_is_pro() ) {
				$page_vis = array_merge( $page_vis, array( 'varnish_enabled','varnish_ip','sucuri_enabled','sucuri_api_key' ) );
			}
			$this->hidden( $settings, $page_vis );
			?>

			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'General Settings', 'prime-cache' ); ?></span>
				<?php
				$tg = array(
					array( 'cache_mobile',          __( 'Mobile Cache','prime-cache' ),           __( 'Serve cached pages to smartphones and tablets. When disabled, WordPress dynamically generates every page for mobile visitors.','prime-cache' ) ),
					array( 'cache_mobile_separate',  __( 'Separate Mobile Cache','prime-cache' ),  __( 'For themes that output different HTML for desktop and mobile. Maintains separate cache files per device type. Not needed for responsive themes.','prime-cache' ) ),
					array( 'cache_logged_in',        __( 'Logged-in User Cache','prime-cache' ),   __( 'Serve cached pages to logged-in users. SECURITY WARNING: all logged-in users and anonymous visitors share one cached copy of each page, so a page generated for one user — including their admin bar, nonces, or any user-specific content — can be served to other users and to the public. Only enable on sites that serve byte-for-byte identical content to everyone (e.g. membership sites with no per-user output).','prime-cache' ) ),
					array( 'gzip_compression',       __( 'Gzip Compression','prime-cache' ),       __( 'Pre-compress cache files with gzip. Supported browsers receive the compressed version, reducing transfer size by 60-80%. Recommended for most environments.','prime-cache' ) ),
					array( 'brotli_compression',     __( 'Brotli Compression','prime-cache' ),     __( 'Enable Brotli compression via mod_brotli (Apache 2.4+). Brotli achieves 15-25% better compression than gzip. Requires mod_brotli and .htaccess Optimization to be enabled. Falls back to gzip via mod_deflate if Brotli is unavailable.','prime-cache' ) ),
					array( 'htaccess_enabled',       __( '.htaccess Optimization','prime-cache' ), __( 'Write optimization rules to .htaccess. Apache serves cached files directly without invoking PHP, significantly improving response time. Also enables mod_deflate compression and ETag removal. mod_expires and Cache-Control headers are added when "Enable Browser Cache Headers" is also on. No effect on Nginx.','prime-cache' ) ),
					array( 'cache_404',              __( 'Cache 404 Pages','prime-cache' ),        __( 'Cache 404 (Not Found) pages. Reduces server load from repeated requests to non-existent URLs. The cached 404 page is served with proper 404 HTTP status code via the PHP drop-in. Note: .htaccess Optimization does not serve cached 404 pages (they always go through PHP to ensure the correct status code).','prime-cache' ) ),
					array( 'cache_mixed_scheme',     __( 'Mixed HTTP/HTTPS Site','prime-cache' ),  __( 'Enable only if your site intentionally serves both http:// and https:// versions of the same URL. When disabled (recommended), the cache scheme follows your Site Address setting — safe and tamper-proof. Reverse proxies are auto-detected. When enabled, the drop-in falls back to per-request header detection; sites behind a reverse proxy must also define PRIME_CACHE_TRUST_X_FORWARDED_PROTO in wp-config.php.','prime-cache' ) ),
					array( 'cache_footprint',        __( 'Cache Footprint','prime-cache' ),        __( 'Append an HTML comment with cache generation time to the source. Useful for verifying cache behavior via "View Source". Can be disabled in production.','prime-cache' ) ),
				);
				foreach ( $tg as $t ) : ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[<?php echo esc_attr( $t[0] ); ?>]" value="1" <?php checked( $settings[ $t[0] ] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php echo esc_html( $t[1] ); ?></b><small><?php echo esc_html( $t[2] ); ?></small></span></label>
				<?php endforeach; ?>
				<?php if ( ! empty( $settings['htaccess_enabled'] ) && Prime_Cache_Htaccess::has_rules() ) : ?><span class="pc-badge pc-badge--g"><?php esc_html_e( '.htaccess Active','prime-cache' ); ?></span>
				<?php elseif ( ! empty( $settings['htaccess_enabled'] ) && ! Prime_Cache_Htaccess::is_writable() ) : ?><span class="pc-badge pc-badge--r"><?php esc_html_e( '.htaccess Not Writable','prime-cache' ); ?></span><?php endif; ?>
			</div>

			<?php do_action( 'prime_cache_page_settings_after_general', $settings ); ?>

			<!-- Browser Cache -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Browser Cache', 'prime-cache' ); ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[browser_cache]" value="1" <?php checked( $settings['browser_cache'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Enable Browser Cache Headers', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Add Cache-Control and Expires headers to cached responses, both using the lifetimes configured below. HTML Cache-Control is applied by the PHP drop-in. CSS, JS, image, font, and Expires headers require .htaccess Optimization to be enabled.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[cache_control_immutable]" value="1" <?php checked( $settings['cache_control_immutable'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Immutable Cache-Control', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Add "immutable" directive to Cache-Control for CSS, JS, images, and fonts. Tells the browser the file will never change during its lifetime, preventing conditional revalidation requests (304 checks).', 'prime-cache' ); ?></small></span></label>

				<?php
				$bc_fields = array(
					array( 'browser_cache_css_js',  __( 'CSS & JS Cache Lifetime', 'prime-cache' ), '' ),
					array( 'browser_cache_images',  __( 'Images / Media / Documents Cache Lifetime', 'prime-cache' ), '' ),
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
				<p class="pc-help"><?php esc_html_e( 'How long until cache files are automatically discarded. Expired caches are regenerated on the next visit and cleaned up hourly by WP-Cron. Set to 0 for unlimited — caches then persist until invalidated by an enabled Auto Purge trigger (Auto Purge tab) or a manual "Clear Cache". For frequently updated news sites, use 1-6 hours. For less active sites, use 1-7 days or unlimited. Note: When .htaccess Optimization is enabled, Apache serves cached files directly and cannot check file age. Expired files may continue to be served until the next WP-Cron cleanup (runs hourly). If WP-Cron is disabled (DISABLE_WP_CRON) and no system cron is configured to run wp-cron.php, the cleanup will not run and expired files will keep being served indefinitely.','prime-cache' ); ?></p>
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
		$fo_keys = array(
			'minify_html','minify_html_dom','remove_html_comments','disable_emoji',
			'minify_css','exclude_css',
			'minify_js','defer_js','delay_js','delay_js_timeout',
			'exclude_js','exclude_inline_js','exclude_defer_js','exclude_delay_js',
			'google_fonts_display',
			'remove_query_strings','rewrite_file_optimizer',
			'disable_emoji','disable_jquery_migrate','disable_wp_embed','disable_dashicons',
			'disable_wp_version','disable_xmlrpc','disable_self_pingback',
			'limit_revisions','revisions_max','disable_rss_feeds','disable_oembed',
			'disable_block_css','disable_google_fonts','disable_global_styles',
			'disable_shortlink','disable_rsd_wlw','disable_rest_api_link',
			'disable_wp_sitemap','add_blank_favicon',
			'woo_disable_scripts','woo_disable_cart_frag',
			'delay_js_safe_mode','delay_js_presets',
			'inline_small_css','inline_css_threshold','async_css_free',
			'local_jquery','limit_dns_prefetch',
		);
		if ( prime_cache_is_pro() ) {
			$fo_keys = array_merge( $fo_keys, array(
				'combine_css','combine_mobile_only','optimize_css_delivery','css_delivery_method',
				'async_css','critical_css','critical_css_auto','remove_unused_css','ucss_safelist',
				'combine_js','combine_google_fonts','self_host_google_fonts','local_analytics',
			) );
		}
		?>
		<h2 class="pc-title"><?php esc_html_e( 'File Optimization', 'prime-cache' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'prime_cache_settings_group' ); ?>
			<?php $this->hidden( $settings, $fo_keys ); ?>

			<!-- HTML -->
			<div class="pc-card">
				<span class="pc-card__h">HTML</span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[minify_html]" value="1" <?php checked( $settings['minify_html'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Minify HTML', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Remove unnecessary whitespace from the HTML output to reduce page size. Content inside pre, script, style, and textarea tags is preserved.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[minify_html_dom]" value="1" <?php checked( $settings['minify_html_dom'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Optimize HTML via DOM Parser', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Requires "Minify HTML" above to be enabled. Switches the minification engine from regex to DOMDocument for deeper optimization — parses the document tree to more aggressively collapse whitespace between block-level elements while preserving inline formatting. Falls back to regex if DOM parsing fails.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[remove_html_comments]" value="1" <?php checked( $settings['remove_html_comments'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Remove HTML Comments', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Strip HTML comments from the output. IE conditional comments are preserved.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[disable_emoji]" value="1" <?php checked( $settings['disable_emoji'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Disable WordPress Emoji', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Remove the emoji inline CSS, wp-emoji-release.min.js script, and DNS prefetch for s.w.org from all pages. Reduces 2 HTTP requests and ~16 KB from every page load. Does not affect actual emoji display in modern browsers.', 'prime-cache' ); ?></small></span></label>
			</div>

			<!-- CSS -->
			<div class="pc-card">
				<span class="pc-card__h">CSS</span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[minify_css]" value="1" <?php checked( $settings['minify_css'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Minify CSS', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Remove whitespace, comments, and unnecessary characters from CSS files to reduce file size.', 'prime-cache' ); ?></small></span></label>
				<?php do_action( 'prime_cache_file_opt_css_controls', $settings ); ?>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Excluded CSS Files', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[exclude_css]" rows="3" class="pc-ta" placeholder="/wp-content/plugins/some-plugin/*.css&#10;some-handle.min.css"><?php echo esc_textarea( $settings['exclude_css'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'One pattern per line. These CSS files will not be minified, combined, or loaded asynchronously. Supports wildcards (*).', 'prime-cache' ); ?></p>
				</div>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[inline_small_css]" value="1" <?php checked( $settings['inline_small_css'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Inline Small CSS Files', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Inline CSS files smaller than the threshold directly into the HTML as <style> tags, eliminating HTTP requests for small stylesheets.', 'prime-cache' ); ?></small></span></label>
				<div class="pc-field" style="margin-left:52px">
					<label class="pc-lbl"><?php esc_html_e( 'Threshold (bytes)', 'prime-cache' ); ?></label>
					<input type="number" name="prime_cache_settings[inline_css_threshold]" value="<?php echo esc_attr( $settings['inline_css_threshold'] ); ?>" min="0" max="65536" class="pc-inp" style="width:100px">
					<span class="pc-meta"><?php echo esc_html( size_format( $settings['inline_css_threshold'] ) ); ?></span>
				</div>
				<?php if ( ! prime_cache_is_pro() ) : ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[async_css_free]" value="1" <?php checked( $settings['async_css_free'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Load CSS Asynchronously (Free)', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Convert non-first <link rel="stylesheet"> tags to non-render-blocking loading via media="print" + onload swap. The first stylesheet on the page is intentionally left synchronous to preserve LCP and prevent unstyled flash for above-the-fold content. Use the "Excluded CSS Files" list above for additional stylesheets that must remain render-blocking.', 'prime-cache' ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Literal tag is descriptive text inside a translated label, not an actual stylesheet. ?></small></span></label>
				<?php endif; ?>
			</div>

			<?php do_action( 'prime_cache_file_opt_css_delivery', $settings ); ?>

			<!-- JavaScript -->
			<div class="pc-card">
				<span class="pc-card__h">JavaScript</span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[minify_js]" value="1" <?php checked( $settings['minify_js'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Minify JavaScript', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Conservative JavaScript size reduction: trims trailing whitespace and collapses blank lines. Comments are preserved (regex-based comment removal is unsafe without a JS parser — gzip handles the bulk of size reduction). Already minified files (.min.js) are skipped.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[defer_js]" value="1" <?php checked( $settings['defer_js'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Load JavaScript Deferred', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Add the defer attribute to enqueued scripts (wp_enqueue_script) to eliminate render-blocking JavaScript. Scripts are downloaded in parallel and executed after HTML parsing. Manually inserted scripts in theme templates are not affected on desktop. Note: on mobile, inline jQuery patterns ($(document).ready, $(function(){...})) are automatically wrapped in DOMContentLoaded so they keep working when jQuery itself is deferred.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[delay_js]" value="1" <?php checked( $settings['delay_js'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Delay JavaScript Execution', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Delay every external <script src="..."> tag in the HTML output (including third-party / CDN — not limited to wp_enqueue_script handles) until user interaction (scroll, click, keydown, touchstart, mousemove). Inline scripts (no src) are never delayed because they typically set up variables that external scripts depend on (wp_localize_script output, consent_api config, chat widget configs, etc.). Applied on mobile devices only to avoid CLS regression on desktop. Separate mobile cache is automatically enabled when this setting is on. Significantly improves mobile page load metrics but may cause a brief delay on first interaction. Use the exclusion list below for external scripts that must run before interaction.', 'prime-cache' ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Literal tag is descriptive text inside a translated label, not an actual script. ?></small></span></label>

				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Delay Timeout (ms)', 'prime-cache' ); ?></label>
					<input type="number" name="prime_cache_settings[delay_js_timeout]" value="<?php echo esc_attr( $settings['delay_js_timeout'] ); ?>" min="0" max="30000" class="pc-inp" style="width:140px">
					<p class="pc-help"><?php esc_html_e( 'Auto-load delayed scripts after this many milliseconds even without user interaction. 0 = wait for interaction only.', 'prime-cache' ); ?></p>
				</div>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[delay_js_safe_mode]" value="1" <?php checked( $settings['delay_js_safe_mode'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Delay JS Safe Mode', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Only delay external (third-party) scripts. All internal scripts from your site (wp-includes, wp-content) load immediately. Reduces performance gains but prevents most compatibility issues.', 'prime-cache' ); ?></small></span></label>
				<?php do_action( 'prime_cache_file_opt_js_controls', $settings ); ?>
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
				<?php do_action( 'prime_cache_file_opt_fonts_controls', $settings ); ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[google_fonts_display]" value="1" <?php checked( $settings['google_fonts_display'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Optimize Google Fonts', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Improves Google Fonts loading: (1) appends display=swap to font URLs so text remains visible during font load (prevents FOIT), (2) loads font CSS asynchronously via media="print"+onload so it does not block rendering, (3) injects a preconnect hint to fonts.gstatic.com to start the connection earlier.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[remove_query_strings]" value="1" <?php checked( $settings['remove_query_strings'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Remove Query Strings', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Remove version query strings (?ver=, ?v=) from local CSS and JS file URLs. Improves cacheability by CDNs and proxies that ignore query strings. External URLs are not affected.', 'prime-cache' ); ?></small></span></label>

			</div>

			<!-- Advanced -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Advanced', 'prime-cache' ); ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[rewrite_file_optimizer]" value="1" <?php checked( $settings['rewrite_file_optimizer'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Enable Rewrite for File Optimizer', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Serve optimized CSS/JS files via clean URLs (/_pc-static/) instead of direct file paths. Uses WordPress rewrite rules for portability. Adds a 1-year Cache-Control max-age for optimal browser caching; the immutable directive is included only when "Immutable Cache-Control" is also enabled. Permalinks are flushed automatically when this setting is changed.', 'prime-cache' ); ?></small></span></label>
			</div>

			<!-- Performance Tweaks -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Performance Tweaks', 'prime-cache' ); ?></span>
				<?php
				$tweaks = array(
					array( 'local_jquery', __( 'Use Local jQuery', 'prime-cache' ), __( 'Restore WordPress bundled jQuery when the theme loads it from an external CDN (e.g. cdnjs.cloudflare.com). Eliminates external connection overhead (~600ms on mobile) by serving jQuery from the same origin.', 'prime-cache' ) ),
					array( 'disable_jquery_migrate', __( 'Disable jQuery Migrate', 'prime-cache' ), __( 'Remove jquery-migrate.min.js from the frontend. Most modern jQuery code and plugins no longer need it. Saves ~10 KB per page.', 'prime-cache' ) ),
					array( 'disable_wp_embed', __( 'Disable WP Embed', 'prime-cache' ), __( 'Remove wp-embed.min.js and oEmbed discovery links from your pages. Saves ~6 KB of JavaScript per page and makes your content harder to auto-embed via discovery, but the oEmbed REST endpoint still responds — combine with "Disable oEmbed" below for full embed prevention.', 'prime-cache' ) ),
					array( 'disable_dashicons', __( 'Disable Dashicons (Frontend)', 'prime-cache' ), __( 'Remove the Dashicons stylesheet for non-logged-in visitors. Saves ~46 KB. Icons remain available for logged-in users and the admin area.', 'prime-cache' ) ),
					array( 'disable_wp_version', __( 'Remove WordPress Version', 'prime-cache' ), __( 'Remove the WordPress version meta tag and feed generator tag. Minor security improvement — prevents version fingerprinting.', 'prime-cache' ) ),
					array( 'disable_xmlrpc', __( 'Disable XML-RPC', 'prime-cache' ), __( 'Disable the XML-RPC API via the xmlrpc_enabled filter and remove the X-Pingback header. This does not block access to xmlrpc.php at the server level — use .htaccess or server configuration for full blocking. Not needed if you use the REST API.', 'prime-cache' ) ),
					array( 'disable_self_pingback', __( 'Disable Self-Pingbacks', 'prime-cache' ), __( 'Prevent WordPress from sending pingback requests to your own site when you link to your own posts.', 'prime-cache' ) ),
					array( 'disable_rss_feeds', __( 'Disable RSS Feeds', 'prime-cache' ), __( 'Disable all RSS/Atom feeds and redirect feed URLs to the homepage. Use only if your site does not need feeds.', 'prime-cache' ) ),
					array( 'disable_oembed', __( 'Disable oEmbed', 'prime-cache' ), __( 'Remove oEmbed discovery links, host JavaScript, and REST API route. Prevents remote embedding of your content.', 'prime-cache' ) ),
					array( 'disable_block_css', __( 'Disable Gutenberg Block CSS', 'prime-cache' ), __( 'Remove wp-block-library, wp-block-library-theme, and WooCommerce block stylesheets. Use only if you are using the Classic Editor and not using any Gutenberg blocks.', 'prime-cache' ) ),
					array( 'disable_google_fonts', __( 'Disable Google Fonts', 'prime-cache' ), __( 'Dequeue all Google Fonts (fonts.googleapis.com and fonts.bunny.net) loaded by themes and plugins. Use if you self-host fonts or do not need external fonts.', 'prime-cache' ) ),
					array( 'disable_global_styles', __( 'Disable Global Styles (SVG)', 'prime-cache' ), __( 'Remove the global-styles inline CSS and SVG filters added by WordPress 6.1+. Saves ~2 KB of inline markup on every page.', 'prime-cache' ) ),
					array( 'limit_dns_prefetch', __( 'Limit DNS Prefetch Hints', 'prime-cache' ), __( 'Limit dns-prefetch and preconnect hints to 4 entries. WordPress auto-adds hints for every external domain, which wastes mobile connections. PageSpeed recommends 4 or fewer.', 'prime-cache' ) ),
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
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[woo_disable_scripts]" value="1" <?php checked( $settings['woo_disable_scripts'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'WooCommerce Script Optimization', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Disable WooCommerce CSS and JavaScript on non-WooCommerce pages (shop, product, archive, cart, checkout, and account pages are excluded). Saves ~100 KB+ on regular blog/pages.', 'prime-cache' ); ?></small></span></label>
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
						'google-adsense' => 'Google AdSense (googlesyndication.com)',
						'facebook-pixel' => 'Facebook Pixel (fbevents.js)',
						'hotjar' => 'Hotjar (hotjar.com)',
						'recaptcha' => 'Google reCAPTCHA (recaptcha)',
						'clarity' => 'Microsoft Clarity (clarity.ms)',
						'intercom' => 'Intercom',
						'crisp' => 'Crisp Chat',
						'tawk' => 'Tawk.to',
						'hubspot' => 'HubSpot (hs-scripts / hs-analytics)',
						'pinterest' => 'Pinterest (pinit.js)',
						'twitter' => 'Twitter / X widgets (widgets.js)',
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
		/* CSS Delivery controls are injected by the optional add-on and are absent in
		   Free. Keep this in its OWN IIFE so its early return cannot prevent the
		   Free-only Delay JS presets handler below from binding. */
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
		})();
		/* Delay JS presets → hidden field. Free feature — must bind regardless of
		   whether the Pro CSS-delivery controls above exist. */
		(function(){
			var cbs=document.querySelectorAll('input[name="pc_delay_presets[]"]'),hid=document.getElementById('pc-delay-presets-hidden');
			if(!cbs.length||!hid)return;
			function syncPresets(){var v=[];cbs.forEach(function(c){if(c.checked)v.push(c.value);});hid.value=v.join(',');}
			cbs.forEach(function(c){c.addEventListener('change',syncPresets);});
		})();
		</script>
		<?php
		// Single informational card at the very end of the tab pointing to the
		// Pro Features submenu — no settings, no disabled controls.
		$this->render_pro_context_card( array(
			'title' => __( 'Advanced CSS optimization', 'prime-cache' ),
			'body'  => __( 'Prime Cache Pro adds Critical CSS generation, unused CSS cleanup, and advanced CSS delivery for sites that need deeper front-end optimization.', 'prime-cache' ),
		) );
	}

	/* ── tab: media ───────────────────────────────────────── */

	private function tab_media( $settings ) {
		$is_pro = prime_cache_is_pro();
		// Format Conversion / Bulk Optimization are now FREE features. WebP and the
		// shared conversion controls render as real inputs for everyone, so their
		// keys are always "visible" (not re-emitted as hidden duplicates).
		$vis = array(
			'lazyload_images','lazyload_iframes','lazyload_videos','lazyload_disable_native','lazyload_skip_first','lazyload_exclude',
			'add_missing_dimensions',
			'img_strip_exif','img_resize','img_max_width','img_max_height',
			'img_conversion_enabled','webp_enabled','img_quality_mode','webp_quality',
			'img_auto_optimize','img_auto_remove_larger','img_exclude_png',
			'img_include_uploads','img_include_themes','img_include_plugins',
			'img_delivery_method','img_converter',
		);
		if ( $is_pro ) {
			// AVIF + YouTube thumbnail stay in the add-on: their real inputs only render with the add-on.
			$vis = array_merge( $vis, array(
				'youtube_thumbnail',
				'avif_enabled','avif_quality',
				'img_include_custom','img_exclude_folders',
			) );
		}
		// AVIF keys are included only when the add-on is active; otherwise they are
		// omitted from $vis so hidden() carries any stored avif_enabled /
		// avif_quality value forward instead of a non-rendered field clearing it.
		$avif_supported = class_exists( 'Prime_Cache_Image_Converter' ) ? Prime_Cache_Image_Converter::avif_supported() : false;
		?>
		<h2 class="pc-title"><?php esc_html_e( 'Media', 'prime-cache' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'prime_cache_settings_group' ); ?>
			<?php $this->hidden( $settings, $vis ); ?>

			<!-- Lazy Load -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Lazy Load', 'prime-cache' ); ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[lazyload_images]" value="1" <?php checked( $settings['lazyload_images'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Lazy Load Images', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Add loading="lazy" attribute to images below the fold. The first N images (configurable below) are skipped to preserve above-the-fold LCP performance. Images with fetchpriority="high" are never lazy loaded.', 'prime-cache' ); ?></small></span></label>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Skip First N Images', 'prime-cache' ); ?></label>
					<input type="number" name="prime_cache_settings[lazyload_skip_first]" value="<?php echo esc_attr( $settings['lazyload_skip_first'] ); ?>" min="0" max="10" class="pc-inp" style="width:140px">
					<p class="pc-help"><?php esc_html_e( 'Number of leading images per page that bypass lazy loading (typically the LCP candidate and adjacent above-the-fold images). Default 3. Set 0 to lazy-load every image.', 'prime-cache' ); ?></p>
				</div>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[lazyload_iframes]" value="1" <?php checked( $settings['lazyload_iframes'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Lazy Load Iframes', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Add loading="lazy" to iframes (YouTube embeds, Google Maps, etc.). Significantly reduces initial page weight for embed-heavy pages.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[lazyload_videos]" value="1" <?php checked( $settings['lazyload_videos'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Lazy Load Videos', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Set preload="none" on video elements to prevent auto-downloading video files until playback is requested.', 'prime-cache' ); ?></small></span></label>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[lazyload_disable_native]" value="1" <?php checked( $settings['lazyload_disable_native'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Disable WordPress Native Lazy Load', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Disable the built-in lazy loading added by WordPress 5.5+. Use this if you want Prime Cache to have full control over which images are lazy loaded, or to prevent double lazy-load attributes. When disabled, WordPress will not automatically add loading="lazy" to images and iframes.', 'prime-cache' ); ?></small></span></label>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Excluded Patterns', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[lazyload_exclude]" rows="2" class="pc-ta" placeholder="logo&#10;hero-image"><?php echo esc_textarea( $settings['lazyload_exclude'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'One pattern per line. Images, iframes, and videos containing these strings will not be lazy loaded.', 'prime-cache' ); ?></p>
				</div>
			</div>

			<!-- Image Optimization -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Image Optimization', 'prime-cache' ); ?></span>
				<?php do_action( 'prime_cache_media_image_controls', $settings ); ?>
				<?php if ( $is_pro ) : ?>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[youtube_thumbnail]" value="1" <?php checked( ! empty( $settings['youtube_thumbnail'] ) ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'YouTube Thumbnail Placeholder', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Replace YouTube iframe embeds with a lightweight thumbnail image. The iframe loads only when the user clicks play, saving significant bandwidth and improving page load time.', 'prime-cache' ); ?></small></span></label>
				<?php endif; ?>
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

			<?php
			// The optional add-on's handler for this hook is removed; the Format Conversion + Bulk
			// Optimization cards are rendered directly below for ALL users (WebP is a
			// Free feature). The hook call is kept for forward-compat / 3rd-party use.
			do_action( 'prime_cache_media_after_optimization', $settings );
			?>

			<!-- Image format conversion. WebP is a free feature; the add-on adds AVIF and shows it only when active. -->
			<div class="pc-card">
				<span class="pc-card__h"><?php echo esc_html( $is_pro ? __( 'Format Conversion (WebP / AVIF)', 'prime-cache' ) : __( 'WebP Conversion', 'prime-cache' ) ); ?></span>
				<label class="pc-sw">
					<input type="checkbox" name="prime_cache_settings[img_conversion_enabled]" value="1" <?php checked( ! empty( $settings['img_conversion_enabled'] ) ); ?>>
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php esc_html_e( 'Enable Image Conversion', 'prime-cache' ); ?></b>
						<small><?php esc_html_e( 'Convert JPEG and PNG images to modern formats for smaller file sizes and faster loading.', 'prime-cache' ); ?></small>
					</span>
				</label>
				<label class="pc-sw">
					<input type="checkbox" name="prime_cache_settings[webp_enabled]" value="1" <?php checked( ! empty( $settings['webp_enabled'] ) ); ?>>
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php esc_html_e( 'WebP', 'prime-cache' ); ?></b>
						<small><?php esc_html_e( 'Convert images to WebP format. Supported by all modern browsers.', 'prime-cache' ); ?></small>
					</span>
				</label>

				<?php if ( $is_pro ) : ?>
				<label class="pc-sw">
					<input type="checkbox" name="prime_cache_settings[avif_enabled]" value="1" <?php checked( ! empty( $settings['avif_enabled'] ) ); ?>>
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php esc_html_e( 'AVIF', 'prime-cache' ); ?></b>
						<small><?php esc_html_e( 'Convert images to AVIF format. Offers superior compression but requires more processing time.', 'prime-cache' ); ?></small>
					</span>
				</label>
				<?php endif; ?>

				<?php if ( $is_pro && ! $avif_supported ) : ?>
				<p class="pc-help" style="margin-top:6px;color:#92400e;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:8px 12px"><?php esc_html_e( 'Your server does not support AVIF conversion — AVIF will be skipped even if enabled. (Requires GD with AVIF support or ImageMagick with AVIF.)', 'prime-cache' ); ?></p>
				<?php endif; ?>

				<div class="pc-field" style="display:flex;gap:16px;flex-wrap:wrap">
					<div>
						<label class="pc-lbl"><?php esc_html_e( 'Quality Mode', 'prime-cache' ); ?></label>
						<select name="prime_cache_settings[img_quality_mode]" class="pc-inp">
							<option value="lossy" <?php selected( $settings['img_quality_mode'] ?? 'lossy', 'lossy' ); ?>><?php esc_html_e( 'Lossy', 'prime-cache' ); ?></option>
							<option value="lossless" <?php selected( $settings['img_quality_mode'] ?? 'lossy', 'lossless' ); ?>><?php esc_html_e( 'Lossless', 'prime-cache' ); ?></option>
							<option value="custom" <?php selected( $settings['img_quality_mode'] ?? 'lossy', 'custom' ); ?>><?php esc_html_e( 'Custom', 'prime-cache' ); ?></option>
						</select>
					</div>
					<div>
						<label class="pc-lbl"><?php esc_html_e( 'WebP Quality', 'prime-cache' ); ?></label>
						<input type="number" name="prime_cache_settings[webp_quality]" value="<?php echo esc_attr( $settings['webp_quality'] ?? 80 ); ?>" min="1" max="100" class="pc-inp" style="width:80px">
					</div>
					<?php if ( $is_pro ) : ?>
					<div>
						<label class="pc-lbl"><?php esc_html_e( 'AVIF Quality', 'prime-cache' ); ?></label>
						<input type="number" name="prime_cache_settings[avif_quality]" value="<?php echo esc_attr( $settings['avif_quality'] ?? 60 ); ?>" min="1" max="100" class="pc-inp" style="width:80px">
					</div>
					<?php endif; ?>
				</div>

				<div class="pc-field" style="display:flex;gap:16px;flex-wrap:wrap">
					<div>
						<label class="pc-lbl"><?php esc_html_e( 'Delivery Method', 'prime-cache' ); ?></label>
						<select name="prime_cache_settings[img_delivery_method]" class="pc-inp">
							<option value="rewrite" <?php selected( $settings['img_delivery_method'] ?? 'rewrite', 'rewrite' ); ?>><?php esc_html_e( 'Rewrite (.htaccess)', 'prime-cache' ); ?></option>
							<option value="picture" <?php selected( $settings['img_delivery_method'] ?? 'rewrite', 'picture' ); ?>><?php esc_html_e( '<picture> Tag', 'prime-cache' ); ?></option>
							<option value="url" <?php selected( $settings['img_delivery_method'] ?? 'rewrite', 'url' ); ?>><?php esc_html_e( 'URL Replacement', 'prime-cache' ); ?></option>
						</select>
					</div>
					<div>
						<label class="pc-lbl"><?php esc_html_e( 'Conversion Engine', 'prime-cache' ); ?></label>
						<select name="prime_cache_settings[img_converter]" class="pc-inp">
							<option value="auto" <?php selected( $settings['img_converter'] ?? 'auto', 'auto' ); ?>><?php esc_html_e( 'Auto (Imagick, then GD)', 'prime-cache' ); ?></option>
							<option value="gd" <?php selected( $settings['img_converter'] ?? 'auto', 'gd' ); ?>>GD</option>
							<option value="imagick" <?php selected( $settings['img_converter'] ?? 'auto', 'imagick' ); ?>>ImageMagick</option>
						</select>
					</div>
				</div>

				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Target Folders', 'prime-cache' ); ?></label>
					<label style="display:block;margin-bottom:4px">
						<input type="checkbox" name="prime_cache_settings[img_include_uploads]" value="1" <?php checked( ! empty( $settings['img_include_uploads'] ) ); ?>>
						<?php esc_html_e( 'Uploads (wp-content/uploads)', 'prime-cache' ); ?>
					</label>
					<label style="display:block;margin-bottom:4px">
						<input type="checkbox" name="prime_cache_settings[img_include_themes]" value="1" <?php checked( ! empty( $settings['img_include_themes'] ) ); ?>>
						<?php esc_html_e( 'Themes (wp-content/themes)', 'prime-cache' ); ?>
					</label>
					<label style="display:block;margin-bottom:4px">
						<input type="checkbox" name="prime_cache_settings[img_include_plugins]" value="1" <?php checked( ! empty( $settings['img_include_plugins'] ) ); ?>>
						<?php esc_html_e( 'Plugins (wp-content/plugins)', 'prime-cache' ); ?>
					</label>
				</div>

				<label class="pc-sw">
					<input type="checkbox" name="prime_cache_settings[img_auto_optimize]" value="1" <?php checked( ! empty( $settings['img_auto_optimize'] ) ); ?>>
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php esc_html_e( 'Auto-Optimize on Upload', 'prime-cache' ); ?></b>
						<small><?php esc_html_e( 'Automatically convert images when they are uploaded to the media library.', 'prime-cache' ); ?></small>
					</span>
				</label>
				<label class="pc-sw">
					<input type="checkbox" name="prime_cache_settings[img_auto_remove_larger]" value="1" <?php checked( ! empty( $settings['img_auto_remove_larger'] ) ); ?>>
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php esc_html_e( 'Delete Larger Conversions', 'prime-cache' ); ?></b>
						<small><?php esc_html_e( 'If the converted file is larger than the original, delete it automatically.', 'prime-cache' ); ?></small>
					</span>
				</label>
				<label class="pc-sw">
					<input type="checkbox" name="prime_cache_settings[img_exclude_png]" value="1" <?php checked( ! empty( $settings['img_exclude_png'] ) ); ?>>
					<span class="pc-sw__track"></span>
					<span class="pc-sw__body">
						<b><?php esc_html_e( 'Exclude PNG Files', 'prime-cache' ); ?></b>
						<small><?php esc_html_e( 'Skip PNG images during conversion. Useful for PNGs with transparency or sharp graphics where WebP/AVIF offers little benefit. JPEG images are still converted.', 'prime-cache' ); ?></small>
					</span>
				</label>
			</div>

			<div class="pc-actions"><?php submit_button( __( 'Save Settings', 'prime-cache' ), 'primary large', 'submit', false ); ?></div>
		</form>

		<!-- Bulk Image Optimization (Free) -->
		<div class="pc-card" data-pc-bulk-nonce="<?php echo esc_attr( wp_create_nonce( 'pc_img_nonce' ) ); ?>">
			<span class="pc-card__h"><?php esc_html_e( 'Bulk Image Optimization', 'prime-cache' ); ?></span>
			<p class="pc-help"><?php esc_html_e( 'Scan and optimize existing images in your media library. Progress is saved — you can pause and resume at any time.', 'prime-cache' ); ?></p>
			<div class="pc-field">
				<button type="button" class="button" id="pc-bulk-scan"><?php esc_html_e( 'Scan for Unoptimized Images', 'prime-cache' ); ?></button>
				<span id="pc-bulk-status" style="margin-left:8px"></span>
			</div>
			<div class="pc-field" id="pc-bulk-progress-wrap" style="display:none;margin-top:8px">
				<div class="pc-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
					<div class="pc-bar__fill" id="pc-bulk-progress-fill" style="width:0%"></div>
				</div>
				<div class="pc-bar__info">
					<span><?php esc_html_e( 'Progress', 'prime-cache' ); ?>: <b id="pc-bulk-progress-pct">0%</b></span>
					<span><b id="pc-bulk-progress-count">0</b> / <b id="pc-bulk-progress-total">0</b></span>
				</div>
			</div>
		</div>
		<script>
		(function(){
			if (typeof jQuery === 'undefined') return;
			jQuery(function($){
				var $btn = $('#pc-bulk-scan');
				if (!$btn.length || $btn.data('pcBound')) return;
				$btn.data('pcBound', true);
				var $status   = $('#pc-bulk-status');
				var $wrap     = $('#pc-bulk-progress-wrap');
				var $fill     = $('#pc-bulk-progress-fill');
				var $pct      = $('#pc-bulk-progress-pct');
				var $count    = $('#pc-bulk-progress-count');
				var $totalEl  = $('#pc-bulk-progress-total');
				var nonce     = $btn.closest('.pc-card').data('pc-bulk-nonce') || '';
				if (!nonce) return;

				function showBar(total){
					$totalEl.text(total);
					$count.text(0);
					$pct.text('0%');
					$fill.css('width', '0%');
					$wrap.find('.pc-bar').attr('aria-valuenow', 0);
					$wrap.show();
				}
				function updateBar(done, total){
					var p = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
					$fill.css('width', p + '%');
					$pct.text(p + '%');
					$count.text(done);
					$wrap.find('.pc-bar').attr('aria-valuenow', p);
				}

				var i18n = {
					scanning:   <?php echo wp_json_encode( __( 'Scanning…', 'prime-cache' ) ); ?>,
					none:       <?php echo wp_json_encode( __( 'No unoptimized images found.', 'prime-cache' ) ); ?>,
					converting: <?php echo wp_json_encode( /* translators: %1$d: number of images processed so far, %2$d: total number of images. */ __( 'Converting %1$d / %2$d…', 'prime-cache' ) ); ?>,
					done:       <?php echo wp_json_encode( /* translators: %d: number of images processed. */ __( 'Done. Processed %d images.', 'prime-cache' ) ); ?>,
					error:      <?php echo wp_json_encode( /* translators: %s: error message. */ __( 'Error: %s', 'prime-cache' ) ); ?>,
					unknown:    <?php echo wp_json_encode( __( 'unknown error', 'prime-cache' ) ); ?>,
					ajaxErr:    <?php echo wp_json_encode( __( 'network error', 'prime-cache' ) ); ?>
				};

				function fmt(tpl){
					var args = [].slice.call(arguments, 1), i = 0;
					return tpl.replace(/%(?:(\d+)\$)?[ds]/g, function(_, p){
						return args[p ? (parseInt(p, 10) - 1) : (i++)];
					});
				}

				var BATCH = 30;

				$btn.on('click', function(e){
					e.preventDefault();
					$btn.prop('disabled', true);
					$status.text(i18n.scanning);
					$wrap.hide();

					$.post(ajaxurl, { action: 'pc_img_scan', nonce: nonce })
						.done(function(resp){
							if (!resp || !resp.success) {
								var m = (resp && resp.data && resp.data.message) ? resp.data.message : i18n.unknown;
								$status.text(fmt(i18n.error, m));
								$btn.prop('disabled', false);
								return;
							}
							var items = (resp.data && resp.data.items) ? resp.data.items.slice() : [];
							var total = items.length;
							if (!total) {
								$status.text(i18n.none);
								$btn.prop('disabled', false);
								return;
							}
							var done = 0;
							showBar(total);

							function step(){
								if (!items.length) {
									updateBar(done, total);
									$status.text(fmt(i18n.done, done));
									$btn.prop('disabled', false);
									return;
								}
								var batch = items.splice(0, BATCH);
								$.post(ajaxurl, { action: 'pc_img_batch', nonce: nonce, items: batch })
									.done(function(r){
										if (r && r.success && r.data) {
											var n = parseInt(r.data.processed, 10) || 0;
											done += n;
											// Server may stop early at its 25s time budget — re-queue the remainder.
											if (n < batch.length) {
												items = batch.slice(n).concat(items);
											}
											updateBar(done, total);
											$status.text(fmt(i18n.converting, done, total));
											step();
										} else {
											items = batch.concat(items);
											var m2 = (r && r.data && r.data.message) ? r.data.message : i18n.unknown;
											$status.text(fmt(i18n.error, m2));
											$btn.prop('disabled', false);
										}
									})
									.fail(function(){
										items = batch.concat(items);
										$status.text(fmt(i18n.error, i18n.ajaxErr));
										$btn.prop('disabled', false);
									});
							}

							updateBar(0, total);
							$status.text(fmt(i18n.converting, 0, total));
							step();
						})
						.fail(function(){
							$status.text(fmt(i18n.error, i18n.ajaxErr));
							$btn.prop('disabled', false);
						});
				});
			});
		})();
		</script>
		<?php
		// AVIF lives in the optional add-on; surface it at the end of the Media
		// tab as a single informational pointer, never as a disabled control.
		$this->render_pro_context_card( array(
			'title' => __( 'Need smaller modern images?', 'prime-cache' ),
			'body'  => __( 'Prime Cache Pro adds AVIF conversion for sites that want to go beyond WebP.', 'prime-cache' ),
		) );
	}


	/* ── tab: preload ─────────────────────────────────────── */

	private function tab_preload( $settings ) {
		$vis = array(
			'preload_enabled','preload_homepage','preload_public_posts','preload_public_tax',
			'preload_interval','preload_max_posts','preload_max_terms','preload_excluded_uri',
			'preload_links',
		);
		if ( prime_cache_is_pro() ) {
			$vis = array_merge( $vis, array(
				'preload_sitemap_enabled','preload_sitemap',
				'speculation_rules','preload_fonts','lcp_optimization','lcp_excluded','preload_resources','prefetch_dns','preconnect',
			) );
		}
		?>
		<h2 class="pc-title"><?php esc_html_e( 'Preload', 'prime-cache' ); ?></h2>
		<form method="post" action="options.php">
			<?php settings_fields( 'prime_cache_settings_group' ); ?>
			<?php $this->hidden( $settings, $vis ); ?>

			<!-- Cache Preloading -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Cache Preloading', 'prime-cache' ); ?></span>
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
					<p class="pc-help"><?php esc_html_e( 'Delay between each preload batch. Each batch sends up to 10 URLs in sequence, then waits this many seconds before the next batch via WP-Cron. Higher values reduce server load but take longer to complete. Preloading automatically pauses when load (weighted 1/5/15-minute average) exceeds 80% of CPU core count or 2.0 — whichever is higher — or when a sudden load spike is detected.', 'prime-cache' ); ?></p>
				</div>
				<div class="pc-field">
					<label class="pc-lbl"><?php esc_html_e( 'Preload Excluded URLs', 'prime-cache' ); ?></label>
					<textarea name="prime_cache_settings[preload_excluded_uri]" rows="3" class="pc-ta" placeholder="/sample-page/&#10;/private-area/*"><?php echo esc_textarea( $settings['preload_excluded_uri'] ); ?></textarea>
					<p class="pc-help"><?php esc_html_e( 'URL path patterns to skip during preloading, one per line. A line matches when it appears anywhere in the URL path; use * as a wildcard for any sequence (for example, /private-area/*).', 'prime-cache' ); ?></p>
				</div>
			</div>

			<!-- Link Prefetching -->
			<div class="pc-card">
				<span class="pc-card__h"><?php esc_html_e( 'Preload Links', 'prime-cache' ); ?></span>
				<label class="pc-sw"><input type="checkbox" name="prime_cache_settings[preload_links]" value="1" <?php checked( $settings['preload_links'] ); ?>><span class="pc-sw__track"></span><span class="pc-sw__body"><b><?php esc_html_e( 'Enable Link Prefetching', 'prime-cache' ); ?></b><small><?php esc_html_e( 'Inject a lightweight JavaScript that prefetches internal links when the user hovers over them or when they enter the viewport. The browser downloads the page in advance so the next click feels instant. Rate-limited to 3 links per second. Excluded: admin, login, cart, checkout, and external links.', 'prime-cache' ); ?></small></span></label>
			</div>

			<?php do_action( 'prime_cache_preload_after_links', $settings ); ?>

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
				<div class="pc-field"><label class="pc-lbl"><?php esc_html_e('Ignored Query Parameters','prime-cache'); ?></label><textarea name="prime_cache_settings[cache_ignore_qs]" rows="3" class="pc-ta"><?php echo esc_textarea($settings['cache_ignore_qs']); ?></textarea><p class="pc-help"><?php echo wp_kses(__('Comma-separated parameter names. These parameters are stripped and the same cache is served as if they were absent. Register ad-tracking parameters (utm_source, fbclid, gclid, etc.) to prevent unnecessary cache duplication. <strong>Note:</strong> Requests with any query string go through the PHP drop-in (the .htaccess fast-path requires an empty query string), still very fast but not zero-PHP.','prime-cache'),array('code'=>array(),'strong'=>array())); ?></p></div>
				<div class="pc-field"><label class="pc-lbl"><?php esc_html_e('Cached Query Parameters','prime-cache'); ?></label><textarea name="prime_cache_settings[cache_query_strings]" rows="3" class="pc-ta" placeholder="lang, currency, color"><?php echo esc_textarea($settings['cache_query_strings']); ?></textarea><p class="pc-help"><?php echo wp_kses(__('Comma-separated parameter names. Each unique value generates a separate cache file. For example, specifying <code>lang</code> creates separate caches for <code>?lang=en</code> and <code>?lang=ja</code>. Use for multilingual plugins or currency switchers. URLs with parameters not in either list are not cached. <strong>Note:</strong> When active, .htaccess fast-path is automatically disabled — all requests are served via the drop-in to ensure correct variant selection (still very fast, but not zero-PHP).','prime-cache'),array('code'=>array(),'strong'=>array())); ?></p></div>
			</div>
			<div class="pc-card"><span class="pc-card__h"><?php esc_html_e('Cookie-based Cache Splitting','prime-cache'); ?></span>
				<div class="pc-field"><label class="pc-lbl"><?php esc_html_e('Vary Cookie Names','prime-cache'); ?></label><textarea name="prime_cache_settings[cache_vary_cookies]" rows="3" class="pc-ta" placeholder="currency, country"><?php echo esc_textarea($settings['cache_vary_cookies']); ?></textarea><p class="pc-help"><?php echo wp_kses(__('Comma-separated cookie names. Different cache files are generated based on each cookie\'s value, even for the same URL. For example, specifying the <code>currency</code> cookie serves separate caches to users with <code>currency=USD</code> vs <code>currency=JPY</code>. Use for e-commerce currency/country display or A/B testing. <strong>Note:</strong> When active, .htaccess fast-path is automatically disabled — all requests are served via the drop-in to ensure correct variant selection.','prime-cache'),array('code'=>array(),'strong'=>array())); ?></p></div>
			</div>
			<div class="pc-card"><span class="pc-card__h"><?php esc_html_e('Purge Settings','prime-cache'); ?></span>
				<div class="pc-field"><label class="pc-lbl"><?php esc_html_e('Always Purge URLs','prime-cache'); ?></label><textarea name="prime_cache_settings[purge_additional_urls]" rows="4" class="pc-ta" placeholder="https://example.com/custom-page/"><?php echo esc_textarea($settings['purge_additional_urls']); ?></textarea><p class="pc-help"><?php esc_html_e('One URL per line. Whenever any post is published, updated, trashed, or deleted, caches for these URLs are also cleared in addition to the standard related pages (home, categories, tags, author, date archives). Use for sitemaps, custom landing pages, or shortcode-based post listing pages that are not auto-detected.','prime-cache'); ?></p></div>
			</div>
			<div class="pc-actions"><?php submit_button(__('Save Settings','prime-cache'),'primary large','submit',false); ?></div>
		</form>
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
			array( 'purge_on_post_update',   __( 'Post Publish / Update', 'prime-cache' ),     __( 'Clear related caches when a post, page, or custom post type is published or updated. Purges the post URL, home page, posts page, author archive, term archives, date archives, and CPT archive — first page only. Pagination (/page/2/, etc.) is left for natural expiry to preserve cache hit rate.', 'prime-cache' ) ),
			array( 'purge_on_post_delete',   __( 'Post Trash / Delete', 'prime-cache' ),       __( 'Clear related caches when a post is moved to trash or permanently deleted.', 'prime-cache' ) ),
			array( 'purge_on_comment',       __( 'Comment Changes', 'prime-cache' ),            __( 'Clear the post page, home page, and posts page cache when a comment is posted, approved, edited, trashed, or deleted. (Home/posts page cleared because they typically display comment counts and recent-comments widgets.)', 'prime-cache' ) ),
			array( 'purge_on_term_change',   __( 'Term Changes', 'prime-cache' ),               __( 'Clear the term archive (including pagination), home page, and posts page when a category, tag, or custom taxonomy term is created, edited, or deleted.', 'prime-cache' ) ),
			array( 'purge_on_theme_switch',  __( 'Theme Switch', 'prime-cache' ),               __( 'Clear the entire cache when the active theme is changed. Theme changes typically affect all pages.', 'prime-cache' ) ),
			array( 'purge_on_permalink',     __( 'Permalink Change', 'prime-cache' ),           __( 'Clear the entire cache when the permalink structure is updated. All cached URLs become invalid.', 'prime-cache' ) ),
			array( 'purge_on_plugin_change', __( 'Plugin Activate / Deactivate', 'prime-cache' ), __( 'Clear the entire cache when any plugin is activated or deactivated. Plugin changes may alter page output, menus, or widgets.', 'prime-cache' ) ),
			array( 'purge_on_customizer',    __( 'Customizer Save', 'prime-cache' ),            __( 'Clear the entire cache when theme customizer settings are saved. Changes to site identity, colors, layouts, and header/footer affect all pages.', 'prime-cache' ) ),
			array( 'purge_on_widget',        __( 'Widget Update', 'prime-cache' ),              __( 'Clear the entire cache when widgets are added, removed, or rearranged. Widgets appear on multiple pages via sidebars and footers.', 'prime-cache' ) ),
			array( 'purge_on_nav_menu',      __( 'Navigation Menu Update', 'prime-cache' ),     __( 'Clear the entire cache when a navigation menu is created, edited, or deleted. Menus are typically displayed on every page.', 'prime-cache' ) ),
			array( 'purge_on_core_update',   __( 'WordPress Core Update', 'prime-cache' ),      __( 'Clear the entire cache after WordPress core is updated. Core updates may change HTML output, scripts, and styles.', 'prime-cache' ) ),
			array( 'purge_on_user_update',   __( 'User Profile Update', 'prime-cache' ),        __( 'Clear the author archive (including pagination), home page, and posts page when a user profile is updated or a user is deleted.', 'prime-cache' ) ),
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
		$htaccess_note = __('Note: When .htaccess Optimization is enabled, only simple patterns are used in the .htaccess fast-path.','prime-cache');
		$rows = array(
			array('cache_reject_uri',     __('Excluded URLs','prime-cache'),         __('URL patterns to never cache','prime-cache'),       '/cart*|/checkout|/my-account', __('Enter URL path patterns separated by <code>|</code>. Use <code>*</code> as a wildcard. Matched URLs are always dynamically generated by WordPress. Specify WooCommerce cart/checkout, my-account pages, or any page with user-specific content.','prime-cache') . ' ' . $htaccess_note),
			array('cache_reject_cookies', __('Excluded Cookies','prime-cache'),      __('Cookies that disable caching','prime-cache'),      'woocommerce_cart_hash|wp_woocommerce_session', __('Enter cookie name patterns separated by <code>|</code>. Use <code>*</code> as a wildcard. When any listed cookie is present, the request is not cached. WordPress login cookies are excluded automatically.','prime-cache') . ' ' . $htaccess_note),
			array('cache_reject_ua',      __('Excluded User Agents','prime-cache'),  __('User agents to never cache','prime-cache'),        'bot|crawler|spider', __('Enter user-agent patterns separated by <code>|</code>. Use <code>*</code> as a wildcard. Matched browsers or crawlers receive uncached responses.','prime-cache') . ' ' . $htaccess_note),
			array('cache_reject_referrer',__('Excluded Referrers','prime-cache'),    __('Referrers to never cache','prime-cache'),          'example.com|spam.site', __('Enter referrer URL patterns separated by <code>|</code>. Use <code>*</code> as a wildcard. Requests from matching referrers are excluded from the cache.','prime-cache') . ' ' . $htaccess_note),
		);
		?>
		<h2 class="pc-title"><?php esc_html_e('Exclusion Rules','prime-cache'); ?></h2>
		<form method="post" action="options.php"><?php settings_fields('prime_cache_settings_group'); $this->hidden($settings,$vis); ?>
			<?php foreach($rows as $r): ?>
			<div class="pc-card"><span class="pc-card__h"><?php echo esc_html($r[1]); ?></span>
				<div class="pc-field"><label class="pc-lbl"><?php echo esc_html($r[2]); ?> <span class="pc-tag"><?php esc_html_e('Wildcard','prime-cache'); ?></span></label><textarea name="prime_cache_settings[<?php echo esc_attr($r[0]); ?>]" rows="3" class="pc-ta" placeholder="<?php echo esc_attr($r[3]); ?>"><?php echo esc_textarea($settings[$r[0]]); ?></textarea><p class="pc-help"><?php echo wp_kses($r[4],array('code'=>array())); ?></p></div>
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

		<?php /* phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin notices shown after nonce-verified import/preset redirects; values are display-only and sanitized. */ ?>
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
			<div class="notice notice-success is-dismissible" style="margin:0 0 16px"><p><?php
			/* translators: %s: preset name (e.g. "Standard", "Aggressive") */
			printf( esc_html__( '"%s" preset applied successfully.', 'prime-cache' ), esc_html( ucfirst( sanitize_key( $_GET['pc_preset'] ) ) ) );
			?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['pc_preset_error'] ) ) : ?>
			<div class="notice notice-error is-dismissible" style="margin:0 0 16px"><p><?php
			/* translators: %s: preset name supplied by the user */
			printf( esc_html__( 'Unknown preset "%s". No changes were made.', 'prime-cache' ), esc_html( sanitize_key( $_GET['pc_preset_error'] ) ) );
			?></p></div>
		<?php endif; ?>
		<?php /* phpcs:enable WordPress.Security.NonceVerification.Recommended */ ?>

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
				<?php /* translators: %s: preset name */ ?>
				<a href="<?php echo esc_url( $auto_url ); ?>" class="pc-btn pc-btn--p pc-btn--sm" style="width:100%" onclick="return confirm(<?php echo esc_attr( wp_json_encode( sprintf( __( 'Apply the "%s" preset? Settings included in the preset will overwrite your current values; settings the preset does not touch (rejected URIs, additional purge URLs, etc.) are preserved.', 'prime-cache' ), __( 'Auto', 'prime-cache' ) ) ) ); ?>)">
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
						__( 'Good performance with low risk. Adds HTML/CSS/JS minification, link prefetching, defer JS, query-string removal, emoji removal, embed removal, and .htaccess optimization on top of Safe settings.', 'prime-cache' ),
						'#f59e0b',
						'dashicons-performance',
					),
					'aggressive' => array(
						__( 'Aggressive', 'prime-cache' ),
						__( 'Maximum performance. Adds defer/delay JS, async CSS, separate mobile cache, preloading, lazy load for iframes/videos, and disables unused WordPress assets (emoji, embed, dashicons, oEmbed, block CSS). Additional add-ons may provide CSS/JS combining and Critical CSS generation. May require testing and exclusion rules for compatibility.', 'prime-cache' ),
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
					<?php /* translators: %s: preset name */ ?>
					<a href="<?php echo esc_url( $preset_url ); ?>" class="pc-btn pc-btn--p pc-btn--sm" style="width:100%" onclick="return confirm(<?php echo esc_attr( wp_json_encode( sprintf( __( 'Apply the "%s" preset? Settings included in the preset will overwrite your current values; settings the preset does not touch (rejected URIs, additional purge URLs, etc.) are preserved.', 'prime-cache' ), $pv[0] ) ) ); ?>)">
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
					<button type="submit" name="pc_import_settings" value="1" class="pc-btn pc-btn--o pc-btn--sm" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Import will overwrite settings included in the JSON. Settings the JSON does not mention (and Cloudflare/Sucuri API keys absent from the file) are kept at their current values. Continue?', 'prime-cache' ) ) ); ?>)">
						<span class="dashicons dashicons-upload" style="font-size:15px;width:15px;height:15px;line-height:15px"></span><?php esc_html_e( 'Import', 'prime-cache' ); ?>
					</button>
				</form>
				<p class="pc-help"><?php esc_html_e( 'Upload a previously exported Prime Cache settings JSON file. Settings included in the JSON overwrite their current values; settings the JSON does not mention (and Cloudflare/Sucuri API keys absent from the file) are preserved.', 'prime-cache' ); ?></p>
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
					<p class="pc-meta"><?php
					/* translators: %s: log file size (e.g. "2.5 MB") */
					printf( esc_html__( 'Log file size: %s', 'prime-cache' ), esc_html( size_format( $log_size ) ) );
					?></p>
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

		$caps = class_exists( 'Prime_Cache_Image_Converter' ) ? Prime_Cache_Image_Converter::get_capabilities() : array( 'gd_webp' => false, 'imagick_webp' => false, 'gd_avif' => false, 'imagick_avif' => false );
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
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown';
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
		// Single source of truth — same logic the rule writer uses.
		$htaccess_write  = Prime_Cache_Htaccess::is_writable();

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
		$lines[] = 'Dropin Loaded: ' . ( defined( 'PRIME_CACHE_ADVANCED_CACHE' ) ? 'Yes' : 'No' );
		$lines[] = 'WP_CACHE (runtime): ' . ( defined( 'WP_CACHE' ) && WP_CACHE ? 'true' : ( defined( 'WP_CACHE' ) ? 'false' : 'undefined' ) );
		$lines[] = 'WP_CACHE (file): ' . ( Prime_Cache_Config::verify_wp_cache_enabled() ? 'true' : 'false/missing' );
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
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $detail is built from esc_html()-escaped values above.
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

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only admin notices shown after nonce-verified cache-clear / stats-reset redirects; flag values are compared to literals or sanitized with sanitize_key().
		if ( isset( $_GET['prime_cache_cleared'] ) && '1' === $_GET['prime_cache_cleared'] )
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cache cleared successfully.', 'prime-cache' ) . '</p></div>';

		if ( isset( $_GET['pc_cleared'] ) ) {
			$cfg = prime_cache_get_settings();
			$extra_notes = array();
			// Add-on integrations: only claim "also purged" when the integration class
			// is actually loaded. Otherwise an orphaned settings flag (add-on deactivated
			// but settings retained) would lie about side effects.
			if ( ! empty( $cfg['varnish_enabled'] ) && class_exists( 'Prime_Cache_Varnish' ) ) {
				$extra_notes[] = __( 'Varnish cache also purged.', 'prime-cache' );
			}
			if ( ! empty( $cfg['sucuri_enabled'] ) && class_exists( 'Prime_Cache_Sucuri' ) ) {
				$extra_notes[] = __( 'Sucuri cache also purged.', 'prime-cache' );
			}
			if ( ! empty( $cfg['cloudflare_enabled'] ) && class_exists( 'Prime_Cache_Cloudflare' ) ) {
				$extra_notes[] = __( 'Cloudflare cache also purged.', 'prime-cache' );
			}
			$sync_note = $extra_notes ? ' ' . implode( ' ', $extra_notes ) : '';
			$msgs = array(
				'all'             => __( 'All caches cleared (page cache, minified files, critical CSS, object cache).', 'prime-cache' ) . $sync_note,
				'preload'         => __( 'All caches cleared. Preloading will start on the next WP-Cron tick (typically within a minute on a site with regular traffic; longer if WP-Cron is disabled or driven by a system cron).', 'prime-cache' ) . $sync_note,
				'preload_partial' => __( 'All caches cleared. Preloading will start on the next WP-Cron tick (default variant only — Vary Cookies active, so cookie-specific variants will be generated on first visitor request).', 'prime-cache' ) . $sync_note,
				'page'            => __( 'Page cache cleared successfully.', 'prime-cache' ) . $sync_note,
				'minified'        => __( 'Minified CSS/JS files cleared successfully.', 'prime-cache' ),
				'ccss'            => __( 'Critical CSS cache cleared successfully.', 'prime-cache' ),
				'object'          => __( 'Object cache flushed successfully.', 'prime-cache' ),
				'url'             => __( 'This page cache cleared successfully.', 'prime-cache' ) . $sync_note,
				'url_error'       => __( 'Could not clear cache for the requested URL. The URL may be invalid, point to a different host, or filesystem permissions prevented removal.', 'prime-cache' ),
				'post'            => __( 'Post cache and related pages cleared successfully.', 'prime-cache' ) . $sync_note,
				'varnish'         => __( 'Varnish cache purge request sent.', 'prime-cache' ),
				'sucuri'          => __( 'Sucuri firewall cache cleared successfully.', 'prime-cache' ),
				'cloudflare'      => __( 'Cloudflare cache purged successfully.', 'prime-cache' ),
				'sucuri_error'    => __( 'Failed to clear Sucuri cache. Check your API key.', 'prime-cache' ),
				'preload_started' => __( 'Cache preloading has been scheduled and will start on the next WP-Cron tick (typically within a minute on a site with regular traffic). If WP-Cron is disabled (DISABLE_WP_CRON) or relies on a system cron, the start may be delayed accordingly.', 'prime-cache' ),
				'preload_started_partial' => __( 'Cache preloading scheduled on the next WP-Cron tick (default variant only). Cookie-specific variants will be generated on first visitor request.', 'prime-cache' ),
				'preload_no_cache' => __( 'Cannot start preload: page caching is disabled. Enable "Cache Enabled" in Page Cache settings first.', 'prime-cache' ),
				'preload_disabled' => __( 'Cannot start preload: preload is disabled. Enable "Cache Preload" in Preload settings first.', 'prime-cache' ),
				'preload_schedule_failed' => __( 'Failed to schedule the preload event. Another plugin or filter may be blocking WP-Cron event registration. Check your error log or run preload manually with WP-CLI.', 'prime-cache' ),
				'reset'           => __( 'All settings have been reset to defaults.', 'prime-cache' ),
			);
			$key   = sanitize_key( $_GET['pc_cleared'] );
			$msg   = $msgs[ $key ] ?? __( 'Cache cleared successfully.', 'prime-cache' );
			$class = in_array( $key, array( 'sucuri_error', 'url_error', 'preload_no_cache', 'preload_disabled', 'preload_schedule_failed' ), true ) ? 'notice-warning' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		if ( isset( $_GET['prime_cache_stats_reset'] ) && '1' === $_GET['prime_cache_stats_reset'] )
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Statistics have been reset.', 'prime-cache' ) . '</p></div>';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

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

/* Pro Features submenu — small inline badge that appears in the WordPress
   sidebar after the "Pro Features" label. Kept restrained (solid colour, small
   text) to read as a wayfinding label rather than promotional ornament. */
.pc-pro-menu-badge{display:inline-block;margin-left:4px;padding:1px 5px;border-radius:999px;background:#1d4ed8;color:#fff;font-size:10px;font-weight:600;line-height:1.4;vertical-align:1px}

/* Pro Features landing page — a single informational screen with a hero,
   foundation/bottlenecks table, outcome cards, recommended-for list, and a
   final CTA. No saveable controls; styling is intentionally calm so the page
   reads as a feature description, not as advertising. */
.pc-pro-features-page{max-width:1080px}
.pc-pro-hero{margin:18px 0 24px;padding:24px;border-radius:12px;background:linear-gradient(135deg,#f7fbff,#eef7ff);border:1px solid #dbeafe}
.pc-pro-hero h1{margin:8px 0 10px;font-size:24px;line-height:1.3}
.pc-pro-hero p{margin:0 0 10px;font-size:14px;line-height:1.6;max-width:780px}
.pc-pro-eyebrow{display:inline-block;padding:2px 8px;border-radius:999px;background:#1d4ed8;color:#fff;font-size:11px;font-weight:600;letter-spacing:.3px}
.pc-pro-cta{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:8px;background:#1d4ed8;color:#fff;text-decoration:none;font-weight:600;font-size:14px}
.pc-pro-cta:hover,.pc-pro-cta:focus{background:#1e40af;color:#fff}
.pc-pro-section{margin:24px 0}
.pc-pro-section__h{margin:0 0 12px;font-size:18px;line-height:1.4}
.pc-pro-steps{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden}
.pc-pro-steps th,.pc-pro-steps td{padding:10px 14px;text-align:left;border-bottom:1px solid #f1f5f9;font-size:14px;line-height:1.5}
.pc-pro-steps thead th{background:#f8fafc;font-weight:600}
.pc-pro-steps tbody tr:last-child td{border-bottom:0}
.pc-pro-card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
.pc-pro-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px}
.pc-pro-card h3{margin:0 0 6px;font-size:14px;line-height:1.4}
.pc-pro-card p{margin:0;font-size:13px;line-height:1.55;color:#4b5563}
.pc-pro-list{margin:0;padding:0;list-style:none}
.pc-pro-list li{padding:8px 0 8px 22px;position:relative;font-size:14px;line-height:1.5;border-bottom:1px solid #f1f5f9}
.pc-pro-list li:last-child{border-bottom:0}
.pc-pro-list li::before{content:"";position:absolute;left:4px;top:14px;width:8px;height:8px;border-radius:50%;background:#1d4ed8}
.pc-pro-footer-cta{margin:24px 0;padding:20px 24px;background:#f7fbff;border:1px solid #dbeafe;border-radius:10px;text-align:left}
.pc-pro-footer-cta h2{margin:0 0 12px;font-size:16px}

/* Single Pro Features pointer card on the dashboard (Phase 2). Reuses the
   pc-card frame so it blends with surrounding KPI/system cards; a thin left
   accent and the existing blue CTA mark it as informational. No shadow, no
   animation, no countdown — strictly a wayfinding card. */
.pc-pro-dashboard-card{margin-top:20px;border-left:3px solid #1d4ed8}
.pc-pro-dashboard-card__h{margin:0 0 8px;font-size:15px;line-height:1.4}
.pc-pro-dashboard-card__body{margin:0 0 12px;font-size:13px;line-height:1.6;color:#4b5563;max-width:780px}
.pc-pro-dashboard-card__cta{margin:0}
.pc-pro-cta--sm{padding:6px 12px;font-size:13px}

/* Per-tab contextual Pro Features card (Phase 3, one per related tab). Shares
   the same restrained dashboard styling; tighter spacing so the card nests at
   the very end of a settings tab without competing with surrounding controls.
   Strictly informational — no fields, no toggles, no warning colour. */
.pc-pro-context-card{margin-top:16px;border-left:3px solid #1d4ed8;background:#fff}
.pc-pro-context-card__h{margin:0 0 6px;font-size:15px;line-height:1.4}
.pc-pro-context-card__body{margin:0 0 10px;font-size:13px;line-height:1.6;color:#4b5563;max-width:760px}
.pc-pro-context-card__cta{margin:0}
.pc-pro-context-card .pc-pro-cta--sm{padding:4px 10px;font-size:13px;line-height:1.6}

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
