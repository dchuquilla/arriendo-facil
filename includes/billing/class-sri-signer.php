<?php
/**
 * XAdES-BES digital signature for SRI Ecuador electronic documents.
 *
 * Produces an enveloped XAdES-BES XML signature that complies with
 * the SRI Ecuador technical specification (RSA-SHA1 + inclusive C14N).
 *
 * Algorithm summary
 * -----------------
 * 1. Compute SHA1 digest of the unsigned <factura> element (C14N).
 * 2. Insert a <Signature> skeleton with EMPTY DigestValue / SignatureValue
 *    nodes directly into the document (so all subsequent C14N calls happen
 *    in the full document namespace context, which is required for
 *    inclusive C14N correctness).
 * 3. Compute SHA1 digests of <KeyInfo> and <SignedProperties> *from the
 *    live document* and fill in the three Reference DigestValues.
 * 4. Canonicalise <SignedInfo>, RSA-SHA1 sign it, write <SignatureValue>.
 * 5. Return the serialised document.
 *
 * @package Arriendo_Facil\Billing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arriendo_Facil_SRI_Signer
 */
class Arriendo_Facil_SRI_Signer {

	// ─── XML namespace URIs ──────────────────────────────────────────────────

	const XMLDSIG_NS = 'http://www.w3.org/2000/09/xmldsig#';
	const XADES_NS   = 'http://uri.etsi.org/01903/v1.3.2#';
	const C14N_URL   = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
	const RSA_SHA1   = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
	const SHA1_URL   = 'http://www.w3.org/2000/09/xmldsig#sha1';

	/** @var string PEM-encoded certificate. */
	private $cert_pem;

	/** @var string PEM-encoded private key. */
	private $pkey_pem;

	/** @var string PEM-encoded CA certificate chain (concatenated). */
	private $chain_pem;

	/**
	 * Constructor.
	 *
	 * @param string $cert_pem  PEM-encoded certificate string.
	 * @param string $pkey_pem  PEM-encoded private key string.
	 * @param string $chain_pem PEM-encoded CA chain (concatenated intermediates).
	 */
	public function __construct( string $cert_pem, string $pkey_pem, string $chain_pem = '' ) {
		$this->cert_pem  = $cert_pem;
		$this->pkey_pem  = $pkey_pem;
		$this->chain_pem = $chain_pem;
	}

	// ─── Public API ──────────────────────────────────────────────────────────

	/**
	 * Signs an unsigned SRI XML document with XAdES-BES.
	 *
	 * @param string $xml_unsigned Well-formed XML produced by Arriendo_Facil_SRI_XML_Factura.
	 * @return string Signed XML with the <Signature> node appended to the root element.
	 * @throws RuntimeException On certificate, key, or signing errors.
	 */
	public function sign( string $xml_unsigned ): string {
		// ── 1. Certificate and key from PEM ──────────────────────────────────
		$cert_pem        = $this->cert_pem;
		$private_key_pem = $this->pkey_pem;

		if ( '' === $cert_pem || '' === $private_key_pem ) {
			throw new RuntimeException( 'Certificate and private key PEM data are required for signing.' );
		}

		// DER-encoded certificate bytes (for X509Certificate element + digest).
		$cert_der = $this->pem_to_der( $cert_pem );
		$cert_b64 = base64_encode( $cert_der );

		// SHA1 digest of the DER certificate (used in XAdES SigningCertificate).
		$cert_digest_b64 = base64_encode( hash( 'sha1', $cert_der, true ) );

		// Certificate metadata for IssuerSerial.
		$cert_info     = openssl_x509_parse( $cert_pem );
		if ( false === $cert_info ) {
			throw new RuntimeException( 'Failed to parse PEM certificate.' );
		}
		$issuer_name   = $this->build_issuer_dn( (array) $cert_info['issuer'] );
		$serial_number = $this->cert_serial( (array) $cert_info );

		// RSA public key components for KeyValue.
		list( $modulus_b64, $exponent_b64 ) = $this->extract_rsa_components( $cert_pem );

		// Signing timestamp.
		$signing_time = ( new DateTime() )->format( DateTime::ATOM );

		// Parse CA chain into array of base64-DER strings.
		$chain_b64 = $this->parse_chain_pem( $this->chain_pem );

		// ── 3. Load unsigned XML ─────────────────────────────────────────────
		$doc                     = new DOMDocument( '1.0', 'UTF-8' );
		$doc->preserveWhiteSpace = false;
		if ( ! $doc->loadXML( $xml_unsigned ) ) {
			throw new RuntimeException( 'Cannot parse unsigned XML document.' );
		}
		$root = $doc->documentElement;

		// ── 4. Compute digest of #comprobante BEFORE Signature is inserted ──
		$comprobante_c14n   = $root->C14N( false, false );
		$comprobante_digest = base64_encode( hash( 'sha1', $comprobante_c14n, true ) );

		// ── 5. Build and insert Signature skeleton (empty placeholders) ──────
		$this->insert_signature_skeleton(
			$doc,
			$root,
			$cert_b64,
			$modulus_b64,
			$exponent_b64,
			$cert_digest_b64,
			$issuer_name,
			$serial_number,
			$signing_time,
			$chain_b64
		);

		// ── 6. XPath helpers on the now-complete document ────────────────────
		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 'ds',    self::XMLDSIG_NS );
		$xpath->registerNamespace( 'xades', self::XADES_NS );

		// ── 7. Compute SignedProperties digest in document context ───────────
		$sp_node   = $xpath->query( '//*[@Id="Signature-XAdES-SignedProperties"]' )->item( 0 );
		$sp_digest = base64_encode( hash( 'sha1', $sp_node->C14N( false, false ), true ) );

		// ── 8. Compute KeyInfo digest in document context ────────────────────
		$ki_node   = $xpath->query( '//*[@Id="Certificate1"]' )->item( 0 );
		$ki_digest = base64_encode( hash( 'sha1', $ki_node->C14N( false, false ), true ) );

		// ── 9. Fill in DigestValues ──────────────────────────────────────────
		$this->set_text(
			$xpath->query( '//*[@Id="comprobante-ref0"]/ds:DigestValue' )->item( 0 ),
			$comprobante_digest
		);
		$this->set_text(
			$xpath->query( '//*[@URI="#Certificate1"]/ds:DigestValue' )->item( 0 ),
			$ki_digest
		);
		$this->set_text(
			$xpath->query( '//*[@URI="#Signature-XAdES-SignedProperties"]/ds:DigestValue' )->item( 0 ),
			$sp_digest
		);

		// ── 10. Canonicalise SignedInfo and compute RSA-SHA1 signature ───────
		$si_node = $xpath->query( '//*[@Id="Signature-SignedInfo"]' )->item( 0 );
		$si_c14n = $si_node->C14N( false, false );

		$pk  = openssl_pkey_get_private( $private_key_pem );
		if ( false === $pk ) {
			throw new RuntimeException( 'Cannot load private key from certificate.' );
		}
		$sig_raw = '';
		if ( ! openssl_sign( $si_c14n, $sig_raw, $pk, OPENSSL_ALGO_SHA1 ) ) {
			throw new RuntimeException( 'openssl_sign failed: ' . openssl_error_string() );
		}
		$sig_b64 = base64_encode( $sig_raw );

		// ── 11. Write SignatureValue ─────────────────────────────────────────
		$this->set_text(
			$xpath->query( '//*[@Id="Signature-SignatureValue"]' )->item( 0 ),
			$sig_b64
		);

		// ── 12. Serialise ────────────────────────────────────────────────────
		$result = $doc->saveXML();
		if ( false === $result ) {
			throw new RuntimeException( 'Cannot serialise signed XML document.' );
		}
		return $result;
	}

	// ─── Signature skeleton builder ──────────────────────────────────────────

	/**
	 * Builds the complete <Signature> subtree with empty DigestValue /
	 * SignatureValue placeholders and appends it to the document root.
	 *
	 * @param DOMDocument $doc
	 * @param DOMElement  $root         Document root (<factura>).
	 * @param string      $cert_b64     Base64-DER certificate.
	 * @param string      $modulus_b64  RSA modulus (base64).
	 * @param string      $exponent_b64 RSA exponent (base64).
	 * @param string      $cert_digest  SHA1 of DER cert (base64).
	 * @param string      $issuer_name  X509 issuer DN string.
	 * @param string      $serial       Certificate serial (decimal string).
	 * @param string      $signing_time ISO 8601 timestamp.
	 * @param array       $chain_b64    Array of base64-DER CA certificates.
	 */
	private function insert_signature_skeleton(
		DOMDocument $doc,
		DOMElement  $root,
		string      $cert_b64,
		string      $modulus_b64,
		string      $exponent_b64,
		string      $cert_digest,
		string      $issuer_name,
		string      $serial,
		string      $signing_time,
		array       $chain_b64 = array()
	): void {
		$ds = self::XMLDSIG_NS;
		$xa = self::XADES_NS;
		$e  = '';  // empty placeholder text

		// <Signature>
		$sig = $doc->createElementNS( $ds, 'Signature' );
		$sig->setAttribute( 'Id', 'Signature' );

		// ── <SignedInfo> ─────────────────────────────────────────────────────
		$si = $doc->createElementNS( $ds, 'SignedInfo' );
		$si->setAttribute( 'Id', 'Signature-SignedInfo' );

		$cm = $doc->createElementNS( $ds, 'CanonicalizationMethod' );
		$cm->setAttribute( 'Algorithm', self::C14N_URL );
		$si->appendChild( $cm );

		$sm = $doc->createElementNS( $ds, 'SignatureMethod' );
		$sm->setAttribute( 'Algorithm', self::RSA_SHA1 );
		$si->appendChild( $sm );

		// Reference[0] — #comprobante (with C14N Transform)
		$ref0 = $doc->createElementNS( $ds, 'Reference' );
		$ref0->setAttribute( 'Id', 'comprobante-ref0' );
		$ref0->setAttribute( 'URI', '#comprobante' );
		$trs0 = $doc->createElementNS( $ds, 'Transforms' );
		$t0   = $doc->createElementNS( $ds, 'Transform' );
		$t0->setAttribute( 'Algorithm', self::C14N_URL );
		$trs0->appendChild( $t0 );
		$ref0->appendChild( $trs0 );
		$ref0->appendChild( $this->digest_method_el( $doc ) );
		$ref0->appendChild( $this->digest_value_el( $doc, $e ) );
		$si->appendChild( $ref0 );

		// Reference[1] — #Certificate1 (no Transform; C14N applied implicitly)
		$ref1 = $doc->createElementNS( $ds, 'Reference' );
		$ref1->setAttribute( 'URI', '#Certificate1' );
		$ref1->appendChild( $this->digest_method_el( $doc ) );
		$ref1->appendChild( $this->digest_value_el( $doc, $e ) );
		$si->appendChild( $ref1 );

		// Reference[2] — #Signature-XAdES-SignedProperties (with C14N Transform)
		$ref2 = $doc->createElementNS( $ds, 'Reference' );
		$ref2->setAttribute( 'Id', 'Signature-SignedInfo-ref1' );
		$ref2->setAttribute( 'Type', 'http://uri.etsi.org/01903#SignedProperties' );
		$ref2->setAttribute( 'URI', '#Signature-XAdES-SignedProperties' );
		$trs2 = $doc->createElementNS( $ds, 'Transforms' );
		$t2   = $doc->createElementNS( $ds, 'Transform' );
		$t2->setAttribute( 'Algorithm', self::C14N_URL );
		$trs2->appendChild( $t2 );
		$ref2->appendChild( $trs2 );
		$ref2->appendChild( $this->digest_method_el( $doc ) );
		$ref2->appendChild( $this->digest_value_el( $doc, $e ) );
		$si->appendChild( $ref2 );

		$sig->appendChild( $si );

		// ── <SignatureValue> (placeholder) ───────────────────────────────────
		$sv = $doc->createElementNS( $ds, 'SignatureValue' );
		$sv->setAttribute( 'Id', 'Signature-SignatureValue' );
		$sv->appendChild( $doc->createTextNode( $e ) );
		$sig->appendChild( $sv );

		// ── <KeyInfo> ────────────────────────────────────────────────────────
		$ki   = $doc->createElementNS( $ds, 'KeyInfo' );
		$ki->setAttribute( 'Id', 'Certificate1' );

		$x509d = $doc->createElementNS( $ds, 'X509Data' );
		$x509c = $doc->createElementNS( $ds, 'X509Certificate' );
		$x509c->appendChild( $doc->createTextNode( $cert_b64 ) );
		$x509d->appendChild( $x509c );

		// Include CA chain certificates for trust validation.
		foreach ( $chain_b64 as $ca_b64 ) {
			$ca_el = $doc->createElementNS( $ds, 'X509Certificate' );
			$ca_el->appendChild( $doc->createTextNode( $ca_b64 ) );
			$x509d->appendChild( $ca_el );
		}

		$ki->appendChild( $x509d );

		$kv   = $doc->createElementNS( $ds, 'KeyValue' );
		$rsa  = $doc->createElementNS( $ds, 'RSAKeyValue' );
		$mod  = $doc->createElementNS( $ds, 'Modulus' );
		$mod->appendChild( $doc->createTextNode( $modulus_b64 ) );
		$rsa->appendChild( $mod );
		$exp  = $doc->createElementNS( $ds, 'Exponent' );
		$exp->appendChild( $doc->createTextNode( $exponent_b64 ) );
		$rsa->appendChild( $exp );
		$kv->appendChild( $rsa );
		$ki->appendChild( $kv );

		$sig->appendChild( $ki );

		// ── <Object> / QualifyingProperties / SignedProperties ───────────────
		$obj = $doc->createElementNS( $ds, 'Object' );
		$obj->setAttribute( 'Id', 'Signature-Object-QualifyingProperties' );

		$qp = $doc->createElementNS( $xa, 'QualifyingProperties' );
		$qp->setAttribute( 'Target', '#Signature' );

		$sp = $doc->createElementNS( $xa, 'SignedProperties' );
		$sp->setAttribute( 'Id', 'Signature-XAdES-SignedProperties' );

		// SignedSignatureProperties
		$ssp = $doc->createElementNS( $xa, 'SignedSignatureProperties' );

		$st = $doc->createElementNS( $xa, 'SigningTime' );
		$st->appendChild( $doc->createTextNode( $signing_time ) );
		$ssp->appendChild( $st );

		$sc    = $doc->createElementNS( $xa, 'SigningCertificate' );
		$crt   = $doc->createElementNS( $xa, 'Cert' );
		$cd    = $doc->createElementNS( $xa, 'CertDigest' );
		$cdm   = $doc->createElementNS( $ds, 'DigestMethod' );
		$cdm->setAttribute( 'Algorithm', self::SHA1_URL );
		$cd->appendChild( $cdm );
		$cdv   = $doc->createElementNS( $ds, 'DigestValue' );
		$cdv->appendChild( $doc->createTextNode( $cert_digest ) );
		$cd->appendChild( $cdv );
		$crt->appendChild( $cd );

		$is   = $doc->createElementNS( $xa, 'IssuerSerial' );
		$isn  = $doc->createElementNS( $ds, 'X509IssuerName' );
		$isn->appendChild( $doc->createTextNode( $issuer_name ) );
		$is->appendChild( $isn );
		$isn2 = $doc->createElementNS( $ds, 'X509SerialNumber' );
		$isn2->appendChild( $doc->createTextNode( $serial ) );
		$is->appendChild( $isn2 );
		$crt->appendChild( $is );
		$sc->appendChild( $crt );
		$ssp->appendChild( $sc );
		$sp->appendChild( $ssp );

		// SignedDataObjectProperties
		$sdop = $doc->createElementNS( $xa, 'SignedDataObjectProperties' );
		$dof  = $doc->createElementNS( $xa, 'DataObjectFormat' );
		$dof->setAttribute( 'ObjectReference', '#comprobante-ref0' );
		$dsc  = $doc->createElementNS( $xa, 'Description' );
		$dsc->appendChild( $doc->createTextNode( 'Documento de venta' ) );
		$dof->appendChild( $dsc );
		$mim  = $doc->createElementNS( $xa, 'MimeType' );
		$mim->appendChild( $doc->createTextNode( 'text/xml' ) );
		$dof->appendChild( $mim );
		$sdop->appendChild( $dof );
		$sp->appendChild( $sdop );

		$qp->appendChild( $sp );
		$obj->appendChild( $qp );
		$sig->appendChild( $obj );

		$root->appendChild( $sig );
	}

	// ─── Certificate helpers ─────────────────────────────────────────────────

	/**
	 * Converts a PEM-encoded certificate to raw DER bytes.
	 *
	 * @param string $pem PEM string.
	 * @return string Binary DER bytes.
	 */
	private function pem_to_der( string $pem ): string {
		$pem   = preg_replace( '/-----[^-]+-----|[\r\n\s]/', '', $pem );
		return (string) base64_decode( (string) $pem );
	}

	/**
	 * Builds an X.509 issuer Distinguished Name string from the parsed issuer array.
	 *
	 * @param array<string, string> $issuer From openssl_x509_parse()['issuer'].
	 * @return string  e.g. "CN=BCE CA, O=BCE, C=EC"
	 */
	private function build_issuer_dn( array $issuer ): string {
		$parts = array();
		foreach ( array( 'CN', 'OU', 'O', 'L', 'ST', 'C' ) as $key ) {
			if ( ! empty( $issuer[ $key ] ) ) {
				$parts[] = $key . '=' . $issuer[ $key ];
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Returns the certificate serial number as a decimal string.
	 *
	 * @param array<string, mixed> $cert_info From openssl_x509_parse().
	 * @return string Decimal serial number.
	 */
	private function cert_serial( array $cert_info ): string {
		if ( ! empty( $cert_info['serialNumberHex'] ) ) {
			$hex = ltrim( (string) $cert_info['serialNumberHex'], '0' );
			if ( '' === $hex ) {
				return '0';
			}
			if ( function_exists( 'gmp_strval' ) ) {
				return gmp_strval( gmp_init( '0x' . $hex ) );
			}
			if ( function_exists( 'bcadd' ) ) {
				$dec = '0';
				for ( $i = 0, $len = strlen( $hex ); $i < $len; $i++ ) {
					$dec = bcadd( bcmul( $dec, '16' ), (string) hexdec( $hex[ $i ] ) );
				}
				return $dec;
			}
			// Fallback (only reliable for serials ≤ PHP_INT_MAX).
			return (string) hexdec( $hex );
		}
		return isset( $cert_info['serialNumber'] ) ? (string) (int) $cert_info['serialNumber'] : '0';
	}

	/**
	 * Extracts the RSA public key modulus and exponent from a PEM certificate.
	 *
	 * @param string $cert_pem PEM certificate.
	 * @return array{0: string, 1: string} [modulus_b64, exponent_b64]
	 * @throws RuntimeException
	 */
	private function extract_rsa_components( string $cert_pem ): array {
		$pub_key = openssl_pkey_get_public( $cert_pem );
		if ( false === $pub_key ) {
			throw new RuntimeException( 'Cannot extract public key from certificate.' );
		}
		$details = openssl_pkey_get_details( $pub_key );
		if ( ! is_array( $details ) || empty( $details['rsa'] ) ) {
			throw new RuntimeException( 'Certificate does not contain an RSA public key.' );
		}
		return array(
			base64_encode( $details['rsa']['n'] ),
			base64_encode( $details['rsa']['e'] ),
		);
	}

	// ─── DOM micro-helpers ───────────────────────────────────────────────────

	/**
	 * Creates a <ds:DigestMethod Algorithm="sha1"> element.
	 *
	 * @param DOMDocument $doc
	 * @return DOMElement
	 */
	private function digest_method_el( DOMDocument $doc ): DOMElement {
		$el = $doc->createElementNS( self::XMLDSIG_NS, 'DigestMethod' );
		$el->setAttribute( 'Algorithm', self::SHA1_URL );
		return $el;
	}

	/**
	 * Creates a <ds:DigestValue> element with the given text content.
	 *
	 * @param DOMDocument $doc
	 * @param string      $value Base64 digest (may be empty placeholder).
	 * @return DOMElement
	 */
	private function digest_value_el( DOMDocument $doc, string $value ): DOMElement {
		$el = $doc->createElementNS( self::XMLDSIG_NS, 'DigestValue' );
		$el->appendChild( $doc->createTextNode( $value ) );
		return $el;
	}

	/**
	 * Replaces the text content of a DOM node.
	 *
	 * @param DOMNode $node  Target node.
	 * @param string  $text  New text content.
	 */
	private function set_text( DOMNode $node, string $text ): void {
		while ( $node->firstChild ) {
			$node->removeChild( $node->firstChild );
		}
		$node->appendChild( $node->ownerDocument->createTextNode( $text ) );
	}

	/**
	 * Parses a concatenated PEM chain string into an array of base64-DER strings.
	 *
	 * @param string $chain_pem Concatenated PEM certificates.
	 * @return array<string> Base64-encoded DER for each CA certificate.
	 */
	private function parse_chain_pem( string $chain_pem ): array {
		if ( '' === trim( $chain_pem ) ) {
			return array();
		}

		$certs = array();
		if ( preg_match_all(
			'/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s',
			$chain_pem,
			$matches
		) ) {
			foreach ( $matches[1] as $body ) {
				$der_b64 = preg_replace( '/\s+/', '', $body );
				if ( '' !== $der_b64 ) {
					$certs[] = $der_b64;
				}
			}
		}

		return $certs;
	}
}
