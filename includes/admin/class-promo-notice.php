<?php
/**
 * A time-limited promotion notice shown inside Prime Cache's own settings screen.
 *
 * WordPress.org guideline 11 ("don't hijack the admin") is what shapes this:
 * the notice is not an admin notice. The Dashboard tab draws it itself, so it
 * can never appear on another screen; it is a plain white card with text and
 * one link, no images or countdown; it shows only in its date window; each
 * user can dismiss it for good; and a filter turns it off. Nothing is fetched
 * from outside the plugin.
 *
 * One instance per campaign (see bf26()).
 *
 * @package PrimeCache
 */

defined( 'ABSPATH' ) || exit;

class Prime_Cache_Promo_Notice {

	/** Black Friday 2026: 2026-11-26T09:00:00Z (18:00 JST). */
	const BF26_START = 1795683600;

	/** Black Friday 2026: 2026-12-02T09:00:00Z (18:00 JST; the deal ends 17:59 JST). */
	const BF26_END = 1796202000;

	/** Query argument carrying the campaign id on the dismiss link. */
	const DISMISS_ARG = 'prime_cache_promo_dismiss';

	/** @var array<string, mixed> */
	private $config;

	/**
	 * @param array<string, mixed> $config {
	 *     @type string $id            Campaign id (letters, digits, underscore).
	 *     @type int    $start         Start, Unix time (UTC).
	 *     @type int    $end           End, Unix time (UTC); hidden from this moment.
	 *     @type string $window_filter Filter that may replace array( 'start' => int, 'end' => int ).
	 *     @type string $meta_key      user_meta key recording the dismissal.
	 * }
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * The Black Friday 2026 notice.
	 *
	 * @return self
	 */
	public static function bf26() {
		return new self(
			array(
				'id'            => 'bf26',
				'start'         => self::BF26_START,
				'end'           => self::BF26_END,
				'window_filter' => 'prime_cache_promo_bf26_window',
				'meta_key'      => 'prime_cache_promo_bf26_dismissed',
			)
		);
	}

	/**
	 * The campaign window after the filter.
	 *
	 * @return array{start: int, end: int}
	 */
	public function window() {
		$default = array(
			'start' => (int) $this->config['start'],
			'end'   => (int) $this->config['end'],
		);

		/**
		 * Filter the promotion window (Unix time, UTC). Used to test the notice
		 * without editing the constants.
		 *
		 * @param array $window array( 'start' => int, 'end' => int ).
		 */
		$window = apply_filters( (string) $this->config['window_filter'], $default );
		if ( ! is_array( $window ) || ! isset( $window['start'], $window['end'] ) ) {
			return $default;
		}
		return array(
			'start' => (int) $window['start'],
			'end'   => (int) $window['end'],
		);
	}

	/**
	 * Whether the current time is inside the window. Compares UTC Unix time, so
	 * the site's timezone setting does not move the campaign.
	 *
	 * @return bool
	 */
	public function in_window() {
		$window = $this->window();
		$now    = time();
		return $now >= $window['start'] && $now < $window['end'];
	}

	/**
	 * Whether the current user dismissed this campaign.
	 *
	 * @return bool
	 */
	public function is_dismissed() {
		return (bool) get_user_meta( get_current_user_id(), (string) $this->config['meta_key'], true );
	}

	/**
	 * Every condition that does not depend on the screen: in the window, an
	 * administrator, the add-on not installed, not dismissed, not filtered off.
	 *
	 * The add-on check uses prime_cache_addon_active, which is true whenever
	 * Prime Cache Pro is installed, licensed or not. prime_cache_is_pro() is
	 * true only with a valid license, so it would show the offer to customers
	 * whose license has lapsed.
	 *
	 * While this is true, the review request is held back on every Prime Cache
	 * screen, so the two never appear together and the one-week campaign wins
	 * over the request, which can wait.
	 *
	 * @return bool
	 */
	public function is_eligible() {
		if ( ! $this->in_window() ) {
			return false;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( apply_filters( 'prime_cache_addon_active', false ) ) {
			return false;
		}
		if ( $this->is_dismissed() ) {
			return false;
		}

		/**
		 * Filter whether in-plugin promotion notices are shown.
		 *
		 * @param bool   $show Default true.
		 * @param string $id   Campaign id.
		 */
		return (bool) apply_filters( 'prime_cache_show_promo_notice', true, (string) $this->config['id'] );
	}

	/**
	 * The link that dismisses this campaign for the current user.
	 *
	 * @return string
	 */
	public function dismiss_url() {
		$id = (string) $this->config['id'];
		return wp_nonce_url( add_query_arg( self::DISMISS_ARG, $id ), self::DISMISS_ARG . '_' . $id );
	}

	/**
	 * Record the dismissal for the current user.
	 */
	public function dismiss() {
		update_user_meta( get_current_user_id(), (string) $this->config['meta_key'], 1 );
	}

	/**
	 * Admin_init: handle the dismiss link. A link, not a form or AJAX, so it
	 * works without JavaScript and cannot nest inside a settings form.
	 */
	public static function handle_dismiss_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked below.
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked below.
		$id        = sanitize_key( wp_unslash( $_GET[ self::DISMISS_ARG ] ) );
		$campaigns = array( 'bf26' => array( __CLASS__, 'bf26' ) );
		if ( ! isset( $campaigns[ $id ] ) ) {
			return;
		}
		check_admin_referer( self::DISMISS_ARG . '_' . $id );
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice = call_user_func( $campaigns[ $id ] );
		$notice->dismiss();

		wp_safe_redirect( remove_query_arg( array( self::DISMISS_ARG, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Output the notice, if it is eligible. Called at the end of the Dashboard tab.
	 */
	public function render() {
		if ( ! $this->is_eligible() ) {
			return;
		}

		$window = $this->window();
		// The deal ends at the end of the window; show the last minute it is valid.
		$last  = $window['end'] - 60;
		$until = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last )
			. ' ' . wp_date( 'T', $last );

		$deal_url = ( 0 === strpos( (string) get_user_locale(), 'ja' ) )
			? 'https://raplsworks.com/black-friday-ja/'
			: 'https://raplsworks.com/black-friday/';
		$deal_url = add_query_arg(
			array(
				'utm_source'   => 'plugin',
				'utm_medium'   => 'bf-notice',
				'utm_campaign' => 'bf26',
			),
			$deal_url
		);
		?>
		<div class="card prime-cache-promo-card" style="max-width: none; margin-top: 20px; position: relative;">
			<a href="<?php echo esc_url( $this->dismiss_url() ); ?>"
				style="position: absolute; top: 8px; right: 12px; text-decoration: none; font-size: 18px; line-height: 1;"
				aria-label="<?php esc_attr_e( 'Dismiss this notice', 'prime-cache' ); ?>">&times;</a>
			<p>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: discount, e.g. "20%", 2: date and time the offer ends, in the site's timezone with its abbreviation (e.g. "December 2, 2026 5:59 PM JST") */
							__( 'Black Friday: %1$s off Prime Cache Pro until %2$s.', 'prime-cache' ),
							'20%',
							$until
						)
					);
					?>
				</strong>
			</p>
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: coupon code */
						__( 'Use code %s at checkout.', 'prime-cache' ),
						'<code>RAPLS-BF26-USERS</code>'
					),
					array( 'code' => array() )
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( $deal_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'See Pro features', 'prime-cache' ); ?></a>
			</p>
		</div>
		<?php
	}
}
