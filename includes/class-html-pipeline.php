<?php
/**
 * Unified HTML post-processing pipeline.
 *
 * Replaces multiple individual ob_start() calls with a single buffer that
 * runs all HTML transformations in one pass, reducing CPU/memory overhead
 * from repeated buffering and regex scanning of the same HTML.
 *
 * Processing order (matches the nested ob_start LIFO execution order):
 * 1. CDN URL rewrite (was ob_start priority 5 = first to execute in LIFO)
 * 2. Media Optimizer (YouTube thumbnails, image dimensions)
 * 3. WebP/AVIF (picture tags or URL rewrite)
 * 4. LazyLoad (images, iframes, videos)
 * 5. LCP optimization (fetchpriority, preload)
 * 6. File Optimizer (minify, combine, critical CSS — was priority -1 = last in LIFO)
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

		// Sort by priority. Use index as tiebreaker for stability (PHP usort is unstable).
		foreach ( $this->processors as $i => &$p ) {
			$p['_idx'] = $i;
		}
		unset( $p );
		usort( $this->processors, function( $a, $b ) {
			$diff = $a['priority'] - $b['priority'];
			return $diff !== 0 ? $diff : $a['_idx'] - $b['_idx'];
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
		// Only guard on minimum length. Individual processors check for </html>
		// themselves if needed — CDN rewrite intentionally runs without it.
		if ( strlen( $html ) < 255 ) {
			return $html;
		}

		foreach ( $this->processors as $proc ) {
			$html = call_user_func( $proc['callback'], $html );
		}

		return $html;
	}
}
