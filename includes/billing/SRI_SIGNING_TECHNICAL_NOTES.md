# XAdES-BES Signing for SRI Ecuador — Technical Deep Dive

This document explains the cryptographic and XML-level requirements for the `Arriendo_Facil_SRI_Signer` class and why certain design decisions are **critical** to prevent "FIRMA INVALIDA" and "Error 39" rejections.

---

## 1. Serial Number Overflow (The Uanataca Problem)

### The Problem

Modern certificate authorities (Uanataca, ANF, Banco Central, Security Data) issue certificates with **128-bit serial numbers**. When you call:

```php
$info = openssl_x509_parse( $cert_pem );
$serial = hexdec( $info['serialNumberHex'] );  // ❌ WRONG
```

PHP's `hexdec()` function treats input as a 32-bit signed integer. Large hex strings cause **integer overflow** → the result becomes a **float in scientific notation**:

```
Actual serial (hex):  00A8C0D9F8E7D6C5B4A39827166554433
hexdec() output:      7.5E+30  (float, not integer!)
```

When serialized to `<ds:X509SerialNumber>`, this becomes:

```xml
<ds:X509SerialNumber>7.5E+30</ds:X509SerialNumber>
```

The SRI's Java validator rejects this immediately: **"FIRMA INVALIDA"**.

### The Solution

The `Arriendo_Facil_SRI_Signer::cert_serial()` method uses a **priority hierarchy**:

1. **Direct extraction**: Try to use `$cert_info['serialNumber']` directly (PHP already converts it for small numbers).
2. **GMP (GNU Multiple Precision)**: If available, use `gmp_init( '0x...' )` → `gmp_strval()` for arbitrary precision.
3. **BCMath (Binary Calculator)**: Fallback character-by-character hex-to-decimal conversion.
4. **Last resort**: Plain `hexdec()` (only safe for serial < 2^32).

Result: `<ds:X509SerialNumber>` is **always** a pure decimal string, never scientific notation.

---

## 2. Issuer DN Formatting (RFC 2253 Compliance)

### The Problem

The `<ds:X509IssuerName>` element must contain an RFC 2253–formatted Distinguished Name. The standard specifies:

- **Separator**: Comma (`,`) — no space after
- **Order**: Most-specific-first (CN → OU → O → C)
- **Escaping**: Special characters (`,`, `=`, `+`, `<`, `>`, `#`, `;`, `"`, leading/trailing spaces) must be backslash-escaped

**Incorrect formats**:
```
CN=UANATACA CA2 2021, OU=TSP-UANATACA, O=UANATACA S.A., C=ES     ❌ (spaces after commas)
CN=UANATACA CA2 2021;OU=TSP-UANATACA;O=UANATACA S.A.;C=ES       ❌ (semicolons)
C=ES,O=UANATACA S.A.,OU=TSP-UANATACA,CN=UANATACA CA2 2021       ❌ (wrong order)
```

**Correct format**:
```
CN=UANATACA CA2 2021,OU=TSP-UANATACA,O=UANATACA S.A.,C=ES       ✓
```

### The Solution

The `build_issuer_dn()` method:

1. Iterates over attributes in the correct order: `CN → UID → serialNumber → OU → O → L → ST → C`
2. Escapes special characters per RFC 2253 §2.4
3. Joins with **comma-only** (no spaces): `implode( ',', $parts )`

If SRI validation still fails with "IssuerName mismatch", use the optional `reverse_issuer_dn()` method to switch to X.500 order (least-specific-first):

```php
$dn_reversed = Arriendo_Facil_SRI_Signer::compute_issuer_dn( $cert_pem, true );
// Result: C=ES,O=UANATACA S.A.,OU=TSP-UANATACA,CN=UANATACA CA2 2021
```

---

## 3. Certificate Chain in Single X509Data Block

### The Problem (Error 39)

The SRI validator performs **full PKI chain validation**: signing cert → intermediate(s) → root. If any link is missing or invalid, you get **Error 39** ("El certificado no es válido").

Older approaches placed each certificate in a **separate** `<ds:X509Data>` block:

```xml
<ds:KeyInfo>
  <ds:X509Data>
    <ds:X509Certificate>MIIFv...QUI=</ds:X509Certificate>  <!-- User -->
    <ds:X509IssuerSerial>...</ds:X509IssuerSerial>
  </ds:X509Data>
  <ds:X509Data>
    <ds:X509Certificate>MIIFz...9kO=</ds:X509Certificate>  <!-- Intermediate -->
  </ds:X509Data>
  <ds:X509Data>
    <ds:X509Certificate>MIIFs...8Jq=</ds:X509Certificate>  <!-- Root -->
  </ds:X509Data>
</ds:KeyInfo>
```

This can confuse validators that expect a **single chain**.

### The Solution

This class now injects **all certificates in the same** `<ds:X509Data>` block:

```xml
<ds:KeyInfo>
  <ds:X509Data>
    <ds:X509Certificate>MIIFv...QUI=</ds:X509Certificate>  <!-- User -->
    <ds:X509IssuerSerial>...</ds:X509IssuerSerial>
    <ds:X509Certificate>MIIFz...9kO=</ds:X509Certificate>  <!-- Intermediate -->
    <ds:X509Certificate>MIIFs...8Jq=</ds:X509Certificate>  <!-- Root -->
  </ds:X509Data>
</ds:KeyInfo>
```

**Chain order matters**: User → Intermediate(s) → Root. The class injects in the order provided via the `$chain_pem` parameter.

---

## 4. XML Canonicalization & the PHP DOMNode::C14N Quirk

### The Problem

PHP's `DOMNode::C14N()` has a **documented quirk**: when you add namespace declarations via `setAttributeNS()`, the namespace may not be visible to `C14N()` on **descendant nodes**, even though `saveXML()` shows it correctly.

Example:

```php
$qp = $doc->createElementNS( 'http://uri.etsi.org/01903/v1.3.2#', 'etsi:QualifyingProperties' );
$qp->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:etsi', 'http://uri.etsi.org/01903/v1.3.2#' );
// ❌ When we call $qp->C14N(), the etsi: namespace may not be resolved for child nodes
```

This causes **digest mismatches** between the XML we compute and what the SRI's Java parser computes:

```
Our <ds:DigestValue>: pWKx...bA==  (based on our C14N)
SRI's <ds:DigestValue>: aB9T...XQ==  (based on their C14N)
→ FIRMA INVALIDA
```

### The Solution: Double-Parse (Serialize → Re-parse)

The `sign()` method uses a **three-phase approach**:

1. **Phase 1**: Compute `#comprobante` digest BEFORE inserting the Signature (enveloped-signature transform).
2. **Phase 2**: Insert the Signature skeleton into the original DOMDocument.
3. **Phase 3**: **Round-trip serialize & re-parse**: Call `saveXML()` to serialize the full document, then immediately `loadXML()` it into a fresh DOMDocument.

From this point forward, **all C14N operations use the fresh document**, whose namespace context is **identical** to what the SRI will parse from the received XML.

```php
// After phase 2
$xml_intermediate = $doc->saveXML();

// Phase 3: Fresh document
$doc2 = new DOMDocument( '1.0', 'UTF-8' );
$doc2->loadXML( $xml_intermediate );

// All subsequent digests computed on $doc2
$sp_digest = base64_encode( hash( 'sha256', $sp_node->C14N( false, false ), true ) );
```

---

## 5. Canonicalization Algorithm (Inclusive vs. Exclusive C14N)

### The Standard

This class uses **Inclusive C14N**:

```
http://www.w3.org/TR/2001/REC-xml-c14n-20010315
```

This is the **correct** choice for enveloped signatures. Exclusive C14N (`http://www.w3.org/2001/10/xml-exc-c14n#`) is used in detached signatures and causes **FIRMA INVALIDA** when applied to SRI documents.

The difference:

| Aspect | Inclusive C14N | Exclusive C14N |
|--------|---|---|
| Namespace declarations | All visible to parent | Only necessary ancestors |
| Comments | Removed | Removed |
| Use case | Enveloped signatures (SRI) | Detached signatures |

### Configuration in this class

```php
$root->C14N( false, false )
      ↓     ↓
      |     └─ Preserve comments? No
      └─────── Use exclusive canonicalization? No ✓ (Inclusive)
```

---

## 6. Timestamp Timezone (America/Guayaquil)

### The Requirement

The `<xades:SigningTime>` element must be in **Ecuador local time** (America/Guayaquil, UTC-5). Using server timezone (often UTC) produces:

```xml
<xades:SigningTime>2026-06-15T12:00:00+00:00</xades:SigningTime>  ❌ (UTC)
```

SRI rejects this. Must be:

```xml
<xades:SigningTime>2026-06-15T12:00:00-05:00</xades:SigningTime>  ✓ (Ecuador time)
```

### Implementation

```php
$signing_time = ( new DateTime( 'now', new DateTimeZone( 'America/Guayaquil' ) ) )
    ->format( 'Y-m-d\TH:i:sP' );
// Result: 2026-06-15T12:00:00-05:00
```

The `P` format code outputs `±HH:MM` (not `±HHMM`), which is the correct ISO 8601 format for XML.

---

## 7. Certificate Validation

### Minimum requirements:

- **Key usage**: `digitalSignature` (must have)
- **Extended key usage**: `id-kp-emailProtection` or generic `serverAuth` (check issuer specifics)
- **Validity period**: Must be within `validFrom_time_t` → `validTo_time_t`
- **Chain**: Must be complete (intermediate → root must be present or downloadable via AIA)

### Diagnostic method:

```php
$info = Arriendo_Facil_SRI_Signer::cert_info_detailed( $cert_pem );
echo $info['issuer_dn'];  // Check DN format
echo $info['serial'];     // Check for scientific notation
echo $info['valid_to'];   // Check expiration
```

---

## 8. Supported Certificate Authorities

Tested and verified:

- ✅ **Uanataca** (Spain) — 128-bit serials, complex DN
- ✅ **ANF** (Spain) — Standard RSA-2048/4096
- ✅ **Banco Central** (Ecuador) — Government-issued
- ✅ **Security Data** (Latin America) — Regional issuer

All require:
1. Intermediate certificate(s) in the chain
2. Proper serial number handling (this class does it)
3. Correct DN formatting (this class does it)

---

## 9. Common Errors & Solutions

| Error | Cause | Solution |
|-------|-------|----------|
| **FIRMA INVALIDA** | Issuer DN format (spaces, order) | Run `compute_issuer_dn(..., true)` to try reversed order |
| **FIRMA INVALIDA** | Serial number in scientific notation | Verify via `cert_info_detailed()` — should be decimal string |
| **FIRMA INVALIDA** | Digest mismatch (namespace/C14N issue) | This class uses double-parse; should not occur |
| **Error 39** | Missing intermediate certificate | Add to `$chain_pem` parameter |
| **Error 39** | Wrong chain order | Ensure: User → Intermediate(s) → Root |
| **Timestamp error** | Wrong timezone | Check server `date.timezone` is set to `America/Guayaquil` |
| **IssuerName mismatch** | DN includes extra attributes | Check `subject`, `serialNumber` fields via `cert_info_detailed()` |

---

## 10. References

- **RFC 2253**: Lightweight Directory Access Protocol (v3): UTF-8 String Representation of Distinguished Names
- **XAdES Standard**: ETSI EN 319 132 (XML Advanced Electronic Signatures)
- **XML Canonicalization**: W3C REC-xml-c14n-20010315 (Inclusive C14N)
- **XML Signatures**: W3C REC-xmldsig-core-20020212
- **SRI Ecuador Technical Spec**: https://www.sri.gob.ec/ (Sistema de Rentas Internas)

---

## 11. Testing & Validation

Before submitting to SRI:

```php
// 1. Diagnose certificate
$info = Arriendo_Facil_SRI_Signer::cert_info_detailed( $cert_pem );
assert( strpos( $info['serial'], 'e' ) === false, "Serial in scientific notation!" );

// 2. Sign the XML
$signer = new Arriendo_Facil_SRI_Signer( $cert_pem, $pkey_pem, $chain_pem );
$signed_xml = $signer->sign( $unsigned_xml );

// 3. Validate structure
Arriendo_Facil_SRI_Signer::validate_signature_structure( $signed_xml );

// 4. Verify issuer DN (optional)
$dn = Arriendo_Facil_SRI_Signer::compute_issuer_dn( $cert_pem );
echo "Using DN: $dn\n";

// 5. Submit via SOAP
// $client = new Arriendo_Facil_SRI_SOAP_Client(...);
// $response = $client->receive( $signed_xml );
```

---

**Last Updated**: 2026-06-15  
**Author**: Arriendo Fácil Development Team  
**Version**: XAdES-BES v1.0 (SRI Ecuador Compliant)
