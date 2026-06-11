<?php
/**
 * XAdES-BES digital signature for SRI Ecuador electronic documents.
 *
 * Produces an enveloped XAdES-BES XML signature that complies with
 * the SRI Ecuador technical specification (RSA-SHA256 + inclusive C14N).
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

	const XMLDSIG_NS  = 'http://www.w3.org/2000/09/xmldsig#';
	const XADES_NS    = 'http://uri.etsi.org/01903/v1.3.2#';
	const C14N_URL    = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
	const RSA_SHA256  = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
	const SHA256_URL  = 'http://www.w3.org/2001/04/xmlenc#sha256';

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
	 * Implementation note — double-parse C14N safety
	 * ------------------------------------------------
	 * PHP's DOMNode::C14N() has a documented quirk: namespace declarations added
	 * to an element via setAttributeNS() are sometimes NOT visible to the C14N
	 * traversal on descendant nodes, while the same declarations ARE visible in
	 * the XML produced by saveXML().  This divergence causes the SignedProperties
	 * and KeyInfo digests we compute to differ from what the SRI's Java-based
	 * OpenXades library computes from the received XML, producing FIRMA INVALIDA.
	 *
	 * The fix: after inserting the Signature skeleton we serialise the full
	 * document to an XML string and immediately re-parse it into a fresh
	 * DOMDocument.  All subsequent C14N computations (SignedProperties, KeyInfo,
	 * SignedInfo) are performed on this fresh document, which has identical
	 * namespace context to what the SRI will parse.  The #comprobante digest is
	 * computed before the Signature is inserted (equivalent to the enveloped-
	 * signature transform) and is thus unaffected by the re-parse.
	 *
	 * @param string $xml_unsigned Well-formed XML produced by Arriendo_Facil_SRI_XML_Factura.
	 * @return string Signed XML with the <Signature> node appended to the root element.
	 * @throws RuntimeException On certificate, key, or signing errors.
	 */
	public function sign( string $xml_unsigned ): string {
		$cert_pem        = $this->cert_pem;
		$private_key_pem = $this->pkey_pem;

		if ( '' === $cert_pem || '' === $private_key_pem ) {
			throw new RuntimeException( 'Certificate and private key PEM data are required for signing.' );
		}

		$cert_der        = $this->pem_to_der( $cert_pem );
		$cert_b64        = base64_encode( $cert_der );
		$cert_digest_b64 = base64_encode( hash( 'sha256', $cert_der, true ) );

		$cert_info = openssl_x509_parse( $cert_pem );
		if ( false === $cert_info ) {
			throw new RuntimeException( 'Failed to parse PEM certificate.' );
		}
		$issuer_name   = $this->build_issuer_dn( (array) $cert_info['issuer'] );
		$serial_number = $this->cert_serial( (array) $cert_info );

		list( $modulus_b64, $exponent_b64 ) = $this->extract_rsa_components( $cert_pem );

		// SRI requires the signing time in Ecuador local timezone (UTC-5 / America/Guayaquil).
		// Using server-default timezone (often UTC) produces +00:00 and is rejected.
		$signing_time = ( new DateTime( 'now', new DateTimeZone( 'America/Guayaquil' ) ) )->format( 'Y-m-d\TH:i:sP' );
		$chain_b64    = $this->parse_chain_pem( $this->chain_pem );

		// ── PHASE 1: Compute #comprobante digest BEFORE Signature is inserted ──
		// This is equivalent to the enveloped-signature transform: the signed
		// document reference covers the root element without the Signature node.
		$doc                     = new DOMDocument( '1.0', 'UTF-8' );
		$doc->preserveWhiteSpace = false;
		if ( ! $doc->loadXML( $xml_unsigned ) ) {
			throw new RuntimeException( 'Cannot parse unsigned XML document.' );
		}
		$root = $doc->documentElement;
		$root->setIdAttribute( 'id', true );

		$comprobante_digest = base64_encode(
			hash( 'sha256', $root->C14N( false, false ), true )
		);

		// ── PHASE 2: Insert the Signature skeleton (empty DigestValues) ─────────
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

		// ── PHASE 3: Round-trip serialize → re-parse ─────────────────────────────
		// This is the critical safety step: all C14N computations from this point
		// forward are performed on doc2, whose namespace context is guaranteed to
		// match what the SRI will parse from the received XML.
		$xml_intermediate = $doc->saveXML();
		if ( false === $xml_intermediate ) {
			throw new RuntimeException( 'Cannot serialise intermediate XML document.' );
		}

		$doc2                     = new DOMDocument( '1.0', 'UTF-8' );
		$doc2->preserveWhiteSpace = false;
		if ( ! $doc2->loadXML( $xml_intermediate ) ) {
			throw new RuntimeException( 'Cannot re-parse intermediate XML document.' );
		}
		$root2 = $doc2->documentElement;
		$root2->setIdAttribute( 'id', true );

		$xpath2 = new DOMXPath( $doc2 );
		$xpath2->registerNamespace( 'ds',   self::XMLDSIG_NS );
		$xpath2->registerNamespace( 'etsi', self::XADES_NS );

		// ── PHASE 4: Compute digests from the re-parsed document ─────────────────
		$sp_node   = $xpath2->query( '//*[@Id="Signature-XAdES-SignedProperties"]' )->item( 0 );
		$sp_digest = base64_encode( hash( 'sha256', $sp_node->C14N( false, false ), true ) );

		$ki_node   = $xpath2->query( '//*[@Id="Certificate1"]' )->item( 0 );
		$ki_digest = base64_encode( hash( 'sha256', $ki_node->C14N( false, false ), true ) );

		// ── PHASE 5: Fill DigestValues into doc2 ────────────────────────────────
		$this->set_text(
			$xpath2->query( '//ds:Reference[@Id="comprobante-ref0"]/ds:DigestValue' )->item( 0 ),
			$comprobante_digest
		);
		$this->set_text(
			$xpath2->query( '//ds:Reference[@URI="#Certificate1"]/ds:DigestValue' )->item( 0 ),
			$ki_digest
		);
		$this->set_text(
			$xpath2->query( '//ds:Reference[@URI="#Signature-XAdES-SignedProperties"]/ds:DigestValue' )->item( 0 ),
			$sp_digest
		);

		// ── PHASE 6: Sign SignedInfo ─────────────────────────────────────────────
		$si_node = $xpath2->query( '//ds:SignedInfo[@Id="Signature-SignedInfo"]' )->item( 0 );
		$si_c14n = $si_node->C14N( false, false );

		$pk = openssl_pkey_get_private( $private_key_pem );
		if ( false === $pk ) {
			throw new RuntimeException( 'Cannot load private key from certificate.' );
		}
		$sig_raw = '';
		if ( ! openssl_sign( $si_c14n, $sig_raw, $pk, OPENSSL_ALGO_SHA256 ) ) {
			throw new RuntimeException( 'openssl_sign failed: ' . openssl_error_string() );
		}

		$this->set_text(
			$xpath2->query( '//ds:SignatureValue[@Id="Signature-SignatureValue"]' )->item( 0 ),
			"\n" . chunk_split( base64_encode( $sig_raw ), 76, "\n" )
		);

		$result = $doc2->saveXML();
		if ( false === $result ) {
			throw new RuntimeException( 'Cannot serialise signed XML document.' );
		}
		return $result;
	}

	// ─── Signature skeleton builder ──────────────────────────────────────────

	/**
	 * Builds the <ds:Signature> subtree with proper ds:/etsi: namespace prefixes.
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

		// <ds:Signature>
		// Do NOT declare xmlns:etsi here via setAttributeNS – let DOMDocument
		// place the declaration naturally on the first etsi: element
		// (etsi:QualifyingProperties).  Explicit setAttributeNS causes a known
		// PHP DOMNode::C14N() bug where the namespace is invisible to the C14N
		// traversal on descendant nodes, producing digest values that differ
		// from what the SRI's parser computes.
		$sig = $doc->createElementNS( $ds, 'ds:Signature' );
		$sig->setAttribute( 'Id', 'Signature' );

		// ── <ds:SignedInfo> ──────────────────────────────────────────────────
		$si = $doc->createElementNS( $ds, 'ds:SignedInfo' );
		$si->setAttribute( 'Id', 'Signature-SignedInfo' );

		$cm = $doc->createElementNS( $ds, 'ds:CanonicalizationMethod' );
		$cm->setAttribute( 'Algorithm', self::C14N_URL );
		$si->appendChild( $cm );

		$sm = $doc->createElementNS( $ds, 'ds:SignatureMethod' );
		$sm->setAttribute( 'Algorithm', self::RSA_SHA256 );
		$si->appendChild( $sm );

		// Reference[0] — #comprobante
		$ref0 = $doc->createElementNS( $ds, 'ds:Reference' );
		$ref0->setAttribute( 'Id', 'comprobante-ref0' );
		$ref0->setAttribute( 'URI', '#comprobante' );
		$trs0 = $doc->createElementNS( $ds, 'ds:Transforms' );
		$t0_env = $doc->createElementNS( $ds, 'ds:Transform' );
		$t0_env->setAttribute( 'Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature' );
		$trs0->appendChild( $t0_env );
		$t0 = $doc->createElementNS( $ds, 'ds:Transform' );
		$t0->setAttribute( 'Algorithm', self::C14N_URL );
		$trs0->appendChild( $t0 );
		$ref0->appendChild( $trs0 );
		$ref0->appendChild( $this->ds_el( $doc, 'ds:DigestMethod', '', array( 'Algorithm' => self::SHA256_URL ) ) );
		$ref0->appendChild( $this->ds_el( $doc, 'ds:DigestValue', '' ) );
		$si->appendChild( $ref0 );

		// Reference[1] — #Certificate1
		$ref1 = $doc->createElementNS( $ds, 'ds:Reference' );
		$ref1->setAttribute( 'URI', '#Certificate1' );
		$ref1->appendChild( $this->ds_el( $doc, 'ds:DigestMethod', '', array( 'Algorithm' => self::SHA256_URL ) ) );
		$ref1->appendChild( $this->ds_el( $doc, 'ds:DigestValue', '' ) );
		$si->appendChild( $ref1 );

		// Reference[2] — #Signature-XAdES-SignedProperties
		$ref2 = $doc->createElementNS( $ds, 'ds:Reference' );
		$ref2->setAttribute( 'Id', 'Signature-SignedInfo-ref1' );
		$ref2->setAttribute( 'Type', 'http://uri.etsi.org/01903#SignedProperties' );
		$ref2->setAttribute( 'URI', '#Signature-XAdES-SignedProperties' );
		$trs2 = $doc->createElementNS( $ds, 'ds:Transforms' );
		$t2   = $doc->createElementNS( $ds, 'ds:Transform' );
		$t2->setAttribute( 'Algorithm', self::C14N_URL );
		$trs2->appendChild( $t2 );
		$ref2->appendChild( $trs2 );
		$ref2->appendChild( $this->ds_el( $doc, 'ds:DigestMethod', '', array( 'Algorithm' => self::SHA256_URL ) ) );
		$ref2->appendChild( $this->ds_el( $doc, 'ds:DigestValue', '' ) );
		$si->appendChild( $ref2 );

		$sig->appendChild( $si );

		// ── <ds:SignatureValue> ──────────────────────────────────────────────
		$sv = $doc->createElementNS( $ds, 'ds:SignatureValue' );
		$sv->setAttribute( 'Id', 'Signature-SignatureValue' );
		$sv->appendChild( $doc->createTextNode( '' ) );
		$sig->appendChild( $sv );

		// ── <ds:KeyInfo> ────────────────────────────────────────────────────
		$ki = $doc->createElementNS( $ds, 'ds:KeyInfo' );
		$ki->setAttribute( 'Id', 'Certificate1' );

		// Entity certificate + X509IssuerSerial in the first X509Data block.
		// SRI requires <ds:X509IssuerSerial> here (not only in etsi:IssuerSerial) to
		// validate "El certificado firmante es válido".
		$x509d = $doc->createElementNS( $ds, 'ds:X509Data' );
		$x509c = $doc->createElementNS( $ds, 'ds:X509Certificate' );
		$x509c->appendChild( $doc->createTextNode( "\n" . chunk_split( $cert_b64, 76, "\n" ) ) );
		$x509d->appendChild( $x509c );

		$x509is  = $doc->createElementNS( $ds, 'ds:X509IssuerSerial' );
		$x509is->appendChild( $this->ds_el( $doc, 'ds:X509IssuerName', $issuer_name ) );
		$x509is->appendChild( $this->ds_el( $doc, 'ds:X509SerialNumber', $serial ) );
		$x509d->appendChild( $x509is );

		$ki->appendChild( $x509d );

		// Each CA / intermediate certificate goes in its own X509Data block.
		foreach ( $chain_b64 as $ca_b64 ) {
			$ca_x509d = $doc->createElementNS( $ds, 'ds:X509Data' );
			$ca_el    = $doc->createElementNS( $ds, 'ds:X509Certificate' );
			$ca_el->appendChild( $doc->createTextNode( "\n" . chunk_split( $ca_b64, 76, "\n" ) ) );
			$ca_x509d->appendChild( $ca_el );
			$ki->appendChild( $ca_x509d );
		}

		$kv  = $doc->createElementNS( $ds, 'ds:KeyValue' );
		$rsa = $doc->createElementNS( $ds, 'ds:RSAKeyValue' );
		$mod_el = $doc->createElementNS( $ds, 'ds:Modulus' );
		$mod_el->appendChild( $doc->createTextNode( "\n" . chunk_split( $modulus_b64, 76, "\n" ) ) );
		$rsa->appendChild( $mod_el );
		$rsa->appendChild( $this->ds_el( $doc, 'ds:Exponent', $exponent_b64 ) );
		$kv->appendChild( $rsa );
		$ki->appendChild( $kv );

		$sig->appendChild( $ki );

		// ── <ds:Object> / etsi:QualifyingProperties ─────────────────────────
		$obj = $doc->createElementNS( $ds, 'ds:Object' );
		$obj->setAttribute( 'Id', 'Signature-Object-QualifyingProperties' );

		$qp = $doc->createElementNS( $xa, 'etsi:QualifyingProperties' );
		$qp->setAttribute( 'Target', '#Signature' );

		$sp = $doc->createElementNS( $xa, 'etsi:SignedProperties' );
		$sp->setAttribute( 'Id', 'Signature-XAdES-SignedProperties' );

		// SignedSignatureProperties
		$ssp = $doc->createElementNS( $xa, 'etsi:SignedSignatureProperties' );
		$ssp->appendChild( $this->etsi_el( $doc, 'etsi:SigningTime', $signing_time ) );

		$sc  = $doc->createElementNS( $xa, 'etsi:SigningCertificate' );
		$crt = $doc->createElementNS( $xa, 'etsi:Cert' );

		$cd  = $doc->createElementNS( $xa, 'etsi:CertDigest' );
		$cd->appendChild( $this->ds_el( $doc, 'ds:DigestMethod', '', array( 'Algorithm' => self::SHA256_URL ) ) );
		$cd->appendChild( $this->ds_el( $doc, 'ds:DigestValue', $cert_digest ) );
		$crt->appendChild( $cd );

		$is = $doc->createElementNS( $xa, 'etsi:IssuerSerial' );
		$is->appendChild( $this->ds_el( $doc, 'ds:X509IssuerName', $issuer_name ) );
		$is->appendChild( $this->ds_el( $doc, 'ds:X509SerialNumber', $serial ) );
		$crt->appendChild( $is );

		$sc->appendChild( $crt );
		$ssp->appendChild( $sc );
		$sp->appendChild( $ssp );

		// SignedDataObjectProperties
		$sdop = $doc->createElementNS( $xa, 'etsi:SignedDataObjectProperties' );
		$dof  = $doc->createElementNS( $xa, 'etsi:DataObjectFormat' );
		$dof->setAttribute( 'ObjectReference', '#comprobante-ref0' );
		$dof->appendChild( $this->etsi_el( $doc, 'etsi:Description', 'Documento de venta' ) );
		$dof->appendChild( $this->etsi_el( $doc, 'etsi:MimeType', 'text/xml' ) );
		$sdop->appendChild( $dof );
		$sp->appendChild( $sdop );

		$qp->appendChild( $sp );
		$obj->appendChild( $qp );
		$sig->appendChild( $obj );

		$root->appendChild( $sig );
	}

	// ─── Certificate helpers ─────────────────────────────────────────────────

	private function pem_to_der( string $pem ): string {
		$pem = preg_replace( '/-----[^-]+-----|[\r\n\s]/', '', $pem );
		return (string) base64_decode( (string) $pem );
	}

	private function build_issuer_dn( array $issuer ): string {
		$parts = array();
		// RFC 2253 order: most-specific first (CN → C).
		foreach ( array( 'CN', 'OU', 'O', 'L', 'ST', 'C' ) as $key ) {
			if ( ! isset( $issuer[ $key ] ) || '' === $issuer[ $key ] ) {
				continue;
			}
			$val = $issuer[ $key ];
			if ( is_array( $val ) ) {
				$val = $val[0];
			}
			$val = (string) $val;
			// RFC 2253 §2.4: escape , = + < > # ; \ " and leading/trailing spaces.
			$escaped = addcslashes( $val, ',=+<>#;\\"' );
			$escaped = preg_replace( '/^ /', '\\ ', $escaped );
			$escaped = preg_replace( '/ $/', '\\ ', $escaped );
			$parts[] = $key . '=' . $escaped;
		}
		return implode( ', ', $parts );
	}

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
			return (string) hexdec( $hex );
		}
		return isset( $cert_info['serialNumber'] ) ? (string) (int) $cert_info['serialNumber'] : '0';
	}

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

	private function ds_el( DOMDocument $doc, string $name, string $text, array $attrs = array() ): DOMElement {
		$el = $doc->createElementNS( self::XMLDSIG_NS, $name );
		foreach ( $attrs as $k => $v ) {
			$el->setAttribute( $k, $v );
		}
		if ( '' !== $text ) {
			$el->appendChild( $doc->createTextNode( $text ) );
		}
		return $el;
	}

	private function etsi_el( DOMDocument $doc, string $name, string $text, array $attrs = array() ): DOMElement {
		$el = $doc->createElementNS( self::XADES_NS, $name );
		foreach ( $attrs as $k => $v ) {
			$el->setAttribute( $k, $v );
		}
		if ( '' !== $text ) {
			$el->appendChild( $doc->createTextNode( $text ) );
		}
		return $el;
	}

	private function set_text( DOMNode $node, string $text ): void {
		while ( $node->firstChild ) {
			$node->removeChild( $node->firstChild );
		}
		$node->appendChild( $node->ownerDocument->createTextNode( $text ) );
	}

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
