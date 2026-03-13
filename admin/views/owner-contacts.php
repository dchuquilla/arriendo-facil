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
// Define if we are in "new contact" mode.
$is_new = isset( $_GET['action'] ) && 'new' === sanitize_key( wp_unslash( $_GET['action'] ) );
?>
<div class="wrap">
	<h1>
		<?php esc_html_e( 'Owner Contacts', 'arriendo-facil' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-owner-contacts&action=new' ) ); ?>" class="page-title-action">
			<?php esc_html_e( '+ New Owner Contact', 'arriendo-facil' ); ?>
		</a>
	</h1>

	<?php if ( $is_new ) : ?>
    <div class="card" style="max-width: 900px; margin: 16px 0; padding: 16px;">
        <h2><?php esc_html_e( 'New Owner Contact', 'arriendo-facil' ); ?></h2>

        <form id="af-owner-contact-form">
            <input type="hidden" name="action" value="af_send_owner_contact" />
            <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'af_owner_contact_nonce' ) ); ?>" />

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="af_owner_id"><?php esc_html_e( 'Owner ID', 'arriendo-facil' ); ?></label></th>
                    <td><input type="number" min="1" required id="af_owner_id" name="owner_id" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="af_subject"><?php esc_html_e( 'Subject', 'arriendo-facil' ); ?></label></th>
                    <td><input type="text" required id="af_subject" name="subject" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="af_message"><?php esc_html_e( 'Message', 'arriendo-facil' ); ?></label></th>
                    <td><textarea required id="af_message" name="message" rows="5" class="large-text"></textarea></td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Send Message', 'arriendo-facil' ); ?></button>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=af-owner-contacts' ) ); ?>"><?php esc_html_e( 'Cancel', 'arriendo-facil' ); ?></a>
            </p>
        </form>
    </div>
<?php endif; ?>

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
