# 🔬 Investigación: Error "FIRMA INVALIDA" Cuando Todo Se Ve Correcto

## El Dilema

Tu certificado:
- ✅ Funciona en OTROS sistemas
- ✅ El test local de firma PASA
- ✅ La cadena CA está INCLUIDA en la firma
- ✅ El certificado está VIGENTE
- ✗ Pero SRI rechaza con "FIRMA INVALIDA"

**Y ahora:** Falla en **AMBOS ambientes** (pruebas y producción)

---

## Análisis: Si No Es la Firma, ¿Qué Es?

Dado que:
1. La firma es criptográficamente correcta (test pasa)
2. El certificado es válido (funciona en otros sistemas)
3. Pero el SRI lo rechaza

**Las posibilidades son:**

### A) El XML que se está firmando es diferente

```
Sistema A (que funciona)    →    XML con estructura X    →    Funciona en SRI
Sistema B (esta app)         →    XML con estructura Y    →    Falla en SRI
```

**Diferencias posibles:**
- Orden diferente de elementos
- Namespaces diferentes
- Valores numéricos con formato diferente
- Fechas en formato diferente
- Caracteres especiales no escapados

### B) El certificado se está incluyendo mal en el XML

Aunque sea válido, podría estar formateado incorrectamente.

### C) Los datos son incorrectos (RUC, fechas, totales)

Aunque la firma sea válida, si los datos están mal, SRI rechaza.

---

## Scripts de Diagnóstico

He creado 2 scripts para investigar esto:

### Script 1: DEBUG_CERTIFICADO_ENVIADO.php

```
URL: /wp-content/plugins/arriendo-facil/DEBUG_CERTIFICADO_ENVIADO.php
```

**Qué verifica:**
- El certificado recuperado de la BD
- El certificado que se incluye en el XML
- Si coinciden exactamente
- Validaciones de formato
- Extensiones requeridas

**Qué buscar en los resultados:**
- Si el DER/Base64 coincide exactamente
- Si tiene "Digital Signature" habilitado
- Si está vigente
- Si hay inconsistencias

### Script 2: DEBUG_XML_GENERADO.php

```
URL: /wp-content/plugins/arriendo-facil/DEBUG_XML_GENERADO.php
```

**Qué verifica:**
- El XML completo generado
- Estructura según especificación SRI
- Validación de cada elemento
- Cálculos de totales
- Formatos de datos

**Qué buscar en los resultados:**
- Si el XML es válido
- Si cumple con especificación SRI
- Si los totales son correctos
- Si los formatos son correctos

---

## Procedimiento de Investigación

### Paso 1: Ejecuta los scripts

1. Abre en navegador:
   ```
   /wp-content/plugins/arriendo-facil/DEBUG_CERTIFICADO_ENVIADO.php
   ```

2. Verifica:
   - ¿El certificado en el XML coincide exactamente?
   - ¿Tiene Digital Signature?
   - ¿Está vigente?

3. Luego abre:
   ```
   /wp-content/plugins/arriendo-facil/DEBUG_XML_GENERADO.php
   ```

4. Verifica:
   - ¿El XML es válido?
   - ¿Cumple con SRI?
   - ¿Los totales son correctos?

### Paso 2: Obtén un XML que funciona de otro sistema

Si es posible, descarga/exporta un XML que sí funciona en el otro sistema.

**Luego compara:**

```
XML de otra app:
- Estructura
- Orden de elementos
- Formato de números
- Formato de fechas
- Namespaces
- Caracteres especiales

VS

XML de Arriendo Fácil:
```

### Paso 3: Identifica las diferencias

Las diferencias encontradas pueden ser:
- Orden de elementos (ejemplo: `<infoTributaria>` antes/después)
- Decimales (`.00` vs sin decimales)
- Fechas (DD/MM/YYYY vs otros formatos)
- Espacios/whitespace
- Atributos faltantes
- Elementos faltantes

### Paso 4: Reporta tus hallazgos

Una vez hayas ejecutado los scripts, comparte:

1. **Resultado de DEBUG_CERTIFICADO_ENVIADO.php:**
   - ¿El certificado coincide?
   - ¿Tiene Digital Signature?
   - ¿Otras advertencias?

2. **Resultado de DEBUG_XML_GENERADO.php:**
   - ¿El XML es válido?
   - ¿Hay errores?
   - ¿Los totales son correctos?

3. **Comparación con otro sistema:**
   - ¿Qué diferencias encontraste?
   - ¿En qué orden vienen los elementos?
   - ¿Cómo están formateados los números?

---

## Posibles Hallazgos

### Si el certificado NO coincide

```
Longitud en XML: 1234 bytes
Longitud esperado: 1234 bytes
Contenido: DIFERENTE
```

→ **Acción:** El certificado se está alterando/corrompiendo

### Si el XML tiene errores estructurales

```
❌ claveAcceso debe ser 49 dígitos
❌ totalSinImpuestos debe ser mayor a 0
```

→ **Acción:** Hay un problema en cómo se construye el XML

### Si encuentras diferencias con otro sistema

```
Otra app: <infoTributaria> ... </infoTributaria> ... <infoFactura>
Esta app: <infoTributaria> ... <infoFactura> ... </infoTributaria>
```

→ **Acción:** El orden de elementos es diferente, hay que reordenar

---

## Checklist de Verificación

- [ ] ¿Ejecutaste DEBUG_CERTIFICADO_ENVIADO.php?
- [ ] ¿El certificado coincide exactamente?
- [ ] ¿Tiene Digital Signature habilitado?
- [ ] ¿Ejecutaste DEBUG_XML_GENERADO.php?
- [ ] ¿El XML es válido?
- [ ] ¿Los totales calculan correctamente?
- [ ] ¿Obtuviste un XML que funciona de otro sistema?
- [ ] ¿Comparaste las estructuras?
- [ ] ¿Identificaste diferencias?

---

## Próximos Pasos

1. **Ejecuta ambos scripts**
2. **Revisa los resultados**
3. **Si hay advertencias → arregla eso**
4. **Si todo se ve bien → compara con otro sistema**
5. **Reporta hallazgos**

---

## Si Nada De Esto Funciona

Si después de todo esto el SRI sigue rechazando, las opciones finales son:

1. **El SRI no acepta ese certificado por razones ajenas al código:**
   - Certificado revocado
   - Certificado no autorizado para firmar
   - Política del SRI ha cambiado

2. **Hay una diferencia fundamental en cómo otros sistemas construyen el XML:**
   - Necesitarías ver exactamente cómo otro sistema lo hace
   - O usar un certificado diferente

3. **Problema de configuración del SRI:**
   - El RUC podría no estar permitido
   - El certificado podría no estar registrado en SRI

---

**¡Adelante con los scripts! Reporta los resultados y encontraremos el problema.** 🚀
