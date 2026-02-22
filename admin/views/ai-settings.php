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

if ( isset( $_POST['af_save_ai_settings'] ) ) {
	check_admin_referer( 'af_ai_settings_nonce' );
	update_option( 'af_ai_api_url', esc_url_raw( wp_unslash( $_POST['af_ai_api_url'] ?? '' ) ) );
	update_option( 'af_ai_api_key', sanitize_text_field( wp_unslash( $_POST['af_ai_api_key'] ?? '' ) ) );
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'arriendo-facil' ) . '</p></div>';
}

$api_url = get_option( 'af_ai_api_url', '' );
$api_key = get_option( 'af_ai_api_key', '' );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'AI Settings', 'arriendo-facil' ); ?></h1>
	<p><?php esc_html_e( 'Configure the AI service used for cost prediction, document generation, and guest scoring.', 'arriendo-facil' ); ?></p>

	<form method="post" action="">
		<?php wp_nonce_field( 'af_ai_settings_nonce' ); ?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="af_ai_api_url"><?php esc_html_e( 'AI API URL', 'arriendo-facil' ); ?></label>
				</th>
				<td>
					<input type="url" id="af_ai_api_url" name="af_ai_api_url"
						value="<?php echo esc_attr( $api_url ); ?>"
						class="regular-text" placeholder="https://api.example.com/v1" />
					<p class="description">
						<?php esc_html_e( 'Base URL of the AI API endpoint.', 'arriendo-facil' ); ?>
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
						class="regular-text" autocomplete="off" />
					<p class="description">
						<?php esc_html_e( 'Bearer token / API key for the AI service.', 'arriendo-facil' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<input type="submit" name="af_save_ai_settings" class="button button-primary"
				value="<?php esc_attr_e( 'Save Settings', 'arriendo-facil' ); ?>" />
		</p>
	</form>
</div>
