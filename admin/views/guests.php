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
