<?php
/**
 * Cleaning service meta box view.
 *
 * @package Arriendo_Facil
 * @var string $company_name         Company name meta value.
 * @var string $company_ruc          Company RUC meta value.
 * @var string $services_description Services description meta value.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<table class="form-table af-cleaning-service-table">
	<tr>
		<th><label for="af_company_name"><?php esc_html_e( 'Company Name', 'arriendo-facil' ); ?></label></th>
		<td>
			<input type="text" id="af_company_name" name="af_company_name"
				value="<?php echo esc_attr( $company_name ); ?>" class="regular-text" required />
		</td>
	</tr>
	<tr>
		<th><label for="af_company_ruc"><?php esc_html_e( 'RUC', 'arriendo-facil' ); ?></label></th>
		<td>
			<input type="text" id="af_company_ruc" name="af_company_ruc"
				value="<?php echo esc_attr( $company_ruc ); ?>" class="regular-text"
				inputmode="numeric" pattern="^[0-9]{13}$" maxlength="13"
				title="RUC must contain exactly 13 digits" required />
		</td>
	</tr>
	<tr>
		<th><label for="af_services_description"><?php esc_html_e( 'Services Description', 'arriendo-facil' ); ?></label></th>
		<td>
			<textarea id="af_services_description" name="af_services_description" rows="5" class="large-text" required><?php echo esc_textarea( $services_description ); ?></textarea>
		</td>
	</tr>
</table>
