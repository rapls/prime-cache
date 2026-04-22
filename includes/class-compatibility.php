<?php
/**
 * Compatibility — detect conflicts with other caching plugins.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Compatibility {

	/** Known conflicting plugins: slug => display name. */
	const CONFLICTS = array(
		'wp-super-cache/wp-cache.php'           => 'WP Super Cache',
		'w3-total-cache/w3-total-cache.php'     => 'W3 Total Cache',
		'wp-rocket/wp-rocket.php'               => 'WP Rocket',
		'powered-cache/powered-cache.php'       => 'Powered Cache',
		'litespeed-cache/litespeed-cache.php'    => 'LiteSpeed Cache',
		'wp-fastest-cache/wpFastestCache.php'    => 'WP Fastest Cache',
		'cache-enabler/cache-enabler.php'        => 'Cache Enabler',
		'comet-cache/comet-cache.php'            => 'Comet Cache',
		'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
		'breeze/breeze.php'                      => 'Breeze',
		'sg-cachepress/sg-cachepress.php'        => 'SG Optimizer',
		'nitropack/main.php'                     => 'NitroPack',
		'autoptimize/autoptimize.php'            => 'Autoptimize',
		'fast-velocity-minify/fvm.php'           => 'Fast Velocity Minify',
	);

	public function __construct() {
		add_action( 'admin_notices', array( $this, 'check_conflicts' ) );
	}

	/**
	 * Show admin notice if a conflicting plugin is active.
	 */
	public function check_conflicts() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active  = get_option( 'active_plugins', array() );
		$found   = array();

		foreach ( self::CONFLICTS as $slug => $name ) {
			if ( in_array( $slug, $active, true ) ) {
				$found[] = $name;
			}
		}

		// Multisite: check network-activated plugins.
		if ( is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );
			foreach ( self::CONFLICTS as $slug => $name ) {
				if ( isset( $network[ $slug ] ) && ! in_array( $name, $found, true ) ) {
					$found[] = $name;
				}
			}
		}

		if ( empty( $found ) ) {
			return;
		}

		$list = '<strong>' . implode( '</strong>, <strong>', array_map( 'esc_html', $found ) ) . '</strong>';
		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: %s: list of plugin names */
			esc_html__( 'Prime Cache: The following caching plugin(s) are also active: %s. Running multiple caching plugins simultaneously may cause conflicts, doubled caching, or unexpected behavior. Please deactivate other caching plugins for optimal performance.', 'prime-cache' ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $list is built from esc_html()-escaped values with HTML <strong> tags.
			$list
		);
		echo '</p></div>';
	}
}
