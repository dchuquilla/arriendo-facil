# 🔴 SOLUCIÓN: FIRMA INVALIDA por P12

**Problema:** SRI rechaza con "Error 39: FIRMA INVALIDA - El certificado firmante no es válido"

**Causa:** El P12 está en formato antiguo o la cadena CA no se extrae correctamente

**Solución:** Convertir P12 al formato compatible y validar cadena CA

---

## ⚡ SOLUCIÓN RÁPIDA (3 pasos)

### Paso 1: Diagnosticar el P12
```bash
# Desde la raíz de tu WordPress
php wp-cli.phar eval-file wp-content/plugins/arriendo-facil/DIAGNOSTICO_P12_DETALLADO.php
```

**Qué buscar:**
- ✅ Si ves "✓ NO SE DETECTARON PROBLEMAS CRÍTICOS" → el P12 es OK
- ❌ Si ves "Certificado vacío", "Clave privada vacía", o "EXPIRADO" → problema crítico

### Paso 2: Convertir P12 (si está en formato antiguo)
```bash
# En CLI (terminal, NO en WordPress)
php wp-content/plugins/arriendo-facil/CONVERTIDOR_P12_VALIDO.php ./cert.p12 "TuContraseña"
```

Esto genera: `cert_convertido.p12`

### Paso 3: Recargar en WordPress
1. Ve a **Facturación → Configuración SRI**
2. Sube el nuevo `cert_convertido.p12`
3. Ingresa contraseña
4. Haz clic en **"Test firma XML"**
5. Ejecuta: `DIAGNOSTICO_CADENA_CA.php` para verificar

---

## 📋 DIAGNÓSTICO DETALLADO

### CASO A: P12 no se extrae (PHP openssl_pkcs12_read falla)

**Síntoma:** DIAGNOSTICO_P12_DETALLADO.php dice "openssl_pkcs12_read() FALLÓ"

**Causa probable:**
1. Contraseña incorrecta
2. P12 en formato PKCS#1 (no PKCS#12 estándar)
3. OpenSSL CLI incompatible

**Solución:**
```bash
# Intenta convertir vía OpenSSL CLI
openssl pkcs12 -in cert.p12 -out cert.pem -nodes -passin pass:"TuContraseña"
openssl pkcs12 -export -in cert.pem -out cert_nuevo.p12 -passout pass:"TuContraseña"
```

Si esto falla → contacta a tu entidad certificadora para re-emitir P12

---

### CASO B: P12 se extrae OK pero cadena CA está vacía

**Síntoma:** 
```
Certificado desencriptado:  ✓ OK (xxx bytes)
Clave privada desencriptada: ✓ OK (xxx bytes)  
Cadena CA desencriptada:    ❌ VACÍO (0 bytes)
```

**Causa:**
1. P12 no incluye certificados intermedios internos
2. AIA no se pudo descargar (sin internet o URL inaccesible)

**Solución:**

#### Opción A: Obtener cadena vía AIA (automático)
```
Facturación → Configuración SRI → [Botón] Reconstruir cadena CA
```

Si esto funciona:
- ✓ Los logs mostrarán "cadena obtenida con X certificado(s)"
- ✓ DIAGNOSTICO_CADENA_CA.php mostrará certificados intermediarios

#### Opción B: Agregar cadena manualmente
1. Obtén la cadena de tu entidad certificadora (BCE, SecurityData, UANATACA, etc.)
2. Ve a **Facturación → Configuración SRI → "Cadena CA Manual"**
3. Pega los certificados PEM concatenados
4. Guarda

---

### CASO C: Cadena CA presente pero aún "FIRMA INVALIDA"

**Síntoma:**
```
Certificados en firma: 2+ (usuario + intermedios)
Pero SRI aún rechaza con "FIRMA INVALIDA"
```

**Causa probable:**
- Certificado UANATACA en ambiente **pruebas** (SRI test no lo reconoce)
- Certificado con Issuer DN en formato incorrecto
- Serial number en formato científico (2.5e+10 en lugar de decimal)

**Soluciones:**

1. **Si es UANATACA:**
   ```
   Facturación → Configuración SRI → Cambiar Ambiente a "2" (Producción)
   O contactar BCE para agregar UANATACA al trust store de pruebas
   ```

2. **Validar Issuer DN:**
   - CORRECTO: `CN=UANATACA,O=UANATACA,C=EC`
   - INCORRECTO: `CN=UANATACA, O=UANATACA, C=EC` (espacios después de comas)
   - El código ya maneja esto: **no es el problema**

3. **Validar serial number:**
   - Los logs mostrarán si hay notación científica
   - El código ya lo previene con GMP/BCMath
   - Ejecuta DIAGNOSTICO_P12_DETALLADO.php y busca "Serial:"

---

## 🔧 HERRAMIENTAS DE DIAGNÓSTICO

### 1. DIAGNOSTICO_P12_DETALLADO.php
**Detecta:** Problemas en la extracción y validez del P12

```bash
php wp-cli.phar eval-file wp-content/plugins/arriendo-facil/DIAGNOSTICO_P12_DETALLADO.php
```

**Qué hace:**
- ✅ Verifica si P12 está cargado
- ✅ Intenta leer con openssl_pkcs12_read() (PHP nativo)
- ✅ Si falla, intenta fallback CLI (OpenSSL 3.x -legacy)
- ✅ Extrae y parsea certificado
- ✅ Valida clave privada
- ✅ Simula firma XAdES-BES
- ✅ Detecta problemas específicos (UANATACA, cert expirado, etc.)

**Output:**
```
✓ Archivo P12 encontrado: cert.p12
  Tamaño: 5234 bytes

✓ openssl_pkcs12_read() exitoso
  Certificado extraído:  2048 bytes
  Clave privada extraída: 1678 bytes
  CA interna en P12: 1 certificado(s)

✓ Certificado parseado correctamente
  CN: EMPRESA S.A.
  O: EMPRESA
  Serial: 1234567890123
  Vigencia: hasta 15/06/2027 ✓ Vigente
  AIA: Sí
```

---

### 2. DIAGNOSTICO_CADENA_CA.php
**Detecta:** Problemas en la cadena CA y su inclusión en la firma

```bash
php wp-cli.phar eval-file wp-content/plugins/arriendo-facil/CLI_DIAGNOSTICO_CADENA.php
```

**Qué hace:**
- ✅ Verifica bytes almacenados en BD
- ✅ Verifica bytes desencriptados
- ✅ Analiza cada certificado intermedio
- ✅ Simula firma y cuenta X509Certificate
- ✅ Detecta si cadena es incompleta

**Output:**
```
📦 ESTADO DE ALMACENAMIENTO
Certificado PEM encriptado:  ✓ PRESENTE (2058 bytes)
Clave privada encriptada:    ✓ PRESENTE (1702 bytes)
Cadena CA encriptada:        ✓ PRESENTE (3456 bytes)

🔗 ANÁLISIS DE CADENA CA
✓ PRESENTE

Certificados intermedios: 2

Detalles:
  [Cert 1] intermediate-uanataca.crt
    CN: UANATACA Intermediate CA
    Emisor: UANATACA Root CA
    Tipo: INTERMEDIA
    Vencimiento: 2028-06-15 ✓ OK

  [Cert 2] uanataca-root.crt
    CN: UANATACA Root CA
    Emisor: UANATACA Root CA
    Tipo: ROOT (autofirmado)
    Vencimiento: 2040-12-31 ✓ OK
```

---

### 3. CONVERTIDOR_P12_VALIDO.php
**Convierte:** P12 antiguo a formato OpenSSL 3.x compatible

```bash
php CONVERTIDOR_P12_VALIDO.php ./cert.p12 "TuContraseña"
```

**Qué hace:**
1. Extrae cert, pkey, chain de P12 antiguo
2. Valida cada componente
3. Re-empaqueta con OpenSSL CLI en formato moderno
4. Valida nuevo P12
5. Genera `cert_convertido.p12`

**Output:**
```
✓ Archivo encontrado: cert.p12
  Tamaño: 5234 bytes
  Contraseña: Mia***

✓ Extracción via PHP exitosa

  Certificado:     2048 bytes
  Clave privada:   1678 bytes
  Cadena CA:       3456 bytes

✓ Certificado válido
  CN: EMPRESA S.A.
  Emisor: UANATACA
  Vigencia: ✓ Vigente (hasta 15/06/2027)

✓ Clave privada válida

✓ P12 nuevo creado: cert_convertido.p12
  Tamaño: 5312 bytes

✓ P12 nuevo pasa validación PHP openssl_pkcs12_read()

✅ CONVERSIÓN EXITOSA

Próximos pasos:
  1. Sube cert_convertido.p12 en Facturación > Configuración SRI
  2. Reconstruir cadena CA si es necesario
  3. Test firma XML
  4. Emitir factura de prueba
```

---

## 📊 ÁRBOL DE DECISIÓN

```
¿Recibe FIRMA INVALIDA?
│
├─→ ¿Logs muestran "Cert bytes: 0"?
│   │
│   ├─→ SÍ: Certificado no se extrajo del P12
│   │       SOLUCIÓN: Sube P12 nuevamente, verifica contraseña
│   │
│   └─→ NO: Certificado OK
│       │
│       └─→ ¿Logs muestran "Chain bytes: 0"?
│           │
│           ├─→ SÍ: Cadena CA está vacía
│           │       SOLUCIÓN: Reconstruir cadena CA (opción A o B)
│           │
│           └─→ NO: Cadena presente
│               │
│               └─→ ¿Ejecuta DIAGNOSTICO_P12_DETALLADO.php?
│                   │
│                   ├─→ "openssl_pkcs12_read FALLÓ"
│                   │   SOLUCIÓN: Convertir con CONVERTIDOR_P12_VALIDO.php
│                   │
│                   └─→ "Certificado UANATACA"
│                       SOLUCIÓN: Cambiar a ambiente producción o
│                                 contactar BCE para agregar al trust store
```

---

## 🚀 CHECKLIST DE IMPLEMENTACIÓN

- [ ] Ejecutar DIAGNOSTICO_P12_DETALLADO.php
- [ ] Revisar la sección de "PROBLEMAS DETECTADOS"
- [ ] Si hay problemas:
  - [ ] Ejecutar CONVERTIDOR_P12_VALIDO.php
  - [ ] Cargar nuevo P12 en Facturación > Config SRI
- [ ] Ejecutar CLI_DIAGNOSTICO_CADENA.php
- [ ] Verificar que "Certificados en firma: 2+" (usuario + intermedios)
- [ ] Si "Certificados en firma: 1":
  - [ ] Ejecutar "Reconstruir cadena CA"
  - [ ] O agregar manualmente vía "Cadena CA Manual"
- [ ] Haz clic en "Test firma XML"
- [ ] Revisa logs para "✓ Todo validado correctamente"
- [ ] Emitir factura de prueba
- [ ] Si SRI aún rechaza:
  - [ ] Si es UANATACA → cambiar a ambiente producción
  - [ ] Si es otra CA → contactar entidad certificadora

---

## 🆘 SI NADA FUNCIONA

1. **Descarga los logs:**
   ```
   /wp-content/debug.log
   ```

2. **Guarda la salida de:**
   ```bash
   php wp-cli.phar eval-file wp-content/plugins/arriendo-facil/DIAGNOSTICO_P12_DETALLADO.php > diagnostico.txt
   php wp-cli.phar eval-file wp-content/plugins/arriendo-facil/CLI_DIAGNOSTICO_CADENA.php >> diagnostico.txt
   ```

3. **Contacta a:**
   - **Tu proveedor de hosting** (para shell_exec, OpenSSL CLI)
   - **Tu entidad certificadora** (para re-emitir P12)
   - **SRI (Banco Central Ecuador)** (para trust store si es UANATACA)

---

## 📝 CAMBIOS DE CÓDIGO IMPLEMENTADOS

Los siguientes cambios ya están implementados en el código:

### Change #1: Validación de Cadena CA en `issue_from_payload()`
**Ubicación:** `class-billing-manager.php` líneas 163-196

✅ **Implementado:** Ahora valida que la cadena CA NO esté vacía antes de firmar
- Si está vacía, intenta reconstruir automáticamente
- Si falla, devuelve error explícito al usuario

### Change #2: Logging de Cadena CA en `parse_chain_pem()`
**Ubicación:** `class-sri-signer.php` después de línea 608

✅ **Implementado:** Loguea cuántos certificados se procesaron
- Si hay 0 certificados, advierte explícitamente
- Los logs mostrarán "X certificado(s) intermedio(s)"

### Change #3: (Adicional) Validación exhaustiva en `issue_from_payload()`
**Ubicación:** `class-billing-manager.php` líneas 252-330

✅ **Implementado:** Logging detallado de todo el proceso
- Verifica cert, pkey, cadena
- Valida estructura de firma
- Cuenta X509Certificate incluidos
- Advierte si solo hay 1 (sin intermediarios)

---

## ✅ CONCLUSIÓN

Tu código XAdES-BES está **técnicamente correcto**. El problema "FIRMA INVALIDA" es casi siempre uno de estos dos:

1. **P12 en formato antiguo** → Usa CONVERTIDOR_P12_VALIDO.php
2. **Cadena CA vacía** → Usa "Reconstruir cadena CA" o agregar manualmente

Con estas herramientas de diagnóstico y conversión, deberías resolver el problema en máximo 30 minutos.

**¡Éxito! 🎉**
