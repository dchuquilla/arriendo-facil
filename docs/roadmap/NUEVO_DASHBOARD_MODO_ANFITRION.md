# Nuevo Dashboard — Modo Anfitrión (inspirado en Airbnb Host)

> Documento de ruta. Se actualiza a medida que se decide e implementa cada sección.
> Referencias de archivo concretas para que cualquier persona (o agente) pueda retomar el trabajo.

## Visión general

Rehacer la experiencia del panel como el **modo anfitrión de Airbnb**: un solo lugar donde el
administrador ve su "Hoy" (check-ins, check-outs, visitas), un **calendario interactivo** como pieza
central, el dinero del mes, las alertas que requieren acción y el acceso rápido a cada propiedad.
Todo adaptado al modelo de gestión/admin (no alianza Airbnb sobre reservas, sino **arriendos
mensuales + visitas agendadas**).

Menú actual (registrado en `admin/class-admin.php:74-276`):

| Slug | Sección | Estado hoy |
|---|---|---|
| `arriendo-facil` | **Panel** | KPIs, hero, semáforo de cobros, calendario en columnas, tareas, ocupación, mantenimiento, salidas 30/60/90 |
| `af-catalog` | Catálogo de propiedades | Grid de tarjetas con badge de estado; **sin exportar/compartir** |
| `af-leases` | Contratos | Tabla compacta + menú de acciones (rediseñado) |
| `af-buildings` | Edificios y unidades | Módulos/edificios + unidades, KPIs |
| `af-collections` | Control de pagos | Ledger real: cargos, pagos, mora, estados de cuenta |
| `af-meter-readings` | Lecturas | Registro mensual; al guardar **auto-genera el cargo** |
| `af-alerts-center` | Alertas | Campana + centro de alertas (sistema nuevo) |
| `af-guests` | Inquilinos | Form contacto + enlace seguro para completar perfil |
| `af-reviews` | Calificaciones | Reseñas inquilino↔propiedad + modal admin→inquilino |
| `af-upcoming-exits` / `af-maintenance` / `af-owner-settlements` | extras | - |

---

## 1. Panel "modo anfitrión" — rediseño (P1)

**Archivos**: `admin/views/dashboard.php` · `assets/css/af-dashboard.css` · `class-admin.php` (KPIs 1869-2192).

**Ya existe** y se reutiliza:
- Semáforo de cobros (cobrado/pendiente/atrasado + top mora).
- "Alertas operativas de calendario": check-ins, check-outs y visitas en 3 columnas con filtro de rango/propiedad.
- Ocupación (available/occupied/maintenance), ingresos últimos 6 meses, salidas 30/60/90, "Requiere tu atención".

**Propuesto** (orden arriba→abajo):
1. **Hoy** — tarjetas de check-in hoy, check-out hoy y visitas de hoy (una sola franja).
2. **Calendario del mes** (widget interactivo, ver §2) con leyenda de colores.
3. **Ingresos del mes** — tarjetas: cobrado, por cobrar, mora + mini-gráfica.
4. **Requiere tu atención** (igual, con enlaces directos).
5. **Tus propiedades** — grid compacto estilo Airbnb con estado Disponible/Ocupado + toggle rápido (§3).
6. Selector superior para **filtrar por propiedad/ad edificio** (persistente en el panel).

---

## 2. Calendario interactivo (P1 — pieza estrella)

**Archivos actuales**: `dashboard.php:173-234` (listas SQL), `af_visit_slots` / `af_visit_bookings`
(activator `class-activator.php:497-513`), `class-rental-workflow.php` (flujo visitas).

**Hoy**: no hay widget calendario (solo iCal de importación OTA). Las visitas y check-ins se muestran
como listas por rango.

**Propuesto**:
- Widget de **calendario mensual** (semana/mes), tipo Airbnb Host, sin librería externa o con
  **FullCalendar v6 local** en `assets/js/vendor` (decisión pendiente).
- Fuentes de eventos por día: `af_leases.start_date/end_date` (check-in/out), `af_visit_slots` +
  `af_visit_bookings` (visitas), bloques de mantenimiento/OTA y días bloqueados.
- **Clic en un día** → panel rápido para:
  - Agendar **visita** (nombre/tel/email del interesado → crea slot+booking).
  - Marcar **check-in / check-out** de un contrato.
  - Bloquear el día (mantenimiento / no disponible).
  - Ver contratos/visitas que caen ese día.
- Colores por tipo + leyenda (entradas, salidas, visitas, bloqueos).
- **Endpoints AJAX nuevos**: `af_calendar_events` (días del rango), `af_calendar_create_visit`,
  `af_calendar_set_block`, `af_calendar_checkin`, `af_calendar_checkout`.

---

## 3. Estados de propiedad Disponible ↔ Ocupado

**Revisado — YA ESTÁ IMPLEMENTADO** (en dos capas):

1. **`_af_is_occupied`** (`includes/class-accommodation-occupied-admin.php`): toggle "Ocupada" en la
   lista del CPT. Marcar siempre es libre; **desmarcar con contrato activo abre un modal** que exige
   motivo, termina el contrato anticipadamente (`af_early_terminate_lease`), notifica al arrendatario
   e interesaos en cola, y solo entonces libera el inmueble.
2. **`_af_status`** en cada acomodación (available/rented/maintenance/inactive) mostrado como badge en
   `admin/views/catalog.php` y filtrable.

**Mejoras propuestas (P2)**:
- Exponer el toggle **dentro de Catálogo y Contratos** (hoy solo vive en la lista de posts de WP).
- Unificar valores: el dashboard trata `occupied` y `rented` de forma equivalente (`dashboard.php:453`);
  normalizar a un solo set de estados.
- Ocupación **derivada** si existe contrato activo (auto-marcado) además del manual.

---

## 4. Chat interno: sistema ↔ administrador / super-admin (P4 — FUTURO)

> Queda **documentado aquí para implementación futura** (requerimiento explícito).

**Objetivo**: canal donde el admin resuelve dudas con el sistema y con el super-admin; el sistema
participa con respuestas/plantillas; y las reseñas del super-admin al admin viven acá también.

**Diseño propuesto**:
- Tablas: `af_chat_threads` (id, user_id, subject, status, last_at) y `af_chat_messages`
  (id, thread_id, author_user_id|null=sistema, body, read_at, created_at).
- Página `admin.php?page=af-mensajes` + badge de no-leídos en el topbar (reutilizar patrón de alertas).
- Bot "sistema" responde a comandos (/ayuda, /como-crear-contrato…) con guías existentes
  (`docs/ota`, `docs/templates`, etc.).
- El super-admin ve todos los threads; el admin solo los suyos.
- Enlace a la **evaluación del admin** por el super-admin (ver §5).

---

## 5. Reseñas (P2/P3)

**Archivos**: `admin/views/reviews.php` · `includes/class-review.php` · tabla `af_reviews` (y
`af_review_groups`, `af_review_tokens`) · AJAX `af_rate_tenant`.

**Ya existe**:
- Reseñas inquilino↔propiedad en 3 direcciones (`tenant_to_owner`, `tenant_to_property`,
  `owner_to_tenant`) con enlaces por token (correo → `/calificar-estancia/`).
- En modo gestión, el **admin califica al inquilino directamente** desde el panel con criterios
  estructurados: `puntualidad_pago` (pre-cargada del histórico de pagos), `cuidado_inmueble`,
  `convivencia`, `comunicacion` + observaciones.
- KPIs (nota promedio, positivas, tasa positiva) + tablas por estado.

**Falta — super-admin → admin (P2)**:
- Nueva dirección `superadmin_to_admin` en `af_reviews` (o tabla aparte `af_admin_reviews`).
- En `af-reviews` (o `af-property-admins`): sección "Evaluación de administradores" donde el
  super-admin califica a cada admin (mismos criterios + responsabilidad/gestión).
- Los admins ven su propia evaluación; visibilidad por rol.

---

## 6. Catálogo compartible por URL o PDF (P2)

**Archivo**: `admin/views/catalog.php`.

**Hoy**: grid de tarjetas (mini-n, badge de estado, specs, renta, "Gestionar →"); **sin** compartir/exportar.

**Propuesto**:
- Botón **"Compartir catálogo"** en la cabecera (visible para cada admin, alcance = **solo sus
  propiedades**).
- **URL pública** con token: ruta `/catalogo/{clave}` (shortcode/re-write) que muestra solo las
  propiedades activas del admin, estética tipo landing (reutilizar tarjetas).
- **PDF**: misma página pública + botón/imprimible; o export server-side (dompdf/WKHTML, decisión).
- Token de expiración opcional; regenerable.
- Registro de accesos (af_notifications_log o tabla `af_shared_catalogs`).

---

## 7. Edificios / unidades / lecturas → cobranza como recordatorios + unificar (P2)

**Archivos**: `admin/views/buildings.php`, `admin/views/collections.php`,
`admin/views/meter-readings.php`, `includes/class-billing-ledger.php`, `includes/class-alerts.php`.

**Aclaración importante (estado verificado)**:
- **Control de pagos NO es solo recordatorio**: lleva contabilidad real (`af_charges`, `af_payments`:
  cargos por concepto/período, pagos registrados, saldos, mora por antigüedad, estados de cuenta
  imprimibles). Lo que NO hace es procesar pagos (no hay pasarela).
- **Lecturas** sí son "registro de medidor" que al guardar **auto-crea el cargo** del servicio al
  contrato activo.
- Los "recordatorios" ya existen vía **Alertas** (`class-alerts.php`): cargos vencidos, lecturas
  faltantes, contratos por vencer, con campana + correo diario.

**Propuesta — unificar o separar (decisión del usuario)**:

| Opción | Descripción |
|---|---|
| **A) Hub "Cobros y servicios"** (recomendada) | Un solo menú con 3 solapas: *Cargos y pagos* / *Lecturas de medidor* / *Recordatorios y avisos*. Mantiene las vistas actuales pero navegadas juntas, + banner "Próximas fechas de cobro del mes". |
| **B) Mantener separadas** | Conservar `af-collections` y `af-meter-readings` como están y solo añadir el banner de próximos cobros + más cruce con alertas. |

En ambas: agregar en cada en-trada/lectura el vínculo a la propiedad/contrato (ya está parcial), el
**día de cobro por mes** y el envío del **aviso al inquilino** (§8).

---

## 8. Aviso al inquilino con confirmación "recibido y leído" (P2)

**Archivos actuales**: `includes/class-guest.php` (correos por token: perfil, documentos, recordatorios),
`af_notifications_log` (log crudo sin UI ni lectura).

**Hoy**: el inquilino recibe correos pero **no hay confirmación de lectura** y no hay historial visible.

**Propuesto**:
- Tabla **`af_notification_messages`**: `id, accommodation_id, lease_id, guest_id, type
  (cobro/servicios/aviso), subject, body, status (pending/sent/read), read_at, email_token, created_at`.
- Página **"Comunicaciones"** (o dentro del hub Cobros): historial por inmueble/inquilino con estado.
- **Correo al inquilino** con resumen (concepto, período, monto, inmueble) + botón
  **"Confirmo que recibí y leí"** → URL pública tokenizada → marca `read`.
- **Indicadores en el panel**: "Leído · 12 ene" en la fila del cargo/mensaje; el admin sabe si su
  inquilino leyó.
- Disparadores: al generar cargos del período, al registrar lectura, o mensaje manual.
- Vinculado siempre al inmueble (`accommodation_id`) y contrato (`lease_id`).

---

## 9. Formulario de Inquilinos (P3)

**Archivos**: `admin/views/guests.php`, `admin/views/guest-profile.php`, `includes/class-guest.php`.

**Estado actual (ya mejorado)**: en modo gestión el admin registra solo **contacto** (nombre, cédula,
email, teléfono); el **resto lo completa el propio inquilino** desde un **enlace seguro** que lo lleva
al sistema (`/completar-perfil-arriendo/`) — todo queda registrado en la DB, nada manual por correo.
Incluye: subida de PDFs (garantía, certificados), estado de documentos (pendiente/verificado/rechazado),
verificación de identidad, recordatorios y aviso de renovación.

**Propuesto**:
- Mejor UX (asistente 3 pasos: contacto → vivienda/mudanza → documentos).
- Mayor automatización: al guardar el perfil → crear el aviso de bienvenida y dejar listo el siguiente
  cobro (§8); búsqueda y edición rápida.
- Validación reforzada de cédula/RUC al registrar.

---

## Orden de implementación propuesto

1. **§2 Calendario interactivo + §1 nuevo layout del Panel** (P1)
2. **§3 Toggle Disponible/Ocupado dentro de Catálogo y Contratos** (P2)
3. **§6 Catálogo compartible (URL + PDF)** (P2)
4. **§8 Avisos al inquilino con lectura + §7 hub/pendientes de cobro** (P2)
5. **§5 Reseña super-admin → admin** (P3)
6. **§9 Mejoras al formulario de Inquilinos** (P3)
7. **§4 Chat interno sistema/admin/super-admin** (P4 — futuro, sin implementar)

## Decisiones pendientes (bloqueadores)

- **Unificar o separar** Control de pagos + Lecturas (§7-A vs §7-B).
- **Librería de calendario**: FullCalendar v6 local vs widget propio ligero (§2).
- **Alcance del aviso al inquilino (§8)**: solo cobros generados vs también mensajes libres.
- **PDF** del catálogo (§6): vista imprimible client-side vs generación server-side.