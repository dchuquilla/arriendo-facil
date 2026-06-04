<?php
/**
 * Accommodation Search REST API.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Accommodation_Search_API
 *
 * Provides REST endpoint for searching accommodations by location and filters.
 */
class Arriendo_Facil_Accommodation_Search_API {

	/**
	 * Constructor – hooks into WordPress.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Registers the REST endpoint for accommodation search.
	 */
	public function register_rest_route() {
		register_rest_route(
			'af/v1',
			'/accommodations/search',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'search_accommodations' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'location'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'latitude'      => array(
						'type'    => 'number',
						'default' => null,
					),
					'longitude'     => array(
						'type'    => 'number',
						'default' => null,
					),
					'radius_km'     => array(
						'type'    => 'number',
						'default' => 10,
					),
					'price_min'     => array(
						'type'    => 'number',
						'default' => 0,
					),
					'price_max'     => array(
						'type'    => 'number',
						'default' => 999999,
					),
					'bedrooms'      => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'bathrooms'     => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'property_type' => array(
						'type'    => 'string',
						'default' => '',
					),
					'amenities'     => array(
						'type'    => 'array',
						'default' => array(),
					),
					'sort'          => array(
						'type'    => 'string',
						'enum'    => array( 'relevance', 'price', 'newest' ),
						'default' => 'relevance',
					),
					'per_page'      => array(
						'type'    => 'integer',
						'default' => 50,
					),
					'page'          => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);
	}

	/**
	 * Searches accommodations based on provided filters.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response
	 */
	public function search_accommodations( $request ) {
		$location      = sanitize_text_field( $request->get_param( 'location' ) );
		$latitude      = floatval( $request->get_param( 'latitude' ) );
		$longitude     = floatval( $request->get_param( 'longitude' ) );
		$radius_km     = max( 1, min( 50, floatval( $request->get_param( 'radius_km' ) ) ) ) ?: 10;
		$price_min     = max( 0, floatval( $request->get_param( 'price_min' ) ) ) ?: 0;
		$price_max     = max( $price_min, floatval( $request->get_param( 'price_max' ) ) ) ?: 999999;
		$bedrooms      = max( 0, absint( $request->get_param( 'bedrooms' ) ) ) ?: 0;
		$bathrooms     = max( 0, absint( $request->get_param( 'bathrooms' ) ) ) ?: 0;
		$property_type = sanitize_text_field( $request->get_param( 'property_type' ) );
		$amenities     = $request->get_param( 'amenities' );
		$amenities     = is_array( $amenities ) ? array_map( 'sanitize_text_field', $amenities ) : array();
		$sort          = in_array( sanitize_text_field( $request->get_param( 'sort' ) ), array( 'relevance', 'price', 'newest' ), true ) ? sanitize_text_field( $request->get_param( 'sort' ) ) : 'relevance';
		$per_page      = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) ) ?: 50;
		$page          = max( 1, absint( $request->get_param( 'page' ) ) ) ?: 1;

		// Validate coordinates if provided
		if ( is_numeric( $latitude ) ) {
			$latitude = max( -90, min( 90, $latitude ) );
		}
		if ( is_numeric( $longitude ) ) {
			$longitude = max( -180, min( 180, $longitude ) );
		}

		// Cache key based on search parameters
		$cache_key = 'af_search_results_' . md5( wp_json_encode( array(
			'location'      => $location,
			'radius_km'     => $radius_km,
			'price_min'     => $price_min,
			'price_max'     => $price_max,
			'bedrooms'      => $bedrooms,
			'bathrooms'     => $bathrooms,
			'property_type' => $property_type,
			'amenities'     => $amenities,
			'sort'          => $sort,
		) ) );

		// Try to get from cache
		$cached_results = get_transient( $cache_key );
		if ( false !== $cached_results ) {
			return new WP_REST_Response( $cached_results, 200 );
		}

		// Single JOIN query — replaces N×13 individual get_post/get_post_meta calls.
		$raw_rows = $this->fetch_accommodations_with_meta();

		if ( empty( $raw_rows ) ) {
			return new WP_REST_Response(
				array(
					'success' => true,
					'count'   => 0,
					'total'   => 0,
					'page'    => $page,
					'results' => array(),
				),
				200
			);
		}

		$filtered_accommodations = array();

		foreach ( $raw_rows as $row ) {
			$accommodation = $this->build_accommodation_from_row( $row );

			if ( ! $this->matches_filters(
				$accommodation,
				compact( 'location', 'latitude', 'longitude', 'radius_km', 'price_min', 'price_max', 'bedrooms', 'bathrooms', 'property_type', 'amenities' )
			) ) {
				continue;
			}

			$filtered_accommodations[] = $accommodation;
		}

		// Sort results
		$filtered_accommodations = $this->sort_accommodations(
			$filtered_accommodations,
			$sort,
			$latitude,
			$longitude
		);

		// Pagination
		$total = count( $filtered_accommodations );
		$offset = ( $page - 1 ) * $per_page;
		$paginated_results = array_slice( $filtered_accommodations, $offset, $per_page );

		$response = array(
			'success' => true,
			'count'   => count( $paginated_results ),
			'total'   => $total,
			'page'    => $page,
			'results' => $paginated_results,
		);

		// Cache for 10 minutes
		set_transient( $cache_key, $response, 10 * MINUTE_IN_SECONDS );

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Fetches all published accommodations with every needed meta key in a
	 * single LEFT JOIN query, eliminating the N+1 pattern.
	 *
	 * Also primes the WP post object cache (for get_permalink) and the
	 * attachment meta cache (for thumbnail URLs) so subsequent calls are
	 * served entirely from RAM.
	 *
	 * @return stdClass[] Raw result rows from wpdb.
	 */
	private function fetch_accommodations_with_meta() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT
				p.ID,
				p.post_title,
				p.post_name,
				p.post_date,
				MAX(CASE WHEN pm.meta_key = '_af_location_text'         THEN pm.meta_value END) AS location_text,
				MAX(CASE WHEN pm.meta_key = '_af_latitude'              THEN pm.meta_value END) AS latitude,
				MAX(CASE WHEN pm.meta_key = '_af_longitude'             THEN pm.meta_value END) AS longitude,
				MAX(CASE WHEN pm.meta_key = '_af_bedrooms'              THEN pm.meta_value END) AS bedrooms,
				MAX(CASE WHEN pm.meta_key = '_af_bathrooms'             THEN pm.meta_value END) AS bathrooms,
				MAX(CASE WHEN pm.meta_key = '_af_monthly_rent'          THEN pm.meta_value END) AS monthly_rent,
				MAX(CASE WHEN pm.meta_key = '_af_property_type'         THEN pm.meta_value END) AS property_type,
				MAX(CASE WHEN pm.meta_key = '_af_amenities'             THEN pm.meta_value END) AS amenities_raw,
				MAX(CASE WHEN pm.meta_key = '_af_status'                THEN pm.meta_value END) AS status,
				MAX(CASE WHEN pm.meta_key = '_af_commercial_status'     THEN pm.meta_value END) AS commercial_status,
				MAX(CASE WHEN pm.meta_key = '_af_commercial_state'      THEN pm.meta_value END) AS commercial_state,
				MAX(CASE WHEN pm.meta_key = '_af_commercial_visibility' THEN pm.meta_value END) AS commercial_visibility,
				MAX(CASE WHEN pm.meta_key = '_thumbnail_id'             THEN pm.meta_value END) AS thumbnail_id
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm
				ON p.ID = pm.post_id
				AND pm.meta_key IN (
					'_af_location_text','_af_latitude','_af_longitude',
					'_af_bedrooms','_af_bathrooms','_af_monthly_rent',
					'_af_property_type','_af_amenities','_af_status',
					'_af_commercial_status','_af_commercial_state','_af_commercial_visibility',
					'_thumbnail_id'
				)
			WHERE p.post_type = 'accommodation'
			  AND p.post_status = 'publish'
			GROUP BY p.ID, p.post_title, p.post_name, p.post_date
			ORDER BY p.post_date DESC
			LIMIT 500"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( empty( $rows ) ) {
			return array();
		}

		// Prime the WP post object cache from the JOIN rows so that
		// get_permalink() never triggers a DB round-trip.
		foreach ( $rows as $row ) {
			if ( ! wp_cache_get( (int) $row->ID, 'posts' ) ) {
				$post_obj = new WP_Post(
					(object) array(
						'ID'                    => (int) $row->ID,
						'post_author'           => 0,
						'post_date'             => $row->post_date,
						'post_date_gmt'         => '',
						'post_content'          => '',
						'post_title'            => $row->post_title,
						'post_excerpt'          => '',
						'post_status'           => 'publish',
						'comment_status'        => 'closed',
						'ping_status'           => 'closed',
						'post_password'         => '',
						'post_name'             => $row->post_name,
						'to_ping'               => '',
						'pinged'                => '',
						'post_modified'         => $row->post_date,
						'post_modified_gmt'     => '',
						'post_content_filtered' => '',
						'post_parent'           => 0,
						'guid'                  => '',
						'menu_order'            => 0,
						'post_type'             => 'accommodation',
						'post_mime_type'        => '',
						'comment_count'         => 0,
						'filter'                => 'raw',
					)
				);
				wp_cache_add( (int) $row->ID, $post_obj, 'posts' );
			}
		}

		// Batch-prime attachment meta cache for thumbnail URLs.
		$thumbnail_ids = array_values( array_unique( array_filter(
			array_map( function ( $r ) { return absint( $r->thumbnail_id ); }, $rows )
		) ) );

		if ( ! empty( $thumbnail_ids ) ) {
			update_meta_cache( 'post', $thumbnail_ids );
		}

		return $rows;
	}

	/**
	 * Builds the accommodation data array from a JOIN result row.
	 *
	 * Replicates the logic of get_accommodation_data() but reads pre-fetched
	 * column values instead of issuing individual get_post_meta() queries.
	 *
	 * @param stdClass $row JOIN result row from fetch_accommodations_with_meta().
	 * @return array
	 */
	private function build_accommodation_from_row( $row ) {
		$amenities = ! empty( $row->amenities_raw ) ? maybe_unserialize( $row->amenities_raw ) : array();
		if ( ! is_array( $amenities ) ) {
			$amenities = array();
		}

		$status                = sanitize_key( (string) ( $row->status ?? '' ) );
		$commercial_status     = sanitize_key( (string) ( $row->commercial_status ?? '' ) );
		$commercial_state      = sanitize_key( (string) ( $row->commercial_state ?? '' ) );
		$commercial_visibility = sanitize_key( (string) ( $row->commercial_visibility ?? '' ) );

		if ( '' === $commercial_status ) {
			if ( 'private' === $commercial_visibility ) {
				$commercial_status = 'private';
			} elseif ( in_array( $commercial_state, array( 'available', 'reserved', 'rented' ), true ) ) {
				$commercial_status = $commercial_state;
			}
		}
		if ( '' === $commercial_status ) {
			$commercial_status = ( 'rented' === $status ) ? 'rented' : 'available';
		}
		if ( '' === $commercial_state ) {
			$commercial_state = in_array( $commercial_status, array( 'available', 'reserved', 'rented' ), true ) ? $commercial_status : 'available';
		}
		if ( '' === $commercial_visibility ) {
			$commercial_visibility = ( 'private' === $commercial_status ) ? 'private' : 'public';
		}

		// Attachment meta is already in WP cache — no extra DB query.
		$thumbnail_id = absint( $row->thumbnail_id ?? 0 );
		$image_url    = '';
		if ( $thumbnail_id ) {
			$src       = wp_get_attachment_image_src( $thumbnail_id, 'large' );
			$image_url = $src ? (string) $src[0] : '';
		}

		return array(
			'id'                    => (int) $row->ID,
			'title'                 => (string) $row->post_title,
			'location'              => (string) ( $row->location_text ?? '' ),
			'latitude'              => floatval( $row->latitude ?? 0 ),
			'longitude'             => floatval( $row->longitude ?? 0 ),
			'status'                => $status,
			'commercial_status'     => $commercial_status,
			'commercial_state'      => $commercial_state,
			'commercial_visibility' => $commercial_visibility,
			'price'                 => floatval( $row->monthly_rent ?? 0 ),
			'bedrooms'              => absint( $row->bedrooms ?? 0 ),
			'bathrooms'             => absint( $row->bathrooms ?? 0 ),
			'property_type'         => (string) ( $row->property_type ?? '' ),
			'image_url'             => $image_url,
			'url'                   => get_permalink( (int) $row->ID ),
			'amenities'             => $amenities,
		);
	}

	/**
	 * Retrieves formatted accommodation data.
	 *
	 * @param int $accommodation_id Post ID.
	 * @return array|null
	 */
	private function get_accommodation_data( $accommodation_id ) {
		$post = get_post( $accommodation_id );
		if ( ! $post ) {
			return null;
		}

		$location_text = get_post_meta( $accommodation_id, '_af_location_text', true );
		$latitude      = floatval( get_post_meta( $accommodation_id, '_af_latitude', true ) );
		$longitude     = floatval( get_post_meta( $accommodation_id, '_af_longitude', true ) );
		$bedrooms      = absint( get_post_meta( $accommodation_id, '_af_bedrooms', true ) );
		$bathrooms     = absint( get_post_meta( $accommodation_id, '_af_bathrooms', true ) );
		$monthly_price = floatval( get_post_meta( $accommodation_id, '_af_monthly_rent', true ) );
		$property_type = get_post_meta( $accommodation_id, '_af_property_type', true );
		$amenities     = get_post_meta( $accommodation_id, '_af_amenities', true );
		$status              = sanitize_key( (string) get_post_meta( $accommodation_id, '_af_status', true ) );
		$commercial_status   = sanitize_key( (string) get_post_meta( $accommodation_id, '_af_commercial_status', true ) );
		$commercial_state    = sanitize_key( (string) get_post_meta( $accommodation_id, '_af_commercial_state', true ) );
		$commercial_visibility = sanitize_key( (string) get_post_meta( $accommodation_id, '_af_commercial_visibility', true ) );
		if ( ! is_array( $amenities ) ) {
			$amenities = array();
		}

		if ( '' === $commercial_status ) {
			if ( 'private' === $commercial_visibility ) {
				$commercial_status = 'private';
			} elseif ( in_array( $commercial_state, array( 'available', 'reserved', 'rented' ), true ) ) {
				$commercial_status = $commercial_state;
			}
		}

		if ( '' === $commercial_status ) {
			$commercial_status = ( 'rented' === $status ) ? 'rented' : 'available';
		}

		if ( '' === $commercial_state ) {
			$commercial_state = in_array( $commercial_status, array( 'available', 'reserved', 'rented' ), true ) ? $commercial_status : 'available';
		}

		if ( '' === $commercial_visibility ) {
			$commercial_visibility = ( 'private' === $commercial_status ) ? 'private' : 'public';
		}

		$image_url = '';
		if ( has_post_thumbnail( $accommodation_id ) ) {
			$image_url = get_the_post_thumbnail_url( $accommodation_id, 'large' );
		}

		return array(
			'id'             => $accommodation_id,
			'title'          => $post->post_title,
			'location'       => $location_text,
			'latitude'       => $latitude,
			'longitude'      => $longitude,
			'status'         => $status,
			'commercial_status' => $commercial_status,
			'commercial_state' => $commercial_state,
			'commercial_visibility' => $commercial_visibility,
			'price'          => $monthly_price,
			'bedrooms'       => $bedrooms,
			'bathrooms'      => $bathrooms,
			'property_type'  => $property_type,
			'image_url'      => $image_url,
			'url'            => get_permalink( $accommodation_id ),
			'amenities'      => $amenities,
		);
	}

	/**
	 * Checks if accommodation matches search filters.
	 *
	 * @param array $accommodation Accommodation data.
	 * @param array $filters Filter parameters.
	 * @return bool
	 */
	private function matches_filters( $accommodation, $filters ) {
		$location   = $filters['location'] ?? '';
		$latitude   = $filters['latitude'] ?? null;
		$longitude  = $filters['longitude'] ?? null;
		$radius_km  = $filters['radius_km'] ?? 10;
		$price_min  = $filters['price_min'] ?? 0;
		$price_max  = $filters['price_max'] ?? 999999;
		$bedrooms   = $filters['bedrooms'] ?? 0;
		$bathrooms  = $filters['bathrooms'] ?? 0;
		$prop_type  = $filters['property_type'] ?? '';
		$amenities  = $filters['amenities'] ?? array();

		if ( in_array( $accommodation['status'], array( 'inactive', 'maintenance' ), true ) ) {
			return false;
		}

		if ( 'private' === $accommodation['commercial_visibility'] ) {
			return false;
		}

		// Location filter
		if ( $location && stripos( $accommodation['location'], $location ) === false ) {
			return false;
		}

		// Radius filter (haversine formula)
		if ( is_numeric( $latitude ) && is_numeric( $longitude ) && $accommodation['latitude'] && $accommodation['longitude'] ) {
			$distance = $this->haversine_distance(
				$latitude,
				$longitude,
				$accommodation['latitude'],
				$accommodation['longitude']
			);
			if ( $distance > $radius_km ) {
				return false;
			}
		}

		// Price filter
		if ( $accommodation['price'] < $price_min || $accommodation['price'] > $price_max ) {
			return false;
		}

		// Bedrooms filter
		if ( $bedrooms > 0 && $accommodation['bedrooms'] < $bedrooms ) {
			return false;
		}

		// Bathrooms filter
		if ( $bathrooms > 0 && $accommodation['bathrooms'] < $bathrooms ) {
			return false;
		}

		// Property type filter
		if ( $prop_type && $accommodation['property_type'] !== $prop_type ) {
			return false;
		}

		// Amenities filter (all must be present)
		if ( ! empty( $amenities ) ) {
			foreach ( $amenities as $amenity ) {
				if ( ! in_array( $amenity, $accommodation['amenities'], true ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Sorts accommodations by specified criteria.
	 *
	 * @param array  $accommodations Array of accommodations.
	 * @param string $sort_by Sort criteria (relevance, price, newest).
	 * @param float  $latitude Current latitude for distance calculation.
	 * @param float  $longitude Current longitude for distance calculation.
	 * @return array
	 */
	private function sort_accommodations( $accommodations, $sort_by, $latitude = null, $longitude = null ) {
		if ( 'price' === $sort_by ) {
			usort( $accommodations, function( $a, $b ) {
				return floatval( $a['price'] ) <=> floatval( $b['price'] );
			} );
		} elseif ( 'newest' === $sort_by ) {
			// Sorted by post ID descending (newest first) - already in order from query
			usort( $accommodations, function( $a, $b ) {
				return $b['id'] <=> $a['id'];
			} );
		} else {
			// Relevance: sort by distance if coordinates available
			if ( is_numeric( $latitude ) && is_numeric( $longitude ) ) {
				usort( $accommodations, function( $a, $b ) use ( $latitude, $longitude ) {
					$dist_a = $this->haversine_distance( $latitude, $longitude, $a['latitude'], $a['longitude'] );
					$dist_b = $this->haversine_distance( $latitude, $longitude, $b['latitude'], $b['longitude'] );
					return $dist_a <=> $dist_b;
				} );
			}
		}

		return $accommodations;
	}

	/**
	 * Calculates distance between two coordinates using Haversine formula.
	 *
	 * @param float $lat1 Latitude 1.
	 * @param float $lon1 Longitude 1.
	 * @param float $lat2 Latitude 2.
	 * @param float $lon2 Longitude 2.
	 * @return float Distance in kilometers.
	 */
	private function haversine_distance( $lat1, $lon1, $lat2, $lon2 ) {
		$earth_radius_km = 6371;

		$dlat = deg2rad( $lat2 - $lat1 );
		$dlon = deg2rad( $lon2 - $lon1 );

		$a = sin( $dlat / 2 ) * sin( $dlat / 2 ) +
			cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) *
			sin( $dlon / 2 ) * sin( $dlon / 2 );

		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

		return $earth_radius_km * $c;
	}
}
