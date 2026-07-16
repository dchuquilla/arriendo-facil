# 🔍 Diagnóstico Completo: Cadena CA en Firma XAdES-BES

**Fecha:** 16 de Junio de 2026  
**Objetivo:** Verificar que la cadena CA se guarde, recupere e incluya correctamente en la firma para evitar "FIRMA INVALIDA"

---

## 📋 Resumen Ejecutivo

Tu implementación de firma XAdES-BES es **técnicamente correcta**, pero hay 3 **puntos críticos** en el flujo de la cadena CA que pueden causar rechazo del SRI:

| Fase | Componente | Estado | Riesgo |
|------|-----------|--------|--------|
| 1️⃣ | Extracción P12 | ✓ Bien | Si no tiene chain interna, busca vía AIA |
| 2️⃣ | Obtención AIA | ⚠️ Depende de URL | Si falla, cadena queda vacía |
| 3️⃣ | Guardado encriptado | ✓ Bien | Pero puede ser vacío silenciosamente |
| 4️⃣ | Recuperación desencriptada | ✓ Bien | Pero no valida que NO esté vacío |
| 5️⃣ | Inclusión en firma | ✓ Bien | Pero si está vacía, no hay intermediarios |

---

## 🔴 Punto Crítico #1: Cadena CA Vacía No Se Valida

**Ubicación:** `class-billing-manager.php` (función `issue_from_payload`)

**Código actual (línea ~156):**
```php
$pems = Arriendo_Facil_SRI_Config::get_cert_pems();

if ( '' === $pems['cert'] || '' === $pems['pkey'] ) {
    return new WP_Error( 'sri_cert_missing', 'Certificado o clave privada no disponibles.' );
}
// ❌ NO VALIDA: if ( '' === $pems['chain'] )
```

**El Problema:**
- ✓ Se valida que `cert` NO esté vacío
- ✓ Se valida que `pkey` NO esté vacío
- ❌ **NO se valida que `chain` NO esté vacío**
- Resultado: La firma se crea SIN certificados intermedios **sin lanzar error**

**Consecuencia en la firma:**
```xml
<!-- Esto es lo que genera el código actual -->
<ds:X509Data>
    <ds:X509Certificate>BASE64(USUARIO_CERT)</ds:X509Certificate>
    <ds:X509IssuerSerial>
        <ds:X509IssuerName>CN=UANATACA...</ds:X509IssuerName>
        <ds:X509SerialNumber>...</ds:X509SerialNumber>
    </ds:X509IssuerSerial>
    <!-- ❌ NO HAY INTERMEDIARIOS -->
</ds:X509Data>

<!-- El SRI intenta validar: Usuario → [nada] → Root
     Pero necesita: Usuario → Intermedio → Root
     Resultado: FIRMA INVALIDA -->
```

---

## 🔴 Punto Crítico #2: AIA Puede Fallar Silenciosamente

**Ubicación:** `class-sri-config.php` línea 357-419 (función `fetch_ca_chain`)

**Escenarios de fallo:**

### A) Certificado SIN extensión AIA
```php
// Línea 362-365
$issuer_url = self::extract_aia_ca_issuer( $current );
if ( '' === $issuer_url ) {
    error_log( '[AF SRI] fetch_ca_chain: sin URL AIA...' );
    break;  // ← Sale del loop, cadena vacía
}
```

**Solución:** Si no hay AIA, el código **devuelve string vacío**. Esto es correcto, pero:
- ✓ Genera logs
- ❌ Se almacena **vacío sin aviso al usuario**

### B) Certificado CON AIA pero URL inaccesible
```php
// Línea 369-372
$response = wp_remote_get( $issuer_url, array( 'timeout' => 15, 'sslverify' => true ) );
if ( is_wp_error( $response ) ) {
    error_log( '[AF SRI] fetch_ca_chain: ERROR al descargar...' );
    break;  // ← Sale, cadena incompleta
}
```

**Riesgo:** El servidor de facturación podría no tener acceso directo a las URLs AIA (firewall, proxy, etc.).

### C) Root certificate detectable pero no excluido correctamente
```php
// Línea 400-403
$is_self_signed = ( ( $ca_info['subject'] ?? array() ) === ( $ca_info['issuer'] ?? array() ) );
if ( $is_self_signed ) {
    error_log( '[AF SRI] fetch_ca_chain: cert autofirmado (root) encontrado...' );
    break;
}
```

**Esto es correcto.** No incluye el root, solo intermediarios. ✓

---

## 🔴 Punto Crítico #3: Validación Débil en `rebuild_chain()`

**Ubicación:** `class-sri-config.php` línea 465-481

**Código actual:**
```php
public static function rebuild_chain() {
    $pems = self::get_cert_pems();
    if ( '' === $pems['cert'] ) {
        return new WP_Error( 'no_cert', ... );
    }

    $chain = self::fetch_ca_chain( $pems['cert'] );
    if ( '' === $chain ) {
        return new WP_Error( 'chain_empty', ... );  // ← Bueno
    }

    // Encriptar y guardar
    $current['chain_pem_enc'] = self::protect_sensitive( $chain );
    update_option( self::OPTION_KEY, $current );
    return true;
}
```

**Lo que está bien:**
- ✓ Verifica que `$chain` NO esté vacío DESPUÉS de obtener
- ✓ Si falla, devuelve WP_Error

**Lo que falta:**
- ❌ No verifica cuántos certificados intermedios se obtuvieron
- ❌ No advierte si solo se obtuvo 1 en lugar de 2-3 esperados
- ❌ No hay forma de "re-intentar" si la descarga fue parcial

---

## ✅ Flujo Correcto Implementado (Partes Que Funcionan)

### Flujo de Guardado (Bien Hecho)
```
1. upload_certificate()
   └─ Archivo P12 sube ✓

2. user abre P12 con password
   └─ read_p12() extrae cert + pkey + chain_interna ✓

3. Si chain_interna está vacía
   └─ fetch_ca_chain() intenta obtener vía AIA ✓

4. save_cert_pems(cert, pkey, chain)
   └─ Encripta con AES-256-GCM ✓
   └─ Guarda en BD opción 'chain_pem_enc' ✓
```

### Flujo de Recuperación (Bien Hecho)
```
1. get_cert_pems()
   └─ Desencripta cert_pem_enc, pkey_pem_enc, chain_pem_enc ✓

2. new SRI_Signer( $cert, $pkey, $chain )
   └─ Almacena en propiedades privadas ✓

3. sign()
   └─ Llama parse_chain_pem() para convertir PEM → array base64 ✓

4. insert_signature_skeleton()
   └─ foreach chain_b64: inserta cada <ds:X509Certificate> ✓
   └─ Resultado: si $chain vacío → foreach no corre, sin error ❌
```

### Flujo de Firma (Correcto Técnicamente)
```
1. C14N del <ds:SignedInfo> ✓
2. openssl_sign() con RSA-SHA256 ✓
3. Insertar en <ds:SignatureValue> ✓
4. Doble-parse para garantizar namespace visibility ✓
5. Devolver XML firmado ✓
```

---

## 🧪 Verificación Manual (Pasos)

### Paso 1: Revisar Logs de Obtención AIA

**Lugar:** `/wp-content/debug.log`

**Buscar líneas de `fetch_ca_chain`:**
```
[AF SRI] fetch_ca_chain: descargando CA intermedia desde https://...
[AF SRI] fetch_ca_chain: CA intermedia obtenida: CN=...
[AF SRI] fetch_ca_chain: cadena obtenida con X certificado(s) intermedio(s).
```

**Interpretación:**
- ✓ Si ves "cadena obtenida con 2 certificado(s)" → cadena OK
- ❌ Si ves "RESULTADO VACÍO" → problema crítico
- ❌ Si ves "sin URL AIA" → certificado no tiene AIA

---

### Paso 2: Verificar Que Cadena Se Guardó

**Query a la base de datos:**
```sql
SELECT 
    option_name,
    CHAR_LENGTH(option_value) as bytes,
    SUBSTRING(option_value, 1, 50) as inicio
FROM wp_options
WHERE option_name = 'af_sri_config'
AND option_value LIKE '%chain_pem_enc%';
```

**Interpretación:**
- ✓ Si `bytes > 100` → cadena guardada
- ❌ Si `bytes < 10` o no aparece → cadena vacía

---

### Paso 3: Verificar Que Se Recupera Correctamente

**Agregar a `admin/views/billing-settings.php` línea ~200:**

```php
$pems = Arriendo_Facil_SRI_Config::get_cert_pems();

echo '<pre>';
echo 'Cert bytes: ' . strlen( $pems['cert'] ) . "\n";
echo 'Pkey bytes: ' . strlen( $pems['pkey'] ) . "\n";
echo 'Chain bytes: ' . strlen( $pems['chain'] ) . "\n";

if ( ! empty( $pems['chain'] ) ) {
    $chain_count = preg_match_all( '/-----BEGIN CERTIFICATE-----/', $pems['chain'] );
    echo "Chain certs: $chain_count\n";
}
echo '</pre>';
```

---

### Paso 4: Inspeccionar XML Firmado

**Ejecutar:**
```php
// En admin área
$pems = Arriendo_Facil_SRI_Config::get_cert_pems();
$signer = new Arriendo_Facil_SRI_Signer( $pems['cert'], $pems['pkey'], $pems['chain'] );
$signed_xml = $signer->sign( $unsigned_xml );

// Contar <ds:X509Certificate> en $signed_xml
$dom = new DOMDocument();
$dom->loadXML( $signed_xml );
$certs = $dom->getElementsByTagNameNS( 'http://www.w3.org/2000/09/xmldsig#', 'X509Certificate' );

echo "Total X509Certificate elements: " . $certs->length;
```

**Interpretación:**
- ✓ Si `length = 2+` → cadena incluida (usuario + intermedio(s))
- ❌ Si `length = 1` → solo usuario, **sin intermediarios**

---

## 🔧 Recomendación de Mejora

### Cambio #1: Validar Cadena CA en `issue_from_payload()`

**Archivo:** `includes/billing/class-billing-manager.php`

**Buscar:** función `issue_from_payload()` alrededor de línea 156

**Cambiar de:**
```php
$pems = Arriendo_Facil_SRI_Config::get_cert_pems();

if ( '' === $pems['cert'] || '' === $pems['pkey'] ) {
    return new WP_Error( 'sri_cert_missing', ... );
}
```

**Cambiar a:**
```php
$pems = Arriendo_Facil_SRI_Config::get_cert_pems();

if ( '' === $pems['cert'] || '' === $pems['pkey'] ) {
    return new WP_Error( 'sri_cert_missing', ... );
}

// NUEVA VALIDACIÓN
if ( '' === $pems['chain'] ) {
    error_log( '[AF] CRÍTICO: Cadena CA vacía. La firma será rechazada por el SRI.' );
    return new WP_Error(
        'sri_chain_missing',
        'Cadena de certificados CA no disponible. Vaya a Facturación > Configuración SRI y haga clic en "Reconstruir cadena CA".'
    );
}
```

### Cambio #2: Agregar Logging en `parse_chain_pem()`

**Archivo:** `includes/billing/class-sri-signer.php`

**Función:** `parse_chain_pem()` línea 590-608

**Agregar después de línea 607:**
```php
// Log para diagnóstico
$cert_count = count( $certs );
error_log( "[AF SRI Signer] Cadena CA procesada: $cert_count certificado(s)" );
if ( 0 === $cert_count ) {
    error_log( "[AF SRI Signer] ⚠️ ADVERTENCIA: Cadena vacía - firma se enviará sin certificados intermedios" );
}
```

---

## 📊 Matriz de Decisión

| Escenario | Síntoma | Causa Probable | Solución |
|-----------|---------|---|---|
| Log dice "RESULTADO VACÍO" | Cadena vacía en BD | Certificado sin AIA | Agregar manualmente cadena en "Cadena CA Manual" |
| Cadena bytes = 0 pero log no lo dice | Silencioso | AIA falló pero no registró bien | Ejecutar `rebuild_chain()` y revisar logs |
| X509Certificate count = 1 | Firma rechazada | Intermediarios no incluidos | Verificar que `chain_pem_enc` no esté vacío |
| FIRMA INVALIDA del SRI | Error 39 | Cadena incompleta o mal formada | Ver "Verificación Manual" Paso 4 |

---

## ✅ Checklist Final

- [ ] Revisar logs de `fetch_ca_chain` en `/wp-content/debug.log`
- [ ] Verificar que cadena se guardó en BD (bytes > 100)
- [ ] Confirmar que recupera sin problemas (step 3 arriba)
- [ ] Inspeccionar XML firmado (step 4 arriba)
- [ ] Si X509Certificate count = 1: ejecutar `rebuild_chain()` o agregar manual
- [ ] Implementar validación en `issue_from_payload()` (Change #1)
- [ ] Re-emitir factura y verificar que SRI acepta

---

**Conclusión:** Tu código XAdES-BES es correcto. El problema radica en que la **cadena CA puede ser vacía sin que haya validación explícita**. Implementar los cambios arriba garantiza que la firma siempre incluya certificados intermedios.
