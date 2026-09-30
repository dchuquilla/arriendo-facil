# 📚 Índice de Documentación
## Arriendo Fácil - Plugin WordPress

---

## 🏠 Estructura de Carpetas

```
docs/
├── /architecture/      → Modelo de negocio (administración interna), suscripción, librerías
├── /design/            → Rediseño UI/UX del panel interno
├── /billing/           → Facturación SRI (Ecuador)
└── /templates/         → Plantillas DOCX de contratos
```

---

## 🎯 Dónde Encontrar Qué

### 🏗️ **Arquitectura General**
**Ir a:** `/docs/architecture/`

- **[BUSINESS_MODEL_ALIGNMENT.md](architecture/BUSINESS_MODEL_ALIGNMENT.md)** - Alineación con el modelo de administración (8 módulos)

- **[DASHBOARD_LIBRARIES.md](architecture/DASHBOARD_LIBRARIES.md)** - Librerías del panel

- **[SUBSCRIPTION_MODEL.md](architecture/SUBSCRIPTION_MODEL.md)** - Modelo de suscripción/monetización 2.0
  - Planes (trial/free/pago), límites por plan, publicidad, paywall
  - ✅ Auto-registro público (§6) y demo `/ver-demo/` implementados (2026-09); monetización pendiente

---

### 💰 **Facturación SRI (Ecuador)**
**Ir a:** `/docs/billing/`

- **[DIAGNOSTICO_FIRMA.md](billing/DIAGNOSTICO_FIRMA.md)** - Diagnóstico de certificados
- **[DIAGNOSTICO_CADENA_CA.md](billing/DIAGNOSTICO_CADENA_CA.md)** - Cadena de CA
- **[ANALISIS_PROBLEMA_FIRMA.md](billing/ANALISIS_PROBLEMA_FIRMA.md)** - Análisis de problemas
- **[SOLUCION_FIRMA_INVALIDA_P12.md](billing/SOLUCION_FIRMA_INVALIDA_P12.md)** - Soluciones (convertidor: `php bin/convertidor-p12.php`)
- **[PROBLEMA_RAIZ_Y_SOLUCION.md](billing/PROBLEMA_RAIZ_Y_SOLUCION.md)** - Raíz del problema de firma
- **[INVESTIGACION_FIRMA_INVALIDA.md](billing/INVESTIGACION_FIRMA_INVALIDA.md)** - Investigación de firmas
- **[FIX_RESUMEN_EJECUTIVO.md](billing/FIX_RESUMEN_EJECUTIVO.md)** - Resumen del fix aplicado
- **[INSTRUCCIONES_IMPLEMENTACION.md](billing/INSTRUCCIONES_IMPLEMENTACION.md)** - Instrucciones del fix
- **[PASOS_DIAGNOSTICO_SIMPLE.md](billing/PASOS_DIAGNOSTICO_SIMPLE.md)** - Diagnóstico paso a paso

---

### 📄 **Templates DOCX**
**Ir a:** `/docs/templates/`

- **[TROUBLESHOOTING_UNFILLED_DOCX.md](templates/TROUBLESHOOTING_UNFILLED_DOCX.md)** - Problemas y soluciones

---

## 🚀 Ruta Rápida por Tema

### Si necesitas entender el modelo
```
1. Lee: docs/architecture/BUSINESS_MODEL_ALIGNMENT.md
2. Consulta: docs/architecture/SUBSCRIPTION_MODEL.md
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
```

---

## 📊 Status de Documentación

| Tema | Status | Actualizado |
|------|--------|-------------|
| Architecture | ✅ Vigente | 2026-09 |
| Billing SRI | ✅ Completo | 2026-06-16 |
| Templates | ✅ Completo | 2026-05-05 |

---

## 💡 Tips de Navegación

- **Ctrl+F o Cmd+F** dentro de cada documento para búsqueda rápida
- **[INDEX.md](INDEX.md)** siempre como punto de partida
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

**Última actualización:** 2026-09-30  
**Estructura:** Organizada en /docs por tema

