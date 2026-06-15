# Arriendo_Facil_SRI_Signer — Quick Start Guide

Complete XAdES-BES XML signing solution for SRI Ecuador electronic invoices, with strict compliance to SRI validation requirements and protection against common "FIRMA INVALIDA" and "Error 39" errors.

---

## Installation & Usage

### Basic Signing

```php
use Arriendo_Facil_Billing;

// Load certificate and private key
$cert_pem  = file_get_contents( '/path/to/cert.pem' );
$pkey_pem  = file_get_contents( '/path/to/private-key.pem' );
$xml_unsigned = '<?xml version="1.0" encoding="UTF-8"?><comprobante id="comprobante">...</comprobante>';

// Sign
$signer = new Arriendo_Facil_SRI_Signer( $cert_pem, $pkey_pem );
$signed_xml = $signer->sign( $xml_unsigned );

// Save and submit
file_put_contents( 'invoice-signed.xml', $signed_xml );
$response = $soap_client->receive( $signed_xml );
```

### Signing with CA Chain (Recommended for Production)

Modern CAs (Uanataca, ANF, Banco Central, Security Data) require intermediate certificates:

```php
// Load chain (concatenated PEM blocks)
$chain_pem = file_get_contents( '/path/to/chain.pem' );
// Example chain.pem:
//   -----BEGIN CERTIFICATE-----
//   [Intermediate CA]
//   -----END CERTIFICATE-----
//   -----BEGIN CERTIFICATE-----
//   [Root CA]
//   -----END CERTIFICATE-----

// Sign with chain
$signer = new Arriendo_Facil_SRI_Signer( $cert_pem, $pkey_pem, $chain_pem );
$signed_xml = $signer->sign( $xml_unsigned );
```

---

## Diagnostics

### Certificate Inspection

Inspect certificate metadata (serial, issuer DN, validity, purposes):

```php
$info = Arriendo_Facil_SRI_Signer::cert_info_detailed( $cert_pem );

echo "Serial: " . $info['serial'] . "\n";
echo "Issuer DN: " . $info['issuer_dn'] . "\n";
echo "Valid until: " . date( 'Y-m-d', $info['valid_to'] ) . "\n";

// Check for issues
if ( strpos( $info['serial'], 'e' ) !== false ) {
    die( "ERROR: Serial number in scientific notation (Uanataca?)!\n" );
}

if ( strpos( $info['issuer_dn'], ', ' ) !== false ) {
    die( "ERROR: Issuer DN has spaces after commas!\n" );
}
```

### Issuer DN Debugging

If SRI returns "IssuerName mismatch", try alternative DN orderings:

```php
// Default order (most-specific → least-specific)
$dn_forward = Arriendo_Facil_SRI_Signer::compute_issuer_dn( $cert_pem, false );
echo "Forward: " . $dn_forward . "\n";

// Reversed order (least-specific → most-specific)
$dn_reversed = Arriendo_Facil_SRI_Signer::compute_issuer_dn( $cert_pem, true );
echo "Reversed: " . $dn_reversed . "\n";

// If reversed works, update your signer code to use reversed DN
```

### Signature Structure Validation

Before submitting to SRI, verify the signed XML has the required structure:

```php
try {
    Arriendo_Facil_SRI_Signer::validate_signature_structure( $signed_xml );
    echo "✓ Signature structure is valid\n";
} catch ( RuntimeException $e ) {
    echo "✗ Signature validation failed: " . $e->getMessage() . "\n";
}
```

---

## CLI Validation Tool

For quick certificate checks without PHP code:

```bash
php cli-validate-signer.php /path/to/cert.pem [/path/to/unsigned.xml]
```

Output:
```
╔════════════════════════════════════════════════════════════════════════╗
║           SRI XAdES-BES Signer Validation Tool                         ║
╚════════════════════════════════════════════════════════════════════════╝

[1/4] Checking files...
  ✓ Certificate: /path/to/cert.pem
  ✓ Private key guessed: /path/to/cert-key.pem

[2/4] Loading Arriendo_Facil_SRI_Signer...
  ✓ Class loaded

[3/4] Validating certificate...
  Certificate details:
    ✓ Serial (decimal): 168644527923...
    ✓ Serial (hex): 00A8C0D9F8E7...
    ✓ Issuer DN (RFC 2253): CN=UANATACA...,C=ES
    ✓ Valid until: 2026-12-31 23:59:59
    ✓ Digital signature capability present

  ✓ Certificate validation passed

[4/4] Testing signature generation...
  Attempting to sign XML...
  ✓ Signing successful (output: 8234 bytes)
  ✓ Structure validation passed
  ✓ Signed XML saved: /path/to/unsigned.signed.xml

✓ ALL TESTS PASSED
```

---

## Complete Integration Example

```php
<?php

class Invoice_Signer {

    private $cert_pem;
    private $pkey_pem;
    private $chain_pem;

    public function __construct( $cert_file, $pkey_file, $chain_file = null ) {
        $this->cert_pem = file_get_contents( $cert_file );
        $this->pkey_pem = file_get_contents( $pkey_file );
        $this->chain_pem = $chain_file ? file_get_contents( $chain_file ) : '';

        // Validate certificate
        try {
            $info = Arriendo_Facil_SRI_Signer::cert_info_detailed( $this->cert_pem );

            if ( strpos( $info['serial'], 'e' ) !== false ) {
                throw new Exception( 'Serial number overflow detected' );
            }

            if ( $info['valid_to'] < time() ) {
                throw new Exception( 'Certificate expired' );
            }
        } catch ( Exception $e ) {
            throw new Exception( "Invalid certificate: {$e->getMessage()}" );
        }
    }

    public function sign_invoice( $unsigned_xml ) {
        try {
            $signer = new Arriendo_Facil_SRI_Signer(
                $this->cert_pem,
                $this->pkey_pem,
                $this->chain_pem
            );

            $signed_xml = $signer->sign( $unsigned_xml );

            // Validate structure
            Arriendo_Facil_SRI_Signer::validate_signature_structure( $signed_xml );

            return $signed_xml;

        } catch ( RuntimeException $e ) {
            throw new Exception( "Signing failed: {$e->getMessage()}" );
        }
    }

    public function get_issuer_dn() {
        return Arriendo_Facil_SRI_Signer::compute_issuer_dn( $this->cert_pem );
    }
}

// Usage
try {
    $signer = new Invoice_Signer(
        '/path/to/cert.pem',
        '/path/to/pkey.pem',
        '/path/to/chain.pem'
    );

    $unsigned_xml = generate_invoice_xml( $invoice_data );
    $signed_xml = $signer->sign_invoice( $unsigned_xml );

    // Submit to SRI
    $soap_client = new SRI_SOAP_Client( ... );
    $response = $soap_client->receive( $signed_xml );

    if ( $response['status'] === 'ACEPTADA' ) {
        save_signed_invoice( $signed_xml );
    }

} catch ( Exception $e ) {
    log_error( $e->getMessage() );
}
```

---

## Common Errors & Solutions

### "FIRMA INVALIDA"

**Symptoms**: SRI rejects signature as invalid.

**Causes** (in order of likelihood):
1. **Issuer DN format** — Spaces after commas, wrong order
2. **Serial number overflow** — Scientific notation (Uanataca issue)
3. **Missing or wrong timezone** — Not Ecuador time
4. **Canonicalization mismatch** — Class uses correct inclusive C14N

**Solutions**:
```php
// 1. Check certificate
$info = Arriendo_Facil_SRI_Signer::cert_info_detailed( $cert_pem );
echo "DN: " . $info['issuer_dn'] . "\n";
echo "Serial: " . $info['serial'] . "\n";

// 2. Try reversed DN order
$dn_reversed = Arriendo_Facil_SRI_Signer::compute_issuer_dn( $cert_pem, true );
echo "Try reversed: " . $dn_reversed . "\n";

// 3. Verify timezone
date_default_timezone_set( 'America/Guayaquil' );

// 4. Check signed XML structure
Arriendo_Facil_SRI_Signer::validate_signature_structure( $signed_xml );
```

### Error 39 (Certificate Chain Validation)

**Symptoms**: SRI returns "El certificado no es válido" with Error 39.

**Cause**: Missing or incomplete certificate chain.

**Solution**:
```php
// Verify intermediate certificates are included
$chain_pem = file_get_contents( '/path/to/chain.pem' );
if ( trim( $chain_pem ) === '' ) {
    die( "ERROR: Chain is empty. Fetch intermediates via AIA.\n" );
}

// Load chain
$signer = new Arriendo_Facil_SRI_Signer( $cert_pem, $pkey_pem, $chain_pem );
```

### Timezone Issues

**Symptom**: Signature time is in UTC instead of Ecuador time.

**Cause**: Server timezone not set or session-level override missing.

**Solution**:
```php
// Ensure America/Guayaquil is set globally
date_default_timezone_set( 'America/Guayaquil' );

// Or per-operation via PHP 8.0+
ini_set( 'date.timezone', 'America/Guayaquil' );
```

---

## API Reference

### `Arriendo_Facil_SRI_Signer`

#### Constructor
```php
public function __construct( string $cert_pem, string $pkey_pem, string $chain_pem = '' )
```
- `$cert_pem`: PEM-encoded X.509 certificate
- `$pkey_pem`: PEM-encoded private key (RSA)
- `$chain_pem`: Concatenated PEM-encoded CA chain (optional, but recommended)

#### `sign( string $xml_unsigned ): string`
Signs an unsigned XML document with XAdES-BES.

**Throws**: `RuntimeException` on certificate, key, or signing errors

**Returns**: Signed XML with appended `<ds:Signature>` element

#### Static Methods

##### `cert_info_detailed( string $cert_pem ): array`
Returns certificate metadata:
```php
[
    'subject'     => [...],           // CN, O, OU, etc.
    'issuer'      => [...],           // Parsed issuer components
    'issuer_dn'   => 'CN=...,C=...',  // RFC 2253 formatted DN
    'serial'      => '123456789',     // Decimal string (safe from overflow)
    'serial_hex'  => '0x75B9...',     // Hex representation
    'valid_from'  => 1234567890,      // Unix timestamp
    'valid_to'    => 1234567890,      // Unix timestamp
    'purposes'    => [...]            // digitalSignature, etc.
]
```

##### `compute_issuer_dn( string $cert_pem, bool $reverse = false ): string`
Returns issuer DN in RFC 2253 format.
- `$reverse = false`: Most-specific first (default)
- `$reverse = true`: Least-specific first (X.500 order, alternative if validation fails)

##### `validate_signature_structure( string $signed_xml ): bool`
Validates that the signed XML contains required Signature elements.

**Throws**: `RuntimeException` on structure validation failure

**Returns**: `true` if structure is valid

---

## Technical Details

See **SRI_SIGNING_TECHNICAL_NOTES.md** for:
- Serial number overflow prevention
- RFC 2253 DN formatting
- Certificate chain handling
- XML canonicalization quirks
- Timezone requirements
- Complete troubleshooting guide

---

## Requirements

- **PHP**: 7.4+ (tested on 8.0+)
- **Extensions**:
  - `openssl` (required)
  - `gmp` or `bcmath` (recommended for large serial numbers)
- **Certificates**: RSA 2048-bit or higher, valid X.509 format

## Supported CAs

✅ Uanataca (Spain)  
✅ ANF (Spain)  
✅ Banco Central (Ecuador)  
✅ Security Data (Latin America)  
✅ Other standard X.509 CAs

## License

Part of Arriendo Fácil billing module.

---

**Last Updated**: 2026-06-15  
**Version**: 1.0 (XAdES-BES, SRI Ecuador Compliant)
