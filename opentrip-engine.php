<?php
/**
 * Plugin Name: OpenTrip Engine
 * Description: A lightweight, open-source tour catalogue and booking-request engine for WordPress.
 * Version: 1.0.0-beta.7
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: opentrip-engine
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OTE_VERSION', '1.0.0-beta.7' );
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ote-departures.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ote-submission-limiter.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ote-rest-api.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ote-pricing.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/interface-ote-payment-gateway.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ote-payment-gateway-registry.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ote-stripe-gateway.php';
OTE_Payment_Gateway_Registry::register( new OTE_Stripe_Gateway() );

final class OpenTrip_Engine {
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	private function posted_string( $key ) {
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}
		return (string) wp_unslash( $_POST[ $key ] );
	}

	private function posted_uint( $key ) {
		$value = $this->posted_string( $key );
		if ( ! preg_match( '/^[0-9]{1,2}$/', $value ) ) {
			return -1;
		}
		return (int) $value;
	}
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_action( 'rest_api_init', array( 'OTE_REST_API', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'OTE_Stripe_Gateway', 'register_webhook_route' ) );
		add_action( 'admin_post_ote_create_stripe_payment_link', array( $this, 'handle_create_stripe_payment_link' ) );
		add_action( 'init', array( $this, 'register_types' ) );
		add_action( 'admin_init', array( $this, 'ensure_payment_secrets_are_not_autoloaded' ), 1 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_menu' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_tour_meta_box' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_itinerary_meta_box' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_inclusions_meta_box' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_gallery_meta_box' ) );
		add_action( 'save_post_ote_tour', array( $this, 'save_gallery' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_gallery_admin' ) );
		add_shortcode( 'opentrip_gallery', array( $this, 'gallery_shortcode' ) );
		add_action( 'save_post_ote_tour', array( $this, 'save_inclusions' ) );
		add_shortcode( 'opentrip_inclusions', array( $this, 'inclusions_shortcode' ) );
		add_action( 'save_post_ote_tour', array( $this, 'save_itinerary' ) );
		add_shortcode( 'opentrip_itinerary', array( $this, 'itinerary_shortcode' ) );
		add_action( 'save_post_ote_tour', array( $this, 'save_tour_meta' ) );
		add_shortcode( 'opentrip_tours', array( $this, 'tours_shortcode' ) );
		add_shortcode( 'opentrip_booking_form', array( $this, 'booking_shortcode' ) );
		add_action( 'init', array( $this, 'handle_booking' ) );
		add_shortcode( 'opentrip_reviews', array( $this, 'reviews_shortcode' ) );
		add_shortcode( 'opentrip_review_form', array( $this, 'review_form_shortcode' ) );
		add_action( 'init', array( $this, 'handle_review_submission' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_booking_meta_box' ) );
		add_action( 'save_post_ote_booking', array( $this, 'save_booking_status' ) );
		add_filter( 'manage_ote_booking_posts_columns', array( $this, 'booking_columns' ) );
		add_action( 'manage_ote_booking_posts_custom_column', array( $this, 'booking_column_content' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'inventory_admin_notice' ) );
		add_action( 'init', array( OTE_Departures::instance(), 'maybe_install' ) );
		add_action( 'init', array( 'OTE_Submission_Limiter', 'maybe_install' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_privacy_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	public static function activate() {
		self::instance()->register_types();
		OTE_Departures::install();
		OTE_Submission_Limiter::install();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}


	public function ensure_payment_secrets_are_not_autoloaded() {
		foreach ( array( 'ote_stripe_secret_key', 'ote_stripe_webhook_secret' ) as $option_name ) {
			$value = get_option( $option_name, false );
			if ( false === $value ) {
				add_option( $option_name, '', '', 'no' );
			} else {
				update_option( $option_name, $value, false );
			}
		}
	}

	public function register_settings() {
		register_setting(
			'ote_settings',
			'ote_notification_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_notification_email' ),
				'default'           => get_option( 'admin_email' ),
			)
		);
		register_setting(
			'ote_settings',
			'ote_admin_notifications_enabled',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_boolean_setting' ),
				'default'           => '1',
			)
		);
		register_setting(
			'ote_settings',
			'ote_customer_notifications_enabled',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_boolean_setting' ),
				'default'           => '1',
			)
		);

		add_settings_section(
			'ote_email_notifications',
			__( 'Email notifications', 'opentrip-engine' ),
			function() {
				echo '<p>' . esc_html__( 'Choose where booking alerts are sent and whether customers receive booking receipts and status updates.', 'opentrip-engine' ) . '</p>';
			},
			'ote-settings'
		);
		add_settings_field(
			'ote_notification_email',
			__( 'Booking notification recipient', 'opentrip-engine' ),
			array( $this, 'render_notification_email_field' ),
			'ote-settings',
			'ote_email_notifications'
		);
		add_settings_field(
			'ote_admin_notifications_enabled',
			__( 'Agency notifications', 'opentrip-engine' ),
			array( $this, 'render_admin_notifications_field' ),
			'ote-settings',
			'ote_email_notifications'
		);
		add_settings_field(
			'ote_customer_notifications_enabled',
			__( 'Customer notifications', 'opentrip-engine' ),
			array( $this, 'render_customer_notifications_field' ),
			'ote-settings',
			'ote_email_notifications'
		);

		register_setting( 'ote_settings', 'ote_stripe_enabled', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_boolean_setting' ), 'default' => '0' ) );
		register_setting( 'ote_settings', 'ote_stripe_secret_key', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_stripe_secret_key' ), 'default' => '' ) );
		register_setting( 'ote_settings', 'ote_stripe_webhook_secret', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_stripe_webhook_secret' ), 'default' => '' ) );
		register_setting( 'ote_settings', 'ote_stripe_deposit_percent', array( 'type' => 'integer', 'sanitize_callback' => array( $this, 'sanitize_stripe_deposit_percent' ), 'default' => 100 ) );
		add_settings_section(
			'ote_stripe_payments',
			__( 'Hosted payments (optional)', 'opentrip-engine' ),
			function() {
				echo '<p>' . esc_html__( 'Stripe Checkout is optional. Customers enter card details on Stripe; this site never receives or stores card numbers or security codes. The key prefix selects test or live mode.', 'opentrip-engine' ) . '</p>';
			},
			'ote-settings'
		);
		add_settings_field( 'ote_stripe_enabled', __( 'Enable Stripe Checkout', 'opentrip-engine' ), array( $this, 'render_stripe_enabled_field' ), 'ote-settings', 'ote_stripe_payments' );
		add_settings_field( 'ote_stripe_secret_key', __( 'Stripe secret key', 'opentrip-engine' ), array( $this, 'render_stripe_secret_key_field' ), 'ote-settings', 'ote_stripe_payments' );
		add_settings_field( 'ote_stripe_webhook_secret', __( 'Stripe webhook signing secret', 'opentrip-engine' ), array( $this, 'render_stripe_webhook_secret_field' ), 'ote-settings', 'ote_stripe_payments' );
		add_settings_field( 'ote_stripe_deposit_percent', __( 'Payment percentage', 'opentrip-engine' ), array( $this, 'render_stripe_deposit_percent_field' ), 'ote-settings', 'ote_stripe_payments' );
	}

	public function sanitize_stripe_secret_key( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return (string) get_option( 'ote_stripe_secret_key', '' );
		}
		if ( ! preg_match( '/^sk_(test|live)_[A-Za-z0-9]+$/', $value ) ) {
			add_settings_error( 'ote_stripe_secret_key', 'ote_invalid_stripe_secret', __( 'Enter a valid Stripe test or live secret key. The previous key has been kept.', 'opentrip-engine' ) );
			return (string) get_option( 'ote_stripe_secret_key', '' );
		}
		return $value;
	}

	public function sanitize_stripe_webhook_secret( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return (string) get_option( 'ote_stripe_webhook_secret', '' );
		}
		if ( ! preg_match( '/^whsec_[A-Za-z0-9]+$/', $value ) ) {
			add_settings_error( 'ote_stripe_webhook_secret', 'ote_invalid_stripe_webhook_secret', __( 'Enter a valid Stripe webhook signing secret. The previous secret has been kept.', 'opentrip-engine' ) );
			return (string) get_option( 'ote_stripe_webhook_secret', '' );
		}
		return $value;
	}

	public function sanitize_stripe_deposit_percent( $value ) {
		$percent = is_scalar( $value ) && preg_match( '/^[0-9]{1,3}$/', (string) $value ) ? (int) $value : 0;
		if ( $percent < 1 || $percent > 100 ) {
			add_settings_error( 'ote_stripe_deposit_percent', 'ote_invalid_stripe_deposit_percent', __( 'Payment percentage must be between 1 and 100. The previous value has been kept.', 'opentrip-engine' ) );
			return absint( get_option( 'ote_stripe_deposit_percent', 100 ) );
		}
		return $percent;
	}

	public function render_stripe_enabled_field() {
		$enabled = (string) get_option( 'ote_stripe_enabled', '0' );
		echo '<input type="hidden" name="ote_stripe_enabled" value="0">';
		echo '<label><input type="checkbox" name="ote_stripe_enabled" value="1" ' . checked( $enabled, '1', false ) . '> ' . esc_html__( 'Allow the agency to create hosted payment links for confirmed bookings.', 'opentrip-engine' ) . '</label>';
	}

	public function render_stripe_secret_key_field() {
		$key = (string) get_option( 'ote_stripe_secret_key', '' );
		$mode = 0 === strpos( $key, 'sk_live_' ) ? __( 'Live mode configured', 'opentrip-engine' ) : ( 0 === strpos( $key, 'sk_test_' ) ? __( 'Test mode configured', 'opentrip-engine' ) : __( 'Not configured', 'opentrip-engine' ) );
		echo '<input type="password" class="regular-text" name="ote_stripe_secret_key" value="" autocomplete="new-password" spellcheck="false" placeholder="' . esc_attr__( 'Paste a Stripe secret key', 'opentrip-engine' ) . '">';
		echo '<p class="description">' . esc_html( $mode ) . ' — ' . esc_html__( 'Leave blank to keep the saved key. Use sk_test_ for test mode or sk_live_ for live mode. The key is never displayed after saving.', 'opentrip-engine' ) . '</p>';
	}

	public function render_stripe_webhook_secret_field() {
		$configured = 0 === strpos( (string) get_option( 'ote_stripe_webhook_secret', '' ), 'whsec_' );
		echo '<input type="password" class="regular-text" name="ote_stripe_webhook_secret" value="" autocomplete="new-password" spellcheck="false" placeholder="' . esc_attr__( 'Paste the Stripe webhook signing secret', 'opentrip-engine' ) . '">';
		echo '<p class="description">' . esc_html( $configured ? __( 'Webhook secret configured.', 'opentrip-engine' ) : __( 'Webhook secret not configured.', 'opentrip-engine' ) ) . ' ' . esc_html__( 'Leave blank to keep the saved secret.', 'opentrip-engine' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Webhook URL:', 'opentrip-engine' ) . ' <code>' . esc_html( rest_url( 'opentrip-engine/v1/payments/stripe/webhook' ) ) . '</code></p>';
		echo '<p class="description">' . esc_html__( 'Subscribe to checkout.session.completed, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed and checkout.session.expired.', 'opentrip-engine' ) . '</p>';
	}

	public function render_stripe_deposit_percent_field() {
		$value = absint( get_option( 'ote_stripe_deposit_percent', 100 ) );
		echo '<input type="number" min="1" max="100" step="1" name="ote_stripe_deposit_percent" value="' . esc_attr( $value ) . '"> %';
		echo '<p class="description">' . esc_html__( 'Percentage of the saved booking quote requested through Stripe. Set 100 for full payment or a lower value for a deposit.', 'opentrip-engine' ) . '</p>';
	}

	public function sanitize_notification_email( $value ) {
		$email = is_scalar( $value ) ? sanitize_email( (string) $value ) : '';
		if ( is_email( $email ) ) {
			return $email;
		}
		add_settings_error(
			'ote_notification_email',
			'ote_invalid_notification_email',
			__( 'Enter a valid notification email address. The previous address has been kept.', 'opentrip-engine' )
		);
		return get_option( 'ote_notification_email', get_option( 'admin_email' ) );
	}

	public function sanitize_boolean_setting( $value ) {
		return is_scalar( $value ) && '1' === (string) $value ? '1' : '0';
	}

	public function render_notification_email_field() {
		$value = get_option( 'ote_notification_email', get_option( 'admin_email' ) );
		echo '<input type="email" class="regular-text" name="ote_notification_email" value="' . esc_attr( $value ) . '" required>';
	}

	public function render_admin_notifications_field() {
		$enabled = (string) get_option( 'ote_admin_notifications_enabled', '1' );
		echo '<input type="hidden" name="ote_admin_notifications_enabled" value="0">';
		echo '<label><input type="checkbox" name="ote_admin_notifications_enabled" value="1" ' . checked( $enabled, '1', false ) . '> ' . esc_html__( 'Email the agency when a new booking request is received.', 'opentrip-engine' ) . '</label>';
	}

	public function render_customer_notifications_field() {
		$enabled = (string) get_option( 'ote_customer_notifications_enabled', '1' );
		echo '<input type="hidden" name="ote_customer_notifications_enabled" value="0">';
		echo '<label><input type="checkbox" name="ote_customer_notifications_enabled" value="1" ' . checked( $enabled, '1', false ) . '> ' . esc_html__( 'Email customers when requests are received, confirmed or cancelled.', 'opentrip-engine' ) . '</label>';
	}

	public function add_settings_menu() {
		add_submenu_page(
			'edit.php?post_type=ote_tour',
			__( 'OpenTrip Settings', 'opentrip-engine' ),
			__( 'Settings', 'opentrip-engine' ),
			'manage_options',
			'ote-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OpenTrip Engine Settings', 'opentrip-engine' ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'ote_settings' );
				do_settings_sections( 'ote-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'opentrip-engine', plugin_dir_url( __FILE__ ) . 'assets/opentrip-engine.css', array(), OTE_VERSION );
	}


	public function load_textdomain() {
		load_plugin_textdomain(
			'opentrip-engine',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	public function register_types() {
		register_post_type( 'ote_tour', array(
			'labels' => array(
				'name' => __( 'Tours', 'opentrip-engine' ),
				'singular_name' => __( 'Tour', 'opentrip-engine' ),
				'add_new_item' => __( 'Add New Tour', 'opentrip-engine' ),
			),
			'public' => true,
			'has_archive' => true,
			'menu_icon' => 'dashicons-location-alt',
			'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'rewrite' => array( 'slug' => 'tours' ),
			'show_in_rest' => true,
		) );

		register_taxonomy( 'ote_destination', 'ote_tour', array(
			'labels' => array(
				'name' => __( 'Destinations', 'opentrip-engine' ),
				'singular_name' => __( 'Destination', 'opentrip-engine' ),
			),
			'public' => true,
			'hierarchical' => true,
			'show_in_rest' => true,
		) );

		register_post_type( 'ote_booking', array(
			'labels' => array(
				'name' => __( 'Booking Requests', 'opentrip-engine' ),
				'singular_name' => __( 'Booking Request', 'opentrip-engine' ),
			),
			'public' => false,
			'show_ui' => true,
			'show_in_menu' => 'edit.php?post_type=ote_tour',
			'supports' => array( 'title' ),
			'capability_type' => 'post',
			'capabilities' => array(
				'edit_posts'            => 'manage_options',
				'edit_others_posts'     => 'manage_options',
				'publish_posts'         => 'manage_options',
				'read_private_posts'    => 'manage_options',
				'delete_posts'          => 'do_not_allow',
				'delete_private_posts'  => 'do_not_allow',
				'delete_published_posts'=> 'do_not_allow',
				'delete_others_posts'   => 'do_not_allow',
				'edit_private_posts'    => 'manage_options',
				'edit_published_posts'  => 'manage_options',
				'create_posts'          => 'manage_options',
			),
			'map_meta_cap' => true,
		) );

		register_post_type( 'ote_review', array(
			'labels' => array(
				'name' => __( 'Tour Reviews', 'opentrip-engine' ),
				'singular_name' => __( 'Tour Review', 'opentrip-engine' ),
				'edit_item' => __( 'Moderate Tour Review', 'opentrip-engine' ),
			),
			'public' => false,
			'show_ui' => true,
			'show_in_menu' => 'edit.php?post_type=ote_tour',
			'supports' => array( 'title', 'editor' ),
			'capability_type' => 'post',
			'capabilities' => array(
				'edit_posts'            => 'manage_options',
				'edit_others_posts'     => 'manage_options',
				'publish_posts'         => 'manage_options',
				'read_private_posts'    => 'manage_options',
				'delete_posts'          => 'manage_options',
				'delete_private_posts'  => 'manage_options',
				'delete_published_posts'=> 'manage_options',
				'delete_others_posts'   => 'manage_options',
				'edit_private_posts'    => 'manage_options',
				'edit_published_posts'  => 'manage_options',
				'create_posts'          => 'manage_options',
			),
			'map_meta_cap' => true,
		) );
	}

	public function add_tour_meta_box() {
		add_meta_box( 'ote_tour_details', __( 'Tour Details', 'opentrip-engine' ), array( $this, 'render_tour_meta_box' ), 'ote_tour', 'normal', 'high' );
	}

	public function render_tour_meta_box( $post ) {
		wp_nonce_field( 'ote_save_tour', 'ote_tour_nonce' );
		$fields = array(
			'price' => get_post_meta( $post->ID, '_ote_price', true ),
			'currency' => get_post_meta( $post->ID, '_ote_currency', true ),
			'duration' => get_post_meta( $post->ID, '_ote_duration', true ),
			'capacity' => get_post_meta( $post->ID, '_ote_capacity', true ),
		);
		?>
		<p><label for="ote_price"><?php esc_html_e( 'Price per person', 'opentrip-engine' ); ?></label><br>
		<input id="ote_price" name="ote_price" type="number" min="0" step="0.01" value="<?php echo esc_attr( $fields['price'] ); ?>"></p>
		<p><label for="ote_currency"><?php esc_html_e( 'Currency (ISO code)', 'opentrip-engine' ); ?></label><br>
		<input id="ote_currency" name="ote_currency" type="text" maxlength="3" value="<?php echo esc_attr( $fields['currency'] ? $fields['currency'] : 'USD' ); ?>"></p>
		<p><label for="ote_duration"><?php esc_html_e( 'Duration (e.g. 8 days)', 'opentrip-engine' ); ?></label><br>
		<input id="ote_duration" name="ote_duration" type="text" value="<?php echo esc_attr( $fields['duration'] ); ?>"></p>
		<p><label for="ote_capacity"><?php esc_html_e( 'Capacity (informational only; 0 = unspecified)', 'opentrip-engine' ); ?></label><br>
		<input id="ote_capacity" name="ote_capacity" type="number" min="0" step="1" value="<?php echo esc_attr( $fields['capacity'] ); ?>"></p>
		<?php
	}

	public function add_itinerary_meta_box() {
		add_meta_box( 'ote_itinerary', __( 'Day-by-Day Itinerary', 'opentrip-engine' ), array( $this, 'render_itinerary_meta_box' ), 'ote_tour', 'normal', 'default' );
	}

	public function render_itinerary_meta_box( $post ) {
		wp_nonce_field( 'ote_save_itinerary', 'ote_itinerary_nonce' );
		$itinerary = get_post_meta( $post->ID, '_ote_itinerary', true );
		if ( ! is_array( $itinerary ) ) {
			$itinerary = array();
		}
		$example = array(
			array(
				'day' => 1,
				'title' => 'Arrival and city tour',
				'city' => 'Marrakech',
				'activities' => 'Airport pickup; guided medina walk',
				'overnight' => 'Hotel in Marrakech',
				'meals' => 'Dinner',
				'transport' => 'Private vehicle',
			),
		);
		$value = $itinerary ? $itinerary : $example;
		?>
		<p><?php esc_html_e( 'Enter the itinerary as a JSON array. Each day supports: day, title, city, activities, overnight, meals and transport.', 'opentrip-engine' ); ?></p>
		<textarea name="ote_itinerary_json" rows="14" class="large-text code"><?php echo esc_textarea( wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Leave the example in place or replace it with your tour schedule. Use a valid JSON array.', 'opentrip-engine' ); ?></p>
		<?php
	}

	public function save_itinerary( $post_id ) {
		if ( ! is_string( isset( $_POST['ote_itinerary_nonce'] ) ? $_POST['ote_itinerary_nonce'] : null ) || ! wp_verify_nonce( sanitize_text_field( $this->posted_string( 'ote_itinerary_nonce' ) ), 'ote_save_itinerary' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$json = $this->posted_string( 'ote_itinerary_json' );
		$data = json_decode( $json, true );
		if ( '' === trim( $json ) ) {
			delete_post_meta( $post_id, '_ote_itinerary' );
			return;
		}
		if ( ! is_array( $data ) || isset( $data['day'] ) ) {
			return;
		}
		$clean = array();
		$fields = array( 'title', 'city', 'activities', 'overnight', 'meals', 'transport' );
		foreach ( array_slice( $data, 0, 60 ) as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$day = isset( $item['day'] ) ? absint( $item['day'] ) : $index + 1;
			if ( ! $day ) {
				$day = $index + 1;
			}
			$row = array( 'day' => $day );
			foreach ( $fields as $field ) {
				$row[ $field ] = isset( $item[ $field ] ) && is_scalar( $item[ $field ] ) ? sanitize_textarea_field( (string) $item[ $field ] ) : '';
			}
			if ( $row['title'] || $row['activities'] || $row['city'] ) {
				$clean[] = $row;
			}
		}
		update_post_meta( $post_id, '_ote_itinerary', $clean );
	}

	public function itinerary_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'tour_id' => 0 ), $atts, 'opentrip_itinerary' );
		$tour_id = absint( $atts['tour_id'] );
		if ( ! $tour_id && is_singular( 'ote_tour' ) ) {
			$tour_id = get_the_ID();
		}
		if ( ! $tour_id || 'ote_tour' !== get_post_type( $tour_id ) ) {
			return '';
		}
		$itinerary = get_post_meta( $tour_id, '_ote_itinerary', true );
		if ( ! is_array( $itinerary ) || empty( $itinerary ) ) {
			return '';
		}
		ob_start();
		echo '<section class="ote-itinerary"><h2>' . esc_html__( 'Day-by-Day Itinerary', 'opentrip-engine' ) . '</h2>';
		foreach ( $itinerary as $item ) {
			$day = isset( $item['day'] ) ? absint( $item['day'] ) : 0;
			$title = isset( $item['title'] ) ? $item['title'] : '';
			echo '<article class="ote-itinerary-day"><h3>' . esc_html( sprintf( __( 'Day %d: %s', 'opentrip-engine' ), $day, $title ) ) . '</h3>';
			foreach ( array( 'city' => __( 'Location', 'opentrip-engine' ), 'activities' => __( 'Activities', 'opentrip-engine' ), 'overnight' => __( 'Overnight', 'opentrip-engine' ), 'meals' => __( 'Meals', 'opentrip-engine' ), 'transport' => __( 'Transportation', 'opentrip-engine' ) ) as $key => $label ) {
				if ( ! empty( $item[ $key ] ) ) {
					echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . nl2br( esc_html( $item[ $key ] ) ) . '</p>';
				}
			}
			echo '</article>';
		}
		echo '</section>';
		return ob_get_clean();
	}

	public function add_inclusions_meta_box() {
		add_meta_box( 'ote_inclusions', __( 'Tour Inclusions & Exclusions', 'opentrip-engine' ), array( $this, 'render_inclusions_meta_box' ), 'ote_tour', 'normal', 'default' );
	}

	public function render_inclusions_meta_box( $post ) {
		wp_nonce_field( 'ote_save_inclusions', 'ote_inclusions_nonce' );
		$included = get_post_meta( $post->ID, '_ote_inclusions', true );
		$excluded = get_post_meta( $post->ID, '_ote_exclusions', true );
		$included_text = is_array( $included ) ? implode( "\n", $included ) : '';
		$excluded_text = is_array( $excluded ) ? implode( "\n", $excluded ) : '';
		?>
		<div class="ote-inclusions-editor">
			<p><?php esc_html_e( 'Add one item per line. Leave a box empty if it does not apply.', 'opentrip-engine' ); ?></p>
			<p><label for="ote_inclusions"><strong><?php esc_html_e( 'What is included in the price', 'opentrip-engine' ); ?></strong></label><br>
			<textarea id="ote_inclusions" name="ote_inclusions" rows="8" class="large-text" placeholder="Accommodation\nDaily breakfast\nAirport transfers"><?php echo esc_textarea( $included_text ); ?></textarea></p>
			<p><label for="ote_exclusions"><strong><?php esc_html_e( 'What is not included in the price', 'opentrip-engine' ); ?></strong></label><br>
			<textarea id="ote_exclusions" name="ote_exclusions" rows="8" class="large-text" placeholder="International flights\nTravel insurance\nPersonal expenses"><?php echo esc_textarea( $excluded_text ); ?></textarea></p>
		</div>
		<?php
	}

	public function save_inclusions( $post_id ) {
		if ( ! is_string( isset( $_POST['ote_inclusions_nonce'] ) ? $_POST['ote_inclusions_nonce'] : null ) || ! wp_verify_nonce( sanitize_text_field( $this->posted_string( 'ote_inclusions_nonce' ) ), 'ote_save_inclusions' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array( 'inclusions', 'exclusions' ) as $type ) {
			$field = 'ote_' . $type;
			$meta_key = '_ote_' . $type;
			$raw = sanitize_textarea_field( $this->posted_string( $field ) );
			$items = preg_split( '/\\r\\n|\\r|\\n/', $raw );
			$clean = array();
			foreach ( $items as $item ) {
				$item = trim( sanitize_text_field( $item ) );
				if ( '' !== $item && ! in_array( $item, $clean, true ) && count( $clean ) < 100 ) {
					$clean[] = $item;
				}
			}
			if ( $clean ) {
				update_post_meta( $post_id, $meta_key, $clean );
			} else {
				delete_post_meta( $post_id, $meta_key );
			}
		}
	}

	public function inclusions_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'tour_id' => 0 ), $atts, 'opentrip_inclusions' );
		$tour_id = absint( $atts['tour_id'] );
		if ( ! $tour_id && is_singular( 'ote_tour' ) ) {
			$tour_id = get_the_ID();
		}
		if ( ! $tour_id || 'ote_tour' !== get_post_type( $tour_id ) ) {
			return '';
		}
		$included = get_post_meta( $tour_id, '_ote_inclusions', true );
		$excluded = get_post_meta( $tour_id, '_ote_exclusions', true );
		$included = is_array( $included ) ? $included : array();
		$excluded = is_array( $excluded ) ? $excluded : array();
		if ( empty( $included ) && empty( $excluded ) ) {
			return '';
		}
		ob_start();
		?>
		<section class="ote-inclusions">
			<h2><?php esc_html_e( 'Tour inclusions and exclusions', 'opentrip-engine' ); ?></h2>
			<?php if ( $included ) : ?>
			<div class="ote-inclusions-column ote-included">
				<h3><?php esc_html_e( 'What is included', 'opentrip-engine' ); ?></h3>
				<ul><?php foreach ( $included as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul>
			</div>
			<?php endif; ?>
			<?php if ( $excluded ) : ?>
			<div class="ote-inclusions-column ote-excluded">
				<h3><?php esc_html_e( 'What is not included', 'opentrip-engine' ); ?></h3>
				<ul><?php foreach ( $excluded as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul>
			</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	public function add_gallery_meta_box() {
		add_meta_box( 'ote_gallery', __( 'Tour Photo Gallery', 'opentrip-engine' ), array( $this, 'render_gallery_meta_box' ), 'ote_tour', 'normal', 'default' );
	}

	public function enqueue_gallery_admin( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'ote_tour' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_media();
		wp_localize_script( 'jquery', 'oteGalleryStrings', array(
			'title' => __( 'Select tour photos', 'opentrip-engine' ),
			'use'   => __( 'Use selected photos', 'opentrip-engine' ),
		) );
		wp_add_inline_script( 'jquery', "
		jQuery(function($){
			var frame;
			$('#ote_gallery_select').on('click', function(e){
				e.preventDefault();
				if(frame){frame.open();return;}
				frame=wp.media({title:oteGalleryStrings.title,button:{text:oteGalleryStrings.use},multiple:true,library:{type:'image'}});
				frame.on('open',function(){
					var ids=$('#ote_gallery_ids').val().split(',').filter(Boolean);
					var selection=frame.state().get('selection'); selection.reset();
					ids.forEach(function(id){var attachment=wp.media.attachment(id);attachment.fetch();selection.add(attachment);});
				});
				frame.on('select',function(){
					var selection=frame.state().get('selection'), ids=[];
					var $preview=$('#ote_gallery_preview').empty();
					selection.each(function(attachment){
						var a=attachment.toJSON(), src=a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url;
						ids.push(a.id);
						var $thumb=$('<span>').addClass('ote-gallery-admin-thumb');
						var $image=$('<img>').attr('src',src).attr('alt','');
						$thumb.append($image);
						$preview.append($thumb);
					});
					$('#ote_gallery_ids').val(ids.join(','));
				});
				frame.open();
			});
			$('#ote_gallery_clear').on('click',function(e){e.preventDefault();$('#ote_gallery_ids').val('');$('#ote_gallery_preview').empty();});
		});
		" );
	}

	public function render_gallery_meta_box( $post ) {
		wp_nonce_field( 'ote_save_gallery', 'ote_gallery_nonce' );
		$ids = get_post_meta( $post->ID, '_ote_gallery_ids', true );
		$ids = is_array( $ids ) ? $ids : array();
		?>
		<p><?php esc_html_e( 'Choose multiple images from the Media Library. Dragging to reorder is not supported yet; selected order is retained.', 'opentrip-engine' ); ?></p>
		<input type="hidden" id="ote_gallery_ids" name="ote_gallery_ids" value="<?php echo esc_attr( implode( ',', array_map( 'absint', $ids ) ) ); ?>">
		<p><button type="button" class="button button-primary" id="ote_gallery_select"><?php esc_html_e( 'Select tour photos', 'opentrip-engine' ); ?></button>
		<button type="button" class="button" id="ote_gallery_clear"><?php esc_html_e( 'Clear gallery', 'opentrip-engine' ); ?></button></p>
		<div id="ote_gallery_preview" class="ote-gallery-admin-preview">
			<?php foreach ( $ids as $id ) { echo '<span class="ote-gallery-admin-thumb">' . wp_get_attachment_image( absint( $id ), 'thumbnail' ) . '</span>'; } ?>
		</div>
		<?php
	}

	public function save_gallery( $post_id ) {
		if ( ! is_string( isset( $_POST['ote_gallery_nonce'] ) ? $_POST['ote_gallery_nonce'] : null ) || ! wp_verify_nonce( sanitize_text_field( $this->posted_string( 'ote_gallery_nonce' ) ), 'ote_save_gallery' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw = sanitize_text_field( $this->posted_string( 'ote_gallery_ids' ) );
		$ids = array_filter( array_unique( array_map( 'absint', explode( ',', $raw ) ) ) );
		$clean = array();
		foreach ( array_slice( $ids, 0, 40 ) as $id ) {
			if ( wp_attachment_is_image( $id ) ) {
				$clean[] = $id;
			}
		}
		if ( $clean ) {
			update_post_meta( $post_id, '_ote_gallery_ids', $clean );
		} else {
			delete_post_meta( $post_id, '_ote_gallery_ids' );
		}
	}

	public function gallery_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'tour_id' => 0, 'columns' => 3, 'size' => 'large' ), $atts, 'opentrip_gallery' );
		$tour_id = absint( $atts['tour_id'] );
		if ( ! $tour_id && is_singular( 'ote_tour' ) ) {
			$tour_id = get_the_ID();
		}
		if ( ! $tour_id || 'ote_tour' !== get_post_type( $tour_id ) ) {
			return '';
		}
		$ids = get_post_meta( $tour_id, '_ote_gallery_ids', true );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return '';
		}
		$columns = min( 6, max( 1, absint( $atts['columns'] ) ) );
		$size = in_array( $atts['size'], array( 'thumbnail', 'medium', 'large', 'full' ), true ) ? $atts['size'] : 'large';
		ob_start();
		?>
		<div class="ote-gallery" style="--ote-gallery-columns:<?php echo esc_attr( $columns ); ?>">
			<?php foreach ( $ids as $index => $id ) : $attachment_id = absint( $id ); $alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ); if ( '' === $alt ) { $alt = sprintf( __( 'Tour photo %1$d for %2$s', 'opentrip-engine' ), $index + 1, get_the_title( $tour_id ) ); } ?>
			<figure class="ote-gallery-item"><?php echo wp_get_attachment_link( $attachment_id, $size, false, false, false, array( 'class' => 'ote-gallery-image', 'alt' => $alt ) ); ?></figure>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public function save_tour_meta( $post_id ) {
		if ( ! is_string( isset( $_POST['ote_tour_nonce'] ) ? $_POST['ote_tour_nonce'] : null ) || ! wp_verify_nonce( sanitize_text_field( $this->posted_string( 'ote_tour_nonce' ) ), 'ote_save_tour' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$price = (float) $this->posted_string( 'ote_price' );
		if ( ! is_finite( $price ) || $price > 9999999999.99 ) {
			$price = 0;
		}
		$currency = strtoupper( sanitize_text_field( $this->posted_string( 'ote_currency' ) ) );
		if ( '' === $currency ) {
			$currency = 'USD';
		}
		$duration = substr( sanitize_text_field( $this->posted_string( 'ote_duration' ) ), 0, 80 );
		$capacity = absint( $this->posted_string( 'ote_capacity' ) );

		update_post_meta( $post_id, '_ote_price', max( 0, $price ) );
		update_post_meta( $post_id, '_ote_currency', preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'USD' );
		update_post_meta( $post_id, '_ote_duration', $duration );
		update_post_meta( $post_id, '_ote_capacity', $capacity );
	}

	public function tours_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'destination' => '', 'limit' => 12 ), $atts, 'opentrip_tours' );
		$args = array(
			'post_type' => 'ote_tour',
			'post_status' => 'publish',
			'posts_per_page' => min( 50, max( 1, absint( $atts['limit'] ) ) ),
		);
		if ( $atts['destination'] ) {
			$args['tax_query'] = array( array(
				'taxonomy' => 'ote_destination',
				'field' => 'slug',
				'terms' => sanitize_title( $atts['destination'] ),
			) );
		}
		$query = new WP_Query( $args );
		if ( ! $query->have_posts() ) {
			return '<p>' . esc_html__( 'No tours found.', 'opentrip-engine' ) . '</p>';
		}
		ob_start();
		echo '<div class="ote-tour-grid">';
		while ( $query->have_posts() ) {
			$query->the_post();
			$id = get_the_ID();
			$price = get_post_meta( $id, '_ote_price', true );
			$currency = get_post_meta( $id, '_ote_currency', true );
			$duration = get_post_meta( $id, '_ote_duration', true );
			echo '<article class="ote-tour-card">';
			if ( has_post_thumbnail() ) {
				echo '<a href="' . esc_url( get_permalink() ) . '">' . get_the_post_thumbnail( $id, 'large', array( 'alt' => ( get_post_meta( get_post_thumbnail_id( $id ), '_wp_attachment_image_alt', true ) ? get_post_meta( get_post_thumbnail_id( $id ), '_wp_attachment_image_alt', true ) : get_the_title( $id ) ) ) ) . '</a>';
			}
			echo '<h3><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></h3>';
			if ( $duration ) {
				echo '<p>' . esc_html( $duration ) . '</p>';
			}
			if ( '' !== $price ) {
				echo '<p>' . esc_html( number_format_i18n( (float) $price, 2 ) . ' ' . ( $currency ? $currency : 'USD' ) ) . '</p>';
			}
			echo '</article>';
		}
		echo '</div>';
		wp_reset_postdata();
		return ob_get_clean();
	}

	public function booking_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'tour_id' => 0 ), $atts, 'opentrip_booking_form' );
		$tour_id = absint( $atts['tour_id'] );
		if ( ! $tour_id && is_singular( 'ote_tour' ) ) {
			$tour_id = get_the_ID();
		}
		if ( ! $tour_id || 'ote_tour' !== get_post_type( $tour_id ) || 'publish' !== get_post_status( $tour_id ) ) {
			return '<p>' . esc_html__( 'Please select a published tour before booking.', 'opentrip-engine' ) . '</p>';
		}
		ob_start();
		if ( isset( $_GET['ote_payment'] ) && is_string( $_GET['ote_payment'] ) ) {
			$payment_return = sanitize_key( wp_unslash( $_GET['ote_payment'] ) );
			if ( 'returned' === $payment_return ) {
				echo '<p class="ote-success" role="status">' . esc_html__( 'You returned from Stripe Checkout. Payment is verified by a secure server notification; the agency will update your booking record separately.', 'opentrip-engine' ) . '</p>';
			} elseif ( 'cancelled' === $payment_return ) {
				echo '<p class="ote-info" role="status">' . esc_html__( 'Checkout was cancelled. Your booking status has not changed. Contact the agency if you need a new payment link.', 'opentrip-engine' ) . '</p>';
			}
		}
		if ( isset( $_GET['ote_booking'] ) && is_string( $_GET['ote_booking'] ) && 'received' === sanitize_key( wp_unslash( $_GET['ote_booking'] ) ) ) {
			echo '<p class="ote-success" role="status">' . esc_html__( 'Thank you. Your booking request was received. It is not confirmed until the travel agency contacts you.', 'opentrip-engine' ) . '</p>';
			if ( isset( $_GET['ote_ref'] ) && is_string( $_GET['ote_ref'] ) ) {
				echo '<p class="ote-booking-reference"><strong>' . esc_html__( 'Your request reference:', 'opentrip-engine' ) . '</strong> ' . esc_html( sanitize_text_field( wp_unslash( $_GET['ote_ref'] ) ) ) . '</p>';
			}
		}
		$available_departures = OTE_Departures::instance()->get_available( $tour_id );
		if ( ! $available_departures ) {
			echo '<p class="ote-no-departures" role="status">' . esc_html__( 'No departures are currently available for this tour. Please contact us.', 'opentrip-engine' ) . '</p>';
			return ob_get_clean();
		}
		?>
		<?php $form_id = wp_unique_id( 'ote-booking-' ); ?>
		<form method="post" class="ote-booking-form" aria-labelledby="<?php echo esc_attr( $form_id . '-heading' ); ?>">
			<h2 id="<?php echo esc_attr( $form_id . '-heading' ); ?>"><?php esc_html_e( 'Request a booking', 'opentrip-engine' ); ?></h2>
			<input type="hidden" name="ote_action" value="submit_booking">
			<input type="hidden" name="ote_tour_id" value="<?php echo esc_attr( $tour_id ); ?>">
			<?php wp_nonce_field( 'ote_submit_booking', 'ote_booking_nonce' ); ?>
			<div class="ote-honeypot" aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;"><label><?php esc_html_e( 'Leave this field empty', 'opentrip-engine' ); ?><input type="text" name="ote_website" tabindex="-1" autocomplete="off"></label></div>
			<p><label for="<?php echo esc_attr( $form_id . '-name' ); ?>"><?php esc_html_e( 'Full name', 'opentrip-engine' ); ?></label><br><input id="<?php echo esc_attr( $form_id . '-name' ); ?>" required type="text" name="ote_name" autocomplete="name"></p>
			<p><label for="<?php echo esc_attr( $form_id . '-email' ); ?>"><?php esc_html_e( 'Email address', 'opentrip-engine' ); ?></label><br><input id="<?php echo esc_attr( $form_id . '-email' ); ?>" required type="email" name="ote_email" autocomplete="email"></p>
			<p><label for="<?php echo esc_attr( $form_id . '-departure' ); ?>"><?php esc_html_e( 'Departure date', 'opentrip-engine' ); ?></label><br>
			<select id="<?php echo esc_attr( $form_id . '-departure' ); ?>" required name="ote_departure_id">
				<option value=""><?php esc_html_e( 'Choose a departure', 'opentrip-engine' ); ?></option>
				<?php foreach ( $available_departures as $departure ) : ?>
				<option value="<?php echo esc_attr( $departure->id ); ?>"><?php echo esc_html( $departure->start_date . ' – ' . $departure->end_date . ' | ' . max( 0, (int) $departure->capacity - (int) $departure->reserved ) . ' ' . __( 'seats left', 'opentrip-engine' ) . ' | ' . number_format_i18n( isset( $departure->adult_price ) && null !== $departure->adult_price ? (float) $departure->adult_price : (float) $departure->price, 2 ) . ' ' . $departure->currency ); ?></option>
				<?php endforeach; ?>
			</select></p>
			<fieldset class="ote-traveller-fields">
				<legend><?php esc_html_e( 'Travellers', 'opentrip-engine' ); ?></legend>
				<p><label for="<?php echo esc_attr( $form_id . '-adults' ); ?>"><?php esc_html_e( 'Adults', 'opentrip-engine' ); ?></label><br><input id="<?php echo esc_attr( $form_id . '-adults' ); ?>" required type="number" name="ote_adults" min="1" max="30" value="1"></p>
				<p><label for="<?php echo esc_attr( $form_id . '-children' ); ?>"><?php esc_html_e( 'Children', 'opentrip-engine' ); ?></label><br><input id="<?php echo esc_attr( $form_id . '-children' ); ?>" required type="number" name="ote_children" min="0" max="29" value="0"></p>
				<p><label for="<?php echo esc_attr( $form_id . '-child-ages' ); ?>"><?php esc_html_e( 'Child ages (comma-separated)', 'opentrip-engine' ); ?></label><br><input id="<?php echo esc_attr( $form_id . '-child-ages' ); ?>" type="text" name="ote_child_ages" inputmode="numeric" placeholder="<?php esc_attr_e( 'For example: 5, 9', 'opentrip-engine' ); ?>" aria-describedby="<?php echo esc_attr( $form_id . '-age-help' ); ?>" autocomplete="off"></p>
				<p><label for="<?php echo esc_attr( $form_id . '-infants' ); ?>"><?php esc_html_e( 'Infants', 'opentrip-engine' ); ?></label><br><input id="<?php echo esc_attr( $form_id . '-infants' ); ?>" required type="number" name="ote_infants" min="0" max="30" value="0"></p>
				<p><label for="<?php echo esc_attr( $form_id . '-infant-ages' ); ?>"><?php esc_html_e( 'Infant ages (comma-separated)', 'opentrip-engine' ); ?></label><br><input id="<?php echo esc_attr( $form_id . '-infant-ages' ); ?>" type="text" name="ote_infant_ages" inputmode="numeric" placeholder="<?php esc_attr_e( 'For example: 0, 1', 'opentrip-engine' ); ?>" aria-describedby="<?php echo esc_attr( $form_id . '-age-help' ); ?>" autocomplete="off"></p>
			</fieldset>
			<p id="<?php echo esc_attr( $form_id . '-age-help' ); ?>" class="ote-price-note"><?php esc_html_e( 'Enter one whole-number age for every child and infant. Infants do not use departure seats. Configured room charges are added per room.', 'opentrip-engine' ); ?></p>
			<p><label for="<?php echo esc_attr( $form_id . '-rooms' ); ?>"><?php esc_html_e( 'Rooms required', 'opentrip-engine' ); ?></label><br><input id="<?php echo esc_attr( $form_id . '-rooms' ); ?>" required type="number" name="ote_rooms" min="1" max="20" value="1"></p>
			<p><label for="<?php echo esc_attr( $form_id . '-message' ); ?>"><?php esc_html_e( 'Message (optional)', 'opentrip-engine' ); ?></label><br><textarea id="<?php echo esc_attr( $form_id . '-message' ); ?>" name="ote_message" rows="4"></textarea></p>
			<p><button type="submit"><?php esc_html_e( 'Send booking request', 'opentrip-engine' ); ?></button></p>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Apply an atomic, privacy-preserving email-scoped limit to public submissions.
	 *
	 * The database limiter stores a salted hash and never trusts proxy IP headers.
	 *
	 * @param string $type  Submission type: booking or review.
	 * @param string $email Validated customer email address.
	 * @return bool Whether the submission limit has been reached.
	 */
	/**
	 * Render a public submission error in an assistive-technology announcement region.
	 *
	 * @param string $message Translated, plain-text error message.
	 * @param int    $status HTTP response status.
	 */
	private function public_form_error( $message, $status = 400 ) {
		$title = __( 'Unable to submit your request', 'opentrip-engine' );
		$alert = '<div role="alert" aria-live="assertive"><p>' . esc_html( $message ) . '</p></div>';
		wp_die( $alert, esc_html( $title ), array( 'response' => absint( $status ) ) );
	}

	public function is_submission_rate_limited( $type, $email ) {
		return OTE_Submission_Limiter::is_limited( $type, $email );
	}

	public function handle_booking() {
		if ( 'submit_booking' !== sanitize_key( $this->posted_string( 'ote_action' ) ) ) {
			return;
		}
		if ( ! is_string( isset( $_POST['ote_booking_nonce'] ) ? $_POST['ote_booking_nonce'] : null ) || ! wp_verify_nonce( sanitize_text_field( $this->posted_string( 'ote_booking_nonce' ) ), 'ote_submit_booking' ) ) {
			$this->public_form_error( __( 'Security check failed. Please go back and try again.', 'opentrip-engine' ), 403 );
		}
		if ( ! empty( $_POST['ote_website'] ) ) {
			$this->public_form_error( __( 'Your booking request could not be submitted.', 'opentrip-engine' ), 400 );
		}
		$tour_id = absint( $this->posted_string( 'ote_tour_id' ) );
		if ( 'ote_tour' !== get_post_type( $tour_id ) || 'publish' !== get_post_status( $tour_id ) ) {
			$this->public_form_error( __( 'The selected tour is unavailable.', 'opentrip-engine' ), 400 );
		}
		$name = sanitize_text_field( $this->posted_string( 'ote_name' ) );
		$email = strtolower( sanitize_email( $this->posted_string( 'ote_email' ) ) );
		$departure_id = absint( $this->posted_string( 'ote_departure_id' ) );
		$adults = $this->posted_uint( 'ote_adults' );
		$children = $this->posted_uint( 'ote_children' );
		$infants = $this->posted_uint( 'ote_infants' );
		$child_ages = OTE_Pricing::parse_ages( $this->posted_string( 'ote_child_ages' ), $children );
		$infant_ages = OTE_Pricing::parse_ages( $this->posted_string( 'ote_infant_ages' ), $infants );
		$rooms = $this->posted_uint( 'ote_rooms' );
		$seats = $adults + $children;
		$travellers = $seats + $infants;
		$message = sanitize_textarea_field( $this->posted_string( 'ote_message' ) );
		$departure = OTE_Departures::instance()->get_departure( $departure_id, $tour_id );
		if ( ! $name || strlen( $name ) > 120 || ! is_email( $email ) || strlen( $email ) > 254 || strlen( $message ) > 3000 || ! $departure || $adults < 1 || $children < 0 || $children > 29 || $infants < 0 || $infants > 30 || $travellers > 30 || $rooms < 1 || $rooms > 20 || is_wp_error( $child_ages ) || is_wp_error( $infant_ages ) || (int) $departure->capacity - (int) $departure->reserved < $seats ) {
			$this->public_form_error( __( 'Please check your name, email, travel date and traveller count.', 'opentrip-engine' ), 400 );
		}
		$quote = OTE_Pricing::calculate_quote( $departure, $adults, $child_ages, $infant_ages, $rooms );
		if ( is_wp_error( $quote ) ) {
			$this->public_form_error( $quote->get_error_message(), 400 );
		}
		if ( $this->is_submission_rate_limited( 'booking', $email ) ) {
			$this->public_form_error( __( 'Too many booking requests have been submitted for this email address. Please wait 15 minutes and try again.', 'opentrip-engine' ), 429 );
		}
		$booking_id = wp_insert_post( array(
			'post_type' => 'ote_booking',
			'post_status' => 'private',
			'post_title' => sprintf( '%s — %s', $name, get_the_title( $tour_id ) ),
		), true );
		if ( is_wp_error( $booking_id ) ) {
			$this->public_form_error( __( 'We could not save your request. Please try again later.', 'opentrip-engine' ), 500 );
		}
		update_post_meta( $booking_id, '_ote_tour_id', $tour_id );
		update_post_meta( $booking_id, '_ote_customer_name', $name );
		update_post_meta( $booking_id, '_ote_customer_email', $email );
		update_post_meta( $booking_id, '_ote_departure_id', $departure_id );
		update_post_meta( $booking_id, '_ote_travel_date', $departure->start_date );
		update_post_meta( $booking_id, '_ote_adults', $adults );
		update_post_meta( $booking_id, '_ote_children', $children );
		update_post_meta( $booking_id, '_ote_child_ages', $child_ages );
		update_post_meta( $booking_id, '_ote_infants', $infants );
		update_post_meta( $booking_id, '_ote_infant_ages', $infant_ages );
		update_post_meta( $booking_id, '_ote_rooms', $rooms );
		update_post_meta( $booking_id, '_ote_travellers', $travellers );
		update_post_meta( $booking_id, '_ote_seats', $seats );
		update_post_meta( $booking_id, '_ote_unit_price', (float) $departure->price );
		update_post_meta( $booking_id, '_ote_currency', $quote['currency'] );
		update_post_meta( $booking_id, '_ote_quote_snapshot', $quote );
		update_post_meta( $booking_id, '_ote_total_price', $quote['total'] );
		update_post_meta( $booking_id, '_ote_customer_message', $message );
		update_post_meta( $booking_id, '_ote_booking_status', 'pending' );
		$reference = sprintf( 'OTE-%06d', $booking_id );
		update_post_meta( $booking_id, '_ote_booking_reference', $reference );

		$this->send_customer_booking_email( $booking_id, 'pending' );
		$this->send_admin_booking_email( $booking_id );

		$referer = wp_get_referer();
		$redirect = $referer ? $referer : get_permalink( $tour_id );
		wp_safe_redirect( add_query_arg( array( 'ote_booking' => 'received', 'ote_ref' => $reference ), $redirect ) );
		exit;
	}

	public function add_booking_meta_box() {
		add_meta_box( 'ote_booking_details', __( 'Booking Details', 'opentrip-engine' ), array( $this, 'render_booking_meta_box' ), 'ote_booking', 'normal', 'high' );
		add_meta_box( 'ote_booking_status', __( 'Request Status', 'opentrip-engine' ), array( $this, 'render_booking_status_box' ), 'ote_booking', 'side', 'high' );
		add_meta_box( 'ote_booking_payment', __( 'Payment', 'opentrip-engine' ), array( $this, 'render_booking_payment_box' ), 'ote_booking', 'side', 'default' );
	}

	public function render_booking_payment_box( $post ) {
		$status = (string) get_post_meta( $post->ID, '_ote_payment_status', true );
		if ( '' === $status ) {
			$status = 'not_started';
		}
		$labels = array(
			'not_started' => __( 'Not started', 'opentrip-engine' ),
			'pending'     => __( 'Payment pending', 'opentrip-engine' ),
			'paid'        => __( 'Paid', 'opentrip-engine' ),
			'failed'      => __( 'Payment failed', 'opentrip-engine' ),
			'expired'     => __( 'Payment link expired', 'opentrip-engine' ),
		);
		echo '<p><strong>' . esc_html__( 'Payment status:', 'opentrip-engine' ) . '</strong> ' . esc_html( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) . '</p>';
		$transaction_id = (string) get_post_meta( $post->ID, '_ote_payment_transaction_id', true );
		if ( $transaction_id ) {
			echo '<p><strong>' . esc_html__( 'Stripe transaction:', 'opentrip-engine' ) . '</strong> ' . esc_html( $transaction_id ) . '</p>';
		}
		$provider = (string) get_post_meta( $post->ID, '_ote_payment_provider', true );
		if ( $provider ) {
			echo '<p><strong>' . esc_html__( 'Provider:', 'opentrip-engine' ) . '</strong> ' . esc_html( $provider ) . '</p>';
		}
		$amount = get_post_meta( $post->ID, '_ote_payment_amount', true );
		$currency = get_post_meta( $post->ID, '_ote_payment_currency', true );
		if ( '' !== (string) $amount && $currency ) {
			echo '<p><strong>' . esc_html__( 'Requested payment:', 'opentrip-engine' ) . '</strong> ' . esc_html( number_format_i18n( (float) $amount, 2 ) . ' ' . $currency ) . '</p>';
		}
		$attention = get_post_meta( $post->ID, '_ote_payment_attention', true );
		if ( $attention ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $attention ) . '</p></div>';
		}
		$notice_key = 'ote_payment_notice_' . get_current_user_id();
		$notice = get_transient( $notice_key );
		if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
			delete_transient( $notice_key );
			echo '<div class="notice notice-' . esc_attr( isset( $notice['type'] ) ? $notice['type'] : 'info' ) . ' inline"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
		$url = (string) get_post_meta( $post->ID, '_ote_payment_url', true );
		if ( $url && 'pending' === $status && OTE_Stripe_Gateway::is_checkout_url_public( $url ) ) {
			echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open existing Stripe payment link', 'opentrip-engine' ) . '</a></p>';
		}
		if ( 'confirmed' !== get_post_meta( $post->ID, '_ote_booking_status', true ) || 'paid' === $status ) {
			if ( 'paid' === $status ) {
				echo '<p>' . esc_html__( 'Payment is recorded. Booking confirmation remains controlled by the agency.', 'opentrip-engine' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'A payment link can be created after the booking request is confirmed.', 'opentrip-engine' ) . '</p>';
			}
			return;
		}
		$gateway = OTE_Payment_Gateway_Registry::get( 'stripe' );
		if ( ! $gateway || ! $gateway->is_enabled() ) {
			echo '<p>' . esc_html__( 'Enable and configure Stripe in OpenTrip Engine Settings to create payment links.', 'opentrip-engine' ) . '</p>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ote_create_stripe_payment_link">
			<input type="hidden" name="booking_id" value="<?php echo esc_attr( $post->ID ); ?>">
			<?php wp_nonce_field( 'ote_create_stripe_payment_link_' . $post->ID ); ?>
			<?php submit_button( $url && 'pending' === $status ? __( 'Reuse or refresh payment link', 'opentrip-engine' ) : __( 'Create Stripe payment link', 'opentrip-engine' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public function handle_create_stripe_payment_link() {
		$booking_id = absint( $this->posted_string( 'booking_id' ) );
		if ( ! $booking_id || 'ote_booking' !== get_post_type( $booking_id ) || ! current_user_can( 'edit_post', $booking_id ) ) {
			wp_die( esc_html__( 'You are not allowed to create a payment link for this booking.', 'opentrip-engine' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ote_create_stripe_payment_link_' . $booking_id );
		$gateway = OTE_Payment_Gateway_Registry::get( 'stripe' );
		$result = $gateway ? $gateway->create_checkout_session( $booking_id ) : new WP_Error( 'ote_gateway_missing', __( 'The Stripe payment adapter is unavailable.', 'opentrip-engine' ) );
		if ( is_wp_error( $result ) ) {
			$notice = array( 'type' => 'error', 'message' => $result->get_error_message() );
		} else {
			$sent = $this->send_payment_link_email( $booking_id, $result );
			$notice = array(
				'type' => 'success',
				'message' => $sent ? __( 'Stripe payment link created and emailed to the customer.', 'opentrip-engine' ) : __( 'Stripe payment link created. Customer email was not sent; copy the link from this booking record.', 'opentrip-engine' ),
			);
		}
		set_transient( 'ote_payment_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		$edit_url = get_edit_post_link( $booking_id, 'url' );
		wp_safe_redirect( add_query_arg( 'ote_payment_notice', '1', $edit_url ) );
		exit;
	}

	public function send_payment_link_email( $booking_id, $session ) {
		if ( '1' !== (string) get_option( 'ote_customer_notifications_enabled', '1' ) ) {
			return false;
		}
		$email = sanitize_email( get_post_meta( $booking_id, '_ote_customer_email', true ) );
		if ( ! is_email( $email ) || empty( $session['url'] ) || ! OTE_Stripe_Gateway::is_checkout_url_public( $session['url'] ) ) {
			return false;
		}
		$name = sanitize_text_field( get_post_meta( $booking_id, '_ote_customer_name', true ) );
		$reference = sanitize_text_field( get_post_meta( $booking_id, '_ote_booking_reference', true ) );
		$amount = number_format_i18n( (float) $session['amount'], 2 );
		$currency = sanitize_text_field( $session['currency'] );
		$subject = sprintf( __( 'Secure payment link for booking %s', 'opentrip-engine' ), $reference );
		$message = sprintf(
			__( "Hello %1$s,\n\nThe travel agency has prepared a secure Stripe payment link for booking %2$s.\nAmount due now: %3$s %4$s.\n\nPay securely here: %5$s\n\nYour card details are entered directly on Stripe and are not stored by this website. Payment does not change the agency's booking confirmation decision.\n\nRegards,\n%6$s", 'opentrip-engine' ),
			$name,
			$reference,
			$amount,
			$currency,
			esc_url_raw( $session['url'] ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		return wp_mail( $email, $subject, $message );
	}

	public function render_booking_meta_box( $post ) {
		$status = get_post_meta( $post->ID, '_ote_booking_status', true );
		$fields = array(
			__( 'Reference', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_booking_reference', true ),
			__( 'Status', 'opentrip-engine' ) => $this->booking_status_label( $status ),
			__( 'Tour', 'opentrip-engine' ) => get_the_title( (int) get_post_meta( $post->ID, '_ote_tour_id', true ) ),
			__( 'Customer', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_customer_name', true ),
			__( 'Email', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_customer_email', true ),
			__( 'Travel date', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_travel_date', true ),
			__( 'Adults', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_adults', true ),
			__( 'Children', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_children', true ),
			__( 'Child ages', 'opentrip-engine' ) => implode( ', ', (array) get_post_meta( $post->ID, '_ote_child_ages', true ) ),
			__( 'Infants', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_infants', true ),
			__( 'Infant ages', 'opentrip-engine' ) => implode( ', ', (array) get_post_meta( $post->ID, '_ote_infant_ages', true ) ),
			__( 'Rooms', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_rooms', true ),
			__( 'Total travellers', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_travellers', true ),
			__( 'Price breakdown', 'opentrip-engine' ) => $this->booking_quote_summary( get_post_meta( $post->ID, '_ote_quote_snapshot', true ) ),
			__( 'Estimated total', 'opentrip-engine' ) => number_format_i18n( (float) get_post_meta( $post->ID, '_ote_total_price', true ), 2 ) . ' ' . get_post_meta( $post->ID, '_ote_currency', true ),
			__( 'Message', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_customer_message', true ),
		);
		foreach ( $fields as $label => $value ) {
			echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</p>';
		}
	}

	/**
	 * Format the immutable booking quote for admin and customer communications.
	 *
	 * @param mixed $quote Saved quote snapshot.
	 * @return string
	 */
	public function booking_quote_summary( $quote ) {
		if ( ! is_array( $quote ) || empty( $quote['currency'] ) ) {
			return __( 'Legacy quote; no itemized breakdown was saved.', 'opentrip-engine' );
		}
		$parts = array();
		foreach ( array( 'adults' => __( 'Adults', 'opentrip-engine' ), 'children' => __( 'Children', 'opentrip-engine' ), 'infants' => __( 'Infants', 'opentrip-engine' ), 'rooms' => __( 'Rooms', 'opentrip-engine' ) ) as $key => $label ) {
			if ( isset( $quote[ $key ]['subtotal'] ) ) {
				$parts[] = sprintf( '%1$s: %2$s %3$s', $label, number_format_i18n( (float) $quote[ $key ]['subtotal'], 2 ), $quote['currency'] );
			}
		}
		return implode( ' | ', $parts );
	}
	public function booking_status_label( $status ) {
		$labels = array(
			'pending' => __( 'Pending', 'opentrip-engine' ),
			'confirmed' => __( 'Confirmed', 'opentrip-engine' ),
			'cancelled' => __( 'Cancelled', 'opentrip-engine' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['pending'];
	}

	public function booking_columns( $columns ) {
		return array(
			'cb' => isset( $columns['cb'] ) ? $columns['cb'] : '',
			'title' => __( 'Booking', 'opentrip-engine' ),
			'ote_tour' => __( 'Tour', 'opentrip-engine' ),
			'ote_customer' => __( 'Customer', 'opentrip-engine' ),
			'ote_travel_date' => __( 'Travel date', 'opentrip-engine' ),
			'ote_travellers' => __( 'Travellers', 'opentrip-engine' ),
			'ote_status' => __( 'Status', 'opentrip-engine' ),
			'date' => __( 'Submitted', 'opentrip-engine' ),
		);
	}

	public function booking_column_content( $column, $post_id ) {
		if ( 'ote_tour' === $column ) {
			echo esc_html( get_the_title( (int) get_post_meta( $post_id, '_ote_tour_id', true ) ) );
		} elseif ( 'ote_customer' === $column ) {
			$name = get_post_meta( $post_id, '_ote_customer_name', true );
			$email = get_post_meta( $post_id, '_ote_customer_email', true );
			echo esc_html( $name );
			if ( $email ) {
				echo '<br><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
			}
		} elseif ( 'ote_travel_date' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_ote_travel_date', true ) );
		} elseif ( 'ote_travellers' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_ote_travellers', true ) );
		} elseif ( 'ote_status' === $column ) {
			$status = get_post_meta( $post_id, '_ote_booking_status', true );
			echo esc_html( $this->booking_status_label( $status ) );
		}
	}

	public function save_booking_status( $post_id ) {
		if ( ! is_string( isset( $_POST['ote_booking_status_nonce'] ) ? $_POST['ote_booking_status_nonce'] : null ) || ! wp_verify_nonce( sanitize_text_field( $this->posted_string( 'ote_booking_status_nonce' ) ), 'ote_save_booking_status' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$status = sanitize_key( $this->posted_string( 'ote_booking_status' ) );
		if ( ! in_array( $status, array( 'pending', 'confirmed', 'cancelled' ), true ) ) {
			return;
		}

		$transition = OTE_Departures::instance()->transition_booking(
			$post_id,
			(int) get_post_meta( $post_id, '_ote_tour_id', true ),
			(int) get_post_meta( $post_id, '_ote_departure_id', true ),
			(int) get_post_meta( $post_id, '_ote_seats', true ) ? (int) get_post_meta( $post_id, '_ote_seats', true ) : (int) get_post_meta( $post_id, '_ote_travellers', true ),
			$status
		);
		if ( false === $transition ) {
			$message = 'confirmed' === $status
				? __( 'The booking could not be confirmed because seats are unavailable or the inventory could not be updated. The booking status was not changed.', 'opentrip-engine' )
				: __( 'The reserved seats could not be released. The booking status was not changed; please check the departure inventory.', 'opentrip-engine' );
			set_transient( 'ote_inventory_error_' . get_current_user_id(), $message, 30 );
			return;
		}
		if ( ! empty( $transition['changed'] ) ) {
			$this->send_customer_booking_email( $post_id, $status );
		}
	}

	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<p>' . esc_html__( 'OpenTrip Engine stores booking request details such as customer name, email address, travel date, traveller counts, room count, optional message, request status and estimated price. It also stores submitted tour reviews, including author name, email address, rating and review text. This information is used to manage travel enquiries, communicate with customers and moderate reviews. Booking and review data are stored in this WordPress site. Booking receipts and status updates are sent using the site email service. Site owners determine retention periods and should review applicable legal obligations.', 'opentrip-engine' ) . '</p>';
		$content .= '<p>' . esc_html__( 'The WordPress personal data exporter can export booking, review and payment-status information associated with an email address. If Stripe Checkout is enabled, the plugin sends the customer email, booking reference, payment amount and currency to Stripe to create a hosted payment session; card numbers and security codes are entered directly on Stripe and are not received or stored by this site. The personal data eraser anonymizes booking contact details, removes review author details and review text, removes local checkout URLs and provider transaction identifiers, and clears the associated short-lived hashed submission-limiter record; erased reviews are unpublished. Payment status and amount/currency may remain on anonymized bookings for accounting and inventory audit purposes. Site owners must determine any additional retention obligations.', 'opentrip-engine' ) . '</p>';
		$content .= '<p>' . esc_html__( 'For abuse prevention, the plugin stores a salted hash of email addresses submitted through booking and review forms, not the raw address in its rate-limit table. These records are opportunistically cleaned up after 24 hours and are also removed when the matching email is processed by the personal data eraser.', 'opentrip-engine' ) . '</p>';
		wp_add_privacy_policy_content( __( 'OpenTrip Engine', 'opentrip-engine' ), $content );
	}

	public function register_privacy_exporter( $exporters ) {
		$exporters['opentrip-engine'] = array(
			'exporter_friendly_name' => __( 'OpenTrip Engine bookings and reviews', 'opentrip-engine' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);
		return $exporters;
	}

	public function register_privacy_eraser( $erasers ) {
		$erasers['opentrip-engine'] = array(
			'eraser_friendly_name' => __( 'OpenTrip Engine bookings and reviews', 'opentrip-engine' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);
		return $erasers;
	}

	public function export_personal_data( $email_address, $page = 1 ) {
		$limit  = 100;
		$offset = max( 0, ( absint( $page ) - 1 ) * $limit );
		$data      = array();
		$has_more = false;
		$types     = array(
			'ote_booking' => '_ote_customer_email',
			'ote_review'  => '_ote_review_email',
		);
		foreach ( $types as $post_type => $email_meta ) {
			$ids = get_posts( array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'fields'         => 'ids',
				'meta_key'       => $email_meta,
				'meta_value'     => strtolower( sanitize_email( $email_address ) ),
			) );
			if ( count( $ids ) === $limit ) {
				$has_more = true;
			}
			foreach ( $ids as $post_id ) {
				if ( 'ote_booking' === $post_type ) {
					$items = array(
						'Booking reference' => get_post_meta( $post_id, '_ote_booking_reference', true ),
						'Tour'              => get_the_title( (int) get_post_meta( $post_id, '_ote_tour_id', true ) ),
						'Customer name'     => get_post_meta( $post_id, '_ote_customer_name', true ),
						'Email address'     => get_post_meta( $post_id, '_ote_customer_email', true ),
						'Travel date'       => get_post_meta( $post_id, '_ote_travel_date', true ),
						'Adults'            => get_post_meta( $post_id, '_ote_adults', true ),
						'Children'          => get_post_meta( $post_id, '_ote_children', true ),
						'Child ages'        => implode( ', ', (array) get_post_meta( $post_id, '_ote_child_ages', true ) ),
						'Infants'           => get_post_meta( $post_id, '_ote_infants', true ),
						'Infant ages'       => implode( ', ', (array) get_post_meta( $post_id, '_ote_infant_ages', true ) ),
						'Rooms'             => get_post_meta( $post_id, '_ote_rooms', true ),
						'Request status'    => get_post_meta( $post_id, '_ote_booking_status', true ),
						'Price breakdown'   => $this->booking_quote_summary( get_post_meta( $post_id, '_ote_quote_snapshot', true ) ),
						'Estimated total'   => get_post_meta( $post_id, '_ote_total_price', true ),
						'Currency'          => get_post_meta( $post_id, '_ote_currency', true ),
						'Payment provider'  => get_post_meta( $post_id, '_ote_payment_provider', true ),
						'Payment status'    => get_post_meta( $post_id, '_ote_payment_status', true ),
						'Payment amount'    => get_post_meta( $post_id, '_ote_payment_amount', true ),
						'Payment currency'  => get_post_meta( $post_id, '_ote_payment_currency', true ),
						'Payment transaction ID' => get_post_meta( $post_id, '_ote_payment_transaction_id', true ),
						'Payment created'   => get_post_meta( $post_id, '_ote_payment_created_at', true ),
						'Payment updated'   => get_post_meta( $post_id, '_ote_payment_updated_at', true ),
						'Customer message'  => get_post_meta( $post_id, '_ote_customer_message', true ),
					);
					$group_id = 'opentrip-bookings';
					$group_label = __( 'OpenTrip Engine booking requests', 'opentrip-engine' );
				} else {
					$items = array(
						'Tour'         => get_the_title( (int) get_post_meta( $post_id, '_ote_review_tour_id', true ) ),
						'Author name'  => get_post_meta( $post_id, '_ote_review_name', true ),
						'Email address'=> get_post_meta( $post_id, '_ote_review_email', true ),
						'Rating'       => get_post_meta( $post_id, '_ote_review_rating', true ),
						'Review text'  => get_post_field( 'post_content', $post_id ),
					);
					$group_id = 'opentrip-reviews';
					$group_label = __( 'OpenTrip Engine reviews', 'opentrip-engine' );
				}
				$export_data = array();
				foreach ( $items as $name => $value ) {
					if ( '' !== (string) $value ) {
						$export_data[] = array( 'name' => $name, 'value' => (string) $value );
					}
				}
				$data[] = array(
					'group_id'    => $group_id,
					'group_label' => $group_label,
					'item_id'     => $post_type . '-' . $post_id,
					'data'        => $export_data,
				);
			}
		}
		return array( 'data' => $data, 'done' => ! $has_more );
	}

	public function erase_personal_data( $email_address, $page = 1 ) {
		$limit = 100;
		$removed  = 0;
		$has_more = false;
		$types    = array(
			'ote_booking' => '_ote_customer_email',
			'ote_review'  => '_ote_review_email',
		);
		foreach ( $types as $post_type => $email_meta ) {
			$ids = get_posts( array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'meta_key'       => $email_meta,
				'meta_value'     => strtolower( sanitize_email( $email_address ) ),
			) );
			if ( count( $ids ) === $limit ) {
				$has_more = true;
			}
			foreach ( $ids as $post_id ) {
				if ( 'ote_booking' === $post_type ) {
					update_post_meta( $post_id, '_ote_customer_name', __( 'Anonymized customer', 'opentrip-engine' ) );
					update_post_meta( $post_id, '_ote_customer_email', '' );
					update_post_meta( $post_id, '_ote_customer_message', '' );
					delete_post_meta( $post_id, '_ote_child_ages' );
					delete_post_meta( $post_id, '_ote_infant_ages' );
					delete_post_meta( $post_id, '_ote_payment_url' );
					delete_post_meta( $post_id, '_ote_payment_session_id' );
					delete_post_meta( $post_id, '_ote_payment_transaction_id' );
					delete_post_meta( $post_id, '_ote_payment_attention' );
					$quote = get_post_meta( $post_id, '_ote_quote_snapshot', true );
					if ( is_array( $quote ) ) {
						if ( isset( $quote['children']['ages'] ) ) { $quote['children']['ages'] = array(); }
						if ( isset( $quote['children']['items'] ) ) { $quote['children']['items'] = array(); }
						if ( isset( $quote['infants']['ages'] ) ) { $quote['infants']['ages'] = array(); }
						update_post_meta( $post_id, '_ote_quote_snapshot', $quote );
					}
					wp_update_post( array( 'ID' => $post_id, 'post_title' => __( 'Anonymized booking request', 'opentrip-engine' ) ) );
				} else {
					update_post_meta( $post_id, '_ote_review_name', __( 'Anonymous', 'opentrip-engine' ) );
					update_post_meta( $post_id, '_ote_review_email', '' );
					wp_update_post( array(
						'ID'           => $post_id,
						'post_title'   => __( 'Anonymized review', 'opentrip-engine' ),
						'post_content' => '',
						'post_status'  => 'draft',
					) );
				}
				$removed++;
			}
		}
		OTE_Submission_Limiter::erase_email( $email_address );

		return array(
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => ! $has_more,
		);
	}

	public function inventory_admin_notice() {
		$key = 'ote_inventory_error_' . get_current_user_id();
		$message = get_transient( $key );
		if ( $message ) {
			delete_transient( $key );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	}


	/**
	 * Send a customer-facing booking receipt or status update.
	 *
	 * @param int    $booking_id Booking post ID.
	 * @param string $status     Current booking status.
	 */

	/**
	 * Send the agency notification for a newly received booking request.
	 *
	 * @param int $booking_id Booking post ID.
	 * @return bool Whether WordPress accepted the email for delivery.
	 */
	public function send_admin_booking_email( $booking_id ) {
		if ( '1' !== (string) get_option( 'ote_admin_notifications_enabled', '1' ) ) {
			return false;
		}
		$admin_email = get_option( 'ote_notification_email', get_option( 'admin_email' ) );
		if ( ! is_email( $admin_email ) ) {
			return false;
		}
		$tour_id = (int) get_post_meta( $booking_id, '_ote_tour_id', true );
		$subject = sprintf( __( 'New booking request: %s', 'opentrip-engine' ), get_the_title( $tour_id ) );
		$body = sprintf(
			__( "A new booking request was received.\n\nReference: %1\$s\nTour: %2\$s\nName: %3\$s\nEmail: %4\$s\nTravel date: %5\$s\nTravellers: %6\$d\n\nReview it in your WordPress dashboard.", 'opentrip-engine' ),
			get_post_meta( $booking_id, '_ote_booking_reference', true ),
			get_the_title( $tour_id ),
			get_post_meta( $booking_id, '_ote_customer_name', true ),
			get_post_meta( $booking_id, '_ote_customer_email', true ),
			get_post_meta( $booking_id, '_ote_travel_date', true ),
			(int) get_post_meta( $booking_id, '_ote_travellers', true )
		);
		return wp_mail( $admin_email, $subject, $body );
	}

	public function send_customer_booking_email( $booking_id, $status ) {
		if ( '1' !== (string) get_option( 'ote_customer_notifications_enabled', '1' ) ) {
			return;
		}
		$email = sanitize_email( get_post_meta( $booking_id, '_ote_customer_email', true ) );
		if ( ! is_email( $email ) ) {
			return;
		}
		$tour_id = (int) get_post_meta( $booking_id, '_ote_tour_id', true );
		$tour_name = get_the_title( $tour_id );
		$reference = get_post_meta( $booking_id, '_ote_booking_reference', true );
		$travel_date = get_post_meta( $booking_id, '_ote_travel_date', true );
		$travellers = (int) get_post_meta( $booking_id, '_ote_travellers', true );
		$quote = get_post_meta( $booking_id, '_ote_quote_snapshot', true );
		$total = number_format_i18n( (float) get_post_meta( $booking_id, '_ote_total_price', true ), 2 );
		$currency = get_post_meta( $booking_id, '_ote_currency', true );
		$name = get_post_meta( $booking_id, '_ote_customer_name', true );
		$greeting = sprintf( __( 'Hello %s,', 'opentrip-engine' ), $name );
		$details = sprintf(
			__( "Request reference: %1\$s\nTour: %2\$s\nDeparture: %3\$s\nTravellers: %4\$d\nEstimated total: %5\$s %6\$s", 'opentrip-engine' ),
			$reference, $tour_name, $travel_date, $travellers, $total, $currency
		);
		if ( is_array( $quote ) ) {
			$details .= "\n" . $this->booking_quote_summary( $quote );
		}
		if ( 'pending' === $status ) {
			$subject = sprintf( __( 'We received your booking request — %s', 'opentrip-engine' ), $reference );
			$message = __( "Thank you for your interest. We have received your booking request. This is not a confirmed reservation and no payment has been taken. Our team will review availability and contact you.\n\n", 'opentrip-engine' );
		} elseif ( 'confirmed' === $status ) {
			$subject = sprintf( __( 'Your booking request is confirmed — %s', 'opentrip-engine' ), $reference );
			$message = __( "Good news: the travel agency has marked your booking request as confirmed. Please contact the agency if you need payment or final travel documents.\n\n", 'opentrip-engine' );
		} elseif ( 'cancelled' === $status ) {
			$subject = sprintf( __( 'Booking request update — %s', 'opentrip-engine' ), $reference );
			$message = __( "Your booking request has been marked as cancelled by the travel agency. Please contact the agency if you believe this is an error.\n\n", 'opentrip-engine' );
		} else {
			return;
		}
		$footer = sprintf( __( "\n\nRegards,\n%s", 'opentrip-engine' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		wp_mail( $email, $subject, $greeting . "\n\n" . $message . $details . $footer );
	}


	public function render_booking_status_box( $post ) {
		wp_nonce_field( 'ote_save_booking_status', 'ote_booking_status_nonce' );
		$current = get_post_meta( $post->ID, '_ote_booking_status', true );
		if ( ! $current ) {
			$current = 'pending';
		}
		?>
		<p><label for="ote_booking_status"><?php esc_html_e( 'Request status', 'opentrip-engine' ); ?></label></p>
		<select name="ote_booking_status" id="ote_booking_status">
			<option value="pending" <?php selected( $current, 'pending' ); ?>><?php esc_html_e( 'Pending', 'opentrip-engine' ); ?></option>
			<option value="confirmed" <?php selected( $current, 'confirmed' ); ?>><?php esc_html_e( 'Confirmed', 'opentrip-engine' ); ?></option>
			<option value="cancelled" <?php selected( $current, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'opentrip-engine' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'This is an internal request status. It does not take payment or guarantee availability.', 'opentrip-engine' ); ?></p>
		<?php
	}

	public function review_form_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'tour_id' => 0 ), $atts, 'opentrip_review_form' );
		$tour_id = absint( $atts['tour_id'] );
		if ( ! $tour_id && is_singular( 'ote_tour' ) ) {
			$tour_id = get_the_ID();
		}
		if ( ! $tour_id || 'ote_tour' !== get_post_type( $tour_id ) || 'publish' !== get_post_status( $tour_id ) ) {
			return '';
		}
		$form_id = wp_unique_id( 'ote-review-' );
		ob_start();
		if ( isset( $_GET['ote_review'] ) && is_string( $_GET['ote_review'] ) && 'received' === sanitize_key( wp_unslash( $_GET['ote_review'] ) ) ) {
			echo '<p class="ote-review-notice" role="status">' . esc_html__( 'Thank you. Your review was submitted and is awaiting moderation.', 'opentrip-engine' ) . '</p>';
		}
		?>
		<form method="post" class="ote-review-form" aria-labelledby="<?php echo esc_attr( $form_id . '-heading' ); ?>">
			<h2 id="<?php echo esc_attr( $form_id . '-heading' ); ?>"><?php esc_html_e( 'Write a guest review', 'opentrip-engine' ); ?></h2>
			<input type="hidden" name="ote_review_action" value="submit_review">
			<input type="hidden" name="ote_review_tour_id" value="<?php echo esc_attr( $tour_id ); ?>">
			<?php wp_nonce_field( 'ote_submit_review', 'ote_review_nonce' ); ?>
			<p class="ote-review-trap" aria-hidden="true"><label><?php esc_html_e( 'Leave this field empty', 'opentrip-engine' ); ?><input type="text" name="ote_website" tabindex="-1" autocomplete="off"></label></p>
			<p><label for="<?php echo esc_attr( $form_id . '-name' ); ?>"><?php esc_html_e( 'Your name', 'opentrip-engine' ); ?></label><br><input id="<?php echo esc_attr( $form_id . '-name' ); ?>" required type="text" name="ote_review_name" maxlength="100" autocomplete="name"></p>
			<p><label for="<?php echo esc_attr( $form_id . '-email' ); ?>"><?php esc_html_e( 'Email (not displayed publicly)', 'opentrip-engine' ); ?></label><br><input id="<?php echo esc_attr( $form_id . '-email' ); ?>" required type="email" name="ote_review_email" maxlength="190" autocomplete="email"></p>
			<p><label for="<?php echo esc_attr( $form_id . '-rating' ); ?>"><?php esc_html_e( 'Rating', 'opentrip-engine' ); ?></label><br><select id="<?php echo esc_attr( $form_id . '-rating' ); ?>" required name="ote_review_rating"><option value=""><?php esc_html_e( 'Choose a rating', 'opentrip-engine' ); ?></option><?php for ( $rating = 5; $rating >= 1; $rating-- ) : ?><option value="<?php echo esc_attr( $rating ); ?>"><?php echo esc_html( sprintf( _n( '%d star', '%d stars', $rating, 'opentrip-engine' ), $rating ) ); ?></option><?php endfor; ?></select></p>
			<p><label for="<?php echo esc_attr( $form_id . '-content' ); ?>"><?php esc_html_e( 'Your review', 'opentrip-engine' ); ?></label><br><textarea id="<?php echo esc_attr( $form_id . '-content' ); ?>" required name="ote_review_content" rows="5" maxlength="5000"></textarea></p>
			<p><button type="submit"><?php esc_html_e( 'Submit review', 'opentrip-engine' ); ?></button></p>
		</form>
		<?php
		return ob_get_clean();
	}

	public function handle_review_submission() {
		if ( 'submit_review' !== sanitize_key( $this->posted_string( 'ote_review_action' ) ) ) {
			return;
		}
		if ( ! is_string( isset( $_POST['ote_review_nonce'] ) ? $_POST['ote_review_nonce'] : null ) || ! wp_verify_nonce( sanitize_text_field( $this->posted_string( 'ote_review_nonce' ) ), 'ote_submit_review' ) ) {
			$this->public_form_error( __( 'Security check failed. Please go back and try again.', 'opentrip-engine' ), 403 );
		}
		if ( ! empty( $_POST['ote_website'] ) ) {
			$this->public_form_error( __( 'Your review could not be submitted.', 'opentrip-engine' ), 400 );
		}
		$tour_id = absint( $this->posted_string( 'ote_review_tour_id' ) );
		$name = sanitize_text_field( $this->posted_string( 'ote_review_name' ) );
		$email = strtolower( sanitize_email( $this->posted_string( 'ote_review_email' ) ) );
		$rating = absint( $this->posted_string( 'ote_review_rating' ) );
		$content = sanitize_textarea_field( $this->posted_string( 'ote_review_content' ) );
		if ( 'ote_tour' !== get_post_type( $tour_id ) || 'publish' !== get_post_status( $tour_id ) || '' === $name || strlen( $name ) > 120 || ! is_email( $email ) || strlen( $email ) > 254 || $rating < 1 || $rating > 5 || '' === trim( $content ) || strlen( $content ) > 5000 ) {
			$this->public_form_error( __( 'Please check your name, email, rating and review text.', 'opentrip-engine' ), 400 );
		}
		if ( $this->is_submission_rate_limited( 'review', $email ) ) {
			$this->public_form_error( __( 'Too many reviews have been submitted for this email address. Please wait 15 minutes and try again.', 'opentrip-engine' ), 429 );
		}
		$review_id = wp_insert_post( array(
			'post_type' => 'ote_review',
			'post_status' => 'pending',
			'post_title' => sprintf( __( 'Review for %1\$s by %2\$s', 'opentrip-engine' ), get_the_title( $tour_id ), $name ),
			'post_content' => $content,
		), true );
		if ( is_wp_error( $review_id ) ) {
			$this->public_form_error( __( 'We could not save your review. Please try again later.', 'opentrip-engine' ), 500 );
		}
		update_post_meta( $review_id, '_ote_review_tour_id', $tour_id );
		update_post_meta( $review_id, '_ote_review_name', $name );
		update_post_meta( $review_id, '_ote_review_email', $email );
		update_post_meta( $review_id, '_ote_review_rating', $rating );
		$referer = wp_get_referer();
		$redirect = $referer ? $referer : get_permalink( $tour_id );
		wp_safe_redirect( add_query_arg( 'ote_review', 'received', $redirect ) );
		exit;
	}

	public function reviews_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'tour_id' => 0, 'limit' => 10 ), $atts, 'opentrip_reviews' );
		$tour_id = absint( $atts['tour_id'] );
		if ( ! $tour_id && is_singular( 'ote_tour' ) ) {
			$tour_id = get_the_ID();
		}
		if ( ! $tour_id || 'ote_tour' !== get_post_type( $tour_id ) ) {
			return '';
		}
		$reviews = new WP_Query( array(
			'post_type' => 'ote_review',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'no_found_rows' => true,
			'meta_query' => array( array( 'key' => '_ote_review_tour_id', 'value' => $tour_id, 'compare' => '=' ) ),
		) );
		if ( ! $reviews->have_posts() ) {
			return '<section class="ote-reviews"><h2>' . esc_html__( 'Guest reviews', 'opentrip-engine' ) . '</h2><p>' . esc_html__( 'No approved reviews yet.', 'opentrip-engine' ) . '</p></section>';
		}
		$ratings = array();
		foreach ( $reviews->posts as $review ) {
			$ratings[] = min( 5, max( 1, absint( get_post_meta( $review->ID, '_ote_review_rating', true ) ) ) );
		}
		$average = array_sum( $ratings ) / count( $ratings );
		$display_reviews = array_slice( $reviews->posts, 0, min( 50, max( 1, absint( $atts['limit'] ) ) ) );
		ob_start();
		?>
		<section class="ote-reviews">
			<h2><?php esc_html_e( 'Guest reviews', 'opentrip-engine' ); ?></h2>
			<p class="ote-review-summary"><strong><?php echo esc_html( number_format_i18n( $average, 1 ) ); ?>/5</strong> — <?php echo esc_html( sprintf( _n( '%d approved review', '%d approved reviews', count( $ratings ), 'opentrip-engine' ), count( $ratings ) ) ); ?></p>
			<?php foreach ( $display_reviews as $review ) : $rating = min( 5, max( 1, absint( get_post_meta( $review->ID, '_ote_review_rating', true ) ) ) ); ?>
				<article class="ote-review">
					<p class="ote-review-stars"><span role="img" aria-label="<?php echo esc_attr( sprintf( __( '%d out of 5 stars', 'opentrip-engine' ), $rating ) ); ?>"><?php echo esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ); ?></span></p>
					<h3><?php echo esc_html( get_post_meta( $review->ID, '_ote_review_name', true ) ); ?></h3>
					<div class="ote-review-content"><?php echo wpautop( esc_html( $review->post_content ) ); ?></div>
					<small><?php echo esc_html( get_the_date( '', $review ) ); ?></small>
				</article>
			<?php endforeach; ?>
		</section>
		<?php
		wp_reset_postdata();
		return ob_get_clean();
	}

}

OpenTrip_Engine::instance();
register_activation_hook( __FILE__, array( 'OpenTrip_Engine', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OpenTrip_Engine', 'deactivate' ) );
