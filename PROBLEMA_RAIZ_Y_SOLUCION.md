# Problema de Raíz: Análisis y Solución

## Hallazgos Críticos

Después de analizar el código completo, he identificado **EL PROBLEMA REAL**:

### El Certificado Extraído del P12 Probablemente Tiene un Problema de Formato/Encoding

**Ubicación:** `class-sri-config.php` líneas 810-828

```php
// COMANDO 1: Extrae el certificado
$cert_cmd = sprintf(
    'openssl pkcs12 -in %s -passin file:%s -clcerts -nokeys %s 2>&1',
    $escaped_path,
    $escaped_pass_file,
    $flags
);

// COMANDO 2: Extrae la clave privada  
$key_cmd = sprintf(
    'openssl pkcs12 -in %s -passin file:%s -nocerts -nodes %s 2>&1',
    $escaped_path,
    $escaped_pass_file,
    $flags
);
```

---

## El Problema Específico

### 1️⃣ Flag `-clcerts` Puede Ser Problemático

El flag `-clcerts` extrae solo certificados "client". Para firma digital tributaria, algunos sistemas pueden necesitar extraer el certificado COMPLETO, no solo el cliente.

**Problema potencial:**
```
openssl pkcs12 -in cert.p12 -passin file:pass.txt -clcerts -nokeys
```

Esto podría devolver un certificado incompleto si el P12 tiene una estructura especial.

### 2️⃣ Clave Privada con `-nodes` Podría Causar Problemas

El flag `-nodes` no encripta la clave privada en la salida. Pero si se añade whitespace o newlines incorrectos, podría corromper la clave.

**Lo que ocurre:**
```bash
openssl pkcs12 -in cert.p12 -passin file:pass.txt -nocerts -nodes
```

Esto devuelve la clave sin cifrado. Pero si hay caracteres especiales o encoding issues, el resultado podría ser inválido.

### 3️⃣ Extracción de PEM Podría Perder Información

**Ubicación:** `class-sri-config.php` líneas 939-950

```php
private static function extract_pem_block( string $output, string $type ): string {
    $begin = "-----BEGIN {$type}-----";
    $end   = "-----END {$type}-----";
    $start = strpos( $output, $begin );
    if ( false === $start ) {
        return '';
    }
    $finish = strpos( $output, $end, $start );
    if ( false === $finish ) {
        return '';
    }
    return substr( $output, $start, $finish - $start + strlen( $end ) );
}
```

**Problema:** 
- Si el comando devuelve múltiples certificados (cert + intermedias), solo extrae el PRIMERO
- Si el resultado tiene whitespace antes del BEGIN, podría no encontrarse
- Si hay errores del CLI, no los maneja

---

## La Verdadera Causa

Basándome en que:
1. ✓ El certificado funciona en otros sistemas
2. ✓ El test de firma local PASA
3. ✓ La cadena CA se obtiene correctamente
4. ✗ Pero SRI rechaza con "certificado no válido"

**Conclusión:** El PEM del certificado que se está extrayendo tiene un problema que NO afecta la firma local (porque usa el mismo PEM), pero SÍ afecta la verificación del SRI.

**Posibilidades:**
- Whitespace extra al inicio/final del PEM
- Líneas adicionales que no son parte del certificado
- Caracteres especiales en el PEM (BOM, etc.)
- Newlines incorrectos en la mitad del certificado
- El comando está capturando advertencias o errores antes/después del PEM

---

## Solución: Mejora en la Extracción de P12

Voy a proporcionar un **fix específico** para mejorar la extracción y manejo del certificado:

### Paso 1: Crear una Nueva Función de Extracción Robusta

En `class-sri-config.php` después de la función `read_p12_legacy_cli()`, agrega:

```php
/**
 * Limpia y valida un PEM extraído del CLI.
 * Elimina whitespace, advertencias, y errores.
 *
 * @param string $pem_raw Raw output from openssl CLI.
 * @param string $type PEM type ('CERTIFICATE' or 'PRIVATE KEY').
 * @return string Clean PEM or empty string.
 */
private static function clean_pem_output( string $pem_raw, string $type ): string {
    $begin = "-----BEGIN {$type}-----";
    $end   = "-----END {$type}-----";
    
    // Buscar el BEGIN del PEM
    $start = strpos( $pem_raw, $begin );
    if ( false === $start ) {
        error_log( "WARNING: No se encontró 'BEGIN {$type}' en output" );
        return '';
    }
    
    // Buscar el END del PEM
    $finish = strpos( $pem_raw, $end, $start );
    if ( false === $finish ) {
        error_log( "WARNING: No se encontró 'END {$type}' en output" );
        return '';
    }
    
    // Extrae exactamente desde BEGIN hasta END (inclusive)
    $pem = substr( $pem_raw, $start, $finish - $start + strlen( $end ) );
    
    // Normaliza line endings (todos los \r\n a \n)
    $pem = str_replace( "\r\n", "\n", $pem );
    $pem = str_replace( "\r", "\n", $pem );
    
    // Divide en líneas y limpia
    $lines = explode( "\n", $pem );
    $clean_lines = array();
    
    foreach ( $lines as $line ) {
        $line = trim( $line );
        // Solo incluye líneas que no estén vacías
        if ( ! empty( $line ) ) {
            $clean_lines[] = $line;
        }
    }
    
    // Reconstruye el PEM
    $clean_pem = implode( "\n", $clean_lines ) . "\n";
    
    // Valida que sea un PEM válido
    if ( 'CERTIFICATE' === $type ) {
        $parsed = openssl_x509_parse( $clean_pem );
        if ( false === $parsed ) {
            error_log( "WARNING: El certificado extraído no es válido: " . openssl_error_string() );
            return '';
        }
    } elseif ( 'PRIVATE KEY' === $type || 'RSA PRIVATE KEY' === $type ) {
        $parsed = openssl_pkey_get_private( $clean_pem );
        if ( false === $parsed ) {
            error_log( "WARNING: La clave privada extraída no es válida: " . openssl_error_string() );
            return '';
        }
    }
    
    return $clean_pem;
}
```

### Paso 2: Reemplaza la Función `extract_pem_block()`

Cambia el uso de `extract_pem_block()` por `clean_pem_output()` en `read_p12_legacy_cli()`:

**Líneas 842-849, ANTES:**
```php
$cert_pem = self::extract_pem_block( $cert_output, 'CERTIFICATE' );
$key_pem  = self::extract_pem_block( $key_output, 'PRIVATE KEY' );

if ( '' !== $cert_pem && '' !== $key_pem ) {
    $chain_pem = '';
    if ( null !== $chain_output ) {
        $chain_pem = self::extract_all_pem_blocks( $chain_output, 'CERTIFICATE' );
    }
```

**DESPUÉS:**
```php
$cert_pem = self::clean_pem_output( $cert_output, 'CERTIFICATE' );
$key_pem  = self::clean_pem_output( $key_output, 'PRIVATE KEY' );

if ( '' !== $cert_pem && '' !== $key_pem ) {
    $chain_pem = '';
    if ( null !== $chain_output ) {
        // Para la cadena, limpia cada certificado
        $chain_pem = self::clean_chain_output( $chain_output );
    }
```

### Paso 3: Mejora la Extracción de la Cadena CA

Agrega una nueva función para limpiar la cadena CA:

```php
/**
 * Limpia y valida certificados de la cadena CA.
 *
 * @param string $chain_output Raw output from openssl CLI.
 * @return string Cleaned PEM chain or empty string.
 */
private static function clean_chain_output( string $chain_output ): string {
    $begin = "-----BEGIN CERTIFICATE-----";
    $end   = "-----END CERTIFICATE-----";
    
    $chain_certs = array();
    $offset = 0;
    
    while ( false !== ( $start = strpos( $chain_output, $begin, $offset ) ) ) {
        $finish = strpos( $chain_output, $end, $start );
        if ( false === $finish ) {
            break;
        }
        
        // Extrae un certificado
        $cert_pem = substr( $chain_output, $start, $finish - $start + strlen( $end ) );
        
        // Normaliza line endings
        $cert_pem = str_replace( "\r\n", "\n", $cert_pem );
        $cert_pem = str_replace( "\r", "\n", $cert_pem );
        
        // Divide en líneas y limpia
        $lines = explode( "\n", $cert_pem );
        $clean_lines = array();
        
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( ! empty( $line ) ) {
                $clean_lines[] = $line;
            }
        }
        
        $clean_cert = implode( "\n", $clean_lines ) . "\n";
        
        // Valida que sea certificado válido
        $parsed = openssl_x509_parse( $clean_cert );
        if ( false !== $parsed ) {
            $chain_certs[] = $clean_cert;
        } else {
            error_log( "WARNING: Certificado inválido en cadena: " . openssl_error_string() );
        }
        
        $offset = $finish + strlen( $end );
    }
    
    return implode( "\n", $chain_certs );
}
```

### Paso 4: Mejora la Manejo de Contraseñas Especiales

En `read_p12_legacy_cli()`, línea 800, asegúrate de que la contraseña se escribe correctamente:

**Línea 800, ANTES:**
```php
file_put_contents( $pass_file, $password );
```

**DESPUÉS:**
```php
$bytes_written = file_put_contents( $pass_file, $password, LOCK_EX );
if ( false === $bytes_written || $bytes_written !== strlen( $password ) ) {
    @unlink( $pass_file );
    return new WP_Error( 
        'tmp_write_error', 
        __( 'No se pudo escribir la contraseña en el archivo temporal.', 'arriendo-facil' )
    );
}
```

---

## Instalación de la Solución

1. **Abre:** `includes/billing/class-sri-config.php`

2. **Ubica:** La función `read_p12_legacy_cli()` (línea ~795)

3. **Después de esa función** (antes del final de la clase), agrega las dos nuevas funciones:
   - `clean_pem_output()`
   - `clean_chain_output()`

4. **Modifica:** Los usos de `extract_pem_block()` por `clean_pem_output()`

5. **Modifica:** El uso de `extract_all_pem_blocks()` por `clean_chain_output()`

6. **Mejora:** La escritura de archivo temporal de contraseña

7. **Guarda** y **prueba**

---

## Prueba la Solución

Después de hacer los cambios:

1. **Vuelve a subir el certificado P12**
2. **Ve a Facturación > Configuración SRI**
3. **Haz clic en "Verificar certificado"** - debe pasar
4. **Haz clic en "Test firma XML"** - debe pasar
5. **Intenta emitir una factura**

---

## Debug para Verificar

Si aún falla, agrega esto en `includes/billing/class-billing-manager.php` línea 151:

```php
$pems = Arriendo_Facil_SRI_Config::get_cert_pems();

// DEBUG
error_log( '=== DEBUG P12 EXTRACTION ===' );
error_log( 'Cert PEM first 200 chars: ' . substr( $pems['cert'], 0, 200 ) );
error_log( 'Cert PEM last 100 chars: ' . substr( $pems['cert'], -100 ) );
error_log( 'Pkey PEM first 200 chars: ' . substr( $pems['pkey'], 0, 200 ) );
error_log( 'Chain length: ' . strlen( $pems['chain'] ) );

// Verify cert can be parsed
$cert_info = openssl_x509_parse( $pems['cert'] );
if ( false === $cert_info ) {
    error_log( 'ERROR: Cert PEM cannot be parsed! ' . openssl_error_string() );
}

// Verify key can be used
$key = openssl_pkey_get_private( $pems['pkey'] );
if ( false === $key ) {
    error_log( 'ERROR: Pkey PEM cannot be parsed! ' . openssl_error_string() );
}
error_log( '=== END DEBUG ===' );
```

Luego intenta emitir y revisa `/wp-content/debug.log`.

---

## Resumen de los Cambios

| Cambio | Ubicación | Razón |
|--------|-----------|-------|
| Nueva función `clean_pem_output()` | After `read_p12_legacy_cli()` | Limpia whitespace y valida PEM |
| Nueva función `clean_chain_output()` | After `clean_pem_output()` | Limpia cada cert de la cadena |
| Usar `clean_pem_output()` | Line 842-843 | Reemplaza `extract_pem_block()` |
| Usar `clean_chain_output()` | Line 848 | Reemplaza `extract_all_pem_blocks()` |
| Validar escritura temporal | Line 800 | Asegura contraseña se escribe bien |

---

**Esta solución aborda el problema de que el certificado extraído podría tener formatting issues que no afectan el test local, pero sí la validación del SRI.**
