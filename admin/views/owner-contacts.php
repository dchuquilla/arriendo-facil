<?php
/**
 * Owner contacts admin page view.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$contacts = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}af_owner_contacts ORDER BY created_at DESC LIMIT 100"
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Owner Contacts', 'arriendo-facil' ); ?></h1>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Owner ID', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Subject', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Message', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Status', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Date', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( $contacts ) : ?>
				<?php foreach ( $contacts as $contact ) : ?>
					<tr class="<?php echo 'unread' === $contact->status ? 'af-unread' : ''; ?>">
						<td><?php echo esc_html( $contact->id ); ?></td>
						<td><?php echo esc_html( $contact->owner_id ); ?></td>
						<td><?php echo esc_html( $contact->subject ); ?></td>
						<td><?php echo esc_html( wp_trim_words( $contact->message, 15 ) ); ?></td>
						<td><?php echo esc_html( $contact->status ); ?></td>
						<td><?php echo esc_html( $contact->created_at ); ?></td>
						<td>
							<?php if ( 'unread' === $contact->status ) : ?>
								<button type="button" class="button af-mark-read"
									data-contact-id="<?php echo esc_attr( $contact->id ); ?>">
									<?php esc_html_e( 'Mark Read', 'arriendo-facil' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="7"><?php esc_html_e( 'No contacts found.', 'arriendo-facil' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>
