<?php
/**
 * Per-user isolation of the WordPress media library.
 *
 * Every property manager (af_property_admin) is a separate client of the
 * platform, so the media picker used when registering a property must only
 * list the files that person uploaded. Platform administrators keep the full
 * library.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Media_Isolation
 */
class Arriendo_Facil_Media_Isolation {

	/**
	 * Registers every entry point through which the library can be listed or edited.
	 */
	public function __construct() {
		add_filter( 'ajax_query_attachments_args', array( $this, 'scope_ajax_query' ) );
		add_action( 'pre_get_posts', array( $this, 'scope_admin_list' ) );
		add_filter( 'rest_attachment_query', array( $this, 'scope_rest_query' ) );
		add_filter( 'map_meta_cap', array( $this, 'protect_foreign_attachments' ), 10, 4 );
	}

	/**
	 * Whether the current request must be limited to the user's own files.
	 *
	 * @return bool
	 */
	private function must_scope() {
		return is_user_logged_in() && ! current_user_can( 'manage_options' );
	}

	/**
	 * Media modal (wp.media) query.
	 *
	 * @param array $query WP_Query args built from the modal request.
	 * @return array
	 */
	public function scope_ajax_query( $query ) {
		if ( $this->must_scope() ) {
			$query['author'] = get_current_user_id();
		}

		return $query;
	}

	/**
	 * Media library list screen (upload.php).
	 *
	 * @param WP_Query $query Query being prepared.
	 * @return void
	 */
	public function scope_admin_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || ! $this->must_scope() ) {
			return;
		}

		if ( 'attachment' === $query->get( 'post_type' ) ) {
			$query->set( 'author', get_current_user_id() );
		}
	}

	/**
	 * REST /wp/v2/media collection.
	 *
	 * @param array $args WP_Query args.
	 * @return array
	 */
	public function scope_rest_query( $args ) {
		if ( $this->must_scope() ) {
			$args['author'] = get_current_user_id();
		}

		return $args;
	}

	/**
	 * Blocks editing/deleting files owned by someone else even when the ID is
	 * guessed (IDOR against admin-ajax / REST media endpoints).
	 *
	 * @param string[] $caps    Required primitive caps.
	 * @param string   $cap     Meta capability being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Extra args (first is the post ID).
	 * @return string[]
	 */
	public function protect_foreign_attachments( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return $caps;
		}

		$post = get_post( absint( $args[0] ) );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $caps;
		}

		if ( (int) $post->post_author !== (int) $user_id ) {
			return array( 'do_not_allow' );
		}

		return $caps;
	}
}
