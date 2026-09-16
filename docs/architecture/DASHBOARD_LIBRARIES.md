# Librerías para el Panel (Dashboard) — 2026-09

Stack actual del proyecto: **sin bundler/npm build**, jQuery + JS vanilla,
CSS con variables (`af-tokens.css`). Cualquier librería nueva debe poder
cargarse por CDN o vendorizarse como archivo único (como ya se hace con
`mammoth.browser.min.js` y `leaflet` vía CDN en `admin/class-admin.php`).

## Instalado en esta sesión

### Chart.js 4.x (CDN, sin build)
- **Uso:** donut "Resumen de ocupación" + línea "Ingresos por arriendos" en
  `admin/views/dashboard.php`; barras "Cobranza por administrador" en
  `admin/views/property-admins.php`; ambos gráficos también en la vista
  pública `/ver-demo/` (datos de ejemplo).
- **Por qué Chart.js y no otra cosa:** sin dependencias, un solo `<script>`,
  API declarativa simple (JSON + canvas), soporta donut/línea/barra que es
  todo lo que pide el mockup, licencia MIT, tamaño ~200 KB — no justifica
  D3 (mucho más código para lo mismo) ni ApexCharts/Highcharts (licencia
  comercial en Highcharts, más peso).
- **Carga:** `wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js', ...)`
  solo en los hooks/páginas que lo usan (no se carga globalmente).
- **Si se prefiere NO depender de un CDN externo en producción:** descargar
  `chart.umd.min.js` a `assets/js/vendor/chart.umd.min.js` (mismo patrón que
  `mammoth.browser.min.js`) y apuntar `wp_enqueue_script` al archivo local.

## Recomendadas (NO instaladas — evaluar si se necesitan)

| Librería | Para qué | Por qué no se agregó ya |
|---|---|---|
| **Flatpickr** (~15 KB, MIT) | Selector de rango de fechas más agradable para el filtro de "Alertas operativas de calendario" (hoy son 2 `<input type="date">` nativos) | El filtro nativo ya funciona; agregar una librería de calendario es una mejora estética, no un requisito funcional. Se puede sumar cuando se rediseñe ese filtro específico. |
| **Choices.js** (~20 KB, MIT) | Selects con búsqueda para los dropdowns de edificio/inmueble/administrador en los filtros | Los `<select>` nativos actuales tienen pocas opciones por tenant; solo valdría la pena si un administrador llega a tener 50+ edificios. |
| **Sortable.js** | Reordenar tarjetas Kanban de mantenimiento con drag&drop | El Kanban actual es una grid filtrable, no un tablero drag&drop; agregarlo sería una nueva feature, no un rediseño visual. |

**Regla aplicada:** no se agregó ninguna librería "por si acaso" — solo
Chart.js, que es la única pieza que el mockup pedía explícitamente
(gráficos) y que no se podía lograr limpiamente con CSS puro.
