<?php
/**
 * Guests admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$guests = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}af_guests ORDER BY created_at DESC LIMIT 100"
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Guests', 'arriendo-facil' ); ?></h1>

	<div class="af-guest-actions" style="margin-bottom: 16px;">
		<button type="button" class="button button-primary" id="af-new-guest">
			<?php esc_html_e( '+ New Guest', 'arriendo-facil' ); ?>
		</button>
	</div>

	<div id="af-guest-form-card" class="card" style="max-width: 900px; margin: 16px 0; padding: 16px; display: none;">
		<h2><?php esc_html_e( 'New Guest', 'arriendo-facil' ); ?></h2>
		<form id="af-guest-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="af_create_guest" />
			<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'af_guest_nonce' ) ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="af_guest_id_number"><?php esc_html_e( 'ID (National ID or Passport)*', 'arriendo-facil' ); ?></label></th>
					<td><input type="text" required id="af_guest_id_number" name="id_number" class="regular-text" inputmode="numeric" pattern="^[0-9]{1,10}$" maxlength="10" title="Use only numbers (max 10)" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_name"><?php esc_html_e( 'Name*', 'arriendo-facil' ); ?></label></th>
					<td><input type="text" required id="af_guest_name" name="name" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_email"><?php esc_html_e( 'Email*', 'arriendo-facil' ); ?></label></th>
					<td><input type="email" required id="af_guest_email" name="email" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_phone"><?php esc_html_e( 'Contact*', 'arriendo-facil' ); ?></label></th>
					<td><input type="text" required id="af_guest_phone" name="phone" class="regular-text" inputmode="numeric" pattern="^[0-9]{1,10}$" maxlength="10" title="Use only numbers (max 10)" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_mascotas"><?php esc_html_e( 'Pets (1 to 10)*', 'arriendo-facil' ); ?></label></th>
					<td><input type="number" required id="af_guest_mascotas" name="mascotas" class="small-text" min="1" max="10" step="1" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_referencia_1"><?php esc_html_e( 'Personal References (min 2)*', 'arriendo-facil' ); ?></label></th>
					<td>
						<input type="text" required id="af_guest_referencia_1" name="referencia_personal_1" class="regular-text" placeholder="Personal reference 1" style="margin-bottom:8px;" />
						<br />
						<input type="text" required id="af_guest_referencia_2" name="referencia_personal_2" class="regular-text" placeholder="Personal reference 2" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_personas_viviran"><?php esc_html_e( 'How many people will live in or enter the property?*', 'arriendo-facil' ); ?></label></th>
					<td>
						<select id="af_guest_personas_viviran" name="personas_viviran" required>
							<option value="">--</option>
							<?php for ( $i = 1; $i <= 10; $i++ ) : ?>
								<option value="<?php echo esc_attr( (string) $i ); ?>"><?php echo esc_html( (string) $i ); ?></option>
							<?php endfor; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_garantia_alicuota_pdf"><?php esc_html_e( 'Guarantee and HOA Fee (PDF)', 'arriendo-facil' ); ?></label></th>
					<td><input type="file" id="af_guest_garantia_alicuota_pdf" name="guest_garantia_alicuota_pdf" class="regular-text" accept="application/pdf,.pdf" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_cedula_papeleta_pdf"><?php esc_html_e( 'National ID and Voting Certificate (PDF)', 'arriendo-facil' ); ?></label></th>
					<td><input type="file" id="af_guest_cedula_papeleta_pdf" name="guest_cedula_papeleta_pdf" class="regular-text" accept="application/pdf,.pdf" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="af_guest_certificado_bancario_pdf"><?php esc_html_e( 'Bank Certificate (PDF)', 'arriendo-facil' ); ?></label></th>
					<td><input type="file" id="af_guest_certificado_bancario_pdf" name="guest_certificado_bancario_pdf" class="regular-text" accept="application/pdf,.pdf" /></td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Guest', 'arriendo-facil' ); ?></button>
				<button type="button" class="button" id="af-cancel-new-guest"><?php esc_html_e( 'Cancel', 'arriendo-facil' ); ?></button>
			</p>
		</form>
	</div>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID (National ID or Passport)', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Name', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Email', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Phone', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'ID Number', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'AI Score', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( $guests ) : ?>
				<?php foreach ( $guests as $guest ) : ?>
					<tr>
						<td><?php echo esc_html( $guest->id_number ); ?></td>
						<td><?php echo esc_html( $guest->first_name . ' ' . $guest->last_name ); ?></td>
						<td><?php echo esc_html( $guest->email ); ?></td>
						<td><?php echo esc_html( $guest->phone ); ?></td>
						<td>—</td>
						<td>
							<?php echo $guest->ai_score ? esc_html( number_format( (float) $guest->ai_score, 2 ) ) : '—'; ?>
						</td>
						<td>
							<button type="button" class="button af-score-guest"
								data-guest-id="<?php echo esc_attr( $guest->id ); ?>">
								<?php esc_html_e( 'Score (AI)', 'arriendo-facil' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="7"><?php esc_html_e( 'No guests found.', 'arriendo-facil' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>
