<?php
/**
 * Persistence of generated lease contract files and owner templates.
 *
 * @package Arriendo_Facil
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_Contract_File_Store
 *
 * Mirrors generated contracts to private storage (R2) so any web node behind
 * a load balancer can serve them, and records the storage metadata used by
 * the secure download endpoint (af_download_lease_contract).
 */
class Arriendo_Facil_Contract_File_Store {

	/**
	 * Secure (capability + nonce checked) download URL for a lease contract.
	 *
	 * @param int $lease_id Lease ID.
	 * @return string
	 */
	public static function download_url( $lease_id ) {
		return add_query_arg(
			array(
				'action'   => 'af_download_lease_contract',
				'lease_id' => absint( $lease_id ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Registers a freshly generated contract file: uploads it to R2 when
	 * enabled and stores the lease storage metadata.
	 *
	 * @param int    $lease_id  Lease ID.
	 * @param string $file_path Absolute local path of the generated file.
	 * @param string $file_name File name.
	 * @param string $local_url Public uploads URL of the local copy.
	 * @param string $mime_type Mime type.
	 * @return string URL to hand out for the document.
	 */
	public static function persist( $lease_id, $file_path, $file_name, $local_url, $mime_type ) {
		$lease_id     = absint( $lease_id );
		$document_url = $local_url;
		$storage_meta = array(
			'provider'  => 'local',
			'file_name' => $file_name,
			'local_url' => $local_url,
			'mime_type' => $mime_type,
		);

		if ( Arriendo_Facil_Private_Storage::is_r2_enabled() ) {
			$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $contents ) {
				$object_key = sprintf( 'lease-contracts/%d/%s', $lease_id, sanitize_file_name( $file_name ) );
				$upload     = Arriendo_Facil_Private_Storage::upload( $contents, $object_key, $mime_type );

				if ( is_wp_error( $upload ) ) {
					error_log( 'Arriendo Facil contract R2 upload failed for lease ' . $lease_id . ': ' . $upload->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				} else {
					$document_url = self::download_url( $lease_id );
					$storage_meta = array(
						'provider'   => Arriendo_Facil_Private_Storage::PROVIDER_R2,
						'object_key' => $object_key,
						'file_name'  => $file_name,
						'local_url'  => '',
						'mime_type'  => $mime_type,
					);
					self::discard_local_copy( $file_path );
				}
			}
		}

		if ( class_exists( 'Arriendo_Facil_Lease' ) ) {
			( new Arriendo_Facil_Lease() )->set_contract_storage_meta( $lease_id, $storage_meta );
		}

		return $document_url;
	}

	/**
	 * Removes the node-local copy once the file lives in shared private storage,
	 * so no contract remains on (or is served from) a single web node's disk.
	 *
	 * @param string $file_path Absolute local path.
	 * @return void
	 */
	public static function discard_local_copy( $file_path ) {
		if ( '' !== (string) $file_path && file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}
	}

	/**
	 * Downloads an owner's DOCX template from R2 to a temp file (used when the
	 * local attachment file is missing, e.g. on another web node).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|false Temp file path (caller must unlink) or false.
	 */
	public static function fetch_owner_template( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}

		$object_key = trim( (string) get_post_meta( $attachment_id, '_af_r2_object_key', true ) );
		if ( '' === $object_key ) {
			return false;
		}

		$tmp_file = Arriendo_Facil_Private_Storage::download_to_temp_file( $object_key, 'af_owner_template_' . $attachment_id . '.docx' );
		if ( is_wp_error( $tmp_file ) ) {
			error_log( 'Arriendo Facil R2 template download error: ' . $tmp_file->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		return $tmp_file;
	}
}
