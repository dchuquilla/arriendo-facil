<?php
/**
 * Matching engine for ranking accommodations against a tenant's search context.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Matching_Engine
 *
 * Scores accommodations (0-100) combining distance, price fit and property
 * reputation (tenant_to_property review average). Used to rank "relevance"
 * search results instead of a pure distance sort.
 */
class Arriendo_Facil_Matching_Engine {

	/**
	 * Relative weight of each scoring component. Must sum to 1.0.
	 *
	 * @var array<string,float>
	 */
	const WEIGHTS = array(
		'distance' => 0.40,
		'price'    => 0.35,
		'reviews'  => 0.25,
	);

	/**
	 * Scores a single accommodation against the search context.
	 *
	 * @param array<string,mixed> $accommodation Accommodation data (from build_accommodation_from_row()).
	 * @param array<string,mixed> $context {
	 *     @type float|null $latitude   Searcher latitude.
	 *     @type float|null $longitude  Searcher longitude.
	 *     @type float      $radius_km  Search radius used for distance normalization.
	 *     @type float      $price_min  Requested budget lower bound.
	 *     @type float      $price_max  Requested budget upper bound.
	 * }
	 * @return float Score between 0 and 100.
	 */
	public static function score( array $accommodation, array $context ) {
		$distance_score = self::distance_score( $accommodation, $context );
		$price_score    = self::price_score( $accommodation, $context );
		$review_score   = self::review_score( $accommodation );

		$total = ( $distance_score * self::WEIGHTS['distance'] )
			+ ( $price_score * self::WEIGHTS['price'] )
			+ ( $review_score * self::WEIGHTS['reviews'] );

		return round( max( 0, min( 100, $total ) ), 2 );
	}

	/**
	 * Ranks a list of accommodations by match score, descending.
	 * Attaches a `match_score` key to each accommodation for transparency.
	 *
	 * @param array<int,array<string,mixed>> $accommodations Accommodation list.
	 * @param array<string,mixed>            $context        Search context (see score()).
	 * @return array<int,array<string,mixed>>
	 */
	public static function rank( array $accommodations, array $context ) {
		foreach ( $accommodations as &$accommodation ) {
			$accommodation['match_score'] = self::score( $accommodation, $context );
		}
		unset( $accommodation );

		usort(
			$accommodations,
			static function ( $a, $b ) {
				return $b['match_score'] <=> $a['match_score'];
			}
		);

		return $accommodations;
	}

	/**
	 * Scores proximity to the searcher. Neutral (60) when no coordinates
	 * are available to compare, since distance can't be penalized fairly.
	 *
	 * @param array<string,mixed> $accommodation Accommodation data.
	 * @param array<string,mixed> $context       Search context.
	 * @return float Score between 0 and 100.
	 */
	private static function distance_score( array $accommodation, array $context ) {
		$searcher_lat = $context['latitude'] ?? null;
		$searcher_lng = $context['longitude'] ?? null;
		$radius_km    = ! empty( $context['radius_km'] ) ? (float) $context['radius_km'] : 25.0;

		if ( ! is_numeric( $searcher_lat ) || ! is_numeric( $searcher_lng )
			|| empty( $accommodation['latitude'] ) || empty( $accommodation['longitude'] ) ) {
			return 60.0;
		}

		$distance_km = self::haversine_distance(
			(float) $searcher_lat,
			(float) $searcher_lng,
			(float) $accommodation['latitude'],
			(float) $accommodation['longitude']
		);

		if ( $distance_km <= 0 ) {
			return 100.0;
		}

		// Linear decay: 0km = 100, radius_km = 0.
		$score = 100.0 * ( 1 - ( $distance_km / $radius_km ) );

		return max( 0.0, min( 100.0, $score ) );
	}

	/**
	 * Scores how well the accommodation price fits the requested budget range.
	 * Neutral (60) when no budget range was requested.
	 *
	 * @param array<string,mixed> $accommodation Accommodation data.
	 * @param array<string,mixed> $context       Search context.
	 * @return float Score between 0 and 100.
	 */
	private static function price_score( array $accommodation, array $context ) {
		$price_min = isset( $context['price_min'] ) ? (float) $context['price_min'] : 0.0;
		$price_max = isset( $context['price_max'] ) ? (float) $context['price_max'] : 0.0;
		$price     = isset( $accommodation['price'] ) ? (float) $accommodation['price'] : 0.0;

		if ( $price_max <= 0 || $price_max >= 999999 ) {
			return 60.0;
		}

		if ( $price <= 0 ) {
			return 50.0;
		}

		$budget_span = max( 1.0, $price_max - $price_min );
		$midpoint    = $price_min + ( $budget_span / 2 );
		$deviation   = abs( $price - $midpoint ) / $budget_span;

		// Closer to the midpoint of the requested range scores higher.
		$score = 100.0 * ( 1 - $deviation );

		return max( 0.0, min( 100.0, $score ) );
	}

	/**
	 * Scores the accommodation's reputation using its tenant_to_property
	 * review average (1-5 stars normalized to 0-100). Neutral (55) when the
	 * property has no completed reviews yet, so new listings aren't buried.
	 *
	 * @param array<string,mixed> $accommodation Accommodation data.
	 * @return float Score between 0 and 100.
	 */
	private static function review_score( array $accommodation ) {
		$avg_stars   = isset( $accommodation['review_avg'] ) ? (float) $accommodation['review_avg'] : 0.0;
		$review_count = isset( $accommodation['review_count'] ) ? (int) $accommodation['review_count'] : 0;

		if ( $review_count <= 0 || $avg_stars <= 0 ) {
			return 55.0;
		}

		return max( 0.0, min( 100.0, ( $avg_stars / 5 ) * 100 ) );
	}

	/**
	 * Calculates distance between two coordinates using the Haversine formula.
	 *
	 * @param float $lat1 Latitude 1.
	 * @param float $lon1 Longitude 1.
	 * @param float $lat2 Latitude 2.
	 * @param float $lon2 Longitude 2.
	 * @return float Distance in kilometers.
	 */
	private static function haversine_distance( $lat1, $lon1, $lat2, $lon2 ) {
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
