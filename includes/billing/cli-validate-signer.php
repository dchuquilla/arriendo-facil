#!/usr/bin/env php
<?php
/**
 * CLI validation tool for Arriendo_Facil_SRI_Signer.
 *
 * Usage:
 *   php cli-validate-signer.php /path/to/cert.pem [/path/to/unsigned.xml]
 *
 * @package Arriendo_Facil\Billing
 */

if ( php_sapi_name() !== 'cli' ) {
	die( "This script must be run from the command line.\n" );
}

if ( $argc < 2 ) {
	echo "Usage: php cli-validate-signer.php /path/to/cert.pem [/path/to/unsigned.xml]\n";
	echo "\nThis tool validates:\n";
	echo "  1. Certificate parsing (serial, issuer DN, validity)\n";
	echo "  2. Private key availability (if provided)\n";
	echo "  3. Signing capability (if unsigned XML is provided)\n";
	echo "  4. Signature structure validation\n";
	exit( 1 );
}

$cert_file = $argv[1];
$xml_file  = isset( $argv[2] ) ? $argv[2] : null;
$pkey_file = null;

// Try to find private key with common naming conventions.
foreach ( array( '_key.pem', '.key.pem', '-key.pem' ) as $suffix ) {
	$guess = str_replace( '.pem', $suffix, $cert_file );
	if ( file_exists( $guess ) ) {
		$pkey_file = $guess;
		break;
	}
}

// ─────────────────────────────────────────────────────────────────────────────

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════╗\n";
echo "║           SRI XAdES-BES Signer Validation Tool                         ║\n";
echo "╚════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// ─── Check files ────────────────────────────────────────────────────────────

echo "[1/4] Checking files...\n";

if ( ! file_exists( $cert_file ) ) {
	echo "  ❌ Certificate file not found: $cert_file\n";
	exit( 1 );
}
echo "  ✓ Certificate: $cert_file\n";

if ( $xml_file && ! file_exists( $xml_file ) ) {
	echo "  ❌ XML file not found: $xml_file\n";
	exit( 1 );
}

if ( $xml_file ) {
	echo "  ✓ XML: $xml_file\n";
}

if ( $pkey_file ) {
	echo "  ✓ Private key guessed: $pkey_file\n";
} else {
	echo "  ⚠ Private key not found (needed for signing test)\n";
}

// ─── Load class ─────────────────────────────────────────────────────────────

echo "\n[2/4] Loading Arriendo_Facil_SRI_Signer...\n";

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
}

$signer_file = __DIR__ . '/class-sri-signer.php';
if ( ! file_exists( $signer_file ) ) {
	echo "  ❌ Signer class not found: $signer_file\n";
	exit( 1 );
}

require_once $signer_file;

if ( ! class_exists( 'Arriendo_Facil_SRI_Signer' ) ) {
	echo "  ❌ Failed to load Arriendo_Facil_SRI_Signer class\n";
	exit( 1 );
}
echo "  ✓ Class loaded\n";

// ─── Validate certificate ───────────────────────────────────────────────────

echo "\n[3/4] Validating certificate...\n";

$cert_pem = file_get_contents( $cert_file );

try {
	$info = Arriendo_Facil_SRI_Signer::cert_info_detailed( $cert_pem );

	echo "  Certificate details:\n";

	// Serial number
	$serial = $info['serial'];
	$has_sci = ( strpos( $serial, 'e' ) !== false || strpos( $serial, 'E' ) !== false );
	if ( $has_sci ) {
		echo "    ❌ Serial (decimal): $serial [⚠ SCIENTIFIC NOTATION!]\n";
	} else {
		echo "    ✓ Serial (decimal): " . substr( $serial, 0, 30 ) . ( strlen( $serial ) > 30 ? '...' : '' ) . "\n";
	}

	echo "    ✓ Serial (hex): " . substr( $info['serial_hex'], 0, 20 ) . "...\n";

	// Issuer DN
	$dn = $info['issuer_dn'];
	$has_space_after_comma = ( strpos( $dn, ', ' ) !== false );
	if ( $has_space_after_comma ) {
		echo "    ❌ Issuer DN: $dn [⚠ SPACES AFTER COMMAS!]\n";
	} else {
		echo "    ✓ Issuer DN (RFC 2253): " . substr( $dn, 0, 60 ) . "...\n";
	}

	// Validity
	$now = time();
	$valid_from = $info['valid_from'];
	$valid_to = $info['valid_to'];

	if ( $now < $valid_from ) {
		echo "    ❌ Not yet valid (starts: " . date( 'Y-m-d H:i:s', $valid_from ) . ")\n";
	} elseif ( $now > $valid_to ) {
		echo "    ❌ Expired (ended: " . date( 'Y-m-d H:i:s', $valid_to ) . ")\n";
	} else {
		echo "    ✓ Valid until: " . date( 'Y-m-d H:i:s', $valid_to ) . "\n";
	}

	// Key purposes
	$has_digital_sig = false;
	foreach ( $info['purposes'] as $purpose => $details ) {
		if ( strpos( strtolower( $purpose ), 'digital' ) !== false || strpos( strtolower( $purpose ), 'signature' ) !== false ) {
			$has_digital_sig = true;
		}
	}
	if ( ! $has_digital_sig ) {
		echo "    ⚠ No digital signature purpose found\n";
	} else {
		echo "    ✓ Digital signature capability present\n";
	}

	echo "\n  ✓ Certificate validation passed\n";

} catch ( RuntimeException $e ) {
	echo "  ❌ Certificate validation failed: " . $e->getMessage() . "\n";
	exit( 1 );
}

// ─── Test signing (if XML and key provided) ─────────────────────────────────

echo "\n[4/4] Testing signature generation...\n";

if ( ! $xml_file ) {
	echo "  ⊘ Skipped (no XML file provided)\n";
	goto skip_signing;
}

if ( ! $pkey_file ) {
	echo "  ⊘ Skipped (no private key found)\n";
	goto skip_signing;
}

try {
	$pkey_pem = file_get_contents( $pkey_file );
	$xml_unsigned = file_get_contents( $xml_file );

	echo "  Attempting to sign XML...\n";
	$signer = new Arriendo_Facil_SRI_Signer( $cert_pem, $pkey_pem );
	$signed_xml = $signer->sign( $xml_unsigned );

	echo "  ✓ Signing successful (output: " . strlen( $signed_xml ) . " bytes)\n";

	// Validate structure
	echo "  Validating signature structure...\n";
	Arriendo_Facil_SRI_Signer::validate_signature_structure( $signed_xml );
	echo "  ✓ Structure validation passed\n";

	// Save signed XML for inspection
	$out_file = dirname( $xml_file ) . '/' . basename( $xml_file, '.xml' ) . '.signed.xml';
	file_put_contents( $out_file, $signed_xml );
	echo "  ✓ Signed XML saved: $out_file\n";

} catch ( RuntimeException $e ) {
	echo "  ❌ Signing failed: " . $e->getMessage() . "\n";
	exit( 1 );
}

skip_signing:

// ─── Final summary ──────────────────────────────────────────────────────────

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════╗\n";
echo "║                        ✓ ALL TESTS PASSED                             ║\n";
echo "╚════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo "Next steps:\n";
echo "  1. Review the certificate and issuer DN above\n";
echo "  2. If issuer DN has warnings, try reversing with:\n";
echo "     Arriendo_Facil_SRI_Signer::compute_issuer_dn( \$cert_pem, true )\n";
echo "  3. Load intermediate CA chain (if needed):\n";
echo "     \$signer = new Arriendo_Facil_SRI_Signer( \$cert_pem, \$pkey_pem, \$chain_pem );\n";
echo "  4. Submit signed XML to SRI web service\n";
echo "\n";

exit( 0 );
