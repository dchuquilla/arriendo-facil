<?php
/**
 * OTA Sync Manager - Orchestrates availability synchronization via iCal.
 *
 * Synchronizes availability between ArriendoFácil and OTA platforms
 * (Booking.com, Airbnb) using iCal feeds and webhooks.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_OTA_Sync_Manager
 *
 * Orchestrates OTA availability synchronization via iCal.
 */
class Arriendo_Facil_OTA_Sync_Manager {

	/**
	 * Synchronizes an accommodation with configured OTA platforms via iCal.
	 *
	 * Downloads iCal feeds from Booking and/or Airbnb and reconciles
	 * local occupancy status with remote availability.
	 *
	 * @param int $accommodation_id Accommodation post ID.
	 * @return array|WP_Error Sync results or WP_Error.
	 */
	public function sync_accommodation( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );

		if ( ! $accommodation_id ) {
			return new WP_Error( 'invalid_accommodation_id', 'Invalid accommodation ID' );
		}

		$post = get_post( $accommodation_id );
		if ( ! $post || 'accommodation' !== $post->post_type ) {
			return new WP_Error( 'invalid_accommodation', 'Accommodation not found' );
		}

		// Acquire lock to prevent concurrent syncs
		if ( ! $this->acquire_sync_lock( $accommodation_id ) ) {
			return new WP_Error( 'sync_in_progress', 'Sync already in progress for this accommodation' );
		}

		try {
			$results = array();

			// Sync Booking.com if iCal URL is configured
			$booking_ical_url = get_post_meta( $accommodation_id, '_af_booking_ical_url', true );
			if ( ! empty( $booking_ical_url ) ) {
				$results['booking'] = $this->sync_from_ical_source( $accommodation_id, 'booking', $booking_ical_url );
			}

			// Sync Airbnb if iCal URL is configured
			$airbnb_ical_url = get_post_meta( $accommodation_id, '_af_airbnb_ical_url', true );
			if ( ! empty( $airbnb_ical_url ) ) {
				$results['airbnb'] = $this->sync_from_ical_source( $accommodation_id, 'airbnb', $airbnb_ical_url );
			}

			if ( empty( $results ) ) {
				return new WP_Error( 'no_sources_configured', 'No iCal URLs configured for this accommodation' );
			}

			// Update last sync timestamp
			update_post_meta( $accommodation_id, '_af_last_sync_timestamp', time() );

			return $results;

		} finally {
			$this->release_sync_lock( $accommodation_id );
		}
	}

	/**
	 * Synchronizes from a single iCal source.
	 *
	 * @param int    $accommodation_id Accommodation post ID.
	 * @param string $source OTA source (booking, airbnb).
	 * @param string $ical_url iCal URL to parse.
	 * @return array Sync result with status and details.
	 */
	private function sync_from_ical_source( $accommodation_id, $source, $ical_url ) {
		$source = sanitize_key( $source );

		try {
			// Parse iCal feed
			$remote_status = Arriendo_Facil_iCal_Parser::parse_ical_url( $ical_url );

			if ( is_wp_error( $remote_status ) ) {
				throw new Exception( $remote_status->get_error_message() );
			}

			// Reconcile with local status
			$this->reconcile_occupancy( $accommodation_id, $source, $remote_status );

			// Log successful sync
			$this->log_sync( $accommodation_id, $source, $remote_status, 'success' );

			return array(
				'status'     => 'success',
				'message'    => 'Synced successfully',
				'is_occupied' => $remote_status['is_occupied'],
				'source'     => $source,
			);

		} catch ( Exception $e ) {
			// Log failed sync
			$this->log_sync( $accommodation_id, $source, array(), 'failed', $e );

			return array(
				'status'  => 'error',
				'message' => $e->getMessage(),
				'source'  => $source,
			);
		}
	}

	/**
	 * Acquires a transient lock to prevent concurrent syncs.
	 *
	 * @param int $accommodation_id Accommodation post ID.
	 * @return bool True if lock acquired, false if already locked.
	 */
	private function acquire_sync_lock( $accommodation_id ) {
		$lock_key = "af_sync_lock_{$accommodation_id}";
		$timeout = 5 * MINUTE_IN_SECONDS;

		if ( ! get_transient( $lock_key ) ) {
			set_transient( $lock_key, time(), $timeout );
			return true;
		}

		return false;
	}

	/**
	 * Releases the sync lock.
	 *
	 * @param int $accommodation_id Accommodation post ID.
	 * @return void
	 */
	private function release_sync_lock( $accommodation_id ) {
		delete_transient( "af_sync_lock_{$accommodation_id}" );
	}

	/**
	 * Reconciles local and remote availability status.
	 *
	 * Remote status (from iCal) is source of truth. Marks accommodation
	 * as occupied if remote indicates occupation.
	 *
	 * @param int    $accommodation_id Accommodation post ID.
	 * @param string $source OTA platform (booking, airbnb).
	 * @param array  $remote_status Remote status from iCal.
	 * @return void
	 */
	private function reconcile_occupancy( $accommodation_id, $source, $remote_status ) {
		$local_occupied = Arriendo_Facil_Accommodation_Occupied_Admin::is_occupied( $accommodation_id );
		$remote_occupied = $remote_status['is_occupied'] ?? false;

		// If remote says occupied, mark locally as occupied
		if ( $remote_occupied && ! $local_occupied ) {
			update_post_meta( $accommodation_id, '_af_is_occupied', 1 );

			// Store occupancy dates for reference
			if ( ! empty( $remote_status['booked_dates'] ) ) {
				$first_booking = reset( $remote_status['booked_dates'] );
				$last_booking = end( $remote_status['booked_dates'] );

				update_post_meta( $accommodation_id, '_af_occupied_from', $first_booking['from'] );
				update_post_meta( $accommodation_id, '_af_occupied_to', $last_booking['to'] );
			}

			do_action( 'af_accommodation_marked_occupied', $accommodation_id, $source, $remote_status );
		}

		// If remote says free but local occupied, alert owner
		if ( ! $remote_occupied && $local_occupied ) {
			do_action( 'af_occupancy_mismatch_detected', $accommodation_id, $source, $remote_status );
		}
	}

	/**
	 * Logs a sync attempt to the database.
	 *
	 * @param int       $accommodation_id Accommodation post ID.
	 * @param string    $source OTA platform.
	 * @param array     $remote_status Remote status.
	 * @param string    $status Sync status (success, failed).
	 * @param Exception $error Error object if failed.
	 * @return bool True if logged, false otherwise.
	 */
	private function log_sync( $accommodation_id, $source, $remote_status = array(), $status = 'success', $error = null ) {
		global $wpdb;

		$local_occupied = Arriendo_Facil_Accommodation_Occupied_Admin::is_occupied( $accommodation_id );

		$result = $wpdb->insert(
			$wpdb->prefix . 'af_otas_sync_log',
			array(
				'accommodation_id'   => $accommodation_id,
				'ota_source'         => $source,
				'sync_type'          => 'ical',
				'status'             => $status,
				'local_was_occupied' => (int) $local_occupied,
				'remote_is_occupied' => (int) ( $remote_status['is_occupied'] ?? 0 ),
				'remote_booked_dates' => ! empty( $remote_status['booked_dates'] ) ? wp_json_encode( $remote_status['booked_dates'] ) : null,
				'error_message'      => $error ? substr( $error->getMessage(), 0, 500 ) : null,
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Processes all scheduled syncs (called via WP-Cron).
	 *
	 * Batch syncs all accommodations with sync enabled.
	 *
	 * @return void
	 */
	public static function process_scheduled_sync() {
		// Get all accommodations with sync enabled
		$accommodations = get_posts( array(
			'post_type'      => 'accommodation',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'     => '_af_sync_enabled',
					'value'   => 1,
					'compare' => '=',
				),
			),
		) );

		if ( empty( $accommodations ) ) {
			return;
		}

		$manager = new self();

		foreach ( $accommodations as $accommodation ) {
			// Sync each accommodation
			$manager->sync_accommodation( $accommodation->ID );

			// Small delay to respect rate limits
			sleep( 1 );
		}
	}
}
