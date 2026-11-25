# Portal del Inquilino v2 — Diseño

> **Alcance:** `https://sitio/inquilino/` sigue siendo un portal **web público con acceso por código (OTP)**, no una sesión de WordPress. Pasa de ser de solo lectura (más un formulario de novedades) a ser un espacio de trabajo del inquilino con **Perfil y documentos**, **Pagos de servicios** e **Incidentes**, donde todo lo que haga se refleja en el panel interno del gestor y deja **evidencia verificable** para ambas partes.
>
> **Principio rector:** una sola fuente de verdad. El portal escribe en las mismas tablas que usa el panel interno (`af_guests`, `af_service_schedules`, `af_service_bill_payments`, `af_cleaning_requests`, `af_guest_documents`); no se duplican datos.

---

## 1. Punto de partida (lo que ya existe)

| Pieza | Estado hoy | Archivo |
|---|---|---|
| Acceso OTP 6 dígitos, sesión por cookie HttpOnly, CSRF, límites por IP/correo, cabeceras anti-caché/noindex | **Sólido, se conserva** | `includes/tenants/class-tenant-access.php` |
| Pantalla del portal (Mi perfil / Pagos / Mantenimiento) | Perfil **solo lectura**; pagos = cobros + abonos; mantenimiento = formulario **sin fotos** | `public/tenant-portal.php` |
| Pagos de servicios (gestor) | Tarjeta por inmueble con un tile por servicio; el gestor marca "pagado" sin comprobante ni responsable | `admin/views/meter-readings.php`, `af_service_schedules`, `af_service_bill_payments` |
| Mantenimiento (gestor) | `af_cleaning_requests` con estado, prioridad, costo, proveedor; **sin fotos ni responsabilidad** | `includes/class-maintenance.php` |
| Documentos del inquilino | `af_guest_documents` en R2 privado (fallback local), descarga solo para el gestor | `includes/class-document-verification.php`, `includes/storage/` |
| Movimiento | GSAP ya vendorizado + motor `af-gsap.js`; tokens "Escalera petróleo" | `assets/js/vendor/gsap.min.js`, `assets/css/af-tokens.css` |

**Huecos que este diseño cierra:** (a) el servicio no dice quién lo paga; (b) no hay comprobantes ni aprobación; (c) no hay fotos en incidentes ni constancia de reparación; (d) no hay noción de responsabilidad ni de reincidencia; (e) el inquilino no puede editar su perfil ni subir documentos; (f) no hay bitácora inmutable.

---

## 2. Experiencia (UX)

### 2.1 Estructura

Mismo lenguaje visual que el panel interno (tokens petróleo `#164A55`, superficies planas, sin sombras en reposo, Inter, `prefers-reduced-motion` respetado).

```
┌ Cabecera: "Hola, Marlene" · inmueble activo (selector si hay >1) · Salir
├ Resumen (KPIs)
│   [Servicios por pagar]  [Incidentes abiertos]  [Documentos pendientes]  [Contratos vigentes]
└ Navegación: Resumen · Servicios · Incidentes · Perfil
```

- **Escritorio (≥ 1024px):** contenedor de ~1080px, navegación como pestañas superiores; Resumen en dos columnas (acciones pendientes a la izquierda, actividad reciente a la derecha).
- **Tableta:** una columna ancha, KPIs 2×2.
- **Teléfono (≤ 782px):** **barra inferior fija** (Resumen · Servicios · Incidentes · Perfil) igual que el panel interno; acciones principales como botones de ancho completo; el selector de archivos abre cámara directamente (`<input type="file" accept="image/*" capture="environment">`). Objetivos táctiles ≥ 44px.
- El saldo/cobros del canon y alícuota actuales se mantienen dentro de **Resumen** (bloque "Cuenta") para no perder lo que ya existe.

### 2.2 Movimiento (GSAP)

Reutilizar `af-gsap.js` (entrada escalonada, contadores de cifras, barras, hoja inferior en móvil, `afMotion.reveal/pop`). Ajustes: ampliar sus selectores de cabecera a `.af-tp-head`; GSAP **autoalojado** (ya en `assets/js/vendor/`), nunca desde CDN. Sin GSAP o con movimiento reducido, nada se oculta.

### 2.3 Pagos de servicios

**Lado gestor (panel interno):** en cada tile de servicio se añade un selector segmentado **"Lo paga: Propietario | Inquilino"** (campo `payer`). Por defecto *Propietario* (comportamiento actual, no rompe nada). Al elegir *Inquilino*, el servicio aparece de inmediato en el portal de ese inquilino.

**Lado inquilino:** lista de servicios con `payer = tenant` del mes, con el mismo código de color de vencimiento del panel (próximo / hoy / vencido).

```
Luz · vence 5 dic            $23.40        [Pendiente]
  [Marcar como pagado]  →  hoja: fecha, monto, referencia,
                              adjuntar comprobante (foto/PDF, 1–5 archivos)  →  Enviar
```

Estados del pago (máquina simple):

`pendiente → enviado (inquilino) → aprobado | rechazado (gestor, con motivo) → [rechazado] → reenviado`

- El inquilino **no puede** poner "aprobado". Solo el gestor aprueba/rechaza (con nota).
- Un pago *aprobado* queda **bloqueado**; cualquier corrección se hace con un nuevo evento, nunca editando el anterior.
- **Historial** por servicio y período, visible para ambos: quién subió qué, cuándo, y quién lo aprobó. Es el respaldo ante reclamos.
- El gestor ve una cola **"Comprobantes por revisar"** (badge en el menú + alerta en `af_alerts` + correo) y puede aprobar desde la misma tarjeta.
- Servicios `payer = owner` no aparecen al inquilino (o aparecen como informativos, sin acción; decisión abierta §9).

### 2.4 Incidentes

**Reportar:** tipo, ubicación, urgencia, descripción y **1–6 fotos** (opcional un video corto ≤ 20 s, fase 2). Se crea en `af_cleaning_requests` con `reported_by = inquilino`; el gestor lo ve en Mantenimiento con la galería.

**Responsabilidad** (el núcleo de tu requisito). Se añade `responsibility` al incidente: `owner` · `tenant` · `por_definir`.

1. Primera avería de un bien: el gestor la repara → `responsibility = owner`.
2. Si ese **mismo bien** (misma ubicación/nombre de activo en el mismo contrato) vuelve a fallar después de una reparación del propietario, el sistema **marca "Reincidencia"** y **sugiere** `tenant` al gestor, enlazando el incidente anterior (`recurrence_of_id`).
3. **El sistema solo sugiere; el gestor decide y confirma.** La responsabilidad tiene consecuencias económicas/legales y no debe decidirla un algoritmo. La decisión queda en la bitácora con quién y cuándo, y el inquilino la ve con la explicación y la evidencia de la reparación anterior.

**Constancia de reparación:**

- Reparación del **propietario**: el gestor sube fotos/factura "antes/después" (`stage = repair`) y cierra. El inquilino ve la evidencia y pulsa **"Recibí conforme"** (o "No estoy conforme" + nota). Ese acuse es la constancia.
- Reparación del **inquilino** (`responsibility = tenant`): en su tarjeta aparece "Te corresponde repararlo"; sube fotos del resultado y/o factura; el gestor verifica y cierra.
- Línea de tiempo por incidente: reportado → revisado → responsabilidad definida → reparado → conforme. Cada paso con autor, fecha y evidencia.
- **Historial por inmueble/contrato** exportable (PDF) para el gestor: todas las reparaciones del propietario y las del inquilino. Útil en una disputa o en la liquidación de la garantía.

### 2.5 Perfil y documentos

| Campo | ¿Edita el inquilino? | Regla |
|---|---|---|
| Teléfono, contacto de emergencia, dirección, ocupación | Sí | Valida formato; cambia en `af_guests` al instante |
| Correo | Sí, con **re-verificación** | Código OTP al correo **nuevo**; avisa al correo anterior; invalida otras sesiones |
| Nombre, identificación | **No** directamente | "Solicitar corrección": el gestor aprueba (evita suplantación) |
| Documentos | Sube y reemplaza | Ver abajo |

**Documentos:** reutiliza los tipos existentes (`cedula_papeleta`, `certificado_bancario`, `certificado_laboral`, `antecedentes_penales`, `garantia_alicuota`) más `otro`. Cada uno muestra estado **Pendiente · En revisión · Verificado · Rechazado (motivo)** usando `Arriendo_Facil_Document_Verification`. Hoy se aceptan solo PDF; se amplía a JPG/PNG/WebP/PDF para que pueda fotografiarlos desde el móvil.

**Reflejo en el gestor:** cada cambio actualiza la ficha del inquilino y deja una marca **"Actualizado por el inquilino"** con el detalle (campo, valor anterior → nuevo) y una alerta; los documentos nuevos entran a su cola de verificación.

---

## 3. Modelo de datos

Migraciones idempotentes con `dbDelta`/`ensure_*` (mismo patrón que `ensure_service_label_column`).

```sql
-- 1) Quién paga cada servicio
ALTER TABLE af_service_schedules
  ADD COLUMN payer VARCHAR(10) NOT NULL DEFAULT 'owner';          -- owner | tenant

-- 2) Estado y trazabilidad del pago de servicio
ALTER TABLE af_service_bill_payments
  ADD COLUMN status        VARCHAR(12) NOT NULL DEFAULT 'approved', -- submitted|approved|rejected (históricos = approved)
  ADD COLUMN submitted_by  VARCHAR(10) NOT NULL DEFAULT 'manager',  -- tenant|manager
  ADD COLUMN guest_id      BIGINT UNSIGNED NULL,
  ADD COLUMN reviewed_by   BIGINT UNSIGNED NULL,
  ADD COLUMN reviewed_at   DATETIME NULL,
  ADD COLUMN review_note   VARCHAR(255) NULL;

-- 3) Responsabilidad y reincidencia en incidentes
ALTER TABLE af_cleaning_requests
  ADD COLUMN responsibility     VARCHAR(12) NOT NULL DEFAULT 'por_definir', -- owner|tenant|por_definir
  ADD COLUMN responsibility_by  BIGINT UNSIGNED NULL,
  ADD COLUMN responsibility_at  DATETIME NULL,
  ADD COLUMN recurrence_of_id   BIGINT UNSIGNED NULL,
  ADD COLUMN tenant_ack         VARCHAR(12) NULL,        -- conforme | no_conforme
  ADD COLUMN tenant_ack_at      DATETIME NULL,
  ADD COLUMN tenant_ack_note    VARCHAR(255) NULL;

-- 4) Evidencia (fotos, comprobantes, facturas). Solo se agrega, no se edita.
CREATE TABLE af_evidence (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  guest_id      BIGINT UNSIGNED NOT NULL,
  lease_id      BIGINT UNSIGNED NULL,
  subject_type  VARCHAR(20) NOT NULL,   -- service_payment | incident
  subject_id    BIGINT UNSIGNED NOT NULL,
  stage         VARCHAR(12) NOT NULL,   -- report | repair | payment
  uploaded_by   VARCHAR(10) NOT NULL,   -- tenant | manager
  storage       VARCHAR(10) NOT NULL,   -- r2 | local (privado)
  object_key    VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(100) NOT NULL,
  file_size     INT UNSIGNED NOT NULL,
  checksum_sha256 CHAR(64) NOT NULL,
  removed_at    DATETIME NULL, removed_by BIGINT UNSIGNED NULL, removed_reason VARCHAR(190) NULL,
  created_at    DATETIME NOT NULL,
  KEY subject (subject_type, subject_id), KEY guest_id (guest_id)
);

-- 5) Bitácora de eventos (línea de tiempo + auditoría). Solo INSERT.
CREATE TABLE af_portal_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  guest_id    BIGINT UNSIGNED NOT NULL,
  lease_id    BIGINT UNSIGNED NULL,
  subject_type VARCHAR(20) NOT NULL, subject_id BIGINT UNSIGNED NOT NULL,
  actor_type  VARCHAR(10) NOT NULL,     -- tenant | manager | system
  actor_id    BIGINT UNSIGNED NULL,
  action      VARCHAR(40) NOT NULL,     -- payment_submitted, payment_approved, incident_reported, responsibility_set, …
  detail      TEXT NULL,                -- JSON: valores anterior/nuevo (sin datos sensibles en claro)
  ip_hash     CHAR(32) NULL, ua_hash CHAR(32) NULL,
  created_at  DATETIME NOT NULL,
  KEY subject (subject_type, subject_id), KEY guest_id (guest_id)
);
```

Notas: `af_service_bill_payments` tiene clave única `(schedule_id, period)`: el estado vigente vive ahí y el **historial completo** vive en `af_portal_events` + `af_evidence`, así un rechazo y reenvío no borra la prueba anterior. Los registros existentes quedan como `approved/manager`, sin cambio de comportamiento.

---

## 4. Seguridad (requisito prioritario)

### 4.1 Se conserva (ya está bien hecho)
OTP con HMAC y 10 min de vigencia, 5 intentos, un solo uso; respuestas genéricas anti-enumeración; límites por IP y correo; cookie `HttpOnly` + `Secure` + `SameSite=Lax`; token de sesión solo como hash en BD; CSRF atado a la sesión en toda escritura; `no-store`, `noindex`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`; identificación enmascarada.

### 4.2 Se añade

**Autorización (lo más importante: evitar IDOR).** Todo endpoint nuevo deriva `guest_id` **solo de la sesión**, nunca del request, y valida la pertenencia en SQL (`WHERE id = %d AND guest_id = %d`) para contrato, servicio, incidente y evidencia. Un helper único `owns_lease() / owns_subject()` usado por todos los handlers, con tests que intentan acceder a recursos de otro inquilino.

**Subida de archivos (la nueva superficie de ataque).**
- Lista blanca por **contenido real** (`finfo` + cabeceras mágicas), no por extensión ni por `Content-Type` del cliente: JPG, PNG, WebP, HEIC→convertido, PDF. Rechazar todo lo demás.
- Tamaño máx. 8 MB por archivo, 6 archivos por envío, cuota por inquilino y por día, límite de velocidad.
- Las imágenes se **re-codifican** (GD/Imagick) para eliminar EXIF/GPS y cualquier carga embebida y se reducen a un tamaño máximo; los PDF se validan (sin JS ni acciones embebidas).
- Clave de objeto **aleatoria** (`evidence/{guest}/{random}.ext`), bucket **privado**, sin URL pública jamás.
- `checksum_sha256` al guardar para demostrar que la evidencia no fue alterada.
- Descarga solo por endpoint autenticado que valida propiedad y emite **URL prefirmada de ≤ 60 s** o hace stream con `Content-Disposition` y `X-Content-Type-Options: nosniff`. El gestor verifica `Arriendo_Facil_Tenancy::can_access_guest()`.
- ⚠ **Verificar antes de construir:** la columna `storage` de `af_guest_documents` documenta el fallback local como "público WP". Confirmar que `Arriendo_Facil_Local_Private_Storage` queda **fuera de la raíz web** (existe `is_inside_web_root()`); si no, la evidencia no puede usar ese fallback.

**Inmutabilidad y prueba.** El inquilino puede **agregar** evidencia pero no borrarla ni editarla; solo el gestor puede retirarla (soft-delete con motivo, queda en bitácora). Un pago aprobado o un incidente cerrado no admite edición, solo nuevos eventos.

**Sesión y cambios sensibles.**
- Inactividad **30 min** (hoy 2 h) por manejar documentos de identidad; tope absoluto 12 h se mantiene. Aviso a los 2 min antes de expirar.
- *Step-up*: cambiar correo exige OTP al correo nuevo; se invalidan las demás sesiones y se avisa al correo anterior. Cambiar teléfono notifica por correo.
- Reautenticación (OTP) para **descargar/ver** documentos de identidad completos; el listado muestra solo nombre del tipo y estado.

**Cabeceras del portal.** Añadir `Content-Security-Policy` estricta (`default-src 'self'; script-src 'self'; img-src 'self' blob: data:; frame-ancestors 'none'`), GSAP autoalojado, sin scripts inline. `Permissions-Policy` ya bloquea cámara por API; la captura vía `<input capture>` no se ve afectada.

**Datos personales.** Nunca registrar PII ni rutas de archivos en logs; la bitácora guarda hash de IP/UA, no valores en claro. Retención y derecho de acceso/eliminación alineados con `docs/PRIVACY_POLICY.md` y `class-account-deletion.php` (extender para incluir `af_evidence` y `af_portal_events`). Contenido de la evidencia visible solo para el inquilino y el gestor dueño del inmueble.

**Aislamiento entre gestores.** Cada gestor es un cliente separado: toda consulta del lado gestor sigue pasando por `Arriendo_Facil_Tenancy` (accesibles por `accommodation_id`/`can_access_guest`).

---

## 5. Cambios en el panel interno (reflejo para el gestor)

1. **Pagos de servicios:** selector "Lo paga" en el tile y en el modal de alta; indicador *"Comprobante por revisar"* con miniatura; botones **Aprobar / Rechazar (con motivo)**; historial por servicio.
2. **Mantenimiento:** galería de fotos del reporte; campo **Responsabilidad** con aviso de **Reincidencia** y enlace al incidente previo; subida de evidencia de reparación; línea de tiempo; estado del acuse del inquilino.
3. **Inquilino (ficha):** insignia "Actualizado por el inquilino" con el diff; pestaña de **Documentos** con estado y cola de verificación; botón existente de enviar acceso al portal se mantiene.
4. **Alertas:** nuevos tipos en `af_alerts` (comprobante recibido, incidente nuevo, documento subido, perfil actualizado, inquilino no conforme) + correo `CAT_NOTIFICATION`.

---

## 6. API (AJAX, patrón actual `af_tpa_*`)

Todos exigen sesión + CSRF y derivan el inquilino de la sesión.

| Acción | Función |
|---|---|
| `af_tpa_profile_save` | Edita campos permitidos; registra diff |
| `af_tpa_email_change_request / _verify` | Cambio de correo con OTP |
| `af_tpa_doc_upload / _list / _view` | Documentos propios |
| `af_tpa_service_list` | Servicios `payer=tenant` del período |
| `af_tpa_service_pay` | Envía pago + evidencia (multipart) |
| `af_tpa_incident_report` | Reemplaza `af_tpa_report`; ahora con fotos |
| `af_tpa_incident_evidence` | Agrega evidencia de reparación (si `responsibility=tenant`) |
| `af_tpa_incident_ack` | "Recibí conforme / no conforme" |
| `af_tpa_evidence_view` | Stream/URL prefirmada tras validar propiedad |

Lado gestor (autenticados con WP + nonce): `af_service_payer_set`, `af_service_payment_review`, `af_incident_responsibility_set`, `af_incident_repair_evidence`, `af_evidence_remove`.

---

## 7. Implementación (estructura de código)

- `includes/tenants/class-tenant-access.php` se mantiene para **acceso y sesión**; la lógica nueva va en clases separadas para no inflar el archivo: `class-tenant-evidence.php` (subida, validación, almacenamiento), `class-tenant-services.php`, `class-tenant-incidents.php`, `class-tenant-profile.php`, `class-portal-events.php`.
- `public/tenant-portal.php` → dividir en parciales `public/tenant-portal/{summary,services,incidents,profile}.php`.
- `assets/js/af-tenant-portal.js` (hoy 248 líneas) → módulos por sección + componente de subida (vista previa, compresión en cliente, barra de progreso).
- CSS: ampliar `af-tenant-portal.css` con los tokens del sistema (`af-tokens.css`), mobile-first.
- Pruebas PHPUnit (existe `tests/`): propiedad/IDOR, validación de archivos (MIME falso, polyglot, tamaño), máquina de estados del pago, regla de reincidencia, cuotas y límites.

## 8. Fases

| Fase | Entrega | Valor |
|---|---|---|
| **1. Cimientos** | Tablas `af_evidence` / `af_portal_events`, clase de evidencia segura, CSP, sesión 30 min, helpers de propiedad + tests | Base segura para todo lo demás |
| **2. Servicios** | Campo `payer`, vista inquilino, envío con comprobante, cola y aprobación del gestor, historial | Respuesta al primer requisito |
| **3. Incidentes** | Fotos, responsabilidad, reincidencia, evidencia de reparación, acuse, línea de tiempo | Respaldo ante reclamos |
| **4. Perfil y documentos** | Edición con diff, cambio de correo con OTP, documentos con estado, reflejo en la ficha | Datos siempre al día |
| **5. Pulido** | Resumen/KPIs, GSAP, responsive final, exportación PDF del historial, revisión de seguridad | Cierre |

## 9. Decisiones tomadas (25 nov. 2026) y desviaciones del diseño

**Decisiones del producto**

1. El inquilino ve **solo** los servicios que le corresponden; el gestor ve los de ambos.
2. La reincidencia **solo sugiere**; el gestor decide por escrito, con motivo obligatorio (mín. 10 caracteres), y queda en la bitácora visible para ambas partes. Un incidente reportado desde el portal **no se puede cerrar** sin responsabilidad definida.
3. Solo fotos (y PDF), sin video.
4. Nombre e identificación: solo por solicitud de corrección al gestor.
5. Aplica a todos los planes.

**Desviaciones respecto al diseño original (y por qué)**

- **CSP sin `script-src`:** la plantilla del tema trae scripts en línea; una política estricta rompería la cabecera del sitio. Se aplican `frame-ancestors`, `object-src 'none'`, `base-uri` y `form-action` (el resto del endurecimiento sigue: cabeceras anti-caché, noindex, sin Referer). GSAP va autoalojado.
- **Documentos de identidad no se pueden ver ni descargar desde el portal:** en lugar de exigir un OTP extra para verlos, simplemente no se exponen. El inquilino ve tipo, fecha y estado; solo el gestor los abre.
- **Costo de reparación del propietario:** cuando `responsibility = owner`, el costo **ya no se descuenta de la garantía** del inquilino (antes se descontaba siempre). Incidentes anteriores sin responsabilidad definida conservan el comportamiento previo.
- **Servicios que paga el inquilino:** no se les genera cobro en su cuenta (`generate_service_charges` los omite) porque paga directo al proveedor; se evita cobrarlo dos veces.
- **Sesión:** 30 min de inactividad (antes 2 h), con aviso 2 min antes y botón "Seguir aquí".

**Pendiente / no incluido**

- Exportación PDF del historial por inmueble.
- Cola de aprobación de correcciones de nombre/identificación (hoy el gestor ve la solicitud en la ficha del inquilino y edita el dato él mismo).
- Revisión legal del valor probatorio y la retención de la evidencia.
