# Integración Booking + Airbnb - Resumen Ejecutivo

**Análisis completado:** 2026-07-08  
**Duración estimada:** 7 semanas (1 sprint por fase)  
**Riesgo:** Bajo (si se siguen mitigaciones)

---

## El Problema

Actualmente, si una acomodación está:
- ❌ Rentada en Booking
- ❌ Rentada en Airbnb
- ✅ Marcada como disponible en ArriendoFacil

**Resultado:** Overbooking, conflictos, clientes insatisfechos.

---

## La Solución

**Sistema de sincronización híbrido** que:

1. **Captura IDs** de propiedades en Booking y Airbnb
2. **Sincroniza** disponibilidad cada 2 horas (configurable)
3. **Prioriza** estado remoto (si está ocupado allá → ocupado acá)
4. **Usa webhooks** para actualizaciones en tiempo real (fallback a cron)
5. **Encripta** credenciales en base de datos (Sodium)
6. **Loguea** cada sincronización para auditoría

---

## Arquitectura de 7 Capas

```
┌─────────────────────────────────────────────┐
│  INTERFACE (Admin)                          │
│  - Meta box con IDs (Booking/Airbnb)        │
│  - Settings page con API keys               │
│  - Botón "Sincronizar ahora"                │
└───────────────┬─────────────────────────────┘
                │
┌───────────────▼─────────────────────────────┐
│  AUTOMATION LAYER                           │
│  ├─ WP-Cron (cada 2h)                      │
│  ├─ Webhooks (real-time si configurados)   │
│  └─ AJAX (manual)                           │
└───────────────┬─────────────────────────────┘
                │
┌───────────────▼─────────────────────────────┐
│  ORCHESTRATOR (OTA_Sync_Manager)           │
│  - Obtiene estado remoto                    │
│  - Reconcilia vs estado local               │
│  - Actualiza _af_is_occupied si needed      │
│  - Registra en log                          │
└───────────────┬─────────────────────────────┘
                │
┌───────────────▼─────────────────────────────┐
│  API CLIENTS (Specific)                     │
│  ├─ BookingAPIClient                        │
│  │  ├─ GET /properties/{id}                 │
│  │  └─ GET /availabilities                  │
│  └─ AirbnbAPIClient                         │
│     └─ GET /listings/{id}/availability      │
└───────────────┬─────────────────────────────┘
                │
┌───────────────▼─────────────────────────────┐
│  API BASE CLASS                             │
│  - Retry logic (exponential backoff)        │
│  - Rate limiting (respeto de límites)       │
│  - Error handling estándar                  │
│  - Logging centralizado                     │
└───────────────┬─────────────────────────────┘
                │
┌───────────────▼─────────────────────────────┐
│  DATA LAYER                                 │
│  ├─ Post meta: _af_booking_property_id      │
│  ├─ Post meta: _af_airbnb_listing_id        │
│  ├─ Post meta: _af_sync_enabled             │
│  ├─ Table: wp_af_otas_sync_log (auditoria)  │
│  ├─ Table: wp_af_ota_credentials (encrypt)  │
│  └─ Post meta: _af_last_sync_timestamp      │
└─────────────────────────────────────────────┘
```

---

## Flujo de Sincronización

### Escenario 1: Propiedad ocupada en Booking

```
1. Booking: "Propiedad #123 ocupada del 15-20 de julio"
           ↓
2. Webhook/Cron: Detecta cambio
           ↓
3. OTA_Sync_Manager: Obtiene estado de Booking
           ↓
4. Reconciliación: 
   - Local (ArriendoFacil): libre
   - Remoto (Booking): ocupado
   → Resultado: Marcar como ocupada
           ↓
5. Update: _af_is_occupied = 1
           ↓
6. Log: Registra en wp_af_otas_sync_log
           ↓
7. Frontend: No aparece disponible en búsqueda
```

### Escenario 2: Propiedad liberada en Airbnb

```
1. Airbnb: "Propiedad #456 disponible nuevamente"
           ↓
2. Webhook/Cron: Detecta cambio
           ↓
3. OTA_Sync_Manager: Verifica disponibilidad
           ↓
4. Reconciliación:
   - Local: ocupada
   - Remoto: libre
   → Resultado: ALERTAR propietario (no cambiar auto)
           ↓
5. Notification: Email al propietario
           ↓
6. Propietario: Manualmente actualiza si aplica
```

---

## Nuevos Metadatos y Tablas

### Metadatos de Acomodación (post_meta)

```php
_af_booking_property_id    // "123456" (ID de propiedad en Booking)
_af_airbnb_listing_id      // "987654" (ID de listing en Airbnb)
_af_sync_enabled           // "1" o "" (activar/desactivar sync)
_af_last_sync_timestamp    // 1720396800 (unix timestamp)
```

### Tabla: wp_af_otas_sync_log

```
id (PK) | accommodation_id | ota_source | sync_type  | status | created_at
1       | 42               | booking    | availability | success | 2026-07-08 10:30:00
2       | 42               | airbnb     | availability | failed  | 2026-07-08 10:32:00
```

**Ventaja:** Historial completo para debugging y análisis.

### Tabla: wp_af_ota_credentials

```
id | owner_id | ota_platform | api_key_encrypted | account_identifier | status
1  | 5        | booking      | <encriptado>      | partner_123        | active
2  | 5        | airbnb       | <encriptado>      | account_abc         | active
```

**Ventaja:** Credenciales seguras, separadas por propietario y plataforma.

---

## Nuevos Archivos a Crear (13 Total)

### Core (6 archivos)
```
includes/
  class-ota-api-client-base.php       ← Base abstracta (retry, rate-limit)
  class-ota-credentials.php            ← Encriptación Sodium
  class-ota-sync-manager.php           ← Orquestador principal
  class-booking-api-client.php         ← Cliente Booking específico
  class-airbnb-api-client.php          ← Cliente Airbnb específico
  class-ota-webhook-handler.php        ← Maneja webhooks entrada
```

### UI (3 archivos)
```
admin/views/
  ota-integrations-settings.php        ← Página de config global
  ota-sync-dashboard.php               ← Dashboard de status

assets/js/
  admin-ota-integrations.js            ← AJAX handlers
```

### Tests (3 archivos)
```
tests/
  OTA_Sync_ManagerTest.php
  OTA_Booking_ClientTest.php
  OTA_Airbnb_ClientTest.php
```

### Plus Documentación
```
OTA_INTEGRATION_ARCHITECTURE.md        ← Documento técnico detallado (25 KB)
OTA_INTEGRATION_SUMMARY.md             ← Este archivo
```

---

## Plan de Implementación (7 Semanas)

### **FASE 1: Base (Semana 1-2)**
**Objetivo:** Infraestructura y tablas

- [ ] Crear tablas en DB (migrations)
- [ ] Agregar metadatos a Accommodation
- [ ] Clase base de API client
- [ ] Clase de encriptación de credenciales
- [ ] Clase orquestadora (stub)

**Impacto:** NULO (no visible, solo infraestructura)

---

### **FASE 2: APIs (Semana 3-4)**
**Objetivo:** Integración con Booking y Airbnb

- [ ] Implementar Booking API client
- [ ] Implementar Airbnb API client
- [ ] Webhook handler
- [ ] Tests unitarios
- [ ] Mock responses para testing

**Impacto:** Bajo (sin UI aún)

---

### **FASE 3: UI (Semana 5)**
**Objetivo:** Interfaz para propietarios

- [ ] Agregar campos OTA a meta box
- [ ] Settings page de configuración
- [ ] AJAX handlers para test de conexión
- [ ] JavaScript para botón de sync manual
- [ ] Submenu en admin

**Impacto:** Propietarios ven opciones de configurar

---

### **FASE 4: Automatización (Semana 6)**
**Objetivo:** Sync automático

- [ ] WP-Cron (cada 2h)
- [ ] Batch processing
- [ ] Retry automático
- [ ] Logging
- [ ] AJAX para sync manual

**Impacto:** ALTO - Sincs activos, props pueden verlo trabajar

---

### **FASE 5: Polish (Semana 7)**
**Objetivo:** Calidad y documentación

- [ ] Notificaciones (email/admin)
- [ ] Dashboard de status
- [ ] Tests E2E
- [ ] Performance tuning
- [ ] Documentación
- [ ] Video tutorial

**Impacto:** Experiencia completa

---

## Seguridad (CRÍTICO)

| Aspecto | Medida |
|---------|--------|
| **Credenciales** | Encriptadas con Sodium en tabla (no en options) |
| **Validación** | Nonces en forms, check_ajax_referer en handlers |
| **Permisos** | Owners solo ven/sincronizan sus acomodaciones |
| **Webhooks** | HMAC signature validation (SHA256) |
| **Rate Limit** | 100 req/min, respetar headers de APIs |
| **Logging** | Toda actividad en wp_af_otas_sync_log para auditar |

---

## Performance

| Optimización | Detalles |
|--------------|----------|
| **Caché** | Disponibilidad cacheada 30 minutos |
| **Batch** | Procesa múltiples props en lote, no individuales |
| **Lazy Load** | Meta box carga status vía AJAX, no bloquea |
| **Locks** | Transient locks previenen race conditions |
| **Sleep** | 1s entre requests para respetar rate limits |

---

## Configuración

### En `wp-config.php` (Opcional)

```php
define( 'AF_OTA_BOOKING_ENABLED', true );
define( 'AF_OTA_AIRBNB_ENABLED', true );
define( 'AF_OTA_SYNC_INTERVAL', 'every_2_hours' );
define( 'AF_OTA_ENABLE_WEBHOOKS', true );
define( 'AF_OTA_AUTO_MARK_OCCUPIED', true );
```

### En WordPress Admin (Settings)

```
Integraciones OTA > Configurar Booking API Key
Integraciones OTA > Configurar Airbnb Token
Integraciones OTA > Frecuencia de sync (dropdown)
Integraciones OTA > Activar webhooks (checkbox)
```

### En Meta Box de Acomodación

```
[ID Booking.com: _______]  
[ID Airbnb: _______]
☑ Sincronizar automáticamente
[Última sync: hace 12 minutos]
[Sincronizar Ahora]
```

---

## Riesgos Principales y Mitigaciones

| Riesgo | Mitigation |
|--------|-----------|
| **API downtime** | Retry logic con exponential backoff, notificar propietario |
| **Rate limiting** | Queue con delays, batch processing, respetar headers |
| **Desincronización** | Sync cada 2h + webhooks, remote = source of truth |
| **Exposición credenciales** | Encriptación Sodium, DB storage, never in options |
| **Race conditions** | Transient locks, validar timestamps |
| **Webhook spoofing** | HMAC signature validation |
| **Saturación** | Batch, WP-Cron, limit 1 sync/accom/30min |

---

## NO Incluye Breaking Changes ✅

- Campos meta completamente opcionales
- Sync deshabilitado por default
- Propiedades sin IDs OTA funcionan igual que antes
- Pueden agregarse gradualmente
- Rollback limpio si aplica

---

## Próximos Pasos

1. **Revisión arquitectura** ← Tú aquí
2. **Aprobación de plan** ← Tú autoriza
3. **Comenzar FASE 1** ← Semana que viene
4. **Iteraciones semanales** ← 1 fase/semana

---

## Referencias

- **Documento técnico completo:** `OTA_INTEGRATION_ARCHITECTURE.md`
- **Memoria de proyecto:** `/memory/project_ota_integration.md`
- **Clase similar existente:** `class-accommodation-occupied-admin.php` (patrón UI)
- **API existente:** `class-accommodation-search-api.php` (referencia REST)

---

## Recomendación Final

**VERDE PARA PROCEDER** ✅

Esta arquitectura es:
- ✅ **Funcional:** Resuelve el problema de overbooking
- ✅ **Escalable:** Soporta agregar más OTAs (Vrbo, Expedia, etc.)
- ✅ **Segura:** Encriptación, validación, logging completo
- ✅ **Mantenible:** Código organizado en clases especializadas
- ✅ **Compatible:** No rompe nada existente
- ✅ **Gradual:** Puede implementarse fase por fase

**Tiempo:** 7 semanas (1 developer full-time)  
**Esfuerzo:** ~280 horas  
**ROI:** Muy alto (previene pérdidas por overbooking)

---

**Análisis completado por:** Arquitecto Senior  
**Fecha:** 2026-07-08  
**Versión:** 1.0
