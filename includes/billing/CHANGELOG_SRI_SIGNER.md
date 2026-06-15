# Changelog: Arriendo_Facil_SRI_Signer v1.0 → v1.1

Enhanced version with strict compliance to SRI Ecuador XAdES-BES requirements, eliminating common "FIRMA INVALIDA" and "Error 39" errors.

---

## Version 1.1 (Current) — 2026-06-15

### Breaking Changes
None. Fully backwards-compatible with existing code.

### New Features

#### 1. **Improved Certificate Chain Handling**
- **Before**: Each CA certificate was placed in a separate `<ds:X509Data>` block
  ```xml
  <ds:X509Data>
    <ds:X509Certificate>User</ds:X509Certificate>
  </ds:X509Data>
  <ds:X509Data>
    <ds:X509Certificate>Intermediate</ds:X509Certificate>
  </ds:X509Data>
  ```
- **After**: All certificates in the same block (Error 39 prevention)
  ```xml
  <ds:X509Data>
    <ds:X509Certificate>User</ds:X509Certificate>
    <ds:X509Certificate>Intermediate</ds:X509Certificate>
  </ds:X509Data>
  ```
- **Benefit**: Prevents SRI Error 39 (certificate chain validation failure)

#### 2. **RFC 2253 Strict DN Formatting**
- **Before**: `implode( ', ', $parts )` → spaces after commas
  ```
  CN=Alice, OU=IT, O=ACME, C=US  ❌ (FIRMA INVALIDA)
  ```
- **After**: `implode( ',', $parts )` → no spaces
  ```
  CN=Alice,OU=IT,O=ACME,C=US  ✓ (Valid)
  ```
- **Benefit**: Eliminates issuer DN formatting rejections

#### 3. **Issuer DN Reversal Option**
New method `reverse_issuer_dn()` to switch between DN ordering:
```php
// Default order (most-specific first)
$dn = Arriendo_Facil_SRI_Signer::compute_issuer_dn( $cert_pem, false );
// Result: CN=Alice,OU=IT,O=ACME,C=US

// X.500 order (least-specific first) — use if SRI validation fails
$dn_alt = Arriendo_Facil_SRI_Signer::compute_issuer_dn( $cert_pem, true );
// Result: C=US,O=ACME,OU=IT,CN=Alice
```
- **Benefit**: Quick workaround for "IssuerName mismatch" errors

#### 4. **Enhanced Serial Number Handling**
Improved `cert_serial()` with better detection:
- **Step 1**: Extract `serialNumber` directly (most reliable)
- **Step 2**: Use GMP if available (arbitrary precision)
- **Step 3**: Fall back to BCMath (slower, reliable)
- **Step 4**: Use hexdec (safe for small numbers only)

Prevents scientific notation overflow for Uanataca and other large serials:
```php
// Before: 1.65E+30 (scientific notation) ❌
// After:  165000000000000000000000000000 (decimal string) ✓
```

#### 5. **New Diagnostic Methods**

##### `cert_info_detailed( string $cert_pem ): array`
Returns comprehensive certificate metadata:
- Subject, Issuer, Serial (decimal & hex)
- Validity period, Key purposes
- RFC 2253 formatted issuer DN

##### `validate_signature_structure( string $signed_xml ): bool`
Pre-submission sanity check:
- Verifies presence of required Signature elements
- Checks DigestValues are populated
- Validates timestamp format

#### 6. **Enhanced Documentation**
- **SRI_SIGNING_TECHNICAL_NOTES.md**: Deep dive into design decisions
- **SRI_SIGNER_README.md**: Quick-start guide with examples
- **class-sri-signer-examples.php**: 6 practical usage patterns
- **cli-validate-signer.php**: CLI validation tool

### Improvements

#### Better Error Messages
- Clear exception messages indicating root cause
- Suggestions for resolution

#### Comprehensive Comments
- Marked critical code sections with "CRITICAL" comments
- RFC references for standard compliance
- Timezone documentation

#### Code Organization
- Grouped certificate helpers together
- Separated diagnostics into dedicated section
- Clear namespace/constant declarations

### Bug Fixes

1. **DN Formatting (RFC 2253)**
   - Removed spaces after commas in issuer DN
   - Now strictly compliant with standard

2. **Serial Number Overflow**
   - Robust extraction avoiding PHP integer limits
   - Handles Uanataca's 128-bit serials correctly

3. **Chain Injection Location**
   - Moved from separate blocks to unified X509Data
   - Prevents Error 39 chain validation issues

### Dependencies

**New**:
- None (uses existing openssl, DOMDocument)

**Recommended** (for production):
- `gmp` PHP extension (if available, used for serial number conversion)
- `bcmath` PHP extension (fallback for serial numbers)

### Migration Guide

No changes required for existing code. The class is fully backwards-compatible.

**Optional improvements**:

```php
// Before: Simple signing
$signer = new Arriendo_Facil_SRI_Signer( $cert_pem, $pkey_pem );

// After: With diagnostics (recommended)
$info = Arriendo_Facil_SRI_Signer::cert_info_detailed( $cert_pem );
if ( strpos( $info['serial'], 'e' ) !== false ) {
    throw new Exception( 'Serial overflow detected' );
}

// Try reversed DN if validation fails
$dn_alt = Arriendo_Facil_SRI_Signer::compute_issuer_dn( $cert_pem, true );
```

### Testing Notes

**Tested certificate authorities**:
- ✅ Uanataca (Spain) — complex DN, large serials
- ✅ ANF (Spain) — standard RSA
- ✅ Banco Central (Ecuador) — government issued
- ✅ Security Data (Latin America) — regional

**Known limitations**:
- Only RSA keys supported (ECDSA not implemented)
- Signature format is enveloped (not detached)
- Requires Python/Java validator for full verification

---

## Version 1.0 (Previous)

Initial implementation with:
- Basic XAdES-BES signing
- openssl integration
- Double-parse C14N safety
- Namespace handling

---

## Planned Future Improvements

- [ ] ECDSA signature support
- [ ] Detached signature mode
- [ ] AIA chain auto-fetch
- [ ] HSM/PKCS#11 support
- [ ] Async signing via batch API

---

## Files Modified/Added

### Modified
- `class-sri-signer.php` — Enhanced with v1.1 features

### Added
- `SRI_SIGNING_TECHNICAL_NOTES.md` — Technical reference
- `SRI_SIGNER_README.md` — Quick-start guide
- `class-sri-signer-examples.php` — Usage examples
- `cli-validate-signer.php` — Validation tool
- `CHANGELOG_SRI_SIGNER.md` — This file

---

## Support & Troubleshooting

### Common Issues

| Issue | Resolution |
|-------|-----------|
| FIRMA INVALIDA | Run `cert_info_detailed()` → check DN & serial |
| Error 39 | Verify chain is loaded & in correct order |
| IssuerName mismatch | Try `compute_issuer_dn(..., true)` for reversed order |
| Timestamp errors | Ensure `date.timezone = 'America/Guayaquil'` |

### CLI Validation

```bash
php cli-validate-signer.php /path/to/cert.pem [/path/to/unsigned.xml]
```

### Contact

For issues or questions, refer to:
- `SRI_SIGNING_TECHNICAL_NOTES.md` for cryptography details
- `SRI_SIGNER_README.md` for API reference
- `class-sri-signer-examples.php` for usage patterns

---

**Version**: 1.1  
**Release Date**: 2026-06-15  
**Compatibility**: PHP 7.4+, SRI Ecuador XAdES-BES specification
