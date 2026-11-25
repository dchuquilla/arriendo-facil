<?php
/**
 * Evidencia del portal (comprobantes de pago, fotos de incidentes y de reparación).
 *
 * Reglas:
 *  - Se acepta por CONTENIDO real (finfo), no por extensión ni por el tipo que declara el
 *    navegador: JPG, PNG, WebP y PDF. Todo lo demás se rechaza.
 *  - Las imágenes se re-codifican a JPEG: se pierde EXIF/GPS y cualquier carga escondida,
 *    y se limita el tamaño. Los PDF no pueden traer JavaScript ni acciones automáticas.
 *  - Se guarda en almacenamiento privado (R2 o carpeta fuera de la web) con nombre aleatorio.
 *    Nunca hay una URL pública: se entrega por un endpoint que valida la pertenencia.
 *  - Solo se agrega. El inquilino no borra ni edita; el gestor puede retirarla con motivo
 *    (borrado lógico, el archivo y su huella se conservan).
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Portal_Evidence
 */
class Arriendo_Facil_Portal_Evidence {

	const MAX_BYTES     = 8388608; // 8 MB por archivo.
	const MAX_FILES     = 6;
	const MAX_PIXELS    = 40000000; // Evita bombas de descompresión.
	const MAX_SIDE      = 2000;
	const JPEG_QUALITY  = 82;
	const DAILY_UPLOADS = 40;

	/**
	 * Tipos permitidos por contenido real.
	 *
	 * @var array<string,string> MIME => extensión guardada.
	 */
	const ALLOWED = array(
		'image/jpeg'      => 'jpg',
		'image/png'       => 'jpg',
		'image/webp'      => 'jpg',
		'application/pdf' => 'pdf',
	);

	/* ─────────────────────────────────────────────────────────────────────
	 * Inspección (sin WordPress, probada con PHPUnit)
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Valida el contenido de un archivo y lo deja listo para guardar.
	 *
	 * @param string $bytes Contenido recibido.
	 * @return array{bytes:string,mime:string,ext:string}|WP_Error
	 */
	public static function inspect( $bytes ) {
		$bytes = (string) $bytes;
		$size  = strlen( $bytes );

		if ( 0 === $size ) {
			return new WP_Error( 'af_ev_empty', __( 'El archivo está vacío.', 'arriendo-facil' ) );
		}
		if ( $size > self::MAX_BYTES ) {
			return new WP_Error( 'af_ev_big', __( 'Cada archivo puede pesar hasta 8 MB.', 'arriendo-facil' ) );
		}

		$finfo = new finfo( FILEINFO_MIME_TYPE );
		$mime  = (string) $finfo->buffer( $bytes );

		if ( ! isset( self::ALLOWED[ $mime ] ) ) {
			return new WP_Error( 'af_ev_type', __( 'Solo se aceptan fotos (JPG, PNG, WebP) o PDF.', 'arriendo-facil' ) );
		}

		if ( 'application/pdf' === $mime ) {
			return self::inspect_pdf( $bytes );
		}

		return self::reencode_image( $bytes );
	}

	/**
	 * @param string $bytes PDF.
	 * @return array{bytes:string,mime:string,ext:string}|WP_Error
	 */
	private static function inspect_pdf( $bytes ) {
		if ( 0 !== strpos( $bytes, '%PDF-' ) ) {
			return new WP_Error( 'af_ev_pdf', __( 'El PDF no es válido.', 'arriendo-facil' ) );
		}

		// Acciones automáticas, scripts y adjuntos embebidos: no tienen cabida en un comprobante.
		if ( preg_match( '#/(JavaScript|JS|Launch|OpenAction|AA|EmbeddedFile|RichMedia|XFA)(?![A-Za-z])#', $bytes ) ) {
			return new WP_Error( 'af_ev_pdf_active', __( 'El PDF contiene elementos no permitidos. Sube una foto o un PDF simple.', 'arriendo-facil' ) );
		}

		return array(
			'bytes' => $bytes,
			'mime'  => 'application/pdf',
			'ext'   => 'pdf',
		);
	}

	/**
	 * Re-codifica una imagen a JPEG: quita metadatos, corrige la orientación y acota el tamaño.
	 *
	 * @param string $bytes Imagen original.
	 * @return array{bytes:string,mime:string,ext:string}|WP_Error
	 */
	private static function reencode_image( $bytes ) {
		$bad = new WP_Error( 'af_ev_img', __( 'No pudimos leer la foto. Prueba con otra imagen.', 'arriendo-facil' ) );

		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return new WP_Error( 'af_ev_gd', __( 'El servidor no puede procesar imágenes ahora. Inténtalo más tarde.', 'arriendo-facil' ) );
		}

		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- imagen inválida devuelve false.
		if ( ! $info || $info[0] < 1 || $info[1] < 1 || ( $info[0] * $info[1] ) > self::MAX_PIXELS ) {
			return $bad;
		}

		$src = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $src ) {
			return $bad;
		}

		// La orientación vive en EXIF y se pierde al re-codificar: se aplica antes.
		if ( IMAGETYPE_JPEG === $info[2] && function_exists( 'exif_read_data' ) ) {
			$exif  = @exif_read_data( 'data://image/jpeg;base64,' . base64_encode( $bytes ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$angle = 0;
			if ( is_array( $exif ) && isset( $exif['Orientation'] ) ) {
				$angle = array( 3 => 180, 6 => -90, 8 => 90 )[ (int) $exif['Orientation'] ] ?? 0;
			}
			if ( 0 !== $angle ) {
				$rotated = imagerotate( $src, $angle, 0 );
				if ( $rotated ) {
					$src = $rotated;
				}
			}
		}

		$w = imagesx( $src );
		$h = imagesy( $src );
		if ( max( $w, $h ) > self::MAX_SIDE ) {
			$scale = self::MAX_SIDE / max( $w, $h );
			$new_w = max( 1, (int) round( $w * $scale ) );
			$new_h = max( 1, (int) round( $h * $scale ) );
			$small = imagescale( $src, $new_w, $new_h );
			if ( $small ) {
				$src = $small;
				$w   = $new_w;
				$h   = $new_h;
			}
		}

		// Fondo blanco: las transparencias de un PNG no deben quedar negras.
		$canvas = imagecreatetruecolor( $w, $h );
		imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );
		imagecopy( $canvas, $src, 0, 0, 0, 0, $w, $h );

		ob_start();
		$ok  = imagejpeg( $canvas, null, self::JPEG_QUALITY );
		$out = (string) ob_get_clean();

		if ( ! $ok || '' === $out ) {
			return $bad;
		}

		return array(
			'bytes' => $out,
			'mime'  => 'image/jpeg',
			'ext'   => 'jpg',
		);
	}

	/**
	 * Archivos subidos en un campo múltiple de $_FILES, validados por contenido.
	 * Todo o nada: si uno falla no se guarda ninguno.
	 *
	 * @param string $field   Nombre del campo (p. ej. "files").
	 * @param int    $min     Mínimo de archivos exigido.
	 * @param int    $max     Máximo de archivos.
	 * @return array<int,array{bytes:string,mime:string,ext:string}>|WP_Error
	 */
	public static function from_request( $field, $min = 0, $max = self::MAX_FILES ) {
		$out = array();

		if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ] ) || empty( $_FILES[ $field ]['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- el llamador ya validó sesión y CSRF.
			return $min > 0 ? new WP_Error( 'af_ev_none', __( 'Adjunta al menos un archivo.', 'arriendo-facil' ) ) : array();
		}

		$file  = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se valida por contenido.
		$names = (array) $file['tmp_name'];
		$errs  = (array) $file['error'];

		$names = array_values( array_filter( $names, 'strlen' ) );
		if ( count( $names ) > $max ) {
			/* translators: %d: max files */
			return new WP_Error( 'af_ev_many', sprintf( __( 'Puedes adjuntar hasta %d archivos a la vez.', 'arriendo-facil' ), $max ) );
		}

		foreach ( $names as $i => $tmp ) {
			if ( isset( $errs[ $i ] ) && UPLOAD_ERR_OK !== (int) $errs[ $i ] ) {
				return new WP_Error( 'af_ev_upload', __( 'No se pudo recibir uno de los archivos. Inténtalo de nuevo.', 'arriendo-facil' ) );
			}
			if ( ! is_uploaded_file( $tmp ) ) {
				return new WP_Error( 'af_ev_upload', __( 'No se pudo recibir uno de los archivos. Inténtalo de nuevo.', 'arriendo-facil' ) );
			}
			if ( filesize( $tmp ) > self::MAX_BYTES ) {
				return new WP_Error( 'af_ev_big', __( 'Cada archivo puede pesar hasta 8 MB.', 'arriendo-facil' ) );
			}

			$checked = self::inspect( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			$out[] = $checked;
		}

		if ( count( $out ) < $min ) {
			return new WP_Error( 'af_ev_none', __( 'Adjunta al menos un archivo.', 'arriendo-facil' ) );
		}

		return $out;
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Almacenamiento
	 * ─────────────────────────────────────────────────────────────────── */

	/**
	 * Escribe un archivo ya validado en almacenamiento privado (R2, o carpeta fuera de la web).
	 *
	 * @param array{bytes:string,mime:string,ext:string} $file      Archivo validado.
	 * @param int                                        $guest_id  Dueño.
	 * @param string                                     $person    Nombre para la carpeta local.
	 * @param string                                     $prefix    Prefijo del nombre (p. ej. "evidencia-payment").
	 * @return array{storage:string,key:string}|WP_Error
	 */
	public static function store_bytes( array $file, $guest_id, $person, $prefix ) {
		$guest_id = absint( $guest_id );
		$token    = strtolower( wp_generate_password( 16, false, false ) );

		if ( Arriendo_Facil_Private_Storage::is_configured() ) {
			$remote = 'evidence/' . $guest_id . '/' . $token . '.' . $file['ext'];
			$put    = Arriendo_Facil_Private_Storage::upload( $file['bytes'], $remote, $file['mime'] );
			if ( ! is_wp_error( $put ) ) {
				return array( 'storage' => 'r2', 'key' => $remote );
			}
			error_log( sprintf( 'Arriendo Facil: R2 rechazó un archivo del inquilino #%d (%s). Se usa almacenamiento local privado.', $guest_id, $put->get_error_code() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$stored = Arriendo_Facil_Local_Private_Storage::store( $file['bytes'], Arriendo_Facil_Local_Private_Storage::CATEGORY_GUESTS, $guest_id, $person, $prefix . '-' . $token, $file['ext'] );
		if ( is_wp_error( $stored ) ) {
			return new WP_Error( 'af_ev_store', __( 'No pudimos guardar el archivo. Inténtalo de nuevo en unos minutos.', 'arriendo-facil' ) );
		}

		return array( 'storage' => Arriendo_Facil_Local_Private_Storage::STORAGE_ID, 'key' => $stored );
	}

	/**
	 * Guarda archivos ya validados y registra una fila por cada uno.
	 *
	 * @param array<int,array{bytes:string,mime:string,ext:string}> $files        Salida de from_request().
	 * @param int                                                    $guest_id     Dueño de la evidencia.
	 * @param int                                                    $lease_id     Contrato.
	 * @param string                                                 $subject_type service_payment | incident.
	 * @param int                                                    $subject_id   Registro.
	 * @param string                                                 $stage        report | repair | payment.
	 * @param string                                                 $uploaded_by  tenant | manager.
	 * @return int[]|WP_Error IDs creados.
	 */
	public static function save( array $files, $guest_id, $lease_id, $subject_type, $subject_id, $stage, $uploaded_by ) {
		global $wpdb;

		$guest_id = absint( $guest_id );
		$ids      = array();
		$person   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(first_name, ' ', last_name) FROM {$wpdb->prefix}af_guests WHERE id = %d", $guest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $files as $file ) {
			$put = self::store_bytes( $file, $guest_id, trim( $person ), 'evidencia-' . $stage );
			if ( is_wp_error( $put ) ) {
				self::discard( $ids );

				return $put;
			}
			$storage = $put['storage'];
			$key     = $put['key'];

			$inserted = $wpdb->insert(
				Arriendo_Facil_Portal_Schema::evidence_table(),
				array(
					'guest_id'        => $guest_id,
					'lease_id'        => $lease_id ? absint( $lease_id ) : null,
					'subject_type'    => sanitize_key( $subject_type ),
					'subject_id'      => absint( $subject_id ),
					'stage'           => sanitize_key( $stage ),
					'uploaded_by'     => 'manager' === $uploaded_by ? 'manager' : 'tenant',
					'storage'         => $storage,
					'object_key'      => $key,
					'mime_type'       => $file['mime'],
					'file_size'       => strlen( $file['bytes'] ),
					'checksum_sha256' => hash( 'sha256', $file['bytes'] ),
					'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);

			if ( ! $inserted ) {
				if ( 'r2' === $storage ) {
					Arriendo_Facil_Private_Storage::delete( $key );
				} else {
					Arriendo_Facil_Local_Private_Storage::delete( $key );
				}
				self::discard( $ids );

				return new WP_Error( 'af_ev_record', __( 'No pudimos registrar el archivo. Inténtalo de nuevo.', 'arriendo-facil' ) );
			}

			$ids[] = (int) $wpdb->insert_id;
		}

		return $ids;
	}

	/**
	 * Deshace los guardados de una subida que falló a medias.
	 *
	 * @param int[] $ids Evidencias ya creadas.
	 * @return void
	 */
	private static function discard( array $ids ) {
		global $wpdb;

		foreach ( $ids as $id ) {
			$row = self::get( $id );
			if ( ! $row ) {
				continue;
			}
			if ( 'r2' === $row->storage ) {
				Arriendo_Facil_Private_Storage::delete( $row->object_key );
			} else {
				Arriendo_Facil_Local_Private_Storage::delete( $row->object_key );
			}
			$wpdb->delete( Arriendo_Facil_Portal_Schema::evidence_table(), array( 'id' => (int) $id ), array( '%d' ) );
		}
	}

	/**
	 * @param int $id Evidencia.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Arriendo_Facil_Portal_Schema::evidence_table() . ' WHERE id = %d', absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Evidencias vigentes de un registro, siempre acotadas al inquilino.
	 *
	 * @param string      $subject_type Tipo.
	 * @param int         $subject_id   ID.
	 * @param int         $guest_id     Inquilino dueño.
	 * @param string|null $stage        Filtro de etapa.
	 * @return array<int,object>
	 */
	public static function for_subject( $subject_type, $subject_id, $guest_id, $stage = null ) {
		global $wpdb;

		$sql  = 'SELECT id, stage, uploaded_by, mime_type, created_at FROM ' . Arriendo_Facil_Portal_Schema::evidence_table() . ' WHERE subject_type = %s AND subject_id = %d AND guest_id = %d AND removed_at IS NULL'; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$args = array( sanitize_key( $subject_type ), absint( $subject_id ), absint( $guest_id ) );
		if ( null !== $stage ) {
			$sql   .= ' AND stage = %s';
			$args[] = sanitize_key( $stage );
		}

		return (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id ASC LIMIT 60', $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Entrega el archivo al navegador y termina. El llamador ya autorizó la petición.
	 *
	 * @param object $row Fila de evidencia.
	 * @return void|WP_Error Solo vuelve si falla.
	 */
	public static function stream( $row ) {
		if ( ! $row || ! empty( $row->removed_at ) ) {
			return new WP_Error( 'af_ev_gone', __( 'Archivo no disponible.', 'arriendo-facil' ) );
		}

		$name = 'evidencia-' . (int) $row->id . ( 'application/pdf' === $row->mime_type ? '.pdf' : '.jpg' );

		if ( 'r2' !== $row->storage ) {
			return Arriendo_Facil_Local_Private_Storage::stream( $row->object_key, $name );
		}

		$body = Arriendo_Facil_Private_Storage::download( $row->object_key );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		nocache_headers();
		header( 'Content-Type: ' . ( 'application/pdf' === $row->mime_type ? 'application/pdf' : 'image/jpeg' ) );
		header( 'Content-Disposition: inline; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binario ya validado.
		exit;
	}

	/**
	 * Retira una evidencia (solo gestor). Se conserva el archivo y su huella.
	 *
	 * @param int    $id     Evidencia.
	 * @param int    $user   Gestor.
	 * @param string $reason Motivo obligatorio.
	 * @return bool
	 */
	public static function remove( $id, $user, $reason ) {
		global $wpdb;

		return (bool) $wpdb->update(
			Arriendo_Facil_Portal_Schema::evidence_table(),
			array(
				'removed_at'     => gmdate( 'Y-m-d H:i:s' ),
				'removed_by'     => absint( $user ),
				'removed_reason' => mb_substr( sanitize_text_field( $reason ), 0, 190 ),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
	}
}
