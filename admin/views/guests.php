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
		<form id="af-guest-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<input type="hidden" name="action" value="af_create_guest" />
			<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'af_guest_nonce' ) ); ?>" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="af_guest_id_number"><?php esc_html_e( 'ID (cedula o pasaporte)*', 'arriendo-facil' ); ?></label></th>
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
					<th scope="row"><label for="af_guest_phone"><?php esc_html_e( 'Phone*', 'arriendo-facil' ); ?></label></th>
					<td><input type="text" required id="af_guest_phone" name="phone" class="regular-text" inputmode="numeric" pattern="^[0-9]{1,10}$" maxlength="10" title="Use only numbers (max 10)" /></td>
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
				<th><?php esc_html_e( 'ID', 'arriendo-facil' ); ?></th>
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
						<td><?php echo esc_html( $guest->id ); ?></td>
						<td><?php echo esc_html( $guest->first_name . ' ' . $guest->last_name ); ?></td>
						<td><?php echo esc_html( $guest->email ); ?></td>
						<td><?php echo esc_html( $guest->phone ); ?></td>
						<td><?php echo esc_html( $guest->id_number ); ?></td>
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
