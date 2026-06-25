<?php
/**
 * Prime Cache — bundled page-cache drop-in template (advanced-cache.php).
 *
 * Prime Cache copies this file to wp-content/advanced-cache.php on activation,
 * replacing the %%TOKEN%% placeholders below with this install's resolved
 * paths. WordPress includes the copied drop-in very early in wp-settings.php —
 * before plugin-location constants such as WP_PLUGIN_DIR are defined — so the
 * path to the plugin's page-cache loader is baked in here at copy time rather
 * than resolved at run time.
 *
 * The placeholders sit inside single-quoted string literals and are replaced
 * with escaped path values, so this template is valid PHP both before and after
 * substitution. The ABSPATH guard below blocks direct web access regardless.
 *
 * @package PrimeCache
 */

// PRIME_CACHE_DROPIN_SIGNATURE — do not remove this marker.
/** Installed by Prime Cache %%PRIME_CACHE_VERSION%% */
defined( 'ABSPATH' ) || exit;

define( 'PRIME_CACHE_ADVANCED_CACHE', true );
define( 'PRIME_CACHE_CACHE_DIR', '%%PRIME_CACHE_CACHE_DIR%%' );
define( 'PRIME_CACHE_CONFIG_DIR', '%%PRIME_CACHE_CONFIG_DIR%%' );

$prime_cache_dropin = '%%PRIME_CACHE_DROPIN_SOURCE%%';
if ( is_readable( $prime_cache_dropin ) ) {
	include $prime_cache_dropin;
}
