# 🔧 Diagnóstico Simple - Solo Necesitas Hacer Esto

## El Plan

He agregado logging **MUY detallado** al código. Ahora:

1. **Emites una factura** (como lo haces normalmente)
2. **Lees el debug.log** 
3. **Me compartes lo que ves**

Basándome en eso, encontramos el problema. ¡Simple!

---

## Paso 1: Habilita Debug Log

Abre `/wp-config.php` y verifica que tengas esto:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

**El archivo de log estará en:** `/wp-content/debug.log`

---

## Paso 2: Emite una Factura de Prueba

1. Ve a **Contratos** en WordPress
2. Selecciona un contrato activo
3. Haz clic en **"Emitir Factura"**
4. Completa los datos
5. Haz clic en **"Emitir"**

**Espera a que falle** (como siempre ha fallado)

---

## Paso 3: Lee el Debug Log

Abre `/wp-content/debug.log` con un editor de texto (o por FTP/SFTP)

**Busca la sección:**
```
╔════════════════════════════════════════════════════════════════╗
║              DIAGNÓSTICO COMPLETO DE FACTURA                   ║
╚════════════════════════════════════════════════════════════════╝
```

Eso es lo que necesito ver.

---

## Paso 4: Copia la Sección de Log

La sección importante comienza con:
```
╔════════════════════════════════════════════════════════════════╗
║              DIAGNÓSTICO COMPLETO DE FACTURA                   ║
```

Y termina con:
```
╔════════════════════════════════════════════════════════════════╗
```

**Copia TODO lo que hay entre esas líneas.**

---

## Paso 5: Comparte el Log Conmigo

Pega aquí el contenido que copiaste del debug.log.

---

## Qué Buscaré

Voy a revisar:

| Línea | Qué significa |
|-------|---------------|
| `[1] XML SIN FIRMAR` | El XML que se intenta firmar |
| `[2] CERTIFICADO RECUPERADO` | Si el certificado está vacío, corrupto, etc. |
| `[3] VALIDACIÓN DE CERTIFICADO` | Si el certificado se puede parsear |
| `[4] VALIDACIÓN DE CLAVE PRIVADA` | Si la clave privada es válida |
| `[5] PROCESO DE FIRMA` | Si la firma se ejecuta correctamente |
| `[6] ESTRUCTURA DE FIRMA` | Si los elementos están en el XML firmado |
| `[7] DATOS DEL COMPROBANTE` | El RUC, clave de acceso, etc. |
| `XML QUE SE ENVÍA AL SRI` | El XML exacto que se envía |
| `RESPUESTA DEL SRI` | El error que el SRI devuelve |

---

## Así Se Vería un Log Exitoso

```
[1] XML SIN FIRMAR
    Longitud: 2345 bytes
    Primeras 300 caracteres: <?xml version...

[2] CERTIFICADO RECUPERADO
    Cert bytes: 1234
    Pkey bytes: 1700
    Chain bytes: 1500
    ✓ Certificado válido

[3] VALIDACIÓN DE CERTIFICADO
    ✓ CN Sujeto: DARIO JAVIER CHUQUILLA GUALPA
    ✓ Emisor: UANATACA CA2 2021
    ✓ Vigencia: 12/11/2025 → 11/11/2029

[4] VALIDACIÓN DE CLAVE PRIVADA
    ✓ Clave privada válida

[5] PROCESO DE FIRMA
    ✓ XML firmado exitosamente
    Tamaño XML firmado: 5000 bytes

[6] ESTRUCTURA DE FIRMA
    Elemento <Signature>: ✓ Presente
    Elemento <X509Certificate>: ✓ Presente
    Certificados en firma: 2

[7] DATOS DEL COMPROBANTE
    Clave de acceso: 1706202401...
    RUC emisor: 0912345678001

[8] LISTOS PARA ENVIAR AL SRI
    ✓ Todo validado correctamente

XML QUE SE ENVÍA AL SRI
<?xml version="1.0" encoding="UTF-8"?>
<factura id="comprobante" version="2.1.0">
...

RESPUESTA DEL SRI (RECEPCIÓN)
Estado: RECIBIDA
Mensajes: []
```

---

## Así Se Vería un Log con Problemas

```
[2] CERTIFICADO RECUPERADO
    Cert bytes: 0                    ← ❌ PROBLEMA
    Pkey bytes: 0                    ← ❌ PROBLEMA
    Chain bytes: 0                   ← ⚠️ ADVERTENCIA
    ❌ CRÍTICO: Certificado está VACÍO

→ Esto significa que el certificado se está desencriptando como vacío
```

---

## Próximos Pasos

1. **Verifica que WP_DEBUG esté habilitado**
2. **Emite una factura de prueba**
3. **Lee el debug.log**
4. **Copia la sección de diagnóstico**
5. **Comparte aquí**

---

**¡Eso es! Solo necesito ver ese log.** 🚀
