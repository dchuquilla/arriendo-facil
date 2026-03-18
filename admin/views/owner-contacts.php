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

         <form id="af-owner-contact-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
            <input type="hidden" name="action" value="af_send_owner_contact" />
            <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'af_owner_contact_nonce' ) ); ?>" />
						<input type="hidden" name="redirect_to" value="<?php echo esc_url( admin_url( 'admin.php?page=af-owner-contacts&action=new' ) ); ?>" />

            <table class="form-table" role="presentation">
								<tr>
                    <th scope="row"><label for="af_owner_id_type"><?php esc_html_e( 'Document Type', 'arriendo-facil' ); ?></label></th>
                    <td>
                        <select id="af_owner_id_type" name="owner_id_type" required>
                            <option value="cedula"><?php esc_html_e( 'Cedula', 'arriendo-facil' ); ?></option>
                            <option value="ruc"><?php esc_html_e( 'RUC', 'arriendo-facil' ); ?></option>
                            <option value="pasaporte"><?php esc_html_e( 'Pasaporte', 'arriendo-facil' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="af_owner_id"><?php esc_html_e( 'Owner ID', 'arriendo-facil' ); ?></label></th>
                    <td><input type="text" required id="af_owner_id" name="owner_id" class="regular-text" /></td>
                </tr>
								<tr>
										<th scope="row"><label for="af_owner_email"><?php esc_html_e( 'Owner Email', 'arriendo-facil' ); ?></label></th>
										<td>
												<input type="email" required id="af_owner_email" name="owner_email" class="regular-text" autocomplete="email" />
												<p class="description"><?php esc_html_e( 'Temporary credentials will be sent to this email.', 'arriendo-facil' ); ?></p>
										</td>
								</tr>
                <tr>
                    <th scope="row"><label for="af_subject"><?php esc_html_e( 'Client Name', 'arriendo-facil' ); ?></label></th>
                    <td><input type="text" required id="af_subject" name="subject" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="af_message"><?php esc_html_e( 'Contract Parameter Details', 'arriendo-facil' ); ?></label></th>
                    <td><textarea required id="af_message" name="message" rows="5" class="large-text"></textarea></td>
                </tr>
            </table>

            <p class="submit">
                <button id="af-owner-contact-submit" type="submit" class="button button-primary">
                    <?php esc_html_e( 'Register Owner', 'arriendo-facil' ); ?>
                </button>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=af-owner-contacts' ) ); ?>"><?php esc_html_e( 'Cancel', 'arriendo-facil' ); ?></a>
            </p>
        </form>
    </div>
<?php endif; ?>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'ID', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Document Type*', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Owner ID*', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Client Name*', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Owner Email*', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Contract Parameter Details*', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Status', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Date', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( $contacts ) : ?>
				<?php foreach ( $contacts as $contact ) : ?>
					<tr>
						<td><?php echo esc_html( $contact->id ); ?></td>
						<td><?php echo esc_html( $contact->owner_id_type ); ?></td>
						<td><?php echo esc_html( $contact->owner_id ); ?></td>
						<td><?php echo esc_html( $contact->owner_email ); ?></td>
						<td><?php echo esc_html( $contact->subject ); ?></td>
						<td><?php echo esc_html( wp_trim_words( $contact->message, 15 ) ); ?></td>
						<td class="af-contact-status"><?php echo esc_html( $contact->status ); ?></td>
						<td><?php echo esc_html( $contact->created_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
					<td colspan="8"><?php esc_html_e( 'No contacts found.', 'arriendo-facil' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>

<script>
( function () {
    var typeEl = document.getElementById( 'af_owner_id_type' );
    var idEl = document.getElementById( 'af_owner_id' );
    var formEl = document.getElementById( 'af-owner-contact-form' );

    if ( ! typeEl || ! idEl ) {
        return;
    }

		function enforceUppercase(){
			idEl.value = idEl.value.toUpperCase();
		}
    function applyRules() {
				idEl.removeEventListener( 'input', enforceUppercase );
        var type = typeEl.value;

        if ( type === 'cedula' ) {
            idEl.setAttribute( 'pattern', '^[0-9]{10}$' );
            idEl.setAttribute( 'minlength', '10' );
            idEl.setAttribute( 'maxlength', '10' );
            idEl.setAttribute( 'title', 'Cedula: exactamente 10 digitos numericos' );
            return;
        }

        if ( type === 'ruc' ) {
            idEl.setAttribute( 'pattern', '^[0-9]{13}$' );
            idEl.setAttribute( 'minlength', '13' );
            idEl.setAttribute( 'maxlength', '13' );
            idEl.setAttribute( 'title', 'RUC: exactamente 13 digitos numericos' );
            return;
        }

        idEl.setAttribute( 'pattern', '^[A-Za-z0-9]{6,15}$' );
        idEl.setAttribute( 'minlength', '6' );
        idEl.setAttribute( 'maxlength', '15' );
        idEl.setAttribute( 'title', 'Pasaporte: alfanumerico de 6 a 15 caracteres' );
				idEl.addEventListener( 'input', enforceUppercase );
        enforceUppercase();
    }

    typeEl.addEventListener( 'change', function () {
        idEl.value = '';
        applyRules();
    } );

    if ( formEl ) {
        formEl.addEventListener( 'submit', applyRules );
    }

    applyRules();
} )();
</script>