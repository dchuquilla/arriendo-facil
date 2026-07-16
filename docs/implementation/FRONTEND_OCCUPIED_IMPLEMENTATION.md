# Frontend: Implementación de Acomodaciones Ocupadas

## Resumen
Las acomodaciones marcadas como "Ocupadas" en el admin **SÍ aparecen en búsquedas y listados**, pero con:
- ✅ Overlay visual rojo oscuro con badge "OCUPADA"
- ✅ Deshabilitadas (no se puede hacer nada)
- ✅ Información visual clara de su estado

## Backend - Lo que ya está implementado

### 1. API de Búsqueda (`/wp-json/af/v1/accommodations/search`)
Cada acomodación retorna **un nuevo campo**:
```json
{
  "id": 123,
  "title": "Casa en La Carolina",
  "is_occupied": true,  // ← NUEVO CAMPO
  "price": 500,
  "bedrooms": 2,
  ...
}
```

**Importante**: `is_occupied: true` = NO se puede hacer NADA con ella

### 2. Meta Key en Base de Datos
- Key: `_af_is_occupied`
- Valor: `'1'` (ocupada) o no existe (disponible)
- Puedes verificar: `if (accommodation.is_occupied) { ... }`

### 3. Métodos PHP Disponibles
```php
// Verificar si una acomodación está ocupada
Arriendo_Facil_Accommodation_Occupied_Admin::is_occupied( $post_id );

// En queries, excluir ocupadas (si necesitas):
'meta_query' => array(
    array(
        'key'     => '_af_is_occupied',
        'value'   => '1',
        'compare' => '!=',
    ),
)
```

### 4. CSS Disponible (Ya Enqueued)
Archivo: `/assets/css/accommodations-occupied.css`

Se carga automáticamente. Clases disponibles:
- `.af-occupied-overlay` — overlay oscuro rojo
- `.af-occupied-badge` — badge "OCUPADA"
- `.af-featured-accommodation--occupied` — para tarjetas
- `.af-occupied-accommodation` — para lista de ocupadas

## Frontend - Lo que DEBES hacer

### 1️⃣ En Búsquedas / Listados (Lo más importante)

**Cuando recibas resultados de la API:**

```javascript
// Pseudocódigo
accommodations.forEach(accommodation => {
  const isOccupied = accommodation.is_occupied;
  
  if (isOccupied) {
    // ❌ NO MOSTRAR:
    // - Botón "Reservar"
    // - Botón "Contactar dueño"
    // - Formulario de contacto
    // - Cualquier CTA de acción
    
    // ✅ MOSTRAR:
    // - Tarjeta visual claramente deshabilitada
    // - Overlay con "OCUPADA"
    // - Botón deshabilitado que diga "NO DISPONIBLE - OCUPADA"
    
    renderOccupiedCard(accommodation);
  } else {
    // Renderizar normalmente con botones activos
    renderAvailableCard(accommodation);
  }
});
```

### 2️⃣ Estructura HTML Recomendada

**Para tarjeta ocupada:**
```html
<div class="accommodation-card af-featured-accommodation--occupied">
  <div class="accommodation-image af-featured-accommodation-image">
    <img src="..." alt="...">
    <div class="af-occupied-overlay">
      <span class="af-occupied-badge">OCUPADA</span>
    </div>
  </div>
  
  <div class="accommodation-content af-featured-accommodation-content">
    <h3>Casa en La Carolina</h3>
    <p>Dirección aquí</p>
    
    <ul>
      <li>Dormitorios: 2</li>
      <li>Baños: 1</li>
      <li>Renta: $500</li>
    </ul>
    
    <!-- DESHABILITADO -->
    <button class="button button-disabled" disabled>
      No disponible - Ocupada
    </button>
  </div>
</div>
```

### 3️⃣ En Página de Detalle (Single)

**Si entran a la página de detalle de una ocupada:**

```javascript
if (accommodation.is_occupied) {
  // Ocultar o deshabilitar:
  document.querySelector('.book-button')?.style.display = 'none';
  document.querySelector('.contact-form')?.style.display = 'none';
  document.querySelector('.contact-button')?.disabled = true;
  
  // Mostrar aviso visible:
  showAlert('Esta acomodación está ocupada y no está disponible para reservas.');
  
  // Opcional: mostrar fecha de disponibilidad si existe
}
```

### 4️⃣ En Formularios / Modales de Contacto

**Si alguien intenta contactar sobre una ocupada:**

```javascript
const handleContactSubmit = (accommodationId) => {
  const accommodation = accommodations.find(a => a.id === accommodationId);
  
  if (accommodation.is_occupied) {
    alert('Esta acomodación está ocupada. No puedes enviar solicitudes.');
    return false; // No enviar formulario
  }
  
  // Proceder con envío normal
  submitContactForm();
};
```

### 5️⃣ En Carrito / Checkout (Si existe)

```javascript
// Al validar carrito antes de checkout:
const hasOccupiedAccommodations = cart.items.some(item => item.is_occupied);

if (hasOccupiedAccommodations) {
  alert('Tu carrito contiene acomodaciones ocupadas. Por favor, remuévelas.');
  removeOccupiedFromCart();
  return false;
}
```

## Testing Checklist

- [ ] Marca una acomodación como "Ocupada" en el admin
- [ ] Búsqueda retorna `is_occupied: true` en API
- [ ] Se muestra con overlay rojo "OCUPADA" en listados
- [ ] Botón de reservar está deshabilitado (no clickeable)
- [ ] No se puede abrir modal de contacto
- [ ] En página de detalle: botones de contacto/reservar deshabilitados
- [ ] Visual responsive en móvil (overlay sigue visible)
- [ ] Desmarca como ocupada en admin
- [ ] Reaparece disponible inmediatamente en búsqueda
- [ ] Todos los botones vuelven a estar activos

## Implementación Paso a Paso

### Para React/Vue:

```jsx
function AccommodationCard({ accommodation }) {
  const isOccupied = accommodation.is_occupied;
  
  return (
    <div className={`card ${isOccupied ? 'card--occupied' : ''}`}>
      <div className="card-image">
        <img src={accommodation.image_url} alt={accommodation.title} />
        {isOccupied && (
          <div className="af-occupied-overlay">
            <span className="af-occupied-badge">OCUPADA</span>
          </div>
        )}
      </div>
      
      <div className="card-content">
        <h3>{accommodation.title}</h3>
        <p>{accommodation.location}</p>
        
        {isOccupied ? (
          <button disabled className="button button-disabled">
            No disponible - Ocupada
          </button>
        ) : (
          <>
            <button onClick={() => handleBook(accommodation.id)}>
              Reservar
            </button>
            <button onClick={() => handleContact(accommodation.id)}>
              Contactar
            </button>
          </>
        )}
      </div>
    </div>
  );
}
```

### Para JavaScript Vanilla:

```javascript
function renderAccommodation(accommodation, container) {
  const card = document.createElement('div');
  card.className = `accommodation-card ${accommodation.is_occupied ? 'af-featured-accommodation--occupied' : ''}`;
  
  const imgHtml = accommodation.is_occupied 
    ? `
      <div class="af-featured-accommodation-image">
        <img src="${accommodation.image_url}" alt="${accommodation.title}">
        <div class="af-occupied-overlay">
          <span class="af-occupied-badge">OCUPADA</span>
        </div>
      </div>
    `
    : `
      <div class="af-featured-accommodation-image">
        <a href="${accommodation.url}">
          <img src="${accommodation.image_url}" alt="${accommodation.title}">
        </a>
      </div>
    `;
  
  const actionHtml = accommodation.is_occupied
    ? `<button class="button button-disabled" disabled>No disponible - Ocupada</button>`
    : `
      <button class="button" onclick="handleBook(${accommodation.id})">Reservar</button>
      <button class="button button-secondary" onclick="handleContact(${accommodation.id})">Contactar</button>
    `;
  
  card.innerHTML = `
    ${imgHtml}
    <div class="card-content">
      <h3>${accommodation.title}</h3>
      <p>${accommodation.location}</p>
      ${actionHtml}
    </div>
  `;
  
  container.appendChild(card);
}
```

## Notas Importantes

1. **El CSS ya está listo** — no necesitas crearlo, ya está en `/assets/css/accommodations-occupied.css`
2. **El overlay es automático en shortcodes** — si usas `[propiedad_destacada]` o `[acomodaciones_ocupadas]`, el HTML ya incluye el overlay
3. **Si tienes código custom** — verifica que uses el campo `is_occupied` de la API
4. **Cache** — el API cachea por 10 minutos, así que los cambios pueden tardar ese tiempo en verse (o purga manualmente `_transient_af_search_results_%`)

## Troubleshooting

**P: Las ocupadas no aparecen en búsqueda**
R: Verificar que removiste el WHERE que excluía ocupadas. Ya fue removido en este commit.

**P: El overlay no se ve**
R: Verificar que el CSS está enqueued. Debería cargarse automáticamente. Abre DevTools y busca `accommodations-occupied.css`

**P: Puedo hacer reserva en una ocupada**
R: Faltan validaciones frontend. Agrega el check `if (accommodation.is_occupied) return false;` antes de enviar

**P: El usuario ve inconsistencias entre búsqueda y detalle**
R: Asegúrate de que la página de detalle también lee el meta `_af_is_occupied` y deshabilita acciones
