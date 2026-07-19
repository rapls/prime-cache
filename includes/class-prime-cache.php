<?php
/**
 * Main plugin class.
 *
 * Orchestrates initialization, activation, and deactivation.
 */

defined( 'ABSPATH' ) || exit;

// Prime Cache manages its own cache files directly for performance; the
// WP_Filesystem API is not used on these cache paths. Disable the direct-file
// sniff for this module.
// phpcs:disable WordPress.WP.AlternativeFunctions

class Prime_Cache {

	/**
	 * Bumped whenever Prime_Cache_Config::write_config_file() emits a new key
	 * the dropin depends on. The constructor regenerates the config file when
	 * the stored schema version is behind, so security-relevant keys (e.g.
	 * $prime_cache_allowed_hosts, $prime_cache_site_scheme) become active
	 * without waiting for an admin visit or a manual settings save.
	 */
	const CONFIG_SCHEMA_VERSION = 13;

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
	/** @var Prime_Cache_File_Optimizer */
	private static $file_optimizer;

	/**
	 * Get the shared File Optimizer instance (for optional add-on delegation).
	 */
	public static function get_file_optimizer() {
		return self::$file_optimizer;
	}

	/**
	 * Get the shared Purge instance (for callers that need post-related purge
	 * with the central tree-purge / hierarchical-aware logic).
	 *
	 * @return Prime_Cache_Purge|null
	 */
	public function get_purge() {
		return $this->purge;
	}

	private function __construct() {
		// Migrate the dropin config file before anything else so security-relevant
		// keys (e.g. $prime_cache_allowed_hosts) are present on the very next request.
		// Cheap once migrated: a single autoloaded option compare.
		$this->maybe_migrate_config_schema();

		$this->purge = new Prime_Cache_Purge();
		self::$file_optimizer = new Prime_Cache_File_Optimizer();
		new Prime_Cache_Preload();
		new Prime_Cache_LazyLoad();
		new Prime_Cache_Media_Optimizer();
		new Prime_Cache_Image_Converter();

		// Add-on classes — initialized by the optional add-on:
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

		add_filter( 'plugin_action_links_' . plugin_basename( PRIME_CACHE_FILE ), array( $this, 'plugin_action_links' ) );

		add_action( 'admin_init', array( $this, 'handle_stats_reset' ) );
		add_action( 'admin_init', array( $this, 'handle_export' ) );
		add_action( 'admin_init', array( $this, 'handle_import' ) );
		add_action( 'admin_init', array( $this, 'handle_object_cache_switch' ) );

		// Regenerate dropin config + .htaccess when WordPress URL or proxy
		// constants change. Without this, $prime_cache_allowed_hosts and
		// $prime_cache_site_scheme stay frozen at the values captured the
		// last time settings were saved, and a domain move / scheme switch
		// silently breaks caching on the new host.
		add_action( 'update_option_home', array( $this, 'regenerate_env_dependent_files' ) );
		add_action( 'update_option_siteurl', array( $this, 'regenerate_env_dependent_files' ) );
		// Drift detection runs on every request (admin AND frontend) so
		// wp-config.php constant changes (`PRIME_CACHE_TRUST_X_FORWARDED_PROTO`,
		// `PRIME_CACHE_PROXY_NO_XFP`, `WP_HOME`, etc.) propagate without
		// waiting for an admin visit. The check itself is one autoloaded
		// option compare per request — cheap.
		if ( ! is_multisite() ) {
			add_action( 'init', array( $this, 'maybe_refresh_env_snapshot' ), 1 );
		}

		// Expired cache cleanup cron (handler only — scheduling done on activation).
		add_action( 'prime_cache_cleanup_expired', array( $this, 'cleanup_expired_cache' ) );

		// Self-heal: if the plugin is active but essential setup is missing
		// (e.g. activation hook was interrupted), repair on the next admin page load.
		if ( is_admin() && ! is_multisite() ) {
			add_action( 'admin_init', array( $this, 'maybe_repair_setup' ) );
		}
	}

	/**
	 * Regenerate the dropin config file if the stored schema version is behind.
	 *
	 * Runs on every request via the constructor so security-relevant config keys
	 * (added in newer plugin versions) become active without waiting for an admin
	 * visit. Once migrated, the cost is one autoloaded option compare per request.
	 */
	/**
	 * Regenerate the dropin config file + .htaccess so they reflect the
	 * current WordPress home/site URL and proxy-related constants. Called
	 * from update_option_home / update_option_siteurl hooks and the env-
	 * snapshot drift detector below.
	 */
	public function regenerate_env_dependent_files() {
		if ( is_multisite() ) {
			return;
		}
		// Track every host we've ever cached for so uninstall can sweep up
		// `wp-content/cache/prime-cache/<old-host>/` directories left behind
		// by domain moves or www↔apex switches. Cap the list to keep the
		// option compact; the install rarely owns more than a handful.
		// Include filter-added aliases (`prime_cache_allowed_hosts`) so the
		// history matches what the drop-in actually caches under.
		require_once PRIME_CACHE_PATH . 'includes/cache-key-functions.php';
		$history     = (array) get_option( 'prime_cache_host_history', array() );
		$current_raw = array();
		foreach ( array( home_url(), site_url() ) as $u ) {
			$h = wp_parse_url( $u, PHP_URL_HOST );
			if ( $h ) {
				$current_raw[] = $h;
			}
		}
		/** This filter is documented in includes/class-config.php */
		$current_raw = apply_filters( 'prime_cache_allowed_hosts', $current_raw );
		if ( ! is_array( $current_raw ) ) {
			$current_raw = array();
		}
		foreach ( $current_raw as $raw_host ) {
			if ( is_string( $raw_host ) && '' !== $raw_host ) {
				$history[] = _prime_cache_normalize_host( $raw_host );
			}
		}
		$history = array_values( array_unique( array_filter( $history ) ) );
		if ( count( $history ) > 20 ) {
			$history = array_slice( $history, -20 );
		}
		update_option( 'prime_cache_host_history', $history, false );

		$settings = prime_cache_get_settings();
		// Only advance the env-snapshot watermark after every required
		// write succeeded — otherwise a transient permission failure would
		// freeze the snapshot at the "we synced" state and the drift
		// detector would stop retrying on later admin visits.
		if ( ! Prime_Cache_Config::write_config_file( $settings ) ) {
			return;
		}
		if ( ! empty( $settings['htaccess_enabled'] ) && class_exists( 'Prime_Cache_Htaccess' ) ) {
			if ( ! Prime_Cache_Htaccess::add_rules( $settings ) ) {
				return;
			}
		}
		update_option( 'prime_cache_env_snapshot', $this->compute_env_snapshot(), false );
	}

	/**
	 * Build a fingerprint of the environment values that get baked into the
	 * dropin config / .htaccess at generation time. Comparing this against a
	 * stored snapshot is how we notice that wp-config.php constants changed
	 * (no hook fires for `define()`).
	 */
	private function compute_env_snapshot() {
		return array(
			'home'         => function_exists( 'home_url' ) ? home_url( '/' ) : '',
			'site'         => function_exists( 'site_url' ) ? site_url( '/' ) : '',
			'trust_xfp'    => defined( 'PRIME_CACHE_TRUST_X_FORWARDED_PROTO' ) && PRIME_CACHE_TRUST_X_FORWARDED_PROTO,
			'proxy_no_xfp' => defined( 'PRIME_CACHE_PROXY_NO_XFP' ) && PRIME_CACHE_PROXY_NO_XFP,
			'strict'       => defined( 'PRIME_CACHE_STRICT_SCHEME' ) && PRIME_CACHE_STRICT_SCHEME,
		);
	}

	/**
	 * Compare current environment fingerprint against the stored snapshot.
	 * On mismatch, regenerate the env-dependent files so .htaccess and the
	 * dropin config catch up to the new wp-config.php values.
	 */
	public function maybe_refresh_env_snapshot() {
		$current = $this->compute_env_snapshot();
		$stored  = get_option( 'prime_cache_env_snapshot', null );
		if ( is_array( $stored ) && $stored === $current ) {
			return;
		}
		$this->regenerate_env_dependent_files();
	}

	private function maybe_migrate_config_schema() {
		if ( is_multisite() ) {
			return;
		}
		$current = (int) get_option( 'prime_cache_config_schema', 0 );
		if ( $current >= self::CONFIG_SCHEMA_VERSION ) {
			return;
		}
		// One-time migration of the Delay JS timeout. Pre-upgrade installs
		// saved the old default `0`, which used to mean "fire after the
		// hardcoded 10s safety timer" — now that the hardcoded fallback is
		// gone, `0` means "never fire until interaction" and breaks
		// analytics/consent/ads on bounce sessions. Bump `0` to the new
		// safer default once so existing sites recover; operators who
		// genuinely want interaction-only mode can re-set it.
		if ( $current < 10 ) {
			$stored = get_option( 'prime_cache_settings', null );
			if ( is_array( $stored )
				&& array_key_exists( 'delay_js_timeout', $stored )
				&& 0 === (int) $stored['delay_js_timeout'] ) {
				$stored['delay_js_timeout'] = 5000;
				update_option( 'prime_cache_settings', $stored, true );
				// Defer the translated warning to `init` — this method runs from
				// the constructor (before `init`), and calling __() here would
				// trip WP 6.7+'s "translation loading triggered too early"
				// (_load_textdomain_just_in_time) notice. The transient is read
				// later on admin_notices, so setting it on init is in time.
				add_action(
					'init',
					function () {
						$warnings   = (array) get_transient( 'prime_cache_activation_warnings' );
						$warnings[] = __( 'Prime Cache upgrade: "Delay JS Timeout" was 0 (no fallback) and has been bumped to 5000ms so delayed scripts still fire on no-interaction sessions. Set it back to 0 if you want interaction-only mode.', 'prime-cache' );
						set_transient( 'prime_cache_activation_warnings', array_values( array_unique( $warnings ) ), 5 * MINUTE_IN_SECONDS );
					},
					11
				);
			}
		}
		$settings = prime_cache_get_settings();
		// Only mark migrated when every write actually succeeded, otherwise
		// transient failures (disk full, permission error) would leave the
		// dropin / .htaccess permanently behind with no automatic retry.
		if ( ! Prime_Cache_Config::write_config_file( $settings ) ) {
			return;
		}
		// 1.10.26 moved the config dir under wp-content/cache/. write_config_file
		// above wrote to the new location; now regenerate advanced-cache.php so
		// the drop-in's baked-in PRIME_CACHE_CONFIG_DIR points there too, then
		// sweep the pre-1.10.26 wp-content/prime-cache-config/ directory. The
		// regeneration only matters when we still own advanced-cache.php — skip
		// it (don't bail the migration) when another plugin manages the drop-in.
		if ( 'external' !== Prime_Cache_Config::get_advanced_cache_owner() ) {
			Prime_Cache_Config::install_advanced_cache();
		}
		Prime_Cache_Config::cleanup_legacy_config_location();
		// Regenerate .htaccess so existing installs pick up new fast-path rules
		// (host allowlist, admin/login excludes, scheme gating, etc.) without
		// waiting for the operator to re-save settings. Schema bumps are the
		// only signal we have that the rule set has changed. If this fails
		// (read-only .htaccess, missing file under tight perms, etc.), keep
		// schema at the old version so we retry on the next request.
		if ( ! empty( $settings['htaccess_enabled'] ) && class_exists( 'Prime_Cache_Htaccess' ) ) {
			if ( ! Prime_Cache_Htaccess::add_rules( $settings ) ) {
				return;
			}
		}
		update_option( 'prime_cache_config_schema', self::CONFIG_SCHEMA_VERSION, true );
	}

	/**
	 * Repair essential setup if missing (self-heal after interrupted activation).
	 *
	 * Checks once per request whether advanced-cache.php, WP_CACHE, and the
	 * config file are in place. Only runs on admin pages so it never adds
	 * latency to front-end requests.
	 */
	public function maybe_repair_setup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings  = prime_cache_get_settings();

		// 1. advanced-cache.php missing or not ours.
		$owner = Prime_Cache_Config::get_advanced_cache_owner();
		if ( 'ours' !== $owner ) {
			Prime_Cache_Config::install_advanced_cache();
		}

		// 2. Config file missing. Mirror the install_seed in Prime_Cache_Config
		// exactly — if the two diverge, the self-heal "file exists" check
		// finds the wrong filename and skips a needed regeneration.
		global $table_prefix;
		$install_seed = ABSPATH . '|' . DB_NAME . '|' . ( isset( $table_prefix ) ? $table_prefix : '' );
		$install_key  = substr( md5( $install_seed ), 0, 8 );
		$config_file  = PRIME_CACHE_CONFIG_DIR . 'site-config-' . $install_key . '.json';

		if ( ! file_exists( $config_file ) ) {
			if ( ! Prime_Cache_Config::write_config_file( $settings ) ) {
				// Surface the failure so admin sees why caching never engages
				// instead of waiting for a self-heal that cannot happen. Reuse
				// the existing activation_warnings transient so the message
				// rides the already-rendered notice channel on the next admin
				// page load.
				$existing   = (array) get_transient( 'prime_cache_activation_warnings' );
				$existing[] = __( 'Prime Cache configuration file could not be regenerated by the self-heal pass. Check that wp-content is writable.', 'prime-cache' );
				set_transient( 'prime_cache_activation_warnings', array_values( array_unique( $existing ) ), 5 * MINUTE_IN_SECONDS );
			}
		}
		// Schema migration is handled in the constructor via maybe_migrate_config_schema().

		// 3. Cron not scheduled.
		if ( ! wp_next_scheduled( 'prime_cache_cleanup_expired' ) ) {
			wp_schedule_event( time(), 'hourly', 'prime_cache_cleanup_expired' );
		}
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

		// Write config file. The dropin needs this to load settings; without it
		// the page-cache layer silently no-ops, so surface the failure instead
		// of leaving the admin to wonder why caching never engages.
		if ( ! Prime_Cache_Config::write_config_file( $settings ) ) {
			$warnings[] = __( 'Prime Cache configuration file could not be written. Check that wp-content is writable — the page cache will not engage until this is resolved.', 'prime-cache' );
		}

		// Install advanced-cache.php dropin.
		$ac_result = Prime_Cache_Config::install_advanced_cache();
		if ( ! $ac_result ) {
			$owner = Prime_Cache_Config::get_advanced_cache_owner();
			if ( 'external' === $owner ) {
				$warnings[] = __( 'advanced-cache.php is managed by another active plugin. Prime Cache page caching will not work until the other plugin is deactivated.', 'prime-cache' );
			}
		}

		// wp-config.php is never touched: page caching engages immediately in
		// standard mode (the plugin runs the page-cache engine itself). If the
		// site owner has added the WP_CACHE line manually, the copied
		// advanced-cache.php drop-in takes over with the faster pre-WordPress
		// serving path. The settings screen explains the optional upgrade.

		// Schedule cron events.
		if ( ! wp_next_scheduled( 'prime_cache_cleanup_expired' ) ) {
			wp_schedule_event( time(), 'hourly', 'prime_cache_cleanup_expired' );
		}

		// Write .htaccess rules if enabled.
		if ( ! empty( $settings['htaccess_enabled'] ) ) {
			Prime_Cache_Htaccess::add_rules( $settings );
		}

		// Trigger cache preloading if enabled. Use Prime_Cache_Preload::request()
		// instead of prime_cache_after_purge_all to avoid logging a false PURGE ALL
		// entry, and to avoid the listener-registration race during activation.
		if ( ! empty( $settings['preload_enabled'] ) && ! empty( $settings['cache_enabled'] ) ) {
			Prime_Cache_Preload::request();
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

		// wp-config.php is never touched (a manually added WP_CACHE line stays;
		// it is harmless without advanced-cache.php).

		// Delete config file.
		Prime_Cache_Config::delete_config_file();

		// Clear all cached files across every host this install owns
		// (matches Prime_Cache_Purge::purge_all() behavior).
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $u ) {
			$h = wp_parse_url( $u, PHP_URL_HOST );
			if ( $h ) {
				$hosts[] = $h;
			}
		}
		foreach ( array_unique( array_filter( $hosts ) ) as $host ) {
			Prime_Cache_Storage::delete_host( $host );
		}
	}

	/**
	 * Add purge button to admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */

	/**
	 * Add action links on the Plugins list page.
	 */
	public function plugin_action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=prime-cache' ) ) . '">' . esc_html__( 'Settings', 'prime-cache' ) . '</a>';
		array_unshift( $links, $settings );
		$manual_url    = ( 0 === strpos( (string) get_user_locale(), 'ja' ) )
			? 'https://raplsworks.com/prime-cache-free-manual-ja/'
			: 'https://raplsworks.com/prime-cache-free-manual-en/';
		$links['docs'] = '<a href="' . esc_url( $manual_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Documentation', 'prime-cache' ) . '</a>';
		if ( ! prime_cache_is_pro() ) {
			$links['pro_features'] = '<a href="' . esc_url( admin_url( 'admin.php?page=prime-cache-pro-features' ) ) . '">' . esc_html__( 'Pro Features', 'prime-cache' ) . '</a>';
		}
		return $links;
	}

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

		// Clear Object Cache. 'broken' means the backend file is missing — the
		// dropin no-ops at runtime so a Clear button would do nothing useful.
		$oc = Prime_Cache_Config::get_active_object_cache();
		if ( 'off' !== $oc && 'external' !== $oc && 'broken' !== $oc ) {
			$wp_admin_bar->add_node( array(
				'id' => 'pc-clear-oc', 'parent' => 'prime-cache',
				'title' => __( 'Clear Object Cache', 'prime-cache' ),
				'href'  => $url( 'clear_object_cache' ),
			) );
		}

		// Purge Varnish Cache.
		if ( prime_cache_is_pro() && $s['varnish_enabled'] ) {
			$wp_admin_bar->add_node( array(
				'id' => 'pc-clear-varnish', 'parent' => 'prime-cache',
				'title' => __( 'Purge Varnish Cache', 'prime-cache' ),
				'href'  => $url( 'purge_varnish' ),
			) );
		}

		// Purge Sucuri Cache.
		if ( prime_cache_is_pro() && $s['sucuri_enabled'] ) {
			$wp_admin_bar->add_node( array(
				'id' => 'pc-clear-sucuri', 'parent' => 'prime-cache',
				'title' => __( 'Purge Sucuri Cache', 'prime-cache' ),
				'href'  => $url( 'purge_sucuri' ),
			) );
		}

		// Purge Cloudflare Cache.
		if ( prime_cache_is_pro() && $s['cloudflare_enabled'] ) {
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
		// Preload is a Free feature. Show the menu whenever it's enabled — the
		// start_preload action handler enforces the cache_enabled precondition.
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
				if ( current_user_can( 'manage_options' ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'prime_cache_purge' ) ) {
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

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'prime_cache_admin_action' ) ) {
			return;
		}

		$action = sanitize_key( $_GET['pc_action'] );
		$msg    = '';

		switch ( $action ) {
			case 'clear_all':
				$this->clear_all_caches();
				$msg = 'all';
				break;

			case 'clear_and_preload':
				$s_tmp = prime_cache_get_settings();
				// Match start_preload's precondition checks so we don't show a
				// "preload scheduled" notice that silently no-ops.
				if ( empty( $s_tmp['cache_enabled'] ) ) {
					$msg = 'preload_no_cache';
					break;
				}
				if ( empty( $s_tmp['preload_enabled'] ) ) {
					$msg = 'preload_disabled';
					break;
				}
				self::sync_stats_to_db();
				$this->purge->purge_all();
				$this->clear_minified_files();
				$this->clear_critical_css_files();
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
				// Call request() directly instead of relying on prime_cache_after_purge_all,
				// which only schedules preload when the listener was registered at bootstrap
				// (i.e. preload_enabled was true at request start). request() works even on
				// a false→true save earlier in the same request lifecycle.
				if ( ! Prime_Cache_Preload::request() ) {
					$msg = 'preload_schedule_failed';
					break;
				}
				// Match start_preload's partial-variant note when cache_vary_cookies
				// is configured: only the default variant is warmed by preload.
				$msg = ! empty( trim( $s_tmp['cache_vary_cookies'] ?? '' ) ) ? 'preload_partial' : 'preload';
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
				$url = isset( $_GET['pc_url'] ) ? esc_url_raw( rawurldecode( sanitize_text_field( wp_unslash( $_GET['pc_url'] ) ) ) ) : '';
				$cleared = false;
				if ( $url ) {
					// Mirror the CLI's flush url validation: require scheme +
					// host that matches one of this install's site hosts.
					// Otherwise a crafted pc_url could target other installs'
					// buckets in shared wp-content setups. Use the shared
					// host normalizer so IDN sites (Unicode vs Punycode mix
					// between home_url() and the supplied URL) compare equal.
					require_once PRIME_CACHE_PATH . 'includes/cache-key-functions.php';
					$parsed_host   = wp_parse_url( $url, PHP_URL_HOST );
					$parsed_scheme = wp_parse_url( $url, PHP_URL_SCHEME );
					$site_hosts    = array();
					foreach ( array( home_url(), site_url() ) as $u ) {
						$h = wp_parse_url( $u, PHP_URL_HOST );
						if ( $h ) {
							$site_hosts[] = $h;
						}
					}
					/** This filter is documented in includes/class-config.php */
					$site_hosts = apply_filters( 'prime_cache_allowed_hosts', $site_hosts );
					if ( ! is_array( $site_hosts ) ) {
						$site_hosts = array();
					}
					$site_hosts = array_map(
						function ( $h ) {
							return is_string( $h ) ? _prime_cache_normalize_host( $h ) : '';
						},
						$site_hosts
					);
					$site_hosts = array_values( array_unique( array_filter( $site_hosts ) ) );
					$norm_host  = $parsed_host ? _prime_cache_normalize_host( $parsed_host ) : '';
					// Path-prefix gate for shared-host multi-install setups
					// (`/site-a/` and `/site-b/` on the same domain). Without
					// this, /site-a's pc_url could clear cached entries that
					// belong to /site-b. home_url() reflects this install's
					// base path; require the URL to fall under it.
					$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
					$home_path = is_string( $home_path ) && '' !== $home_path ? $home_path : '/';
					if ( '/' !== substr( $home_path, -1 ) ) {
						$home_path .= '/';
					}
					$url_path = wp_parse_url( $url, PHP_URL_PATH );
					$url_path = is_string( $url_path ) && '' !== $url_path ? $url_path : '/';
					$path_ok  = ( '/' === $home_path ) || 0 === strpos( $url_path . '/', $home_path );
					if ( '' !== $norm_host && $parsed_scheme
						&& ( 'http' === $parsed_scheme || 'https' === $parsed_scheme )
						&& in_array( $norm_host, $site_hosts, true )
						&& $path_ok
					) {
						$cleared = (bool) Prime_Cache_Storage::delete_url( $url );
					}
				}
				$msg = $cleared ? 'url' : 'url_error';
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
				$s_tmp = prime_cache_get_settings();
				// Refuse if the preconditions for preload to actually do anything
				// aren't met, so the user sees an error instead of a misleading
				// "scheduled" success message that silently no-ops.
				if ( empty( $s_tmp['cache_enabled'] ) ) {
					$msg = 'preload_no_cache';
					break;
				}
				if ( empty( $s_tmp['preload_enabled'] ) ) {
					$msg = 'preload_disabled';
					break;
				}
				if ( ! Prime_Cache_Preload::request() ) {
					$msg = 'preload_schedule_failed';
					break;
				}
				$msg = ! empty( trim( $s_tmp['cache_vary_cookies'] ?? '' ) ) ? 'preload_started_partial' : 'preload_started';
				break;

			case 'reset_settings':
				delete_option( 'prime_cache_settings' );
				$defaults = prime_cache_get_settings( true );
				if ( ! is_multisite() ) {
					$reset_warnings = array();
					if ( ! Prime_Cache_Config::write_config_file( $defaults ) ) {
						$reset_warnings[] = __( 'Settings reset in the database, but the drop-in config file could not be written. Page caching may keep using the old config until the file is writable.', 'prime-cache' );
					}
					$ac_ok = Prime_Cache_Config::install_advanced_cache();
					Prime_Cache_Htaccess::remove_rules();
					if ( ! $ac_ok && 'external' === Prime_Cache_Config::get_advanced_cache_owner() ) {
						$reset_warnings[] = __( 'Settings reset but advanced-cache.php is managed by another plugin. Page caching will not work until the other plugin is deactivated.', 'prime-cache' );
					}
					if ( ! empty( $reset_warnings ) ) {
						set_transient( 'prime_cache_env_warnings', $reset_warnings, 60 );
					}
				}
				// Purge everything after a reset. Pages cached under the old
				// optimization settings (e.g. Remove Unused CSS, Delay JS) would
				// otherwise keep serving stale, potentially broken markup even
				// though the settings that produced them are gone.
				$this->clear_all_caches();
				$redirect = add_query_arg( array( 'tab' => 'tools', 'pc_cleared' => 'reset' ), admin_url( 'admin.php?page=prime-cache' ) );
				wp_safe_redirect( $redirect );
				exit;

			case 'apply_preset':
				$preset = sanitize_key( $_GET['preset'] ?? '' );
				$preset_settings = self::get_preset( $preset );
				$preset_applied  = false;
				if ( $preset_settings ) {
					$preset_applied = true;
					$current = prime_cache_get_settings();
					$merged  = array_merge( $current, $preset_settings );

					// Compute derived fields that sanitize_settings() normally calculates.
					$ocd    = ! empty( $merged['optimize_css_delivery'] );
					$method = isset( $merged['css_delivery_method'] ) ? $merged['css_delivery_method'] : 'remove_unused_css';
					$merged['async_css']        = $ocd && 'async_css' === $method;
					$merged['remove_unused_css'] = $ocd && 'remove_unused_css' === $method;

					// Same coupling as sanitize_settings(): delay_js needs both
					// cache_mobile and cache_mobile_separate. optional add-on presets (via the
					// prime_cache_preset_* filter) might set delay_js without these.
					if ( ! empty( $merged['delay_js'] ) ) {
						$merged['cache_mobile']          = true;
						$merged['cache_mobile_separate'] = true;
					}

					update_option( 'prime_cache_settings', $merged );
					if ( ! is_multisite() ) {
						$preset_warnings = array();
						if ( ! Prime_Cache_Config::write_config_file( $merged ) ) {
							$preset_warnings[] = __( 'Preset applied to the database, but the drop-in config file could not be written. Page caching may not reflect the preset until the file is writable.', 'prime-cache' );
						}
						$ac_ok = Prime_Cache_Config::install_advanced_cache();
						if ( $merged['htaccess_enabled'] ) {
							Prime_Cache_Htaccess::add_rules( $merged );
						} else {
							Prime_Cache_Htaccess::remove_rules();
						}
						if ( ! $ac_ok && 'external' === Prime_Cache_Config::get_advanced_cache_owner() ) {
							$preset_warnings[] = __( 'Preset applied but advanced-cache.php is managed by another plugin. Page caching will not work until the other plugin is deactivated.', 'prime-cache' );
						}
						if ( ! empty( $preset_warnings ) ) {
							set_transient( 'prime_cache_env_warnings', $preset_warnings, 60 );
						}
					}
				}
				$redirect_args = $preset_applied
					? array( 'tab' => 'tools', 'pc_preset' => $preset )
					: array( 'tab' => 'tools', 'pc_preset_error' => $preset );
				$redirect = add_query_arg( $redirect_args, admin_url( 'admin.php?page=prime-cache' ) );
				wp_safe_redirect( $redirect );
				exit;

			case 'toggle_cache':
				$s = prime_cache_get_settings();
				$s['cache_enabled'] = empty( $s['cache_enabled'] );
				update_option( 'prime_cache_settings', $s );
				if ( ! is_multisite() ) {
					if ( ! Prime_Cache_Config::write_config_file( $s ) ) {
						set_transient( 'prime_cache_env_warnings', array(
							__( 'Cache toggled in the database, but the drop-in config file could not be written. The change may not take effect until the file is writable.', 'prime-cache' ),
						), 60 );
					}
					// Sync .htaccess fast-path with the new cache_enabled state.
					// Without this, disabling cache leaves the rewrite rules in place
					// and Apache keeps serving cached files directly, bypassing the
					// drop-in's cache_enabled check entirely.
					//
					// Always re-emit via add_rules($s) — build_rules() already gates
					// the rewrite block behind $settings['cache_enabled'], so the
					// non-cache features (mod_deflate, mod_expires when browser_cache
					// is on, security headers, HSTS, etc.) are preserved.
					if ( ! empty( $s['htaccess_enabled'] ) ) {
						Prime_Cache_Htaccess::add_rules( $s );
					}
				}
				$tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );
				wp_safe_redirect( admin_url( 'admin.php?page=prime-cache&tab=' . $tab ) );
				exit;

			default:
				return;
		}

		// Redirect back to the referring page, or fall back to the Prime Cache dashboard.
		$referer = wp_get_referer();
		if ( $referer ) {
			$redirect = remove_query_arg( array( 'pc_action', 'pc_url', 'pc_post_id', '_wpnonce', 'pc_cleared' ), $referer );
		} else {
			$redirect = admin_url( 'admin.php?page=prime-cache' );
		}
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
					'preload_fonts'        => false,
					'defer_js'             => true,
					// Keep combining off for stability.
					'combine_css'          => false,
					'combine_js'           => false,
					'delay_js'             => false,
				) );

			case 'aggressive':
				$preset = array_merge( $common, array(
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
					'defer_js'              => true,
					'delay_js'              => true,
					'disable_emoji'         => true,
					'disable_wp_embed'      => true,
					'disable_dashicons'     => true,
					'disable_oembed'        => true,
					'disable_block_css'     => true,
					'remove_query_strings'  => true,
					'delay_js_safe_mode'    => true,
					'inline_small_css'      => true,
					'async_css_free'        => true,
					'local_jquery'          => true,
					'limit_dns_prefetch'    => true,
					'google_fonts_display'  => true,
					'cache_mobile_separate' => true,
					'preload_enabled'       => true,
					'preload_homepage'      => true,
					'preload_public_posts'  => true,
				) );
				// The optional add-on enhances presets with additional features.
				return apply_filters( 'prime_cache_preset_aggressive', $preset );

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
		// Use the canonical helper so the writability check matches what the
		// .htaccess writer actually does (file when it exists, parent dir when not).
		$htaccess_ok = Prime_Cache_Htaccess::is_writable();

		// HTTP/2 detection: check via headers_list() or SERVER_PROTOCOL.
		$http2 = false;
		if ( function_exists( 'apache_get_modules' ) && in_array( 'mod_http2', apache_get_modules(), true ) ) {
			$http2 = true;
		} elseif ( ! empty( $_SERVER['SERVER_PROTOCOL'] ) && version_compare( str_replace( 'HTTP/', '', sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ) ), '2', '>=' ) ) {
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
		// wp_is_block_theme() was introduced in WP 5.9. "Requires at least: 5.8" is
		// intentionally kept, so the call is gated by function_exists() and dispatched
		// dynamically via call_user_func() to avoid Plugin Check's static
		// wp_function_not_compatible_with_requires_wp report.
		$wp_is_block_theme_fn = 'wp_is_block_theme';
		$is_block_theme       = function_exists( $wp_is_block_theme_fn ) && (bool) call_user_func( $wp_is_block_theme_fn );
		$post_count    = (int) wp_count_posts()->publish;
		$is_pro        = prime_cache_is_pro();

		// ── Build settings ───────────────────────────────────────────
		$s = array(
			// Core — always on.
			'cache_enabled'         => true,
			'cache_mobile'          => true,
			'cache_mobile_separate' => true, // required: delay JS is mobile-only
			'gzip_compression'      => true,
			'cache_footprint'       => false, // Auto preset overrides the global default (true) — keep production HTML free of the cache-stamp comment.

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

			// jQuery — restore local copy if theme uses CDN.
			'local_jquery'          => true,

			// Defer JS — Free feature, safe for all sites.
			'defer_js'              => true,

			// Inline small CSS + async non-first CSS (Free features).
			'inline_small_css'      => true,
			'async_css_free'        => true,

			// Google Fonts display swap (Free feature).
			'google_fonts_display'   => true,

			// Cleanup — safe tweaks.
			'disable_emoji'         => true,
			'disable_wp_embed'      => true,
			'disable_oembed'        => true,
			'remove_query_strings'  => true,
			'limit_dns_prefetch'    => true,

			// Block CSS — keep enabled for block themes.
			'disable_block_css'     => ! $is_block_theme,

			// Links.
			'preload_links'         => true,

			// Preload.
			'preload_enabled'       => true,
			'preload_homepage'      => true,
			'preload_public_posts'  => true,
		);

		// The optional add-on enhances the auto preset with additional features.
		$s = apply_filters( 'prime_cache_preset_auto', $s );

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

		// The optional add-on adds image conversion, object cache, and other add-on settings
		// via the prime_cache_preset_auto filter above.

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
		$sw     = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );
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

		// .htaccess. Same logic as the rule writer (Prime_Cache_Htaccess::is_writable):
		// when the file exists, only the file's writability matters; when missing,
		// the parent directory's writability matters (so we can create it).
		$info[ '.htaccess' ] = Prime_Cache_Htaccess::is_writable() ? __( 'Writable', 'prime-cache' ) : __( 'Not writable', 'prime-cache' );

		// HTTP protocol.
		$proto = sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1' ) );
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
		// wp_is_block_theme() was introduced in WP 5.9; the function_exists() guard
		// makes the call safe on WP 5.8, and dynamic dispatch through call_user_func()
		// avoids Plugin Check's static wp_function_not_compatible_with_requires_wp
		// report so "Requires at least: 5.8" can be kept.
		$wp_is_block_theme_fn = 'wp_is_block_theme';
		$type  = ( function_exists( $wp_is_block_theme_fn ) && (bool) call_user_func( $wp_is_block_theme_fn ) )
			? __( 'Block Theme', 'prime-cache' )
			: __( 'Classic Theme', 'prime-cache' );
		$info[ __( 'Theme', 'prime-cache' ) ] = $theme->get( 'Name' ) . ' (' . $type . ')';

		// Posts.
		$info[ __( 'Published Posts', 'prime-cache' ) ] = number_format_i18n( (int) wp_count_posts()->publish );

		// WooCommerce.
		if ( class_exists( 'WooCommerce' ) ) {
			$info[ 'WooCommerce' ] = WC()->version;
		}

		// Optional add-on — only surfaced when an add-on is actually active, so the
		// free plugin's System Info never advertises an inactive add-on.
		if ( prime_cache_is_pro() ) {
			$info[ __( 'Optional Add-on', 'prime-cache' ) ] = __( 'Active', 'prime-cache' );
		}

		return $info;
	}

	/**
	 * Run the full "Clear All Cache" workflow used by the admin button.
	 *
	 * Persists stats, purges every host's page cache, deletes minified/critical
	 * CSS files, and flushes the object cache. Public so the WP-CLI command and
	 * other entry points can invoke the exact same sequence as the admin UI.
	 */
	public function clear_all_caches() {
		self::sync_stats_to_db();
		$this->purge->purge_all();
		$this->clear_minified_files();
		$this->clear_critical_css_files();
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}

	/**
	 * Delete all minified/combined CSS/JS files in the file optimizer cache.
	 */
	private function clear_minified_files() {
		$fo_dir = WP_CONTENT_DIR . '/cache/prime-cache-fo/';
		if ( ! is_dir( $fo_dir ) ) {
			return;
		}

		// Boundary check: refuse to recurse if the dir is a symlink that
		// resolves outside our cache root. Without this, an iterator would
		// follow the link and unlink files in the target.
		$cache_root = WP_CONTENT_DIR . '/cache/';
		if ( class_exists( 'Prime_Cache_File_Optimizer' )
			&& ! Prime_Cache_File_Optimizer::path_within( realpath( $fo_dir ), realpath( $cache_root ) ) ) {
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
		// Boundary check: refuse to glob into a symlinked target outside the
		// cache root. Without this, ccss/ → /etc/ssl/private would let an
		// admin-context purge unlink files outside our cache.
		$cache_root = WP_CONTENT_DIR . '/cache/';
		if ( ! class_exists( 'Prime_Cache_File_Optimizer' )
			|| ! Prime_Cache_File_Optimizer::path_within( realpath( $ccss_dir ), realpath( $cache_root ) ) ) {
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

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'prime_cache_reset_stats' ) ) {
			return;
		}

		// Reset DB stats.
		update_option( 'prime_cache_stats', array( 'hit' => 0, 'miss' => 0, 'preload' => 0, 'since' => time() ), false );

		// Reset file-based stats.
		$stats_file = PRIME_CACHE_CACHE_DIR . 'stats.json';
		$data = wp_json_encode( array( 'hit' => 0, 'miss' => 0, 'preload' => 0, 'since' => time() ) );
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

		// Mode 'c+' (read+write) — not 'c' (write-only). stream_get_contents()
		// below reads the existing counters, which fails with "Bad file
		// descriptor" on a write-only handle.
		$fp = fopen( $stats_file, 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fp ) {
			return;
		}

		flock( $fp, LOCK_EX );
		fseek( $fp, 0 );
		$raw = stream_get_contents( $fp );
		$file_stats = $raw ? json_decode( $raw, true ) : null;

		if ( is_array( $file_stats ) ) {
			$db = get_option( 'prime_cache_stats', array( 'hit' => 0, 'miss' => 0, 'preload' => 0, 'since' => 0 ) );
			$db['hit']     = (int) ( $db['hit'] ?? 0 ) + (int) ( $file_stats['hit'] ?? 0 );
			$db['miss']    = (int) ( $db['miss'] ?? 0 ) + (int) ( $file_stats['miss'] ?? 0 );
			$db['preload'] = (int) ( $db['preload'] ?? 0 ) + (int) ( $file_stats['preload'] ?? 0 );
			if ( ! $db['since'] && ! empty( $file_stats['since'] ) ) {
				$db['since'] = (int) $file_stats['since'];
			}
			update_option( 'prime_cache_stats', $db, false );

			// Reset file counters to zero (keep since).
			ftruncate( $fp, 0 );
			fseek( $fp, 0 );
			fwrite( $fp, json_encode( array( 'hit' => 0, 'miss' => 0, 'preload' => 0, 'since' => $db['since'] ) ) );
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

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'prime_cache_object_cache' ) ) {
			return;
		}

		$backend = sanitize_key( $_GET['prime_cache_object_cache'] );
		$allowed = array_merge( array( 'off' ), array_keys( Prime_Cache_Config::get_available_object_caches() ) );

		if ( ! in_array( $backend, $allowed, true ) ) {
			return;
		}

		// Object cache is an add-on feature: only allow ENABLING a backend when the
		// add-on is active and licensed. Always allow 'off' so an admin can still
		// remove our own drop-in if the add-on/license later becomes inactive,
		// rather than being stranded on a stale object-cache configuration.
		if ( 'off' !== $backend && ! prime_cache_is_pro() ) {
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
		$db = get_option( 'prime_cache_stats', array( 'hit' => 0, 'miss' => 0, 'preload' => 0, 'since' => 0 ) );
		$hs = wp_parse_args( $db, array( 'hit' => 0, 'miss' => 0, 'preload' => 0, 'since' => 0 ) );
		$stats_file = PRIME_CACHE_CACHE_DIR . 'stats.json';
		if ( is_readable( $stats_file ) ) {
			$d = json_decode( file_get_contents( $stats_file ), true ); // phpcs:ignore
			if ( is_array( $d ) ) {
				$hs['hit']     += (int) ( $d['hit'] ?? 0 );
				$hs['miss']    += (int) ( $d['miss'] ?? 0 );
				$hs['preload'] += (int) ( $d['preload'] ?? 0 );
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
				// An unreadable subdirectory throws UnexpectedValueException
				// mid-iteration — show partial stats instead of a fatal.
				try {
					$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( PRIME_CACHE_CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS ) );
					foreach ( $it as $f ) { if ( $f->isFile() && 'html' === $f->getExtension() ) $dir_stats['files']++; if ( $f->isFile() ) $dir_stats['size'] += $f->getSize(); }
				} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				}
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
			<li><?php esc_html_e( 'HIT', 'prime-cache' ); ?>: <b><?php echo esc_html( number_format( $hs['hit'] ) ); ?></b> / <?php esc_html_e( 'MISS', 'prime-cache' ); ?>: <b><?php echo esc_html( number_format( $hs['miss'] ) ); ?></b> / <?php esc_html_e( 'Preload', 'prime-cache' ); ?>: <b><?php echo esc_html( number_format( $hs['preload'] ) ); ?></b></li>
			<li><?php esc_html_e( 'Size', 'prime-cache' ); ?>: <b><?php echo esc_html( size_format( $size ) ); ?></b></li>
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
					return false === strpos( $url, 's.w.org' );
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
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'pc_export' ) ) {
			return;
		}

		// Use the merged-with-defaults snapshot, not the raw stored option.
		// get_option returns only the keys actually written to the DB; on a
		// fresh install most settings live in defaults and the JSON would
		// otherwise omit them, contradicting the UI promise of "all settings".
		$settings = prime_cache_get_settings();

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
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $data is JSON from wp_json_encode(), output as file download with Content-Type: application/json header.
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
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'pc_import' ) ) {
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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- is_uploaded_file() validates this server-generated temp path; unslashing/sanitizing it would corrupt Windows paths.
		if ( ! is_uploaded_file( $_FILES['pc_import_file']['tmp_name'] ) ) {
			wp_safe_redirect( $error_url );
			exit;
		}

		// Size limit: 256 KB max for a settings JSON.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- numeric upload size from the file validated by is_uploaded_file() above; cast to int.
		if ( (int) ( $_FILES['pc_import_file']['size'] ?? 0 ) > 262144 ) {
			wp_safe_redirect( $error_url );
			exit;
		}

		// Extension check.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- the upload was validated (nonce, is_uploaded_file, size) above; only the extension is read here, and it is sanitized.
		$ext = strtolower( pathinfo( sanitize_text_field( wp_unslash( $_FILES['pc_import_file']['name'] ) ), PATHINFO_EXTENSION ) );
		if ( 'json' !== $ext ) {
			wp_safe_redirect( $error_url );
			exit;
		}

		// MIME type validation via finfo (content-based, not trust client header).
		// finfo_open can return false on hosts where the magic database is
		// unavailable; calling finfo_file(false, ...) errors on PHP 8. Skip the
		// MIME check entirely in that case rather than aborting the import,
		// since extension + size + JSON-decode are still enforced below.
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( false !== $finfo ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- tmp_name is the server-generated upload path validated by is_uploaded_file() above; unslashing/sanitizing would corrupt Windows paths.
				$mime = finfo_file( $finfo, $_FILES['pc_import_file']['tmp_name'] );
				finfo_close( $finfo );
				// JSON files may report as application/json or text/plain.
				if ( $mime && ! in_array( $mime, array( 'application/json', 'text/plain', 'text/json' ), true ) ) {
					wp_safe_redirect( $error_url );
					exit;
				}
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

		// Backfill keys missing from the JSON with the user's current values so
		// partial imports (older exports, hand-edited JSON, exports from a setup
		// that didn't define every key) don't silently flip every absent boolean
		// to false through `!empty($input[key])` in sanitize_settings().
		$data = wp_parse_args( $data, $current );

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

		$now = time();

		// An unreadable subdirectory throws UnexpectedValueException mid-
		// iteration — skip this cron run instead of fataling inside WP-Cron.
		try {
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
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Retry on the next scheduled run.
		}
	}
}
