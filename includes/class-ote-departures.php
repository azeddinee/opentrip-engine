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
	private $reservations_table;

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

	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ote_departures';
		$this->reservations_table = $wpdb->prefix . 'ote_booking_reservations';
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_ote_create_departure', array( $this, 'create_departure' ) );
		add_action( 'admin_post_ote_set_departure_status', array( $this, 'set_departure_status_from_admin' ) );
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
		$reservations_table = $wpdb->prefix . 'ote_booking_reservations';
		$reservations_sql = "CREATE TABLE {$reservations_table} (
			booking_id bigint(20) unsigned NOT NULL,
			departure_id bigint(20) unsigned NOT NULL,
			seats int(10) unsigned NOT NULL,
			reserved_at datetime NOT NULL,
			PRIMARY KEY  (booking_id),
			KEY departure_id (departure_id)
		) {$charset} ENGINE=InnoDB;";
		dbDelta( $reservations_sql );
		update_option( 'ote_departures_db_version', '2' );
	}

	public function maybe_install() {
		if ( '2' !== get_option( 'ote_departures_db_version' ) ) {
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
			<?php if ( isset( $_GET['created'] ) && '1' === sanitize_key( wp_unslash( $_GET['created'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Departure created successfully.', 'opentrip-engine' ); ?></p></div>
			<?php elseif ( isset( $_GET['created'] ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'The departure could not be created. Please review the details and try again.', 'opentrip-engine' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['updated'] ) && '1' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Departure status updated.', 'opentrip-engine' ); ?></p></div>
			<?php elseif ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'The departure status could not be updated. It may be fully booked or already past.', 'opentrip-engine' ); ?></p></div>
			<?php endif; ?>
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
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Tour', 'opentrip-engine' ); ?></th><th><?php esc_html_e( 'Dates', 'opentrip-engine' ); ?></th><th><?php esc_html_e( 'Seats', 'opentrip-engine' ); ?></th><th><?php esc_html_e( 'Price', 'opentrip-engine' ); ?></th><th><?php esc_html_e( 'Status', 'opentrip-engine' ); ?></th><th><?php esc_html_e( 'Actions', 'opentrip-engine' ); ?></th></tr></thead><tbody>
			<?php if ( $departures ) : foreach ( $departures as $departure ) : ?>
				<tr><td><?php echo esc_html( get_the_title( $departure->tour_id ) ); ?></td><td><?php echo esc_html( $departure->start_date . ' – ' . $departure->end_date ); ?></td><td><?php echo esc_html( max( 0, (int) $departure->capacity - (int) $departure->reserved ) . ' / ' . (int) $departure->capacity ); ?></td><td><?php echo esc_html( number_format_i18n( (float) $departure->price, 2 ) . ' ' . $departure->currency ); ?></td><td><?php echo esc_html( ucfirst( $departure->status ) ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="ote_set_departure_status"><input type="hidden" name="departure_id" value="<?php echo esc_attr( $departure->id ); ?>"><input type="hidden" name="status" value="<?php echo esc_attr( 'open' === $departure->status ? 'closed' : 'open' ); ?>"><?php wp_nonce_field( 'ote_set_departure_status_' . $departure->id, 'ote_departure_status_nonce' ); ?><button type="submit" class="button button-small"><?php echo esc_html( 'open' === $departure->status ? __( 'Close', 'opentrip-engine' ) : __( 'Reopen', 'opentrip-engine' ) ); ?></button></form></td></tr>
			<?php endforeach; else : ?><tr><td colspan="6"><?php esc_html_e( 'No departures created yet.', 'opentrip-engine' ); ?></td></tr><?php endif; ?>
			</tbody></table>
		</div>
		<?php
	}

	public function create_departure() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'opentrip-engine' ), '', array( 'response' => 403 ) );
		}
		if ( ! is_string( isset( $_POST['ote_departure_nonce'] ) ? $_POST['ote_departure_nonce'] : null ) || ! wp_verify_nonce( $this->posted_string( 'ote_departure_nonce' ), 'ote_create_departure' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'opentrip-engine' ), '', array( 'response' => 403 ) );
		}
		$tour_id = absint( $this->posted_string( 'tour_id' ) );
		$start = sanitize_text_field( $this->posted_string( 'start_date' ) );
		$end = sanitize_text_field( $this->posted_string( 'end_date' ) );
		$capacity = absint( $this->posted_string( 'capacity' ) );
		$price = (float) $this->posted_string( 'price' );
		$currency = strtoupper( sanitize_text_field( $this->posted_string( 'currency' ) ) );
		$start_obj = DateTime::createFromFormat( '!Y-m-d', $start );
		$end_obj = DateTime::createFromFormat( '!Y-m-d', $end );
		if ( 'ote_tour' !== get_post_type( $tour_id ) || 'publish' !== get_post_status( $tour_id ) || ! $start_obj || $start_obj->format( 'Y-m-d' ) !== $start || ! $end_obj || $end_obj->format( 'Y-m-d' ) !== $end || $end < $start || $start < wp_date( 'Y-m-d' ) || $capacity < 1 || $capacity > 10000 || ! is_finite( $price ) || $price < 0 || $price > 9999999999.99 || ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			wp_die( esc_html__( 'Please provide valid tour, dates, capacity and price details.', 'opentrip-engine' ), '', array( 'response' => 400 ) );
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


	/**
	 * Update a departure's public booking status.
	 *
	 * @param int    $departure_id Departure ID.
	 * @param string $status       open or closed.
	 * @return bool Whether the row was updated.
	 */
	public function set_status( $departure_id, $status ) {
		global $wpdb;
		$departure_id = absint( $departure_id );
		if ( ! $departure_id || ! in_array( $status, array( 'open', 'closed' ), true ) ) {
			return false;
		}
		if ( 'closed' === $status ) {
			$changed = $wpdb->query( $wpdb->prepare(
				"UPDATE {$this->table} SET status = 'closed' WHERE id = %d AND status = 'open'",
				$departure_id
			) );
		} else {
			$changed = $wpdb->query( $wpdb->prepare(
				"UPDATE {$this->table} SET status = 'open' WHERE id = %d AND status = 'closed' AND start_date >= %s AND reserved < capacity",
				$departure_id,
				wp_date( 'Y-m-d' )
			) );
		}
		return 1 === $changed;
	}

	public function set_departure_status_from_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'opentrip-engine' ), '', array( 'response' => 403 ) );
		}
		$departure_id = absint( $this->posted_string( 'departure_id' ) );
		if ( ! is_string( isset( $_POST['ote_departure_status_nonce'] ) ? $_POST['ote_departure_status_nonce'] : null ) || ! wp_verify_nonce( $this->posted_string( 'ote_departure_status_nonce' ), 'ote_set_departure_status_' . $departure_id ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'opentrip-engine' ), '', array( 'response' => 403 ) );
		}
		$status = sanitize_key( $this->posted_string( 'status' ) );
		$updated = $this->set_status( $departure_id, $status );
		$redirect = add_query_arg(
			array(
				'post_type' => 'ote_tour',
				'page'      => 'ote-departures',
				'updated'   => $updated ? 1 : 0,
			),
			admin_url( 'edit.php' )
		);
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


	/**
	 * Atomically transition a booking status and its seat reservation.
	 *
	 * The booking status row is locked first so two administrators cannot
	 * reserve seats twice for the same booking. Inventory changes, reservation
	 * ledger writes and the status update share one database transaction.
	 *
	 * @return array|false Previous status on success, false on failure.
	 */
	public function transition_booking( $booking_id, $tour_id, $departure_id, $seats, $new_status ) {
		global $wpdb;

		$booking_id = absint( $booking_id );
		$tour_id = absint( $tour_id );
		$departure_id = absint( $departure_id );
		$seats = absint( $seats );
		if ( ! $booking_id || ! $tour_id || ! $departure_id || ! $seats || ! in_array( $new_status, array( 'pending', 'confirmed', 'cancelled' ), true ) ) {
			return false;
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}

		$status_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1 FOR UPDATE",
			$booking_id,
			'_ote_booking_status'
		) );
		if ( ! $status_row ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		$previous = $status_row->meta_value ? $status_row->meta_value : 'pending';
		if ( $previous === $new_status ) {
			$wpdb->query( 'COMMIT' );
			return array( 'previous' => $previous, 'changed' => false );
		}

		$reservation = $wpdb->get_row( $wpdb->prepare(
			"SELECT departure_id, seats FROM {$this->reservations_table} WHERE booking_id = %d FOR UPDATE",
			$booking_id
		) );

		if ( 'confirmed' === $new_status && 'confirmed' !== $previous ) {
			if ( $reservation ) {
				if ( (int) $reservation->departure_id !== $departure_id || (int) $reservation->seats !== $seats ) {
					$wpdb->query( 'ROLLBACK' );
					return false;
				}
			} else {
				$inserted = $wpdb->insert(
					$this->reservations_table,
					array(
						'booking_id'   => $booking_id,
						'departure_id' => $departure_id,
						'seats'        => $seats,
						'reserved_at'  => current_time( 'mysql', true ),
					),
					array( '%d', '%d', '%d', '%s' )
				);
				if ( false === $inserted ) {
					$wpdb->query( 'ROLLBACK' );
					return false;
				}
				$changed = $wpdb->query( $wpdb->prepare(
					"UPDATE {$this->table} SET reserved = reserved + %d WHERE id = %d AND tour_id = %d AND status = 'open' AND start_date >= %s AND reserved + %d <= capacity",
					$seats, $departure_id, $tour_id, wp_date( 'Y-m-d' ), $seats
				) );
				if ( 1 !== $changed ) {
					$wpdb->query( 'ROLLBACK' );
					return false;
				}
			}
		} elseif ( in_array( $new_status, array( 'pending', 'cancelled' ), true ) && 'confirmed' === $previous ) {
			if ( $reservation ) {
				$release_departure = (int) $reservation->departure_id;
				$release_seats = (int) $reservation->seats;
				$changed = $wpdb->query( $wpdb->prepare(
					"UPDATE {$this->table} SET reserved = reserved - %d WHERE id = %d AND reserved >= %d",
					$release_seats, $release_departure, $release_seats
				) );
				if ( 1 !== $changed ) {
					$wpdb->query( 'ROLLBACK' );
					return false;
				}
				$deleted = $wpdb->delete( $this->reservations_table, array( 'booking_id' => $booking_id ), array( '%d' ) );
				if ( 1 !== $deleted ) {
					$wpdb->query( 'ROLLBACK' );
					return false;
				}
			} else {
				// Compatibility path for confirmed bookings created before the reservation ledger existed.
				$changed = $wpdb->query( $wpdb->prepare(
					"UPDATE {$this->table} SET reserved = reserved - %d WHERE id = %d AND reserved >= %d",
					$seats, $departure_id, $seats
				) );
				if ( 1 !== $changed ) {
					$wpdb->query( 'ROLLBACK' );
					return false;
				}
			}
		}

		$updated = $wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => $new_status ),
			array( 'meta_id' => (int) $status_row->meta_id ),
			array( '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		$wpdb->query( 'COMMIT' );
		wp_cache_delete( $booking_id, 'post_meta' );
		return array( 'previous' => $previous, 'changed' => true );
	}

	public function reserve( $departure_id, $tour_id, $seats ) {
		global $wpdb;
		$seats = absint( $seats );
		if ( ! $departure_id || ! $tour_id || ! $seats ) {
			return false;
		}
		$changed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$this->table} SET reserved = reserved + %d WHERE id = %d AND tour_id = %d AND status = 'open' AND start_date >= %s AND reserved + %d <= capacity",
			$seats, $departure_id, $tour_id, wp_date( 'Y-m-d' ), $seats
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
