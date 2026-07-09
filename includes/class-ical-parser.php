<?php
/**
 * iCal Parser - Parses .ics files from Booking.com and Airbnb
 *
 * Downloads and parses iCal files to extract occupancy information.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_iCal_Parser
 *
 * Handles downloading and parsing iCal files from OTA platforms.
 */
class Arriendo_Facil_iCal_Parser {

	/**
	 * Downloads and parses an iCal (.ics) file.
	 *
	 * @param string $ical_url URL to the .ics file.
	 * @return array|WP_Error Array with 'is_occupied' and 'booked_dates' or WP_Error.
	 */
	public static function parse_ical_url( $ical_url ) {
		if ( empty( $ical_url ) ) {
			return new WP_Error( 'empty_url', 'iCal URL is empty' );
		}

		// Download the .ics file
		$response = wp_remote_get(
			$ical_url,
			array(
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'download_failed', 'Failed to download iCal file: ' . $response->get_error_message() );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http_code ) {
			return new WP_Error( 'http_error', "HTTP {$http_code} when fetching iCal file" );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return new WP_Error( 'empty_body', 'iCal file is empty' );
		}

		return self::parse_ical_content( $body );
	}

	/**
	 * Parses iCal content (raw .ics format).
	 *
	 * @param string $ical_content Raw iCal content.
	 * @return array Array with occupancy data.
	 */
	public static function parse_ical_content( $ical_content ) {
		$booked_dates = array();
		$today = new DateTime( 'today' );

		// Parse VEVENT blocks
		$events = self::extract_events( $ical_content );

		foreach ( $events as $event ) {
			$summary = self::get_event_property( $event, 'SUMMARY' );
			$dtstart = self::get_event_property( $event, 'DTSTART' );
			$dtend = self::get_event_property( $event, 'DTEND' );

			// Only consider blocked/booked dates (Airbnb uses "Blocked", Booking uses "Booked" or similar)
			if ( ! self::is_booking_event( $summary ) ) {
				continue;
			}

			// Parse dates
			$start_date = self::parse_ical_date( $dtstart );
			$end_date = self::parse_ical_date( $dtend );

			if ( ! $start_date || ! $end_date ) {
				continue;
			}

			// Only include future dates (today or after)
			if ( $end_date < $today ) {
				continue;
			}

			$booked_dates[] = array(
				'from' => $start_date->format( 'Y-m-d' ),
				'to'   => $end_date->format( 'Y-m-d' ),
			);
		}

		// Check if currently occupied (today or near future)
		$is_occupied = self::check_if_currently_occupied( $booked_dates, $today );

		return array(
			'is_occupied'  => $is_occupied,
			'booked_dates' => $booked_dates,
			'updated_at'   => current_time( 'mysql' ),
		);
	}

	/**
	 * Extracts VEVENT blocks from iCal content.
	 *
	 * @param string $ical_content Raw iCal content.
	 * @return array Array of event strings.
	 */
	private static function extract_events( $ical_content ) {
		$events = array();
		$pattern = '/BEGIN:VEVENT(.*?)END:VEVENT/is';

		if ( preg_match_all( $pattern, $ical_content, $matches ) ) {
			$events = $matches[0];
		}

		return $events;
	}

	/**
	 * Gets a property value from a VEVENT block.
	 *
	 * @param string $event VEVENT block.
	 * @param string $property Property name (e.g., SUMMARY, DTSTART).
	 * @return string|null Property value or null.
	 */
	private static function get_event_property( $event, $property ) {
		$pattern = '/' . $property . '(?:;[^:]*)?:([^\r\n]*)/';

		if ( preg_match( $pattern, $event, $matches ) ) {
			return trim( $matches[1] );
		}

		return null;
	}

	/**
	 * Checks if an event summary indicates a booking/blocked date.
	 *
	 * @param string $summary Event summary.
	 * @return bool True if event is a booking.
	 */
	private static function is_booking_event( $summary ) {
		if ( empty( $summary ) ) {
			return false;
		}

		$summary_lower = strtolower( $summary );

		// Airbnb uses "Blocked", "Reserved"
		// Booking uses "Booked", "Occupied"
		// Google Calendar might use "Busy"
		$booking_keywords = array( 'block', 'booked', 'book', 'reserved', 'reserv', 'occupied' );

		foreach ( $booking_keywords as $keyword ) {
			if ( strpos( $summary_lower, $keyword ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parses iCal date format (YYYYMMDD or YYYYMMDDTHHMMSS).
	 *
	 * @param string $date_string Date string in iCal format.
	 * @return DateTime|null Parsed date or null.
	 */
	private static function parse_ical_date( $date_string ) {
		if ( empty( $date_string ) ) {
			return null;
		}

		// Handle TZID format: DTSTART;TZID=America/New_York:20260708T143000
		if ( strpos( $date_string, ':' ) !== false ) {
			$date_string = substr( $date_string, strpos( $date_string, ':' ) + 1 );
		}

		try {
			// Format: YYYYMMDD or YYYYMMDDTHHMMSS
			if ( strlen( $date_string ) === 8 ) {
				// Date only (YYYYMMDD)
				return DateTime::createFromFormat( 'Ymd', $date_string );
			} else {
				// DateTime (YYYYMMDDTHHMMSS)
				return DateTime::createFromFormat( 'YmdHis', $date_string );
			}
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Checks if property is currently occupied based on booked dates.
	 *
	 * @param array    $booked_dates Array of booked date ranges.
	 * @param DateTime $today Today's date.
	 * @return bool True if currently occupied.
	 */
	private static function check_if_currently_occupied( $booked_dates, $today ) {
		foreach ( $booked_dates as $booking ) {
			$from = new DateTime( $booking['from'] );
			$to   = new DateTime( $booking['to'] );

			// Property is occupied if today falls within any booking
			if ( $today >= $from && $today < $to ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Generates an iCal file from accommodations.
	 *
	 * Creates a downloadable .ics file with all occupied dates
	 * so users can import into Booking/Airbnb/etc.
	 *
	 * @param int    $accommodation_id Accommodation post ID.
	 * @param string $timezone Timezone (e.g., 'America/Guayaquil').
	 * @return string iCal file content.
	 */
	public static function generate_ical_for_accommodation( $accommodation_id, $timezone = 'UTC' ) {
		$accommodation_id = absint( $accommodation_id );
		$post = get_post( $accommodation_id );

		if ( ! $post || 'accommodation' !== $post->post_type ) {
			return '';
		}

		$is_occupied = get_post_meta( $accommodation_id, '_af_is_occupied', true );
		$occupied_from = get_post_meta( $accommodation_id, '_af_occupied_from', true );
		$occupied_to = get_post_meta( $accommodation_id, '_af_occupied_to', true );

		// iCal header
		$ical = "BEGIN:VCALENDAR\r\n";
		$ical .= "VERSION:2.0\r\n";
		$ical .= "PRODID:-//ArriendoFácil//Property Management//EN\r\n";
		$ical .= "CALSCALE:GREGORIAN\r\n";
		$ical .= "METHOD:PUBLISH\r\n";
		$ical .= "BEGIN:VTIMEZONE\r\n";
		$ical .= "TZID:{$timezone}\r\n";
		$ical .= "BEGIN:STANDARD\r\n";
		$ical .= "DTSTART:19000101T000000\r\n";
		$ical .= "TZOFFSETFROM:+0000\r\n";
		$ical .= "TZOFFSETTO:+0000\r\n";
		$ical .= "END:STANDARD\r\n";
		$ical .= "END:VTIMEZONE\r\n";

		// Add blocked/booked event if occupied
		if ( $is_occupied && $occupied_from && $occupied_to ) {
			$event_start = self::date_to_ical_format( $occupied_from );
			$event_end = self::date_to_ical_format( $occupied_to );
			$uid = md5( $accommodation_id . $occupied_from . $occupied_to ) . '@arriendofacil.local';

			$ical .= "BEGIN:VEVENT\r\n";
			$ical .= "UID:{$uid}\r\n";
			$ical .= "DTSTAMP:" . self::date_to_ical_format( current_time( 'mysql' ) ) . "Z\r\n";
			$ical .= "DTSTART;TZID={$timezone}:{$event_start}\r\n";
			$ical .= "DTEND;TZID={$timezone}:{$event_end}\r\n";
			$ical .= "SUMMARY:Ocupada - " . $post->post_title . "\r\n";
			$ical .= "DESCRIPTION:Propiedad reservada en ArriendoFácil\r\n";
			$ical .= "STATUS:CONFIRMED\r\n";
			$ical .= "TRANSP:OPAQUE\r\n";
			$ical .= "END:VEVENT\r\n";
		}

		$ical .= "END:VCALENDAR\r\n";

		return $ical;
	}

	/**
	 * Converts date string to iCal format (YYYYMMDDTHHMMSS).
	 *
	 * @param string $date Date string.
	 * @return string iCal formatted date.
	 */
	private static function date_to_ical_format( $date ) {
		try {
			$dt = new DateTime( $date );
			return $dt->format( 'Ymd\THis' );
		} catch ( Exception $e ) {
			return gmdate( 'Ymd\THis' );
		}
	}
}
