# Alineación con el modelo de negocio (8 módulos) — 2026-09

> Mapea la especificación de negocio (8 módulos) recibida del usuario contra
> lo que YA existe en el código, para tener un único documento de
> consistencia. No se implementó nada nuevo de lógica de negocio a partir de
> este documento salvo lo explícitamente indicado en cada sección.

## Roles (confirmado, sin cambios)

| Rol | Capability | Alcance | Dónde |
|---|---|---|---|
| **Super admin** | `manage_options` | Ve TODO el negocio (todos los subadmins). Panel maestro de licencias. | WP admin nativo (`administrator`) |
| **Administrador de Propiedades** (subadmin, `af_property_admin`) | `af_manage_properties` (`Arriendo_Facil_Tenancy::CAP`) | Solo SUS edificios/unidades/inmuebles/contratos/inquilinos/cargos/mantenimiento (`_af_owner_id` / `owner_id` en `af_buildings`) | [includes/class-tenancy.php](../../includes/class-tenancy.php) |

El filtro "por administrador asignado" que ve el super admin en el Panel
(`admin/views/dashboard.php`, `$_GET['af_admin_id']`) y el panel maestro
[admin/views/property-admins.php](../../admin/views/property-admins.php) son
la capa de supervisión agregada del super admin sobre todos los subadmins.

## Módulo 1 — Panel de Control (Dashboard)

| Pedido | Estado |
|---|---|
| Filtro de rango de fechas para alertas de calendario | ✅ `af_date_from`/`af_date_to` en dashboard.php |
| Filtro por propiedad/edificio/administrador | ✅ `af_accommodation_id`, `af_building_id` (todos); `af_admin_id` solo super admin |
| Alertas de calendario (visitas, check-in, check-out) | ✅ 3 columnas en dashboard.php |
| Resumen rápido de Propiedades/Contratos/Huéspedes | ✅ KPI grid |
| Semáforo de cobros (Cobrado/Pendiente/Atrasado + días de mora) | ✅ `.af-semaforo` + tabla "top mora" |
| Próximas salidas 30/60/90 días | ✅ sección "Contratos por vencer" + [admin/views/upcoming-exits.php](../../admin/views/upcoming-exits.php) |
| Resumen de mantenimientos por prioridad | ✅ KPI "Mantenimiento" (crítica/media/baja) |
| **Gráficos (ocupación, ingresos)** | ⚠️ **Faltaba** — agregado en esta sesión: donut "Resumen de ocupación" + línea "Ingresos por arriendos" (Chart.js) |

## Módulo 2 — Gestión de Propiedades

| Pedido | Estado |
|---|---|
| Alta con dirección, hab/baños, canon, garantía, disponibilidad | ✅ wizard `admin/views/accommodation-wizard.php` |
| Inventario de bienes/electrodomésticos | ✅ `_af_inventory` (meta box, tabla JSON) |
| Catálogo visual | ✅ `admin/views/catalog.php` |
| Consumos por medidor (agua/luz/gas) | ✅ `admin/views/meter-readings.php` + `class-billing-ledger.php::record_meter_reading()` |
| Sugerencia de precio (algoritmo) | ✅ `Arriendo_Facil_Accommodation::estimate_monthly_rent()` — promedio estadístico de comparables, NO IA de pago |

## Módulo 3 — Contratos y Expediente Digital

| Pedido | Estado |
|---|---|
| Alta con inmueble/huésped, fechas, canon+alícuota, día de pago, plantilla | ✅ `admin/views/leases.php` |
| Estados Activo/Terminado | ✅ `af_leases.status` |
| Generador de documentos desde plantilla | ✅ `class-docx-template-processor.php` (mammoth+plantillas) |
| Estado de notarización (pendiente/en proceso/notarizado) | ✅ `legal_status` en `af_leases` + timeline en ficha del inquilino |
| Emisión mensual automática canon+alícuota | ✅ cron `af_generate_monthly_charges_cron` -> `generate_monthly_charges()` |
| Liquidación de garantía al check-out | ✅ `admin/views/upcoming-exits.php` (calculadora en vivo, descuenta daños/consumos) |

## Módulo 4 — Huéspedes/Arrendatarios

| Pedido | Estado |
|---|---|
| Datos personales (nombre, nacionalidad, celular, correo, ciudad) | ✅ `admin/views/guests.php` (alta) + formulario público con token |
| Documentos (cédula, cert. laboral, cert. bancario) | ✅ subida por token, storage privado R2 (`class-private-storage.php`) |
| Flujo de enlaces/recordatorios automáticos | ✅ `af_send_guest_profile_link` + cron de recordatorios |
| Validación de identidad (criterio humano) | ✅ `Document_Verification::set_status` (aprobar/rechazar manual) + `Identity_Validator` (dígito verificador automático, cotejo PDF como señal de apoyo no decisoria) |
| Base de datos unificada / histórico | ✅ [admin/views/guest-profile.php](../../admin/views/guest-profile.php) ("Ficha del inquilino") |

## Módulo 5 — Reviews / Valoración

| Pedido | Estado |
|---|---|
| Calificación cualitativa + observaciones | ✅ `admin/views/reviews.php`, 4 criterios (puntualidad, cuidado, convivencia, comunicación) |
| Score de Pago Real (automático) | ✅ `Review::suggest_payment_score()` — deriva puntualidad del ledger, preseleccionado en el modal |
| Ficha de calidad integrada | ✅ ficha del inquilino combina score + reseña cualitativa |
| Visualización interna (privado) | ✅ nunca se expone públicamente en el modelo interno (reviews owner→tenant, no hay reviews públicas del inquilino) |

## Módulo 6 — Pagos, Dispersión y Cobros

| Pedido | Estado |
|---|---|
| Registro de pago (monto, fecha, referencia) | ✅ `admin/views/collections.php` -> `af_record_payment` |
| Lecturas de medidores | ✅ `meter-readings.php` |
| Semáforo detallado + mora diaria | ✅ cron `af_flag_overdue_charges` (diario) + `.af-semaforo` |
| Suma automática servicios+canon+alícuota | ✅ `generate_monthly_charges()` + `record_meter_reading()` genera cargo |
| Dispersión de fondos a propietarios | ✅ `class-owner-settlement.php` + `admin/views/owner-settlements.php` (liquidación imprimible, NO integra pasarela de pago real — es cálculo/reporte, no transferencia bancaria automatizada) |

## Módulo 7 — Facturación Electrónica SRI

| Pedido | Estado |
|---|---|
| Usuario/clave SRI + firma .p12/.pfx | ✅ `admin/views/billing-settings.php` |
| Emisión en 1 clic | ✅ `admin/views/billing.php` |
| Historial (emitidas/anuladas/pendientes) + filtros | ✅ tabs + búsqueda |
| Autorización manual (criterio humano) | ✅ siempre requiere click explícito del administrador |

**Regla del proyecto (reafirmada):** este módulo NO se toca a nivel de
lógica/AJAX/firma; solo se le aplicó responsive puramente visual en una
sesión anterior.

## Módulo 8 — Mantenimiento e Incidencias

| Pedido | Estado |
|---|---|
| Alta (inmueble, tipo, descripción, prioridad) | ✅ `admin/views/maintenance.php` |
| Bandeja tipo Kanban/lista | ✅ grid de tarjetas `.af-maint-card`, borde de color por prioridad |
| Estados Registrado/En proceso/Resuelto | ✅ `class-maintenance.php` |
| Costo asociado, descuento en liquidación/garantía | ✅ `cost` en `af_cleaning_requests`, usado por `Owner_Settlement` y por la calculadora de garantía |

## Gaps reales encontrados en esta pasada

1. **Gráficos en el Panel** — el dashboard tenía KPIs y semáforo pero ningún
   gráfico (donut/línea). Se agregó con Chart.js (ver
   [DASHBOARD_LIBRARIES.md](DASHBOARD_LIBRARIES.md)).
2. **Panel del super admin sin visual comparativo** — `property-admins.php`
   era 100% tabular. Se agregó un gráfico de barras (cobrado vs. pendiente
   por administrador) reutilizando el mismo Chart.js.
3. **Separación "propietario real" vs. "subadmin que administra"** — sigue
   pendiente (ya documentado en `arriendo-facil-2.0-migration` memoria y en
   `SUBSCRIPTION_MODEL.md`), no se tocó en esta pasada.
4. **Monetización (trial/free/pago, ads, paywall)** — sigue sin implementar,
   ver `SUBSCRIPTION_MODEL.md`. Este documento de negocio (8 módulos) NO
   menciona el modelo de suscripción, así que se asume que son
   preocupaciones independientes: los 8 módulos son "qué hace el producto",
   la suscripción es "cómo se cobra el acceso al producto".
