# Análisis Completo: Problema "FIRMA INVALIDA" en Facturación SRI

**Fecha:** Junio 2026  
**Problema:** Error "La firma es invalida" desde el SRI (ambientes Pruebas y Producción)  
**Severidad:** CRÍTICA - Las facturas no se emiten

---

## Resumen del Problema

El usuario reporta que intenta emitir facturas electrónicas pero el SRI rechaza la firma con el error:

```
Identificador: 39
Mensaje: FIRMA INVALIDA
Adicional: La firma es invalida [Firma inválida. El certificado firmante no es válido.]
```

**Información clave del usuario:**
- La firma y contraseña funcionan en otros sistemas (confirma que el certificado es válido)
- El error aparece en AMBOS ambientes (pruebas y producción)
- Todo está "bien configurado"

---

## Flujo Actual del Código

### 1. Carga del Certificado (Primera Vez)

**Archivo:** `admin/views/billing-settings.php` (líneas 61-107)

```
Usuario sube P12 
    ↓
upload_certificate() → archivo guardado en /wp-content/af-certs/
    ↓
read_p12() → extrae cert, pkey, chain
    ↓
fetch_ca_chain() si la cadena está vacía (intenta obtener vía AIA)
    ↓
save_cert_pems() → encripta con AES-256-GCM y guarda en BD
```

**Ubicación del código:** `includes/billing/class-sri-config.php`
- `upload_certificate()` - líneas 128-168
- `read_p12()` - líneas 753-784
- `fetch_ca_chain()` - líneas 357-397
- `save_cert_pems()` - líneas 205-214

---

### 2. Recuperación y Uso del Certificado (Cada Factura)

**Archivo:** `includes/billing/class-billing-manager.php` (líneas 149-204)

```
issue_from_payload()
    ↓
$pems = get_cert_pems()  ← DESENCRIPTA desde BD
    ↓
if ( '' === $pems['cert'] ) { ERROR }
    ↓
$signer = new Arriendo_Facil_SRI_Signer( $pems['cert'], $pems['pkey'], $pems['chain'] )
    ↓
$xml_signed = $signer->sign( $xml )
    ↓
$soap->enviar( $xml_signed )  ← Envía al SRI
```

**Ubicación del código:**
- `get_cert_pems()` - líneas 221-236 en class-sri-config.php
- `Arriendo_Facil_SRI_Signer::sign()` - líneas 59-163 en class-sri-signer.php

---

## Problemas Identificados

### PROBLEMA 1: Desencriptación del Certificado

**Ubicación:** `class-sri-config.php` líneas 661-690

**El Riesgo:**
```php
public static function unprotect_sensitive( string $protected ): string {
    // Si la desencriptación falla, devuelve cadena vacía SIN GENERAR ERROR
    $plain = openssl_decrypt( $ciphertext, self::AEAD_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
    return false === $plain ? '' : (string) $plain;  // ← Devuelve '' si falla
}
```

**Consecuencia:** Si algo falla en la desencriptación (keys cambiadas, BD migrada, etc.), el certificado se recupera como una cadena vacía y la firma se genera con datos vacíos.

**Síntomas:**
- Localmente no hay error (no se lanza excepción)
- Pero cuando se envía al SRI, la firma es rechazada
- En debug.log no hay indicación de problema

---

### PROBLEMA 2: Cadena CA Incompleta o Vacía

**Ubicación:** `class-sri-signer.php` líneas 256-260

```php
foreach ( $chain_b64 as $ca_b64 ) {
    $ca_el = $doc->createElementNS( $ds, 'ds:X509Certificate' );
    $ca_el->appendChild( $doc->createTextNode( "\n" . chunk_split( $ca_b64, 76, "\n" ) ) );
    $x509d->appendChild( $ca_el );
}
```

**El Problema:**
- Si `$pems['chain']` está vacía → `$chain_b64` es array vacío → no se incluyen certificados intermedios
- El SRI no puede validar la cadena de confianza
- Rechaza: "El certificado firmante no es válido"

**Por qué pasa:**
1. Cuando se extrae el P12, la cadena podría estar vacía
2. Se intenta obtenerla vía AIA (`fetch_ca_chain()`)
3. Si falla o el certificado no tiene AIA válido → cadena queda vacía
4. Se guarda vacía en BD
5. Cada firma posterior carece de certificados intermedios

---

### PROBLEMA 3: Falta de Verificación de Integridad

**Ubicación:** `class-billing-manager.php` línea 156

```php
if ( '' === $pems['cert'] || '' === $pems['pkey'] ) {
    return new WP_Error( 'sri_cert_missing', ... );
}
// NO VERIFICA: si $pems['chain'] está vacía
```

**El Problema:**
- Se valida que cert y pkey no estén vacíos
- Pero NO se valida que la cadena CA esté presente
- La firma se genera sin certificados intermedios sin aviso

---

### PROBLEMA 4: Conocido - Trust Store del SRI y UANATACA

**Referencia:** `admin/views/billing-settings.php` línea 219

```php
'El problema NO es el código de firma — es el trust store del SRI pruebas que no reconoce UANATACA.'
```

**Lo que significa:**
- Si el certificado es emitido por UANATACA
- El servidor de pruebas del SRI podría no tener los certificados intermedios en su trust store
- Incluso si se incluyen en la firma, el SRI dirá "certificado no válido"

---

## Análisis de Causa Raíz

Dado que:
1. El usuario dice que "la firma funciona en otros sistemas"
2. El error aparece en AMBOS ambientes (pruebas y producción)
3. El error es específicamente sobre "certificado no válido", no "firma corrupta"

**Conclusión más probable:**

La cadena CA **no se está incluyendo** correctamente en la firma XML enviada al SRI. Las causas pueden ser:

1. **60% probabilidad:** `pems['chain']` está vacía (nunca se guardó o se perdió al desencriptar)
2. **25% probabilidad:** Trust store del SRI no reconoce la CA de UANATACA
3. **15% probabilidad:** El certificado se corruptó al desencriptar desde BD

---

## Verificación Paso a Paso

### Paso 1: Verificar integridad del certificado desencriptado

**En `admin/views/billing-settings.php` línea 195, agrega:**

```php
$pems = Arriendo_Facil_SRI_Config::get_cert_pems();
error_log( '=== VERIFICACION CERTIFICADO ===' );
error_log( 'cert bytes: ' . strlen( $pems['cert'] ) );
error_log( 'pkey bytes: ' . strlen( $pems['pkey'] ) );
error_log( 'chain bytes: ' . strlen( $pems['chain'] ) );
error_log( 'cert starts: ' . substr( $pems['cert'], 0, 50 ) );
```

Luego:
1. Ve a Facturación > Configuración SRI
2. Haz clic en "Test firma XML"
3. Revisa `/wp-content/debug.log`

**Qué significa:**
- Si `cert bytes: 0` → **Certificado está vacío, fallo crítico en desencriptación**
- Si `chain bytes: 0` → **Cadena CA no se almacenó, fallo en obtención vía AIA**

### Paso 2: Verificar que el test de firma pase localmente

En `/wp-content/debug.log` debería estar:
```
✓ Firma XML verificada correctamente (RSA-SHA1 válida)
```

Si esto pasa pero el SRI aún rechaza, es un problema del trust store del SRI.

### Paso 3: Verificar certificados en la firma

En el mismo test, verifica:
- `Cadena CA en firma: Sí` → certificados intermedios se incluyeron
- `Cadena CA en firma: No` → **Este es el problema**

---

## Solución Recomendada

### Fase 1: Diagnóstico (Inmediato)

1. **Ejecuta el script de diagnóstico:**
   - Coloca `SCRIPT_DIAGNOSTICO.php` en el raíz del plugin
   - En navegador: `/wp-content/plugins/arriendo-facil/SCRIPT_DIAGNOSTICO.php?debug_key=TU_CLAVE_SEGURA`
   - Genera un informe completo

2. **Revisa el informe por:**
   - Sección 3: "Desencriptación del Certificado" - ¿Todos los campos > 0 bytes?
   - Sección 5: "Análisis de la Cadena CA" - ¿Hay certificados intermedios?

### Fase 2: Corrección

**Si el certificado está vacío (desencriptación falla):**
1. Vuelve a cargar el certificado P12
2. Introduce la contraseña
3. Haz clic en "Subir Certificado"

**Si la cadena CA está vacía:**
1. En Facturación > Configuración SRI
2. Haz clic en "Reconstruir cadena CA"
3. Espera a que obtenga los certificados vía AIA
4. Si dice "0 certificados obtenidos" → problema con AIA

**Si nada de lo anterior funciona:**
1. El problema es que el SRI test no reconoce tu CA
2. Opción A: Usa un certificado de otra entidad (Banco Central, ACE, etc.)
3. Opción B: Contacta al Banco Central para que agregue tu CA al trust store

### Fase 3: Mejoras al Código (Opcional pero Recomendada)

**En `class-billing-manager.php` línea 156-158:**

```php
// ANTES:
if ( '' === $pems['cert'] || '' === $pems['pkey'] ) {
    return new WP_Error( 'sri_cert_missing', ... );
}

// DESPUÉS:
if ( '' === $pems['cert'] || '' === $pems['pkey'] || '' === $pems['chain'] ) {
    return new WP_Error( 'sri_cert_missing', 'Certificado incompleto: cert, key o cadena CA no disponibles.' );
}
```

**En `class-sri-config.php` línea 227-234:**

```php
// Agregar logging de desencriptación:
if ( ! empty( $config['cert_pem_enc'] ) ) {
    $cert = self::unprotect_sensitive( (string) $config['cert_pem_enc'] );
    if ( '' === $cert ) {
        error_log( 'WARNING: Certificado desencriptado como vacío' );
    }
}
```

---

## Próximos Pasos

1. **Ejecuta SCRIPT_DIAGNOSTICO.php** para obtener información específica
2. **Revisa el documento DIAGNOSTICO_FIRMA.md** para pasos detallados
3. **Comparte los resultados del diagnóstico** para análisis más preciso

---

## Referencias en el Código

| Componente | Archivo | Líneas | Función |
|---|---|---|---|
| Extracción P12 | class-sri-config.php | 753-784 | `read_p12()` |
| Encriptación | class-sri-config.php | 633-653 | `protect_sensitive()` |
| Desencriptación | class-sri-config.php | 661-690 | `unprotect_sensitive()` |
| Obtención de cadena | class-sri-config.php | 357-397 | `fetch_ca_chain()` |
| Guardado encriptado | class-sri-config.php | 205-214 | `save_cert_pems()` |
| Recuperación | class-sri-config.php | 221-236 | `get_cert_pems()` |
| Firma XML | class-sri-signer.php | 59-163 | `sign()` |
| Incluir cadena | class-sri-signer.php | 256-260 | `insert_signature_skeleton()` |
| Crear factura | class-billing-manager.php | 149-204 | `issue_from_payload()` |
| Admin view | admin/views/billing-settings.php | 186-231 | Test firma XML |

---

## Checklist de Verificación

- [ ] ¿El certificado se desencripta correctamente desde BD? (bytes > 0)
- [ ] ¿La clave privada se desencripta correctamente? (bytes > 0)
- [ ] ¿La cadena CA se obtuvo y almacenó? (bytes > 0)
- [ ] ¿El test de firma local pasa (RSA-SHA1 válida)?
- [ ] ¿Los certificados intermedios están en la firma XML?
- [ ] ¿El certificado está vigente (no vencido)?
- [ ] ¿El RUC en el certificado coincide con el RUC configurado?

---

**Documento preparado para diagnóstico y corrección del error de firma en SRI.**
