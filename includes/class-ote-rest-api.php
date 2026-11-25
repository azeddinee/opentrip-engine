<?php
/**
 * Read-only public REST API for OpenTrip Engine.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OTE_REST_API {
	const API_NAMESPACE = 'opentrip-engine/v1';

	/**
	 * Register public read-only routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::API_NAMESPACE,
			'/tours',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_tours' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'page'        => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => function( $value ) {
							return is_numeric( $value ) && (int) $value >= 1;
						},
					),
					'per_page'    => array(
						'default'           => 10,
						'sanitize_callback' => 'absint',
						'validate_callback' => function( $value ) {
							return is_numeric( $value ) && (int) $value >= 1 && (int) $value <= 50;
						},
					),
					'destination' => array(
						'sanitize_callback' => 'sanitize_title',
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/tours/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_tour' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
						'validate_callback' => function( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/tours/(?P<id>\d+)/departures',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_departures' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
						'validate_callback' => function( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/destinations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_destinations' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * List published, non-password-protected tours.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_tours( $request ) {
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$args     = array(
			'post_type'        => 'ote_tour',
			'post_status'      => 'publish',
			'has_password'     => false,
			'posts_per_page'   => $per_page,
			'paged'            => $page,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'no_found_rows'    => false,
			'ignore_sticky_posts' => true,
		);

		$destination = sanitize_title( (string) $request->get_param( 'destination' ) );
		if ( '' !== $destination ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'ote_destination',
					'field'    => 'slug',
					'terms'    => $destination,
				),
			);
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::format_tour( $post );
		}

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) (int) $query->max_num_pages );
		$response->header( 'X-OTE-Page', (string) $page );
		return $response;
	}

	/**
	 * Fetch one published tour.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_tour( $request ) {
		$tour = self::get_public_tour( absint( $request['id'] ) );
		if ( is_wp_error( $tour ) ) {
			return $tour;
		}
		return rest_ensure_response( self::format_tour( $tour ) );
	}

	/**
	 * Fetch currently bookable departures for one published tour.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_departures( $request ) {
		$tour_id = absint( $request['id'] );
		$tour    = self::get_public_tour( $tour_id );
		if ( is_wp_error( $tour ) ) {
			return $tour;
		}

		$departures = OTE_Departures::instance()->get_available( $tour_id );
		$items      = array();
		foreach ( $departures as $departure ) {
			$items[] = array(
				'id'             => (int) $departure->id,
				'tour_id'        => (int) $departure->tour_id,
				'start_date'     => (string) $departure->start_date,
				'end_date'       => (string) $departure->end_date,
				'seats_remaining'=> max( 0, (int) $departure->capacity - (int) $departure->reserved ),
				'price'          => (float) $departure->price,
				'currency'       => (string) $departure->currency,
			);
		}
		return rest_ensure_response( $items );
	}

	/**
	 * List destination terms used by published tours.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_destinations() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'ote_destination',
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$items = array();
		foreach ( $terms as $term ) {
			$term_link = get_term_link( $term );
			$items[] = array(
				'id'          => (int) $term->term_id,
				'name'        => (string) $term->name,
				'slug'        => (string) $term->slug,
				'description' => sanitize_textarea_field( $term->description ),
				'count'       => (int) $term->count,
				'link'        => is_wp_error( $term_link ) ? '' : esc_url_raw( $term_link ),
			);
		}
		return rest_ensure_response( $items );
	}

	/**
	 * Return a normalized ISO-style currency code.
	 *
	 * @param mixed $value Stored currency value.
	 * @return string
	 */
	private static function public_currency( $value ) {
		$currency = strtoupper( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) );
		return preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'USD';
	}

	/**
	 * Sanitize a bounded list of public text values.
	 *
	 * @param mixed $values Candidate values.
	 * @param int   $limit Maximum number of values.
	 * @return array
	 */
	private static function clean_text_list( $values, $limit ) {
		if ( ! is_array( $values ) ) {
			return array();
		}
		$clean = array();
		foreach ( array_slice( $values, 0, absint( $limit ) ) as $value ) {
			if ( is_scalar( $value ) ) {
				$text = sanitize_text_field( (string) $value );
				if ( '' !== $text ) {
					$clean[] = $text;
				}
			}
		}
		return $clean;
	}

	/**
	 * Return a tour only when it is public and published.
	 *
	 * @param int $tour_id Tour ID.
	 * @return WP_Post|WP_Error
	 */
	private static function get_public_tour( $tour_id ) {
		$tour = get_post( $tour_id );
		if ( ! $tour || 'ote_tour' !== $tour->post_type || 'publish' !== $tour->post_status || '' !== $tour->post_password ) {
			return new WP_Error(
				'ote_tour_not_found',
				__( 'Tour not found.', 'opentrip-engine' ),
				array( 'status' => 404 )
			);
		}
		return $tour;
	}

	/**
	 * Shape intentionally public tour data; never expose arbitrary post meta.
	 *
	 * @param WP_Post $tour Tour post.
	 * @return array
	 */
	private static function format_tour( $tour ) {
		$tour_id   = (int) $tour->ID;
		$itinerary = get_post_meta( $tour_id, '_ote_itinerary', true );
		$itinerary = is_array( $itinerary ) ? array_slice( $itinerary, 0, 60 ) : array();
		$days      = array();

		foreach ( $itinerary as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$day = array( 'day' => isset( $item['day'] ) ? absint( $item['day'] ) : 0 );
			foreach ( array( 'title', 'city', 'activities', 'overnight', 'meals', 'transport' ) as $field ) {
				$day[ $field ] = isset( $item[ $field ] ) && is_scalar( $item[ $field ] )
					? sanitize_textarea_field( (string) $item[ $field ] )
					: '';
			}
			$days[] = $day;
		}

		$included = get_post_meta( $tour_id, '_ote_inclusions', true );
		$excluded = get_post_meta( $tour_id, '_ote_exclusions', true );
		$included = self::clean_text_list( $included, 100 );
		$excluded = self::clean_text_list( $excluded, 100 );

		$gallery_ids = get_post_meta( $tour_id, '_ote_gallery_ids', true );
		$gallery     = array();
		if ( is_array( $gallery_ids ) ) {
			foreach ( array_slice( $gallery_ids, 0, 40 ) as $attachment_id ) {
				$attachment_id = absint( $attachment_id );
				if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
					continue;
				}
				$image_url = wp_get_attachment_image_url( $attachment_id, 'large' );
				if ( $image_url ) {
					$gallery[] = array(
						'id'  => $attachment_id,
						'url' => esc_url_raw( $image_url ),
						'alt' => sanitize_text_field( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
					);
				}
			}
		}

		$terms = get_the_terms( $tour_id, 'ote_destination' );
		$destinations = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$destinations[] = array(
					'id'   => (int) $term->term_id,
					'name' => sanitize_text_field( $term->name ),
					'slug' => (string) $term->slug,
				);
			}
		}

		$thumbnail_id = get_post_thumbnail_id( $tour_id );
		$featured_image = null;
		if ( $thumbnail_id ) {
			$thumbnail_url = get_the_post_thumbnail_url( $tour_id, 'large' );
			if ( $thumbnail_url ) {
				$featured_image = array(
					'id'  => (int) $thumbnail_id,
					'url' => esc_url_raw( $thumbnail_url ),
					'alt' => sanitize_text_field( (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) ),
				);
			}
		}

		return array(
			'id'             => $tour_id,
			'slug'           => (string) $tour->post_name,
			'title'          => sanitize_text_field( $tour->post_title ),
			'link'           => get_permalink( $tour_id ),
			'excerpt'        => sanitize_text_field( $tour->post_excerpt ? $tour->post_excerpt : wp_trim_words( wp_strip_all_tags( $tour->post_content ), 55 ) ),
			'content'        => wp_kses_post( $tour->post_content ),
			'price'          => (float) get_post_meta( $tour_id, '_ote_price', true ),
			'currency'       => self::public_currency( get_post_meta( $tour_id, '_ote_currency', true ) ),
			'duration'       => sanitize_text_field( (string) get_post_meta( $tour_id, '_ote_duration', true ) ),
			'featured_image' => $featured_image,
			'destinations'   => $destinations,
			'itinerary'      => $days,
			'inclusions'     => $included,
			'exclusions'     => $excluded,
			'gallery'        => $gallery,
		);
	}
}
