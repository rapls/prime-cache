<?php
/**
 * Unified HTML post-processing pipeline.
 *
 * Replaces multiple individual ob_start() calls with a single buffer that
 * runs all HTML transformations in one pass, reducing CPU/memory overhead
 * from repeated buffering and regex scanning of the same HTML.
 *
 * Processing order (matches previous ob_start priority order):
 * 1. File Optimizer (minify, combine, critical CSS, etc.)
 * 2. LazyLoad (images, iframes, videos)
 * 3. WebP/AVIF (picture tags or URL rewrite)
 * 4. Media Optimizer (YouTube thumbnails, image dimensions)
 * 5. CDN URL rewrite
 * 6. LCP optimization (fetchpriority, preload)
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_HTML_Pipeline {

	/** @var array Registered processors in execution order. */
	private $processors = array();

	/** @var bool Whether the pipeline buffer is active. */
	private $active = false;

	/**
	 * Register a processor callback.
	 *
	 * @param string   $name     Identifier for debugging.
	 * @param callable $callback Function that receives HTML and returns HTML.
	 * @param int      $priority Lower = runs first.
	 */
	public function register( $name, $callback, $priority = 10 ) {
		$this->processors[] = array(
			'name'     => $name,
			'callback' => $callback,
			'priority' => $priority,
		);
	}

	/**
	 * Start the unified output buffer.
	 */
	public function start() {
		if ( $this->active || empty( $this->processors ) ) {
			return;
		}

		// Sort by priority (stable sort preserves registration order for same priority).
		usort( $this->processors, function( $a, $b ) {
			return $a['priority'] - $b['priority'];
		} );

		$this->active = true;
		ob_start( array( $this, 'process' ) );
	}

	/**
	 * Process HTML through all registered processors in order.
	 *
	 * @param string $html Raw HTML output.
	 * @return string Processed HTML.
	 */
	public function process( $html ) {
		if ( strlen( $html ) < 255 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}

		foreach ( $this->processors as $proc ) {
			$html = call_user_func( $proc['callback'], $html );
		}

		return $html;
	}
}
