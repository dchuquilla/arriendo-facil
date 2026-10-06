<?php
/**
 * Lease contract document generation (administration model).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Contract_Generator
 *
 * Single pipeline that turns an existing lease row into a contract document.
 * The lease already exists (created by the operator or by the tenant's
 * onboarding form); this class only produces and attaches its document:
 *
 *   1. Owner DOCX template for the property, filled in place (layout kept).
 *   2. Otherwise the standard Ecuadorian lease template (deterministic).
 *      AI drafting is only used when the legacy marketplace modules are on.
 *
 * Heavy work runs in the background via the af_generate_lease_contract event
 * so admin screens never generate documents while rendering.
 */
class Arriendo_Facil_Contract_Generator {

	const CRON_HOOK = 'af_generate_lease_contract';

	const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

	/**
	 * Registers the background job hook.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled' ), 10, 1 );
	}

	/**
	 * Queues background generation for a lease (deduplicated per lease).
	 *
	 * @param int $lease_id Lease ID.
	 * @return bool True when a job is queued (now or already pending).
	 */
	public static function schedule( $lease_id ) {
		$lease_id = absint( $lease_id );
		if ( ! $lease_id ) {
			return false;
		}

		$args = array( $lease_id );
		if ( wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			return true;
		}

		if ( get_transient( self::failure_key( $lease_id ) ) ) {
			return false;
		}

		return (bool) wp_schedule_single_event( time(), self::CRON_HOOK, $args );
	}

	/**
	 * @param int $lease_id Lease ID.
	 * @return string
	 */
	private static function failure_key( $lease_id ) {
		return 'af_contract_gen_failed_' . absint( $lease_id );
	}

	/**
	 * Whether a background generation job is pending for the lease.
	 *
	 * @param int $lease_id Lease ID.
	 * @return bool
	 */
	public static function is_pending( $lease_id ) {
		return (bool) wp_next_scheduled( self::CRON_HOOK, array( absint( $lease_id ) ) );
	}

	/**
	 * Cron callback: generates the document if the lease still has none.
	 *
	 * @param int $lease_id Lease ID.
	 * @return void
	 */
	public static function run_scheduled( $lease_id ) {
		$lease_id = absint( $lease_id );
		if ( ! $lease_id ) {
			return;
		}

		Arriendo_Facil_Job_Lock::run(
			'lease_contract_' . $lease_id,
			10 * MINUTE_IN_SECONDS,
			static function () use ( $lease_id ) {
				$generator = new self();
				if ( $generator->lease_has_document( $lease_id ) ) {
					return;
				}

				$result = $generator->generate_for_lease( $lease_id );
				if ( empty( $result['generated'] ) ) {
					// Avoid regenerating on every page view when the inputs are broken.
					set_transient( self::failure_key( $lease_id ), 1, HOUR_IN_SECONDS );
				}
			}
		);
	}

	/**
	 * Whether the lease already has an attached document or uploaded version.
	 *
	 * @param int $lease_id Lease ID.
	 * @return bool
	 */
	public function lease_has_document( $lease_id ) {
		$lease_service = new Arriendo_Facil_Lease();
		$lease         = $lease_service->get_lease( $lease_id );
		if ( ! $lease ) {
			return true;
		}

		if ( '' !== trim( (string) $lease->document_url ) ) {
			return true;
		}

		$versions = $lease_service->get_contract_versions( $lease_id );
		return ! empty( $versions['versions'] );
	}

	/**
	 * Generates and attaches the contract document of an existing lease.
	 *
	 * @param int   $lease_id Lease ID.
	 * @param array $options  { use_ai: bool } Force AI drafting (legacy button).
	 * @return array{generated:bool,lease_id:int,document_url:string,template_used:bool,template_attachment_id:int}
	 */
	public function generate_for_lease( $lease_id, array $options = array() ) {
		$lease_id = absint( $lease_id );
		$result   = array(
			'generated'              => false,
			'lease_id'               => $lease_id,
			'document_url'           => '',
			'template_used'          => false,
			'template_attachment_id' => 0,
		);

		$lease = $lease_id ? ( new Arriendo_Facil_Lease() )->get_lease( $lease_id ) : null;
		if ( ! $lease ) {
			return $result;
		}

		$owner_template = $this->get_owner_contract_example_context(
			(int) $lease->accommodation_id,
			isset( $lease->template_attachment_id ) ? absint( $lease->template_attachment_id ) : 0
		);
		$has_template   = ! empty( $owner_template['attachment_id'] );
		$payload        = $this->build_payload( $lease, $owner_template );

		$document_url = '';
		if ( $has_template ) {
			$document_url = $this->create_filled_contract_from_owner_template( $lease_id, $owner_template, $payload );
			if ( '' === $document_url && ! empty( $owner_template['url'] ) ) {
				// Unfilled template is still better than no document for the operator to edit.
				$document_url = esc_url_raw( (string) $owner_template['url'] );
			}
		} else {
			$contract_text = $this->draft_contract_text( $payload, ! empty( $options['use_ai'] ) );
			$document_url  = $this->create_generated_contract_file( $lease_id, $contract_text, $payload );
			if ( '' === $document_url ) {
				$document_url = $this->create_last_resort_contract_file( $lease_id, $contract_text );
			}
		}

		if ( '' !== $document_url ) {
			$this->force_attach_lease_document( $lease_id, $document_url );
		}

		$result['generated']              = '' !== $document_url;
		$result['document_url']           = $document_url;
		$result['template_used']          = $has_template;
		$result['template_attachment_id'] = $has_template ? (int) $owner_template['attachment_id'] : 0;

		return $result;
	}

	/**
	 * Contract body when there is no owner template.
	 *
	 * @param array $payload Contract payload.
	 * @param bool  $use_ai  Force AI drafting.
	 * @return string
	 */
	private function draft_contract_text( array $payload, $use_ai ) {
		$contract_text = '';
		$ai_allowed    = $use_ai || ( defined( 'AF_LEGACY_MODULES' ) && AF_LEGACY_MODULES );

		if ( $ai_allowed && class_exists( 'Arriendo_Facil_AI_Service' ) ) {
			try {
				$ai_result = ( new Arriendo_Facil_AI_Service() )->generate_document( $payload );
				if ( ! is_wp_error( $ai_result ) && isset( $ai_result['contract_text'] ) && is_string( $ai_result['contract_text'] ) ) {
					$contract_text = trim( wp_strip_all_tags( $ai_result['contract_text'] ) );
				}
			} catch ( Throwable $throwable ) {
				error_log( 'Arriendo Facil AI contract drafting failed: ' . $throwable->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		if ( '' === $contract_text ) {
			$contract_text = $this->build_fallback_contract_text( $payload );
		}

		return $this->normalize_generated_legal_contract_text( $contract_text, $payload );
	}

	/**
	 * Builds the contract payload from persisted data (lease, tenant, property, owner).
	 *
	 * @param object $lease          Lease row.
	 * @param array  $owner_template Owner template context.
	 * @return array
	 */
	private function build_payload( $lease, array $owner_template ) {
		global $wpdb;

		$accommodation_id = (int) $lease->accommodation_id;
		$guest            = null;
		if ( ! empty( $lease->guest_id ) ) {
			$guest = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}af_guests WHERE id = %d", (int) $lease->guest_id )
			);
		}

		$guest_value = static function ( $field ) use ( $guest ) {
			return ( $guest && isset( $guest->{$field} ) ) ? (string) $guest->{$field} : '';
		};

		$owner_user_id = isset( $owner_template['owner_user_id'] ) ? absint( $owner_template['owner_user_id'] ) : 0;
		if ( ! $owner_user_id ) {
			$owner_user_id = $this->resolve_accommodation_owner_user_id( $accommodation_id );
		}

		$payload = array(
			'lease_id'                    => (int) $lease->id,
			'accommodation_id'            => $accommodation_id,
			'accommodation_title'         => sanitize_text_field( (string) get_the_title( $accommodation_id ) ),
			'accommodation_address'       => sanitize_text_field( (string) get_post_meta( $accommodation_id, '_af_address', true ) ),
			'accommodation_city'          => (string) get_post_meta( $accommodation_id, '_af_city', true ),
			'accommodation_square_meters' => (string) get_post_meta( $accommodation_id, '_af_square_meters', true ),
			'accommodation_bedrooms'      => (string) get_post_meta( $accommodation_id, '_af_bedrooms', true ),
			'accommodation_bathrooms'     => (string) get_post_meta( $accommodation_id, '_af_bathrooms', true ),
			'accommodation_property_type' => (string) get_post_meta( $accommodation_id, '_af_property_type', true ),
			'guest_id'                    => (int) $lease->guest_id,
			'guest_name'                  => sanitize_text_field( trim( $guest_value( 'first_name' ) . ' ' . $guest_value( 'last_name' ) ) ),
			'guest_email'                 => sanitize_email( $guest_value( 'email' ) ),
			'guest_phone'                 => sanitize_text_field( $guest_value( 'phone' ) ),
			'guest_id_number'             => sanitize_text_field( $guest_value( 'id_number' ) ),
			'mascotas'                    => absint( $guest_value( 'mascotas' ) ),
			'referencia_personal_1'       => '',
			'referencia_personal_2'       => '',
			'personas_viviran'            => absint( $guest_value( 'personas_viviran' ) ),
			'start_date'                  => (string) $lease->start_date,
			'end_date'                    => (string) $lease->end_date,
			'monthly_rent'                => (float) $lease->monthly_rent,
			'deposit_amount'              => isset( $lease->deposit_amount ) ? (float) $lease->deposit_amount : 0.0,
			'payment_due_day'             => isset( $lease->payment_due_day ) ? absint( $lease->payment_due_day ) : 0,
			'rental_mode'                 => '' !== $guest_value( 'rental_mode' ) ? $guest_value( 'rental_mode' ) : 'dates',
			'guarantee_text'              => '' !== $guest_value( 'guarantee_text' ) ? sanitize_text_field( $guest_value( 'guarantee_text' ) ) : 'Garantía equivalente a dos (2) meses del canon de arrendamiento',
			'template_available'          => ! empty( $owner_template['attachment_id'] ),
			'template_name'               => isset( $owner_template['file_name'] ) ? sanitize_text_field( (string) $owner_template['file_name'] ) : '',
			'template_mime'               => isset( $owner_template['mime_type'] ) ? sanitize_text_field( (string) $owner_template['mime_type'] ) : '',
			'template_url'                => isset( $owner_template['url'] ) ? esc_url_raw( (string) $owner_template['url'] ) : '',
			'template_text'               => isset( $owner_template['template_text'] ) ? (string) $owner_template['template_text'] : '',
			'owner_user_id'               => $owner_user_id,
			'owner_name'                  => isset( $owner_template['owner_name'] ) ? sanitize_text_field( (string) $owner_template['owner_name'] ) : '',
			'owner_email'                 => isset( $owner_template['owner_email'] ) ? sanitize_email( (string) $owner_template['owner_email'] ) : '',
			'owner_id_number'             => $this->get_owner_identification_number( $owner_user_id ),
		);

		if ( '' === $payload['owner_name'] && $owner_user_id ) {
			$owner_user = get_userdata( $owner_user_id );
			if ( $owner_user ) {
				// Use af_contact_name (responsible person) for contracts, not display_name.
				$af_contact_name = (string) get_user_meta( $owner_user_id, 'af_contact_name', true );
				$payload['owner_name']  = ! empty( $af_contact_name ) ? sanitize_text_field( $af_contact_name ) : sanitize_text_field( (string) $owner_user->display_name );
				$payload['owner_email'] = sanitize_email( (string) $owner_user->user_email );
			}
		}

		$placeholders = $this->get_stored_placeholders( $lease );
		if ( $placeholders ) {
			// Operator-entered values win over the derived ones.
			$overrides = array(
				'nombres_inquilino'      => 'guest_name',
				'cedula_inquilino'       => 'guest_id_number',
				'nombres_propietario'    => 'owner_name',
				'cedula_propietario'     => 'owner_id_number',
				'dirección_inmueble'     => 'accommodation_address',
				'tipo_inmueble'          => 'accommodation_property_type',
				'n_habitacion'           => 'accommodation_bedrooms',
				'n_baños'                => 'accommodation_bathrooms',
				'dimensiones_inmueble'   => 'accommodation_square_meters',
				'canon_mensual'          => 'monthly_rent',
				'monto_numero'           => 'deposit_amount',
				'fecha_incio'            => 'start_date',
				'fecha_fin'              => 'end_date',
			);

			foreach ( $overrides as $placeholder => $payload_key ) {
				if ( ! isset( $placeholders[ $placeholder ] ) || '' === $placeholders[ $placeholder ] ) {
					continue;
				}
				$value = $placeholders[ $placeholder ];
				if ( in_array( $payload_key, array( 'monthly_rent', 'deposit_amount' ), true ) ) {
					$payload[ $payload_key ] = (float) $value;
				} elseif ( 'owner_id_number' === $payload_key ) {
					$payload[ $payload_key ] = sanitize_text_field( (string) $value );
				} else {
					$payload[ $payload_key ] = sanitize_text_field( (string) $value );
				}
			}

			// Full bag for the DOCX filler and for anything that needs the
			// exact labels the contract template expects.
			$payload['contract_placeholders'] = $placeholders;
		}

		$payload['legal_requirements']  = $this->get_contract_legal_requirements();
		$payload['legal_template_base'] = $this->build_legal_contract_template( $payload, '' );

		return $payload;
	}

	/**
	 * Reads the placeholders the operator typed when creating the lease.
	 *
	 * They are stored as JSON in post meta under the lease id (the same place
	 * Arriendo_Facil_Lease writes them).
	 *
	 * @param object $lease Lease row.
	 * @return array<string,string>
	 */
	private function get_stored_placeholders( $lease ) {
		$lease_id = isset( $lease->id ) ? absint( $lease->id ) : 0;
		if ( ! $lease_id ) {
			return array();
		}

		$all = get_option( 'af_contract_placeholders_by_lease', array() );
		if ( is_array( $all ) && isset( $all[ $lease_id ] ) && is_array( $all[ $lease_id ] ) ) {
			return array_map( 'strval', $all[ $lease_id ] );
		}

		// Legacy builds wrote this as post meta keyed by the lease id.
		$raw = get_post_meta( $lease_id, 'af_contract_placeholders', true );
		if ( '' === $raw || null === $raw ) {
			return array();
		}

		if ( is_array( $raw ) ) {
			return array_map( 'strval', $raw );
		}

		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? array_map( 'strval', $decoded ) : array();
	}

	/**
	 * Creates a lease document from owner's DOCX template preserving layout/styles.
	 *
	 * @param int   $lease_id Lease ID.
	 * @param array $owner_template Owner template context.
	 * @param array $payload Lease payload.
	 * @return string
	 */
	private function create_filled_contract_from_owner_template( $lease_id, array $owner_template, array $payload ) {
		$lease_id       = absint( $lease_id );
		$attachment_id  = isset( $owner_template['attachment_id'] ) ? absint( $owner_template['attachment_id'] ) : 0;
		$template_path  = $attachment_id ? get_attached_file( $attachment_id ) : '';
		$template_mime  = isset( $owner_template['mime_type'] ) ? strtolower( (string) $owner_template['mime_type'] ) : '';
		$template_ext   = strtolower( (string) pathinfo( (string) $template_path, PATHINFO_EXTENSION ) );
		$tmp_downloaded  = false;

		if ( ! $lease_id || ! $attachment_id ) {
			error_log( 'Arriendo Facil owner-template generation skipped: missing lease_id or attachment_id.' );
			return '';
		}

		// If local file is missing, download from R2.
		if ( ! $template_path || ! file_exists( $template_path ) ) {
			$template_path = Arriendo_Facil_Contract_File_Store::fetch_owner_template( $attachment_id );
			if ( ! $template_path ) {
				error_log( 'Arriendo Facil owner-template generation failed: template file not found locally and R2 download failed. attachment_id=' . $attachment_id );
				return '';
			}
			$tmp_downloaded = true;
			$template_ext   = strtolower( (string) pathinfo( $template_path, PATHINFO_EXTENSION ) );
		}

		if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' !== $template_mime && 'docx' !== $template_ext ) {
			if ( $tmp_downloaded ) {
				@unlink( $template_path );
			}
			error_log( 'Arriendo Facil owner-template generation failed: template is not DOCX. attachment_id=' . $attachment_id . ', mime=' . $template_mime . ', ext=' . $template_ext );
			return '';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			if ( $tmp_downloaded ) {
				@unlink( $template_path );
			}
			error_log( 'Arriendo Facil owner-template generation failed: wp_upload_dir unavailable.' );
			return '';
		}

		$contracts_dir = trailingslashit( $uploads['basedir'] ) . 'arriendo-facil/contracts';
		if ( ! wp_mkdir_p( $contracts_dir ) ) {
			if ( $tmp_downloaded ) {
				@unlink( $template_path );
			}
			error_log( 'Arriendo Facil owner-template generation failed: cannot create contracts dir.' );
			return '';
		}

		$file_name = sprintf( 'lease-%d-owner-template-%s.docx', $lease_id, gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $contracts_dir ) . $file_name;

		// PRIMARY PATH: AI-driven direct fill (works with any template format).
		$phpword_success = false;

		// Check configured processing method (markdown is primary, direct_xml is fallback).
		$processing_method = defined( 'AF_CONTRACT_PROCESSING_METHOD' )
			? AF_CONTRACT_PROCESSING_METHOD
			: (string) get_option( 'af_contract_processing_method', 'markdown' );

		// MARKDOWN PATH: try pandoc-based flow first.
		if ( 'markdown' === $processing_method
			&& class_exists( 'Arriendo_Facil_DOCX_Template_Processor' )
			&& Arriendo_Facil_DOCX_Template_Processor::is_pandoc_available()
		) {
			try {
				$tpl_proc   = new Arriendo_Facil_DOCX_Template_Processor();
				$ai_service = class_exists( 'Arriendo_Facil_AI_Service' ) ? new Arriendo_Facil_AI_Service() : null;

				$md_payload = $payload;
				$md_payload['attachment_id'] = $attachment_id;

				if ( $ai_service && $tpl_proc->fill_template_with_markdown( $template_path, $file_path, $md_payload, $ai_service ) ) {
					$phpword_success = true;
					error_log( 'Arriendo Facil owner-template generation: fill_template_with_markdown succeeded for lease_id=' . $lease_id );
				} else {
					error_log( 'Arriendo Facil owner-template generation: fill_template_with_markdown failed for lease_id=' . $lease_id . '; falling through to direct XML methods' );
				}
			} catch ( \Throwable $e ) {
				error_log( 'Arriendo Facil owner-template generation: fill_template_with_markdown exception for lease_id=' . $lease_id . ': ' . $e->getMessage() );
			}
		}

		// DIRECT XML FALLBACK: existing P1/P2/P3 priority cascade.
		if ( ! $phpword_success && class_exists( 'Arriendo_Facil_DOCX_Template_Processor' ) ) {
			$tpl_proc   = new Arriendo_Facil_DOCX_Template_Processor();
			$ai_service = class_exists( 'Arriendo_Facil_AI_Service' ) ? new Arriendo_Facil_AI_Service() : null;

			// PRIORITY 1: Deterministic context-based filling (no AI, no saved map needed).
			if ( $tpl_proc->fill_template_with_context( $template_path, $file_path, $payload ) ) {
				$phpword_success = true;
				error_log( 'Arriendo Facil owner-template generation: fill_template_with_context succeeded for lease_id=' . $lease_id );
			}

			// PRIORITY 2: AI-driven direct fill.
			if ( ! $phpword_success && $ai_service && $tpl_proc->fill_template_with_ai( $template_path, $file_path, $payload, $ai_service ) ) {
				$phpword_success = true;
				error_log( 'Arriendo Facil owner-template generation: fill_template_with_ai succeeded for lease_id=' . $lease_id );
			}

			// PRIORITY 3: Legacy pre-processed template with PhpWord TemplateProcessor.
			if ( ! $phpword_success ) {
				error_log( 'Arriendo Facil owner-template generation: fill_template_with_ai failed or unavailable for lease_id=' . $lease_id . '; trying legacy path' );

				$processed_tpl_path = '';

				$processed_new = $tpl_proc->process_owner_template( $template_path, $ai_service, '', $payload );
				if ( '' !== $processed_new && file_exists( $processed_new ) ) {
					$processed_tpl_path = $processed_new;
					update_post_meta( $attachment_id, '_af_processed_template_path', $processed_tpl_path );
					error_log( 'Arriendo Facil owner-template generation: regenerated processed template for lease_id=' . $lease_id );
				}

				if ( '' !== $processed_tpl_path && file_exists( $processed_tpl_path ) ) {
					if ( $tpl_proc->fill_template( $processed_tpl_path, $file_path, $payload ) ) {
						$phpword_success = true;
						error_log( 'Arriendo Facil owner-template generation: legacy fill_template succeeded for lease_id=' . $lease_id );
					}
				}
			}
		}

		if ( $phpword_success ) {
			$validation = $this->validate_filled_contract( $file_path, $lease_id );
			if ( ! $validation['valid'] ) {
				error_log( 'Arriendo Facil owner-template generation: contract validation failed for lease_id=' . $lease_id . ', missing_count=' . $validation['missing_count'] );
			}
		}

		if ( $tmp_downloaded ) {
			@unlink( $template_path );
		}

		if ( ! $phpword_success ) {
			error_log( 'Arriendo Facil owner-template generation failed: could not produce a contract from the owner template.' );
			return '';
		}

		$local_url = trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name );

		return Arriendo_Facil_Contract_File_Store::persist( $lease_id, $file_path, $file_name, $local_url, Arriendo_Facil_Contract_Text_Extractor::DOCX_MIME );
	}

	/**
	 * Validates that a filled DOCX contract contains critical lease fields.
	 *
	 * Returns validation result with count of missing critical fields.
	 * If 4+ critical fields are missing, contract may need fallback.
	 *
	 * @param string $file_path Path to the filled DOCX.
	 * @param int    $lease_id  Lease ID for logging.
	 * @return array { valid: bool, missing_count: int, missing_fields: array }
	 */
	private function validate_filled_contract( $file_path, $lease_id ) {
		$file_path = (string) $file_path;
		$lease_id  = absint( $lease_id );

		if ( ! $file_path || ! file_exists( $file_path ) || ! class_exists( 'ZipArchive' ) ) {
			error_log( 'Arriendo Facil contract validation: file not found or ZipArchive not available. lease_id=' . $lease_id . ', path=' . $file_path );
			return array( 'valid' => false, 'missing_count' => 99, 'missing_fields' => array() );
		}

		$critical_fields = array(
			'ARRENDATARIO'        => 'guest_name',
			'CEDULA_ARRENDATARIO' => 'guest_id_number',
			'ARRENDADOR'          => 'owner_name',
			'CANON'               => 'monthly_rent',
			'FECHA_INICIO'        => 'start_date',
			'DIRECCION'           => 'accommodation_address',
		);

		$blank_marker = '...............';
		$missing_fields = array();

		try {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $file_path ) ) {
				error_log( 'Arriendo Facil contract validation: cannot open DOCX. lease_id=' . $lease_id );
				return array( 'valid' => false, 'missing_count' => 99, 'missing_fields' => array() );
			}

			$xml = $zip->getFromName( 'word/document.xml' );
			$zip->close();

			if ( false === $xml || '' === $xml ) {
				error_log( 'Arriendo Facil contract validation: document.xml not found. lease_id=' . $lease_id );
				return array( 'valid' => false, 'missing_count' => 99, 'missing_fields' => array() );
			}

			$text = wp_strip_all_tags( (string) $xml );
			foreach ( $critical_fields as $placeholder => $field_name ) {
				if ( false === strpos( $text, $placeholder ) || false !== strpos( $text, '${' . $placeholder . '}' ) || false !== strpos( $text, $blank_marker ) ) {
					$missing_fields[] = $field_name . '(' . $placeholder . ')';
				}
			}

			$missing_count = count( $missing_fields );
			$valid = $missing_count < 4;

			error_log( 'Arriendo Facil contract validation: lease_id=' . $lease_id . ', missing_count=' . $missing_count . ', valid=' . ( $valid ? 'true' : 'false' ) . ', missing_fields=[' . implode( ', ', $missing_fields ) . ']' );

			return array(
				'valid'          => $valid,
				'missing_count'  => $missing_count,
				'missing_fields' => $missing_fields,
			);
		} catch ( \Throwable $e ) {
			error_log( 'Arriendo Facil contract validation exception: ' . $e->getMessage() . ' lease_id=' . $lease_id );
			return array( 'valid' => false, 'missing_count' => 99, 'missing_fields' => array() );
		}
	}

	/**
	 * Creates a DOCX fallback file when primary DOC/DOCX generation failed.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param string $contract_text Contract text.
	 * @return string
	 */
	private function create_last_resort_contract_file( $lease_id, $contract_text ) {
		$lease_id = absint( $lease_id );
		$text     = trim( (string) $contract_text );

		if ( ! $lease_id || '' === $text ) {
			return '';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}

		$contracts_dir = trailingslashit( $uploads['basedir'] ) . 'arriendo-facil/contracts';
		if ( ! wp_mkdir_p( $contracts_dir ) ) {
			return '';
		}

		$file_name = sprintf( 'lease-%d-fallback-%s.docx', $lease_id, gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $contracts_dir ) . $file_name;

		if ( ! $this->write_contract_docx_file( $file_path, $text, array() ) ) {
			return '';
		}

		return esc_url_raw( trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name ) );
	}

	/**
	 * Persists lease document URL with primary and fallback DB update.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param string $document_url Document URL.
	 * @return void
	 */
	private function force_attach_lease_document( $lease_id, $document_url ) {
		$lease_id     = absint( $lease_id );
		$document_url = esc_url_raw( (string) $document_url );

		if ( ! $lease_id || '' === $document_url ) {
			return;
		}

		$attached = false;
		if ( class_exists( 'Arriendo_Facil_Lease' ) ) {
			$lease_service = new Arriendo_Facil_Lease();
			$attached      = (bool) $lease_service->attach_document( $lease_id, $document_url );
		}

		if ( ! $attached ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'af_leases',
				array( 'document_url' => $document_url ),
				array( 'id' => $lease_id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Builds a deterministic fallback contract text when AI is unavailable.
	 *
	 * @param array $payload Lease and guest context.
	 * @return string
	 */
	private function build_fallback_contract_text( array $payload ) {
		return $this->build_legal_contract_template( $payload, '' );
	}

	/**
	 * Provides legal requirements used to guide AI contract drafting.
	 *
	 * @return array<int,string>
	 */
	private function get_contract_legal_requirements() {
		return array(
			'Usa lenguaje juridico formal ecuatoriano apto para revision legal y vigente al 2026.',
			'Fundamenta el contrato en el Codigo Civil del Ecuador Arts. 1857-1948 (Del Arrendamiento), la Ley de Inquilinato y el Codigo Organico General de Procesos (COGEP).',
			'Incluye clausulas numeradas con titulos en mayusculas y obligaciones claras para ambas partes.',
			'Identifica a las partes con nombre completo, numero de cedula/RUC, celular y correo.',
			'Clausula de objeto: descripcion del inmueble (nombre y direccion completa).',
			'Clausula de plazo: fecha de inicio, fecha de fin, condicion de prorroga automatica con aviso de 30 dias.',
			'Clausula de canon: valor mensual en USD, dia maximo de pago (primeros 5 dias del mes), interes de mora del 1% mensual por retraso.',
			'Clausula de garantia: tipo y monto de garantia, plazo de devolucion (15 dias habiles tras verificacion).',
			'Clausula de destino: uso exclusivo habitacional, numero de personas y mascotas, prohibicion de subarriendo.',
			'Clausula de servicios: agua, luz, gas e internet a cargo del arrendatario; predial y administracion a cargo del arrendador salvo pacto.',
			'Clausula de obligaciones del arrendatario: pago puntual, conservacion del inmueble, prohibicion de modificaciones sin autorizacion.',
			'Clausula de obligaciones del arrendador: posesion pacifica, reparaciones estructurales (Art. 1937 CC).',
			'Clausula de terminacion: vencimiento, mutuo acuerdo, incumplimiento, desahucio conforme COGEP, caso fortuito.',
			'Clausula de referencias personales del arrendatario.',
			'Clausula de jurisdiccion: jueces competentes del Ecuador, renuncia a domicilio y fuero especial.',
			'Bloque de firmas con lineas para firma, nombre completo y cedula de arrendador y arrendatario.',
		);
	}

	/**
	 * Builds a legal base template used by chatbot-generated contracts.
	 *
	 * @param array  $payload Lease and guest context.
	 * @param string $extra_clauses Optional extra clauses text.
	 * @return string
	 */
	private function build_legal_contract_template( array $payload, $extra_clauses = '' ) {
		$owner_name      = isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '________________________';
		$owner_id        = isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '________________________';
		$guest_name      = isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '________________________';
		$guest_id        = isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '________________________';
		$guest_phone     = isset( $payload['guest_phone'] ) ? sanitize_text_field( (string) $payload['guest_phone'] ) : '________________________';
		$guest_email     = isset( $payload['guest_email'] ) ? sanitize_email( (string) $payload['guest_email'] ) : '________________________';
		$property        = isset( $payload['accommodation_title'] ) ? sanitize_text_field( (string) $payload['accommodation_title'] ) : '________________________';
		$address         = isset( $payload['accommodation_address'] ) ? sanitize_text_field( (string) $payload['accommodation_address'] ) : '________________________';
		$start_date      = isset( $payload['start_date'] ) ? sanitize_text_field( (string) $payload['start_date'] ) : '________________________';
		$end_date        = isset( $payload['end_date'] ) ? sanitize_text_field( (string) $payload['end_date'] ) : '________________________';
		$monthly_rent    = isset( $payload['monthly_rent'] ) ? number_format( (float) $payload['monthly_rent'], 2, '.', '' ) : '0.00';
		$desired_price   = isset( $payload['desired_price'] ) ? sanitize_text_field( (string) $payload['desired_price'] ) : '';
		$guarantee_text  = isset( $payload['guarantee_text'] ) ? sanitize_text_field( (string) $payload['guarantee_text'] ) : 'Garantia equivalente a dos (2) meses del canon de arrendamiento.';
		$mascotas        = isset( $payload['mascotas'] ) ? absint( $payload['mascotas'] ) : 0;
		$personas        = isset( $payload['personas_viviran'] ) ? absint( $payload['personas_viviran'] ) : 0;
		$reference_1     = isset( $payload['referencia_personal_1'] ) ? sanitize_text_field( (string) $payload['referencia_personal_1'] ) : '________________________';
		$reference_2     = isset( $payload['referencia_personal_2'] ) ? sanitize_text_field( (string) $payload['referencia_personal_2'] ) : '________________________';

		if ( '' !== trim( $desired_price ) ) {
			$monthly_rent = $desired_price;
		}

		$city_and_date = sprintf( 'Quito, %s', current_time( 'Y-m-d' ) );
		$extra_clauses = trim( (string) $extra_clauses );

		$contract  = "CONTRATO DE ARRENDAMIENTO DE INMUEBLE\n";
		$contract .= "(Conforme al Codigo Civil del Ecuador, Arts. 1857-1948, y la Ley de Inquilinato vigente con sus reformas)\n";
		$contract .= "\n";
		$contract .= $city_and_date . "\n";
		$contract .= "\n";
		$contract .= "COMPARECIENTES\n";
		$contract .= "\n";
		$contract .= "En la ciudad de Quito, Republica del Ecuador, comparecen a la celebracion del presente contrato:\n";
		$contract .= "ARRENDADOR: " . $owner_name . ", con numero de cedula de ciudadania o RUC: " . $owner_id . ", en calidad de propietario o representante autorizado del inmueble que se describe en este instrumento (en adelante \"EL ARRENDADOR\").\n";
		$contract .= "ARRENDATARIO: " . $guest_name . ", con numero de cedula de ciudadania: " . $guest_id . ", celular: " . $guest_phone . ", correo electronico: " . $guest_email . " (en adelante \"EL ARRENDATARIO\").\n";
		$contract .= "\n";
		$contract .= "Las partes, libres y voluntariamente, convienen en celebrar el presente CONTRATO DE ARRENDAMIENTO, sujeto a las siguientes clausulas:\n";
		$contract .= "\n";
		$contract .= "CLAUSULA PRIMERA - OBJETO DEL CONTRATO\n";
		$contract .= "EL ARRENDADOR da en arrendamiento a EL ARRENDATARIO el inmueble denominado \"" . $property . "\", ubicado en " . $address . ", Republica del Ecuador. EL ARRENDATARIO declara conocer el estado actual del inmueble y aceptarlo en las condiciones en que se encuentra, comprometiendose a restituirlo en iguales condiciones al termino del contrato, salvo el deterioro proveniente del uso normal y legitimo.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA SEGUNDA - PLAZO\n";
		$contract .= "El plazo de vigencia del presente contrato es de " . $start_date . " hasta el " . $end_date . ". Vencido el plazo, si ninguna de las partes notifica por escrito su voluntad de terminar el contrato con al menos treinta (30) dias de anticipacion, el contrato se entendera prorrogado automaticamente por periodos iguales, conforme lo dispuesto en el Art. 1885 del Codigo Civil ecuatoriano.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA TERCERA - CANON DE ARRENDAMIENTO Y FORMA DE PAGO\n";
		$contract .= "Las partes acuerdan un canon mensual de arrendamiento de USD " . $monthly_rent . " (dolares de los Estados Unidos de America), pagadero dentro de los primeros cinco (5) dias de cada mes calendario. El pago debera realizarse mediante transferencia bancaria, deposito o el medio que mutuamente convengan las partes por escrito. El retraso en el pago generara un interes de mora del 1% mensual sobre el valor adeudado, conforme lo permite la normativa civil ecuatoriana.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA CUARTA - GARANTIA\n";
		$contract .= "Como garantia del cumplimiento de las obligaciones contractuales, EL ARRENDATARIO entrega: " . $guarantee_text . ". Dicha garantia sera devuelta al termino del contrato, previa verificacion del estado del inmueble y la ausencia de valores pendientes de pago, en un plazo no mayor a quince (15) dias habiles.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA QUINTA - DESTINO Y USO DEL INMUEBLE\n";
		$contract .= "El inmueble objeto de este contrato sera destinado unica y exclusivamente para uso habitacional de EL ARRENDATARIO y su nucleo familiar autorizado, compuesto por " . $personas . " persona(s) y " . $mascotas . " mascota(s) declarada(s). Queda expresamente prohibido subarriendar total o parcialmente el inmueble, ceder este contrato o cambiar el destino del bien sin autorizacion previa y escrita de EL ARRENDADOR.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA SEXTA - SERVICIOS BASICOS Y GASTOS\n";
		$contract .= "Los servicios de energia electrica, agua potable, telefonia, internet y gas domiciliario seran de cargo exclusivo de EL ARRENDATARIO durante la vigencia del contrato. El impuesto predial y los gastos de administracion del inmueble (si aplican) corresponden a EL ARRENDADOR, salvo pacto expreso en contrario.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA SEPTIMA - OBLIGACIONES DE EL ARRENDATARIO\n";
		$contract .= "EL ARRENDATARIO se obliga a: (a) Pagar puntualmente el canon en la forma convenida; (b) Mantener el inmueble en buen estado de conservacion y limpieza; (c) No realizar obras ni modificaciones sin autorizacion escrita de EL ARRENDADOR; (d) Notificar de inmediato cualquier dano o averia que requiera reparacion urgente; (e) Permitir el acceso al inmueble de EL ARRENDADOR o sus representantes para inspeccion, con aviso previo de al menos 24 horas; (f) Cumplir las normas de convivencia del sector y el reglamento de la propiedad horizontal si aplica.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA OCTAVA - OBLIGACIONES DE EL ARRENDADOR\n";
		$contract .= "EL ARRENDADOR se obliga a: (a) Mantener al ARRENDATARIO en el uso pacifico del inmueble durante la vigencia del contrato; (b) Efectuar las reparaciones locativas que le correspondan conforme al Art. 1937 del Codigo Civil; (c) No perturbar la posesion del ARRENDATARIO; (d) Entregar el inmueble en condiciones habitables.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA NOVENA - TERMINACION DEL CONTRATO\n";
		$contract .= "El presente contrato terminara por: (a) Vencimiento del plazo acordado; (b) Mutuo acuerdo de las partes, por escrito; (c) Incumplimiento grave de las obligaciones contractuales o legales por cualquiera de las partes; (d) Desahucio conforme al procedimiento establecido en la Ley de Inquilinato y el Codigo Organico General de Procesos (COGEP); (e) Destruccion o inhabilitacion del inmueble por caso fortuito o fuerza mayor. En caso de desahucio voluntario, EL ARRENDATARIO debera notificar con al menos treinta (30) dias de anticipacion.\n";
		$contract .= "\n";
		$contract .= "CLAUSULA DECIMA - REFERENCIAS PERSONALES DEL ARRENDATARIO\n";
		$contract .= "EL ARRENDATARIO declara como referencias personales: Referencia 1: " . $reference_1 . ". Referencia 2: " . $reference_2 . ".\n";

		if ( '' !== $extra_clauses ) {
			$contract .= "\n";
			$contract .= "CLAUSULA DECIMA PRIMERA - DISPOSICIONES ADICIONALES\n";
			$contract .= $extra_clauses . "\n";
			$next_clause = 'DECIMA SEGUNDA';
		} else {
			$next_clause = 'DECIMA PRIMERA';
		}

		$contract .= "\n";
		$contract .= "CLAUSULA " . $next_clause . " - JURISDICCION, COMPETENCIA Y LEY APLICABLE\n";
		$contract .= "Para todos los efectos legales derivados del presente contrato, las partes se someten expresamente a la jurisdiccion y competencia de los jueces y tribunales de la Republica del Ecuador, con sede en la ciudad pactada, y se regiran por el Codigo Civil (Arts. 1857-1948), la Ley de Inquilinato, el Codigo Organico General de Procesos (COGEP) y las demas normas conexas vigentes en 2026. Las partes renuncian expresamente a domicilio y fuero especial.\n";
		$contract .= "\n";
		$contract .= "En fe de lo cual, las partes suscriben el presente contrato en dos (2) ejemplares de igual tenor y valor legal, en la fecha indicada en el encabezado.\n";
		$contract .= "\n";
		$contract .= "FIRMAS\n";
		$contract .= "\n";
		$contract .= "EL ARRENDADOR:\n";
		$contract .= "Firma: ________________________\n";
		$contract .= "Nombre: " . $owner_name . "\n";
		$contract .= "Cedula/RUC: " . $owner_id . "\n";
		$contract .= "\n";
		$contract .= "EL ARRENDATARIO:\n";
		$contract .= "Firma: ________________________\n";
		$contract .= "Nombre: " . $guest_name . "\n";
		$contract .= "Cedula: " . $guest_id . "\n";

		return $contract;
	}

	/**
	 * Ensures generated contract text keeps legal format requirements.
	 *
	 * @param string $contract_text Raw contract text from AI.
	 * @param array  $payload Lease and guest context.
	 * @return string
	 */
	private function normalize_generated_legal_contract_text( $contract_text, array $payload ) {
		$contract_text = trim( preg_replace( '/\s+\n/', "\n", (string) $contract_text ) );
		if ( '' === $contract_text ) {
			return $this->build_legal_contract_template( $payload, '' );
		}

		$has_owner_template = ! empty( $payload['template_available'] )
			&& isset( $payload['template_text'] )
			&& is_string( $payload['template_text'] )
			&& '' !== trim( $payload['template_text'] );

		$lower_text = strtolower( $contract_text );
		$has_title  = false !== strpos( $lower_text, 'contrato de arrendamiento' );
		$has_clause = false !== strpos( $lower_text, 'clausula' );
		$has_sign   = false !== strpos( $lower_text, 'firma' ) || false !== strpos( $lower_text, 'arrendatario:' );

		if ( $has_owner_template ) {
			if ( ! $has_sign ) {
				$signature_block = "\n\nFIRMAS\n\nARRENDADOR: ________________________\nNombre: "
					. ( isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '________________________' )
					. "\nCedula/RUC: "
					. ( isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '________________________' )
					. "\n\nARRENDATARIO: ________________________\nNombre: "
					. ( isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '________________________' )
					. "\nCedula: "
					. ( isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '________________________' )
					. "\n";

				$contract_text .= $signature_block;
			}

			return $contract_text;
		}

		if ( ! $has_title || ! $has_clause || strlen( $contract_text ) < 700 ) {
			return $this->build_legal_contract_template( $payload, $contract_text );
		}

		if ( ! $has_sign ) {
			$signature_block = "\n\nFIRMAS\n\nARRENDADOR: ________________________\nNombre: "
				. ( isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '________________________' )
				. "\nCedula/RUC: "
				. ( isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '________________________' )
				. "\n\nARRENDATARIO: ________________________\nNombre: "
				. ( isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '________________________' )
				. "\nCedula: "
				. ( isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '________________________' )
				. "\n";

			$contract_text .= $signature_block;
		}

		return $contract_text;
	}

	/**
	 * Finds the latest contract example uploaded by the accommodation owner.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return array<string,mixed>
	 */
	private function get_owner_contract_example_context( $accommodation_id, $template_attachment_id = 0 ) {
		$accommodation_id = absint( $accommodation_id );
		$owner_user_id = $this->resolve_accommodation_owner_user_id( $accommodation_id );

		if ( ! $owner_user_id ) {
			error_log( 'Arriendo Facil owner-template lookup: accommodation has no resolved owner. accommodation_id=' . $accommodation_id );
			return array();
		}

		$template_attachment_id = absint( $template_attachment_id );

		// Explicit template selection takes precedence over the latest upload.
		if ( $template_attachment_id ) {
			$chosen = get_post( $template_attachment_id );
			if ( $chosen && 'attachment' === $chosen->post_type ) {
				$attachment_owner = (int) get_post_meta( $template_attachment_id, '_af_owner_user_id', true );
				$is_owner_doc     = ( $attachment_owner && $attachment_owner === $owner_user_id )
					|| (int) get_post_meta( $template_attachment_id, '_af_owner_contract_example', true ) === 1;
				if ( $is_owner_doc ) {
					return $this->build_contract_template_context_from_attachment( $template_attachment_id, $owner_user_id );
				}
			}
		}

		$attachment_ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_af_owner_contract_example',
						'value' => '1',
					),
					array(
						'key'   => '_af_owner_user_id',
						'value' => (string) $owner_user_id,
					),
				),
			)
		);

		$attachment_id = ! empty( $attachment_ids ) ? absint( $attachment_ids[0] ) : 0;

		if ( ! $attachment_id ) {
			$attachment_ids = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'key'   => '_af_sensitive_doc_type',
							'value' => 'contract_example',
						),
						array(
							'key'   => '_af_owner_user_id',
							'value' => (string) $owner_user_id,
						),
					),
				)
			);
			$attachment_id = ! empty( $attachment_ids ) ? absint( $attachment_ids[0] ) : 0;

			if ( $attachment_id ) {
				update_post_meta( $attachment_id, '_af_owner_contract_example', '1' );
			}
		}

		if ( ! $attachment_id ) {
			error_log( 'Arriendo Facil owner-template lookup: no owner contract attachment found. accommodation_id=' . $accommodation_id . ', owner_user_id=' . $owner_user_id );
			return array();
		}

		return $this->build_contract_template_context_from_attachment( $attachment_id, $owner_user_id );
	}

	/**
	 * Lists the DOCX contract templates available for an accommodation owner,
	 * used to let operators pick which template generates the lease document.
	 *
	 * @param int $accommodation_id Accommodation post ID.
	 * @return array<int,array{id:int,title:string,file_name:string,mime_type:string}>
	 */
	public function get_owner_contract_templates_for_accommodation( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );
		$owner_user_id    = $this->resolve_accommodation_owner_user_id( $accommodation_id );
		if ( ! $owner_user_id ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 50,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => '_af_owner_contract_example',
						'value' => '1',
					),
					array(
						'key'   => '_af_sensitive_doc_type',
						'value' => 'contract_example',
					),
				),
			)
		);

		$templates = array();
		foreach ( $ids as $attachment_id ) {
			$attachment_owner = (int) get_post_meta( $attachment_id, '_af_owner_user_id', true );
			if ( $attachment_owner && $attachment_owner !== $owner_user_id ) {
				continue;
			}
			$mime     = (string) get_post_mime_type( $attachment_id );
			$templates[] = array(
				'id'        => (int) $attachment_id,
				'title'     => (string) get_the_title( $attachment_id ),
				'file_name' => (string) wp_basename( (string) get_attached_file( $attachment_id ) ),
				'mime_type' => $mime,
			);
		}

		return $templates;
	}

	/**
	 * Returns the contract identity of the owner linked to an accommodation.
	 *
	 * Used by the lease form to pre-fill the owner placeholders so the operator
	 * never retypes data the system already knows.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return array{name:string,id_number:string}
	 */
	public function get_owner_identity_for_accommodation( $accommodation_id ) {
		$empty = array(
			'name'      => '',
			'id_number' => '',
		);

		$owner_user_id = $this->resolve_accommodation_owner_user_id( $accommodation_id );
		if ( ! $owner_user_id ) {
			return $empty;
		}

		$name      = '';
		$id_number = $this->get_owner_identification_number( $owner_user_id );

		$owner_user = get_userdata( $owner_user_id );
		if ( $owner_user ) {
			$name = sanitize_text_field( (string) $owner_user->display_name );
		}

		return array(
			'name'      => $name,
			'id_number' => $id_number,
		);
	}

	/**
	 * Resolves owner user ID for an accommodation with safe fallbacks.
	 *
	 * @param int $accommodation_id Accommodation ID.
	 * @return int
	 */
	private function resolve_accommodation_owner_user_id( $accommodation_id ) {
		$accommodation_id = absint( $accommodation_id );
		if ( ! $accommodation_id ) {
			return 0;
		}

		$owner_user_id = absint( get_post_meta( $accommodation_id, '_af_owner_id', true ) );
		if ( $owner_user_id > 0 ) {
			return $owner_user_id;
		}

		$legacy_owner_user_id = absint( get_post_meta( $accommodation_id, '_af_owner_user_id', true ) );
		if ( $legacy_owner_user_id > 0 ) {
			return $legacy_owner_user_id;
		}

		$post = get_post( $accommodation_id );
		if ( $post && ! empty( $post->post_author ) ) {
			$post_author_id = absint( $post->post_author );
			if ( $post_author_id > 0 ) {
				$author = get_user_by( 'id', $post_author_id );
				if ( $author && in_array( 'af_property_admin', (array) $author->roles, true ) ) {
					return $post_author_id;
				}
			}
		}

		return 0;
	}

	/**
	 * Returns owner identification number from owner contacts table.
	 *
	 * @param int $owner_user_id Owner user ID.
	 * @return string
	 */
	private function get_owner_identification_number( $owner_user_id ) {
		$owner_user_id = absint( $owner_user_id );
		if ( ! $owner_user_id ) {
			return '';
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'af_owner_contacts';
		$owner_id   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT owner_id FROM {$table_name} WHERE wp_user_id = %d ORDER BY id DESC LIMIT 1",
				$owner_user_id
			)
		);

		return sanitize_text_field( (string) $owner_id );
	}

	/**
	 * Builds standardized contract template context from an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $fallback_owner_user_id Owner user fallback ID.
	 * @return array<string,mixed>
	 */
	private function build_contract_template_context_from_attachment( $attachment_id, $fallback_owner_user_id = 0 ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return array();
		}

		$owner_user_id = absint( get_post_meta( $attachment_id, '_af_owner_user_id', true ) );
		if ( ! $owner_user_id ) {
			$owner_user_id = absint( $fallback_owner_user_id );
		}

		$path          = get_attached_file( $attachment_id );
		$mime_type     = (string) get_post_mime_type( $attachment_id );
		$cache_signature = '';
		if ( $path && file_exists( $path ) ) {
			$file_size = @filesize( $path );
			$file_mtime = @filemtime( $path );
			$cache_signature = sha1( $path . '|' . (string) $file_size . '|' . (string) $file_mtime . '|' . $mime_type );
		}

		$cached_signature = (string) get_post_meta( $attachment_id, '_af_template_text_cache_sig', true );
		$cached_text      = get_post_meta( $attachment_id, '_af_template_text_cache', true );
		$cached_text      = is_string( $cached_text ) ? $cached_text : '';

		if ( '' !== $cache_signature && '' !== $cached_text && hash_equals( $cached_signature, $cache_signature ) ) {
			$template_text = $cached_text;
		} else {
			$template_text = Arriendo_Facil_Contract_Text_Extractor::extract( $path, $mime_type );
			if ( '' === $template_text && '' !== $cached_text ) {
				$template_text = $cached_text;
			}

			if ( '' !== $cache_signature ) {
				update_post_meta( $attachment_id, '_af_template_text_cache_sig', $cache_signature );
				update_post_meta( $attachment_id, '_af_template_text_cache', $template_text );
			}
		}
		$owner_user    = get_user_by( 'id', $owner_user_id );

		return array(
			'attachment_id' => $attachment_id,
			'owner_user_id' => $owner_user_id,
			'owner_name'    => $owner_user ? (string) $owner_user->display_name : '',
			'owner_email'   => $owner_user ? (string) $owner_user->user_email : '',
			'file_name'     => $path ? wp_basename( $path ) : '',
			'mime_type'     => $mime_type,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'template_text' => $template_text,
		);
	}

	/**
	 * Creates a generated contract DOCX file and returns a secure document URL.
	 *
	 * @param int    $lease_id Lease ID.
	 * @param string $contract_text Contract body.
	 * @param array  $payload Lease and guest context used for formatting.
	 * @return string
	 */
	private function create_generated_contract_file( $lease_id, $contract_text, array $payload = array() ) {
		$lease_id = absint( $lease_id );
		if ( ! $lease_id || '' === trim( $contract_text ) ) {
			return '';
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}

		$contracts_dir = trailingslashit( $uploads['basedir'] ) . 'arriendo-facil/contracts';
		if ( ! wp_mkdir_p( $contracts_dir ) ) {
			return '';
		}

		$file_name = sprintf( 'lease-%d-contract-%s.docx', $lease_id, gmdate( 'Ymd-His' ) );
		$file_path = trailingslashit( $contracts_dir ) . $file_name;
		$mime_type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

		if ( ! $this->write_contract_docx_file( $file_path, $contract_text, $payload ) ) {
			$file_name = sprintf( 'lease-%d-contract-%s.doc', $lease_id, gmdate( 'Ymd-His' ) );
			$file_path = trailingslashit( $contracts_dir ) . $file_name;
			$mime_type = 'application/msword';

			if ( ! $this->write_contract_doc_fallback_file( $file_path, $contract_text ) ) {
				return '';
			}
		}

		$local_url = trailingslashit( $uploads['baseurl'] ) . 'arriendo-facil/contracts/' . rawurlencode( $file_name );

		return Arriendo_Facil_Contract_File_Store::persist( $lease_id, $file_path, $file_name, $local_url, $mime_type );
	}

	/**
	 * Writes a fallback MS Word-compatible HTML document when DOCX is unavailable.
	 *
	 * @param string $file_path Destination file path.
	 * @param string $contract_text Contract text.
	 * @return bool
	 */
	private function write_contract_doc_fallback_file( $file_path, $contract_text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $contract_text );
		if ( ! is_array( $lines ) ) {
			$lines = array( (string) $contract_text );
		}

		$body = '';
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				$body .= '<p>&nbsp;</p>';
				continue;
			}

			$body .= '<p>' . esc_html( $line ) . '</p>';
		}

		if ( '' === $body ) {
			$body = '<p>Contrato</p>';
		}

		$html = '<html><head><meta charset="UTF-8"></head><body style="font-family:Times New Roman, serif; font-size:12pt;">' . $body . '</body></html>';

		return false !== file_put_contents( $file_path, $html );
	}

	/**
	 * Writes a minimal DOCX file from plain contract text.
	 *
	 * @param string $file_path Destination file path.
	 * @param string $contract_text Contract text.
	 * @param array  $payload Lease and guest context for visual formatting.
	 * @return bool
	 */
	private function write_contract_docx_file( $file_path, $contract_text, array $payload = array() ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}

		$paragraphs = $this->build_contract_docx_paragraphs( $contract_text, $payload );
		if ( empty( $paragraphs ) ) {
			$paragraphs = array(
				array(
					'text'  => 'Contrato',
					'bold'  => false,
					'align' => 'left',
				),
			);
		}

		$doc_paragraphs_xml = '';
		foreach ( $paragraphs as $paragraph ) {
			$text  = isset( $paragraph['text'] ) ? (string) $paragraph['text'] : '';
			$bold  = ! empty( $paragraph['bold'] );
			$align = isset( $paragraph['align'] ) ? (string) $paragraph['align'] : 'both';
			$tab_stops = array();

			if ( isset( $paragraph['tab_stops'] ) && is_array( $paragraph['tab_stops'] ) ) {
				$tab_stops = $paragraph['tab_stops'];
			}

			if ( '' === $text ) {
				$doc_paragraphs_xml .= '<w:p/>';
				continue;
			}

			$paragraph_properties = '';
			if ( in_array( $align, array( 'left', 'center', 'right', 'both' ), true ) ) {
				$paragraph_properties .= '<w:jc w:val="' . esc_attr( $align ) . '"/>';
			}

			if ( ! empty( $tab_stops ) ) {
				$paragraph_properties .= '<w:tabs>';
				foreach ( $tab_stops as $tab_stop ) {
					$position = absint( $tab_stop );
					if ( $position > 0 ) {
						$paragraph_properties .= '<w:tab w:val="left" w:pos="' . $position . '"/>';
					}
				}
				$paragraph_properties .= '</w:tabs>';
			}

			$run_properties = '';
			if ( $bold ) {
				$run_properties = '<w:rPr><w:b/><w:bCs/></w:rPr>';
			}

			$doc_paragraphs_xml .= '<w:p>';
			if ( '' !== $paragraph_properties ) {
				$doc_paragraphs_xml .= '<w:pPr>' . $paragraph_properties . '</w:pPr>';
			}
			$doc_paragraphs_xml .= $this->build_docx_text_runs_xml( $text, $run_properties );
			$doc_paragraphs_xml .= '</w:p>';
		}

		$document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:wp14="http://schemas.microsoft.com/office/word/2010/wordprocessingDrawing" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:w10="urn:schemas-microsoft-com:office:word" xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml" xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup" xmlns:wpi="http://schemas.microsoft.com/office/word/2010/wordprocessingInk" xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml" xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" mc:Ignorable="w14 w15 wp14">'
			. '<w:body>' . $doc_paragraphs_xml . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/><w:cols w:space="720"/></w:sectPr></w:body></w:document>';

		$content_types_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			. '</Types>';

		$styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:styles xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" mc:Ignorable="">'
			. '<w:docDefaults>'
			. '<w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="24"/><w:szCs w:val="24"/></w:rPr></w:rPrDefault>'
			. '<w:pPrDefault><w:pPr><w:spacing w:before="0" w:after="160" w:line="360" w:lineRule="auto"/><w:jc w:val="both"/></w:pPr></w:pPrDefault>'
			. '</w:docDefaults>'
			. '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
			. '</w:styles>';

		$rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
			. '</Relationships>';

		$doc_rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';

		$zip->addFromString( '[Content_Types].xml', $content_types_xml );
		$zip->addFromString( '_rels/.rels', $rels_xml );
		$zip->addFromString( 'word/document.xml', $document_xml );
		$zip->addFromString( 'word/styles.xml', $styles_xml );
		$zip->addFromString( 'word/_rels/document.xml.rels', $doc_rels_xml );

		return $zip->close();
	}

	/**
	 * Builds DOCX paragraphs using strict contract format rules.
	 *
	 * @param string $contract_text Contract text.
	 * @param array  $payload Lease and guest context.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_contract_docx_paragraphs( $contract_text, array $payload = array() ) {
		$paragraphs = array();
		$lines      = preg_split( '/\r\n|\r|\n/', (string) $contract_text );

		if ( ! is_array( $lines ) ) {
			$lines = array( (string) $contract_text );
		}

		$has_title = false;
		$last_was_empty = false;
		$title_inserted = false;

		foreach ( $lines as $raw_line ) {
			$line = trim( (string) $raw_line );

			if ( '' === $line ) {
				if ( $last_was_empty ) {
					continue;
				}
				$paragraphs[] = array(
					'text'  => '',
					'bold'  => false,
					'align' => 'both',
				);
				$last_was_empty = true;
				continue;
			}

			$upper_line = strtoupper( $line );
			$is_title   = false !== strpos( $upper_line, 'CONTRATO DE ARRENDAMIENTO' );
			$is_clause  = 0 === strpos( $upper_line, 'CLAUSULA ' );

			if ( 0 === strpos( $line, 'Quito,' ) ) {
				continue;
			}

			if ( $this->is_contract_signature_line( $line ) ) {
				continue;
			}

			if ( ! $has_title && $is_title ) {
				$paragraphs[] = array(
					'text'  => 'CONTRATO DE ARRENDAMIENTO',
					'bold'  => true,
					'align' => 'center',
				);
				$paragraphs[] = array(
					'text'  => $this->format_contract_date_line( '' ),
					'bold'  => false,
					'align' => 'right',
				);
				$has_title      = true;
				$title_inserted = true;
				$last_was_empty = false;
				continue;
			}

			$paragraphs[] = array(
				'text'  => $line,
				'bold'  => $is_clause,
				'align' => $is_clause ? 'left' : 'both',
			);
			$last_was_empty = false;
		}

		if ( ! $has_title || ! $title_inserted ) {
			array_unshift(
				$paragraphs,
				array(
					'text'  => 'CONTRATO DE ARRENDAMIENTO',
					'bold'  => true,
					'align' => 'center',
				),
				array(
					'text'  => $this->format_contract_date_line( '' ),
					'bold'  => false,
					'align' => 'right',
				)
			);
		}

		$paragraphs = array_merge( $paragraphs, $this->build_contract_signature_paragraphs( $payload ) );

		return $paragraphs;
	}

	/**
	 * Builds WordprocessingML run XML, preserving tab stops in content.
	 *
	 * @param string $text Paragraph text.
	 * @param string $run_properties Run properties XML.
	 * @return string
	 */
	private function build_docx_text_runs_xml( $text, $run_properties = '' ) {
		$parts = explode( "\t", (string) $text );
		if ( 1 === count( $parts ) ) {
			return '<w:r>' . $run_properties . '<w:t xml:space="preserve">' . esc_xml( $text ) . '</w:t></w:r>';
		}

		$xml = '';
		foreach ( $parts as $index => $part ) {
			$xml .= '<w:r>' . $run_properties . '<w:t xml:space="preserve">' . esc_xml( $part ) . '</w:t></w:r>';
			if ( $index < count( $parts ) - 1 ) {
				$xml .= '<w:r>' . $run_properties . '<w:tab/></w:r>';
			}
		}

		return $xml;
	}

	/**
	 * Detects whether a line belongs to the signature section.
	 *
	 * @param string $line Contract line.
	 * @return bool
	 */
	private function is_contract_signature_line( $line ) {
		$clean = strtoupper( trim( (string) $line ) );

		if ( '' === $clean ) {
			return false;
		}

		if ( in_array( $clean, array( 'FIRMAS', 'ARRENDADOR', 'ARRENDATARIO', 'EL ARRENDADOR', 'EL ARRENDATARIO' ), true ) ) {
			return true;
		}

		if ( 0 === strpos( $clean, 'ARRENDADOR:' ) || 0 === strpos( $clean, 'ARRENDATARIO:' ) ) {
			return true;
		}

		if ( 0 === strpos( $clean, 'EL ARRENDADOR' ) || 0 === strpos( $clean, 'EL ARRENDATARIO' ) ) {
			return true;
		}

		if ( 0 === strpos( $clean, 'FIRMA:' ) || 0 === strpos( $clean, 'NOMBRE:' ) ) {
			return true;
		}

		if ( 0 === strpos( $clean, 'CEDULA:' ) || 0 === strpos( $clean, 'CÉDULA:' ) || 0 === strpos( $clean, 'CEDULA/RUC:' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Builds a fixed signature block with two sections.
	 *
	 * @param array $payload Lease and guest context.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_contract_signature_paragraphs( array $payload ) {
		$owner_name = isset( $payload['owner_name'] ) ? sanitize_text_field( (string) $payload['owner_name'] ) : '________________________';
		$owner_id   = isset( $payload['owner_id_number'] ) ? sanitize_text_field( (string) $payload['owner_id_number'] ) : '________________________';
		$guest_name = isset( $payload['guest_name'] ) ? sanitize_text_field( (string) $payload['guest_name'] ) : '________________________';
		$guest_id   = isset( $payload['guest_id_number'] ) ? sanitize_text_field( (string) $payload['guest_id_number'] ) : '________________________';

		return array(
			array(
				'text'  => '',
				'bold'  => false,
				'align' => 'both',
			),
			array(
				'text'  => 'FIRMAS',
				'bold'  => true,
				'align' => 'left',
			),
			array(
				'text'  => '',
				'bold'  => false,
				'align' => 'both',
			),
			array(
				'text'  => 'ARRENDADOR\tARRENDATARIO',
				'bold'  => true,
				'align' => 'left',
				'tab_stops' => array( 6400 ),
			),
			array(
				'text'  => 'Firma: ________________________\tFirma: ________________________',
				'bold'  => false,
				'align' => 'left',
				'tab_stops' => array( 6400 ),
			),
			array(
				'text'  => 'Nombre: ' . $owner_name . "\t" . 'Nombre: ' . $guest_name,
				'bold'  => false,
				'align' => 'left',
				'tab_stops' => array( 6400 ),
			),
			array(
				'text'  => 'Cedula/RUC: ' . $owner_id . "\t" . 'Cedula: ' . $guest_id,
				'bold'  => false,
				'align' => 'left',
				'tab_stops' => array( 6400 ),
			),
		);
	}

	/**
	 * Formats contract date line as "Quito, d de mes de Y".
	 *
	 * @param string $line Raw date line.
	 * @return string
	 */
	private function format_contract_date_line( $line ) {
		$timestamp = current_time( 'timestamp' );

		$months = array(
			1  => 'enero',
			2  => 'febrero',
			3  => 'marzo',
			4  => 'abril',
			5  => 'mayo',
			6  => 'junio',
			7  => 'julio',
			8  => 'agosto',
			9  => 'septiembre',
			10 => 'octubre',
			11 => 'noviembre',
			12 => 'diciembre',
		);

		$day        = (int) gmdate( 'j', $timestamp );
		$month_idx  = (int) gmdate( 'n', $timestamp );
		$year       = (string) gmdate( 'Y', $timestamp );
		$month_name = isset( $months[ $month_idx ] ) ? $months[ $month_idx ] : gmdate( 'F', $timestamp );

		return sprintf( 'Quito, %d de %s de %s', $day, $month_name, $year );
	}
}
