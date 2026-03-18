<?php
/**
 * Database Optimizer — cleanup revisions, drafts, spam, transients, table optimization.
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Database_Optimizer {

	const CRON_HOOK = 'prime_cache_db_cleanup';

	public function __construct() {
		$s = prime_cache_get_settings();

		// Always register custom cron intervals so they're available when
		// wp_schedule_event() is called during the same request (OFF → ON toggle).
		add_filter( 'cron_schedules', array( $this, 'add_cron_intervals' ) );

		// Scheduled auto-cleanup.
		if ( $s['db_auto_cleanup'] ) {
			add_action( self::CRON_HOOK, array( $this, 'run_scheduled_cleanup' ) );
		}

		// Manual cleanup handler.
		add_action( 'admin_init', array( $this, 'handle_manual_cleanup' ) );
	}

	/**
	 * Register custom cron intervals.
	 */
	public function add_cron_intervals( $schedules ) {
		$schedules['pc_db_daily']   = array( 'interval' => DAY_IN_SECONDS, 'display' => 'Daily' );
		$schedules['pc_db_weekly']  = array( 'interval' => WEEK_IN_SECONDS, 'display' => 'Weekly' );
		$schedules['pc_db_monthly'] = array( 'interval' => 30 * DAY_IN_SECONDS, 'display' => 'Monthly' );
		return $schedules;
	}

	/**
	 * Run cleanup for all enabled options (cron callback).
	 */
	public function run_scheduled_cleanup() {
		$s = prime_cache_get_settings();
		$this->execute_cleanup( $s );
	}

	/**
	 * Handle manual cleanup via admin action.
	 */
	public function handle_manual_cleanup() {
		if ( ! isset( $_GET['prime_cache_db_cleanup'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'prime_cache_db_cleanup' ) ) {
			return;
		}

		$s = prime_cache_get_settings();
		$results = $this->execute_cleanup( $s );

		$total = array_sum( $results );
		$redirect = add_query_arg(
			array( 'prime_cache_db_cleaned' => $total, 'tab' => 'database' ),
			admin_url( 'admin.php?page=prime-cache' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Execute all enabled cleanup tasks.
	 *
	 * @param array $s Settings.
	 * @return array Counts of deleted items per task.
	 */
	public function execute_cleanup( $s ) {
		global $wpdb;
		$results = array();

		if ( ! empty( $s['db_revisions'] ) ) {
			$results['revisions'] = $this->clean_revisions();
		}
		if ( ! empty( $s['db_auto_drafts'] ) ) {
			$results['auto_drafts'] = $this->clean_auto_drafts();
		}
		if ( ! empty( $s['db_trashed_posts'] ) ) {
			$results['trashed_posts'] = $this->clean_trashed_posts();
		}
		if ( ! empty( $s['db_spam_comments'] ) ) {
			$results['spam_comments'] = $this->clean_spam_comments();
		}
		if ( ! empty( $s['db_trashed_comments'] ) ) {
			$results['trashed_comments'] = $this->clean_trashed_comments();
		}
		if ( ! empty( $s['db_expired_transients'] ) ) {
			$results['expired_transients'] = $this->clean_expired_transients();
		}
		if ( ! empty( $s['db_all_transients'] ) ) {
			$results['all_transients'] = $this->clean_all_transients();
		}
		if ( ! empty( $s['db_optimize_tables'] ) ) {
			$results['tables'] = $this->optimize_tables();
		}

		return $results;
	}

	// ── Count Methods ────────────────────────────────────────

	/**
	 * Get counts of items available for cleanup.
	 *
	 * @return array
	 */
	public function get_counts() {
		global $wpdb;

		return array(
			'revisions'          => (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'auto_drafts'        => (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ),
			'trashed_posts'      => (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'trash'" ),
			'spam_comments'      => (int) $wpdb->get_var( "SELECT COUNT(comment_ID) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ),
			'trashed_comments'   => (int) $wpdb->get_var( "SELECT COUNT(comment_ID) FROM {$wpdb->comments} WHERE comment_approved IN ('trash','post-trashed')" ),
			'expired_transients' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(option_name) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			) ),
			'all_transients'     => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(option_name) FROM {$wpdb->options} WHERE option_name LIKE %s",
				'%' . $wpdb->esc_like( '_transient_' ) . '%'
			) ),
			'tables'             => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND data_free > 0",
				DB_NAME
			) ),
		);
	}

	// ── Cleanup Methods ──────────────────────────────────────

	private function clean_revisions() {
		global $wpdb;
		// Batch limit to prevent memory exhaustion on large sites.
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' LIMIT 1000" );
		foreach ( $ids as $id ) {
			wp_delete_post_revision( (int) $id );
		}
		return count( $ids );
	}

	private function clean_auto_drafts() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' LIMIT 1000" );
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		return count( $ids );
	}

	private function clean_trashed_posts() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' LIMIT 1000" );
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
		return count( $ids );
	}

	private function clean_spam_comments() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam' LIMIT 1000" );
		foreach ( $ids as $id ) {
			wp_delete_comment( (int) $id, true );
		}
		return count( $ids );
	}

	private function clean_trashed_comments() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('trash','post-trashed') LIMIT 1000" );
		foreach ( $ids as $id ) {
			wp_delete_comment( (int) $id, true );
		}
		return count( $ids );
	}

	private function clean_expired_transients() {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			time()
		) );

		$count = 0;
		foreach ( $names as $name ) {
			$key = str_replace( '_transient_timeout_', '', $name );
			if ( delete_transient( $key ) ) {
				$count++;
			}
		}
		return $count;
	}

	private function clean_all_transients() {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_' ) . '%'
		) );

		$count = 0;
		foreach ( $names as $name ) {
			$key = str_replace( '_transient_', '', $name );
			if ( delete_transient( $key ) ) {
				$count++;
			}
		}

		// Also clean site transients in multisite.
		if ( is_multisite() ) {
			$site_names = $wpdb->get_col( $wpdb->prepare(
				"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s AND meta_key NOT LIKE %s",
				$wpdb->esc_like( '_site_transient_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%'
			) );
			foreach ( $site_names as $name ) {
				$key = str_replace( '_site_transient_', '', $name );
				if ( delete_site_transient( $key ) ) {
					$count++;
				}
			}
		}

		return $count;
	}

	private function optimize_tables() {
		global $wpdb;
		// Include all engines with fragmentation (InnoDB + MyISAM).
		$tables = $wpdb->get_col( $wpdb->prepare(
			"SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND data_free > 0",
			DB_NAME
		) );

		foreach ( $tables as $table ) {
			$safe_table = str_replace( '`', '', $table );
			$wpdb->query( "OPTIMIZE TABLE `{$safe_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return count( $tables );
	}

	/**
	 * Reschedule cron when frequency changes.
	 */
	public static function reschedule_cron( $frequency ) {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( $frequency && in_array( $frequency, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			wp_schedule_event( time() + 60, 'pc_db_' . $frequency, self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule cron.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}
