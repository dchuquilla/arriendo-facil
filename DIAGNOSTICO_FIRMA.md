# Diagnóstico: Error de Firma Inválida en SRI

## Problema Reportado
```
Detalle error: [{"identificador":"39","mensaje":"FIRMA INVALIDA","tipo":"ERROR","adicional":"La firma es invalida [Firma inválida. El certificado firmante no es válido.]"}]
```

**Ambientes afectados:** Pruebas y Producción

## Causas Identificadas

### 1. ❌ Corrupción del Certificado en Encriptación/Desencriptación
**Ubicación:** `class-sri-config.php` líneas 633-690

**Problema:** 
- Los certificados PEM se encriptan con AES-256-GCM al guardar en BD
- Se desencriptan cuando se van a usar
- Si hay un problema en este ciclo, el certificado se corrompe sin generar error

**Síntoma:** El certificado se ve bien en la BD (campo encrypted), pero al recuperarse está vacío o corrupto

**Verificación en `admin/views/billing-settings.php` línea 195:**
```php
$signer = new Arriendo_Facil_SRI_Signer( $pems['cert'], $pems['pkey'], $pems['chain'] );
```
Si cualquiera de estos es una cadena vacía, la firma será rechazada.

---

### 2. ❌ Cadena CA (Certificate Chain) Vacía o Incompleta
**Ubicación:** `class-billing-manager.php` línea 200

**Problema:**
```php
$signer = call_user_func( $this->signer_factory, $pems['cert'], $pems['pkey'], $pems['chain'] ?? '' );
```

Si `$pems['chain']` está vacía, el XML firmado NO incluirá los certificados intermedios. El SRI no puede validar la cadena de confianza.

**Síntoma:** La firma se ve correcta localmente, pero el SRI dice "El certificado firmante no es válido"

**Ubicación en firma:** `class-sri-signer.php` líneas 256-260
```php
foreach ( $chain_b64 as $ca_b64 ) {
    $ca_el = $doc->createElementNS( $ds, 'ds:X509Certificate' );
    $ca_el->appendChild( $doc->createTextNode( "\n" . chunk_split( $ca_b64, 76, "\n" ) ) );
    $x509d->appendChild( $ca_el );
}
```

---

### 3. ⚠️ Problema Conocido: Trust Store del SRI Test y UANATACA
**Referencia:** `admin/views/billing-settings.php` línea 219

Hay un comentario que dice:
```
"El problema NO es el código de firma — es el trust store del SRI pruebas que no reconoce UANATACA."
```

**Problema:** Si tu certificado es de UANATACA, el servidor de pruebas del SRI podría no tener en su trust store los certificados intermedios de UANATACA.

---

### 4. ❌ Certificado Vencido
**Ubicación:** `class-sri-config.php` líneas 280-295

Un certificado vencido causará rechazo inmediato del SRI.

---

## Plan de Diagnóstico

### Paso 1: Verificar si el certificado se corrompe en BD

Edita la vista `admin/views/billing-settings.php` línea 189-195:

```php
} else {
    try {
        $test_xml = '<?xml version="1.0" encoding="UTF-8"?><factura id="comprobante" version="2.1.0"><infoTributaria><ambiente>1</ambiente><razonSocial>TEST</razonSocial></infoTributaria></factura>';
        
        // AGREGAR ESTO PARA DIAGNOSTICAR:
        $pems = Arriendo_Facil_SRI_Config::get_cert_pems();
        error_log('=== DIAGNOSTICO CERTIFICADO ===');
        error_log('cert length: ' . strlen($pems['cert']));
        error_log('pkey length: ' . strlen($pems['pkey']));
        error_log('chain length: ' . strlen($pems['chain']));
        error_log('cert first 100 chars: ' . substr($pems['cert'], 0, 100));
        error_log('pkey first 100 chars: ' . substr($pems['pkey'], 0, 100));
        error_log('chain first 100 chars: ' . substr($pems['chain'], 0, 100));
        
        $signer   = new Arriendo_Facil_SRI_Signer( $pems['cert'], $pems['pkey'], $pems['chain'] );
        $signed   = $signer->sign( $test_xml );
        // ... resto del código
```

Verifica `/wp-content/debug.log` después de hacer clic en "Test firma XML". Busca "DIAGNOSTICO CERTIFICADO".

**Qué significa:**
- Si `cert length: 0` o `pkey length: 0` → **El certificado se está corrompiendo al desencriptar**
- Si `chain length: 0` → **La cadena CA no se guardó correctamente**

---

### Paso 2: Verificar si el certificado original es válido

Ve a **Facturación > Configuración SRI** y hace clic en **"Verificar certificado"**.

**Qué buscar:**
- "Vigencia: XX/XX/XXXX → XX/XX/XXXX" - ¿Es la fecha futura?
- "RUC/Serial en cert:" - ¿Coincide con tu RUC configurado?
- "Key Usage:" - ¿Dice "digitalSignature" o similar?
- "CA chain:" - ¿Dice más de 0 certificados intermedios?

Si dice **"CA chain: 0 certificados"**, haz clic en **"Reconstruir cadena CA"**.

---

### Paso 3: Verificar la firma local

Haz clic en **"Test firma XML"** y busca en `/wp-content/debug.log`:

```
✓ Firma XML verificada correctamente (RSA-SHA1 válida)
```

Si dice esto y el problema persiste en el SRI, es un problema de **trust store del SRI, no del código**.

---

## Solución Probable

Basándome en tu descripción ("la firma funciona en otros sistemas"), creo que el problema es:

### **La cadena CA no se está incluyendo correctamente en la firma XML enviada al SRI**

**Por qué:**
1. El certificado se extrae bien del P12
2. La firma local es válida (según el test)
3. Pero el SRI dice que el certificado no es válido
4. Esto ocurre en ambos ambientes

**Solución:**

Necesitas **forzar la inclusión de la cadena CA completa**. Haz lo siguiente:

1. **En Facturación > Configuración SRI:**
   - Haz clic en **"Reconstruir cadena CA"**
   - Verifica que diga "Se obtuvieron X certificado(s) intermedio(s)"

2. **Si sigue sin funcionar:**
   - El problema es que el SRI no tiene en su trust store el certificado emisor
   - Necesitas contactar al Banco Central / SRI para actualizar su trust store
   - O usar un certificado de una entidad de certificación que esté en el trust store del SRI

---

## Cambios de Código Recomendados

Si después del diagnóstico confirmas que el problema es la cadena CA vacía, aquí hay un fix:

**En `includes/billing/class-billing-manager.php` línea 200:**

```php
// ANTES:
$signer = call_user_func( $this->signer_factory, $pems['cert'], $pems['pkey'], $pems['chain'] ?? '' );

// DESPUÉS:
$chain = isset( $pems['chain'] ) && '' !== $pems['chain'] ? $pems['chain'] : '';
if ( '' === $chain ) {
    // Intentar obtener la cadena si no existe
    $chain = Arriendo_Facil_SRI_Config::fetch_ca_chain( $pems['cert'] );
}
$signer = call_user_func( $this->signer_factory, $pems['cert'], $pems['pkey'], $chain );
```

---

## Verificación Final

Una vez hayas verificado todo, intenta hacer:

1. Ir a **Facturación > Configuración SRI**
2. Hacer clic en **"Verificar certificado"** - debe decir ✓ Certificado válido
3. Hacer clic en **"Test firma XML"** - debe decir ✓ Firma XML verificada correctamente
4. Luego intentar **emitir una factura**

Si los pasos 2 y 3 pasan pero el paso 4 falla con el mismo error, el problema es el trust store del SRI.

