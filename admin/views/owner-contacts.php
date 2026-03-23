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
$notice = isset( $_GET['af_notice'] ) ? sanitize_key( wp_unslash( $_GET['af_notice'] ) ) : '';
$message = isset( $_GET['af_message'] ) ? sanitize_text_field( wp_unslash( $_GET['af_message'] ) ) : '';
?>
<div class="wrap">
	<h1>
		<?php esc_html_e( 'Owner Contacts', 'arriendo-facil' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=af-owner-contacts&action=new' ) ); ?>" class="page-title-action">
			<?php esc_html_e( '+ New Owner Contact', 'arriendo-facil' ); ?>
		</a>
	</h1>

    <?php if ( 'owner_disabled' === $notice ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Owner account disabled successfully.', 'arriendo-facil' ); ?></p></div>
    <?php elseif ( 'owner_disable_error' === $notice ) : ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php
                echo esc_html(
                    $message
                        ? $message
                        : __( 'Could not disable owner account.', 'arriendo-facil' )
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

	<?php if ( $is_new ) : ?>
    <div class="card" style="max-width: 900px; margin: 16px 0; padding: 16px;">
        <h2><?php esc_html_e( 'New Owner Contact', 'arriendo-facil' ); ?></h2>

         <form id="af-owner-contact-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
            <input type="hidden" name="action" value="af_send_owner_contact" />
            <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'af_owner_contact_nonce' ) ); ?>" />
						<input type="hidden" name="redirect_to" value="<?php echo esc_url( admin_url( 'admin.php?page=af-owner-contacts&action=new' ) ); ?>" />

            <table class="form-table" role="presentation">
								<tr>
                    <th scope="row"><label for="af_owner_id_type"><?php esc_html_e( 'Document Type*', 'arriendo-facil' ); ?></label></th>
                    <td>
                        <select id="af_owner_id_type" name="owner_id_type" required>
                            <option value="cedula"><?php esc_html_e( 'Cedula', 'arriendo-facil' ); ?></option>
                            <option value="ruc"><?php esc_html_e( 'RUC', 'arriendo-facil' ); ?></option>
                            <option value="pasaporte"><?php esc_html_e( 'Pasaporte', 'arriendo-facil' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="af_owner_id"><?php esc_html_e( 'Owner ID*', 'arriendo-facil' ); ?></label></th>
                    <td><input type="text" required id="af_owner_id" name="owner_id" class="regular-text" /></td>
                </tr>
								<tr>
										<th scope="row"><label for="af_owner_email"><?php esc_html_e( 'Owner Email*', 'arriendo-facil' ); ?></label></th>
										<td>
												<input type="email" required id="af_owner_email" name="owner_email" class="regular-text" autocomplete="email" />
                                                <p class="description"><?php esc_html_e( 'Activation instructions will be sent only to this email.', 'arriendo-facil' ); ?></p>
										</td>
								</tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e( 'Has Legal Agent?', 'arriendo-facil' ); ?></th>
                                    <td>
                                        <fieldset>
                                            <label style="margin-right:16px;">
                                                <input type="radio" name="has_legal_agent" value="0" checked />
                                                <?php esc_html_e( 'No', 'arriendo-facil' ); ?>
                                            </label>
                                            <label>
                                                <input type="radio" name="has_legal_agent" value="1" />
                                                <?php esc_html_e( 'Yes', 'arriendo-facil' ); ?>
                                            </label>
                                        </fieldset>
                                    </td>
                                </tr>
                                <tr id="af-legal-agent-fields" style="display:none;">
                                    <th scope="row"><?php esc_html_e( 'Legal Agent Details', 'arriendo-facil' ); ?></th>
                                    <td>
                                        <p>
                                            <label for="af_legal_agent_name"><?php esc_html_e( 'Name', 'arriendo-facil' ); ?></label><br />
                                            <input type="text" id="af_legal_agent_name" name="legal_agent_name" class="regular-text" />
                                        </p>
                                        <p>
                                            <label for="af_legal_agent_id_type"><?php esc_html_e( 'ID Type', 'arriendo-facil' ); ?></label><br />
                                            <select id="af_legal_agent_id_type" name="legal_agent_id_type">
                                                <option value="cedula"><?php esc_html_e( 'Cedula', 'arriendo-facil' ); ?></option>
                                                <option value="ruc"><?php esc_html_e( 'RUC', 'arriendo-facil' ); ?></option>
                                                <option value="pasaporte"><?php esc_html_e( 'Pasaporte', 'arriendo-facil' ); ?></option>
                                            </select>
                                        </p>
                                        <p>
                                            <label for="af_legal_agent_id"><?php esc_html_e( 'ID Number', 'arriendo-facil' ); ?></label><br />
                                            <input type="text" id="af_legal_agent_id" name="legal_agent_id" class="regular-text" />
                                        </p>
                                        <p>
                                            <label for="af_legal_agent_phone"><?php esc_html_e( 'Phone', 'arriendo-facil' ); ?></label><br />
                                            <input type="text" id="af_legal_agent_phone" name="legal_agent_phone" class="regular-text" autocomplete="tel" inputmode="numeric" pattern="^[0-9]+$" title="Use only numbers" />
                                        </p>
                                        <p>
                                            <label for="af_legal_agent_email"><?php esc_html_e( 'Email', 'arriendo-facil' ); ?></label><br />
                                            <input type="email" id="af_legal_agent_email" name="legal_agent_email" class="regular-text" autocomplete="email" />
                                            <span class="description" style="display:block;margin-top:6px;">
                                                <?php esc_html_e( 'No activation email is sent to the legal agent.', 'arriendo-facil' ); ?>
                                            </span>
                                        </p>
                                    </td>
                                </tr>
                <tr>
                    <th scope="row"><label for="af_subject"><?php esc_html_e( 'Client Name*', 'arriendo-facil' ); ?></label></th>
                    <td><input type="text" required id="af_subject" name="subject" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="af_message"><?php esc_html_e( 'Contract Parameter Details*', 'arriendo-facil' ); ?></label></th>
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
				<th><?php esc_html_e( 'Document Type', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Owner ID', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Owner Email', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Client Name', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Contract Parameter Details', 'arriendo-facil' ); ?></th>
                <th><?php esc_html_e( 'Legal Agent', 'arriendo-facil' ); ?></th>
                <th><?php esc_html_e( 'Details', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Status', 'arriendo-facil' ); ?></th>
                <th><?php esc_html_e( 'Account Status', 'arriendo-facil' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'arriendo-facil' ); ?></th>
				<th><?php esc_html_e( 'Date', 'arriendo-facil' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( $contacts ) : ?>
				<?php foreach ( $contacts as $contact ) : ?>
                    <?php
                    $account_status = '';
                    if ( ! empty( $contact->wp_user_id ) ) {
                        $account_status = (string) get_user_meta( (int) $contact->wp_user_id, 'af_owner_account_status', true );
                        if ( '' === $account_status ) {
                            $account_status = 'inactive';
                        }
                    }
                    ?>
					<tr>
						<td><?php echo esc_html( $contact->id ); ?></td>
						<td><?php echo esc_html( $contact->owner_id_type ); ?></td>
						<td><?php echo esc_html( $contact->owner_id ); ?></td>
						<td><?php echo esc_html( $contact->owner_email ); ?></td>
						<td><?php echo esc_html( $contact->subject ); ?></td>
						<td><?php echo esc_html( wp_trim_words( $contact->message, 15 ) ); ?></td>
                        <td><?php echo ! empty( $contact->has_legal_agent ) ? esc_html__( 'Yes', 'arriendo-facil' ) : esc_html__( 'No', 'arriendo-facil' ); ?></td>
                        <td>
                            <?php
                            if ( ! empty( $contact->has_legal_agent ) ) {
                                ?>
                                <button
                                    type="button"
                                    class="button button-secondary af-open-legal-agent-modal"
                                    data-legal-agent-name="<?php echo esc_attr( (string) $contact->legal_agent_name ); ?>"
                                    data-legal-agent-id-type="<?php echo esc_attr( strtoupper( (string) $contact->legal_agent_id_type ) ); ?>"
                                    data-legal-agent-id="<?php echo esc_attr( (string) $contact->legal_agent_id ); ?>"
                                    data-legal-agent-phone="<?php echo esc_attr( (string) $contact->legal_agent_phone ); ?>"
                                    data-legal-agent-email="<?php echo esc_attr( (string) $contact->legal_agent_email ); ?>"
                                >
                                    <?php esc_html_e( 'More Details', 'arriendo-facil' ); ?>
                                </button>
                                <?php
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                        <td class="af-contact-status"><?php echo esc_html( $contact->status ); ?></td>
                        <td class="af-account-status"><?php echo esc_html( $account_status ? $account_status : 'n/a' ); ?></td>
                        <td class="af-account-actions">
                            <?php if ( ! empty( $contact->wp_user_id ) && 'active' === $account_status ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Disable this account? The user will no longer be able to log in.');" style="display:inline;">
                                    <input type="hidden" name="action" value="af_disable_owner_account" />
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr( (int) $contact->wp_user_id ); ?>" />
                                    <?php wp_nonce_field( 'af_disable_owner_account_' . (int) $contact->wp_user_id, 'af_disable_owner_account_nonce' ); ?>
                                    <button type="submit" class="button button-secondary">
                                        <?php esc_html_e( 'Disable Account', 'arriendo-facil' ); ?>
                                    </button>
                                </form>
                            <?php else : ?>
                                <span class="description">-</span>
                            <?php endif; ?>
                        </td>
						<td><?php echo esc_html( $contact->created_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr>
                    <td colspan="12"><?php esc_html_e( 'No contacts found.', 'arriendo-facil' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

    <div id="af-legal-agent-modal" class="af-modal" hidden>
        <div class="af-modal__backdrop" data-af-close-modal="1"></div>
        <div class="af-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="af-legal-agent-modal-title">
            <div class="af-modal__header">
                <h2 id="af-legal-agent-modal-title"><?php esc_html_e( 'Legal Agent Details', 'arriendo-facil' ); ?></h2>
                <button type="button" class="button-link af-modal__close" data-af-close-modal="1" aria-label="<?php esc_attr_e( 'Close', 'arriendo-facil' ); ?>">&times;</button>
            </div>
            <div class="af-modal__body">
                <p><strong><?php esc_html_e( 'Name', 'arriendo-facil' ); ?>:</strong> <span data-af-field="name">-</span></p>
                <p><strong><?php esc_html_e( 'ID', 'arriendo-facil' ); ?>:</strong> <span data-af-field="id">-</span></p>
                <p><strong><?php esc_html_e( 'Phone', 'arriendo-facil' ); ?>:</strong> <span data-af-field="phone">-</span></p>
                <p><strong><?php esc_html_e( 'Email', 'arriendo-facil' ); ?>:</strong> <span data-af-field="email">-</span></p>
            </div>
        </div>
    </div>
</div>