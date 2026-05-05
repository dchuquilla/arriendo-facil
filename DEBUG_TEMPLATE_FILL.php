<?php
/**
 * Debug script: diagnose why DOCX template is not being filled with chatbot data
 *
 * Run in browser at: /wp-admin/admin.php?page=your-page&debug_template_walter=1
 * Or via WP-CLI: wp eval-file DEBUG_TEMPLATE_FILL.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Find owner "prueba archivos Walter" and their accommodation/template
global $wpdb;

echo "=== DOCX Template Fill Diagnostic ===\n\n";

// Step 1: Find owner user
$owner_query = $wpdb->prepare(
	"SELECT id, wp_user_id, owner_email, owner_name FROM {$wpdb->prefix}af_owner_contacts
	 WHERE owner_email LIKE %s OR subject LIKE %s
	 ORDER BY id DESC LIMIT 5",
	'%walter%',
	'%walter%'
);

echo "Step 1: Finding owner 'prueba archivos Walter'\n";
$owner_contacts = $wpdb->get_results( $owner_query );

if ( empty( $owner_contacts ) ) {
	echo "❌ No owner contacts found matching 'walter'. Try searching by exact email.\n";
	return;
}

foreach ( $owner_contacts as $contact ) {
	echo "  Found: ID={$contact->id}, User ID={$contact->wp_user_id}, Email={$contact->owner_email}, Name={$contact->owner_name}\n";
}

// Use first result
$owner_id = absint( $owner_contacts[0]->wp_user_id );
echo "  → Using owner user_id=$owner_id\n\n";

// Step 2: Find accommodation "La Pradera #09"
echo "Step 2: Finding accommodation 'La Pradera #09'\n";
$accom_query = $wpdb->prepare(
	"SELECT ID, post_title, post_author FROM {$wpdb->posts}
	 WHERE post_type = 'accommodation' AND post_title LIKE %s AND post_author = %d
	 ORDER BY ID DESC LIMIT 1",
	'%Pradera%',
	$owner_id
);

$accommodation = $wpdb->get_row( $accom_query );

if ( ! $accommodation ) {
	echo "❌ No accommodation 'La Pradera' found for owner.\n";
	return;
}

echo "  Found: ID={$accommodation->ID}, Title={$accommodation->post_title}\n";
$accommodation_id = (int) $accommodation->ID;

// Step 3: Find owner's contract template
echo "Step 3: Finding owner's contract template\n";

$template_query = $wpdb->prepare(
	"SELECT ID, post_title, post_mime_type FROM {$wpdb->posts} p
	 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
	 WHERE post_type = 'attachment'
	 AND post_author = %d
	 AND pm.meta_key = '_af_owner_contract_example'
	 AND pm.meta_value = '1'
	 ORDER BY p.ID DESC LIMIT 1",
	$owner_id
);

$template = $wpdb->get_row( $template_query );

if ( ! $template ) {
	echo "❌ No owner contract template found\n";
	return;
}

echo "  Found: ID={$template->ID}, Title={$template->post_title}, Mime={$template->post_mime_type}\n";
$template_id = (int) $template->ID;

// Step 4: Check if template is cached/processed
echo "Step 4: Checking processed template cache\n";

$processed_path = get_post_meta( $template_id, '_af_processed_template_path', true );
if ( $processed_path ) {
	echo "  ✓ Cached path found: $processed_path\n";
	if ( file_exists( $processed_path ) ) {
		echo "  ✓ Cached file EXISTS\n";
		$size = filesize( $processed_path );
		echo "  ✓ File size: $size bytes\n";

		// Check if it has placeholders
		$zip = new ZipArchive();
		if ( $zip->open( $processed_path ) ) {
			$xml = $zip->getFromName( 'word/document.xml' );
			$zip->close();

			if ( $xml ) {
				$placeholder_count = substr_count( $xml, '${' );
				echo "  ✓ Found $placeholder_count placeholders (\${ ) in document\n";

				if ( $placeholder_count > 0 ) {
					// Show first few placeholders
					preg_match_all( '/\$\{([A-Z_]+)\}/', $xml, $matches );
					$unique_phs = array_unique( $matches[1] );
					echo "  ✓ Placeholder list: " . implode( ', ', array_slice( $unique_phs, 0, 10 ) ) . "\n";
				}
			} else {
				echo "  ❌ document.xml not found in cached DOCX\n";
			}
		} else {
			echo "  ❌ Cannot open cached DOCX as ZIP\n";
		}
	} else {
		echo "  ❌ Cached file NOT FOUND at path\n";
	}
} else {
	echo "  ⚠ No cached processed path found; template will be reprocessed on next lease\n";
}

// Step 5: Get original template file info
echo "Step 5: Checking original template file\n";

$original_path = get_attached_file( $template_id );
if ( $original_path && file_exists( $original_path ) ) {
	echo "  ✓ Original path exists: $original_path\n";
	$size = filesize( $original_path );
	echo "  ✓ File size: $size bytes\n";

	// Check for blanks in original
	$zip = new ZipArchive();
	if ( $zip->open( $original_path ) ) {
		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();

		if ( $xml ) {
			$underscore_count = substr_count( $xml, '___' );
			$dots_count = substr_count( $xml, '...' );
			$ellipsis_count = substr_count( $xml, '…' );
			echo "  ✓ Original blanks detected: underscores=$underscore_count, dots=$dots_count, ellipsis=$ellipsis_count\n";

			if ( ( $underscore_count + $dots_count + $ellipsis_count ) === 0 ) {
				echo "  ❌ NO BLANKS FOUND in original template!\n";
				echo "     This means process_owner_template() will not inject any placeholders.\n";
				echo "     Check if template has blanks like: ________ or ........ or …\n";
			}
		}
	}
} else {
	echo "  ❌ Original template file not found\n";
}

// Step 6: Check recent leases
echo "Step 6: Checking recent leases for this accommodation\n";

$lease_query = $wpdb->prepare(
	"SELECT ID, accommodation_id, guest_id, status FROM {$wpdb->prefix}af_leases
	 WHERE accommodation_id = %d
	 ORDER BY ID DESC LIMIT 3",
	$accommodation_id
);

$leases = $wpdb->get_results( $lease_query );

if ( empty( $leases ) ) {
	echo "  No leases found for this accommodation\n";
} else {
	foreach ( $leases as $lease ) {
		echo "  Lease ID={$lease->ID}, Guest ID={$lease->guest_id}, Status={$lease->status}\n";
	}
}

echo "\n=== Diagnostic Summary ===\n";
echo "Owner: User ID=$owner_id\n";
echo "Accommodation: ID=$accommodation_id (La Pradera #09)\n";
echo "Template: ID=$template_id\n";
echo "Processed Path: " . ( $processed_path ? 'SET' : 'NOT SET' ) . "\n";
echo "\n=== Next Steps ===\n";
echo "1. Check if template file has actual blanks (_____ or ....... or …)\n";
echo "2. Check error_log for 'Arriendo Facil process_owner_template' messages\n";
echo "3. Check error_log for 'fill_template' messages to see vars_set count\n";
echo "4. If vars_set is low, check if build_placeholder_values() is getting data\n";
?>
