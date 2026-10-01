## Propósito de la sección «Mantenimiento» (Arriendo Fácil)

El objetivo es modernizar y simplificar la gestión de incidencias y reparaciones de inmuebles, muebles, instalaciones y electrodomésticos, sustituyendo el antiguo «Excel dinámico» por una herramienta pensada para usarse a diario por el equipo operativo.

### ¿Para qué sirve?

1. **Registrar solicitudes de reparación de forma clara**
   - Captura lo esencial en un solo vistazo: inmueble, unidad, bien a reparar, tipo de trabajo y qué está dañado.
   - Reduce la fricción: el formulario solo pide lo imprescindible y deja detalles, fecha, contacto y costo en una sección opcional (`<details>`). Esto evita sobrecargar al usuario al crear una solicitud, pero permite completarla cuando se tenga la información.

2. **Mantener un listado ágil e interactivo**
   - Tabla interactiva con búsqueda instantánea, filtros por estado/tipo, ordenamiento por columnas (incluye numérico para costo), fila de detalle expandible y resaltado visual (borde rojo para prioridad alta, azul para solicitudes con fecha pactada).
   - Comportamiento dinámico: al hacer clic en cualquier fila se expande su detalle (sin tener que apuntar únicamente al ícono). El estado puede cambiarse directamente desde el detalle sin recargar la página.

3. **Dar visibilidad accionable (sin saturar)**
   - Chips compactos de resumen (Abiertas, Prioridad alta, Con fecha pactada, Gasto del mes). No son KPIs decorativos: cada uno es un filtro rápido. Al pulsar uno se filtra la tabla y queda marcado (aria-pressed). Pulsarlo de nuevo lo limpia.
   - Barra de herramientas minimalista: un único buscador + botón «Filtros» (con badge cuando hay filtros activos) + acciones (Exportar CSV, Imprimir). El contador de resultados se actualiza en tiempo real.

4. **Catálogo privado de contactos de servicios**
   - Gestiona proveedores (plomero, albañil, carpintero, electricista, técnico de electrodomésticos, etc.) por administrador/tenant (aislado por `owner_id`). Los administradores de plataforma (`manage_options`) pueden ver todos los contactos.
   - Tarjetas limpias con datos útiles (empresa, ubicación, tarifas, teléfono/WhatsApp, correo). Se puede crear, editar y eliminar desde la misma interfaz (formulario reutilizado). Los contactos aparecen agrupados por oficio en el selector al crear una solicitud.

5. **Flexibilidad al asignar el contacto**
   - Se puede vincular un contacto del catálogo (`provider_id`) o escribir un contacto manual (nombre y teléfono) cuando no está en el listado. Esto evita bloquear el registro por no tener el proveedor cargado.

6. **Exportar e imprimir lo que se ve**
   - Exportación CSV generada en el navegador con BOM UTF-8 a partir de las filas visibles (coincide exactamente con lo que el usuario está filtrando/viendo). No requiere llamada al servidor.
   - Impresión limpia (`@media print`): oculta controles (toolbar, acciones, botones) y expande los detalles para obtener una hoja útil para trabajo en campo.

7. **Diseño centrado en el usuario**
   - Progresivo y sin sobrecarga: prioriza lo esencial, oculta lo opcional sin perderlo. Usa espaciados y tokens del sistema, responsive (se adapta a móvil).
   - Interactivo y dinámico: búsqueda en tiempo real, filtros combinados (búsqueda + estado/tipo + chips rápidos), ordenamiento con indicador visual, clear de búsqueda con Escape, popover de filtros autocerrable por click fuera/Escape.
   - Seguro y compatible: respeta el aislamiento por tenant, mantiene la tabla original `af_cleaning_requests` para no romper integraciones existentes, y añade columnas de reparación por migración idempotente.

### Idea clave
Hacer lo necesario para registrar y seguir una reparación en segundos, sin obligar al usuario a llenar un formulario largo ni a salir del listado. La información extra está ahí cuando se necesita (detalle expandible + sección opcional), no cuando estorba.
