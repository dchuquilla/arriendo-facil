<?php
/**
 * Class Arriendo_Facil_Account_Deletion
 *
 * Un gestor de propiedades (af_property_admin) puede dar de baja su propia
 * cuenta: se DESACTIVA (soft delete) y el administrador decide después si la
 * reactiva o la elimina definitivamente con todos sus datos. Los
 * administradores (manage_options) nunca pueden darse de baja.
 *
 * Se conservan a propósito: los comprobantes electrónicos del SRI y su log
 * (obligación de conservación tributaria) y las cuentas de usuario de los
 * inquilinos.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Arriendo_Facil_Account_Deletion {

	const ACTION       = 'af_delete_own_account';
	const ADMIN_ACTION = 'af_account_admin_action';
	const STATUS_META  = 'af_account_status';
	const CONFIRM = 'ELIMINAR';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render_card' ), 30 );
		add_action( 'admin_footer-profile.php', array( __CLASS__, 'render_form_shell' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_error_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_request' ) );
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( __CLASS__, 'handle_admin_action' ) );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_deactivated_login' ), 4, 1 );
	}

	/**
	 * Shows the failure message after a rejected request.
	 *
	 * @return void
	 */
	public static function render_error_notice() {
		global $pagenow;
		if ( 'profile.php' !== $pagenow ) {
			return;
		}
		$key = 'af_delete_error_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( ! $msg ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $msg ) );
	}

	/**
	 * Whether the user may delete their own account.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function can_delete( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || get_current_user_id() !== $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) || is_super_admin( $user_id ) ) {
			return false;
		}
		return Arriendo_Facil_Tenancy::is_property_manager( $user_id );
	}

	/**
	 * Danger-zone card inside the profile form (fields point to the external form).
	 *
	 * @param WP_User $user Profile user.
	 * @return void
	 */
	public static function render_card( $user ) {
		if ( ! self::can_delete( $user->ID ) ) {
			return;
		}
		?>
		<div class="af-danger-card" id="af-danger-zone">
			<h2><?php esc_html_e( 'Dar de baja mi cuenta', 'arriendo-facil' ); ?></h2>
			<p class="af-danger-card__lead"><?php esc_html_e( 'Tu cuenta se desactivará y perderás el acceso de inmediato.', 'arriendo-facil' ); ?></p>
			<ul class="af-danger-card__list">
				<li><?php esc_html_e( 'Tus propiedades dejarán de mostrarse y no podrás iniciar sesión.', 'arriendo-facil' ); ?></li>
				<li><?php esc_html_e( 'Tus datos no se borran en este momento: el administrador de la plataforma decidirá si reactiva tu cuenta o la elimina definitivamente.', 'arriendo-facil' ); ?></li>
				<li><?php esc_html_e( 'Si quieres volver, contacta al administrador.', 'arriendo-facil' ); ?></li>
			</ul>
			<button type="button" class="af-danger-card__open" aria-expanded="false" aria-controls="af-danger-confirm"><?php esc_html_e( 'Quiero dar de baja mi cuenta', 'arriendo-facil' ); ?></button>
			<div class="af-danger-card__confirm" id="af-danger-confirm" hidden>
				<label for="af_delete_password"><?php esc_html_e( 'Tu contraseña actual', 'arriendo-facil' ); ?></label>
				<input type="password" id="af_delete_password" name="af_delete_password" form="af-delete-account-form" autocomplete="current-password" />
				<label for="af_delete_confirm">
					<?php
					printf(
						/* translators: %s: confirmation word. */
						esc_html__( 'Escribe %s para confirmar', 'arriendo-facil' ),
						'<strong>' . esc_html( self::CONFIRM ) . '</strong>'
					);
					?>
				</label>
				<input type="text" id="af_delete_confirm" name="af_delete_confirm" form="af-delete-account-form" autocomplete="off" />
				<div class="af-danger-card__actions">
					<button type="submit" form="af-delete-account-form" class="af-danger-card__submit" id="af-delete-submit" disabled><?php esc_html_e( 'Dar de baja mi cuenta', 'arriendo-facil' ); ?></button>
					<button type="button" class="af-danger-card__cancel"><?php esc_html_e( 'Cancelar', 'arriendo-facil' ); ?></button>
				</div>
			</div>
		</div>
		<dialog class="af-danger-dialog" id="af-danger-dialog" aria-labelledby="af-danger-dialog-title">
			<h3 id="af-danger-dialog-title"><?php esc_html_e( '¿Seguro que quieres dar de baja tu cuenta?', 'arriendo-facil' ); ?></h3>
			<p><?php esc_html_e( 'Se cerrará tu sesión y no podrás volver a entrar hasta que el administrador reactive tu cuenta.', 'arriendo-facil' ); ?></p>
			<div class="af-danger-card__actions">
				<button type="button" class="af-danger-card__submit" id="af-danger-dialog-yes"><?php esc_html_e( 'Sí, dar de baja', 'arriendo-facil' ); ?></button>
				<button type="button" class="af-danger-card__cancel" id="af-danger-dialog-no" autofocus><?php esc_html_e( 'No, volver', 'arriendo-facil' ); ?></button>
			</div>
		</dialog>
		<script>
		( function () {
			var card = document.getElementById( 'af-danger-zone' );
			if ( ! card ) { return; }
			var open = card.querySelector( '.af-danger-card__open' );
			var box = document.getElementById( 'af-danger-confirm' );
			var pass = document.getElementById( 'af_delete_password' );
			var word = document.getElementById( 'af_delete_confirm' );
			var submit = document.getElementById( 'af-delete-submit' );
			function sync() { submit.disabled = ! ( pass.value.length > 0 && word.value.trim() === <?php echo wp_json_encode( self::CONFIRM ); ?> ); }
			function toggle( show ) {
				box.hidden = ! show;
				open.hidden = show;
				open.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
				if ( show ) { pass.focus(); }
			}
			open.addEventListener( 'click', function () { toggle( true ); } );
			card.querySelector( '.af-danger-card__cancel' ).addEventListener( 'click', function () { pass.value = ''; word.value = ''; sync(); toggle( false ); } );
			var dialog = document.getElementById( 'af-danger-dialog' );
			var form = document.getElementById( 'af-delete-account-form' );
			var confirmed = false;
			submit.addEventListener( 'click', function ( e ) {
				if ( confirmed ) { return; }
				e.preventDefault();
				if ( dialog && dialog.showModal ) { dialog.showModal(); }
				else if ( window.confirm( <?php echo wp_json_encode( __( '¿Seguro que quieres dar de baja tu cuenta?', 'arriendo-facil' ) ); ?> ) ) { confirmed = true; form.submit(); }
			} );
			document.getElementById( 'af-danger-dialog-no' ).addEventListener( 'click', function () { dialog.close(); } );
			document.getElementById( 'af-danger-dialog-yes' ).addEventListener( 'click', function () { confirmed = true; dialog.close(); form.submit(); } );
			pass.addEventListener( 'input', sync );
			word.addEventListener( 'input', sync );
			// Enter en estos campos no debe guardar el perfil ni eliminar sin confirmar.
			[ pass, word ].forEach( function ( el ) {
				el.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key && submit.disabled ) { e.preventDefault(); } } );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Empty external form (a form cannot be nested inside #your-profile).
	 *
	 * @return void
	 */
	public static function render_form_shell() {
		if ( ! self::can_delete( get_current_user_id() ) ) {
			return;
		}
		?>
		<form id="af-delete-account-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION, 'af_delete_nonce' ); ?>
		</form>
		<?php
	}

	/**
	 * Handles the deletion request.
	 *
	 * @return void
	 */
	public static function handle_request() {
		$user_id  = get_current_user_id();
		$back_url = admin_url( 'profile.php' );

		if ( ! self::can_delete( $user_id ) ) {
			wp_die( esc_html__( 'No tienes permiso para eliminar esta cuenta.', 'arriendo-facil' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION, 'af_delete_nonce' );

		$password = isset( $_POST['af_delete_password'] ) ? (string) wp_unslash( $_POST['af_delete_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$confirm  = isset( $_POST['af_delete_confirm'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['af_delete_confirm'] ) ) ) : '';
		$user     = get_userdata( $user_id );

		if ( self::CONFIRM !== $confirm || ! $user || ! wp_check_password( $password, $user->user_pass, $user_id ) ) {
			set_transient( 'af_delete_error_' . $user_id, __( 'No se eliminó la cuenta: la contraseña o la palabra de confirmación no coinciden.', 'arriendo-facil' ), MINUTE_IN_SECONDS * 5 );
			wp_safe_redirect( $back_url . '#af-danger-zone' );
			exit;
		}

		self::deactivate_user( $user_id );
		wp_logout();

		wp_safe_redirect( add_query_arg( 'af_account_deactivated', '1', home_url( '/' ) ) );
		exit;
	}

	/**
	 * Soft delete: blocks access and hides the manager's properties.
	 *
	 * @param int $user_id Property manager user ID.
	 * @return void
	 */
	public static function deactivate_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
			return;
		}

		update_user_meta( $user_id, 'af_prev_license_status', get_user_meta( $user_id, 'af_license_status', true ) );
		update_user_meta( $user_id, 'af_license_status', 'suspended' );
		update_user_meta( $user_id, self::STATUS_META, 'deactivated' );
		update_user_meta( $user_id, 'af_deactivated_at', current_time( 'mysql' ) );

		$acc_ids = get_posts(
			array(
				'post_type'      => 'accommodation',
				'post_status'    => array( 'publish', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_af_owner_id',
						'value' => $user_id,
					),
				),
			)
		);
		foreach ( $acc_ids as $acc_id ) {
			update_post_meta( $acc_id, '_af_prev_status', get_post_status( $acc_id ) );
			wp_update_post(
				array(
					'ID'          => $acc_id,
					'post_status' => 'draft',
				)
			);
		}

		WP_Session_Tokens::get_instance( $user_id )->destroy_all();
	}

	/**
	 * Restores a deactivated manager.
	 *
	 * @param int $user_id Property manager user ID.
	 * @return void
	 */
	public static function reactivate_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}

		$prev = get_user_meta( $user_id, 'af_prev_license_status', true );
		update_user_meta( $user_id, 'af_license_status', $prev ? $prev : 'active' );
		delete_user_meta( $user_id, 'af_prev_license_status' );
		delete_user_meta( $user_id, self::STATUS_META );
		delete_user_meta( $user_id, 'af_deactivated_at' );

		$acc_ids = get_posts(
			array(
				'post_type'      => 'accommodation',
				'post_status'    => 'draft',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_af_owner_id',
						'value' => $user_id,
					),
					array(
						'key'     => '_af_prev_status',
						'compare' => 'EXISTS',
					),
				),
			)
		);
		foreach ( $acc_ids as $acc_id ) {
			$prev_status = get_post_meta( $acc_id, '_af_prev_status', true );
			wp_update_post(
				array(
					'ID'          => $acc_id,
					'post_status' => $prev_status ? $prev_status : 'publish',
				)
			);
			delete_post_meta( $acc_id, '_af_prev_status' );
		}
	}

	/**
	 * Whether the user is deactivated.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_deactivated( $user_id ) {
		return 'deactivated' === get_user_meta( absint( $user_id ), self::STATUS_META, true );
	}

	/**
	 * Login block for deactivated accounts.
	 *
	 * @param WP_User|WP_Error $user User.
	 * @return WP_User|WP_Error
	 */
	public static function block_deactivated_login( $user ) {
		if ( $user instanceof WP_User && self::is_deactivated( $user->ID ) ) {
			return new WP_Error( 'af_account_deactivated', __( '<strong>Error:</strong> esta cuenta fue dada de baja. Contacta al administrador de la plataforma.', 'arriendo-facil' ) );
		}
		return $user;
	}

	/**
	 * Accounts waiting for the admin decision.
	 *
	 * @return WP_User[]
	 */
	public static function get_deactivated_users() {
		return get_users(
			array(
				'role'       => Arriendo_Facil_Tenancy::PROPERTY_MANAGER_ROLE,
				'meta_key'   => self::STATUS_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => 'deactivated', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'    => 'ID',
			)
		);
	}

	/**
	 * Admin-only: reactivate or permanently delete a deactivated manager.
	 *
	 * @return void
	 */
	public static function handle_admin_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permiso denegado.', 'arriendo-facil' ), '', array( 'response' => 403 ) );
		}
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		check_admin_referer( self::ADMIN_ACTION . '_' . $user_id, 'af_account_nonce' );

		$op      = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$target  = get_userdata( $user_id );
		$result  = 'error';
		$allowed = $target
			&& $user_id !== get_current_user_id()
			&& ! user_can( $user_id, 'manage_options' )
			&& ! is_super_admin( $user_id )
			&& Arriendo_Facil_Tenancy::is_property_manager( $user_id )
			&& self::is_deactivated( $user_id );

		if ( $allowed && 'reactivate' === $op ) {
			self::reactivate_user( $user_id );
			$result = 'reactivated';
		} elseif ( $allowed && 'purge' === $op ) {
			self::purge_user_data( $user_id );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
			$result = 'purged';
		}

		wp_safe_redirect( add_query_arg( 'af_acc_result', $result, admin_url( 'admin.php?page=af-property-admins' ) ) );
		exit;
	}

	/**
	 * Admin section listing deactivated accounts (included by the licenses view).
	 *
	 * @return void
	 */
	public static function render_admin_section() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$users  = self::get_deactivated_users();
		$result = isset( $_GET['af_acc_result'] ) ? sanitize_key( wp_unslash( $_GET['af_acc_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$labels = array(
			'reactivated' => array( 'success', __( 'La cuenta fue reactivada.', 'arriendo-facil' ) ),
			'purged'      => array( 'success', __( 'La cuenta y sus datos fueron eliminados definitivamente.', 'arriendo-facil' ) ),
			'error'       => array( 'error', __( 'No se pudo completar la acción.', 'arriendo-facil' ) ),
		);
		?>
		<div class="af-section" id="af-deactivated-accounts" style="margin-top: var(--af-space-4);">
			<header class="af-section__header">
				<div>
					<h2 class="af-section__title"><?php esc_html_e( 'Cuentas dadas de baja', 'arriendo-facil' ); ?></h2>
					<p class="af-section__subtitle"><?php esc_html_e( 'Gestores que pidieron eliminar su cuenta. Sus datos siguen guardados hasta que decidas.', 'arriendo-facil' ); ?></p>
				</div>
			</header>
			<?php if ( isset( $labels[ $result ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $labels[ $result ][0] ); ?> inline"><p><?php echo esc_html( $labels[ $result ][1] ); ?></p></div>
			<?php endif; ?>
			<table class="wp-list-table widefat fixed striped af-data-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Gestor', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Correo', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Dada de baja', 'arriendo-facil' ); ?></th>
						<th><?php esc_html_e( 'Decisión', 'arriendo-facil' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $users ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No hay cuentas pendientes de decisión.', 'arriendo-facil' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $users as $u ) : ?>
					<tr>
						<td><?php echo esc_html( $u->display_name ); ?></td>
						<td><?php echo esc_html( $u->user_email ); ?></td>
						<td><?php echo esc_html( (string) get_user_meta( $u->ID, 'af_deactivated_at', true ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-flex;gap:8px;flex-wrap:wrap;">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_ACTION ); ?>" />
								<input type="hidden" name="user_id" value="<?php echo esc_attr( $u->ID ); ?>" />
								<?php wp_nonce_field( self::ADMIN_ACTION . '_' . $u->ID, 'af_account_nonce' ); ?>
								<button type="submit" name="op" value="reactivate" class="button"><?php esc_html_e( 'Reactivar', 'arriendo-facil' ); ?></button>
								<button type="submit" name="op" value="purge" class="button" style="color:#b4443f;border-color:#d9534f;"
									onclick="return window.confirm(<?php echo esc_attr( wp_json_encode( __( 'Se eliminarán definitivamente la cuenta y todos sus datos. Esta acción no se puede deshacer. ¿Continuar?', 'arriendo-facil' ) ) ); ?>);"><?php esc_html_e( 'Eliminar definitivamente', 'arriendo-facil' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Removes everything owned by the given property manager.
	 *
	 * @param int $user_id Property manager user ID.
	 * @return void
	 */
	public static function purge_user_data( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
			return;
		}

		$p = $wpdb->prefix;

		$acc_ids = array_map(
			'absint',
			get_posts(
				array(
					'post_type'      => 'accommodation',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'   => '_af_owner_id',
							'value' => $user_id,
						),
					),
				)
			)
		);

		$building_ids = self::col( $p . 'af_buildings', 'id', 'owner_id', array( $user_id ) );
		$unit_ids     = array_unique(
			array_merge(
				self::col( $p . 'af_units', 'id', 'building_id', $building_ids ),
				self::col( $p . 'af_units', 'id', 'accommodation_id', $acc_ids )
			)
		);
		$lease_ids    = self::col( $p . 'af_leases', 'id', 'accommodation_id', $acc_ids );
		$charge_ids   = array_unique(
			array_merge(
				self::col( $p . 'af_charges', 'id', 'lease_id', $lease_ids ),
				self::col( $p . 'af_charges', 'id', 'unit_id', $unit_ids )
			)
		);
		$sched_ids    = array_unique(
			array_merge(
				self::col( $p . 'af_service_schedules', 'id', 'unit_id', $unit_ids ),
				self::col( $p . 'af_service_schedules', 'id', 'accommodation_id', $acc_ids )
			)
		);
		$group_ids    = array_unique(
			array_merge(
				self::col( $p . 'af_review_groups', 'id', 'owner_user_id', array( $user_id ) ),
				self::col( $p . 'af_review_groups', 'id', 'accommodation_id', $acc_ids )
			)
		);
		$slot_ids     = self::col( $p . 'af_visit_slots', 'id', 'accommodation_id', $acc_ids );

		// Inquilinos (af_guests) vinculados solo a este gestor.
		$guest_ids = array_unique(
			array_merge(
				self::col( $p . 'af_leases', 'guest_id', 'id', $lease_ids ),
				self::col( $p . 'af_reservations', 'guest_id', 'accommodation_id', $acc_ids )
			)
		);
		$guest_ids = array_filter( array_map( 'absint', $guest_ids ) );
		$guest_ids = array_values(
			array_filter(
				$guest_ids,
				static function ( $guest_id ) use ( $wpdb, $p, $acc_ids ) {
					if ( ! self::table_has( $p . 'af_leases', 'guest_id' ) ) {
						return false;
					}
					$others = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}af_leases WHERE guest_id = %d", $guest_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
					$mine   = $acc_ids ? self::count_in( $p . 'af_leases', 'accommodation_id', $acc_ids, 'guest_id = ' . (int) $guest_id ) : 0;
					return $others <= $mine;
				}
			)
		);

		self::delete_in( $p . 'af_payments', 'charge_id', $charge_ids );
		self::delete_in( $p . 'af_charges', 'id', $charge_ids );
		self::delete_in( $p . 'af_service_bill_payments', 'schedule_id', $sched_ids );
		self::delete_in( $p . 'af_service_schedules', 'id', $sched_ids );
		self::delete_in( $p . 'af_meter_readings', 'unit_id', $unit_ids );
		self::delete_in( $p . 'af_meter_readings', 'accommodation_id', $acc_ids );
		self::delete_in( $p . 'af_cleaning_requests', 'accommodation_id', $acc_ids );
		self::delete_in( $p . 'af_lease_events', 'lease_id', $lease_ids );
		self::delete_in( $p . 'af_lease_events', 'accommodation_id', $acc_ids );
		self::delete_in( $p . 'af_review_tokens', 'review_group_id', $group_ids );
		self::delete_in( $p . 'af_reviews', 'review_group_id', $group_ids );
		self::delete_in( $p . 'af_reviews', 'owner_user_id', array( $user_id ) );
		self::delete_in( $p . 'af_review_groups', 'id', $group_ids );
		self::delete_in( $p . 'af_notification_messages', 'accommodation_id', $acc_ids );
		self::delete_in( $p . 'af_notifications_log', 'accommodation_id', $acc_ids );
		self::delete_in( $p . 'af_calendar_blocks', 'accommodation_id', $acc_ids );
		self::delete_in( $p . 'af_visit_bookings', 'slot_id', $slot_ids );
		self::delete_in( $p . 'af_visit_bookings', 'accommodation_id', $acc_ids );
		self::delete_in( $p . 'af_visit_slots', 'id', $slot_ids );
		self::delete_in( $p . 'af_interest_queue', 'accommodation_id', $acc_ids );
		self::delete_in( $p . 'af_reservations', 'accommodation_id', $acc_ids );
		self::delete_in( $p . 'af_guest_onboarding_tokens', 'accommodation_id', $acc_ids );
		self::delete_in( $p . 'af_guest_onboarding_tokens', 'guest_id', $guest_ids );
		self::delete_in( $p . 'af_guest_documents', 'guest_id', $guest_ids );
		self::delete_in( $p . 'af_otas_sync_log', 'accommodation_id', $acc_ids );
		self::delete_in( $p . 'af_leases', 'id', $lease_ids );
		self::delete_in( $p . 'af_units', 'id', $unit_ids );
		self::delete_in( $p . 'af_buildings', 'id', $building_ids );
		self::delete_in( $p . 'af_guests', 'id', $guest_ids );

		foreach ( array( 'af_catalog_groups', 'af_service_providers', 'af_emission_points', 'af_ota_credentials' ) as $table ) {
			self::delete_in( $p . $table, 'owner_id', array( $user_id ) );
		}
		self::delete_in( $p . 'af_owner_contacts', 'wp_user_id', array( $user_id ) );
		self::delete_in( $p . 'af_admin_reviews', 'admin_user_id', array( $user_id ) );
		self::delete_in( $p . 'af_ai_processing_queue', 'user_id', array( $user_id ) );
		self::delete_in( $p . 'af_alerts', 'user_id', array( $user_id ) );
		self::delete_in( $p . 'af_idempotency_keys', 'user_id', array( $user_id ) );

		foreach ( $acc_ids as $acc_id ) {
			$attachments = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'any',
					'post_parent'    => $acc_id,
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			foreach ( $attachments as $attachment_id ) {
				wp_delete_attachment( (int) $attachment_id, true );
			}
			wp_delete_post( $acc_id, true );
		}

		$avatar_id = (int) get_user_meta( $user_id, 'af_avatar_id', true );
		if ( $avatar_id ) {
			wp_delete_attachment( $avatar_id, true );
		}
	}

	/**
	 * Whether a table has a column (also guards against missing tables).
	 *
	 * @param string $table  Full table name.
	 * @param string $column Column.
	 * @return bool
	 */
	private static function table_has( $table, $column ) {
		global $wpdb;
		static $cache = array();
		if ( ! isset( $cache[ $table ] ) ) {
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ); // phpcs:ignore WordPress.DB
			$cache[ $table ] = is_array( $cols ) ? $cols : array();
		}
		return in_array( $column, $cache[ $table ], true );
	}

	/**
	 * Placeholders for an integer list.
	 *
	 * @param int[] $ids IDs.
	 * @return string
	 */
	private static function in_list( $ids ) {
		return implode( ',', array_map( 'absint', $ids ) );
	}

	/**
	 * Selects $select from $table where $where_col IN ids.
	 *
	 * @param string $table     Table.
	 * @param string $select    Column to return.
	 * @param string $where_col Filter column.
	 * @param int[]  $ids       Values.
	 * @return int[]
	 */
	private static function col( $table, $select, $where_col, $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( ! $ids || ! self::table_has( $table, $select ) || ! self::table_has( $table, $where_col ) ) {
			return array();
		}
		$list = self::in_list( $ids );
		return array_map( 'absint', (array) $wpdb->get_col( "SELECT `{$select}` FROM `{$table}` WHERE `{$where_col}` IN ({$list})" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Counts rows where $col IN ids plus an extra trusted condition.
	 *
	 * @param string $table Table.
	 * @param string $col   Column.
	 * @param int[]  $ids   Values.
	 * @param string $extra Trusted SQL condition built from integers.
	 * @return int
	 */
	private static function count_in( $table, $col, $ids, $extra ) {
		global $wpdb;
		$list = self::in_list( $ids );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` IN ({$list}) AND {$extra}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Deletes rows where $col IN ids. Skips silently when table/column is missing.
	 *
	 * @param string $table Table.
	 * @param string $col   Column.
	 * @param int[]  $ids   Values.
	 * @return void
	 */
	private static function delete_in( $table, $col, $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( ! $ids || ! self::table_has( $table, $col ) ) {
			return;
		}
		$list = self::in_list( $ids );
		$wpdb->query( "DELETE FROM `{$table}` WHERE `{$col}` IN ({$list})" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
