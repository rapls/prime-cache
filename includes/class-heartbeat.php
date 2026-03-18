<?php
/**
 * Heartbeat API control.
 *
 * Allows disabling or reducing the WordPress Heartbeat API frequency
 * per location (frontend, admin dashboard, post editor).
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Heartbeat {

	/** @var array */
	private $settings;

	/** @var string Current location: frontend|admin|editor */
	private $location;

	public function __construct() {
		$this->settings = prime_cache_get_settings();

		if ( empty( $this->settings['heartbeat_enabled'] ) ) {
			return;
		}

		// Skip during AJAX — heartbeat itself uses AJAX.
		if ( wp_doing_ajax() ) {
			return;
		}

		$this->location = $this->detect_location();

		// Disable heartbeat script if needed.
		if ( 'disable' === $this->get_behavior() ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'deregister_heartbeat' ), PHP_INT_MAX );
			add_action( 'admin_enqueue_scripts', array( $this, 'deregister_heartbeat' ), PHP_INT_MAX );
		}

		// Modify heartbeat interval if needed.
		if ( 'modify' === $this->get_behavior() ) {
			add_filter( 'heartbeat_settings', array( $this, 'modify_interval' ), PHP_INT_MAX );
		}
	}

	/**
	 * Detect the current page context.
	 *
	 * @return string frontend|admin|editor
	 */
	private function detect_location() {
		if ( ! is_admin() ) {
			return 'frontend';
		}

		// Use get_current_screen() for accurate editor detection including
		// block editor, site editor, widget editor, and customizer.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen ) {
			if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
				return 'editor';
			}
			if ( 'post' === $screen->base || in_array( $screen->id, array( 'site-editor', 'widgets', 'customize' ), true ) ) {
				return 'editor';
			}
		}

		// Fallback for early hooks before screen is set.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		if ( preg_match( '#/wp-admin/(post\.php|post-new\.php|site-editor\.php|widgets\.php)#', $uri ) ) {
			return 'editor';
		}

		return 'admin';
	}

	/**
	 * Get the configured behavior for the current location.
	 *
	 * @return string enable|disable|modify
	 */
	private function get_behavior() {
		$map = array(
			'frontend' => $this->settings['heartbeat_frontend'] ?? 'disable',
			'admin'    => $this->settings['heartbeat_admin'] ?? 'modify',
			'editor'   => $this->settings['heartbeat_editor'] ?? 'enable',
		);

		return $map[ $this->location ] ?? 'enable';
	}

	/**
	 * Get the configured interval for the current location.
	 *
	 * @return int Seconds.
	 */
	private function get_interval() {
		$map = array(
			'frontend' => (int) ( $this->settings['heartbeat_frontend_interval'] ?? 60 ),
			'admin'    => (int) ( $this->settings['heartbeat_admin_interval'] ?? 120 ),
			'editor'   => 15, // Editor always uses WordPress default (15s) when enabled.
		);

		$interval = $map[ $this->location ] ?? 60;

		// Clamp between 15 and 300 seconds.
		return max( 15, min( 300, $interval ) );
	}

	/**
	 * Deregister the heartbeat script.
	 */
	public function deregister_heartbeat() {
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * Modify the heartbeat interval.
	 *
	 * @param array $settings Heartbeat settings.
	 * @return array
	 */
	public function modify_interval( $settings ) {
		$interval = $this->get_interval();
		$settings['interval']        = $interval;
		$settings['minimalInterval'] = $interval;
		return $settings;
	}
}
