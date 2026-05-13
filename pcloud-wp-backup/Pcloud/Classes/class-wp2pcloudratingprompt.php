<?php
/**
 * WP2PcloudRatingPrompt class
 *
 * @file class-wp2pcloudratingprompt.php
 * @package pcloud_wp_backup
 */

namespace Pcloud\Classes;

/**
 * Displays a non-intrusive rating prompt on the plugin settings page after
 * 2 successful backups and at least 24 hours since activation.
 *
 * States (stored in wp_options as `wp2pcl_rating_state`):
 *  - "pending"          → prompt has never been shown (or threshold not met).
 *  - "dismissed_later"  → user clicked "Remind me later"; re-ask after 7 days.
 *  - "resolved"         → user clicked "Rate" / "Feedback" / "Don't ask again". Never re-prompt.
 *
 * Counter (stored as `wp2pcl_successful_backups`, int): bumped by the caller
 * (main plugin) at every successful backup state transition.
 */
class WP2PcloudRatingPrompt {

	private const OPT_STATE      = 'wp2pcl_rating_state';
	private const OPT_COUNT      = 'wp2pcl_successful_backups';
	private const OPT_INSTALLED  = 'wp2pcl_installed_at';
	private const OPT_REMIND_AT  = 'wp2pcl_rating_remind_at';

	private const THRESHOLD      = 2;
	private const MIN_AGE_HOURS  = 24;
	private const REMIND_DAYS    = 7;

	private const REVIEW_URL = 'https://wordpress.org/support/plugin/pcloud-wp-backup/reviews/#new-post';
	private const SUPPORT_EMAIL = 'support@pcloud.com';

	/**
	 * Record a successful backup. Called from the main plugin at every backup-
	 * success state transition (both manual and auto).
	 */
	public static function record_successful_backup(): void {
		$count = intval( get_option( self::OPT_COUNT, 0 ) );
		update_option( self::OPT_COUNT, $count + 1, false );

		self::ensure_installed_at();
	}

	/**
	 * Ensure the "installed at" timestamp exists. For fresh installs this is set
	 * by on_activate(). For existing installs upgrading from a version without
	 * the rating prompt, we use the earliest known backup timestamp
	 * (PCLOUD_LAST_BACKUPDT) as a proxy — the user has clearly been active
	 * since then. Falls back to time() only if no prior backup exists.
	 */
	private static function ensure_installed_at(): void {
		if ( false !== get_option( self::OPT_INSTALLED, false ) ) {
			return;
		}

		// We're here because on_activate() never ran — this is an existing install
		// upgrading to a version with the rating prompt. The user has been running
		// the plugin long enough to accumulate successful backups, so the 24h
		// cooling-off period (designed to skip brand-new "just testing" installs)
		// has obviously already passed. Set installed_at far enough in the past
		// to clear the gate immediately.
		$ts = time() - ( ( self::MIN_AGE_HOURS + 1 ) * HOUR_IN_SECONDS );
		add_option( self::OPT_INSTALLED, $ts, '', 'no' );
	}

	/**
	 * Hook into admin_notices (called from the main plugin).
	 */
	public static function maybe_render(): void {
		if ( ! self::should_show() ) {
			return;
		}
		self::render_notice();
	}

	/**
	 * Handle the AJAX dismiss/resolve action.
	 */
	public static function handle_ajax(): void {
		if ( ! current_user_can( 'administrator' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_key( $_POST['nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wp2pcl_rating_nonce' ) ) {
			wp_send_json_error( 'Invalid nonce.', 403 );
		}

		$action = isset( $_POST['rating_action'] ) ? sanitize_text_field( wp_unslash( $_POST['rating_action'] ) ) : '';

		switch ( $action ) {
			case 'rated':
			case 'feedback':
			case 'dismiss_forever':
				update_option( self::OPT_STATE, 'resolved', false );
				break;

			case 'remind_later':
				update_option( self::OPT_STATE, 'dismissed_later', false );
				update_option( self::OPT_REMIND_AT, time() + ( self::REMIND_DAYS * DAY_IN_SECONDS ), false );
				break;

			default:
				wp_send_json_error( 'Unknown action.', 400 );
		}

		wp_send_json_success();
	}

	/**
	 * Register the option rows on plugin activation so they exist on first read.
	 */
	public static function on_activate(): void {
		if ( false === get_option( self::OPT_COUNT, false ) ) {
			add_option( self::OPT_COUNT, 0, '', 'no' );
		}
		if ( false === get_option( self::OPT_STATE, false ) ) {
			add_option( self::OPT_STATE, 'pending', '', 'no' );
		}
		if ( false === get_option( self::OPT_INSTALLED, false ) ) {
			add_option( self::OPT_INSTALLED, time(), '', 'no' );
		}
	}

	/**
	 * Clean up on full uninstall.
	 */
	public static function on_uninstall(): void {
		delete_option( self::OPT_STATE );
		delete_option( self::OPT_COUNT );
		delete_option( self::OPT_INSTALLED );
		delete_option( self::OPT_REMIND_AT );
	}

	/**
	 * Decide whether the prompt should render right now.
	 */
	private static function should_show(): bool {

		if ( ! current_user_can( 'administrator' ) ) {
			return false;
		}

		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore
		if ( 'wp2pcloud_settings' !== $page ) {
			return false;
		}

		// Lazy-init for existing installs that never went through on_activate().
		self::ensure_installed_at();

		$state = get_option( self::OPT_STATE, 'pending' );
		if ( 'resolved' === $state ) {
			return false;
		}

		if ( 'dismissed_later' === $state ) {
			$remind_at = intval( get_option( self::OPT_REMIND_AT, 0 ) );
			if ( $remind_at > 0 && time() < $remind_at ) {
				return false;
			}
		}

		$count = intval( get_option( self::OPT_COUNT, 0 ) );
		if ( $count < self::THRESHOLD ) {
			return false;
		}

		// The 24h cooling-off period avoids prompting users who just installed and are
		// still testing. If the counter already proves real usage (>= threshold) this is
		// an upgrade from a pre-prompt version and the cooling period is meaningless.
		$installed_at       = intval( get_option( self::OPT_INSTALLED, 0 ) );
		$age_seconds        = ( $installed_at > 0 ) ? ( time() - $installed_at ) : PHP_INT_MAX;
		$min_seconds        = self::MIN_AGE_HOURS * HOUR_IN_SECONDS;
		$is_upgrade_install = ( $count >= self::THRESHOLD );

		if ( ! $is_upgrade_install && $age_seconds < $min_seconds ) {
			return false;
		}

		return true;
	}

	/**
	 * Render a centered modal overlay with the two-step rating flow.
	 */
	private static function render_notice(): void {
		$nonce      = wp_create_nonce( 'wp2pcl_rating_nonce' );
		$review_url = esc_url( self::REVIEW_URL );
		$mailto     = esc_url( 'mailto:' . self::SUPPORT_EMAIL . '?subject=' . rawurlencode( 'pCloud WP Backup feedback' ) );
		$ajax_url   = esc_url( admin_url( 'admin-ajax.php' ) );
		?>
		<style>
			#wp2pcl-rating-overlay {
				position: fixed; inset: 0; z-index: 999999;
				background: rgba(0,0,0,0.55);
				display: flex; align-items: center; justify-content: center;
				animation: wp2pcl-fadein 0.3s ease;
			}
			@keyframes wp2pcl-fadein { from { opacity: 0; } to { opacity: 1; } }
			@keyframes wp2pcl-slideup { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: translateY(0); } }

			#wp2pcl-rating-card {
				background: #fff; border-radius: 16px; padding: 36px 40px 32px;
				max-width: 460px; width: 90%; position: relative;
				box-shadow: 0 20px 60px rgba(0,0,0,0.3);
				text-align: center;
				animation: wp2pcl-slideup 0.35s ease;
			}
			#wp2pcl-rating-card .wp2pcl-close {
				position: absolute; top: 12px; right: 16px;
				background: none; border: none; font-size: 22px; color: #999;
				cursor: pointer; line-height: 1; padding: 4px;
			}
			#wp2pcl-rating-card .wp2pcl-close:hover { color: #333; }
			#wp2pcl-rating-card .wp2pcl-icon { font-size: 52px; margin-bottom: 12px; }
			#wp2pcl-rating-card h3 {
				font-size: 20px; font-weight: 700; margin: 0 0 8px; color: #1d2327;
			}
			#wp2pcl-rating-card p {
				font-size: 14px; color: #50575e; margin: 0 0 24px; line-height: 1.6;
			}
			#wp2pcl-rating-card .wp2pcl-btns { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
			#wp2pcl-rating-card .wp2pcl-btn {
				display: inline-flex; align-items: center; gap: 6px;
				padding: 12px 28px; border-radius: 8px; font-size: 14px; font-weight: 600;
				border: none; cursor: pointer; text-decoration: none; transition: all 0.15s;
			}
			#wp2pcl-rating-card .wp2pcl-btn-primary {
				background: #0073aa; color: #fff;
			}
			#wp2pcl-rating-card .wp2pcl-btn-primary:hover { background: #005a87; color: #fff; }
			#wp2pcl-rating-card .wp2pcl-btn-outline {
				background: #f0f0f1; color: #50575e;
			}
			#wp2pcl-rating-card .wp2pcl-btn-outline:hover { background: #e2e4e7; }
			#wp2pcl-rating-card .wp2pcl-btn-star {
				background: linear-gradient(135deg, #f59e0b, #f97316); color: #fff;
			}
			#wp2pcl-rating-card .wp2pcl-btn-star:hover { filter: brightness(1.1); }
			#wp2pcl-rating-card .wp2pcl-link {
				display: inline-block; margin-top: 16px; font-size: 13px;
				color: #999; text-decoration: none; cursor: pointer;
			}
			#wp2pcl-rating-card .wp2pcl-link:hover { color: #666; text-decoration: underline; }
			#wp2pcl-rating-card .wp2pcl-stars { font-size: 32px; letter-spacing: 4px; margin-bottom: 6px; }
		</style>

		<div id="wp2pcl-rating-overlay">
			<div id="wp2pcl-rating-card">
				<button type="button" class="wp2pcl-close" data-wp2pcl-action="remind_later" title="<?php echo esc_attr__( 'Close', 'pcloud-wp-backup' ); ?>">&times;</button>

				<!-- Step 1: Initial question -->
				<div id="wp2pcl-rating-step1">
					<div class="wp2pcl-icon">&#9729;&#65039;</div>
					<h3><?php echo esc_html__( "Enjoying pCloud WP Backup?", 'pcloud-wp-backup' ); ?></h3>
					<p><?php echo esc_html__( "Your backups are running smoothly! We'd love to hear how things are going.", 'pcloud-wp-backup' ); ?></p>
					<div class="wp2pcl-btns">
						<button type="button" class="wp2pcl-btn wp2pcl-btn-primary" data-wp2pcl-step="happy">
							&#128077; <?php echo esc_html__( "Love it!", 'pcloud-wp-backup' ); ?>
						</button>
						<button type="button" class="wp2pcl-btn wp2pcl-btn-outline" data-wp2pcl-step="unhappy">
							&#128076; <?php echo esc_html__( 'Not great', 'pcloud-wp-backup' ); ?>
						</button>
					</div>
				</div>

				<!-- Step 2a: Happy path -->
				<div id="wp2pcl-rating-step-happy" style="display: none;">
					<div class="wp2pcl-stars">&#11088;&#11088;&#11088;&#11088;&#11088;</div>
					<h3><?php echo esc_html__( "That's wonderful!", 'pcloud-wp-backup' ); ?></h3>
					<p><?php echo esc_html__( "A quick 5-star rating on WordPress.org helps other site owners discover a backup solution that actually works. It only takes a moment!", 'pcloud-wp-backup' ); ?></p>
					<div class="wp2pcl-btns">
						<a href="<?php echo $review_url; ?>" target="_blank" rel="noopener noreferrer"
						   class="wp2pcl-btn wp2pcl-btn-star" data-wp2pcl-action="rated">
							&#9733; <?php echo esc_html__( 'Rate 5 Stars', 'pcloud-wp-backup' ); ?>
						</a>
						<button type="button" class="wp2pcl-btn wp2pcl-btn-outline" data-wp2pcl-action="remind_later">
							<?php echo esc_html__( 'Maybe later', 'pcloud-wp-backup' ); ?>
						</button>
					</div>
					<a href="#" class="wp2pcl-link" data-wp2pcl-action="dismiss_forever"><?php echo esc_html__( "I already did / Don't ask again", 'pcloud-wp-backup' ); ?></a>
				</div>

				<!-- Step 2b: Unhappy path -->
				<div id="wp2pcl-rating-step-unhappy" style="display: none;">
					<div class="wp2pcl-icon">&#128172;</div>
					<h3><?php echo esc_html__( "We'd love to help!", 'pcloud-wp-backup' ); ?></h3>
					<p><?php echo esc_html__( "Please tell us what went wrong and we'll do our best to fix it. Your feedback makes the plugin better for everyone.", 'pcloud-wp-backup' ); ?></p>
					<div class="wp2pcl-btns">
						<a href="<?php echo $mailto; ?>" class="wp2pcl-btn wp2pcl-btn-primary" data-wp2pcl-action="feedback">
							&#9993; <?php echo esc_html__( 'Send Feedback', 'pcloud-wp-backup' ); ?>
						</a>
						<button type="button" class="wp2pcl-btn wp2pcl-btn-outline" data-wp2pcl-action="remind_later">
							<?php echo esc_html__( 'Maybe later', 'pcloud-wp-backup' ); ?>
						</button>
					</div>
					<a href="#" class="wp2pcl-link" data-wp2pcl-action="dismiss_forever"><?php echo esc_html__( "Don't ask again", 'pcloud-wp-backup' ); ?></a>
				</div>
			</div>
		</div>

		<script>
		(function() {
			var overlay = document.getElementById('wp2pcl-rating-overlay');
			if (!overlay) return;

			var step1   = document.getElementById('wp2pcl-rating-step1');
			var happy   = document.getElementById('wp2pcl-rating-step-happy');
			var unhappy = document.getElementById('wp2pcl-rating-step-unhappy');

			function dismiss() {
				overlay.style.transition = 'opacity 0.25s';
				overlay.style.opacity = '0';
				setTimeout(function() { overlay.remove(); }, 300);
			}

			function sendAction(ratingAction) {
				var xhr = new XMLHttpRequest();
				xhr.open('POST', '<?php echo $ajax_url; ?>');
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.send(
					'action=wp2pcl_rating_dismiss' +
					'&nonce=<?php echo esc_js( $nonce ); ?>' +
					'&rating_action=' + encodeURIComponent(ratingAction)
				);
			}

			overlay.addEventListener('click', function(e) {
				/* Clicking the backdrop (outside the card) = remind later */
				if (e.target === overlay) {
					sendAction('remind_later');
					dismiss();
					return;
				}

				/* Step transitions */
				var stepBtn = e.target.closest('[data-wp2pcl-step]');
				if (stepBtn) {
					step1.style.display = 'none';
					var target = stepBtn.dataset.wp2pclStep === 'happy' ? happy : unhappy;
					target.style.display = '';
					target.style.animation = 'wp2pcl-fadein 0.2s ease';
					return;
				}

				/* Action buttons */
				var actionBtn = e.target.closest('[data-wp2pcl-action]');
				if (!actionBtn) return;

				e.preventDefault();
				var action = actionBtn.dataset.wp2pclAction;
				sendAction(action);

				/* If the button is a link (Rate / Feedback), let it open first */
				if (actionBtn.href && actionBtn.href !== '#' && !actionBtn.href.endsWith('#')) {
					window.open(actionBtn.href, '_blank');
				}

				dismiss();
			});
		})();
		</script>
		<?php
	}
}
