# 📚 Índice de Documentación
## Arriendo Fácil - Plugin WordPress

---

## 🏠 Estructura de Carpetas

```
docs/
├── /architecture/      → Diseño técnico general
├── /payments/          → 💳 NUEVO: Integración Deuna + Banco Pichincha
├── /billing/           → Facturación SRI (Ecuador)
├── /templates/         → Procesamiento de documentos DOCX
├── /ota/               → Integración Booking.com + Airbnb
├── /implementation/    → Guías de implementación
└── /troubleshooting/   → Solución de problemas (legacy)
```

---

## 🎯 Dónde Encontrar Qué

### 💳 **Pagos y Dispersión de Fondos (NUEVO)**
**Ir a:** `/docs/payments/`

1. **[WORDPRESS_COMPATIBILITY_SUMMARY.md](payments/WORDPRESS_COMPATIBILITY_SUMMARY.md)** ⭐ **COMIENZA AQUÍ**
   - Análisis ejecutivo de la arquitectura
   - Qué cambió de Node.js a PHP
   - Plan de 4 semanas
   - Equivalencias Node.js ↔ WordPress

2. **[ARCHITECTURE_ANALYSIS.md](payments/ARCHITECTURE_ANALYSIS.md)** - Análisis técnico detallado
   - Incompatibilidades identificadas
   - Tablas de cambios por componente
   - Stack tecnológico comparativo

3. **[IMPLEMENTATION_WORDPRESS_PHP.md](payments/IMPLEMENTATION_WORDPRESS_PHP.md)** - Guía paso a paso
   - 6 fases de implementación
   - Código PHP listo para copiar/pegar
   - Estructura de tablas SQL
   - Ejemplos de OAuth, webhooks, transfers

---

### 🏗️ **Arquitectura General**
**Ir a:** `/docs/architecture/`

- **[ARCHITECTURE_PAYMENTS.md](architecture/ARCHITECTURE_PAYMENTS.md)** - Referencia técnica completa
  - Flujos de negocio
  - Endpoints documentados
  - DB schema (conceptual)
  - Seguridad e idempotencia

- **[ARCHITECTURE_DIAGRAMS.md](architecture/ARCHITECTURE_DIAGRAMS.md)** - Diagramas visuales
  - ASCII art flows
  - State machines
  - Validaciones HMAC
  - Dashboard de monitoreo

- **[OTA_INTEGRATION_ARCHITECTURE.md](architecture/OTA_INTEGRATION_ARCHITECTURE.md)** - Integración OTA
  - Sincronización Booking.com + Airbnb
  - Webhooks y disponibilidad

---

### 💰 **Facturación SRI (Ecuador)**
**Ir a:** `/docs/billing/`

- **[DIAGNOSTICO_FIRMA.md](billing/DIAGNOSTICO_FIRMA.md)** - Diagnóstico de certificados
- **[DIAGNOSTICO_CADENA_CA.md](billing/DIAGNOSTICO_CADENA_CA.md)** - Cadena de CA
- **[ANALISIS_PROBLEMA_FIRMA.md](billing/ANALISIS_PROBLEMA_FIRMA.md)** - Análisis de problemas
- **[SOLUCION_FIRMA_INVALIDA_P12.md](billing/SOLUCION_FIRMA_INVALIDA_P12.md)** - Soluciones

---

### 📄 **Templates DOCX**
**Ir a:** `/docs/templates/`

- **[DOCX_IMPROVEMENT_SUMMARY.md](templates/DOCX_IMPROVEMENT_SUMMARY.md)** - Mejoras en templates
- **[TROUBLESHOOTING_UNFILLED_DOCX.md](templates/TROUBLESHOOTING_UNFILLED_DOCX.md)** - Problemas y soluciones

---

### 🏢 **Integración OTA (Booking + Airbnb)**
**Ir a:** `/docs/ota/`

- **[OTA_INTEGRATION_SUMMARY.md](ota/OTA_INTEGRATION_SUMMARY.md)** - Resumen de integración
- **[PASOS_DIAGNOSTICO_SIMPLE.md](ota/PASOS_DIAGNOSTICO_SIMPLE.md)** - Diagnóstico paso a paso

---

### 🔧 **Implementación**
**Ir a:** `/docs/implementation/`

- **[IMPLEMENTATION_CHECKLIST.md](implementation/IMPLEMENTATION_CHECKLIST.md)** - Checklist general
- **[INSTRUCCIONES_IMPLEMENTACION.md](implementation/INSTRUCCIONES_IMPLEMENTACION.md)** - Instrucciones detalladas
- **[FRONTEND_OCCUPIED_IMPLEMENTATION.md](implementation/FRONTEND_OCCUPIED_IMPLEMENTATION.md)** - Frontend: ocupación
- **[FIX_RESUMEN_EJECUTIVO.md](implementation/FIX_RESUMEN_EJECUTIVO.md)** - Resumen de fixes

---

### 🚨 **Solución de Problemas (Legacy)**
**Ir a:** `/docs/troubleshooting/`

- **[PROBLEMA_RAIZ_Y_SOLUCION.md](troubleshooting/PROBLEMA_RAIZ_Y_SOLUCION.md)** - Raíz de problemas
- **[INVESTIGACION_FIRMA_INVALIDA.md](troubleshooting/INVESTIGACION_FIRMA_INVALIDA.md)** - Investigación de firmas

---

## 🚀 Ruta Rápida por Tema

### Si necesitas implementar pagos ahora
```
1. Lee: docs/payments/WORDPRESS_COMPATIBILITY_SUMMARY.md (5 min)
2. Sigue: docs/payments/IMPLEMENTATION_WORDPRESS_PHP.md (paso a paso)
3. Referencia: docs/payments/ARCHITECTURE_ANALYSIS.md (cuando tengas dudas)
```

### Si necesitas entender la arquitectura
```
1. Lee: docs/architecture/ARCHITECTURE_PAYMENTS.md
2. Ve: docs/architecture/ARCHITECTURE_DIAGRAMS.md
3. Consulta: docs/payments/WORDPRESS_COMPATIBILITY_SUMMARY.md
```

### Si tienes problemas con firma SRI
```
1. Lee: docs/billing/DIAGNOSTICO_FIRMA.md
2. Sigue pasos: docs/billing/SOLUCION_FIRMA_INVALIDA_P12.md
3. Diagnostica: docs/billing/DIAGNOSTICO_CADENA_CA.md
```

### Si tienes problemas con templates DOCX
```
1. Consulta: docs/templates/TROUBLESHOOTING_UNFILLED_DOCX.md
2. Entiende: docs/templates/DOCX_IMPROVEMENT_SUMMARY.md
```

---

## 📊 Status de Documentación

| Tema | Status | Actualizado |
|------|--------|-------------|
| **Payments (NEW)** | ✅ Completo | 2026-07-16 |
| Architecture | ✅ Completo | 2026-07-16 |
| Billing SRI | ✅ Completo | 2026-06-16 |
| Templates | ✅ Completo | 2026-05-05 |
| OTA Integration | ✅ Completo | 2026-07-14 |
| Implementation | ✅ Actualizado | 2026-07-16 |

---

## 💡 Tips de Navegación

- **Ctrl+F o Cmd+F** dentro de cada documento para búsqueda rápida
- **[INDEX.md](INDEX.md)** siempre como punto de partida
- **WORDPRESS_COMPATIBILITY_SUMMARY.md** es la mejor entrada a Payments
- Cada carpeta tiene documentos independientes (no necesitas leer todos)

---

## 📝 Convención de Nombres

```
{TEMA}_{TIPO}.md

TEMA:
  - ARCHITECTURE    → Diseño técnico
  - IMPLEMENTATION  → Guías paso a paso
  - DIAGNOSTICO     → Troubleshooting
  - SOLUCION        → Soluciones probadas
  - SUMMARY         → Resumen ejecutivo

TIPO:
  - _WORDPRESS.md   → Específico para WordPress
  - _ANÁLISIS.md    → Análisis técnico
  - _CHECKLIST.md   → Checklist verificación
```

---

**Última actualización:** 2026-07-16  
**Estructura:** Organizada en /docs por tema  
**Próximo:** Leer WORDPRESS_COMPATIBILITY_SUMMARY.md para pagos

