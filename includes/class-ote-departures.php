<?php
/**
 * Departure inventory service for OpenTrip Engine.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OTE_Departures {
	private static $instance = null;
	private $table;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ote_departures';
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_ote_create_departure', array( $this, 'create_departure' ) );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . 'ote_departures';
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tour_id bigint(20) unsigned NOT NULL,
			start_date date NOT NULL,
			end_date date NOT NULL,
			capacity int(10) unsigned NOT NULL,
			reserved int(10) unsigned NOT NULL DEFAULT 0,
			price decimal(12,2) NOT NULL DEFAULT 0.00,
			currency char(3) NOT NULL DEFAULT 'USD',
			status varchar(20) NOT NULL DEFAULT 'open',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY tour_status_date (tour_id,status,start_date)
		) {$charset};";
		dbDelta( $sql );
		update_option( 'ote_departures_db_version', '1' );
	}

	public function maybe_install() {
		if ( '1' !== get_option( 'ote_departures_db_version' ) ) {
			self::install();
		}
	}

	public function admin_menu() {
		add_submenu_page(
			'edit.php?post_type=ote_tour',
			__( 'Departures', 'opentrip-engine' ),
			__( 'Departures', 'opentrip-engine' ),
			'manage_options',
			'ote-departures',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$departures = $wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY start_date ASC LIMIT 200" );
		$tours = get_posts( array( 'post_type' => 'ote_tour', 'post_status' => 'publish', 'numberposts' => 200 ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tour Departures', 'opentrip-engine' ); ?></h1>
			<p><?php esc_html_e( 'Create dated departures with seat inventory. Only confirmed booking requests consume seats.', 'opentrip-engine' ); ?></p>
			<h2><?php esc_html_e( 'Add departure', 'opentrip-engine' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ote_create_departure">
				<?php wp_nonce_field( 'ote_create_departure', 'ote_departure_nonce' ); ?>
				<table class="form-table"><tbody>
					<tr><th><label for="ote_departure_tour"><?php esc_html_e( 'Tour', 'opentrip-engine' ); ?></label></th><td><select required name="tour_id" id="ote_departure_tour"><option value=""><?php esc_html_e( 'Select a tour', 'opentrip-engine' ); ?></option><?php foreach ( $tours as $tour ) : ?><option value="<?php echo esc_attr( $tour->ID ); ?>"><?php echo esc_html( $tour->post_title ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><label for="ote_start_date"><?php esc_html_e( 'Start date', 'opentrip-engine' ); ?></label></th><td><input required type="date" name="start_date" id="ote_start_date" min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"></td></tr>
					<tr><th><label for="ote_end_date"><?php esc_html_e( 'End date', 'opentrip-engine' ); ?></label></th><td><input required type="date" name="end_date" id="ote_end_date"></td></tr>
					<tr><th><label for="ote_capacity"><?php esc_html_e( 'Total seats', 'opentrip-engine' ); ?></label></th><td><input required type="number" min="1" max="10000" name="capacity" id="ote_capacity" value="12"></td></tr>
					<tr><th><label for="ote_departure_price"><?php esc_html_e( 'Price per traveller', 'opentrip-engine' ); ?></label></th><td><input required type="number" min="0" step="0.01" name="price" id="ote_departure_price" value="0"></td></tr>
					<tr><th><label for="ote_departure_currency"><?php esc_html_e( 'Currency', 'opentrip-engine' ); ?></label></th><td><input required type="text" pattern="[A-Za-z]{3}" maxlength="3" name="currency" id="ote_departure_currency" value="USD"></td></tr>
				</tbody></table>
				<?php submit_button( __( 'Create departure', 'opentrip-engine' ) ); ?>
			</form>
			<hr><h2><?php esc_html_e( 'Existing departures', 'opentrip-engine' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Tour', 'opentrip-engine' ); ?></th><th><?php esc_html_e( 'Dates', 'opentrip-engine' ); ?></th><th><?php esc_html_e( 'Seats', 'opentrip-engine' ); ?></th><th><?php esc_html_e( 'Price', 'opentrip-engine' ); ?></th><th><?php esc_html_e( 'Status', 'opentrip-engine' ); ?></th></tr></thead><tbody>
			<?php if ( $departures ) : foreach ( $departures as $departure ) : ?>
				<tr><td><?php echo esc_html( get_the_title( $departure->tour_id ) ); ?></td><td><?php echo esc_html( $departure->start_date . ' – ' . $departure->end_date ); ?></td><td><?php echo esc_html( max( 0, (int) $departure->capacity - (int) $departure->reserved ) . ' / ' . (int) $departure->capacity ); ?></td><td><?php echo esc_html( number_format_i18n( (float) $departure->price, 2 ) . ' ' . $departure->currency ); ?></td><td><?php echo esc_html( ucfirst( $departure->status ) ); ?></td></tr>
			<?php endforeach; else : ?><tr><td colspan="5"><?php esc_html_e( 'No departures created yet.', 'opentrip-engine' ); ?></td></tr><?php endif; ?>
			</tbody></table>
		</div>
		<?php
	}

	public function create_departure() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'opentrip-engine' ), 403 );
		}
		check_admin_referer( 'ote_create_departure', 'ote_departure_nonce' );
		$tour_id = isset( $_POST['tour_id'] ) ? absint( $_POST['tour_id'] ) : 0;
		$start = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$end = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
		$capacity = isset( $_POST['capacity'] ) ? absint( $_POST['capacity'] ) : 0;
		$price = isset( $_POST['price'] ) ? (float) wp_unslash( $_POST['price'] ) : 0;
		$currency = isset( $_POST['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : '';
		$start_obj = DateTime::createFromFormat( '!Y-m-d', $start );
		$end_obj = DateTime::createFromFormat( '!Y-m-d', $end );
		if ( 'ote_tour' !== get_post_type( $tour_id ) || 'publish' !== get_post_status( $tour_id ) || ! $start_obj || $start_obj->format( 'Y-m-d' ) !== $start || ! $end_obj || $end_obj->format( 'Y-m-d' ) !== $end || $end < $start || $start < wp_date( 'Y-m-d' ) || $capacity < 1 || $capacity > 10000 || $price < 0 || ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			wp_die( esc_html__( 'Please provide valid tour, dates, capacity and price details.', 'opentrip-engine' ), 400 );
		}
		global $wpdb;
		$wpdb->insert( $this->table, array(
			'tour_id' => $tour_id,
			'start_date' => $start,
			'end_date' => $end,
			'capacity' => $capacity,
			'reserved' => 0,
			'price' => $price,
			'currency' => $currency,
			'status' => 'open',
			'created_at' => current_time( 'mysql', true ),
		), array( '%d', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%s' ) );
		$redirect = add_query_arg( array( 'post_type' => 'ote_tour', 'page' => 'ote-departures', 'created' => $wpdb->insert_id ? 1 : 0 ), admin_url( 'edit.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function get_departure( $departure_id, $tour_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT id,tour_id,start_date,end_date,capacity,reserved,price,currency FROM {$this->table} WHERE id = %d AND tour_id = %d AND status = 'open' AND start_date >= %s AND reserved < capacity",
			$departure_id,
			$tour_id,
			wp_date( 'Y-m-d' )
		) );
	}

	public function get_available( $tour_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id,start_date,end_date,capacity,reserved,price,currency FROM {$this->table} WHERE tour_id = %d AND status = 'open' AND start_date >= %s AND reserved < capacity ORDER BY start_date ASC",
			$tour_id,
			wp_date( 'Y-m-d' )
		) );
	}

	public function reserve( $departure_id, $tour_id, $seats ) {
		global $wpdb;
		$seats = absint( $seats );
		if ( ! $departure_id || ! $tour_id || ! $seats ) {
			return false;
		}
		$changed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$this->table} SET reserved = reserved + %d WHERE id = %d AND tour_id = %d AND status = 'open' AND reserved + %d <= capacity",
			$seats, $departure_id, $tour_id, $seats
		) );
		return 1 === $changed;
	}

	public function release( $departure_id, $seats ) {
		global $wpdb;
		$seats = absint( $seats );
		if ( ! $departure_id || ! $seats ) {
			return false;
		}
		$changed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$this->table} SET reserved = reserved - %d WHERE id = %d AND reserved >= %d",
			$seats, $departure_id, $seats
		) );
		return 1 === $changed;
	}
}
