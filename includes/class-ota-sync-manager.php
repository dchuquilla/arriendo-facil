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
			$errors = array();

			foreach ( $sources as $source ) {
				$source = sanitize_key( $source );

				// Get credentials
				$credentials = Arriendo_Facil_OTA_Credentials::get_decrypted( $owner_id, $source );
				if ( ! $credentials ) {
					$error_msg = "No credentials configured for {$source}";
					$errors[ $source ] = $error_msg;
					$results[ $source ] = array(
						'status' => 'error',
						'message' => $error_msg,
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
						$error_msg = "No {$source} property ID configured";
						$errors[ $source ] = $error_msg;
						$results[ $source ] = array(
							'status' => 'error',
							'message' => $error_msg,
						);
						continue;
					}

					// Get client and check occupancy
					$client = $this->get_client( $source, $credentials );
					if ( is_wp_error( $client ) ) {
						throw new Exception( $client->get_error_message() );
					}

					// Get remote status
					$remote_status = $client->check_property_occupied( $remote_property_id );
					if ( is_wp_error( $remote_status ) ) {
						throw new Exception( $remote_status->get_error_message() );
					}

					// Reconcile with local status
					$this->reconcile_occupancy( $accommodation_id, $source, $remote_status );

					// Log successful sync
					$this->log_sync( $accommodation_id, $source, $remote_status, 'success' );

					$results[ $source ] = array(
						'status' => 'success',
						'message' => 'Synced successfully',
						'is_occupied' => $remote_status['is_occupied'],
					);

				} catch ( Exception $e ) {
					$error_msg = $e->getMessage();
					$errors[ $source ] = $error_msg;
					$this->handle_sync_error( $accommodation_id, $source, $e );
					$this->log_sync( $accommodation_id, $source, array(), 'failed', $e );
					$results[ $source ] = array(
						'status' => 'error',
						'message' => $error_msg,
					);
				}
			}

			// Update last sync timestamp and errors
			update_post_meta( $accommodation_id, '_af_last_sync_timestamp', time() );
			if ( ! empty( $errors ) ) {
				update_post_meta( $accommodation_id, '_af_ota_last_errors', $errors );
			} else {
				delete_post_meta( $accommodation_id, '_af_ota_last_errors' );
			}

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
	 * @param string $platform    Platform identifier.
	 * @param array  $credentials Decrypted credentials array.
	 * @return Arriendo_Facil_OTA_API_Client_Base|WP_Error Client or error.
	 */
	private function get_client( $platform, $credentials ) {
		$platform = sanitize_key( $platform );
		$api_key = $credentials['api_key'] ?? null;
		$account_id = $credentials['account_id'] ?? null;

		if ( ! $api_key || ! $account_id ) {
			return new WP_Error( 'invalid_credentials', 'Invalid credentials' );
		}

		try {
			switch ( $platform ) {
				case 'booking':
					return new Arriendo_Facil_Booking_API_Client( $api_key, $account_id );

				case 'airbnb':
					return new Arriendo_Facil_Airbnb_API_Client( $api_key, $account_id );

				default:
					return new WP_Error( 'unknown_platform', "Unknown platform: {$platform}" );
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'client_error', $e->getMessage() );
		}
	}

	/**
	 * Reconciles local and remote availability status.
	 *
	 * Strategy: Remote status is source of truth. If remote says occupied,
	 * mark locally as occupied. If mismatch (remote free, local occupied),
	 * alert owner via hook.
	 *
	 * @param int    $accommodation_id Accommodation post ID.
	 * @param string $source          OTA platform source.
	 * @param array  $remote_status   Remote availability status.
	 * @return void
	 */
	private function reconcile_occupancy( $accommodation_id, $source, $remote_status ) {
		$local_occupied = Arriendo_Facil_Accommodation_Occupied_Admin::is_occupied( $accommodation_id );
		$remote_occupied = $remote_status['is_occupied'] ?? false;

		// If remote says occupied, mark locally as occupied (safer strategy)
		if ( $remote_occupied && ! $local_occupied ) {
			update_post_meta( $accommodation_id, '_af_is_occupied', 1 );
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
	 * @param string    $source          OTA platform source.
	 * @param array     $remote_status   Remote status.
	 * @param string    $status          Sync status (success, failed).
	 * @param Exception $error           Error object if failed.
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
				'sync_type'          => 'availability',
				'remote_property_id' => $remote_status['property_id'] ?? '',
				'status'             => $status,
				'local_was_occupied' => (int) $local_occupied,
				'remote_is_occupied' => (int) ( $remote_status['is_occupied'] ?? 0 ),
				'remote_booked_dates' => ! empty( $remote_status['booked_dates'] ) ? wp_json_encode( $remote_status['booked_dates'] ) : null,
				'error_message'      => $error ? substr( $error->getMessage(), 0, 500 ) : null,
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Handles sync errors with retry scheduling.
	 *
	 * Schedules automatic retry after 1 hour (max 3 times).
	 *
	 * @param int       $accommodation_id Accommodation post ID.
	 * @param string    $source          OTA platform.
	 * @param Exception $e               Exception that occurred.
	 * @return void
	 */
	private function handle_sync_error( $accommodation_id, $source, Exception $e ) {
		$retry_count = (int) get_post_meta( $accommodation_id, "_af_{$source}_retry_count", true );

		// Log error
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "OTA sync error for accommodation {$accommodation_id} ({$source}): " . $e->getMessage() );
		}

		// Schedule retry if under limit
		if ( $retry_count < 3 ) {
			wp_schedule_single_event(
				time() + HOUR_IN_SECONDS,
				'af_retry_ota_sync',
				array( $accommodation_id, $source )
			);
			update_post_meta( $accommodation_id, "_af_{$source}_retry_count", $retry_count + 1 );
		} else {
			// Notify owner after max retries
			do_action( 'af_ota_sync_failed_critical', $accommodation_id, $source, $e );
			update_post_meta( $accommodation_id, "_af_{$source}_retry_count", 0 );
		}
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
			'post_type' => 'accommodation',
			'posts_per_page' => -1,
			'post_status' => 'publish',
			'meta_query' => array(
				array(
					'key' => '_af_sync_enabled',
					'value' => 1,
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

	/**
	 * Processes manual retry for failed sync.
	 *
	 * Called via WP-Cron action: af_retry_ota_sync
	 *
	 * @param int    $accommodation_id Accommodation post ID.
	 * @param string $source          OTA platform.
	 * @return void
	 */
	public static function process_retry_sync( $accommodation_id, $source ) {
		$manager = new self();
		$manager->sync_accommodation( $accommodation_id, array( $source ) );
	}
}
