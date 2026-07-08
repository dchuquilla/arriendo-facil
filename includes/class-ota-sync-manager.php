<?php
/**
 * OTA Sync Manager - Orchestrator for availability synchronization.
 *
 * Coordinates synchronization between ArriendoFacil and OTA platforms
 * (Booking.com, Airbnb, etc). Handles reconciliation, logging, and notifications.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_OTA_Sync_Manager
 *
 * Orchestrates OTA availability synchronization.
 */
class Arriendo_Facil_OTA_Sync_Manager {

	/**
	 * Synchronizes an accommodation with configured OTA platforms.
	 *
	 * Fetches availability from all configured platforms (Booking, Airbnb, etc.)
	 * and reconciles with local accommodation status.
	 *
	 * @param int   $accommodation_id Accommodation post ID.
	 * @param array $sources          Platforms to sync (default: all configured).
	 * @return array|WP_Error Sync results per platform or WP_Error.
	 */
	public function sync_accommodation( $accommodation_id, $sources = array() ) {
		$accommodation_id = absint( $accommodation_id );

		if ( ! $accommodation_id ) {
			return new WP_Error( 'invalid_accommodation_id', 'Invalid accommodation ID' );
		}

		$post = get_post( $accommodation_id );
		if ( ! $post || 'accommodation' !== $post->post_type ) {
			return new WP_Error( 'invalid_accommodation', 'Accommodation not found' );
		}

		// Get owner ID
		$owner_id = (int) get_post_meta( $accommodation_id, '_af_owner_id', true );
		if ( ! $owner_id ) {
			return new WP_Error( 'no_owner', 'Accommodation has no owner' );
		}

		// Default to all configured sources if not specified
		if ( empty( $sources ) ) {
			$sources = array();
			if ( Arriendo_Facil_OTA_Credentials::is_configured( $owner_id, 'booking' ) ) {
				$sources[] = 'booking';
			}
			if ( Arriendo_Facil_OTA_Credentials::is_configured( $owner_id, 'airbnb' ) ) {
				$sources[] = 'airbnb';
			}
		}

		if ( empty( $sources ) ) {
			return new WP_Error( 'no_configured_sources', 'No OTA platforms configured for this accommodation' );
		}

		// Acquire lock to prevent concurrent syncs
		if ( ! $this->acquire_sync_lock( $accommodation_id ) ) {
			return new WP_Error( 'sync_in_progress', 'Sync already in progress for this accommodation' );
		}

		try {
			$results = array();

			foreach ( $sources as $source ) {
				$source = sanitize_key( $source );

				// Get credentials
				$credentials = Arriendo_Facil_OTA_Credentials::get_decrypted( $owner_id, $source );
				if ( ! $credentials ) {
					$results[ $source ] = array(
						'status' => 'error',
						'message' => 'No credentials configured',
					);
					continue;
				}

				try {
					// Get remote property ID
					$remote_property_id = get_post_meta(
						$accommodation_id,
						"_af_{$source}_property_id",
						true
					);

					if ( ! $remote_property_id ) {
						$results[ $source ] = array(
							'status' => 'error',
							'message' => "No {$source} property ID configured",
						);
						continue;
					}

					// TODO: In Phase 2, implement actual API client instantiation
					// $client = $this->get_client( $source, $credentials );
					// $remote_status = $client->check_property_occupied( $remote_property_id );
					// $this->reconcile_occupancy( $accommodation_id, $source, $remote_status );
					// $this->log_sync( $accommodation_id, $source, $remote_status, 'success' );

					$results[ $source ] = array(
						'status' => 'pending',
						'message' => 'Client implementation pending (Phase 2)',
					);
				} catch ( Exception $e ) {
					$this->handle_sync_error( $accommodation_id, $source, $e );
					$results[ $source ] = array(
						'status' => 'error',
						'message' => $e->getMessage(),
					);
				}
			}

			// Update last sync timestamp
			update_post_meta( $accommodation_id, '_af_last_sync_timestamp', time() );

			return $results;

		} finally {
			$this->release_sync_lock( $accommodation_id );
		}
	}

	/**
	 * Acquires a transient lock to prevent concurrent syncs on same accommodation.
	 *
	 * Uses transients for atomic lock acquisition (WordPress built-in mutex).
	 *
	 * @param int $accommodation_id Accommodation post ID.
	 * @return bool True if lock acquired, false if already locked.
	 */
	private function acquire_sync_lock( $accommodation_id ) {
		$lock_key = "af_sync_lock_{$accommodation_id}";
		$timeout = 5 * MINUTE_IN_SECONDS;

		// Transient operations are atomic, so this is safe
		if ( ! get_transient( $lock_key ) ) {
			set_transient( $lock_key, time(), $timeout );
			return true;
		}

		return false;
	}

	/**
	 * Releases the sync lock for an accommodation.
	 *
	 * @param int $accommodation_id Accommodation post ID.
	 * @return void
	 */
	private function release_sync_lock( $accommodation_id ) {
		delete_transient( "af_sync_lock_{$accommodation_id}" );
	}

	/**
	 * Gets an OTA API client instance for a platform.
	 *
	 * TODO: Implement in Phase 2 with actual clients.
	 *
	 * @param string $platform    Platform identifier.
	 * @param array  $credentials Decrypted credentials array.
	 * @return Arriendo_Facil_OTA_API_Client_Base|WP_Error Client or error.
	 */
	private function get_client( $platform, $credentials ) {
		// Stub for Phase 2 implementation
		return new WP_Error(
			'client_not_implemented',
			"Client for {$platform} not yet implemented"
		);
	}

	/**
	 * Reconciles local and remote availability status.
	 *
	 * Strategy: Remote status is source of truth. If remote says occupied,
	 * mark locally as occupied. If mismatch (remote free, local occupied),
	 * alert owner.
	 *
	 * TODO: Implement in Phase 2.
	 *
	 * @param int    $accommodation_id Accommodation post ID.
	 * @param string $source          OTA platform source.
	 * @param array  $remote_status   Remote availability status.
	 * @return void
	 */
	private function reconcile_occupancy( $accommodation_id, $source, $remote_status ) {
		// Stub for Phase 2
	}

	/**
	 * Logs a sync attempt to the database.
	 *
	 * TODO: Implement in Phase 2.
	 *
	 * @param int    $accommodation_id Accommodation post ID.
	 * @param string $source          OTA platform source.
	 * @param array  $remote_status   Remote status.
	 * @param string $status          Sync status (success, failed).
	 * @param Exception $error        Error object if failed.
	 * @return bool True if logged, false otherwise.
	 */
	private function log_sync( $accommodation_id, $source, $remote_status, $status = 'success', $error = null ) {
		// Stub for Phase 2
		return true;
	}

	/**
	 * Handles sync errors with retry scheduling.
	 *
	 * TODO: Implement in Phase 2.
	 *
	 * @param int       $accommodation_id Accommodation post ID.
	 * @param string    $source          OTA platform.
	 * @param Exception $e               Exception that occurred.
	 * @return void
	 */
	private function handle_sync_error( $accommodation_id, $source, Exception $e ) {
		// Stub for Phase 2
		error_log( "OTA sync error for accommodation {$accommodation_id} ({$source}): " . $e->getMessage() );
	}

	/**
	 * Processes all scheduled syncs (called via WP-Cron).
	 *
	 * TODO: Implement in Phase 2.
	 *
	 * @return void
	 */
	public static function process_scheduled_sync() {
		// Stub for Phase 2 - will batch sync all enabled accommodations
	}
}
