# Modelo de suscripción y monetización — Arriendo Fácil 2.0

> **Estado: PARCIALMENTE IMPLEMENTADO.**
> - **Implementado (2026-09):** el flujo de **auto-registro público del
>   administrador** (§6) con verificación de correo obligatoria, siembra de
>   demo aislada y onboarding supervisado. También se construyó la **página
>   pública de vista previa del panel** (`/ver-demo/`) que réplica el
>   dashboard read-only e invita a crear cuenta.
> - **Pendiente (no implementado):** planes trial/free/pago, tarjeta,
>   publicidad (ads), paywall por sección, límites cuantitativos y
>   automatizaciones premium (§2–§5, §7–§8 de moneda/metodo). No crear
>   código sobre esas secciones sin confirmación explícita del usuario.

## 1. Objetivo de negocio

Arriendo Fácil se vende como licencia a administradores de propiedades
(subadmins, rol `af_property_admin`). El modelo de acceso debe ser:

- **Uso gratuito posible, pero limitado** — especialmente en automatizaciones
  (generación de cargos, cobranza automática, notificaciones, etc).
- **Publicidad (ads)** visible en el plan gratuito.
- **14 días de prueba (trial)** con **todas las funciones activas y sin
  publicidad**, para que el cliente evalúe el producto completo.
- **Tarjeta de pago requerida al registrarse** ("1 mes gratis" como gancho).
  Si el cliente **retira la tarjeta** (o esta falla y no se resuelve), el
  sistema:
  - Vuelve a mostrar publicidad.
  - Limita secciones y límites cuantitativos (número de edificios, unidades,
    etc.) al nivel del plan gratuito.
- **Paywall por sección**: al intentar entrar a una función restringida para
  el plan actual, se muestra un modal explicando que debe pagar/actualizar el
  plan para acceder, en vez de ocultarla sin explicación.

## 2. Planes propuestos

| Plan | Duración | Publicidad | Automatizaciones | Límites cuantitativos |
|---|---|---|---|---|
| **Trial** | 14 días desde el registro (con tarjeta) | No | Todas activas | Sin límite (o el límite más alto, a definir) |
| **Free** (post-trial sin tarjeta, o trial expirado sin conversión) | Indefinido | Sí | Restringidas (ver §3) | Bajos (ej. 1 edificio, N unidades) |
| **Pago** (mensual, tarjeta activa y cobrando) | Mientras la tarjeta siga válida | No | Todas activas | Altos / sin límite |

Estados de una licencia (extiende `af_license_status`, hoy solo
`active`/`suspended` — ver §7):

```
registrado_con_tarjeta ──(14 días)──► trial_expirado
        │                                   │
        │ (cobro exitoso mes 1)             │ (nunca agregó tarjeta o falló el cobro)
        ▼                                   ▼
     pagado ◄──(reintento exitoso)──── pago_fallido
        │                                   │
        │ (tarjeta retirada / cancela)      │ (se agota reintento)
        ▼                                   ▼
      free (con ads, limitado)  ◄───────────┘
```

## 3. Matriz de funciones y límites (borrador, a validar con el usuario)

Esta tabla es un punto de partida — falta que el usuario confirme los
números exactos y qué automatizaciones cuentan como "premium".

| Función | Trial / Pago | Free |
|---|---|---|
| Nº de edificios | Ilimitado (o tope alto, ej. 20) | 1 |
| Nº de unidades por edificio | Ilimitado | Tope bajo (ej. 5) |
| Emisión mensual unificada automática (cron) | Sí | No — solo generación manual, uno por uno |
| Gestión de mora automática (flag overdue diario) | Sí | No — el estado se debe revisar manualmente |
| Estimador de precio de renovación | Sí | No |
| Flujos de notificaciones/alertas internas (30/60/90 días) | Sí | Solo alerta a 30 días |
| Centro de Facturación SRI | Sí | Sí (no se restringe — es un servicio ya vendido/operado aparte, revisar con el usuario) |
| Publicidad (banners internos en el panel) | No | Sí |
| Exportes / reportes (liquidación, estado de cuenta) | Sí | Vista básica, sin export |

**Pendiente de decidir con el usuario:** si la Facturación SRI (que ya es un
módulo maduro y "no tocar" según reglas del proyecto) debe quedar dentro del
paywall o mantenerse siempre disponible por ser un compromiso legal/fiscal
del arrendador, no un "premium feature".

## 4. Flujo de registro con tarjeta ("1 mes gratis")

1. El futuro administrador de propiedades se registra (ver §6 sobre
   auto-registro vs alta manual).
2. Se le pide tarjeta de pago al final del registro, con copy claro:
   *"Primer mes gratis. Después de 14 días de prueba completa (sin
   publicidad) se cobrará el plan mensual salvo que canceles antes."*
3. Si **no ingresa tarjeta**: cuenta queda en plan **Free** desde el día 1
   (con publicidad y límites), sin pasar por el trial premium.
4. Si **ingresa tarjeta**: cuenta pasa a **Trial** (14 días, todo
   desbloqueado, sin ads).
5. Al día 14:
   - Si el cobro del mes 1 es exitoso → **Pagado**.
   - Si falla el cobro o la tarjeta fue retirada antes → **Free** (ads +
     límites), con aviso in-app de qué pasó y cómo reactivar.
6. Mientras esté en **Pagado**, cada ciclo mensual repite el cobro. Si el
   cliente **quita la tarjeta manualmente** en cualquier momento, la cuenta
   baja a **Free** en el siguiente corte (no de inmediato a mitad de mes,
   para no cortar el servicio abruptamente — a confirmar con el usuario si
   se prefiere corte inmediato).

## 5. Paywall UX (modal de bloqueo)

- Al intentar abrir una sección/función restringida para el plan actual, no
  se oculta el menú: se permite el click y aparece un **modal de bloqueo**
  reutilizando el patrón `.af-modal` que ya existe en `assets/css/admin.css`
  (mismo patrón usado en `guests.php`/`buildings.php`/`collections.php`).
- Copy sugerido: *"Esta función es parte del plan Pro. Actualiza tu plan
  para desbloquear [nombre de la función]."* + botón "Actualizar plan" (lleva
  a la pantalla de facturación de la licencia).
- El modal debe ser un componente reutilizable (`af-paywall-modal`) que
  reciba el nombre de la función bloqueada, para no duplicar HTML/JS por
  cada pantalla.

## 6. Registro del administrador: auto-registro vs alta manual

### ✅ IMPLEMENTADO (2026-09)

El auto-registro público del administrador de propiedades **ya está
construido y operativo**. Ambos flujos coexisten:

- **Alta manual (ya existía, sigue igual)**: el super admin crea la cuenta
  desde `admin/views/property-admins.php` (sin correo de confirmación,
  `af_signup_source = 'manual'`). No dispara trial/tarjeta.
- **Auto-registro público (implementado)**:
  - Shortcode `[af_property_admin_signup]` en
    `includes/class-property-admin-registration.php`, renderizado en la
    página pública `/solicitar-demo/`
    (`page-solicitar-demo.php` del tema hijo `twentytwentyfive-child`).
  - Formulario público (fuera de wp-admin) con: nombre de empresa,
    responsable, correo, teléfono, identificación (cédula/RUC validada
    con `Arriendo_Facil_Identity_Validator`), nacionalidad, ciudad y
    contraseña con política estricta (≥10 chars, mayúscula, minúscula,
    número, símbolo).
  - Crea el usuario con rol `af_property_admin` reutilizando
    `Arriendo_Facil_Activator::ensure_owner_role()`.
  - Anti-abuso: nonce, honeypot, firma HMAC del formulario con timestamp,
    rate-limit por IP/UA y por correo, cifrado libsodium de datos
    sensibles (teléfono e identificación), y fingerprint de identificación
    para evitar duplicados.
  - **Verificación de correo obligatoria**: la cuenta nace con
    `af_admin_email_verified = 0`, se envía un correo con token de 24 h y
    el login se bloquea hasta verificarla
    (`enforce_email_verification_on_login`). Confirma la pregunta abierta 2
    de §6: **sí se requiere verificación de correo**.
  - Al verificar el token se siembra un **dataset demo aislado** por cuenta
    (`Arriendo_Facil_Property_Admin_Demo::seed_demo`, guardado en
    `af_demo_seeded` / `af_demo_dataset`): edificio, unidades, inquilino,
    contrato y cobros, todos con `_af_owner_id = user_id` para que nunca se
    mezclen con datos reales.
  - Onboarding supervisado (`includes/class-property-admin-onboarding.php`):
    la cuenta sigue con `af_admin_doc_status = 'pendiente'`; las páginas
    operativas (`GATED_SLUGS`, 17 slugs de negocio) quedan ocultas hasta
    que el prospecto completa perfil/documentos y el super admin aprueba
    desde Administradores. El Panel (`arriendo-facil`) y "Mi perfil"
    (`af-admin-profile`) sí quedan accesibles para que explore la demo.
  - Cron diario `af_admin_profile_reminders_cron` recuerda al prospecto los
    pasos pendientes (verificar correo, completar perfil) y alerta al
    equipo cuando hay documentos por revisar.
  - Emails de apoyo: verificación (24 h), reenvío, recordatorios de perfil,
    y notificación al equipo de un nuevo registro.

### Flujo de demo público (marketing → cuenta)

Además de la página `/solicitar-demo/`, se construyó una **vista previa
read-only del panel** en `/ver-demo/` (`page-ver-demo.php`):
- Réplica visual del dashboard usando el sistema `af-shell` del plugin
  (tokens + shell + forms) con **datos quemados de ejemplo** (KPIs,
  semáforo de cobros, calendario check-in/out/visitas, contratos 30/60/90,
  accesos rápidos, tareas). No toca la base de datos ni requiere auth.
- Los CTA del sitio (header, footer, hero y flip-cards de la home) apuntan
  a `af_demo_preview_url()` (`/ver-demo/`); el modal de esa página invita a
  **crear la cuenta** y lleva a `af_demo_signup_url()` (`/solicitar-demo/`).
- Helpers en `functions.php` del tema: `af_demo_preview_url()` y
  `af_ensure_demo_preview_page()` (auto-crea la página si falta, patrón
  igual al existente de `solicitar-demo`). Ambas páginas están en el
  sitemap.

### Preguntas resueltas

1. **¿El auto-registro reemplaza el panel de alta manual o coexisten?**
   → **Coexisten**, diferenciados por `af_signup_source` (`self` vs
   `manual`). El manual sigue siendo útil para venta asistida/VIP.
2. **¿Se requiere verificación de correo antes de dar acceso?**
   → **Sí, obligatoria** (token de 24 h, login bloqueado hasta verificar),
   además de revisión documental supervisada antes de datos reales.
3. **¿Quién procesa el cobro real? (pasarela recurrente)** → **Sigue
   abierta** — sin decisión ni implementación (ver §10).

## 7. Modelo de datos propuesto (a crear cuando se implemente, NO CREAR AHORA)

Extiende lo ya existente (`af_license_status` en usermeta, hoy solo
`active`/`suspended`).

> **Nota implementación (2026-09):** las metas `af_signup_source`,
> `af_admin_email_verified`, `af_admin_doc_status`, `af_admin_onboarding_step`
> y `af_demo_seeded`/`af_demo_dataset` ya existen y se usan en el
> auto-registro (§6). No duplicarlas; la tabla de suscripción de abajo es
> para la fase de monetización y aún NO existe.

- Nueva tabla `af_subscriptions` (o extender usermeta si se prefiere evitar
  una tabla nueva):
  - `user_id` (FK al `af_property_admin`)
  - `plan` (`trial` | `free` | `paid`)
  - `trial_ends_at` (datetime, null si nunca hubo tarjeta)
  - `card_on_file` (bool / token de la pasarela, nunca el PAN completo)
  - `payment_gateway` (string, ej. `deuna`)
  - `payment_status` (`none` | `active` | `past_due` | `canceled`)
  - `current_period_end` (datetime)
  - `ads_enabled` (bool, derivado de `plan` pero cacheado para no recalcular)
- Nuevos límites por plan: tabla estática en código (no DB) tipo
  `Arriendo_Facil_Plan_Limits::for_plan('free')` → array con
  `max_buildings`, `max_units`, `automations_enabled` (array de flags), etc.
- Nuevo helper `Arriendo_Facil_Subscription` (paralelo a
  `Arriendo_Facil_Tenancy`) con:
  - `get_plan_for_user($user_id)`
  - `is_feature_allowed($user_id, $feature_key)`
  - `remaining_quota($user_id, $resource)` (ej. edificios restantes)
  - Cron diario `af_expire_trials_cron` que baja de `trial` a `free` cuando
    `trial_ends_at` ya pasó y no hay cobro exitoso.

## 8. Puntos de integración con lo ya construido

- El paywall se apoya en `Arriendo_Facil_Tenancy::CAP`
  (`af_manage_properties`) para el control de acceso por rol/ownership que
  ya existe; el plan de suscripción es una capa **adicional** encima (no
  reemplaza el control de acceso multi-tenant, lo complementa).
- `admin/views/property-admins.php` (panel maestro) es el lugar natural para
  mostrar, además de las licencias, el plan/estado de suscripción de cada
  subadmin y forzar cambios manuales (ej. soporte extiende el trial).
- Los límites cuantitativos (nº de edificios/unidades) se deben verificar en
  los mismos puntos donde hoy se crean (`ajax_create_building`,
  `ajax_create_unit` en `class-property-structure.php`) antes de insertar.

## 9. Fases de implementación sugeridas (futuro, no iniciar sin confirmación)

1. Modelo de datos + helper `Arriendo_Facil_Subscription` + cron de
   expiración de trial.
2. Enforcement de límites cuantitativos (edificios/unidades) en los AJAX ya
   existentes.
3. Enforcement de automatizaciones (bloquear cron-only features en plan
   free, no solo la UI).
4. Componente de paywall modal reutilizable + wiring en los menús/páginas
   restringidas.
5. Publicidad (ads) — definir si son banners propios (afiliados/patrocinios)
   o red de ads externa (Google AdSense no aplica bien a wp-admin; más
   probable que sean banners propios promocionando el upgrade).
6. Tarjeta + pasarela de cobro recurrente. *Nota: el registro público ya está
   implementado (§6); falta solo la parte de cobro/plan.*
7. Panel de soporte/facturación para que el super admin gestione cambios de
   plan manuales, reembolsos, extensiones de trial, etc.

## 10. Preguntas abiertas para el usuario (bloquean implementación)

- Números exactos de la matriz de límites (§3).
- Pasarela de pago a usar para cobros recurrentes de la licencia (no del
  dispersión a propietarios, que es un flujo distinto ya documentado en
  `docs/payments/`).
- ~~Si el auto-registro público reemplaza o coexiste con el alta manual del
  super admin~~ → **RESUELTO (2026-09): coexisten** vía `af_signup_source`
  (`self`/`manual`), ver §6.
- ~~Si la verificación de correo es obligatoria~~ → **RESUELTO (2026-09):
  sí es obligatoria** (token 24 h + revisión documental supervisada), ver §6.
- Si la Facturación SRI queda dentro o fuera del paywall.
- Comportamiento exacto al quitar la tarjeta: ¿degradación inmediata o al
  cierre del ciclo ya pagado?
