<?php
/**
 * Configuración SRI – Facturación Electrónica Ecuador.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'arriendo-facil' ) );
}

if ( ! function_exists( 'check_admin_referer' ) || ! function_exists( 'wp_nonce_field' ) ) {
	wp_die( esc_html__( 'Error de seguridad: funciones de nonce no disponibles.', 'arriendo-facil' ) );
}

// ─── POST handlers ───────────────────────────────────────────────────────────

$af_sri_notice = null;

// Save general + environment settings.
if ( isset( $_POST['af_save_sri_config'] ) ) {
	check_admin_referer( 'af_sri_settings_nonce' );

	// Normalize first: accept cédula (10 digits) → auto-expand to RUC (append 001).
	$ruc = Arriendo_Facil_SRI_Config::normalize_ruc(
		preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['af_ruc'] ?? '' ) ) )
	);
	$email = sanitize_email( wp_unslash( $_POST['af_email_notificacion'] ?? '' ) );
	$dir_establecimiento = sanitize_text_field( wp_unslash( $_POST['af_dir_establecimiento'] ?? '' ) );

	// Validate RUC
	$ruc_validation = Arriendo_Facil_SRI_Config::validate_ruc( $ruc );
	if ( is_wp_error( $ruc_validation ) ) {
		$af_sri_notice = array( 'type' => 'error', 'msg' => $ruc_validation->get_error_message() );
	}
	// Validate Email is required and valid
	elseif ( empty( $email ) || ! is_email( $email ) ) {
		$af_sri_notice = array( 'type' => 'error', 'msg' => __( '❌ El email para notificaciones es OBLIGATORIO y debe ser válido. El SRI envía las autorizaciones a este correo.', 'arriendo-facil' ) );
	}
	// Validate establishment address
	elseif ( empty( $dir_establecimiento ) ) {
		$af_sri_notice = array( 'type' => 'error', 'msg' => __( '❌ La dirección del establecimiento es OBLIGATORIA. Debe contener una dirección física real (calle, número, ciudad).', 'arriendo-facil' ) );
	}
	else {
		Arriendo_Facil_SRI_Config::save(
			array(
				'ruc'                   => $ruc,
				'razon_social'          => sanitize_text_field( wp_unslash( $_POST['af_razon_social'] ?? '' ) ),
				'nombre_comercial'      => sanitize_text_field( wp_unslash( $_POST['af_nombre_comercial'] ?? '' ) ),
				'dir_establecimiento'   => sanitize_text_field( wp_unslash( $_POST['af_dir_establecimiento'] ?? '' ) ),
				'dir_matriz'            => sanitize_text_field( wp_unslash( $_POST['af_dir_matriz'] ?? '' ) ),
				'obligado_contabilidad' => sanitize_text_field( wp_unslash( $_POST['af_obligado_contabilidad'] ?? 'NO' ) ),
				'ambiente'              => sanitize_key( wp_unslash( $_POST['af_ambiente'] ?? '1' ) ),
				'email_notificacion'    => sanitize_email( wp_unslash( $_POST['af_email_notificacion'] ?? '' ) ),
				'sri_soap_timeout'      => (string) absint( wp_unslash( $_POST['af_sri_soap_timeout'] ?? 30 ) ),
				'sri_soap_max_retries'  => (string) absint( wp_unslash( $_POST['af_sri_soap_max_retries'] ?? 3 ) ),
			)
		);
		$af_sri_notice = array( 'type' => 'success', 'msg' => __( 'Configuración guardada.', 'arriendo-facil' ) );
	}
}

// Upload certificate.
if ( isset( $_POST['af_upload_certificate'] ) ) {
	check_admin_referer( 'af_sri_settings_nonce' );

	if ( ! empty( $_FILES['af_cert_file']['name'] ) ) {
		$upload_result = Arriendo_Facil_SRI_Config::upload_certificate( $_FILES['af_cert_file'] );
		if ( is_wp_error( $upload_result ) ) {
			$af_sri_notice = array( 'type' => 'error', 'msg' => $upload_result->get_error_message() );
		} else {
			$af_sri_notice = array( 'type' => 'success', 'msg' => __( 'Certificado subido correctamente.', 'arriendo-facil' ) );
		}
	}

	// Save password if provided (no sanitize_text_field — it strips %XX and special chars).
	$new_password = (string) wp_unslash( $_POST['af_cert_password'] ?? '' );
	if ( '' !== $new_password ) {
		Arriendo_Facil_SRI_Config::save_cert_password( $new_password );

		// Extract PEMs from the P12 and store them encrypted.
		$cert_path = Arriendo_Facil_SRI_Config::cert_path();
		if ( $cert_path ) {
			$p12_result = Arriendo_Facil_SRI_Config::read_p12( $cert_path, $new_password );
			if ( is_wp_error( $p12_result ) ) {
				$af_sri_notice = array(
					'type' => 'error',
					'msg'  => __( 'Certificado subido, pero no se pudo leer: ', 'arriendo-facil' ) . $p12_result->get_error_message(),
				);
			} elseif ( empty( $p12_result['cert'] ) || empty( $p12_result['pkey'] ) ) {
				$af_sri_notice = array(
					'type' => 'error',
					'msg'  => __( 'El certificado no contiene los datos esperados (certificado + clave privada).', 'arriendo-facil' ),
				);
			} else {
				$chain = $p12_result['chain'] ?? '';
				if ( '' === trim( $chain ) ) {
					$chain = Arriendo_Facil_SRI_Config::fetch_ca_chain( $p12_result['cert'] );
				}
				Arriendo_Facil_SRI_Config::save_cert_pems( $p12_result['cert'], $p12_result['pkey'], $chain );
				$chain_count = ( '' !== trim( $chain ) ) ? preg_match_all( '/-----BEGIN CERTIFICATE-----/', $chain ) : 0;
				if ( (int) $chain_count > 0 ) {
					$msg           = sprintf(
						__( 'Certificado subido y verificado. ✓ Cadena CA: %d certificado(s) intermedio(s) obtenidos.', 'arriendo-facil' ),
						$chain_count
					);
					$af_sri_notice = array( 'type' => 'success', 'msg' => $msg );
				} else {
					// Chain is empty — save what we have and warn loudly.
					// Without the CA chain the SRI will return FIRMA INVALIDA (error 39)
					// "El certificado firmante no es válido" at authorization time.
					$af_sri_notice = array(
						'type' => 'warning',
						'msg'  => __(
							'⚠️ Certificado subido, pero NO se pudo obtener la cadena de certificados CA intermedios. ' .
							'Sin esta cadena el SRI rechazará las facturas con "El certificado firmante no es válido" (error 39). ' .
							'Haz clic en "Reconstruir cadena CA" para intentar descargarla. ' .
							'Si el botón falla, tu servidor bloquea las salidas HTTPS a los servidores BCE/SecurityData; ' .
							'contacta a tu hosting para habilitarlas.',
							'arriendo-facil'
						),
					);
				}
			}
		}
	}
}

// Test certificate (uses stored PEMs — no need to re-read P12).
if ( isset( $_POST['af_test_certificate'] ) ) {
	check_admin_referer( 'af_sri_settings_nonce' );

	$test_result = Arriendo_Facil_SRI_Config::test_stored_certificate();
	if ( is_wp_error( $test_result ) ) {
		$af_sri_notice = array( 'type' => 'error', 'msg' => $test_result->get_error_message() );
	} else {
		$af_sri_notice = array(
			'type' => 'success',
			'msg'  => __( '✓ El certificado es válido y está activo.', 'arriendo-facil' ),
		);
	}
}


// Save/Add emission point.
if ( isset( $_POST['af_save_emission_point'] ) ) {
	check_admin_referer( 'af_sri_settings_nonce' );

	global $wpdb;
	$estab  = str_pad( preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['af_cod_estab'] ?? '001' ) ) ), 3, '0', STR_PAD_LEFT );
	$punto  = str_pad( preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['af_cod_punto'] ?? '001' ) ) ), 3, '0', STR_PAD_LEFT );
	$desc   = sanitize_text_field( wp_unslash( $_POST['af_punto_desc'] ?? '' ) );
	$activo = isset( $_POST['af_punto_activo'] ) ? 1 : 0;

	if ( strlen( $estab ) !== 3 || strlen( $punto ) !== 3 ) {
		$af_sri_notice = array( 'type' => 'error', 'msg' => __( 'Los códigos de establecimiento y punto de emisión deben tener exactamente 3 dígitos.', 'arriendo-facil' ) );
	} else {
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}af_emission_points WHERE codigo_establecimiento = %s AND codigo_punto_emision = %s",
				$estab,
				$punto
			)
		);

		if ( $existing ) {
			$wpdb->update(
				$wpdb->prefix . 'af_emission_points',
				array(
					'descripcion' => $desc,
					'activo'      => $activo,
				),
				array( 'id' => (int) $existing ),
				array( '%s', '%d' ),
				array( '%d' )
			);
			$af_sri_notice = array( 'type' => 'success', 'msg' => __( 'Punto de emisión actualizado.', 'arriendo-facil' ) );
		} else {
			$wpdb->insert(
				$wpdb->prefix . 'af_emission_points',
				array(
					'codigo_establecimiento' => $estab,
					'codigo_punto_emision'   => $punto,
					'descripcion'            => $desc,
					'activo'                 => $activo,
					'secuencial_actual'      => 1,
				),
				array( '%s', '%s', '%s', '%d', '%d' )
			);
			$af_sri_notice = array( 'type' => 'success', 'msg' => __( 'Punto de emisión creado.', 'arriendo-facil' ) );
		}
	}
}

// ─── Load current values ─────────────────────────────────────────────────────

$cfg          = Arriendo_Facil_SRI_Config::get();
$cert_path    = Arriendo_Facil_SRI_Config::cert_path();
$cert_has_pwd = ( '' !== $cfg['cert_password_enc'] );
$cert_has_pems = ( '' !== $cfg['cert_pem_enc'] && '' !== $cfg['pkey_pem_enc'] );

global $wpdb;
$emission_points = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}af_emission_points ORDER BY codigo_establecimiento, codigo_punto_emision"
);

$af_has_emitter_data = ( '' !== trim( (string) $cfg['ruc'] ) && '' !== trim( (string) $cfg['razon_social'] ) && '' !== trim( (string) $cfg['dir_establecimiento'] ) && '' !== trim( (string) $cfg['email_notificacion'] ) );
$af_cert_ready       = ( '' !== $cert_path && $cert_has_pwd && $cert_has_pems );
$af_points_ready     = ! empty( $emission_points );

?>
<div class="wrap">
	<h1><?php esc_html_e( 'Configuración SRI – Facturación Electrónica', 'arriendo-facil' ); ?></h1>

	<div class="af-sri-hero">
		<div class="af-sri-hero__content">
			<p class="af-sri-hero__eyebrow"><?php esc_html_e( 'Guía rápida de configuración', 'arriendo-facil' ); ?></p>
			<h2 class="af-sri-hero__title"><?php esc_html_e( 'Configura el SRI sin perderte en la pantalla', 'arriendo-facil' ); ?></h2>
			<p class="af-sri-hero__text"><?php esc_html_e( 'Completa primero los datos del emisor, luego sube el certificado y finalmente crea tu punto de emisión. Todo lo necesario para emitir está reunido aquí.', 'arriendo-facil' ); ?></p>
		</div>
		<div class="af-sri-hero__links">
			<a class="button button-secondary" href="#af-sri-datos-emisor"><?php esc_html_e( '1. Datos del emisor', 'arriendo-facil' ); ?></a>
			<a class="button button-secondary" href="#af-sri-certificado"><?php esc_html_e( '2. Certificado', 'arriendo-facil' ); ?></a>
			<a class="button button-secondary" href="#af-sri-puntos"><?php esc_html_e( '3. Puntos de emisión', 'arriendo-facil' ); ?></a>
		</div>
		<div class="af-sri-status-grid">
			<div class="af-sri-status-card <?php echo $af_has_emitter_data ? 'is-ready' : 'is-pending'; ?>">
				<span class="af-sri-status-card__label"><?php esc_html_e( 'Datos del emisor', 'arriendo-facil' ); ?></span>
				<strong class="af-sri-status-card__value"><?php echo esc_html( $af_has_emitter_data ? __( 'Completo', 'arriendo-facil' ) : __( 'Pendiente', 'arriendo-facil' ) ); ?></strong>
				<span class="af-sri-status-card__hint"><?php esc_html_e( 'RUC, razón social, dirección y email.', 'arriendo-facil' ); ?></span>
			</div>
			<div class="af-sri-status-card <?php echo $af_cert_ready ? 'is-ready' : 'is-pending'; ?>">
				<span class="af-sri-status-card__label"><?php esc_html_e( 'Certificado', 'arriendo-facil' ); ?></span>
				<strong class="af-sri-status-card__value"><?php echo esc_html( $af_cert_ready ? __( 'Listo', 'arriendo-facil' ) : __( 'Pendiente', 'arriendo-facil' ) ); ?></strong>
				<span class="af-sri-status-card__hint"><?php esc_html_e( 'P12/PFX, contraseña y cadena CA.', 'arriendo-facil' ); ?></span>
			</div>
			<div class="af-sri-status-card <?php echo $af_points_ready ? 'is-ready' : 'is-pending'; ?>">
				<span class="af-sri-status-card__label"><?php esc_html_e( 'Puntos de emisión', 'arriendo-facil' ); ?></span>
				<strong class="af-sri-status-card__value"><?php echo esc_html( $af_points_ready ? __( 'Configurados', 'arriendo-facil' ) : __( 'Falta crear', 'arriendo-facil' ) ); ?></strong>
				<span class="af-sri-status-card__hint"><?php esc_html_e( 'Serie y secuencial para facturar.', 'arriendo-facil' ); ?></span>
			</div>
		</div>
	</div>

	<?php if ( $af_sri_notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $af_sri_notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $af_sri_notice['msg'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( '2' === $cfg['ambiente'] ) : ?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( '⚠ Ambiente de PRODUCCIÓN activo.', 'arriendo-facil' ); ?></strong>
				<?php esc_html_e( 'Los comprobantes emitidos son documentos tributarios con validez legal ante el SRI. Asegúrese de que el RUC y el certificado sean los definitivos.', 'arriendo-facil' ); ?>
			</p>
		</div>
	<?php else : ?>
		<div class="notice notice-info">
			<p>
				<?php esc_html_e( 'Ambiente de PRUEBAS activo. Los comprobantes se envían al servidor de certificación del SRI y no tienen validez tributaria.', 'arriendo-facil' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<!-- ─── Datos del Emisor ──────────────────────────────────────────────── -->
	<div class="af-sri-section" id="af-sri-datos-emisor">
		<div class="af-sri-section__header">
			<div>
				<h2><?php esc_html_e( 'Datos del Emisor', 'arriendo-facil' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Esta es la base de la configuración. Si ya consultaste el RUC, revisa que el sistema haya traído los datos correctos antes de guardar.', 'arriendo-facil' ); ?></p>
			</div>
			<span class="af-sri-section__pill"><?php esc_html_e( 'Paso 1', 'arriendo-facil' ); ?></span>
		</div>
	<form method="post" action="">
		<?php wp_nonce_field( 'af_sri_settings_nonce' ); ?>

		<p class="description af-sri-section__intro">
			<?php esc_html_e( 'Información requerida por el SRI para emitir comprobantes válidos. Los campos marcados con * son obligatorios.', 'arriendo-facil' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="af_ruc"><?php esc_html_e( 'RUC', 'arriendo-facil' ); ?> <span style="color:red;">*</span> <span class="description">(13 dígitos; o cédula de 10 dígitos para persona natural)</span></label>
				</th>
				<td>
					<input type="text" id="af_ruc" name="af_ruc"
						value="<?php echo esc_attr( $cfg['ruc'] ); ?>"
						class="regular-text" maxlength="13" pattern="\d{10,13}"
						placeholder="1717012890 o 1717012890001"
						required />
					<button type="button" id="af-ruc-lookup-btn" class="button" style="margin-left:8px;">
						<?php esc_html_e( '🔍 Consultar en SRI', 'arriendo-facil' ); ?>
					</button>
					<span id="af-ruc-lookup-status" style="margin-left:8px; font-style:italic;"></span>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="af_razon_social"><?php esc_html_e( 'Razón Social', 'arriendo-facil' ); ?> <span style="color:red;">*</span></label>
				</th>
				<td>
					<input type="text" id="af_razon_social" name="af_razon_social"
						value="<?php echo esc_attr( $cfg['razon_social'] ); ?>"
						class="large-text" maxlength="300" required />
					<p class="description"><?php esc_html_e( 'Tal como aparece en el RUC del SRI. Auto-completado al consultar el RUC.', 'arriendo-facil' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="af_nombre_comercial"><?php esc_html_e( 'Nombre Comercial', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<input type="text" id="af_nombre_comercial" name="af_nombre_comercial"
						value="<?php echo esc_attr( $cfg['nombre_comercial'] ); ?>"
						class="large-text" maxlength="300" />
					<p class="description" style="color:#666;">
						<?php esc_html_e( '❌ Opcional. Solo si es diferente a la razón social. Aparece en los comprobantes.', 'arriendo-facil' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="af_dir_establecimiento"><?php esc_html_e( 'Dirección del Establecimiento', 'arriendo-facil' ); ?> <span style="color:red;">*</span></label>
				</th>
				<td>
					<input type="text" id="af_dir_establecimiento" name="af_dir_establecimiento"
						value="<?php echo esc_attr( $cfg['dir_establecimiento'] ); ?>"
						class="large-text" maxlength="300" placeholder="Ej: Quito, Pichincha, Calle X Nº123" required />
					<p class="description">
						<?php esc_html_e( 'Dirección física del establecimiento (se extrae del SRI al consultar el RUC). Si está vacía, completa manualmente.', 'arriendo-facil' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="af_dir_matriz"><?php esc_html_e( 'Dirección Matriz', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<?php $af_dir_match = ( ! empty( $cfg['dir_establecimiento'] ) && $cfg['dir_establecimiento'] === $cfg['dir_matriz'] ); ?>
					<input type="text" id="af_dir_matriz" name="af_dir_matriz"
						value="<?php echo esc_attr( $cfg['dir_matriz'] ); ?>"
						class="large-text" maxlength="300" />
					<p class="description" id="af-dir-matriz-desc">
						<?php if ( $af_dir_match ) : ?>
							<span style="color:#2e7d32; font-weight:500;">✓ Igual a la dirección del establecimiento.</span>
						<?php else : ?>
							<?php esc_html_e( 'Opcional. Solo si es diferente a la dirección del establecimiento.', 'arriendo-facil' ); ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Obligado a llevar Contabilidad', 'arriendo-facil' ); ?> <span style="color:red;">*</span>
				</th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="af_obligado_contabilidad" value="NO"
								<?php checked( $cfg['obligado_contabilidad'], 'NO' ); ?> />
							<?php esc_html_e( 'NO', 'arriendo-facil' ); ?>
						</label>
						&nbsp;&nbsp;
						<label>
							<input type="radio" name="af_obligado_contabilidad" value="SI"
								<?php checked( $cfg['obligado_contabilidad'], 'SI' ); ?> />
							<?php esc_html_e( 'SI', 'arriendo-facil' ); ?>
						</label>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Auto-completado al consultar el RUC.', 'arriendo-facil' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="af_email_notificacion"><?php esc_html_e( 'Email para notificaciones', 'arriendo-facil' ); ?> <span style="color:red;">*</span></label>
				</th>
				<td>
					<input type="email" id="af_email_notificacion" name="af_email_notificacion"
						value="<?php echo esc_attr( $cfg['email_notificacion'] ); ?>"
						class="regular-text" required
						pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$" />
					<p class="description" style="color:#d32f2f; font-weight:500;">
						<?php esc_html_e( '⚠️ CRÍTICO: El SRI envía las autorizaciones de comprobantes a este email. Sin él, no recibirás los documentos autorizados.', 'arriendo-facil' ); ?>
					</p>
					<p id="af-email-error" class="description" style="color:#d32f2f; display:none; margin-top:5px;"></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Ambiente SRI', 'arriendo-facil' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Ambiente de emisión', 'arriendo-facil' ); ?></th>
				<td>
					<fieldset>
						<label>
							<input type="radio" name="af_ambiente" value="1"
								<?php checked( $cfg['ambiente'], '1' ); ?> />
							<strong><?php esc_html_e( 'Pruebas (Certificación)', 'arriendo-facil' ); ?></strong>
							<span class="description"> — <?php esc_html_e( 'Servidor de certificación SRI. Sin validez tributaria.', 'arriendo-facil' ); ?></span>
						</label>
						<br />
						<label>
							<input type="radio" name="af_ambiente" value="2"
								<?php checked( $cfg['ambiente'], '2' ); ?> />
							<strong><?php esc_html_e( 'Producción', 'arriendo-facil' ); ?></strong>
							<span class="description"> — <?php esc_html_e( 'Servidor productivo SRI. Comprobantes con validez legal.', 'arriendo-facil' ); ?></span>
						</label>
					</fieldset>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Configuración Avanzada', 'arriendo-facil' ); ?> <span class="description" style="font-size:0.9em;">(Opcional)</span></h2>
		<p class="description" style="color:#666; margin-bottom:15px;">
			<?php esc_html_e( 'Parámetros técnicos. Los valores por defecto funcionan para la mayoría de casos. Modifica solo si tienes problemas de conexión.', 'arriendo-facil' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="af_sri_soap_timeout"><?php esc_html_e( 'Timeout SOAP (segundos)', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<input type="number" id="af_sri_soap_timeout" name="af_sri_soap_timeout"
						value="<?php echo esc_attr( $cfg['sri_soap_timeout'] ?? 30 ); ?>"
						class="small-text" min="10" max="120" step="1" />
					<p class="description" style="color:#999;">
						<?php esc_html_e( '⚙️ Default: 30 seg. Aumenta a 60 si tu conexión es lenta.', 'arriendo-facil' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="af_sri_soap_max_retries"><?php esc_html_e( 'Máximo de Reintentos', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<input type="number" id="af_sri_soap_max_retries" name="af_sri_soap_max_retries"
						value="<?php echo esc_attr( $cfg['sri_soap_max_retries'] ?? 3 ); ?>"
						class="small-text" min="1" max="5" step="1" />
					<p class="description" style="color:#999;">
						<?php esc_html_e( '⚙️ Default: 3. Reintentos inmediatos ante errores temporales.', 'arriendo-facil' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" name="af_save_sri_config" class="button button-primary">
				<?php esc_html_e( 'Guardar Configuración', 'arriendo-facil' ); ?>
			</button>
		</p>
	</form>
	</div>

	<!-- ─── Certificado Digital ──────────────────────────────────────────── -->
	<div class="af-sri-section" id="af-sri-certificado">
		<div class="af-sri-section__header">
			<div>
				<h2><?php esc_html_e( 'Certificado Digital (P12)', 'arriendo-facil' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Sube el certificado, guarda su contraseña y confirma que la cadena CA quedó lista para evitar errores de firma.', 'arriendo-facil' ); ?></p>
			</div>
			<span class="af-sri-section__pill"><?php esc_html_e( 'Paso 2', 'arriendo-facil' ); ?></span>
		</div>

	<table class="form-table" role="presentation">
		<tr>
			<th><?php esc_html_e( 'Estado actual', 'arriendo-facil' ); ?></th>
			<td>
				<?php if ( $cert_path ) : ?>
					<span style="color:#2e7d32; font-weight:600;">
						&#10003; <?php esc_html_e( 'Certificado cargado', 'arriendo-facil' ); ?>
					</span>
					&nbsp;
					<span class="description"><?php echo esc_html( basename( $cert_path ) ); ?></span>
					&nbsp;|&nbsp;
					<?php if ( $cert_has_pwd ) : ?>
						<span style="color:#2e7d32;">&#10003; <?php esc_html_e( 'Contraseña guardada', 'arriendo-facil' ); ?></span>
					<?php else : ?>
						<span style="color:#c62828;">&#10007; <?php esc_html_e( 'Sin contraseña', 'arriendo-facil' ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					<span style="color:#c62828;">&#10007; <?php esc_html_e( 'Ningún certificado cargado', 'arriendo-facil' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
	</table>

	<form method="post" action="" enctype="multipart/form-data">
		<?php wp_nonce_field( 'af_sri_settings_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="af_cert_file"><?php esc_html_e( 'Archivo .p12 / .pfx', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<input type="file" id="af_cert_file" name="af_cert_file"
						accept=".p12,.pfx" />
					<p class="description">
						<?php esc_html_e( 'Certificado emitido por el Banco Central del Ecuador o una entidad de certificación autorizada. Máx. 1 MB.', 'arriendo-facil' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="af_cert_password"><?php esc_html_e( 'Contraseña del certificado', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<input type="password" id="af_cert_password" name="af_cert_password"
						class="regular-text" autocomplete="new-password" />
					<p class="description">
						<?php
						if ( $cert_has_pwd ) {
							esc_html_e( 'Ya hay una contraseña guardada. Déjela en blanco para mantenerla.', 'arriendo-facil' );
						} else {
							esc_html_e( 'Introduzca la contraseña del archivo P12.', 'arriendo-facil' );
						}
						?>
					</p>
				</td>
			</tr>
		</table>
		<p class="submit">
			<button type="submit" name="af_upload_certificate" class="button button-primary">
				<?php esc_html_e( 'Subir Certificado', 'arriendo-facil' ); ?>
			</button>
		</p>
	</form>

	<?php if ( $cert_has_pems ) : ?>
		<form method="post" action="">
			<?php wp_nonce_field( 'af_sri_settings_nonce' ); ?>
			<p>
				<button type="submit" name="af_test_certificate" class="button">
					<?php esc_html_e( 'Verificar certificado', 'arriendo-facil' ); ?>
				</button>
				<span class="description"><?php esc_html_e( 'Verifica que el certificado sea válido y esté activo.', 'arriendo-facil' ); ?></span>
			</p>
		</form>

	<?php endif; ?>
	</div>

	<!-- ─── Puntos de Emisión ────────────────────────────────────────────── -->
	<div class="af-sri-section" id="af-sri-puntos">
		<div class="af-sri-section__header">
			<div>
				<h2><?php esc_html_e( 'Puntos de Emisión', 'arriendo-facil' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Cada comprobante se asocia a un establecimiento y punto de emisión (serie). El punto activo con el secuencial más bajo se utiliza por defecto.', 'arriendo-facil' ); ?></p>
			</div>
			<span class="af-sri-section__pill"><?php esc_html_e( 'Paso 3', 'arriendo-facil' ); ?></span>
		</div>

	<?php if ( $emission_points ) : ?>
		<table class="wp-list-table widefat fixed striped" style="max-width:800px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Establecimiento', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Punto Emisión', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Serie', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Descripción', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Secuencial actual', 'arriendo-facil' ); ?></th>
					<th><?php esc_html_e( 'Activo', 'arriendo-facil' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $emission_points as $pt ) : ?>
					<tr>
						<td><?php echo esc_html( $pt->codigo_establecimiento ); ?></td>
						<td><?php echo esc_html( $pt->codigo_punto_emision ); ?></td>
						<td><strong><?php echo esc_html( $pt->codigo_establecimiento . $pt->codigo_punto_emision ); ?></strong></td>
						<td><?php echo esc_html( $pt->descripcion ?: '—' ); ?></td>
						<td><?php echo esc_html( number_format( (int) $pt->secuencial_actual, 0, '', '' ) ); ?></td>
						<td>
							<?php if ( $pt->activo ) : ?>
								<span style="color:#2e7d32;">&#10003;</span>
							<?php else : ?>
								<span style="color:#c62828;">&#10007;</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<br />
	<?php else : ?>
		<p class="af-sri-empty-state"><?php esc_html_e( 'No hay puntos de emisión configurados. Agregue al menos uno para poder facturar.', 'arriendo-facil' ); ?></p>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Agregar / Actualizar Punto de Emisión', 'arriendo-facil' ); ?></h3>
	<form method="post" action="">
		<?php wp_nonce_field( 'af_sri_settings_nonce' ); ?>
		<table class="form-table" role="presentation" style="max-width:600px;">
			<tr>
				<th scope="row">
					<label for="af_cod_estab"><?php esc_html_e( 'Código Establecimiento', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<input type="text" id="af_cod_estab" name="af_cod_estab"
						value="001" class="small-text" maxlength="3" pattern="\d{3}" required />
					<span class="description"><?php esc_html_e( '3 dígitos (ej: 001)', 'arriendo-facil' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="af_cod_punto"><?php esc_html_e( 'Código Punto de Emisión', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<input type="text" id="af_cod_punto" name="af_cod_punto"
						value="001" class="small-text" maxlength="3" pattern="\d{3}" required />
					<span class="description"><?php esc_html_e( '3 dígitos (ej: 001)', 'arriendo-facil' ); ?></span>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="af_punto_desc"><?php esc_html_e( 'Descripción', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<input type="text" id="af_punto_desc" name="af_punto_desc"
						class="regular-text" maxlength="255"
						placeholder="<?php esc_attr_e( 'Punto de emisión principal', 'arriendo-facil' ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Activo', 'arriendo-facil' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="af_punto_activo" value="1" checked />
						<?php esc_html_e( 'Habilitado para emitir comprobantes', 'arriendo-facil' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<p class="submit">
			<button type="submit" name="af_save_emission_point" class="button button-primary">
				<?php esc_html_e( 'Guardar Punto de Emisión', 'arriendo-facil' ); ?>
			</button>
		</p>
	</form>
	</div>

</div><!-- .wrap -->

<script>
(function () {
	var btn    = document.getElementById( 'af-ruc-lookup-btn' );
	var status = document.getElementById( 'af-ruc-lookup-status' );
	if ( ! btn ) return;

	btn.addEventListener( 'click', function () {
		var ruc = ( document.getElementById( 'af_ruc' ).value || '' ).replace( /\D/g, '' );
		// Normalizar: cédula 10 dígitos → RUC 13 dígitos (persona natural).
		if ( ruc.length === 10 ) { ruc = ruc + '001'; }
		if ( ruc.length !== 13 ) {
			status.innerHTML = '<span style="color:#c62828;">Ingresa el RUC (13 dígitos) o tu cédula (10 dígitos).</span>';
			return;
		}
		btn.disabled = true;
		status.innerHTML = '<span style="color:#555;">Consultando SRI…</span>';

		var data = new FormData();
		data.append( 'action', 'af_sri_ruc_lookup' );
		data.append( 'ruc', ruc );
		data.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'af_sri_ruc_lookup' ) ); ?>' );

		fetch( '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
			method : 'POST',
			body   : data,
			credentials: 'same-origin',
		} )
		.then( function ( r ) {
			// Read as text first so a non-JSON response (PHP debug output, fatal error HTML)
			// reveals the actual content instead of a generic "Error de conexión".
			return r.text().then( function ( text ) {
				try {
					return JSON.parse( text );
				} catch ( e ) {
					// Expose the first 200 chars of the raw response to help diagnose.
					throw new Error( 'Respuesta inválida del servidor: ' + text.substring( 0, 200 ) );
				}
			} );
		} )
		.then( function ( resp ) {
			if ( resp.success && resp.data ) {
				var d = resp.data;
				if ( d.razon_social )      { document.getElementById( 'af_razon_social' ).value        = d.razon_social; }
				if ( d.nombre_comercial )  { document.getElementById( 'af_nombre_comercial' ).value    = d.nombre_comercial; }
				if ( d.dir_establecimiento ) { document.getElementById( 'af_dir_establecimiento' ).value = d.dir_establecimiento; }
				if ( d.dir_matriz )        { document.getElementById( 'af_dir_matriz' ).value          = d.dir_matriz; }
				if ( d.obligado_contabilidad ) {
					var radios = document.querySelectorAll( 'input[name="af_obligado_contabilidad"]' );
					radios.forEach( function ( r ) { r.checked = ( r.value === d.obligado_contabilidad ); } );
				}
				status.innerHTML = '<span style="color:#2e7d32;">✓ Datos cargados desde el SRI. Revisa y guarda.</span>';
			} else {
				var msg = ( resp.data && resp.data.message ) ? resp.data.message : 'No se encontró información.';
				status.innerHTML = '<span style="color:#c62828;">' + msg + '</span>';
			}
		} )
		.catch( function ( err ) {
			status.innerHTML = '<span style="color:#c62828;" title="' + ( err.message || '' ).replace( /"/g, '&quot;' ) + '">Error al consultar el SRI. Detalle: ' + ( err.message ? err.message.substring( 0, 120 ) : 'Error de red' ) + '</span>';
		} )
		.finally( function () { btn.disabled = false; } );
	} );
}());
</script>
