<?php
/**
 * Plugin Name: OpenTrip Engine
 * Description: A lightweight, open-source tour catalogue and booking-request engine for WordPress.
 * Version: 0.3.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: opentrip-engine
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OTE_VERSION', '0.3.0' );
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ote-departures.php';

final class OpenTrip_Engine {
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_types' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_tour_meta_box' ) );
		add_action( 'save_post_ote_tour', array( $this, 'save_tour_meta' ) );
		add_shortcode( 'opentrip_tours', array( $this, 'tours_shortcode' ) );
		add_shortcode( 'opentrip_booking_form', array( $this, 'booking_shortcode' ) );
		add_action( 'init', array( $this, 'handle_booking' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_booking_meta_box' ) );
		add_action( 'save_post_ote_booking', array( $this, 'save_booking_status' ) );
		add_filter( 'manage_ote_booking_posts_columns', array( $this, 'booking_columns' ) );
		add_action( 'manage_ote_booking_posts_custom_column', array( $this, 'booking_column_content' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'inventory_admin_notice' ) );
	}

	public static function activate() {
		self::instance()->register_types();
		OTE_Departures::install();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'opentrip-engine', plugin_dir_url( __FILE__ ) . 'assets/opentrip-engine.css', array(), OTE_VERSION );
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

	public function save_tour_meta( $post_id ) {
		if ( ! isset( $_POST['ote_tour_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ote_tour_nonce'] ) ), 'ote_save_tour' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$price = isset( $_POST['ote_price'] ) ? (float) wp_unslash( $_POST['ote_price'] ) : 0;
		$currency = isset( $_POST['ote_currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['ote_currency'] ) ) ) : 'USD';
		$duration = isset( $_POST['ote_duration'] ) ? sanitize_text_field( wp_unslash( $_POST['ote_duration'] ) ) : '';
		$capacity = isset( $_POST['ote_capacity'] ) ? absint( $_POST['ote_capacity'] ) : 0;

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
				echo '<a href="' . esc_url( get_permalink() ) . '">' . get_the_post_thumbnail( $id, 'large' ) . '</a>';
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
		if ( isset( $_GET['ote_booking'] ) && 'received' === sanitize_key( wp_unslash( $_GET['ote_booking'] ) ) ) {
			echo '<p role="status">' . esc_html__( 'Thank you. Your booking request was received.', 'opentrip-engine' ) . '</p>';
		}
		?>
		<form method="post" class="ote-booking-form">
			<input type="hidden" name="ote_action" value="submit_booking">
			<input type="hidden" name="ote_tour_id" value="<?php echo esc_attr( $tour_id ); ?>">
			<?php wp_nonce_field( 'ote_submit_booking', 'ote_booking_nonce' ); ?>
			<p><label><?php esc_html_e( 'Full name', 'opentrip-engine' ); ?><br><input required type="text" name="ote_name" autocomplete="name"></label></p>
			<p><label><?php esc_html_e( 'Email address', 'opentrip-engine' ); ?><br><input required type="email" name="ote_email" autocomplete="email"></label></p>
			<?php $available_departures = OTE_Departures::instance()->get_available( $tour_id ); ?>
		<?php if ( $available_departures ) : ?>
		<p><label><?php esc_html_e( 'Departure date', 'opentrip-engine' ); ?><br>
		<select required name="ote_departure_id">
			<option value=""><?php esc_html_e( 'Choose a departure', 'opentrip-engine' ); ?></option>
			<?php foreach ( $available_departures as $departure ) : ?>
			<option value="<?php echo esc_attr( $departure->id ); ?>"><?php echo esc_html( $departure->start_date . ' – ' . $departure->end_date . ' | ' . max( 0, (int) $departure->capacity - (int) $departure->reserved ) . ' ' . __( 'seats left', 'opentrip-engine' ) . ' | ' . number_format_i18n( (float) $departure->price, 2 ) . ' ' . $departure->currency ); ?></option>
			<?php endforeach; ?>
		</select></label></p>
		<?php else : ?>
		<p><?php esc_html_e( 'No departures are currently available for this tour. Please contact us.', 'opentrip-engine' ); ?></p>
		<?php endif; ?>
			<p><label><?php esc_html_e( 'Number of travellers', 'opentrip-engine' ); ?><br><input required type="number" name="ote_travellers" min="1" max="30" value="1"></label></p>
			<p><label><?php esc_html_e( 'Message (optional)', 'opentrip-engine' ); ?><br><textarea name="ote_message" rows="4"></textarea></label></p>
			<p><button type="submit"><?php esc_html_e( 'Send booking request', 'opentrip-engine' ); ?></button></p>
		</form>
		<?php
		return ob_get_clean();
	}

	public function handle_booking() {
		if ( empty( $_POST['ote_action'] ) || 'submit_booking' !== sanitize_key( wp_unslash( $_POST['ote_action'] ) ) ) {
			return;
		}
		if ( ! isset( $_POST['ote_booking_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ote_booking_nonce'] ) ), 'ote_submit_booking' ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'opentrip-engine' ), 403 );
		}
		$tour_id = isset( $_POST['ote_tour_id'] ) ? absint( $_POST['ote_tour_id'] ) : 0;
		if ( 'ote_tour' !== get_post_type( $tour_id ) || 'publish' !== get_post_status( $tour_id ) ) {
			wp_die( esc_html__( 'The selected tour is unavailable.', 'opentrip-engine' ), 400 );
		}
		$name = isset( $_POST['ote_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ote_name'] ) ) : '';
		$email = isset( $_POST['ote_email'] ) ? sanitize_email( wp_unslash( $_POST['ote_email'] ) ) : '';
		$departure_id = isset( $_POST['ote_departure_id'] ) ? absint( $_POST['ote_departure_id'] ) : 0;
		$travellers = isset( $_POST['ote_travellers'] ) ? absint( $_POST['ote_travellers'] ) : 0;
		$message = isset( $_POST['ote_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ote_message'] ) ) : '';
		$departure = OTE_Departures::instance()->get_departure( $departure_id, $tour_id );
		if ( ! $name || ! is_email( $email ) || ! $departure || $travellers < 1 || $travellers > 30 || (int) $departure->capacity - (int) $departure->reserved < $travellers ) {
			wp_die( esc_html__( 'Please check your name, email, travel date and traveller count.', 'opentrip-engine' ), 400 );
		}
		$booking_id = wp_insert_post( array(
			'post_type' => 'ote_booking',
			'post_status' => 'private',
			'post_title' => sprintf( '%s — %s', $name, get_the_title( $tour_id ) ),
		), true );
		if ( is_wp_error( $booking_id ) ) {
			wp_die( esc_html__( 'We could not save your request. Please try again later.', 'opentrip-engine' ), 500 );
		}
		update_post_meta( $booking_id, '_ote_tour_id', $tour_id );
		update_post_meta( $booking_id, '_ote_customer_name', $name );
		update_post_meta( $booking_id, '_ote_customer_email', $email );
		update_post_meta( $booking_id, '_ote_departure_id', $departure_id );
		update_post_meta( $booking_id, '_ote_travel_date', $departure->start_date );
		update_post_meta( $booking_id, '_ote_travellers', $travellers );
		update_post_meta( $booking_id, '_ote_customer_message', $message );
		update_post_meta( $booking_id, '_ote_booking_status', 'pending' );

		$admin_email = get_option( 'admin_email' );
		if ( $admin_email && is_email( $admin_email ) ) {
			$subject = sprintf( __( 'New booking request: %s', 'opentrip-engine' ), get_the_title( $tour_id ) );
			$body = sprintf(
				__( "A new booking request was received.\n\nTour: %1$s\nName: %2$s\nEmail: %3$s\nTravel date: %4$s\nTravellers: %5$d\n\nReview it in your WordPress dashboard.", 'opentrip-engine' ),
				get_the_title( $tour_id ), $name, $email, $date, $travellers
			);
			wp_mail( $admin_email, $subject, $body );
		}

		$referer = wp_get_referer();
		$redirect = $referer ? $referer : get_permalink( $tour_id );
		wp_safe_redirect( add_query_arg( 'ote_booking', 'received', $redirect ) );
		exit;
	}

	public function add_booking_meta_box() {
		add_meta_box( 'ote_booking_details', __( 'Booking Details', 'opentrip-engine' ), array( $this, 'render_booking_meta_box' ), 'ote_booking', 'normal', 'high' );
		add_meta_box( 'ote_booking_status', __( 'Request Status', 'opentrip-engine' ), array( $this, 'render_booking_status_box' ), 'ote_booking', 'side', 'high' );
	}

	public function render_booking_meta_box( $post ) {
		$status = get_post_meta( $post->ID, '_ote_booking_status', true );
		$fields = array(
			__( 'Status', 'opentrip-engine' ) => $this->booking_status_label( $status ),
			__( 'Tour', 'opentrip-engine' ) => get_the_title( (int) get_post_meta( $post->ID, '_ote_tour_id', true ) ),
			__( 'Customer', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_customer_name', true ),
			__( 'Email', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_customer_email', true ),
			__( 'Travel date', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_travel_date', true ),
			__( 'Travellers', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_travellers', true ),
			__( 'Message', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_customer_message', true ),
		);
		foreach ( $fields as $label => $value ) {
			echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</p>';
		}
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
		if ( ! isset( $_POST['ote_booking_status_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ote_booking_status_nonce'] ) ), 'ote_save_booking_status' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$status = isset( $_POST['ote_booking_status'] ) ? sanitize_key( wp_unslash( $_POST['ote_booking_status'] ) ) : 'pending';
		$allowed = array( 'pending', 'confirmed', 'cancelled' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return;
		}
		$previous = get_post_meta( $post_id, '_ote_booking_status', true );
		$departure_id = (int) get_post_meta( $post_id, '_ote_departure_id', true );
		$seats = (int) get_post_meta( $post_id, '_ote_travellers', true );
		$inventory = OTE_Departures::instance();
		if ( 'confirmed' === $status && 'confirmed' !== $previous && ! $inventory->reserve( $departure_id, (int) get_post_meta( $post_id, '_ote_tour_id', true ), $seats ) ) {
			set_transient( 'ote_inventory_error_' . get_current_user_id(), __( 'Not enough seats remain for this departure. The booking was not confirmed.', 'opentrip-engine' ), 30 );
			return;
		}
		if ( ( 'cancelled' === $status || 'pending' === $status ) && 'confirmed' === $previous ) {
			$inventory->release( $departure_id, $seats );
		}
		update_post_meta( $post_id, '_ote_booking_status', $status );
	}

	public function inventory_admin_notice() {
		$key = 'ote_inventory_error_' . get_current_user_id();
		$message = get_transient( $key );
		if ( $message ) {
			delete_transient( $key );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
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
}

OpenTrip_Engine::instance();
register_activation_hook( __FILE__, array( 'OpenTrip_Engine', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OpenTrip_Engine', 'deactivate' ) );
