# Implementación de la Solución - Instrucciones Paso a Paso

## ✅ Cambios Realizados

Se han actualizado los siguientes archivos:

### 1. `includes/billing/class-sri-config.php`
- ✅ Nueva función `clean_pem_output()` - Limpia y valida PEMs extraídos
- ✅ Nueva función `clean_chain_output()` - Procesa cadena CA correctamente
- ✅ Mejora en escritura de archivo temporal de contraseña
- ✅ Las funciones antiguas ahora usan las nuevas funciones

### 2. `includes/billing/class-billing-manager.php`
- ✅ Logging de diagnóstico mejorado
- ✅ Validación de certificado y clave antes de firmar
- ✅ Mensajes de error más informativos

---

## 📋 Pasos para Aplicar la Solución

### Paso 1: Recarga del Certificado (CRÍTICO)

**Ahora que hemos arreglado la extracción del P12, necesitas recargar tu certificado:**

1. Ve a **Facturación > Configuración SRI**
2. En la sección **"Certificado Digital (P12)"**, mira el estado actual
3. **IMPORTANTE:** Descarga nuevamente tu archivo P12 del Banco Central o entidad certificadora
4. Haz clic en **"Subir Certificado"**
5. Selecciona el archivo P12
6. Ingresa la contraseña
7. Haz clic en **"Subir Certificado"**

**Espera a que termine** - Verás un mensaje de éxito

---

### Paso 2: Verificación Post-Carga

Una vez cargado el certificado:

1. Haz clic en **"Verificar certificado"**
   - Debe pasar sin errores
   - Verifica: "✓ Certificado válido"

2. Haz clic en **"Reconstruir cadena CA"**
   - Debe obtener certificados intermedios
   - Verifica que diga "Se obtuvieron X certificado(s) intermedio(s)"

3. Haz clic en **"Test firma XML"**
   - Debe pasar sin errores
   - Verifica: "✓ Firma XML verificada correctamente"

Si todos los pasos pasan, procede al Paso 3.

---

### Paso 3: Emite una Factura de Prueba

1. Ve a **Contratos**
2. Selecciona un contrato activo
3. Haz clic en **"Emitir Factura"**
4. Completa los datos
5. Haz clic en **"Emitir"**

**Espera el resultado:**
- ✅ Si se autoriza → ¡Problema resuelto!
- ❌ Si falla → Revisa el debug.log

---

### Paso 4: Revisar Debug Log (Si falla)

Si algo falla, consulta `/wp-content/debug.log`:

```bash
tail -100 /wp-content/debug.log
```

**Busca la sección:**
```
=== Diagnóstico de Firma ===
Cert bytes: [número]
Pkey bytes: [número]
Chain bytes: [número]
```

**Interpreta los resultados:**

| Resultado | Significado |
|-----------|-------------|
| `Cert bytes: 0` | ❌ El certificado está vacío - Vuelve a cargarlo |
| `Pkey bytes: 0` | ❌ La clave privada está vacía - Vuelve a cargarla |
| `Chain bytes: 0` | ⚠️ Sin cadena CA - Haz "Reconstruir cadena CA" |
| Todos > 0 | ✅ Los datos se cargaron correctamente |

---

## 🧪 Testing Completo

Después de los cambios, ejecuta esta secuencia de pruebas:

### Test 1: Verificación de Certificado
```
Facturación > Configuración SRI
Haz clic: "Verificar certificado"
Resultado esperado: ✓ Certificado válido
```

### Test 2: Reconstrucción de Cadena CA
```
Facturación > Configuración SRI
Haz clic: "Reconstruir cadena CA"
Resultado esperado: Se obtuvieron X certificado(s) intermedio(s)
```

### Test 3: Firma XML
```
Facturación > Configuración SRI
Haz clic: "Test firma XML"
Resultado esperado: ✓ Firma XML verificada correctamente (RSA-SHA1 válida)
```

### Test 4: Emisión Real
```
Contratos > Selecciona un contrato > Emitir Factura
Resultado esperado: Comprobante autorizado por SRI
```

---

## 🚨 Troubleshooting

### Problema: "El certificado está vacío" (Cert bytes: 0)

**Causa:** La extracción desde el P12 falló

**Solución:**
1. Verifica que el P12 es correcto
2. Verifica que la contraseña es exacta (incluyendo mayúsculas, espacios)
3. Prueba descargar el P12 nuevamente desde el Banco Central
4. Vuelve a cargarlo

### Problema: "Chain bytes: 0" pero todo lo demás funciona

**Causa:** La cadena CA no se obtuvo automáticamente

**Solución:**
1. Haz clic en **"Reconstruir cadena CA"**
2. Si sigue en 0, significa que el certificado no tiene AIA
3. Contacta al Banco Central para obtener la cadena CA manualmente

### Problema: "Firma verificada localmente pero SRI rechaza"

**Causa:** El SRI no reconoce tu CA (problema de trust store del SRI)

**Solución:**
1. Si estás en pruebas, cambia a PRODUCCIÓN
2. Si ya estás en producción, contacta al Banco Central
3. O usa un certificado de otra AC (Banco Pacífico, ACE, etc.)

---

## 📊 Cambios Técnicos Resumidos

### Antes
```php
// Podría devolver PEM con whitespace incorrecto
$cert_pem = self::extract_pem_block( $cert_output, 'CERTIFICATE' );
```

### Ahora
```php
// Limpia, normaliza y valida el PEM
$cert_pem = self::clean_pem_output( $cert_output, 'CERTIFICATE' );
// - Normaliza line endings (\r\n → \n)
// - Elimina whitespace al inicio/final
// - Valida que sea un PEM válido
// - Devuelve cadena vacía si falla (en lugar de PEM corrupto)
```

### Beneficios
✅ Certificados extraídos más limpios y seguros  
✅ Validación inmediata de PEM  
✅ Mejor manejo de contraseñas especiales  
✅ Logging mejorado para diagnóstico  
✅ Detección temprana de problemas  

---

## ✨ Próximos Pasos

1. **Recarga el certificado** (es lo más importante)
2. **Verifica con los botones de test**
3. **Intenta emitir una factura**
4. **Comparte el resultado**

Si tienes problemas, **comparte el contenido de `/wp-content/debug.log`** después de intentar emitir.

---

## 📞 Soporte Técnico

Si algo no funciona después de estos cambios:

1. **Revisa debug.log** - Te dirá exactamente qué falló
2. **Verifica la contraseña** - Asegúrate de que es correcta
3. **Recarga el certificado** - A veces es necesario hacerlo dos veces
4. **Contacta al Banco Central** - Si el SRI sigue rechazando

---

**¡Los cambios están listos! Ahora recarga tu certificado y prueba.**
