<?php
/**
 * Plugin Name: Prime Cache
 * Description: A fast and stable page caching plugin for WordPress.
 * Version: 1.0.0
 * Author: Min
 * License: GPL-2.0-or-later
 * Text Domain: prime-cache
 */

defined( 'ABSPATH' ) || exit;

define( 'PRIME_CACHE_VERSION', '1.0.0' );
define( 'PRIME_CACHE_FILE', __FILE__ );
define( 'PRIME_CACHE_PATH', plugin_dir_path( __FILE__ ) );

if ( ! defined( 'PRIME_CACHE_CACHE_DIR' ) ) {
	define( 'PRIME_CACHE_CACHE_DIR', WP_CONTENT_DIR . '/cache/prime-cache/' );
}
if ( ! defined( 'PRIME_CACHE_CONFIG_DIR' ) ) {
	define( 'PRIME_CACHE_CONFIG_DIR', WP_CONTENT_DIR . '/prime-cache-config/' );
}

define( 'PRIME_CACHE_DROPIN_SOURCE', PRIME_CACHE_PATH . 'dropins/page-cache.php' );

require_once PRIME_CACHE_PATH . 'includes/class-cache-storage.php';
require_once PRIME_CACHE_PATH . 'includes/class-cache-tests.php';
require_once PRIME_CACHE_PATH . 'includes/class-config.php';
require_once PRIME_CACHE_PATH . 'includes/class-purge.php';
require_once PRIME_CACHE_PATH . 'includes/class-htaccess.php';
require_once PRIME_CACHE_PATH . 'includes/class-file-optimizer.php';
require_once PRIME_CACHE_PATH . 'includes/class-preload.php';
require_once PRIME_CACHE_PATH . 'includes/class-database-optimizer.php';
require_once PRIME_CACHE_PATH . 'includes/class-varnish.php';
require_once PRIME_CACHE_PATH . 'includes/class-sucuri.php';
require_once PRIME_CACHE_PATH . 'includes/class-heartbeat.php';
require_once PRIME_CACHE_PATH . 'includes/class-lazyload.php';
require_once PRIME_CACHE_PATH . 'includes/class-cdn.php';
require_once PRIME_CACHE_PATH . 'includes/class-cloudflare.php';
require_once PRIME_CACHE_PATH . 'includes/class-webp.php';
require_once PRIME_CACHE_PATH . 'includes/class-media-optimizer.php';
require_once PRIME_CACHE_PATH . 'includes/class-post-metabox.php';
require_once PRIME_CACHE_PATH . 'includes/class-compatibility.php';

require_once PRIME_CACHE_PATH . 'includes/class-performance-tweaks.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once PRIME_CACHE_PATH . 'includes/class-cli.php';
}
require_once PRIME_CACHE_PATH . 'includes/class-prime-cache.php';

if ( is_admin() ) {
	require_once PRIME_CACHE_PATH . 'includes/admin/class-admin-settings.php';
}

/**
 * Get plugin settings with defaults.
 *
 * @return array
 */
function prime_cache_get_settings( $force = false ) {
	static $cached = null;
	if ( null !== $cached && ! $force ) {
		return $cached;
	}
	$defaults = array(
		'cache_enabled'         => true,
		'cache_mobile'          => true,
		'cache_mobile_separate' => false,
		'cache_logged_in'       => false,
		'gzip_compression'      => true,
		'cache_reject_uri'      => '',
		'cache_reject_cookies'  => '',
		'cache_reject_ua'       => '',
		'cache_reject_referrer' => '',
		'cache_vary_cookies'    => '',
		'cache_query_strings'   => '',
		'purge_additional_urls' => '',
		'purge_on_post_update'  => true,
		'purge_on_post_delete'  => true,
		'purge_on_comment'      => true,
		'purge_on_term_change'  => true,
		'purge_on_theme_switch' => true,
		'purge_on_permalink'    => true,
		'purge_on_plugin_change' => true,
		'purge_on_customizer'   => true,
		'purge_on_widget'       => true,
		'purge_on_nav_menu'     => true,
		'purge_on_core_update'  => true,
		'purge_on_user_update'  => true,
		'minify_html'           => false,
		'minify_html_dom'       => false,
		'remove_html_comments'  => false,
		'minify_css'            => false,
		'combine_css'           => false,
		'optimize_css_delivery'  => false,
		'css_delivery_method'   => 'remove_unused_css',
		'async_css'             => false,
		'critical_css'          => '',
		'critical_css_auto'     => false,
		'remove_unused_css'     => false,
		'ucss_safelist'         => '',
		'exclude_css'           => '',
		'minify_js'             => false,
		'combine_js'            => false,
		'defer_js'              => false,
		'delay_js'              => false,
		'delay_js_timeout'      => 0,
		'exclude_js'            => '',
		'exclude_inline_js'     => '',
		'exclude_defer_js'      => '',
		'exclude_delay_js'      => '',
		'combine_google_fonts'  => false,
		'self_host_google_fonts' => false,
		'google_fonts_display'  => true,
		'remove_query_strings'  => false,
		'rewrite_file_optimizer' => false,
		'preload_enabled'       => false,
		'preload_homepage'      => true,
		'preload_public_posts'  => true,
		'preload_public_tax'    => true,
		'preload_sitemap_enabled' => false,
		'preload_sitemap'       => '',
		'preload_interval'      => 2,
		'preload_max_posts'     => 500,
		'preload_max_terms'     => 200,
		'preload_excluded_uri'  => '',
		'preload_links'         => true,
		'preload_fonts'         => false,
		'lcp_optimization'      => false,
		'lcp_excluded'          => '',
		'prefetch_dns'          => '',
		'preconnect'            => '',
		'varnish_enabled'       => false,
		'varnish_ip'            => '',
		'sucuri_enabled'        => false,
		'sucuri_api_key'        => '',
		'heartbeat_enabled'     => false,
		'heartbeat_frontend'    => 'disable',
		'heartbeat_admin'       => 'modify',
		'heartbeat_editor'      => 'enable',
		'heartbeat_admin_interval'    => 120,
		'heartbeat_frontend_interval' => 60,
		'lazyload_images'       => false,
		'lazyload_iframes'      => false,
		'lazyload_videos'       => false,
		'lazyload_disable_native' => false,
		'lazyload_exclude'      => '',
		'cdn_enabled'           => false,
		'cdn_hostname'          => '',
		'cdn_include_dirs'      => 'wp-content,wp-includes',
		'cdn_exclude'           => '.php',
		'cdn_relative'          => true,
		'cloudflare_enabled'    => false,
		'cloudflare_email'      => '',
		'cloudflare_api_key'    => '',
		'cloudflare_zone_id'    => '',
		'img_conversion_enabled' => false,
		'webp_enabled'          => false,
		'avif_enabled'          => false,
		'img_quality_mode'      => 'lossy',
		'webp_quality'          => 80,
		'avif_quality'          => 60,
		'img_strip_exif'        => false,
		'img_resize'            => false,
		'img_max_width'         => 2560,
		'img_max_height'        => 2560,
		'img_auto_optimize'     => false,
		'img_auto_remove_larger' => true,
		'img_exclude_png'       => false,
		'img_include_uploads'   => true,
		'img_include_themes'    => false,
		'img_include_plugins'   => false,
		'img_include_custom'    => '',
		'img_exclude_folders'   => '',
		'img_delivery_method'   => 'rewrite',
		'img_converter'         => 'auto',
		'youtube_thumbnail'     => false,
		'add_missing_dimensions' => false,
		'hsts_enabled'          => false,
		'hsts_max_age'          => 31536000,
		'security_headers'      => false,
		'disable_emoji'         => false,
		'disable_jquery_migrate' => false,
		'disable_wp_embed'      => false,
		'disable_dashicons'     => false,
		'disable_wp_version'    => false,
		'disable_xmlrpc'        => false,
		'disable_self_pingback' => false,
		'limit_revisions'       => false,
		'revisions_max'         => 5,
		'disable_rss_feeds'     => false,
		'disable_oembed'        => false,
		'disable_block_css'     => false,
		'disable_google_fonts'  => false,
		'disable_global_styles' => false,
		'disable_shortlink'     => false,
		'disable_rsd_wlw'       => false,
		'disable_rest_api_link' => false,
		'disable_wp_sitemap'    => false,
		'add_blank_favicon'     => false,
		'woo_disable_scripts'   => false,
		'woo_disable_cart_frag' => false,
		'delay_js_safe_mode'    => false,
		'delay_js_presets'      => '',
		'inline_small_css'      => false,
		'inline_css_threshold'  => 8192,
		'local_analytics'       => false,
		'preload_resources'     => '',
		'speculation_rules'     => false,
		'cache_404'             => false,
		'debug_log'             => false,
		'db_revisions'          => false,
		'db_auto_drafts'        => false,
		'db_trashed_posts'      => false,
		'db_spam_comments'      => false,
		'db_trashed_comments'   => false,
		'db_expired_transients' => false,
		'db_all_transients'     => false,
		'db_optimize_tables'    => false,
		'db_auto_cleanup'       => false,
		'db_cleanup_frequency'  => 'weekly',
		'htaccess_enabled'      => false,
		'browser_cache'         => false,
		'browser_cache_css_js'  => 31536000,
		'browser_cache_images'  => 15552000,
		'browser_cache_fonts'   => 15552000,
		'browser_cache_html'    => 0,
		'brotli_compression'    => false,
		'cache_control_immutable' => false,
		'cache_lifespan'        => 0,
		'cache_footprint'       => true,
		'cache_ignore_qs'       => 'utm_source, utm_medium, utm_campaign, utm_term, utm_content, utm_expid, fbclid, gclid, ga_source, ga_medium, ga_campaign, ga_term, ga_content',
	);

	$settings = get_option( 'prime_cache_settings', array() );

	$cached = wp_parse_args( $settings, $defaults );
	return $cached;
}

add_action( 'init', function() {
	load_plugin_textdomain( 'prime-cache', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

register_activation_hook( __FILE__, array( 'Prime_Cache', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Prime_Cache', 'deactivate' ) );

Prime_Cache::get_instance();
