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
			return new WP_Error( 'contract_not_found', __( 'No se encontró la plantilla base del contrato.', 'arriendo-facil' ) );
		}

		if ( ! is_readable( $source ) ) {
			return new WP_Error( 'contract_not_readable', __( 'La plantilla base del contrato no se puede leer.', 'arriendo-facil' ) );
		}

		// Create working copy with unique identifier
		$timestamp = gmdate( 'YmdHis' );
		$dest_name = "lease-{$lease_id}-{$timestamp}.docx";
		$dest_path = self::get_storage_dir() . $dest_name;

		if ( ! copy( $source, $dest_path ) ) {
			return new WP_Error( 'copy_failed', __( 'No se pudo crear la copia de trabajo del contrato.', 'arriendo-facil' ) );
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
			/*
			 * Fecha de firma: se calculan en el servidor en el momento de crear
			 * el contrato, nunca se piden al operador (`system` los oculta del
			 * formulario y `get_placeholders_by_section()` los descarta).
			 */
			'año'                    => array(
				'label'       => __( 'Año', 'arriendo-facil' ),
				'description' => __( 'Año en que se firma el contrato.', 'arriendo-facil' ),
				'type'        => 'year',
				'section'     => 'fecha_lugar',
				'pattern'     => '/^\d{4}$/',
				'source'      => 'contract.signed_year',
				'system'      => true,
			),
			'mes'                    => array(
				'label'       => __( 'Mes', 'arriendo-facil' ),
				'description' => __( 'Mes de firma del contrato (1-12).', 'arriendo-facil' ),
				'type'        => 'month',
				'section'     => 'fecha_lugar',
				'pattern'     => '/^(0?[1-9]|1[0-2])$/',
				'source'      => 'contract.signed_month',
				'system'      => true,
			),
			'dia_numero'             => array(
				'label'       => __( 'Día', 'arriendo-facil' ),
				'description' => __( 'Día de firma del contrato (1-31).', 'arriendo-facil' ),
				'type'        => 'day',
				'section'     => 'fecha_lugar',
				'pattern'     => '/^(0?[1-9]|[12][0-9]|3[01])$/',
				'source'      => 'contract.signed_day',
				'system'      => true,
			),

			// Fechas del contrato: espejo de los campos base del paso "Vigencia".
			'fecha_incio'            => array(
				'label'       => __( 'Fecha de Inicio', 'arriendo-facil' ),
				'description' => __( 'Fecha de inicio del contrato.', 'arriendo-facil' ),
				'type'        => 'date',
				'section'     => 'fecha_lugar',
				'source'      => 'lease.start_date',
				'system'      => true,
			),
			'fecha_fin'              => array(
				'label'       => __( 'Fecha de Fin', 'arriendo-facil' ),
				'description' => __( 'Fecha de finalización del contrato.', 'arriendo-facil' ),
				'type'        => 'date',
				'section'     => 'fecha_lugar',
				'source'      => 'lease.end_date',
				'system'      => true,
			),
			'dias_notificacion'      => array(
				'label'       => __( 'Días de Notificación', 'arriendo-facil' ),
				'description' => __( 'Días de aviso previo antes del vencimiento.', 'arriendo-facil' ),
				'type'        => 'integer',
				'section'     => 'fecha_lugar',
				'default'     => 30,
			),

			// Datos Propietario (2)
			'nombres_propietario'    => array(
				'label'       => __( 'Nombre del Propietario', 'arriendo-facil' ),
				'description' => __( 'Nombre completo del propietario del inmueble.', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'propietario',
				'source'      => 'accommodation.owner_name',
			),
			'cedula_propietario'     => array(
				'label'       => __( 'Cédula del Propietario', 'arriendo-facil' ),
				'description' => __( 'Cédula o identificación del propietario.', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'propietario',
				'source'      => 'accommodation.owner_id',
			),

			// Datos Inquilino (3)
			'nombres_inquilino'      => array(
				'label'       => __( 'Nombre del Inquilino', 'arriendo-facil' ),
				'description' => __( 'Nombre completo del inquilino.', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'inquilino',
				'source'      => 'guest.display_name',
				'required'    => true,
			),
			'cedula_inquilino'       => array(
				'label'       => __( 'Cédula del Inquilino', 'arriendo-facil' ),
				'description' => __( 'Cédula o identificación del inquilino.', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'inquilino',
				'source'      => 'guest.document_id',
				'required'    => true,
			),
			'nacionalidad_inquilino' => array(
				'label'       => __( 'Nacionalidad', 'arriendo-facil' ),
				'description' => __( 'Nacionalidad del inquilino.', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'inquilino',
				'source'      => 'guest.nationality',
			),

			// Datos Inmueble (8)
			'tipo_inmueble'          => array(
				'label'       => __( 'Tipo de Inmueble', 'arriendo-facil' ),
				'description' => __( 'Tipo de inmueble arrendado.', 'arriendo-facil' ),
				'type'        => 'select',
				'section'     => 'inmueble',
				'options'     => array(
					'apartamento' => __( 'Apartamento', 'arriendo-facil' ),
					'casa'        => __( 'Casa', 'arriendo-facil' ),
					'oficina'     => __( 'Oficina', 'arriendo-facil' ),
					'local'       => __( 'Local comercial', 'arriendo-facil' ),
				),
				'source' => 'accommodation.property_type',
			),
			'dirección_inmueble'     => array(
				'label'       => __( 'Dirección del Inmueble', 'arriendo-facil' ),
				'description' => __( 'Dirección completa del inmueble.', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'inmueble',
				'source'      => 'accommodation.address',
				'required'    => true,
			),
			'n_habitacion'           => array(
				'label'       => __( 'Número de Habitaciones', 'arriendo-facil' ),
				'description' => __( 'Cantidad de habitaciones.', 'arriendo-facil' ),
				'type'        => 'integer',
				'section'     => 'inmueble',
				'source' => 'accommodation.bedrooms',
			),
			'n_baños'                => array(
				'label'       => __( 'Número de Baños', 'arriendo-facil' ),
				'description' => __( 'Cantidad de baños.', 'arriendo-facil' ),
				'type'        => 'integer',
				'section'     => 'inmueble',
				'source' => 'accommodation.bathrooms',
			),
			'parqueadero'            => array(
				'label'       => __( 'Parqueaderos', 'arriendo-facil' ),
				'description' => __( 'Cantidad de parqueaderos.', 'arriendo-facil' ),
				'type'        => 'integer',
				'section'     => 'inmueble',
				'source' => 'accommodation.parking',
			),
			'dimensiones_inmueble'   => array(
				'label'       => __( 'Dimensiones', 'arriendo-facil' ),
				'description' => __( 'Superficie del inmueble en m².', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'inmueble',
				'source' => 'accommodation.square_meters',
			),
			'estado_mobiliario'      => array(
				'label'       => __( 'Estado del Inmueble', 'arriendo-facil' ),
				'description' => __( 'Estado en que se entrega el inmueble.', 'arriendo-facil' ),
				'type'        => 'select',
				'section'     => 'inmueble',
				'options'     => array(
					'amueblado'     => __( 'Amueblado', 'arriendo-facil' ),
					'semi_amoblado' => __( 'Semi-amoblado', 'arriendo-facil' ),
					'sin_amoblar'   => __( 'Sin amoblar', 'arriendo-facil' ),
					'nuevo'         => __( 'Nuevo', 'arriendo-facil' ),
					'buen_estado'   => __( 'Buen estado', 'arriendo-facil' ),
					'reparacion'    => __( 'Requiere reparación', 'arriendo-facil' ),
				),
				'source' => 'accommodation.delivery_state',
			),
			'identificación'         => array(
				'label'       => __( 'Identificación del Inmueble', 'arriendo-facil' ),
				'description' => __( 'Código o referencia del inmueble.', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'inmueble',
				'source' => 'accommodation.reference',
			),

			// Datos Financieros (8)
			'canon_mensual'          => array(
				'label'       => __( 'Renta Mensual', 'arriendo-facil' ),
				'description' => __( 'Valor de la renta mensual.', 'arriendo-facil' ),
				'type'        => 'decimal',
				'section'     => 'financiero',
				'source'      => 'lease.monthly_rent',
				'required'    => true,
				// Espejo del campo base "Canon Mensual" del paso Vigencia.
				'system'      => true,
			),
			'canon_letra'            => array(
				'label'       => __( 'Renta en Letras', 'arriendo-facil' ),
				'description' => __( 'Renta mensual escrita en letras.', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'financiero',
				'computed'    => 'number_to_words:canon_mensual',
			),
			'plazo_contrato'         => array(
				'label'       => __( 'Plazo del Contrato', 'arriendo-facil' ),
				'description' => __( 'Duración del contrato, por ejemplo "12 meses".', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'financiero',
				'source' => 'lease.duration',
			),
			'monto_numero'           => array(
				'label'       => __( 'Monto en Números', 'arriendo-facil' ),
				'description' => __( 'Valor total de la garantía en números.', 'arriendo-facil' ),
				'type'        => 'decimal',
				'section'     => 'financiero',
				'source'      => 'lease.deposit_amount',
				// Espejo del campo base "Garantía" del paso Vigencia.
				'system'      => true,
			),
			'monto_letra'            => array(
				'label'       => __( 'Monto en Letras', 'arriendo-facil' ),
				'description' => __( 'Valor de la garantía escrito en letras.', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'financiero',
				'computed'    => 'number_to_words:monto_numero',
			),
			'número_cuenta'          => array(
				'label'       => __( 'Número de Cuenta', 'arriendo-facil' ),
				'description' => __( 'Número de cuenta para los pagos.', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'financiero',
				'source' => 'manual',
			),
			'nombre_banco'           => array(
				'label'       => __( 'Nombre del Banco', 'arriendo-facil' ),
				'description' => __( 'Banco donde se recibe el pago.', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'financiero',
				'source' => 'manual',
			),
			'nombre_titular'         => array(
				'label'       => __( 'Nombre del Titular', 'arriendo-facil' ),
				'description' => __( 'Titular de la cuenta de pago.', 'arriendo-facil' ),
				'type'        => 'text',
				'section'     => 'financiero',
				'source' => 'owner.name',
			),

			// Pagos de Garantía (2)
			'fecha_pago_garantia_1'  => array(
				'label'       => __( 'Fecha Primer Pago Garantía', 'arriendo-facil' ),
				'description' => __( 'Fecha del primer pago de la garantía.', 'arriendo-facil' ),
				'type'        => 'date',
				'section'     => 'garantia',
				'source' => 'lease.deposit_due_1',
			),
			'fecha_pago_garantia_2'  => array(
				'label'       => __( 'Fecha Segundo Pago Garantía', 'arriendo-facil' ),
				'description' => __( 'Fecha del segundo pago de la garantía.', 'arriendo-facil' ),
				'type'        => 'date',
				'section'     => 'garantia',
				'source' => 'lease.deposit_due_2',
			),
		);
	}

	/**
	 * Get placeholders organized by section
	 *
	 * Placeholders flagged as `system` are computed in the server (or are a
	 * mirror of a base form field) and are never rendered in the wizard.
	 *
	 * @return array Placeholders grouped by section
	 */
	public static function get_placeholders_by_section() {
		$schema   = self::get_placeholders_schema();
		$sections = array(
			'fecha_lugar' => __( 'Notificaciones', 'arriendo-facil' ),
			'propietario' => __( 'Propietario', 'arriendo-facil' ),
			'inquilino'   => __( 'Datos del Inquilino', 'arriendo-facil' ),
			'inmueble'    => __( 'Datos del Inmueble', 'arriendo-facil' ),
			'financiero'  => __( 'Financiero', 'arriendo-facil' ),
			'garantia'    => __( 'Garantía', 'arriendo-facil' ),
		);

		$grouped = array();
		foreach ( $sections as $section_id => $section_label ) {
			$grouped[ $section_id ] = array(
				'label'        => $section_label,
				'placeholders' => array(),
			);
		}

		foreach ( $schema as $placeholder => $config ) {
			if ( ! empty( $config['system'] ) ) {
				continue;
			}
			$section = $config['section'] ?? '';
			if ( isset( $grouped[ $section ] ) ) {
				$grouped[ $section ]['placeholders'][ $placeholder ] = $config;
			}
		}

		// Drop empty sections so the wizard never renders an empty step.
		return array_filter(
			$grouped,
			static function ( $group ) {
				return ! empty( $group['placeholders'] );
			}
		);
	}

	/**
	 * Completa los placeholders que nunca se piden al operador.
	 *
	 * Abarca los marcados como `system` (fecha de firma y los espejos de los
	 * campos base del formulario) más los derivados que se calculan a partir
	 * de la vigencia y los montos. Solo rellena claves vacías o ausentes: lo
	 * que el operador escribió en el formulario siempre gana.
	 *
	 * @param array  $values        Placeholders recibidos del formulario.
	 * @param string $start_date    Fecha de inicio (Y-m-d).
	 * @param string $end_date      Fecha de fin (Y-m-d).
	 * @param float  $monthly_rent  Canon mensual.
	 * @param float  $deposit_amount Garantía.
	 * @return array
	 */
	public static function with_defaults( $values, $start_date, $end_date, $monthly_rent, $deposit_amount ) {
		if ( ! is_array( $values ) ) {
			$values = array();
		}

		$now = current_time( 'mysql' );
		$now = is_string( $now ) && 10 === strlen( $now ) ? $now : gmdate( 'Y-m-d' );

		$system = array(
			'año'            => substr( $now, 0, 4 ),
			'mes'            => (string) (int) substr( $now, 5, 2 ),
			'dia_numero'     => (string) (int) substr( $now, 8, 2 ),
			'fecha_incio'    => $start_date,
			'fecha_fin'      => $end_date,
			'dias_notificacion' => '30',
			'fecha_pago_garantia_1' => $start_date,
			'fecha_pago_garantia_2' => self::add_months( $start_date, 1 ),
		);

		if ( $monthly_rent > 0 ) {
			$system['canon_mensual'] = number_format( $monthly_rent, 2, '.', '' );
		}
		if ( $deposit_amount > 0 ) {
			$system['monto_numero'] = number_format( $deposit_amount, 2, '.', '' );
		}
		$duration = self::contract_duration( $start_date, $end_date );
		if ( $duration ) {
			$system['plazo_contrato'] = $duration;
		}

		foreach ( $system as $key => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			if ( ! isset( $values[ $key ] ) || '' === trim( (string) $values[ $key ] ) ) {
				$values[ $key ] = $value;
			}
		}

		return $values;
	}

	/**
	 * Duración del contrato en meses, expresada en texto.
	 *
	 * @param string $start_date Fecha de inicio (Y-m-d).
	 * @param string $end_date   Fecha de fin (Y-m-d).
	 * @return string '' cuando las fechas no son válidas.
	 */
	private static function contract_duration( $start_date, $end_date ) {
		$start = self::parse_date( $start_date );
		$end   = self::parse_date( $end_date );
		if ( ! $start || ! $end || $end <= $start ) {
			return '';
		}

		$months = ( (int) $end->format( 'Y' ) - (int) $start->format( 'Y' ) ) * 12
			+ ( (int) $end->format( 'n' ) - (int) $start->format( 'n' ) );
		if ( (int) $end->format( 'j' ) < (int) $start->format( 'j' ) ) {
			--$months;
		}
		if ( $months < 1 ) {
			return '';
		}

		return 1 === $months ? __( '1 mes', 'arriendo-facil' ) : sprintf( __( '%d meses', 'arriendo-facil' ), $months );
	}

	/**
	 * Suma meses a una fecha Y-m-d.
	 *
	 * @param string $date   Fecha origen.
	 * @param int    $months Meses a sumar.
	 * @return string '' cuando la fecha no es válida.
	 */
	private static function add_months( $date, $months ) {
		$parsed = self::parse_date( $date );
		if ( ! $parsed ) {
			return '';
		}
		$parsed->modify( sprintf( '%+d months', (int) $months ) );

		return $parsed->format( 'Y-m-d' );
	}

	/**
	 * @param string $date Fecha Y-m-d.
	 * @return DateTime|null
	 */
	private static function parse_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}

		return DateTime::createFromFormat( 'Y-m-d', $date );
	}

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
					$errors[ $placeholder ] = sprintf( __( '%s es obligatorio.', 'arriendo-facil' ), $config['label'] );
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
