<?php
/**
 * Usage examples and test cases for Arriendo_Facil_SRI_Signer.
 *
 * @package Arriendo_Facil\Billing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Example 1: Basic signing with certificate and private key (no chain).
 */
function example_basic_sign() {
	// Assume $cert_pem and $pkey_pem are loaded from storage.
	$cert_pem  = file_get_contents( '/path/to/cert.pem' );
	$pkey_pem  = file_get_contents( '/path/to/private-key.pem' );
	$xml_unsigned = file_get_contents( '/path/to/unsigned.xml' );

	try {
		$signer = new Arriendo_Facil_SRI_Signer( $cert_pem, $pkey_pem );
		$signed_xml = $signer->sign( $xml_unsigned );
		file_put_contents( '/path/to/signed.xml', $signed_xml );
		echo "✓ Signed successfully.\n";
	} catch ( RuntimeException $e ) {
		echo "✗ Signing failed: " . $e->getMessage() . "\n";
	}
}

/**
 * Example 2: Signing with CA chain (intermediate certificates).
 *
 * Chain loading strategies:
 * a) Manual: Concatenate PEM certs into a single string.
 * b) Automatic from AIA: Fetch intermediates via HTTP (not shown here).
 */
function example_sign_with_chain() {
	$cert_pem      = file_get_contents( '/path/to/cert.pem' );
	$pkey_pem      = file_get_contents( '/path/to/private-key.pem' );

	// Load intermediate(s). For Uanataca / ANF, you may need to fetch from AIA.
	// Example structure:
	//   -----BEGIN CERTIFICATE-----
	//   [Intermediate CA Base64]
	//   -----END CERTIFICATE-----
	//   -----BEGIN CERTIFICATE-----
	//   [Root CA Base64]
	//   -----END CERTIFICATE-----
	$chain_pem = file_get_contents( '/path/to/chain.pem' );

	$xml_unsigned = file_get_contents( '/path/to/unsigned.xml' );

	try {
		$signer = new Arriendo_Facil_SRI_Signer( $cert_pem, $pkey_pem, $chain_pem );
		$signed_xml = $signer->sign( $xml_unsigned );
		echo "✓ Signed with chain successfully.\n";
	} catch ( RuntimeException $e ) {
		echo "✗ Error: " . $e->getMessage() . "\n";
	}
}

/**
 * Example 3: Debugging certificate details (Uanataca, ANF, etc.).
 *
 * Use this if you encounter:
 * - "IssuerName mismatch" errors
 * - "Serial number" validation failures
 * - Timezone issues
 */
function example_diagnose_certificate() {
	$cert_pem = file_get_contents( '/path/to/cert.pem' );

	try {
		// Extract and print all certificate metadata.
		$info = Arriendo_Facil_SRI_Signer::cert_info_detailed( $cert_pem );

		echo "Certificate Analysis:\n";
		echo "───────────────────────\n";
		echo "Issuer DN (RFC 2253): " . $info['issuer_dn'] . "\n";
		echo "Serial (decimal): " . $info['serial'] . "\n";
		echo "Serial (hex): " . $info['serial_hex'] . "\n";

		$valid_from = new DateTime();
		$valid_from->setTimestamp( $info['valid_from'] );
		$valid_to = new DateTime();
		$valid_to->setTimestamp( $info['valid_to'] );

		echo "Valid from: " . $valid_from->format( 'Y-m-d H:i:s' ) . "\n";
		echo "Valid to: " . $valid_to->format( 'Y-m-d H:i:s' ) . "\n";

		echo "\nSubject (parsed):\n";
		foreach ( $info['subject'] as $k => $v ) {
			echo "  {$k}: {$v}\n";
		}

		echo "\nIssuer (parsed):\n";
		foreach ( $info['issuer'] as $k => $v ) {
			echo "  {$k}: {$v}\n";
		}

		echo "\nKey Purposes:\n";
		foreach ( $info['purposes'] as $purpose => $details ) {
			echo "  {$purpose}: " . ( $details['general'] ? 'General' : 'Restricted' ) . "\n";
		}
	} catch ( RuntimeException $e ) {
		echo "✗ Error: " . $e->getMessage() . "\n";
	}
}

/**
 * Example 4: Issuer DN debugging (fixing "FIRMA INVALIDA").
 *
 * If the SRI rejects your signature with "IssuerName mismatch", try:
 * 1. Default RFC 2253 order (most-specific → least-specific)
 * 2. Reversed X.500 order (least-specific → most-specific)
 */
function example_issuer_dn_debugging() {
	$cert_pem = file_get_contents( '/path/to/cert.pem' );

	echo "Issuer DN debugging:\n";
	echo "───────────────────\n";

	// Order 1: Default (most-specific first).
	$dn_forward = Arriendo_Facil_SRI_Signer::compute_issuer_dn( $cert_pem, false );
	echo "Default order: " . $dn_forward . "\n";

	// Order 2: Reversed (least-specific first).
	$dn_reversed = Arriendo_Facil_SRI_Signer::compute_issuer_dn( $cert_pem, true );
	echo "Reversed order: " . $dn_reversed . "\n";

	echo "\nIf validation fails, try updating your signer code to use the reversed order.\n";
}

/**
 * Example 5: Validating signed XML structure (pre-submission sanity check).
 *
 * Before submitting to SRI, verify that the XML has the required structure.
 */
function example_validate_signed_xml() {
	$signed_xml = file_get_contents( '/path/to/signed.xml' );

	try {
		Arriendo_Facil_SRI_Signer::validate_signature_structure( $signed_xml );
		echo "✓ Signature structure is valid.\n";
	} catch ( RuntimeException $e ) {
		echo "✗ Structure validation failed: " . $e->getMessage() . "\n";
	}
}

/**
 * Example 6: Integration with Arriendo_Facil_SRI_SOAP_Client.
 *
 * Typical workflow:
 * 1. Generate unsigned XML via Arriendo_Facil_SRI_XML_Factura
 * 2. Sign it via Arriendo_Facil_SRI_Signer
 * 3. Validate structure
 * 4. Submit via Arriendo_Facil_SRI_SOAP_Client
 */
function example_full_workflow() {
	// Step 1: Load credentials.
	$cert_pem = file_get_contents( '/path/to/cert.pem' );
	$pkey_pem = file_get_contents( '/path/to/private-key.pem' );
	$chain_pem = file_get_contents( '/path/to/chain.pem' );

	// Step 2: Generate unsigned XML (mocked here).
	$invoice_data = array(
		'ruc'          => '1106202601171',
		'environment'  => 1, // Test environment
		'tipo_comprobante' => '01', // Invoice
		'numero_comprobante' => '001001000000001',
		'monto_total'  => 120.00,
		// ... other fields
	);
	// $xml_unsigned = Arriendo_Facil_SRI_XML_Factura::generate( $invoice_data );

	// Step 3: Sign the XML.
	try {
		$signer = new Arriendo_Facil_SRI_Signer( $cert_pem, $pkey_pem, $chain_pem );
		$signed_xml = $signer->sign( $xml_unsigned );

		// Step 4: Validate structure.
		Arriendo_Facil_SRI_Signer::validate_signature_structure( $signed_xml );
		echo "✓ XML signed and validated.\n";

		// Step 5: Submit to SRI (via SOAP client).
		// $soap_client = new Arriendo_Facil_SRI_SOAP_Client( ... );
		// $response = $soap_client->receive( $signed_xml );
		// ...
	} catch ( RuntimeException $e ) {
		echo "✗ Error: " . $e->getMessage() . "\n";
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// TROUBLESHOOTING REFERENCE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Troubleshooting checklist for common signing errors:
 *
 * ERROR: "FIRMA INVALIDA"
 * ───────────────────────
 * Usually caused by:
 * 1. Incorrect issuer DN format:
 *    - Spaces after commas (CN=Alice, OU=IT vs CN=Alice,OU=IT)
 *    - Wrong DN order (use reversed order if default fails)
 * 2. Serial number overflow (very large Uanataca numbers)
 *    - Verify via example_diagnose_certificate()
 * 3. Wrong canonicalization (exclusive vs inclusive)
 *    - This class uses INCLUSIVE (correct)
 * 4. Namespace context mismatch (PHP DOMNode::C14N quirk)
 *    - This class uses double-parse (serialize → re-parse) fix
 *
 * FIX: Run example_diagnose_certificate() and example_issuer_dn_debugging()
 *
 * ERROR: Error 39 (Certificate chain validation)
 * ──────────────────────────────────────────────
 * Usually caused by:
 * 1. Missing intermediate certificates
 *    - Fetch from certificate AIA extension
 *    - Load into $chain_pem parameter
 * 2. Wrong chain order
 *    - Should be: User → Intermediate(s) → Root
 *    - This class injects in the order provided
 * 3. Chain in separate X509Data blocks (old approach)
 *    - This class now uses single X509Data block
 *
 * FIX: Verify chain is loaded and in correct order
 *
 * ERROR: Timezone issues (wrong time offset)
 * ───────────────────────────────────────────
 * The signing time MUST be in America/Guayaquil (UTC-5).
 * This class enforces it. Check server timezone is set correctly.
 *
 * ERROR: "El certificado firmante es inválido"
 * ──────────────────────────────────────────────
 * The X509IssuerSerial in <ds:KeyInfo> doesn't match SRI records.
 * Verify issuer DN and serial number via example_diagnose_certificate().
 *
 */
