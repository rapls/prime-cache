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
			add_action( 'before_delete_post', array( $this, 'on_post_delete' ) );
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
			// delete_term needs the deleted-term object: by the time this fires,
			// the row is already gone from the DB so get_term_link() can't
			// reconstruct the URL. WP passes the WP_Term as the 4th argument.
			add_action( 'delete_term', array( $this, 'on_term_delete' ), 10, 4 );
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
		// Note: wp_update_nav_menu does NOT fire on create — wp_update_nav_menu_object
		// returns early in the create branch (wp-includes/nav-menu.php). Hook
		// wp_create_nav_menu separately to satisfy the UI promise of purging on
		// menu create.
		if ( $s['purge_on_nav_menu'] ) {
			add_action( 'wp_create_nav_menu', array( $this, 'purge_all' ) );
			add_action( 'wp_update_nav_menu', array( $this, 'purge_all' ) );
			add_action( 'wp_delete_nav_menu', array( $this, 'purge_all' ) );
		}

		// ── WordPress core update ─────────────────────────────
		if ( $s['purge_on_core_update'] ) {
			add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );
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
		// Trash transitions belong to the trash/delete event, gated by
		// purge_on_post_delete. If we also fired here, disabling
		// purge_on_post_delete alone would be ineffective for trash.
		if ( 'trash' === $new_status ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		$this->purge_post_and_related( $post->ID );
	}

	// ── Post trash / delete ───────────────────────────────────

	public function on_post_delete( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'auto-draft' === $post->post_status || 'attachment' === $post->post_type ) {
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
			// Single-URL delete in two cases:
			//   - Static front page: permalink is the host root and tree-delete
			//     would wipe everything.
			//   - Hierarchical post type: /docs/ has /docs/api/ children that
			//     aren't comment-page-N or feed; tree-delete would clobber
			//     unrelated child page caches. Same guard as
			//     get_post_tree_purge_urls() applies here.
			$home_root       = trailingslashit( home_url( '/' ) );
			$is_root         = ( trailingslashit( $permalink ) === $home_root );
			$is_hierarchical = is_post_type_hierarchical( $post->post_type );
			if ( $is_root || $is_hierarchical ) {
				Prime_Cache_Storage::delete_url( $permalink );
			} else {
				// Tree-delete also clears /comment-page-N/ — the most affected
				// subtree on a comment change — and /feed/ for the post.
				Prime_Cache_Storage::delete_url_tree( $permalink );
			}
		}
		// Home and posts page typically show comment counts and recent-comments widgets.
		$this->purge_home();
	}

	// ── Term change ───────────────────────────────────────────

	public function on_term_change( $term_id, $tt_id, $taxonomy ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || ! $tax->public ) {
			return;
		}
		$link = get_term_link( (int) $term_id, $taxonomy );
		if ( ! is_wp_error( $link ) ) {
			// Hierarchical taxonomies + hierarchical rewrite: child term
			// archives live under the parent's URL (`/category/news/local/`),
			// so tree-deleting `/category/news/` would purge every child
			// term's cache too. Single-delete the parent archive front page;
			// /page/N/ stays slightly stale until natural expiry.
			$rewrite = isset( $tax->rewrite ) && is_array( $tax->rewrite ) ? $tax->rewrite : array();
			$has_child_under_parent = ! empty( $tax->hierarchical ) && ! empty( $rewrite['hierarchical'] );
			if ( $has_child_under_parent ) {
				Prime_Cache_Storage::delete_url( $link );
			} else {
				// Flat taxonomy: tree-delete to also clear /page/2/, /page/3/...
				Prime_Cache_Storage::delete_url_tree( $link );
			}
		}
		$this->purge_home();
	}

	/**
	 * Term delete hook: receives the WP_Term object before it disappears.
	 *
	 * `delete_term` fires after the row is removed, so passing the term_id back
	 * to get_term_link() returns WP_Error. Use the object's slug (and parent
	 * for hierarchical taxonomies) to reconstruct the archive URL ourselves.
	 *
	 * @param int     $term_id      Deleted term ID.
	 * @param int     $tt_id        Term taxonomy ID.
	 * @param string  $taxonomy     Taxonomy slug.
	 * @param WP_Term $deleted_term Term object as it existed prior to deletion.
	 */
	public function on_term_delete( $term_id, $tt_id, $taxonomy, $deleted_term ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || ! $tax->public ) {
			return;
		}
		// WP would normally use get_term_link, but the term is gone. For
		// hierarchical taxonomies with hierarchical rewrite, get_term_link
		// walks the parent chain via get_ancestors → get_term, both of which
		// need the row that was just deleted. We collect every plausible
		// archive URL the deleted term could have lived at and tree-purge
		// them all — duplicates are harmless, but missing one leaves stale
		// caches forever. Order matters: prefer get_term_link's output
		// (respects term_link filter and rewrite settings) when available.
		$urls = array();
		if ( is_object( $deleted_term ) && ! empty( $deleted_term->slug ) ) {
			// Always try get_term_link first — handles the term_link filter,
			// with_front, and rewrite['hierarchical']=false correctly.
			$link = get_term_link( $deleted_term, $taxonomy );
			if ( ! is_wp_error( $link ) && is_string( $link ) && '' !== $link ) {
				$urls[] = $link;
			}

			// Hierarchical taxonomy with hierarchical rewrite: also rebuild
			// the parent-prefixed path manually in case get_term_link's
			// ancestor walk failed (term gone from DB) and returned the flat
			// `/base/child/` URL when the real archive is `/base/parent/child/`.
			$rewrite = isset( $tax->rewrite ) && is_array( $tax->rewrite ) ? $tax->rewrite : array();
			$rewrite_hierarchical = ! empty( $rewrite['hierarchical'] );
			if ( $tax->hierarchical && $rewrite_hierarchical && isset( $deleted_term->parent ) && (int) $deleted_term->parent > 0 ) {
				$slugs     = array( $deleted_term->slug );
				$parent_id = (int) $deleted_term->parent;
				$walk      = 0;
				while ( $parent_id > 0 && $walk < 20 ) {
					$parent_term = get_term( $parent_id, $taxonomy );
					if ( ! $parent_term || is_wp_error( $parent_term ) ) {
						break;
					}
					array_unshift( $slugs, $parent_term->slug );
					$parent_id = (int) $parent_term->parent;
					$walk++;
				}
				$base = isset( $rewrite['slug'] ) && '' !== $rewrite['slug'] ? trim( $rewrite['slug'], '/' ) : $taxonomy;
				$urls[] = home_url( '/' . $base . '/' . implode( '/', array_map( 'rawurlencode', $slugs ) ) . '/' );
			}
		}
		foreach ( array_unique( array_filter( $urls ) ) as $link ) {
			Prime_Cache_Storage::delete_url_tree( $link );
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

	// ── Core update (upgrader) ───────────────────────────────

	/**
	 * Only purge cache when a core update completes, not plugin/theme/translation updates.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Update details including 'type'.
	 */
	public function on_upgrader_complete( $upgrader, $options ) {
		if ( isset( $options['type'] ) && 'core' === $options['type'] ) {
			$this->purge_all();
		}
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
			// Tree-delete to also clear /page/2/, /page/3/... under the author archive.
			Prime_Cache_Storage::delete_url_tree( $author_url );
		}
		$this->purge_home();
	}

	public function on_user_delete( $user_id ) {
		$author_url = get_author_posts_url( $user_id );
		if ( $author_url ) {
			Prime_Cache_Storage::delete_url_tree( $author_url );
		}
		$this->purge_home();
	}

	// ── Shared purge methods ──────────────────────────────────

	public function purge_post_and_related( $post_id ) {
		$urls       = $this->get_post_related_urls( $post_id );
		$tree_purge = $this->get_post_tree_purge_urls( $post_id );

		$additional = trim( $this->settings['purge_additional_urls'] );
		if ( $additional ) {
			$extra = array_map( 'trim', explode( "\n", $additional ) );
			$extra = array_filter( $extra, array( $this, 'is_valid_purge_url' ) );
			$urls  = array_merge( $urls, $extra );
		}

		// Tree-delete URLs whose pagination/feed/comment-page lives in
		// subdirectories — single-URL delete leaves /page/2/, /feed/, and
		// /comment-page-2/ stale. Skip these from the single-delete loop so
		// we do not double-purge or accidentally clear adjacent unrelated
		// URLs (home, posts page) that might otherwise appear in $urls.
		$tree_set = array_flip( $tree_purge );
		foreach ( array_unique( array_filter( $urls ) ) as $url ) {
			if ( isset( $tree_set[ $url ] ) ) {
				continue;
			}
			Prime_Cache_Storage::delete_url( $url );
		}
		foreach ( $tree_purge as $url ) {
			Prime_Cache_Storage::delete_url_tree( $url );
		}
	}

	/**
	 * Reject lines from purge_additional_urls that lack a scheme+host or point
	 * at a different host than this install. Without this, a relative path or
	 * stray non-URL token would normalize to an empty host inside
	 * Prime_Cache_Storage::get_cache_dir() and target unintended directories
	 * under the cache root.
	 */
	private function is_valid_purge_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return false;
		}
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return false;
		}
		if ( 'http' !== $parsed['scheme'] && 'https' !== $parsed['scheme'] ) {
			return false;
		}
		// IDN-aware host normalization: home_url() and the supplied URL may
		// disagree on Unicode vs Punycode for the same domain; the shared
		// normalizer collapses both forms to one canonical key. Include
		// `prime_cache_allowed_hosts` so alias hosts (www↔apex, etc.) the
		// drop-in is configured to cache for are also valid purge targets.
		require_once PRIME_CACHE_PATH . 'includes/cache-key-functions.php';
		$site_hosts = array();
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
		$norm_host  = _prime_cache_normalize_host( $parsed['host'] );
		return '' !== $norm_host && in_array( $norm_host, $site_hosts, true );
	}

	/**
	 * URLs whose stale variants live in subdirectories (pagination, comment
	 * pages, feeds) and therefore need a recursive purge.
	 */
	private function get_post_tree_purge_urls( $post_id ) {
		$urls = array();
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $urls;
		}
		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			// Skip when permalink == home root. On a static front page,
			// get_permalink returns home_url('/'), and tree-deleting the
			// host root would clear every cached page (effectively
			// purge_all). The home root is still single-URL purged via
			// get_post_related_urls/purge_home elsewhere.
			//
			// Skip for hierarchical post types too. /docs/ has /docs/api/
			// children that aren't /comment-page-N/ or /feed/; tree-delete
			// would clobber unrelated child page caches. The single-URL
			// delete in get_post_related_urls still clears the parent.
			$home_root      = trailingslashit( home_url( '/' ) );
			$is_root        = ( trailingslashit( $permalink ) === $home_root );
			$is_hierarchical = is_post_type_hierarchical( $post->post_type );
			if ( ! $is_root && ! $is_hierarchical ) {
				// Covers /comment-page-N/ and /feed/ subtrees beneath the post URL.
				$urls[] = $permalink;
			}
		}
		$author_url = get_author_posts_url( $post->post_author );
		if ( $author_url ) {
			// Covers /author/<slug>/page/N/.
			$urls[] = $author_url;
		}
		// CPT archive intentionally NOT added here: WordPress permalinks
		// often live directly under the archive base (`/events/post-name/`),
		// so tree-deleting `/events/` would clobber every cached single
		// post under that CPT on each update. The single-URL delete from
		// get_post_related_urls is enough; /events/page/N/ pagination may
		// stay slightly stale until natural expiry, the same trade-off
		// purge_home accepts for the posts page.
		// Term and date archives have /page/N/ pagination and /feed/ subdirs.
		// Only collect terms whose archive doesn't shelter children under the
		// same URL prefix — same hierarchical-taxonomy guard as on_term_change.
		// Hierarchical-rewrite parent terms go to the single-delete list via
		// get_post_related_urls instead.
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}
			$rewrite = isset( $taxonomy->rewrite ) && is_array( $taxonomy->rewrite ) ? $taxonomy->rewrite : array();
			$has_child_under_parent = ! empty( $taxonomy->hierarchical ) && ! empty( $rewrite['hierarchical'] );
			if ( $has_child_under_parent ) {
				continue; // Tree-delete would clobber sibling/child term caches.
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
		// Date archives only get tree-purged when the permalink structure
		// keeps post URLs OUT of the date prefix. With `/%year%/%postname%/`
		// or `/%year%/%monthnum%/%postname%/`, `/2026/` is the parent of
		// every post that year — tree-deleting it would evict every cached
		// single post on each update. Single-URL purge in get_post_related_urls
		// still clears the archive front page.
		$permalink_structure = (string) get_option( 'permalink_structure', '' );
		$date_prefix_in_perma = ( false !== strpos( $permalink_structure, '%year%' )
			|| false !== strpos( $permalink_structure, '%monthnum%' )
			|| false !== strpos( $permalink_structure, '%day%' ) );
		if ( ! $date_prefix_in_perma ) {
			$post_date = get_post_time( 'U', false, $post );
			if ( $post_date ) {
				$urls[] = get_year_link( wp_date( 'Y', $post_date ) );
				$urls[] = get_month_link( wp_date( 'Y', $post_date ), wp_date( 'm', $post_date ) );
				$urls[] = get_day_link( wp_date( 'Y', $post_date ), wp_date( 'm', $post_date ), wp_date( 'd', $post_date ) );
			}
		}
		return array_unique( array_filter( $urls ) );
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

		// Custom post types with has_archive: purge the post type archive top page.
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			$pt_archive = get_post_type_archive_link( $post->post_type );
			if ( $pt_archive ) {
				$urls[] = $pt_archive;
			}
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
		// Iterate the same hosts the drop-in / htaccess will cache for.
		// home_url and site_url can resolve to different hosts (e.g. WP_SITEURL
		// points to a separate admin domain), and operators can extend the
		// list via the `prime_cache_allowed_hosts` filter for www↔apex,
		// language subdomains, etc. Without including those, "Clear All
		// Cache" / `wp prime-cache flush page` would leave alias hosts
		// serving stale content forever.
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$h = wp_parse_url( $url, PHP_URL_HOST );
			if ( $h ) {
				$hosts[] = $h;
			}
		}
		/** This filter is documented in includes/class-config.php */
		$hosts = apply_filters( 'prime_cache_allowed_hosts', $hosts );
		if ( ! is_array( $hosts ) ) {
			$hosts = array();
		}
		// Re-normalize and drop empties before iterating. A filter callback
		// that returns ''/null or a value that collapses to empty after
		// normalization would otherwise reach delete_host(''), which —
		// before its own fail-close guard — could recurse over the entire
		// shared cache root.
		require_once PRIME_CACHE_PATH . 'includes/cache-key-functions.php';
		$hosts = array_map(
			function ( $h ) {
				return is_string( $h ) ? _prime_cache_normalize_host( $h ) : '';
			},
			$hosts
		);
		$hosts = array_values( array_unique( array_filter( $hosts ) ) );
		foreach ( $hosts as $host ) {
			Prime_Cache_Storage::delete_host( $host );
		}
		delete_transient( 'prime_cache_dir_stats' );
		do_action( 'prime_cache_after_purge_all' );
	}

	private function purge_home() {
		// Home is at the host root, so a tree-delete here would equal purge_all
		// (clears every post under the host). Keep it single-URL.
		Prime_Cache_Storage::delete_url( home_url( '/' ) );
		$posts_page_id = (int) get_option( 'page_for_posts' );
		if ( $posts_page_id ) {
			$pp = get_permalink( $posts_page_id );
			if ( $pp ) {
				// Single-URL only: posts page often shares its prefix with
				// post permalinks (`/blog/` → `/blog/post-name/`), so tree-
				// delete here would evict every post on each home purge.
				// Pagination at /<pp>/page/N/ may stay slightly stale until
				// natural expiry; the trade-off is acceptable since home
				// purges fire on every comment/term/user change.
				Prime_Cache_Storage::delete_url( $pp );
			}
		}
	}

	public function on_settings_update( $old_value, $new_value ) {
		// Multisite: page caching is not supported — do not write config file.
		if ( is_multisite() ) {
			return;
		}
		if ( ! Prime_Cache_Config::write_config_file( $new_value ) ) {
			// Surface the failure on the next settings page load. Without this,
			// the admin saves their changes and the dropin keeps reading the
			// previous config file with no indication anything went wrong.
			$existing   = (array) get_transient( 'prime_cache_activation_warnings' );
			$existing[] = __( 'Prime Cache settings were saved but the configuration file could not be regenerated. Caching will continue to use the previous settings until the file write succeeds. Check that wp-content is writable.', 'prime-cache' );
			set_transient( 'prime_cache_activation_warnings', array_values( array_unique( $existing ) ), 5 * MINUTE_IN_SECONDS );
		}
		// Cached pages were rendered with the previous settings (lazy load,
		// minify, defer/delay, image delivery, …), and with the default
		// 7-day lifespan they would keep serving that stale HTML long after
		// the admin changed the configuration. update_option only fires this
		// hook when the value actually changed, so always purge here.
		$this->purge_all();
	}
}
