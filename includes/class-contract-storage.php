<?php
/**
 * Contract Storage - Safe Management of Base Contract Template
 *
 * @package Arriendo_Facil
 * @subpackage Storage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages secure access to base contract template
 *
 * @since 2.0
 */
class Arriendo_Facil_Contract_Storage {

	/**
	 * Base contract filename
	 */
	const BASE_CONTRACT = 'CONTRATO_ARRIENDO_Facil.docx';

	/**
	 * Storage directory path
	 */
	const STORAGE_DIR = '/includes/storage/contracts/';

	/**
	 * Get full path to storage directory
	 *
	 * @return string Absolute path to contracts storage directory
	 */
	public static function get_storage_dir() {
		return ARRIENDO_FACIL_PLUGIN_DIR . 'includes/storage/contracts/';
	}

	/**
	 * Get full path to base contract template
	 *
	 * @return string|false Absolute path if exists, false otherwise
	 */
	public static function get_base_contract_path() {
		$path = self::get_storage_dir() . self::BASE_CONTRACT;
		return file_exists( $path ) ? $path : false;
	}

	/**
	 * Get URL to storage directory for download (through PHP handler)
	 *
	 * @param string $filename Optional filename to access.
	 * @return string URL to download handler.
	 */
	public static function get_download_url( $filename = '' ) {
		if ( empty( $filename ) ) {
			$filename = self::BASE_CONTRACT;
		}
		return admin_url( 'admin-ajax.php?action=af_download_contract&file=' . urlencode( $filename ) . '&nonce=' . wp_create_nonce( 'af_contract_download' ) );
	}

	/**
	 * Check if base contract exists and is valid DOCX
	 *
	 * @return bool True if base contract exists and is readable
	 */
	public static function has_base_contract() {
		$path = self::get_base_contract_path();
		return $path && is_readable( $path );
	}

	/**
	 * Copy base contract to working directory with unique ID
	 *
	 * @param int $lease_id Lease ID for naming convention.
	 * @return string|WP_Error Path to copied contract or error.
	 */
	public static function create_working_copy( $lease_id ) {
		$source = self::get_base_contract_path();
		if ( ! $source ) {
			return new WP_Error( 'contract_not_found', __( 'Base contract template not found', 'arriendo-facil' ) );
		}

		if ( ! is_readable( $source ) ) {
			return new WP_Error( 'contract_not_readable', __( 'Base contract is not readable', 'arriendo-facil' ) );
		}

		// Create working copy with unique identifier
		$timestamp = gmdate( 'YmdHis' );
		$dest_name = "lease-{$lease_id}-{$timestamp}.docx";
		$dest_path = self::get_storage_dir() . $dest_name;

		if ( ! copy( $source, $dest_path ) ) {
			return new WP_Error( 'copy_failed', __( 'Could not create working copy of contract', 'arriendo-facil' ) );
		}

		return $dest_path;
	}

	/**
	 * Verify contract file integrity (basic check)
	 *
	 * @param string $file_path Path to contract file.
	 * @return bool True if file appears to be valid DOCX
	 */
	public static function is_valid_docx( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return false;
		}

		// Check DOCX signature (ZIP file with specific structure)
		$fp = fopen( $file_path, 'rb' );
		if ( ! $fp ) {
			return false;
		}

		$signature = fread( $fp, 4 );
		fclose( $fp );

		// DOCX files are ZIP files, signature is "PK\x03\x04"
		return 'PK' === substr( $signature, 0, 2 );
	}

	/**
	 * List all generated contract copies for a lease
	 *
	 * @param int $lease_id Lease ID.
	 * @return array Array of file paths.
	 */
	public static function get_lease_contracts( $lease_id ) {
		$dir = self::get_storage_dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$pattern = "lease-{$lease_id}-*.docx";
		$files   = glob( $dir . $pattern );

		return is_array( $files ) ? $files : array();
	}

	/**
	 * Clean up old contract copies (keep only last 5)
	 *
	 * @param int $lease_id Lease ID.
	 * @return int Number of files deleted.
	 */
	public static function cleanup_old_copies( $lease_id ) {
		$files = self::get_lease_contracts( $lease_id );
		if ( count( $files ) <= 5 ) {
			return 0;
		}

		// Sort by modified time, newest first
		usort( $files, function ( $a, $b ) {
			return filemtime( $b ) - filemtime( $a );
		} );

		// Delete all but the 5 newest
		$deleted = 0;
		for ( $i = 5; $i < count( $files ); $i++ ) {
			if ( unlink( $files[ $i ] ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Get placeholder schema for the contract template
	 *
	 * This defines all available placeholders and their properties
	 *
	 * @return array Placeholder configuration array
	 */
	public static function get_placeholders_schema() {
		return array(
			// Datos de Fecha y Lugar (7)
			'año'                    => array(
				'label'       => __( 'Año', 'arriendo-facil' ),
				'description' => __( 'Year of contract execution', 'arriendo-facil' ),
				'type'        => 'year',
				'section'     => 'fecha_lugar',
				'pattern'     => '/^\d{4}$/',
			),
			'mes'                    => array(
				'label'       => __( 'Mes', 'arriendo-facil' ),
				'description' => __( 'Month number (1-12)', 'arriendo-facil' ),
				'type'        => 'month',
				'section'     => 'fecha_lugar',
				'pattern'     => '/^(0?[1-9]|1[0-2])$/',
			),
			'dia_numero'             => array(
				'label'       => __( 'Día', 'arriendo-facil' ),
				'description' => __( 'Day of month (1-31)', 'arriendo-facil' ),
				'type'        => 'day',
				'section'     => 'fecha_lugar',
				'pattern'     => '/^(0?[1-9]|[12][0-9]|3[01])$/',
			),
			'fecha_incio'            => array(
				'label'       => __( 'Fecha de Inicio', 'arriendo-facil' ),
				'description' => __( 'Contract start date (YYYY-MM-DD)', 'arriendo-facil' ),
				'type'        => 'date',
				'section'     => 'fecha_lugar',
				'source'      => 'lease.start_date',
			),
			'fecha_fin'              => array(
				'label'       => __( 'Fecha de Fin', 'arriendo-facil' ),
				'description' => __( 'Contract end date (YYYY-MM-DD)', 'arriendo-facil' ),
				'type'        => 'date',
				'section'     => 'fecha_lugar',
				'source'      => 'lease.end_date',
			),
			'dias_notificacion'      => array(
				'label'       => __( 'Días de Notificación', 'arriendo-facil' ),
				'description' => __( 'Notification period in days', 'arriendo-facil' ),
				'type'        => 'integer',
				'section'     => 'fecha_lugar',
				'default'     => 30,
			),

			// Datos Propietario (2)
			'nombres_propietario'    => array(
				'label'       => __( 'Nombre del Propietario', 'arriendo-facil' ),
				'description' => __( 'Full name of property owner', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'propietario',
				'source'      => 'accommodation.owner_name',
			),
			'cedula_propietario'     => array(
				'label'       => __( 'Cédula del Propietario', 'arriendo-facil' ),
				'description' => __( 'Owner identification document', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'propietario',
				'source'      => 'accommodation.owner_id',
			),

			// Datos Inquilino (3)
			'nombres_inquilino'      => array(
				'label'       => __( 'Nombre del Inquilino', 'arriendo-facil' ),
				'description' => __( 'Full name of tenant', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'inquilino',
				'source'      => 'guest.display_name',
				'required'    => true,
			),
			'cedula_inquilino'       => array(
				'label'       => __( 'Cédula del Inquilino', 'arriendo-facil' ),
				'description' => __( 'Tenant identification document', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'inquilino',
				'source'      => 'guest.document_id',
				'required'    => true,
			),
			'nacionalidad_inquilino' => array(
				'label'       => __( 'Nacionalidad', 'arriendo-facil' ),
				'description' => __( 'Tenant nationality', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'inquilino',
				'source'      => 'guest.nationality',
			),

			// Datos Inmueble (8)
			'tipo_inmueble'          => array(
				'label'       => __( 'Tipo de Inmueble', 'arriendo-facil' ),
				'description' => __( 'Property type (apartment, house, etc.)', 'arriendo-facil' ),
				'type'        => 'select',
				'section'     => 'inmueble',
				'options'     => array(
					'apartamento' => __( 'Apartment', 'arriendo-facil' ),
					'casa'        => __( 'House', 'arriendo-facil' ),
					'oficina'     => __( 'Office', 'arriendo-facil' ),
					'local'       => __( 'Commercial', 'arriendo-facil' ),
				),
			),
			'dirección_inmueble'     => array(
				'label'       => __( 'Dirección del Inmueble', 'arriendo-facil' ),
				'description' => __( 'Full property address', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'inmueble',
				'source'      => 'accommodation.address',
				'required'    => true,
			),
			'n_habitacion'           => array(
				'label'       => __( 'Número de Habitaciones', 'arriendo-facil' ),
				'description' => __( 'Number of bedrooms', 'arriendo-facil' ),
				'type'        => 'integer',
				'section'     => 'inmueble',
			),
			'n_baños'                => array(
				'label'       => __( 'Número de Baños', 'arriendo-facil' ),
				'description' => __( 'Number of bathrooms', 'arriendo-facil' ),
				'type'        => 'integer',
				'section'     => 'inmueble',
			),
			'parqueadero'            => array(
				'label'       => __( 'Parqueaderos', 'arriendo-facil' ),
				'description' => __( 'Number of parking spaces', 'arriendo-facil' ),
				'type'        => 'integer',
				'section'     => 'inmueble',
			),
			'dimensiones_inmueble'   => array(
				'label'       => __( 'Dimensiones', 'arriendo-facil' ),
				'description' => __( 'Property size (m²)', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'inmueble',
			),
			'estado_mobiliario'      => array(
				'label'       => __( 'Estado del Mobiliario', 'arriendo-facil' ),
				'description' => __( 'Furniture condition description', 'arriendo-facil' ),
				'type'        => 'textarea',
				'section'     => 'inmueble',
			),
			'identificación'         => array(
				'label'       => __( 'Identificación del Inmueble', 'arriendo-facil' ),
				'description' => __( 'Property ID or reference', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'inmueble',
			),

			// Datos Financieros (8)
			'canon_mensual'          => array(
				'label'       => __( 'Renta Mensual', 'arriendo-facil' ),
				'description' => __( 'Monthly rent amount', 'arriendo-facil' ),
				'type'        => 'decimal',
				'section'     => 'financiero',
				'source'      => 'lease.monthly_rent',
				'required'    => true,
			),
			'canon_letra'            => array(
				'label'       => __( 'Renta en Letras', 'arriendo-facil' ),
				'description' => __( 'Monthly rent written in words', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'financiero',
				'computed'    => 'number_to_words:canon_mensual',
			),
			'plazo_contrato'         => array(
				'label'       => __( 'Plazo del Contrato', 'arriendo-facil' ),
				'description' => __( 'Contract duration (e.g., "12 meses")', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'financiero',
			),
			'monto número'           => array(
				'label'       => __( 'Monto en Números', 'arriendo-facil' ),
				'description' => __( 'Total amount in numbers', 'arriendo-facil' ),
				'type'        => 'decimal',
				'section'     => 'financiero',
			),
			'monto letra'            => array(
				'label'       => __( 'Monto en Letras', 'arriendo-facil' ),
				'description' => __( 'Total amount written in words', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'financiero',
				'computed'    => 'number_to_words:monto número',
			),
			'número_cuenta'          => array(
				'label'       => __( 'Número de Cuenta', 'arriendo-facil' ),
				'description' => __( 'Bank account number for payments', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'financiero',
			),
			'nombre_banco'           => array(
				'label'       => __( 'Nombre del Banco', 'arriendo-facil' ),
				'description' => __( 'Bank name', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'financiero',
			),
			'nombre_titular'         => array(
				'label'       => __( 'Nombre del Titular', 'arriendo-facil' ),
				'description' => __( 'Account holder name', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'financiero',
			),

			// Pagos de Garantía (2)
			'fecha_pago_garantia_1'  => array(
				'label'       => __( 'Fecha Primer Pago Garantía', 'arriendo-facil' ),
				'description' => __( 'First deposit payment due date', 'arriendo-facil' ),
				'type'        => 'date',
				'section'     => 'garantia',
			),
			'fecha_pago_garantia_2'  => array(
				'label'       => __( 'Fecha Segundo Pago Garantía', 'arriendo-facil' ),
				'description' => __( 'Second deposit payment due date', 'arriendo-facil' ),
				'type'        => 'date',
				'section'     => 'garantia',
			),

			// Otros (1)
			'n_b_social'             => array(
				'label'       => __( 'Nombre Comercial/Social', 'arriendo-facil' ),
				'description' => __( 'Business or social name', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'otros',
			),
		);
	}

	/**
	 * Get placeholders organized by section
	 *
	 * @return array Placeholders grouped by section
	 */
	public static function get_placeholders_by_section() {
		$schema   = self::get_placeholders_schema();
		$sections = array(
			'fecha_lugar' => __( 'Datos de Fecha y Lugar', 'arriendo-facil' ),
			'propietario' => __( 'Datos del Propietario', 'arriendo-facil' ),
			'inquilino'   => __( 'Datos del Inquilino', 'arriendo-facil' ),
			'inmueble'    => __( 'Datos del Inmueble', 'arriendo-facil' ),
			'financiero'  => __( 'Datos Financieros', 'arriendo-facil' ),
			'garantia'    => __( 'Pagos de Garantía', 'arriendo-facil' ),
			'otros'       => __( 'Otros Datos', 'arriendo-facil' ),
		);

		$grouped = array();
		foreach ( $sections as $section_id => $section_label ) {
			$grouped[ $section_id ] = array(
				'label'        => $section_label,
				'placeholders' => array(),
			);
		}

		foreach ( $schema as $placeholder => $config ) {
			$section = $config['section'] ?? 'otros';
			if ( isset( $grouped[ $section ] ) ) {
				$grouped[ $section ]['placeholders'][ $placeholder ] = $config;
			}
		}

		return $grouped;
	}

	/**
	 * Validate placeholder values
	 *
	 * @param array $values Placeholder values to validate.
	 * @return array|WP_Error Valid values or error with issues found.
	 */
	/**
	 * Validate placeholder values against schema
	 *
	 * @param array $values Array of placeholder values to validate.
	 * @return array ['valid' => bool, 'data' => array|null, 'errors' => array]
	 */
	public static function validate_placeholders( $values ) {
		$schema  = self::get_placeholders_schema();
		$errors  = array();
		$valid   = array();

		foreach ( $schema as $placeholder => $config ) {
			if ( ! isset( $values[ $placeholder ] ) ) {
				if ( ! empty( $config['required'] ) ) {
					$errors[ $placeholder ] = sprintf( __( '%s is required', 'arriendo-facil' ), $config['label'] );
				}
				continue;
			}

			$value = $values[ $placeholder ];

			// Type validation
			switch ( $config['type'] ) {
				case 'date':
					if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
						$errors[ $placeholder ] = sprintf( __( '%s debe estar en formato YYYY-MM-DD', 'arriendo-facil' ), $config['label'] );
					} else {
						$valid[ $placeholder ] = $value;
					}
					break;

				case 'integer':
					if ( ! is_numeric( $value ) || intval( $value ) != $value ) {
						$errors[ $placeholder ] = sprintf( __( '%s debe ser un número entero', 'arriendo-facil' ), $config['label'] );
					} else {
						$valid[ $placeholder ] = intval( $value );
					}
					break;

				case 'decimal':
					if ( ! is_numeric( $value ) ) {
						$errors[ $placeholder ] = sprintf( __( '%s debe ser un número', 'arriendo-facil' ), $config['label'] );
					} else {
						$valid[ $placeholder ] = floatval( $value );
					}
					break;

				default:
					$valid[ $placeholder ] = sanitize_text_field( $value );
					break;
			}
		}

		return array(
			'valid'  => empty( $errors ),
			'data'   => empty( $errors ) ? $valid : null,
			'errors' => $errors,
		);
	}
}
