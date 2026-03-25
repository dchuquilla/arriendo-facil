<?php
/**
 * AI Settings admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'arriendo-facil' ) );
}

/**
 * Returns a setting value with wp-config constant priority.
 *
 * @param string $constant_name Constant name.
 * @param string $option_name Option name.
 * @param string $default Default value.
 * @return string
 */
function af_settings_get_value( $constant_name, $option_name, $default = '' ) {
	if ( defined( $constant_name ) ) {
		$constant_value = constant( $constant_name );
		return is_string( $constant_value ) ? $constant_value : $default;
	}

	return (string) get_option( $option_name, $default );
}

/**
 * Whether a field is locked by a constant in wp-config.php.
 *
 * @param string $constant_name Constant name.
 * @return bool
 */
function af_settings_is_locked( $constant_name ) {
	return defined( $constant_name );
}

if ( isset( $_POST['af_save_ai_settings'] ) ) {
	check_admin_referer( 'af_ai_settings_nonce' );
	update_option( 'af_ai_api_url', esc_url_raw( wp_unslash( $_POST['af_ai_api_url'] ?? '' ) ) );
	update_option( 'af_ai_api_key', sanitize_text_field( wp_unslash( $_POST['af_ai_api_key'] ?? '' ) ) );
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'arriendo-facil' ) . '</p></div>';
}

if ( isset( $_POST['af_save_storage_credentials'] ) ) {
	check_admin_referer( 'af_ai_settings_nonce' );

	if ( ! af_settings_is_locked( 'AF_STORAGE_PROVIDER' ) ) {
		update_option( 'af_storage_provider', sanitize_key( wp_unslash( $_POST['af_storage_provider'] ?? 'cloudflare_r2' ) ) );
	}

	if ( ! af_settings_is_locked( 'AF_R2_ACCESS_KEY_ID' ) ) {
		update_option( 'af_r2_access_key_id', sanitize_text_field( wp_unslash( $_POST['af_r2_access_key_id'] ?? '' ) ) );
	}

	if ( ! af_settings_is_locked( 'AF_R2_SECRET_ACCESS_KEY' ) ) {
		update_option( 'af_r2_secret_access_key', sanitize_text_field( wp_unslash( $_POST['af_r2_secret_access_key'] ?? '' ) ) );
	}

	if ( ! af_settings_is_locked( 'AF_R2_ENDPOINT_URL' ) ) {
		update_option( 'af_r2_endpoint_url', esc_url_raw( wp_unslash( $_POST['af_r2_endpoint_url'] ?? '' ) ) );
	}

	if ( ! af_settings_is_locked( 'AF_R2_BUCKET_NAME' ) ) {
		update_option( 'af_r2_bucket_name', sanitize_text_field( wp_unslash( $_POST['af_r2_bucket_name'] ?? '' ) ) );
	}

	if ( ! af_settings_is_locked( 'AF_R2_CUSTOM_DOMAIN' ) ) {
		update_option( 'af_r2_custom_domain', esc_url_raw( wp_unslash( $_POST['af_r2_custom_domain'] ?? '' ) ) );
	}

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Storage credentials saved.', 'arriendo-facil' ) . '</p></div>';
}

if ( isset( $_POST['af_test_storage_connection'] ) ) {
	check_admin_referer( 'af_ai_settings_nonce' );

	$endpoint_url = af_settings_get_value( 'AF_R2_ENDPOINT_URL', 'af_r2_endpoint_url', '' );
	$bucket_name  = af_settings_get_value( 'AF_R2_BUCKET_NAME', 'af_r2_bucket_name', '' );

	$test_url = '';
	if ( $endpoint_url && $bucket_name ) {
		$test_url = trailingslashit( untrailingslashit( $endpoint_url ) ) . rawurlencode( $bucket_name );
	}

	if ( ! $test_url ) {
		update_option( 'af_r2_connected', '0' );
		update_option( 'af_r2_last_check', current_time( 'mysql' ) );
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Connection failed: missing endpoint or bucket name.', 'arriendo-facil' ) . '</p></div>';
	} else {
		$response = wp_remote_request(
			$test_url,
			array(
				'method'  => 'HEAD',
				'timeout' => 12,
			)
		);

		$connected = false;
		if ( ! is_wp_error( $response ) ) {
			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$connected   = $status_code >= 200 && $status_code < 500;
		}

		update_option( 'af_r2_connected', $connected ? '1' : '0' );
		update_option( 'af_r2_last_check', current_time( 'mysql' ) );

		if ( $connected ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Connected: endpoint reachable.', 'arriendo-facil' ) . '</p></div>';
		} else {
			$error_message = is_wp_error( $response ) ? $response->get_error_message() : __( 'Unexpected response.', 'arriendo-facil' );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Connection failed:', 'arriendo-facil' ) . ' ' . esc_html( $error_message ) . '</p></div>';
		}
	}
}

$api_url = af_settings_get_value( 'AF_AI_API_URL', 'af_ai_api_url', '' );
$api_key = af_settings_get_value( 'AF_AI_API_KEY', 'af_ai_api_key', '' );

$storage_provider = af_settings_get_value( 'AF_STORAGE_PROVIDER', 'af_storage_provider', 'cloudflare_r2' );
$r2_access_key_id = af_settings_get_value( 'AF_R2_ACCESS_KEY_ID', 'af_r2_access_key_id', '' );
$r2_secret_key    = af_settings_get_value( 'AF_R2_SECRET_ACCESS_KEY', 'af_r2_secret_access_key', '' );
$r2_endpoint_url  = af_settings_get_value( 'AF_R2_ENDPOINT_URL', 'af_r2_endpoint_url', '' );
$r2_bucket_name   = af_settings_get_value( 'AF_R2_BUCKET_NAME', 'af_r2_bucket_name', '' );
$r2_custom_domain = af_settings_get_value( 'AF_R2_CUSTOM_DOMAIN', 'af_r2_custom_domain', '' );

$r2_connected = '1' === (string) get_option( 'af_r2_connected', '0' );
$r2_last_check_raw = (string) get_option( 'af_r2_last_check', '' );
$r2_last_check = '';
if ( $r2_last_check_raw ) {
	$timestamp = strtotime( $r2_last_check_raw );
	if ( false !== $timestamp ) {
		$r2_last_check = wp_date( 'd/m/Y - h:i A', $timestamp );
	}
}

$php_upload_max_bytes = wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) );
$php_post_max_bytes   = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
$owner_safe_total     = (int) apply_filters( 'af_owner_contact_safe_request_bytes', min( $php_post_max_bytes, 30 * 1024 * 1024 ) );
$php_upload_max_human = size_format( $php_upload_max_bytes );
$php_post_max_human   = size_format( $php_post_max_bytes );
$owner_safe_human     = size_format( $owner_safe_total );

$ai_url_locked            = af_settings_is_locked( 'AF_AI_API_URL' );
$ai_key_locked            = af_settings_is_locked( 'AF_AI_API_KEY' );
$provider_locked          = af_settings_is_locked( 'AF_STORAGE_PROVIDER' );
$access_key_locked        = af_settings_is_locked( 'AF_R2_ACCESS_KEY_ID' );
$secret_key_locked        = af_settings_is_locked( 'AF_R2_SECRET_ACCESS_KEY' );
$endpoint_locked          = af_settings_is_locked( 'AF_R2_ENDPOINT_URL' );
$bucket_locked            = af_settings_is_locked( 'AF_R2_BUCKET_NAME' );
$custom_domain_locked     = af_settings_is_locked( 'AF_R2_CUSTOM_DOMAIN' );
$any_storage_field_locked = $provider_locked || $access_key_locked || $secret_key_locked || $endpoint_locked || $bucket_locked || $custom_domain_locked;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Settings', 'arriendo-facil' ); ?></h1>
	<p><?php esc_html_e( 'Configure AI and cloud storage integrations for Arriendo Facil.', 'arriendo-facil' ); ?></p>

	<form method="post" action="">
		<?php wp_nonce_field( 'af_ai_settings_nonce' ); ?>
		<h2><?php esc_html_e( 'AI Service', 'arriendo-facil' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="af_ai_api_url"><?php esc_html_e( 'AI API URL', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<input type="url" id="af_ai_api_url" name="af_ai_api_url"
						value="<?php echo esc_attr( $api_url ); ?>"
						class="regular-text" placeholder="https://api.example.com/v1" <?php disabled( $ai_url_locked ); ?> />
					<p class="description">
						<?php esc_html_e( 'Base URL of the AI API endpoint.', 'arriendo-facil' ); ?>
						<?php if ( $ai_url_locked ) : ?>
							<br /><?php esc_html_e( 'This value is defined in wp-config.php and cannot be edited here.', 'arriendo-facil' ); ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="af_ai_api_key"><?php esc_html_e( 'AI API Key', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<input type="password" id="af_ai_api_key" name="af_ai_api_key"
						value="<?php echo esc_attr( $api_key ); ?>"
						class="regular-text" autocomplete="off" <?php disabled( $ai_key_locked ); ?> />
					<p class="description">
						<?php esc_html_e( 'Bearer token / API key for the AI service.', 'arriendo-facil' ); ?>
						<?php if ( $ai_key_locked ) : ?>
							<br /><?php esc_html_e( 'This value is defined in wp-config.php and cannot be edited here.', 'arriendo-facil' ); ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<input type="submit" name="af_save_ai_settings" class="button button-primary"
				value="<?php esc_attr_e( 'Save Settings', 'arriendo-facil' ); ?>" />
		</p>

		<hr />
		<h2><?php esc_html_e( 'Cloud Provider', 'arriendo-facil' ); ?></h2>
		<p><?php esc_html_e( 'Select a cloud storage provider and provide the necessary credentials.', 'arriendo-facil' ); ?></p>
		<p class="description">
			<?php esc_html_e( 'Note: You can configure credentials here or define them in wp-config.php for enhanced security. Constants defined in wp-config.php will take priority and disable these fields.', 'arriendo-facil' ); ?>
		</p>

		<table class="form-table">
			<tr>
				<th scope="row"><label for="af_storage_provider"><?php esc_html_e( 'Cloud Provider', 'arriendo-facil' ); ?></label></th>
				<td>
					<select id="af_storage_provider" name="af_storage_provider" <?php disabled( $provider_locked ); ?>>
						<option value="cloudflare_r2" <?php selected( $storage_provider, 'cloudflare_r2' ); ?>><?php esc_html_e( 'Cloudflare R2', 'arriendo-facil' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="af_r2_access_key_id"><?php esc_html_e( 'Access Key ID', 'arriendo-facil' ); ?></label></th>
				<td><input type="text" id="af_r2_access_key_id" name="af_r2_access_key_id" value="<?php echo esc_attr( $r2_access_key_id ); ?>" class="regular-text" autocomplete="off" <?php disabled( $access_key_locked ); ?> /></td>
			</tr>
			<tr>
				<th scope="row"><label for="af_r2_secret_access_key"><?php esc_html_e( 'Secret Access Key', 'arriendo-facil' ); ?></label></th>
				<td><input type="password" id="af_r2_secret_access_key" name="af_r2_secret_access_key" value="<?php echo esc_attr( $r2_secret_key ); ?>" class="regular-text" autocomplete="off" <?php disabled( $secret_key_locked ); ?> /></td>
			</tr>
			<tr>
				<th scope="row"><label for="af_r2_endpoint_url"><?php esc_html_e( 'Endpoint URL', 'arriendo-facil' ); ?></label></th>
				<td><input type="url" id="af_r2_endpoint_url" name="af_r2_endpoint_url" value="<?php echo esc_attr( $r2_endpoint_url ); ?>" class="regular-text" placeholder="https://accountid.r2.cloudflarestorage.com" <?php disabled( $endpoint_locked ); ?> /></td>
			</tr>
			<tr>
				<th scope="row"><label for="af_r2_bucket_name"><?php esc_html_e( 'Bucket Name', 'arriendo-facil' ); ?></label></th>
				<td><input type="text" id="af_r2_bucket_name" name="af_r2_bucket_name" value="<?php echo esc_attr( $r2_bucket_name ); ?>" class="regular-text" <?php disabled( $bucket_locked ); ?> /></td>
			</tr>
			<tr>
				<th scope="row"><label for="af_r2_custom_domain"><?php esc_html_e( 'Custom Domain (CDN URL)', 'arriendo-facil' ); ?></label></th>
				<td><input type="url" id="af_r2_custom_domain" name="af_r2_custom_domain" value="<?php echo esc_attr( $r2_custom_domain ); ?>" class="regular-text" placeholder="https://cdn.example.com" <?php disabled( $custom_domain_locked ); ?> /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Connected', 'arriendo-facil' ); ?></th>
				<td>
					<strong><?php echo $r2_connected ? esc_html__( 'Yes', 'arriendo-facil' ) : esc_html__( 'No', 'arriendo-facil' ); ?></strong>
					<?php if ( $r2_last_check ) : ?>
						<p class="description"><?php echo esc_html__( 'Last check:', 'arriendo-facil' ) . ' ' . esc_html( $r2_last_check ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Upload Limits', 'arriendo-facil' ); ?></th>
				<td>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: upload_max_filesize, 2: post_max_size, 3: plugin safe request cap */
								__( 'PHP upload_max_filesize: %1$s | PHP post_max_size: %2$s | Plugin safe request cap: %3$s', 'arriendo-facil' ),
								$php_upload_max_human,
								$php_post_max_human,
								$owner_safe_human
							)
						);
						?>
					</p>
					<p class="description">
						<?php esc_html_e( 'If Nginx is used, set client_max_body_size >= post_max_size to avoid 413/400 on admin-ajax uploads.', 'arriendo-facil' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<input type="submit" name="af_save_storage_credentials" class="button button-primary" value="<?php esc_attr_e( 'Save Credentials', 'arriendo-facil' ); ?>" />
			<input type="submit" name="af_test_storage_connection" class="button button-secondary" value="<?php esc_attr_e( 'Test Connection', 'arriendo-facil' ); ?>" />
		</p>

		<?php if ( $any_storage_field_locked ) : ?>
			<p class="description"><?php esc_html_e( 'One or more storage fields are locked by constants in wp-config.php.', 'arriendo-facil' ); ?></p>
		<?php endif; ?>
	</form>
</div>
