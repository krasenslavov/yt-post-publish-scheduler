<?php
/**
 * Plugin Name: YT Post Publish Scheduler
 * Plugin URI: https://github.com/krasenslavov/yt-post-publish-scheduler
 * Description: Schedule posts to automatically unpublish and republish at specified dates. Perfect for seasonal content, time-limited offers, and recurring announcements. Uses WordPress cron for reliable scheduling.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Krasen Slavov
 * Author URI: https://krasenslavov.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yt-post-publish-scheduler
 * Domain Path: /languages
 *
 * @package YT_Post_Publish_Scheduler
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'YT_PPS_VERSION', '1.0.0' );
define( 'YT_PPS_BASENAME', plugin_basename( __FILE__ ) );
define( 'YT_PPS_PATH', plugin_dir_path( __FILE__ ) );
define( 'YT_PPS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main Plugin Class
 *
 * @since 1.0.0
 */
class YT_Post_Publish_Scheduler {

	/**
	 * Single instance of the class.
	 *
	 * @var YT_Post_Publish_Scheduler
	 */
	private static $instance = null;

	/**
	 * Plugin options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Get single instance.
	 *
	 * @return YT_Post_Publish_Scheduler
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_options();
		$this->init_hooks();
	}

	/**
	 * Load plugin options.
	 */
	private function load_options() {
		$this->options = get_option(
			'yt_pps_options',
			array(
				'enabled_post_types' => array( 'post' ),
				'unpublish_status'   => 'draft',
				'send_notifications' => true,
				'notification_email' => get_option( 'admin_email' ),
				'log_actions'        => true,
				'allow_past_dates'   => false,
				'timezone_display'   => 'local',
			)
		);
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function init_hooks() {
		// Plugin lifecycle.
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		// Core hooks.
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Meta box.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );

		// Cron hooks.
		add_action( 'yt_pps_unpublish_post', array( $this, 'unpublish_post' ) );
		add_action( 'yt_pps_republish_post', array( $this, 'republish_post' ) );

		// Admin columns.
		add_filter( 'manage_posts_columns', array( $this, 'add_admin_columns' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'render_admin_columns' ), 10, 2 );

		// Admin notices.
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );

		// Plugin links.
		add_filter( 'plugin_action_links_' . YT_PPS_BASENAME, array( $this, 'add_action_links' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_yt_pps_clear_schedule', array( $this, 'ajax_clear_schedule' ) );
		add_action( 'wp_ajax_yt_pps_get_scheduled_posts', array( $this, 'ajax_get_scheduled_posts' ) );
	}

	/**
	 * Plugin activation.
	 */
	public function activate() {
		if ( ! get_option( 'yt_pps_options' ) ) {
			add_option(
				'yt_pps_options',
				array(
					'enabled_post_types' => array( 'post' ),
					'unpublish_status'   => 'draft',
					'send_notifications' => true,
					'notification_email' => get_option( 'admin_email' ),
					'log_actions'        => true,
					'allow_past_dates'   => false,
					'timezone_display'   => 'local',
				)
			);
		}

		// Create log table.
		$this->create_log_table();

		// Set activation flag.
		set_transient( 'yt_pps_activated', true, 30 );
	}

	/**
	 * Plugin deactivation.
	 */
	public function deactivate() {
		// Clear all scheduled events.
		$this->clear_all_schedules();

		delete_transient( 'yt_pps_activated' );
	}

	/**
	 * Create log table.
	 */
	private function create_log_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'pps_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			post_id bigint(20) NOT NULL,
			action varchar(20) NOT NULL,
			old_status varchar(20) DEFAULT NULL,
			new_status varchar(20) DEFAULT NULL,
			scheduled_date datetime NOT NULL,
			executed_date datetime NOT NULL,
			success tinyint(1) DEFAULT 1,
			message text DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY action (action),
			KEY executed_date (executed_date)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Clear all scheduled cron events.
	 */
	private function clear_all_schedules() {
		$args = array(
			'post_type'      => 'any',
			'posts_per_page' => -1,
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_yt_pps_unpublish_date',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_yt_pps_republish_date',
					'compare' => 'EXISTS',
				),
			),
		);

		$posts = get_posts( $args );

		foreach ( $posts as $post ) {
			wp_clear_scheduled_hook( 'yt_pps_unpublish_post', array( $post->ID ) );
			wp_clear_scheduled_hook( 'yt_pps_republish_post', array( $post->ID ) );
		}
	}

	/**
	 * Load text domain for translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'yt-post-publish-scheduler',
			false,
			dirname( YT_PPS_BASENAME ) . '/languages'
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		global $post_type;

		// Only load on post edit screens and settings page.
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'settings_page_yt-post-publish-scheduler' ), true ) ) {
			return;
		}

		// Check if post type is enabled.
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) &&
			! in_array( $post_type, $this->options['enabled_post_types'], true ) ) {
			return;
		}

		wp_enqueue_style(
			'yt-pps-admin',
			YT_PPS_URL . 'assets/css/yt-post-publish-scheduler.css',
			array(),
			YT_PPS_VERSION
		);

		wp_enqueue_script(
			'yt-pps-admin',
			YT_PPS_URL . 'assets/js/yt-post-publish-scheduler.js',
			array( 'jquery', 'jquery-ui-datepicker' ),
			YT_PPS_VERSION,
			true
		);

		wp_enqueue_style( 'jquery-ui-datepicker' );

		wp_localize_script(
			'yt-pps-admin',
			'ytPpsData',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'yt_pps_nonce' ),
				'dateFormat'     => 'yy-mm-dd',
				'timeFormat'     => 'H:i',
				'allowPastDates' => $this->options['allow_past_dates'],
				'strings'        => array(
					'confirmClear'    => __( 'Are you sure you want to clear this schedule?', 'yt-post-publish-scheduler' ),
					'invalidDate'     => __( 'Invalid date format.', 'yt-post-publish-scheduler' ),
					'pastDate'        => __( 'Past dates are not allowed.', 'yt-post-publish-scheduler' ),
					'unpublishAfter'  => __( 'Unpublish date must be before republish date.', 'yt-post-publish-scheduler' ),
					'scheduleCleared' => __( 'Schedule cleared successfully.', 'yt-post-publish-scheduler' ),
					'error'           => __( 'An error occurred.', 'yt-post-publish-scheduler' ),
				),
			)
		);
	}

	/**
	 * Add settings page to admin menu.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Post Publish Scheduler', 'yt-post-publish-scheduler' ),
			__( 'Publish Scheduler', 'yt-post-publish-scheduler' ),
			'manage_options',
			'yt-post-publish-scheduler',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		register_setting(
			'yt_pps_settings',
			'yt_pps_options',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
			)
		);
	}

	/**
	 * Sanitize plugin options.
	 *
	 * @param array $input Raw input values.
	 * @return array Sanitized values.
	 */
	public function sanitize_options( $input ) {
		$sanitized = array();

		$sanitized['enabled_post_types'] = array();
		if ( ! empty( $input['enabled_post_types'] ) && is_array( $input['enabled_post_types'] ) ) {
			foreach ( $input['enabled_post_types'] as $post_type ) {
				$sanitized['enabled_post_types'][] = sanitize_key( $post_type );
			}
		}

		$sanitized['unpublish_status']   = sanitize_key( $input['unpublish_status'] ?? 'draft' );
		$sanitized['send_notifications'] = ! empty( $input['send_notifications'] );
		$sanitized['notification_email'] = sanitize_email( $input['notification_email'] ?? get_option( 'admin_email' ) );
		$sanitized['log_actions']        = ! empty( $input['log_actions'] );
		$sanitized['allow_past_dates']   = ! empty( $input['allow_past_dates'] );
		$sanitized['timezone_display']   = sanitize_text_field( $input['timezone_display'] ?? 'local' );

		return $sanitized;
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error(
				'yt_pps_messages',
				'yt_pps_message',
				__( 'Settings saved successfully.', 'yt-post-publish-scheduler' ),
				'success'
			);
		}

		settings_errors( 'yt_pps_messages' );
		?>
		<div class="wrap yt-pps-settings-page">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'yt_pps_settings' ); ?>

				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Enabled Post Types', 'yt-post-publish-scheduler' ); ?></th>
						<td>
							<?php
							$post_types = get_post_types( array( 'public' => true ), 'objects' );
							foreach ( $post_types as $post_type ) :
								if ( 'attachment' === $post_type->name ) {
									continue;
								}
								$checked = in_array( $post_type->name, $this->options['enabled_post_types'], true );
								?>
								<label>
									<input type="checkbox" name="yt_pps_options[enabled_post_types][]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( $checked, true ); ?>>
									<?php echo esc_html( $post_type->label ); ?>
								</label><br>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Select post types where scheduling is available', 'yt-post-publish-scheduler' ); ?></p>
						</td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'Unpublish Status', 'yt-post-publish-scheduler' ); ?></th>
						<td>
							<select name="yt_pps_options[unpublish_status]">
								<option value="draft" <?php selected( $this->options['unpublish_status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'yt-post-publish-scheduler' ); ?></option>
								<option value="pending" <?php selected( $this->options['unpublish_status'], 'pending' ); ?>><?php esc_html_e( 'Pending Review', 'yt-post-publish-scheduler' ); ?></option>
								<option value="private" <?php selected( $this->options['unpublish_status'], 'private' ); ?>><?php esc_html_e( 'Private', 'yt-post-publish-scheduler' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Status to set when unpublishing posts', 'yt-post-publish-scheduler' ); ?></p>
						</td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'Email Notifications', 'yt-post-publish-scheduler' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="yt_pps_options[send_notifications]" value="1" <?php checked( $this->options['send_notifications'], true ); ?>>
								<?php esc_html_e( 'Send email notifications', 'yt-post-publish-scheduler' ); ?>
							</label>
							<p>
								<input type="email" name="yt_pps_options[notification_email]" value="<?php echo esc_attr( $this->options['notification_email'] ); ?>" class="regular-text">
							</p>
							<p class="description"><?php esc_html_e( 'Email address to receive notifications', 'yt-post-publish-scheduler' ); ?></p>
						</td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'Log Actions', 'yt-post-publish-scheduler' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="yt_pps_options[log_actions]" value="1" <?php checked( $this->options['log_actions'], true ); ?>>
								<?php esc_html_e( 'Log all scheduling actions', 'yt-post-publish-scheduler' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Keep a log of all unpublish/republish actions', 'yt-post-publish-scheduler' ); ?></p>
						</td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'Allow Past Dates', 'yt-post-publish-scheduler' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="yt_pps_options[allow_past_dates]" value="1" <?php checked( $this->options['allow_past_dates'], true ); ?>>
								<?php esc_html_e( 'Allow scheduling in the past', 'yt-post-publish-scheduler' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Enable this for testing or backdating schedules', 'yt-post-publish-scheduler' ); ?></p>
						</td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'Timezone Display', 'yt-post-publish-scheduler' ); ?></th>
						<td>
							<select name="yt_pps_options[timezone_display]">
								<option value="local" <?php selected( $this->options['timezone_display'], 'local' ); ?>><?php esc_html_e( 'Site Timezone', 'yt-post-publish-scheduler' ); ?></option>
								<option value="utc" <?php selected( $this->options['timezone_display'], 'utc' ); ?>><?php esc_html_e( 'UTC', 'yt-post-publish-scheduler' ); ?></option>
							</select>
							<p class="description">
								<?php
								printf(
									/* translators: %s: timezone string */
									esc_html__( 'Current site timezone: %s', 'yt-post-publish-scheduler' ),
									esc_html( wp_timezone_string() )
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'yt-post-publish-scheduler' ) ); ?>
			</form>

			<div class="yt-pps-info-box">
				<h2><?php esc_html_e( 'Scheduled Posts', 'yt-post-publish-scheduler' ); ?></h2>
				<?php $this->render_scheduled_posts_table(); ?>
			</div>

			<?php if ( $this->options['log_actions'] ) : ?>
			<div class="yt-pps-info-box">
				<h2><?php esc_html_e( 'Recent Activity Log', 'yt-post-publish-scheduler' ); ?></h2>
				<?php $this->render_activity_log(); ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render scheduled posts table.
	 */
	private function render_scheduled_posts_table() {
		$scheduled_posts = $this->get_scheduled_posts();

		if ( empty( $scheduled_posts ) ) {
			echo '<p>' . esc_html__( 'No posts currently scheduled.', 'yt-post-publish-scheduler' ) . '</p>';
			return;
		}
		?>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Post', 'yt-post-publish-scheduler' ); ?></th>
					<th><?php esc_html_e( 'Type', 'yt-post-publish-scheduler' ); ?></th>
					<th><?php esc_html_e( 'Unpublish Date', 'yt-post-publish-scheduler' ); ?></th>
					<th><?php esc_html_e( 'Republish Date', 'yt-post-publish-scheduler' ); ?></th>
					<th><?php esc_html_e( 'Status', 'yt-post-publish-scheduler' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $scheduled_posts as $post ) : ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
							<?php echo esc_html( $post->post_title ); ?>
						</a>
					</td>
					<td><?php echo esc_html( get_post_type_object( $post->post_type )->labels->singular_name ); ?></td>
					<td><?php echo esc_html( $this->format_date( get_post_meta( $post->ID, '_yt_pps_unpublish_date', true ) ) ); ?></td>
					<td><?php echo esc_html( $this->format_date( get_post_meta( $post->ID, '_yt_pps_republish_date', true ) ) ); ?></td>
					<td><?php echo esc_html( ucfirst( $post->post_status ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render activity log.
	 */
	private function render_activity_log() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'pps_logs';

		$logs = $wpdb->get_results(
			"SELECT * FROM $table_name ORDER BY executed_date DESC LIMIT 50", // phpcs:ignore
			ARRAY_A
		);

		if ( empty( $logs ) ) {
			echo '<p>' . esc_html__( 'No activity logged yet.', 'yt-post-publish-scheduler' ) . '</p>';
			return;
		}
		?>
		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'yt-post-publish-scheduler' ); ?></th>
					<th><?php esc_html_e( 'Post', 'yt-post-publish-scheduler' ); ?></th>
					<th><?php esc_html_e( 'Action', 'yt-post-publish-scheduler' ); ?></th>
					<th><?php esc_html_e( 'Status Change', 'yt-post-publish-scheduler' ); ?></th>
					<th><?php esc_html_e( 'Result', 'yt-post-publish-scheduler' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
				<tr>
					<td><?php echo esc_html( $this->format_date( $log['executed_date'] ) ); ?></td>
					<td>
						<?php
						$post = get_post( $log['post_id'] );
						if ( $post ) {
							echo '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( $post->post_title ) . '</a>';
						} else {
							echo esc_html__( '(Deleted)', 'yt-post-publish-scheduler' );
						}
						?>
					</td>
					<td><?php echo esc_html( ucfirst( $log['action'] ) ); ?></td>
					<td><?php echo esc_html( $log['old_status'] ) . ' → ' . esc_html( $log['new_status'] ); ?></td>
					<td>
						<?php if ( $log['success'] ) : ?>
							<span class="yt-pps-status-success"><?php esc_html_e( 'Success', 'yt-post-publish-scheduler' ); ?></span>
						<?php else : ?>
							<span class="yt-pps-status-error"><?php esc_html_e( 'Failed', 'yt-post-publish-scheduler' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Get scheduled posts.
	 *
	 * @return array Posts with scheduling.
	 */
	private function get_scheduled_posts() {
		$args = array(
			'post_type'      => $this->options['enabled_post_types'],
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_yt_pps_unpublish_date',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_yt_pps_republish_date',
					'compare' => 'EXISTS',
				),
			),
		);

		return get_posts( $args );
	}

	/**
	 * Add meta box.
	 */
	public function add_meta_box() {
		foreach ( $this->options['enabled_post_types'] as $post_type ) {
			add_meta_box(
				'yt_pps_meta_box',
				__( 'Publish Schedule', 'yt-post-publish-scheduler' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render meta box.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'yt_pps_save_meta_box', 'yt_pps_nonce' );

		$unpublish_date = get_post_meta( $post->ID, '_yt_pps_unpublish_date', true );
		$republish_date = get_post_meta( $post->ID, '_yt_pps_republish_date', true );
		?>
		<div class="yt-pps-meta-box">
			<p>
				<label for="yt-pps-unpublish-date">
					<strong><?php esc_html_e( 'Unpublish Date', 'yt-post-publish-scheduler' ); ?></strong>
				</label>
				<input type="datetime-local" id="yt-pps-unpublish-date" name="yt_pps_unpublish_date" value="<?php echo esc_attr( $unpublish_date ? gmdate( 'Y-m-d\TH:i', strtotime( $unpublish_date ) ) : '' ); ?>" class="widefat">
				<span class="description"><?php esc_html_e( 'When to automatically unpublish this post', 'yt-post-publish-scheduler' ); ?></span>
			</p>

			<p>
				<label for="yt-pps-republish-date">
					<strong><?php esc_html_e( 'Republish Date', 'yt-post-publish-scheduler' ); ?></strong>
				</label>
				<input type="datetime-local" id="yt-pps-republish-date" name="yt_pps_republish_date" value="<?php echo esc_attr( $republish_date ? gmdate( 'Y-m-d\TH:i', strtotime( $republish_date ) ) : '' ); ?>" class="widefat">
				<span class="description"><?php esc_html_e( 'When to automatically republish this post', 'yt-post-publish-scheduler' ); ?></span>
			</p>

			<?php if ( $unpublish_date || $republish_date ) : ?>
			<p>
				<button type="button" id="yt-pps-clear-schedule" class="button">
					<?php esc_html_e( 'Clear Schedule', 'yt-post-publish-scheduler' ); ?>
				</button>
			</p>
			<?php endif; ?>

			<div class="yt-pps-schedule-info">
				<?php if ( $unpublish_date ) : ?>
					<p>
						<span class="dashicons dashicons-clock"></span>
						<?php
						printf(
							/* translators: %s: formatted date */
							esc_html__( 'Scheduled to unpublish: %s', 'yt-post-publish-scheduler' ),
							'<strong>' . esc_html( $this->format_date( $unpublish_date ) ) . '</strong>'
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( $republish_date ) : ?>
					<p>
						<span class="dashicons dashicons-clock"></span>
						<?php
						printf(
							/* translators: %s: formatted date */
							esc_html__( 'Scheduled to republish: %s', 'yt-post-publish-scheduler' ),
							'<strong>' . esc_html( $this->format_date( $republish_date ) ) . '</strong>'
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Save meta box data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_meta_box( $post_id, $post ) {
		// Verify nonce.
		if ( ! isset( $_POST['yt_pps_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['yt_pps_nonce'] ) ), 'yt_pps_save_meta_box' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Check post type.
		if ( ! in_array( $post->post_type, $this->options['enabled_post_types'], true ) ) {
			return;
		}

		// Get old values.
		$old_unpublish = get_post_meta( $post_id, '_yt_pps_unpublish_date', true );
		$old_republish = get_post_meta( $post_id, '_yt_pps_republish_date', true );

		// Get new values.
		$unpublish_date = isset( $_POST['yt_pps_unpublish_date'] ) ? sanitize_text_field( wp_unslash( $_POST['yt_pps_unpublish_date'] ) ) : '';
		$republish_date = isset( $_POST['yt_pps_republish_date'] ) ? sanitize_text_field( wp_unslash( $_POST['yt_pps_republish_date'] ) ) : '';

		// Convert to MySQL datetime format.
		if ( $unpublish_date ) {
			$unpublish_date = gmdate( 'Y-m-d H:i:s', strtotime( $unpublish_date ) );
		}
		if ( $republish_date ) {
			$republish_date = gmdate( 'Y-m-d H:i:s', strtotime( $republish_date ) );
		}

		// Clear old schedules.
		wp_clear_scheduled_hook( 'yt_pps_unpublish_post', array( $post_id ) );
		wp_clear_scheduled_hook( 'yt_pps_republish_post', array( $post_id ) );

		// Save unpublish date.
		if ( $unpublish_date ) {
			update_post_meta( $post_id, '_yt_pps_unpublish_date', $unpublish_date );
			$timestamp = strtotime( get_gmt_from_date( $unpublish_date ) );
			if ( $timestamp > time() ) {
				wp_schedule_single_event( $timestamp, 'yt_pps_unpublish_post', array( $post_id ) );
			}
		} else {
			delete_post_meta( $post_id, '_yt_pps_unpublish_date' );
		}

		// Save republish date.
		if ( $republish_date ) {
			update_post_meta( $post_id, '_yt_pps_republish_date', $republish_date );
			$timestamp = strtotime( get_gmt_from_date( $republish_date ) );
			if ( $timestamp > time() ) {
				wp_schedule_single_event( $timestamp, 'yt_pps_republish_post', array( $post_id ) );
			}
		} else {
			delete_post_meta( $post_id, '_yt_pps_republish_date' );
		}
	}

	/**
	 * Unpublish post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function unpublish_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		$old_status = $post->post_status;
		$new_status = $this->options['unpublish_status'];

		// Update post status.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $new_status,
			)
		);

		// Log action.
		$this->log_action(
			$post_id,
			'unpublish',
			$old_status,
			$new_status,
			get_post_meta( $post_id, '_yt_pps_unpublish_date', true )
		);

		// Send notification.
		if ( $this->options['send_notifications'] ) {
			$this->send_notification( $post_id, 'unpublish' );
		}

		// Clear meta.
		delete_post_meta( $post_id, '_yt_pps_unpublish_date' );
	}

	/**
	 * Republish post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function republish_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		$old_status = $post->post_status;
		$new_status = 'publish';

		// Update post status.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $new_status,
			)
		);

		// Log action.
		$this->log_action(
			$post_id,
			'republish',
			$old_status,
			$new_status,
			get_post_meta( $post_id, '_yt_pps_republish_date', true )
		);

		// Send notification.
		if ( $this->options['send_notifications'] ) {
			$this->send_notification( $post_id, 'republish' );
		}

		// Clear meta.
		delete_post_meta( $post_id, '_yt_pps_republish_date' );
	}

	/**
	 * Log action to database.
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $action        Action type.
	 * @param string $old_status    Old status.
	 * @param string $new_status    New status.
	 * @param string $scheduled_date Scheduled date.
	 */
	private function log_action( $post_id, $action, $old_status, $new_status, $scheduled_date ) {
		if ( ! $this->options['log_actions'] ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'pps_logs';

		$wpdb->insert(
			$table_name,
			array(
				'post_id'        => $post_id,
				'action'         => $action,
				'old_status'     => $old_status,
				'new_status'     => $new_status,
				'scheduled_date' => $scheduled_date,
				'executed_date'  => current_time( 'mysql' ),
				'success'        => 1,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Send email notification.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  Action type.
	 */
	private function send_notification( $post_id, $action ) {
		$post  = get_post( $post_id );
		$email = $this->options['notification_email'];

		if ( ! $email ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: action, 2: post title */
			__( '[%1$s] Post %2$s: %3$s', 'yt-post-publish-scheduler' ),
			get_bloginfo( 'name' ),
			'unpublish' === $action ? __( 'Unpublished', 'yt-post-publish-scheduler' ) : __( 'Republished', 'yt-post-publish-scheduler' ),
			$post->post_title
		);

		$message = sprintf(
			/* translators: 1: post title, 2: action, 3: post URL */
			__( 'The post "%1$s" has been %2$s automatically.', 'yt-post-publish-scheduler' ) . "\n\n" .
			__( 'View post:', 'yt-post-publish-scheduler' ) . ' %3$s' . "\n" .
			__( 'Edit post:', 'yt-post-publish-scheduler' ) . ' %4$s',
			$post->post_title,
			'unpublish' === $action ? __( 'unpublished', 'yt-post-publish-scheduler' ) : __( 'republished', 'yt-post-publish-scheduler' ),
			get_permalink( $post_id ),
			get_edit_post_link( $post_id, 'raw' )
		);

		wp_mail( $email, $subject, $message );
	}

	/**
	 * Format date for display.
	 *
	 * @param string $date Date string.
	 * @return string Formatted date.
	 */
	private function format_date( $date ) {
		if ( empty( $date ) ) {
			return '-';
		}

		return get_date_from_gmt( $date, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * Add admin columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_admin_columns( $columns ) {
		$columns['yt_pps_schedule'] = __( 'Schedule', 'yt-post-publish-scheduler' );
		return $columns;
	}

	/**
	 * Render admin columns.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_admin_columns( $column, $post_id ) {
		if ( 'yt_pps_schedule' !== $column ) {
			return;
		}

		$unpublish_date = get_post_meta( $post_id, '_yt_pps_unpublish_date', true );
		$republish_date = get_post_meta( $post_id, '_yt_pps_republish_date', true );

		if ( $unpublish_date ) {
			echo '<div class="yt-pps-column-schedule">';
			echo '<span class="dashicons dashicons-arrow-down-alt"></span> ';
			echo esc_html( $this->format_date( $unpublish_date ) );
			echo '</div>';
		}

		if ( $republish_date ) {
			echo '<div class="yt-pps-column-schedule">';
			echo '<span class="dashicons dashicons-arrow-up-alt"></span> ';
			echo esc_html( $this->format_date( $republish_date ) );
			echo '</div>';
		}

		if ( ! $unpublish_date && ! $republish_date ) {
			echo '-';
		}
	}

	/**
	 * Show admin notices.
	 */
	public function show_admin_notices() {
		$screen = get_current_screen();

		if ( 'post' !== $screen->base ) {
			return;
		}

		global $post;

		if ( ! $post ) {
			return;
		}

		$unpublish_date = get_post_meta( $post->ID, '_yt_pps_unpublish_date', true );
		$republish_date = get_post_meta( $post->ID, '_yt_pps_republish_date', true );

		if ( $unpublish_date && strtotime( $unpublish_date ) < time() ) {
			?>
			<div class="notice notice-warning">
				<p><?php esc_html_e( 'The unpublish date for this post has passed. The post may have been unpublished automatically.', 'yt-post-publish-scheduler' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * AJAX clear schedule.
	 */
	public function ajax_clear_schedule() {
		check_ajax_referer( 'yt_pps_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'yt-post-publish-scheduler' ) ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'yt-post-publish-scheduler' ) ) );
		}

		// Clear schedules.
		wp_clear_scheduled_hook( 'yt_pps_unpublish_post', array( $post_id ) );
		wp_clear_scheduled_hook( 'yt_pps_republish_post', array( $post_id ) );

		// Clear meta.
		delete_post_meta( $post_id, '_yt_pps_unpublish_date' );
		delete_post_meta( $post_id, '_yt_pps_republish_date' );

		wp_send_json_success( array( 'message' => __( 'Schedule cleared successfully.', 'yt-post-publish-scheduler' ) ) );
	}

	/**
	 * AJAX get scheduled posts.
	 */
	public function ajax_get_scheduled_posts() {
		check_ajax_referer( 'yt_pps_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
		}

		$posts = $this->get_scheduled_posts();

		wp_send_json_success( array( 'posts' => $posts ) );
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Existing links.
	 * @return array Modified links.
	 */
	public function add_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'options-general.php?page=yt-post-publish-scheduler' ),
			__( 'Settings', 'yt-post-publish-scheduler' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}
}

/**
 * Initialize the plugin.
 */
function yt_pps_init() {
	return YT_Post_Publish_Scheduler::get_instance();
}

// Bootstrap the plugin.
yt_pps_init();
