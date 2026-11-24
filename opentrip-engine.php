<?php
/**
 * Plugin Name: OpenTrip Engine
 * Description: A lightweight, open-source tour catalogue and booking-request engine for WordPress.
 * Version: 1.0.0-beta.2
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: opentrip-engine
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OTE_VERSION', '1.0.0-beta.2' );
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
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_privacy_eraser' ) );
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
		if ( ! isset( $_POST['ote_itinerary_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ote_itinerary_nonce'] ) ), 'ote_save_itinerary' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$json = isset( $_POST['ote_itinerary_json'] ) ? wp_unslash( $_POST['ote_itinerary_json'] ) : '';
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
				$row[ $field ] = isset( $item[ $field ] ) ? sanitize_textarea_field( $item[ $field ] ) : '';
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
		if ( ! isset( $_POST['ote_inclusions_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ote_inclusions_nonce'] ) ), 'ote_save_inclusions' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array( 'inclusions', 'exclusions' ) as $type ) {
			$field = 'ote_' . $type;
			$meta_key = '_ote_' . $type;
			$raw = isset( $_POST[ $field ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) : '';
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
		wp_add_inline_script( 'jquery', "
		jQuery(function($){
			var frame;
			$('#ote_gallery_select').on('click', function(e){
				e.preventDefault();
				if(frame){frame.open();return;}
				frame=wp.media({title:'Select tour photos',button:{text:'Use selected photos'},multiple:true,library:{type:'image'}});
				frame.on('open',function(){
					var ids=$('#ote_gallery_ids').val().split(',').filter(Boolean);
					var selection=frame.state().get('selection'); selection.reset();
					ids.forEach(function(id){var attachment=wp.media.attachment(id);attachment.fetch();selection.add(attachment);});
				});
				frame.on('select',function(){
					var selection=frame.state().get('selection'), ids=[], html='';
					selection.each(function(attachment){var a=attachment.toJSON();ids.push(a.id);html+='<span class=\"ote-gallery-admin-thumb\"><img src=\"'+(a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url)+'\" alt=\"\"></span>';});
					$('#ote_gallery_ids').val(ids.join(','));
					$('#ote_gallery_preview').html(html);
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
		if ( ! isset( $_POST['ote_gallery_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ote_gallery_nonce'] ) ), 'ote_save_gallery' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw = isset( $_POST['ote_gallery_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ote_gallery_ids'] ) ) : '';
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
			<?php foreach ( $ids as $id ) : ?>
			<figure class="ote-gallery-item"><?php echo wp_get_attachment_link( absint( $id ), $size, false, false, array( 'class' => 'ote-gallery-link' ) ); ?></figure>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
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
			echo '<p class="ote-success" role="status">' . esc_html__( 'Thank you. Your booking request was received. It is not confirmed until the travel agency contacts you.', 'opentrip-engine' ) . '</p>';
			if ( isset( $_GET['ote_ref'] ) ) {
				echo '<p class="ote-booking-reference"><strong>' . esc_html__( 'Your request reference:', 'opentrip-engine' ) . '</strong> ' . esc_html( sanitize_text_field( wp_unslash( $_GET['ote_ref'] ) ) ) . '</p>';
			}
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
			<p><label><?php esc_html_e( 'Adults', 'opentrip-engine' ); ?><br><input required type="number" name="ote_adults" min="1" max="30" value="1"></label></p>
			<p><label><?php esc_html_e( 'Children', 'opentrip-engine' ); ?><br><input required type="number" name="ote_children" min="0" max="30" value="0"></label></p>
			<p><label><?php esc_html_e( 'Rooms required', 'opentrip-engine' ); ?><br><input required type="number" name="ote_rooms" min="1" max="20" value="1"></label></p>
			<p class="ote-price-note"><?php esc_html_e( 'The displayed departure price is applied per traveller. Room selection is recorded for the agency and does not change the quote.', 'opentrip-engine' ); ?></p>
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
		$adults = isset( $_POST['ote_adults'] ) ? absint( $_POST['ote_adults'] ) : 0;
		$children = isset( $_POST['ote_children'] ) ? absint( $_POST['ote_children'] ) : 0;
		$rooms = isset( $_POST['ote_rooms'] ) ? absint( $_POST['ote_rooms'] ) : 0;
		$travellers = $adults + $children;
		$message = isset( $_POST['ote_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ote_message'] ) ) : '';
		$departure = OTE_Departures::instance()->get_departure( $departure_id, $tour_id );
		if ( ! $name || ! is_email( $email ) || ! $departure || $adults < 1 || $children > 29 || $travellers > 30 || $rooms < 1 || $rooms > 20 || (int) $departure->capacity - (int) $departure->reserved < $travellers ) {
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
		update_post_meta( $booking_id, '_ote_adults', $adults );
		update_post_meta( $booking_id, '_ote_children', $children );
		update_post_meta( $booking_id, '_ote_rooms', $rooms );
		update_post_meta( $booking_id, '_ote_travellers', $travellers );
		update_post_meta( $booking_id, '_ote_unit_price', (float) $departure->price );
		update_post_meta( $booking_id, '_ote_currency', $departure->currency );
		update_post_meta( $booking_id, '_ote_total_price', round( $travellers * (float) $departure->price, 2 ) );
		update_post_meta( $booking_id, '_ote_customer_message', $message );
		update_post_meta( $booking_id, '_ote_booking_status', 'pending' );
		$reference = sprintf( 'OTE-%06d', $booking_id );
		update_post_meta( $booking_id, '_ote_booking_reference', $reference );

		$admin_email = get_option( 'admin_email' );
		$this->send_customer_booking_email( $booking_id, 'pending' );

		if ( $admin_email && is_email( $admin_email ) ) {
			$subject = sprintf( __( 'New booking request: %s', 'opentrip-engine' ), get_the_title( $tour_id ) );
			$body = sprintf(
				__( "A new booking request was received.\n\nReference: %1$s\nTour: %2$s\nName: %3$s\nEmail: %4$s\nTravel date: %5$s\nTravellers: %6$d\n\nReview it in your WordPress dashboard.", 'opentrip-engine' ),
				$reference, get_the_title( $tour_id ), $name, $email, $departure->start_date, $travellers
			);
			wp_mail( $admin_email, $subject, $body );
		}

		$referer = wp_get_referer();
		$redirect = $referer ? $referer : get_permalink( $tour_id );
		wp_safe_redirect( add_query_arg( array( 'ote_booking' => 'received', 'ote_ref' => $reference ), $redirect ) );
		exit;
	}

	public function add_booking_meta_box() {
		add_meta_box( 'ote_booking_details', __( 'Booking Details', 'opentrip-engine' ), array( $this, 'render_booking_meta_box' ), 'ote_booking', 'normal', 'high' );
		add_meta_box( 'ote_booking_status', __( 'Request Status', 'opentrip-engine' ), array( $this, 'render_booking_status_box' ), 'ote_booking', 'side', 'high' );
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
			__( 'Rooms', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_rooms', true ),
			__( 'Total travellers', 'opentrip-engine' ) => get_post_meta( $post->ID, '_ote_travellers', true ),
			__( 'Estimated total', 'opentrip-engine' ) => number_format_i18n( (float) get_post_meta( $post->ID, '_ote_total_price', true ), 2 ) . ' ' . get_post_meta( $post->ID, '_ote_currency', true ),
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
			if ( ! $inventory->release( $departure_id, $seats ) ) {
				set_transient( 'ote_inventory_error_' . get_current_user_id(), __( 'The reserved seats could not be released. The booking status was not changed; please check the departure inventory.', 'opentrip-engine' ), 30 );
				return;
			}
		}
		update_post_meta( $post_id, '_ote_booking_status', $status );
		if ( $status !== $previous ) {
			$this->send_customer_booking_email( $post_id, $status );
		}
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
				'meta_value'     => sanitize_email( $email_address ),
			) );
			if ( count( $ids ) === $limit ) {
				$has_more = true;
			}
			foreach ( $ids as $post_id ) {
				if ( 'ote_booking' === $post_type ) {
					$items = array(
						'Booking reference' => get_post_meta( $post_id, '_ote_booking_reference', true ),
						'Tour'              => get_the_title( (int) get_post_meta( $post_id, '_ote_tour_id', true ) ),
					'Customer name'      => get_post_meta( $post_id, '_ote_customer_name', true ),
					'Email address'      => get_post_meta( $post_id, '_ote_customer_email', true ),
					'Travel date'        => get_post_meta( $post_id, '_ote_travel_date', true ),
					'Adults'             => get_post_meta( $post_id, '_ote_adults', true ),
					'Children'           => get_post_meta( $post_id, '_ote_children', true ),
					'Rooms'              => get_post_meta( $post_id, '_ote_rooms', true ),
					'Customer message'   => get_post_meta( $post_id, '_ote_customer_message', true ),
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
				'meta_value'     => sanitize_email( $email_address ),
			) );
			if ( count( $ids ) === $limit ) {
				$has_more = true;
			}
			foreach ( $ids as $post_id ) {
				if ( 'ote_booking' === $post_type ) {
					update_post_meta( $post_id, '_ote_customer_name', __( 'Anonymized customer', 'opentrip-engine' ) );
					update_post_meta( $post_id, '_ote_customer_email', '' );
					update_post_meta( $post_id, '_ote_customer_message', '' );
					wp_update_post( array( 'ID' => $post_id, 'post_title' => __( 'Anonymized booking request', 'opentrip-engine' ) ) );
				} else {
					update_post_meta( $post_id, '_ote_review_name', __( 'Anonymous', 'opentrip-engine' ) );
					update_post_meta( $post_id, '_ote_review_email', '' );
					wp_update_post( array(
						'ID'           => $post_id,
						'post_title'   => __( 'Anonymized review', 'opentrip-engine' ),
						'post_content' => '',
					) );
				}
				$removed++;
			}
		}
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
	public function send_customer_booking_email( $booking_id, $status ) {
		$email = sanitize_email( get_post_meta( $booking_id, '_ote_customer_email', true ) );
		if ( ! is_email( $email ) ) {
			return;
		}
		$tour_id = (int) get_post_meta( $booking_id, '_ote_tour_id', true );
		$tour_name = get_the_title( $tour_id );
		$reference = get_post_meta( $booking_id, '_ote_booking_reference', true );
		$travel_date = get_post_meta( $booking_id, '_ote_travel_date', true );
		$travellers = (int) get_post_meta( $booking_id, '_ote_travellers', true );
		$total = number_format_i18n( (float) get_post_meta( $booking_id, '_ote_total_price', true ), 2 );
		$currency = get_post_meta( $booking_id, '_ote_currency', true );
		$name = get_post_meta( $booking_id, '_ote_customer_name', true );
		$greeting = sprintf( __( 'Hello %s,', 'opentrip-engine' ), $name );
		$details = sprintf(
			__( "Request reference: %1$s\nTour: %2$s\nDeparture: %3$s\nTravellers: %4$d\nEstimated total: %5$s %6$s", 'opentrip-engine' ),
			$reference, $tour_name, $travel_date, $travellers, $total, $currency
		);
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
		ob_start();
		if ( isset( $_GET['ote_review'] ) && 'received' === sanitize_key( wp_unslash( $_GET['ote_review'] ) ) ) {
			echo '<p class="ote-review-notice" role="status">' . esc_html__( 'Thank you. Your review was submitted and is awaiting moderation.', 'opentrip-engine' ) . '</p>';
		}
		?>
		<form method="post" class="ote-review-form">
			<input type="hidden" name="ote_review_action" value="submit_review">
			<input type="hidden" name="ote_review_tour_id" value="<?php echo esc_attr( $tour_id ); ?>">
			<?php wp_nonce_field( 'ote_submit_review', 'ote_review_nonce' ); ?>
			<p class="ote-review-trap" aria-hidden="true"><label><?php esc_html_e( 'Leave this field empty', 'opentrip-engine' ); ?><input type="text" name="ote_website" tabindex="-1" autocomplete="off"></label></p>
			<p><label><?php esc_html_e( 'Your name', 'opentrip-engine' ); ?><br><input required type="text" name="ote_review_name" maxlength="100" autocomplete="name"></label></p>
			<p><label><?php esc_html_e( 'Email (not displayed publicly)', 'opentrip-engine' ); ?><br><input required type="email" name="ote_review_email" maxlength="190" autocomplete="email"></label></p>
			<p><label><?php esc_html_e( 'Rating', 'opentrip-engine' ); ?><br><select required name="ote_review_rating"><option value=""><?php esc_html_e( 'Choose a rating', 'opentrip-engine' ); ?></option><?php for ( $rating = 5; $rating >= 1; $rating-- ) : ?><option value="<?php echo esc_attr( $rating ); ?>"><?php echo esc_html( sprintf( _n( '%d star', '%d stars', $rating, 'opentrip-engine' ), $rating ) ); ?></option><?php endfor; ?></select></label></p>
			<p><label><?php esc_html_e( 'Your review', 'opentrip-engine' ); ?><br><textarea required name="ote_review_content" rows="5" maxlength="5000"></textarea></label></p>
			<p><button type="submit"><?php esc_html_e( 'Submit review', 'opentrip-engine' ); ?></button></p>
		</form>
		<?php
		return ob_get_clean();
	}

	public function handle_review_submission() {
		if ( empty( $_POST['ote_review_action'] ) || 'submit_review' !== sanitize_key( wp_unslash( $_POST['ote_review_action'] ) ) ) {
			return;
		}
		if ( ! isset( $_POST['ote_review_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ote_review_nonce'] ) ), 'ote_submit_review' ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'opentrip-engine' ), 403 );
		}
		if ( ! empty( $_POST['ote_website'] ) ) {
			wp_die( esc_html__( 'Your review could not be submitted.', 'opentrip-engine' ), 400 );
		}
		$tour_id = isset( $_POST['ote_review_tour_id'] ) ? absint( $_POST['ote_review_tour_id'] ) : 0;
		$name = isset( $_POST['ote_review_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ote_review_name'] ) ) : '';
		$email = isset( $_POST['ote_review_email'] ) ? sanitize_email( wp_unslash( $_POST['ote_review_email'] ) ) : '';
		$rating = isset( $_POST['ote_review_rating'] ) ? absint( $_POST['ote_review_rating'] ) : 0;
		$content = isset( $_POST['ote_review_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ote_review_content'] ) ) : '';
		if ( 'ote_tour' !== get_post_type( $tour_id ) || 'publish' !== get_post_status( $tour_id ) || '' === $name || ! is_email( $email ) || $rating < 1 || $rating > 5 || '' === trim( $content ) || strlen( $content ) > 5000 ) {
			wp_die( esc_html__( 'Please check your name, email, rating and review text.', 'opentrip-engine' ), 400 );
		}
		$review_id = wp_insert_post( array(
			'post_type' => 'ote_review',
			'post_status' => 'pending',
			'post_title' => sprintf( __( 'Review for %1$s by %2$s', 'opentrip-engine' ), get_the_title( $tour_id ), $name ),
			'post_content' => $content,
		), true );
		if ( is_wp_error( $review_id ) ) {
			wp_die( esc_html__( 'We could not save your review. Please try again later.', 'opentrip-engine' ), 500 );
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
					<p class="ote-review-stars" aria-label="<?php echo esc_attr( sprintf( __( '%d out of 5 stars', 'opentrip-engine' ), $rating ) ); ?>"><?php echo esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ); ?></p>
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
