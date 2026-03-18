<?php
/**
 * Cache purge logic.
 *
 * Each trigger checks its own setting before acting.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Purge {

	/** @var array Cached settings. */
	private $settings;

	public function __construct() {
		$this->settings = prime_cache_get_settings();
		$s = $this->settings;

		// ── Post publish / update ─────────────────────────────
		if ( $s['purge_on_post_update'] ) {
			add_action( 'transition_post_status', array( $this, 'on_post_update' ), 10, 3 );
		}

		// ── Post trash / delete ───────────────────────────────
		if ( $s['purge_on_post_delete'] ) {
			add_action( 'trashed_post', array( $this, 'on_post_delete' ) );
			add_action( 'deleted_post', array( $this, 'on_post_delete' ) );
		}

		// ── Comment ───────────────────────────────────────────
		if ( $s['purge_on_comment'] ) {
			add_action( 'wp_set_comment_status', array( $this, 'on_comment_change' ) );
			add_action( 'edit_comment', array( $this, 'on_comment_change' ) );
			add_action( 'comment_post', array( $this, 'on_comment_change' ) );
			add_action( 'delete_comment', array( $this, 'on_comment_change' ) );
			add_action( 'trash_comment', array( $this, 'on_comment_change' ) );
		}

		// ── Term (category / tag / custom taxonomy) ───────────
		if ( $s['purge_on_term_change'] ) {
			add_action( 'create_term', array( $this, 'on_term_change' ), 10, 3 );
			add_action( 'edit_term', array( $this, 'on_term_change' ), 10, 3 );
			add_action( 'delete_term', array( $this, 'on_term_change' ), 10, 3 );
		}

		// ── Theme switch ──────────────────────────────────────
		if ( $s['purge_on_theme_switch'] ) {
			add_action( 'switch_theme', array( $this, 'purge_all' ) );
		}

		// ── Permalink structure ────────────────────────────────
		if ( $s['purge_on_permalink'] ) {
			add_action( 'update_option_permalink_structure', array( $this, 'purge_all' ) );
		}

		// ── Plugin activate / deactivate ──────────────────────
		if ( $s['purge_on_plugin_change'] ) {
			add_action( 'activated_plugin', array( $this, 'purge_all' ) );
			add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivate' ) );
		}

		// ── Customizer save ───────────────────────────────────
		if ( $s['purge_on_customizer'] ) {
			add_action( 'customize_save_after', array( $this, 'purge_all' ) );
		}

		// ── Widget update ─────────────────────────────────────
		if ( $s['purge_on_widget'] ) {
			add_action( 'update_option_sidebars_widgets', array( $this, 'purge_all' ) );
			add_filter( 'widget_update_callback', array( $this, 'on_widget_update' ), 10, 4 );
		}

		// ── Navigation menu update ────────────────────────────
		if ( $s['purge_on_nav_menu'] ) {
			add_action( 'wp_update_nav_menu', array( $this, 'purge_all' ) );
		}

		// ── WordPress core update ─────────────────────────────
		if ( $s['purge_on_core_update'] ) {
			add_action( '_core_updated_successfully', array( $this, 'purge_all' ) );
			add_action( 'upgrader_process_complete', array( $this, 'purge_all' ) );
		}

		// ── User profile update (author archive) ──────────────
		if ( $s['purge_on_user_update'] ) {
			add_action( 'profile_update', array( $this, 'on_user_update' ) );
			add_action( 'delete_user', array( $this, 'on_user_delete' ) );
		}

		// ── Always: Settings update triggers config rewrite ───
		add_action( 'update_option_prime_cache_settings', array( $this, 'on_settings_update' ), 10, 2 );
	}

	// ── Post publish / update ─────────────────────────────────

	public function on_post_update( $new_status, $old_status, $post ) {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		$this->purge_post_and_related( $post->ID );
	}

	// ── Post trash / delete ───────────────────────────────────

	public function on_post_delete( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$this->purge_post_and_related( $post_id );
	}

	// ── Comment ───────────────────────────────────────────────

	public function on_comment_change( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment || ! $comment->comment_post_ID ) {
			return;
		}
		$post = get_post( $comment->comment_post_ID );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		$permalink = get_permalink( $post->ID );
		if ( $permalink ) {
			Prime_Cache_Storage::delete_url( $permalink );
		}
	}

	// ── Term change ───────────────────────────────────────────

	public function on_term_change( $term_id, $tt_id, $taxonomy ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || ! $tax->public ) {
			return;
		}
		$link = get_term_link( (int) $term_id, $taxonomy );
		if ( ! is_wp_error( $link ) ) {
			Prime_Cache_Storage::delete_url( $link );
		}
		$this->purge_home();
	}

	// ── Plugin deactivate (skip self) ─────────────────────────

	public function on_plugin_deactivate( $plugin ) {
		if ( plugin_basename( PRIME_CACHE_FILE ) === $plugin ) {
			return;
		}
		$this->purge_all();
	}

	// ── Widget update ─────────────────────────────────────────

	public function on_widget_update( $instance, $new_instance, $old_instance, $widget ) {
		$this->purge_all();
		return $instance;
	}

	// ── User profile update ───────────────────────────────────

	public function on_user_update( $user_id ) {
		$author_url = get_author_posts_url( $user_id );
		if ( $author_url ) {
			Prime_Cache_Storage::delete_url( $author_url );
		}
		$this->purge_home();
	}

	public function on_user_delete( $user_id ) {
		$author_url = get_author_posts_url( $user_id );
		if ( $author_url ) {
			Prime_Cache_Storage::delete_url( $author_url );
		}
		$this->purge_home();
	}

	// ── Shared purge methods ──────────────────────────────────

	public function purge_post_and_related( $post_id ) {
		$urls = $this->get_post_related_urls( $post_id );

		$additional = trim( $this->settings['purge_additional_urls'] );
		if ( $additional ) {
			$extra = array_map( 'trim', explode( "\n", $additional ) );
			$urls  = array_merge( $urls, array_filter( $extra ) );
		}

		foreach ( array_unique( array_filter( $urls ) ) as $url ) {
			Prime_Cache_Storage::delete_url( $url );
		}
	}

	private function get_post_related_urls( $post_id ) {
		$urls = array();
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $urls;
		}

		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$urls[] = $permalink;
		}

		$urls[] = home_url( '/' );

		$posts_page_id = (int) get_option( 'page_for_posts' );
		if ( $posts_page_id ) {
			$pp = get_permalink( $posts_page_id );
			if ( $pp ) {
				$urls[] = $pp;
			}
		}

		$author_url = get_author_posts_url( $post->post_author );
		if ( $author_url ) {
			$urls[] = $author_url;
		}

		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}
			$terms = get_the_terms( $post_id, $taxonomy->name );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_link = get_term_link( $term );
					if ( ! is_wp_error( $term_link ) ) {
						$urls[] = $term_link;
					}
				}
			}
		}

		// Use site-local time (not UTC) for date archive links to match WordPress behavior.
		$post_date = get_post_time( 'U', false, $post );
		if ( $post_date ) {
			$urls[] = get_year_link( wp_date( 'Y', $post_date ) );
			$urls[] = get_month_link( wp_date( 'Y', $post_date ), wp_date( 'm', $post_date ) );
			$urls[] = get_day_link( wp_date( 'Y', $post_date ), wp_date( 'm', $post_date ), wp_date( 'd', $post_date ) );
		}

		return array_unique( array_filter( $urls ) );
	}

	public function purge_all() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $host ) {
			Prime_Cache_Storage::delete_host( $host );
		}
		do_action( 'prime_cache_after_purge_all' );
	}

	private function purge_home() {
		Prime_Cache_Storage::delete_url( home_url( '/' ) );
		$posts_page_id = (int) get_option( 'page_for_posts' );
		if ( $posts_page_id ) {
			$pp = get_permalink( $posts_page_id );
			if ( $pp ) {
				Prime_Cache_Storage::delete_url( $pp );
			}
		}
	}

	public function on_settings_update( $old_value, $new_value ) {
		// Multisite: page caching is not supported — do not write config file.
		if ( is_multisite() ) {
			return;
		}
		Prime_Cache_Config::write_config_file( $new_value );
	}
}
