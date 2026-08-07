# Plan de Rediseño Visual — Panel Interno Arriendo Fácil

> **Alcance:** rediseño **puramente visual y de interacción** del panel interno de WordPress (`/wp-admin`) usado por administradores y propietarios (`af_owner`). No modifica lógica de negocio, endpoints, capabilities ni estructura de base de datos.
>
> **Inspiración:** Booking.com (densidad informativa, confianza, jerarquía clara, calendarios) + Airbnb (calidez, aire, foto grande, tipografía humanista, cards redondeadas).
>
> **Restricción:** debe convivir con la UI nativa de WordPress (`.wrap`, `.wp-list-table`, `.button`, `notices`) porque las vistas se montan dentro de `#wpbody-content`.

---

## 1. Análisis del backend (contexto)

### 1.1 Arquitectura del panel

```
arriendo-facil.php                  ← bootstrap del plugin (equivalente al functions.php)
└── admin/class-admin.php           ← Arriendo_Facil_Admin
    ├── add_menu()                  → registra top-level "Arriendo Fácil" + 10 submenús
    ├── remove_menus_for_owner()    → oculta menús nativos WP a rol af_owner
    ├── redirect_owner_*()          → owner aterriza siempre en admin.php?page=arriendo-facil
    ├── enqueue_assets( $hook )     → carga admin.css + admin.js en TODAS las pantallas del plugin
    └── render_*()                  → include admin/views/{vista}.php
```

### 1.2 Vistas actuales y peso

| Vista | Líneas | Rol | Estado visual |
|---|---:|---|---|
| `dashboard.php` | 103 | admin + owner | KPIs planos, sin tendencias |
| `leases.php` | 555 | admin + owner | tabla WP densa, acciones apiladas |
| `cleaning-requests.php` | 243 | admin + owner | tabla WP, estados sin color |
| `guests.php` | 420 | admin + owner | cola + score IA sin visualización |
| `reviews.php` | 150 | admin + owner | estrellas texto plano, sin gráfico |
| `owner-contacts.php` | 386 | admin | tabla + modal legal agent |
| `billing.php` + `billing-settings.php` | 1 940 | admin | ya usa hero + status grid (`.af-sri-*`), buena base |
| `ai-settings.php` | 401 | admin | form WP nativo |
| `accommodation-wizard.php` | 774 | admin + owner | ya rediseñado (referencia interna del nuevo sistema) |
| `ota-*` (3 vistas) | 655 | admin | tablas WP |

### 1.3 Deuda visual detectada

1. **Tokens dispersos:** `admin.css` mezcla colores hard-coded (`#2271b1`, `#2753a6`, `#065f46`, `#dc2626`, `#7f1d1d`, `#1d6c00`, `#123a75`, `#b32d2e`…). No hay variables CSS.
2. **Dos lenguajes visuales conviviendo:** el resto del panel usa WP core (bordes cuadrados `4px`, tipografía Open Sans/system), pero `admin-wizard.css` y `af-sri-*` ya usan otro (radios `8–14px`, sombras suaves, grises `#f3f4f6`, `#1f2937`). Consolidar el "lenguaje wizard" como estándar.
3. **Dashboard sin narrativa:** solo conteos absolutos, sin comparativa, sin tendencias, sin acciones contextuales.
4. **Estados sin color semántico:** `pending`, `active`, `completed`, `terminated`, `expired` se renderizan como texto plano. Necesitan pills con color.
5. **Responsive débil:** las tablas WP colapsan mal <900px. Owner puede entrar desde móvil (login redirige a `admin.php`) y hoy es incómodo.
6. **Owner y admin ven exactamente el mismo layout** salvo por conteos. No hay onboarding, ni empty states diferenciados, ni jerarquía adaptada.

---

## 2. Principios de diseño

| # | Principio | Traducción práctica |
|---|---|---|
| 1 | **Confianza como Booking** | jerarquía numérica clara, badges de estado siempre visibles, "verificado" en cada tarjeta |
| 2 | **Calidez como Airbnb** | tipografía humanista, radios 12–16, mucha foto, tono conversacional en empty states |
| 3 | **Un vistazo = una decisión** | cada pantalla resuelve UNA pregunta en <3 s antes del scroll |
| 4 | **Owner ≠ Admin** | mismo sistema, distinto foco: owner ve *sus propiedades*, admin ve *el negocio* |
| 5 | **Mobile-worthy, no mobile-afterthought** | wp-admin en móvil funciona: sidebar drawer, tablas → cards, acciones sticky |
| 6 | **WP-nativo compatible** | nunca romper `.notice`, `.wp-list-table`, `.button-primary`; extender, no reemplazar |

---

## 3. Sistema de diseño "AF Design System"

Se define un único set de tokens que reemplaza los valores hard-coded. Se inyecta en `admin.css` como `:root` scope al body `body[class*="arriendo-facil"], body[class*="af-"]` para no contaminar otros plugins.

### 3.1 Paleta

Basada en la identidad de arriendofacil.net (azul confianza + coral cálido) y compatible con contrastes AA:

**Primaria — Azul Arriendo (confianza, Booking-like)**

| Token | Hex | Uso |
|---|---|---|
| `--af-primary-50` | `#EFF6FF` | fondos hover, chips |
| `--af-primary-100` | `#DBEAFE` | fondos suaves, badge info |
| `--af-primary-500` | `#2563EB` | botón primario, links |
| `--af-primary-600` | `#1D4ED8` | hover primario |
| `--af-primary-700` | `#1E40AF` | sidebar activo, titulares |
| `--af-primary-900` | `#0B2A5B` | headers oscuros |

**Secundaria — Coral Hogar (calidez, Airbnb-like, CTAs emocionales)**

| Token | Hex | Uso |
|---|---|---|
| `--af-accent-50` | `#FFF1F0` | fondo destacado |
| `--af-accent-500` | `#F5385C` | badge "Nuevo", promo, estrella review |
| `--af-accent-600` | `#D42548` | hover accent |

**Neutrales — Grises tierra (calidez ecuatoriana, no azul frío)**

| Token | Hex | Uso |
|---|---|---|
| `--af-gray-0` | `#FFFFFF` | superficies |
| `--af-gray-50` | `#F7F7F5` | fondo de página |
| `--af-gray-100` | `#EEEDE8` | divisores suaves |
| `--af-gray-200` | `#E2E1DB` | borders |
| `--af-gray-400` | `#A8A69E` | texto secundario |
| `--af-gray-600` | `#5B594F` | texto normal |
| `--af-gray-900` | `#1B1A16` | headings |

**Semánticos (estados de negocio: contratos, limpiezas, reservas)**

| Token | Hex | Estado mapeado |
|---|---|---|
| `--af-success-500` | `#0F9D58` | `active`, `completed`, `paid` |
| `--af-success-50` | `#E6F7EE` | pill success bg |
| `--af-warning-500` | `#F59E0B` | `pending`, `in_progress` |
| `--af-warning-50` | `#FEF6E6` | pill warning bg |
| `--af-danger-500` | `#DC2626` | `terminated`, `expired`, `rejected`, occupied overlay |
| `--af-danger-50` | `#FEECEC` | pill danger bg |
| `--af-info-500` | `#0EA5E9` | `draft`, `synced OTA` |
| `--af-info-50` | `#E6F6FE` | pill info bg |

### 3.2 Tipografía

- **Familia base:** `"Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif` (Airbnb usa Circular; Inter es su alternativa libre y ya es de facto en admins modernos).
- **Numérica tabular:** `font-variant-numeric: tabular-nums;` en KPIs y tablas de precios.
- **Escala (rem, base 16):**

| Token | rem | px | Uso |
|---|---:|---:|---|
| `--af-text-xs` | 0.75 | 12 | caption, labels |
| `--af-text-sm` | 0.875 | 14 | body en tablas |
| `--af-text-base` | 1 | 16 | body por defecto |
| `--af-text-lg` | 1.125 | 18 | subtítulos card |
| `--af-text-xl` | 1.5 | 24 | H1 vista |
| `--af-text-2xl` | 2 | 32 | KPI grande |
| `--af-text-3xl` | 2.5 | 40 | KPI hero dashboard |

- Pesos: 400 (body), 500 (labels), 600 (subtítulos), 700 (KPI, H1).
- Interlineado: 1.5 body, 1.2 headings, 1.1 KPIs.

### 3.3 Espaciado, radios, sombras, motion

```
--af-space-1: 4px    --af-radius-sm: 6px    --af-shadow-xs: 0 1px 2px rgba(16,24,40,.05)
--af-space-2: 8px    --af-radius-md: 10px   --af-shadow-sm: 0 2px 6px rgba(16,24,40,.06)
--af-space-3: 12px   --af-radius-lg: 14px   --af-shadow-md: 0 8px 20px rgba(16,24,40,.08)
--af-space-4: 16px   --af-radius-xl: 20px   --af-shadow-lg: 0 20px 40px rgba(16,24,40,.10)
--af-space-6: 24px   --af-radius-full: 999px
--af-space-8: 32px
--af-space-12: 48px

--af-motion-fast: 120ms cubic-bezier(.2,.8,.2,1)
--af-motion-base: 180ms cubic-bezier(.2,.8,.2,1)
--af-motion-slow: 260ms cubic-bezier(.2,.8,.2,1)
```

Respeta `prefers-reduced-motion: reduce` → transiciones a 0ms.

### 3.4 Breakpoints

| Nombre | min-width | Comportamiento |
|---|---|---|
| `xs` | 0 | 1 columna, sidebar WP colapsado, tablas → cards |
| `sm` | 600 | 2 columnas de KPI |
| `md` | 900 | 3 columnas de KPI, tablas visibles |
| `lg` | 1200 | 4 columnas de KPI, layout dashboard 2/3 + 1/3 |
| `xl` | 1600 | max-width contenido 1440, gutters generosos |

Notas WP: `#wpwrap` colapsa el sidebar en 782px. Nuestros breakpoints se alinean por encima de ese umbral.

---

## 4. Componentes reutilizables

Todos con prefijo `af-` y clases BEM. Se agregan **encima** de las clases WP existentes, no las sustituyen.

### 4.1 `af-page-header`
Título + subtítulo + acciones a la derecha + fila de filtros. Reemplaza el `<h1>` suelto dentro de `.wrap`.

```
[ icono ] Título de la vista                          [ Filtro ▾ ] [ + Acción principal ]
Subtítulo/descripcion breve (14px, --af-gray-400)
─────────────────────────────────────────────────────────────────────────────────────────
```

### 4.2 `af-kpi-card` (reemplaza `.af-stat-card`)
```
┌───────────────────────────┐
│ 🏠 Alojamientos           │  ← icono + label (12px uppercase)
│ 24                        │  ← número (--af-text-3xl, tabular-nums)
│ ▲ 8% vs mes anterior      │  ← delta con color semántico
│ Ver todos →               │  ← link
└───────────────────────────┘
```
Variantes: `--positive`, `--negative`, `--neutral`, `--attention` (borde izquierdo warning cuando hay algo pendiente).

### 4.3 `af-status-pill`
Píldora coloreada según `--af-{semantic}-50/500`. Mapeo:

| Estado DB | Pill |
|---|---|
| `active` / `completed` / `paid` / `AUTORIZADO` | success |
| `pending` / `in_progress` / `draft` | warning |
| `terminated` / `expired` / `rejected` / `DEVUELTA` | danger |
| `synced` OTA / `sent` | info |

### 4.4 `af-data-table` (envuelve `.wp-list-table`)
- Cabeceras sticky al hacer scroll interno.
- Filas con hover `--af-primary-50`.
- Zebra opcional `--af-gray-50`.
- Última columna "Acciones" **siempre a la derecha**, con menú kebab (`⋮`) que agrupa acciones secundarias (dejando solo la primaria visible). Elimina el apilamiento vertical actual de `.af-lease-actions-stack`.
- En móvil: colapsa a `af-record-card` (cada fila = card con label + valor + acciones al pie).

### 4.5 `af-property-card` (nuevo, inspiración Airbnb)
Para listados de alojamientos dentro del dashboard del owner:
```
┌───────────────────────────────────────┐
│ [foto 16:9, radius-lg]                │
│                       [★ 4.8]  [⓿ 3] │  ← rating + notificaciones
│                                       │
│ Suite Iñaquito                        │
│ Quito · 2 hab · 1 baño                │
│ $ 650 / mes    [ Activo ]             │
└───────────────────────────────────────┘
```

### 4.6 `af-timeline` (para lease detail y cleaning)
Vertical, con checkpoints de ciclo de vida: `draft → active → renewed → terminated`. Refuerza la sensación Booking (línea temporal de reserva).

### 4.7 `af-empty-state`
Ilustración SVG + copy amable + CTA. Diferente para owner ("Aún no tienes propiedades, agrega la primera") vs admin ("No hay actividad este mes").

### 4.8 `af-toast` (ya existe en wizard)
Reusar `.af-wizard__toast--{success|info|error}` como toast global de la plataforma. Portearlo a `admin.css`.

### 4.9 `af-modal`
Ya existe (`.af-modal__dialog`). Estandarizar variantes: `--sm` (400), `--md` (560, default), `--lg` (1000), `--full` (móvil). Añadir `role="dialog" aria-modal="true"` y trap de foco.

### 4.10 `af-side-drawer` (nuevo)
Panel lateral derecho de 480px que reemplaza modales en flujos de detalle (ver contrato, ver huésped, ver factura). Patrón Booking extranet.

### 4.11 `af-form-field`
Contenedor label + input + hint + error, con altura consistente 40px, radius `--af-radius-md`, focus ring `--af-primary-500` con outline 2px offset 2px (accesible).

### 4.12 `af-nav-tabs`
Tabs horizontales con indicador animado bajo la activa. Se aplica en billing (Emitidas / Anuladas / Reintentos), en OTA (Airbnb / Booking), y en la nueva vista de detalle de alojamiento.

---

## 5. Layout & navegación

### 5.1 Sidebar de WordPress (menú `Arriendo Fácil`)

- **Iconografía:** reemplazar `dashicons-building` por un SVG de la llave/casa Arriendo Fácil (via `add_menu_page` con data URI SVG).
- **Reordenar submenús** por frecuencia de uso, con separadores visuales inyectados con `add_submenu_page` truco (`'—'`):

```
🏠 Panel
🗓  Contratos                    ← flujo diario admin/owner
🧹 Limpiezas
👥 Huéspedes                     ← incluye cola de interés (badge conteo pendiente)
⭐ Valoraciones
── Gestión ──
🏢 Contactos de propietarios      (solo admin)
🌐 Integraciones OTA
⚙️  Sincronización OTA            (solo admin)
── Facturación ──                 (solo admin)
🧾 Facturación
⚙️  Config. SRI
── Sistema ──
🤖 Ajustes de IA                  (solo admin)
```

- **Badges numéricos** al lado del submenu label (via `add_submenu_page` con HTML): "Limpiezas <span class='af-nav-badge'>3</span>". Estilo Booking extranet.

### 5.2 Estructura de cada página

```
┌─────────────────────────────────────────────────────────────────┐
│ af-page-header                                                  │
│   H1  ·  breadcrumb (Panel / Contratos)      [filtros] [+ CTA]  │
├─────────────────────────────────────────────────────────────────┤
│ af-page-toolbar (opcional: tabs, search, view toggle)           │
├─────────────────────────────────────────────────────────────────┤
│ af-page-body (contenido)                                        │
│   ↳ notices (.notice) se posicionan aquí, arriba, con radius-md │
└─────────────────────────────────────────────────────────────────┘
                             (footer WP oculto en vistas del plugin)
```

Wrapper CSS: se envuelve el contenido dentro de `<div class="wrap af-shell">…</div>` para aislar tokens sin perder compatibilidad con WP.

---

## 6. Rediseño por vista

### 6.1 Dashboard (`admin/views/dashboard.php`)

**Objetivo admin:** "¿Qué necesita mi atención hoy?"
**Objetivo owner:** "¿Cómo van mis propiedades?"

Layout deseado (desktop `lg`):

```
┌── Bienvenida ──────────────────────────────────────────────┐
│ "Buenos días, María" · Hoy es martes 5 de agosto           │
│ [ + Registrar alojamiento ]  [ + Nuevo contrato ]          │
└────────────────────────────────────────────────────────────┘

Fila de KPIs (4 col en lg, 2 en md, 1 en xs)
[ 🏠 Alojamientos ] [ 📄 Contratos activos ] [ 🧹 Limpiezas pend. ] [ ⭐ Rating ]

┌─ 2/3: Actividad reciente ─────────────┐ ┌─ 1/3: Tareas pendientes ──┐
│  Timeline: últimos 10 eventos         │ │ ☐ 3 solicitudes limpieza  │
│  (nuevo contrato, review, sync OTA…)  │ │ ☐ 1 contrato por firmar   │
│                                       │ │ ☐ 2 huéspedes en cola     │
│                                       │ │ ☐ 1 factura por emitir    │
└───────────────────────────────────────┘ └───────────────────────────┘

┌─ Owner-only: Mis propiedades ──────────────────────────────┐
│  Grid de af-property-card (2–3 col)                        │
│  con foto, estado ocupación, ingreso mes, próximo evento   │
└────────────────────────────────────────────────────────────┘

┌─ Admin-only: Salud del negocio ────────────────────────────┐
│  Sparklines: contratos/mes, ingresos, ocupación media      │
│  (Chart.js ya está justificado en docs/design)             │
└────────────────────────────────────────────────────────────┘
```

- **KPIs con delta** vs 30 días anteriores (una sola query adicional por card).
- **Ocupación** visualizada como barra horizontal (Airbnb "Superhost"–like).
- Empty state cálido si el owner aún no tiene propiedades.

### 6.2 Contratos (`admin/views/leases.php`)

- Encabezado con contadores tipo pill: `Activos 12` / `Borrador 3` / `Terminados 8` que actúan como filtros (Booking extranet pattern).
- Tabla → `af-data-table`. Columnas visibles por defecto: **Alojamiento** (con miniatura), **Huésped**, **Vigencia** (badge de días restantes), **Renta**, **Estado**, **Facturación mes actual** (pill), **Acciones**.
- Acciones: solo "Ver" visible; el resto (activar, subir doc, terminar, previsualizar plantilla) en un menú `⋮`. Elimina la actual columna de 320px de ancho.
- Click en fila → abre `af-side-drawer` con detalle del contrato + timeline + descarga documento (sin salir de la vista).
- Móvil: cada fila = `af-record-card` con acciones sticky abajo.

### 6.3 Limpiezas (`admin/views/cleaning-requests.php`)

- Header con dos vistas alternables: **Lista** (default) / **Calendario semanal** (nuevo, Booking-like, con drag para reprogramar solo visual — el backend ya soporta `requested_date`).
- Cada request como card con: foto alojamiento, servicio, fecha solicitada, estado, empresa asignada. Botón "Marcar en curso / completar".
- Estado con `af-status-pill`.

### 6.4 Huéspedes (`admin/views/guests.php`)

- Tabs: **Cola de interés** / **Huéspedes activos** / **Historial**.
- Score IA visualizado como donut compacto 0–100 con color semántico (Airbnb "match" pattern).
- Acciones aprobar/rechazar como botones inline con confirm inline (no `confirm()` nativo).

### 6.5 Valoraciones (`admin/views/reviews.php`)

- Hero con: promedio de estrellas (grande, Airbnb-style), total, % positivas (barra segmentada 5⭐ / 4⭐ / 3⭐ / 2⭐ / 1⭐).
- Filtro segmentado direccional: `Todas / Del huésped / Del propietario`.
- Card por review con avatar (inicial + color determinístico), texto, estrellas, alojamiento, fecha relativa ("hace 3 días").

### 6.6 Contactos de propietarios (`admin/views/owner-contacts.php`, solo admin)

- Layout maestro-detalle: lista a la izquierda (avatar + nombre + estado), detalle a la derecha con datos legales, documentos, tab de mensajería.
- Reutilizar el modal existente `.af-modal` promoviéndolo a `af-side-drawer`.

### 6.7 Facturación (`admin/views/billing.php`, `billing-settings.php`)

Ya tiene una base sólida (`.af-sri-hero`, `.af-sri-status-grid`, `.af-sri-section`). Se **preservan las clases** y solo se re-mapean sus valores a tokens del nuevo sistema. Cambios menores:
- Tabs superiores para separar Emitidas / Anuladas / Rechazadas.
- Cronograma visual de estados SRI: `Firmado → Enviado → Autorizado`.
- Card de configuración SRI con indicador de "certificado vence en X días" (warning si <30).

### 6.8 Wizard de alojamiento (`accommodation-wizard.php`)

Ya es la referencia visual del nuevo sistema. Ajustes:
- Migrar variables hard-coded (`#f3f4f6`, `#e5e7eb`) a tokens `--af-gray-*`.
- Añadir progreso persistente al hacer scroll (barra top sticky).
- Mejorar el mapa de Leaflet con controles custom que respeten la paleta.

### 6.9 OTA (`ota-integrations-settings.php`, `ota-sync-dashboard.php`)

- Cards de plataforma (Booking, Airbnb) con logo, estado de conexión (`af-status-pill`), último sync, próxima sincronización.
- Panel de sincronización con timeline de eventos recientes tipo activity log.

### 6.10 Ajustes de IA (`ai-settings.php`)

- Layout de 2 columnas: form + card lateral con "Estado del servicio" (ping al endpoint, versión, latencia).
- Reemplazar toggles WP por switches `af-switch` (accesibles con role="switch").

---

## 7. Roles: Administrador vs Owner

Mismo sistema, diferente foco. Se controla vía la utilidad ya existente `Arriendo_Facil_Accommodation::user_is_owner()`.

| Aspecto | Administrador | Owner (`af_owner`) |
|---|---|---|
| **Dashboard hero** | "Panel de operaciones" | "Hola, {nombre}" |
| **KPIs primarios** | Alojamientos totales, contratos, ingresos, ocupación media | *Mis* alojamientos, ingresos del mes, ocupación, rating promedio |
| **CTA principal** | "+ Registrar alojamiento" | "+ Registrar alojamiento" (mismo) + "Ver mis contratos" |
| **Sidebar** | Todos los módulos | Sin: Contactos de propietarios, Config SRI, Ajustes IA, Sync OTA (ya oculto) |
| **Tablas** | Ver todo el negocio | Filtradas por `owner_ids` (ya implementado en queries) |
| **Empty states** | Neutrales, orientados a datos | Cálidos, orientados a onboarding |
| **Tone of voice** | Directo, operativo | Cercano, motivador ("tu próxima renta empieza aquí") |
| **Onboarding** | N/A | Checklist inicial (registrar propiedad → subir foto → definir precio → publicar) |

---

## 8. Responsive

Estrategia mobile-first para el contenido, pero considerando que WP admin ya colapsa a 782px.

### 8.1 Patrones clave

- **Sidebar WP:** en <783px WP ya lo transforma en toggle. Estilizar el botón toggle con paleta AF.
- **Tablas:** en <900px, `af-data-table` colapsa a `af-record-card` (cada TR → card con label:value). Se logra con CSS `display: block` en `<tr>/<td>` + `::before { content: attr(data-label); }`. Los templates PHP deben emitir `data-label` en cada `<td>`.
- **KPI grid:** `grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))`.
- **Modales / drawers:** en móvil el drawer ocupa 100% del ancho y sube desde abajo (bottom sheet), radios superiores 20px, con "asa" visual.
- **Acciones críticas:** barra sticky inferior con las 1–2 acciones principales (Airbnb-like).
- **Formularios largos** (wizard, ajustes SRI): 1 columna en móvil, labels arriba, teclado numérico donde aplique (`inputmode="numeric"`).
- **Touch targets:** mínimo 44×44 px según WCAG 2.5.5.
- **Tap highlights:** `-webkit-tap-highlight-color: transparent` + focus visible custom.

### 8.2 Matriz de comportamiento por vista

| Vista | xs (móvil) | md (tablet) | lg+ (desktop) |
|---|---|---|---|
| Dashboard | KPIs 1 col, timeline en tabs | KPIs 2 col | KPIs 4 col + 2/3+1/3 |
| Contratos | cards apiladas | tabla básica | tabla + drawer al lado |
| Limpiezas | cards | lista | lista + toggle calendario |
| Huéspedes | cards de solicitud | tabla | tabla + drawer |
| Reviews | 1 col | 2 col | 2 col + hero grande |
| Billing | acordeón | tabla | tabla + hero + KPI SRI |
| Wizard | pasos secuenciales, nav inferior | 2 col | sidenav + form |

---

## 9. Accesibilidad y microinteracciones

### 9.1 Accesibilidad (WCAG 2.1 AA)

- Contraste mínimo 4.5:1 texto normal, 3:1 texto grande (paleta verificada).
- Focus visible: outline 2px `--af-primary-500` + offset 2px en todos los interactivos.
- `prefers-reduced-motion: reduce` → sin transiciones ni transformaciones.
- `prefers-color-scheme: dark` → **fase 2**, no bloquea la fase 1.
- Todos los iconos decorativos con `aria-hidden="true"`. Iconos-botón con `aria-label`.
- `af-status-pill` incluye texto legible, no solo color.
- Screen reader: `af-page-header` como `<header>` con `<h1>`, breadcrumbs con `<nav aria-label="Migas de pan">`.
- Skip link "Ir al contenido" ya provisto por WP; asegurar contraste.

### 9.2 Microinteracciones

- Hover en cards: elevación de shadow-sm → shadow-md, translateY(-2px), 180ms.
- Hover en filas de tabla: fondo `--af-primary-50`.
- Loading en botones: spinner inline + estado `aria-busy="true"`, sin bloquear la UI.
- Toast entra desde arriba-derecha (desktop) o desde abajo (móvil), auto-dismiss 5s, con opción manual.
- Copy-to-clipboard en RUC/cédula/URL corta con feedback inline "Copiado ✓".
- Skeleton loaders en KPIs y tablas mientras carga AJAX.

---

## 10. Estrategia de implementación (fases, sin código todavía)

### Fase 0 — Fundaciones (bajo riesgo)
1. Crear `assets/css/af-tokens.css` con `:root { --af-* }` y `body[class*="toplevel_page_arriendo-facil"], body[class*="arriendo-facil_page_"], body[class*="admin_page_af-"]` como scope.
2. Enqueue en `class-admin.php::enqueue_assets()` **antes** que `admin.css`.
3. Refactor progresivo: en `admin.css`, `admin-wizard.css` y `accommodations-occupied.css`, sustituir valores hard-coded por variables (búsqueda y reemplazo dirigido, sin cambios visuales).

**Criterio de aceptación:** el panel se ve idéntico visualmente antes/después → puramente mecánico, cero riesgo.

### Fase 1 — Chrome global (shell + navegación)
1. Icono SVG custom del menú (base64 en `add_menu_page`).
2. Badges numéricos en submenús (contratos borrador, limpiezas pendientes, cola huéspedes).
3. Clase wrapper `af-shell` aplicada en cada `render_*()`.
4. Nuevo `af-page-header` reemplazando los `<h1>` en las 15 vistas.
5. Toast global desde `admin.js`.

**Criterio:** primer impacto visual notable, sin tocar tablas ni forms.

### Fase 2 — Dashboard
1. Rediseño de `dashboard.php` con nueva estructura y KPIs con delta.
2. `af-property-card` para owner.
3. Timeline de actividad reciente.
4. Empty states diferenciados admin/owner.

**Criterio:** owner que entra por primera vez entiende su panel en <10 s.

### Fase 3 — Listados de alto tráfico
1. `af-data-table` sobre `leases`, `cleaning-requests`, `guests`, `reviews`, `owner-contacts`.
2. `af-status-pill` reemplazando strings de estado.
3. Menús kebab de acciones.
4. `af-side-drawer` para detalle (contratos y huéspedes primero).

**Criterio:** reducción visual de ancho horizontal, cero scroll horizontal en `md`.

### Fase 4 — Responsive real
1. Colapso de tablas → `af-record-card` en <900px.
2. Bottom sheet en móvil.
3. Sticky action bars.
4. QA en 320, 375, 414, 768, 1024, 1280, 1440.

**Criterio:** owner puede aprobar una solicitud desde el móvil sin zoom.

### Fase 5 — Módulos especializados
1. Billing con timeline SRI y tabs.
2. OTA con cards por plataforma y activity log.
3. AI settings con service health card.
4. Wizard: pulido + tokens + progress sticky.

### Fase 6 — Deleite (opcional / v2)
1. Dark mode.
2. Micro-animaciones adicionales.
3. Onboarding tour interactivo para nuevos owners.
4. Personalización de dashboard (drag KPIs).

---

## 11. Impacto en el código (visual only)

| Archivo | Cambio | Riesgo |
|---|---|---|
| `assets/css/af-tokens.css` | **nuevo** — variables CSS | nulo |
| `assets/css/admin.css` | refactor a tokens + nuevas clases `af-*` | bajo |
| `assets/css/admin-wizard.css` | migración a tokens | bajo |
| `assets/css/af-shell.css` | **nuevo** — layout `af-shell`, `af-page-header`, `af-data-table` responsive, `af-side-drawer` | bajo |
| `assets/js/admin.js` | añadir toast global, drawer, kebab, skeleton loaders | medio (compartido) |
| `admin/class-admin.php` | icono SVG del menú, enqueue de nuevos CSS, badges en submenus | bajo |
| `admin/views/*.php` | envolver contenido en `af-shell`, cambiar clases de estado a `af-status-pill`, añadir `data-label` a `<td>`, sustituir botones apilados por menú kebab | medio (repetitivo pero mecánico) |

**No se toca:** lógica de queries, capabilities, hooks REST, workflow de contratos, generación de documentos, firma SRI, sincronización OTA, cálculo IA.

---

## 12. Criterios de éxito

Medibles (aunque el trabajo es visual, se puede validar):

1. **Contraste:** 100% de textos ≥ AA verificado con axe-core.
2. **Responsive:** cero scroll horizontal en 320px en todas las vistas listadas.
3. **Percepción de velocidad:** skeletons visibles <100ms tras click; feedback de éxito/error en todas las acciones AJAX.
4. **Consistencia:** ningún hex hard-coded fuera de `af-tokens.css` tras Fase 0 (verificable con `grep -E "#[0-9a-fA-F]{6}"` en `assets/css/`).
5. **Owner-friendly:** un owner nuevo llega al dashboard y encuentra el botón para registrar su primera propiedad sin buscar (test de 5 s).
6. **Compatibilidad WP:** notices y admin bar no rompen.
7. **Performance:** CSS total del panel < 120 KB minificado; JS añadido < 20 KB.

---

## 13. Referencias visuales

- **Booking extranet:** densidad de tabla, filtros pill, sticky headers, timeline de reserva, activity log.
- **Airbnb hosting dashboard:** cards de propiedad con foto grande, tono cercano, empty states cálidos, side drawer para detalle, donut de score.
- **Arriendo Fácil (arriendofacil.net):** hero azul con acento coral, badges "VERIFICADO / DISPONIBLE / ROOM / APARTMENT", tipografía humanista, testimonios en cards.
- **Wizard actual del plugin:** referencia interna del estilo objetivo (grises tierra, radios 8–12, sombras suaves, toasts con color semántico).

---

## 14. Fuera de alcance (explícito)

- No se cambia el frontend público de arriendofacil.net.
- No se migra a React ni a otro framework (todo sigue en PHP + jQuery ya presente).
- No se toca la lógica de facturación SRI, firmas, OTA, IA ni base de datos.
- No se añaden nuevas capabilities ni roles.
- No se traducen strings nuevos: se reutilizan los ya existentes en `.wrap` cuando sea posible; los nuevos entran al text domain `arriendo-facil` con `esc_html_e()`.

---

## Anexo A — Mapeo de estados a pills (referencia rápida)

| Módulo | Campo DB | Valor | Pill |
|---|---|---|---|
| Leases | `status` | `draft` | warning "Borrador" |
| Leases | `status` | `active` | success "Activo" |
| Leases | `status` | `terminated` | danger "Terminado" |
| Leases | `status` | `expired` | danger "Vencido" |
| Cleaning | `status` | `pending` | warning "Pendiente" |
| Cleaning | `status` | `in_progress` | info "En curso" |
| Cleaning | `status` | `completed` | success "Completada" |
| Reviews | `status` | `pending` | warning "Pendiente" |
| Reviews | `status` | `completed` | success "Publicada" |
| Owner contacts | `status` | `active` | success "Activo" |
| Owner contacts | `status` | `inactive` | neutral "Inactivo" |
| Billing (SRI) | `estado` | `AUTORIZADO` | success |
| Billing (SRI) | `estado` | `EN_PROCESO` | warning |
| Billing (SRI) | `estado` | `DEVUELTA` / `NO_AUTORIZADO` | danger |
| Interest queue | `status` | `pending` | warning "En cola" |
| Interest queue | `status` | `approved` | success "Aprobada" |
| Interest queue | `status` | `rejected` | danger "Rechazada" |

## Anexo B — Checklist de handoff por vista

Para cada vista, marcar al terminar la fase correspondiente:

- [ ] Wrap `af-shell` + `af-page-header`
- [ ] Estados con `af-status-pill`
- [ ] Tabla con `af-data-table` (o cards en <md)
- [ ] `data-label` en cada `<td>` para modo card
- [ ] Menú kebab de acciones
- [ ] Drawer/modal actualizado
- [ ] Empty state con copy adaptado a rol
- [ ] Validado con axe-core (AA)
- [ ] Validado en 320 / 768 / 1440 px
