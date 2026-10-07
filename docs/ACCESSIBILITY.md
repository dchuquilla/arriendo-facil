# Guía de Accesibilidad (WCAG 2.1 AA) - Arriendo Fácil

**Última Actualización**: Octubre 2026

## 1. Auditoría de Accesibilidad WCAG 2.1 AA

### Perceivable (Perceptible)

#### 1.1 Text Alternatives (Alt Text)
- [x] Todas las imágenes tienen alt text descriptivo
- [x] Imágenes decorativas tienen alt=""
- [x] SVGs tienen título y descripción
- [x] Icons tienen aria-label
- [x] Background images no contienen info crítica

**Ejemplo Correcto**:
```html
<!-- ✅ CORRECTO -->
<img src="property.jpg" alt="Departamento de 2 habitaciones en Quito">

<!-- ❌ INCORRECTO -->
<img src="property.jpg" alt="image" />
<img src="icon.svg" />
```

#### 1.3 Adaptability (Responsive)
- [x] Funciona en pantallas desde 320px hasta 2560px
- [x] No hay scroll horizontal en mobile
- [x] Elementos no se superponen
- [x] Text reflow sin pérdida de contenido

#### 1.4 Distinguishable (Contraste, Texto)
- [x] Ratio de contraste texto: 4.5:1 (normal), 3:1 (grande)
- [x] Logos y gráficos: 3:1
- [x] Funcionalidad no depende solo de color
- [x] Texto redimensionable sin pérdida

**Validar Contraste**:
```
Ratio = (L1 + 0.05) / (L2 + 0.05)

donde L = 0.2126 * R + 0.7152 * G + 0.0722 * B
(valores R,G,B normalizados 0-1)

Herramientas:
- WebAIM Contrast Checker
- Stark plugin (Figma)
- Axe DevTools
```

---

### Operable (Operativo)

#### 2.1 Keyboard (Navegación por Teclado)
- [x] Toda funcionalidad accesible por teclado
- [x] Tab order lógico y visible
- [x] Focus indicator claro (outline mín 2px)
- [x] Sin keyboard traps
- [x] Shortcuts no interfieren con navegación

**Ejemplo Focus**:
```css
/* WCAG AA exige visible focus */
*:focus {
  outline: 3px solid #4A90E2;
  outline-offset: 2px;
}
```

#### 2.2 Enough Time
- [x] No hay auto-refresh que pierde contenido
- [x] Timeouts >20 segundos o pausables
- [x] Animations pausables o <5 segundos
- [x] No parpadeos >3 por segundo

#### 2.3 Seizures
- [x] Sin contenido que parpadea 3+ veces/seg
- [x] Sin flash patterns que causan seizures

#### 2.4 Navigability (Navegación)
- [x] Propósito de link claro desde contexto
- [x] Heading order lógico (H1 -> H2 -> H3)
- [x] Múltiples formas de navegar (menú, búsqueda, mapa)
- [x] Skip navigation link presente
- [x] Ubicación actual indicada en navegación

**Ejemplo Skip Link**:
```html
<a href="#main-content" class="skip-link">
  Ir al contenido principal
</a>

<style>
  .skip-link {
    position: absolute;
    top: -40px;
    left: 0;
    background: #000;
    color: #fff;
    padding: 8px;
  }
  
  .skip-link:focus {
    top: 0;
  }
</style>
```

---

### Understandable (Comprensible)

#### 3.1 Language (Idioma)
- [x] Idioma de página en `<html lang="es">`
- [x] Cambios de idioma marcados con `lang` attribute
- [x] Abreviaciones expandidas (primer uso)

**Ejemplo**:
```html
<html lang="es">
  <p>El <abbr title="Departamento">Dpto</abbr> 101...</p>
  <p>Cambio a <span lang="en">English</span></p>
</html>
```

#### 3.2 Predictable (Predecible)
- [x] Navegación consistente
- [x] Componentes funcionan igual siempre
- [x] Sin cambios inesperados de contexto
- [x] Funcionalidad consistente

#### 3.3 Input Assistance (Asistencia)
- [x] Labels asociados con inputs
- [x] Mensajes de error claros
- [x] Sugerencias para corregir
- [x] Confirmación antes de acciones irreversibles

**Ejemplo Labels**:
```html
<!-- ✅ CORRECTO -->
<label for="email">Correo electrónico</label>
<input id="email" type="email" />

<!-- ❌ INCORRECTO -->
<input type="email" placeholder="Email" />
```

---

### Robust (Robusto)

#### 4.1 Compatible
- [x] HTML válido (sin errores)
- [x] ARIA roles usados correctamente
- [x] ARIA labels en componentes complejos
- [x] Compatible con screen readers

**Validar HTML**:
```bash
# W3C Validator
curl -X POST -F "input=@index.html" https://validator.w3.org/nu/

# Local validation
npx html-validate index.html
```

---

## 2. ARIA (Accessible Rich Internet Applications)

### Roles ARIA

```html
<!-- Navigation -->
<nav role="navigation" aria-label="Main navigation">
  <ul>
    <li><a href="/">Home</a></li>
  </ul>
</nav>

<!-- Dialog -->
<div role="dialog" aria-labelledby="dialog-title" aria-modal="true">
  <h2 id="dialog-title">Confirmar acción</h2>
  <p>¿Estás seguro?</p>
</div>

<!-- Alert -->
<div role="alert" aria-live="polite">
  Error: Por favor completa el formulario
</div>

<!-- Tab panel -->
<div role="tablist">
  <button role="tab" aria-selected="true" aria-controls="panel1">Tab 1</button>
  <div id="panel1" role="tabpanel">Contenido</div>
</div>

<!-- Custom dropdown -->
<div role="combobox" aria-expanded="false" aria-haspopup="listbox">
  <input role="searchbox" />
  <ul role="listbox">
    <li role="option">Opción 1</li>
  </ul>
</div>
```

### Live Regions

```html
<!-- Notificaciones que se anuncian -->
<div aria-live="polite" aria-atomic="true">
  Propiedad agregada exitosamente
</div>

<!-- Alertas urgentes -->
<div aria-live="assertive" role="alert">
  Error: Conexión perdida
</div>
```

---

## 3. Componentes Accesibles

### Formularios

```html
<form>
  <!-- Campo de texto -->
  <label for="nombre">Nombre</label>
  <input id="nombre" type="text" required aria-required="true" />
  
  <!-- Con error -->
  <label for="email">Email</label>
  <input id="email" type="email" aria-invalid="true" aria-describedby="email-error" />
  <span id="email-error">Email inválido</span>
  
  <!-- Campo de búsqueda -->
  <label for="search">Buscar propiedades</label>
  <input id="search" type="search" placeholder="Dirección, tipo..." />
  
  <!-- Radio buttons accesibles -->
  <fieldset>
    <legend>Tipo de propiedad</legend>
    <label>
      <input type="radio" name="type" value="apartment" />
      Departamento
    </label>
    <label>
      <input type="radio" name="type" value="house" />
      Casa
    </label>
  </fieldset>
  
  <!-- Checkboxes -->
  <fieldset>
    <legend>Amenidades</legend>
    <label>
      <input type="checkbox" name="amenities" value="pool" />
      Piscina
    </label>
    <label>
      <input type="checkbox" name="amenities" value="gym" />
      Gimnasio
    </label>
  </fieldset>
  
  <!-- Select accesible -->
  <label for="city">Ciudad</label>
  <select id="city">
    <option>Seleccionar</option>
    <option value="quito">Quito</option>
    <option value="guayaquil">Guayaquil</option>
  </select>
</form>
```

### Tablas

```html
<!-- Tabla accesible -->
<table>
  <caption>Listado de propiedades</caption>
  <thead>
    <tr>
      <th scope="col">Dirección</th>
      <th scope="col">Precio</th>
      <th scope="col">Habitaciones</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Calle A 123</td>
      <td>$500</td>
      <td>2</td>
    </tr>
  </tbody>
</table>
```

### Buttons

```html
<!-- Button accesible -->
<button aria-label="Abrir menú de opciones">⋮</button>

<!-- Button con loading state -->
<button aria-busy="true" disabled>
  Guardando...
</button>

<!-- Toggle button -->
<button aria-pressed="false" aria-label="Toggle modo oscuro">
  🌙
</button>
```

### Modals

```html
<div role="dialog" aria-labelledby="modal-title" aria-modal="true">
  <h2 id="modal-title">Crear propiedad</h2>
  
  <!-- Focus trap al cerrar -->
  <form>
    <input type="text" placeholder="Dirección" />
    <button type="submit">Guardar</button>
    <button type="button" aria-label="Cerrar">×</button>
  </form>
</div>
```

---

## 4. Testing Automático

### Herramientas

```bash
# axe DevTools (browser extension)
# npm package
npm install @axe-core/react

# Lighthouse
# Built-in Chrome DevTools

# WAVE
# Browser extension: https://wave.webaim.org/

# Screen reader testing
# NVDA (Windows) - Gratis
# JAWS (Windows) - Pago
# VoiceOver (macOS/iOS) - Incluido
# TalkBack (Android) - Incluido
```

### Automated Tests

```javascript
// React Testing Library + axe
import { render } from '@testing-library/react';
import { axe, toHaveNoViolations } from 'jest-axe';

expect.extend(toHaveNoViolations);

test('Button es accesible', async () => {
  const { container } = render(<Button>Click me</Button>);
  const results = await axe(container);
  expect(results).toHaveNoViolations();
});
```

---

## 5. Mobile Accessibility

### iOS VoiceOver
- [ ] Todos los elementos interactivos anunciados
- [ ] Touch targets ≥ 44x44 puntos
- [ ] Contraste suficiente incluso en modo oscuro
- [ ] No depender solo de audio

### Android TalkBack
- [ ] Labels en español
- [ ] Acciones anunciadas
- [ ] No texto demasiado pequeño
- [ ] Orden de enfoque lógico

---

## 6. Cumplimiento WCAG 2.1 AA

| Criterio | Nivel | Status | Evidencia |
|----------|-------|--------|-----------|
| 1.1.1 Non-text Content | A | ✅ | All images have alt |
| 1.3.1 Info and Relationships | A | ✅ | HTML semantic |
| 1.4.3 Contrast (Minimum) | AA | ✅ | 4.5:1 ratio |
| 2.1.1 Keyboard | A | ✅ | All interactive operable |
| 2.4.3 Focus Order | A | ✅ | Logical tab order |
| 2.4.7 Focus Visible | AA | ✅ | Clear focus indicator |
| 3.1.1 Language of Page | A | ✅ | lang="es" set |
| 3.3.1 Error Identification | A | ✅ | Error messages clear |
| 4.1.2 Name, Role, Value | A | ✅ | ARIA labels complete |
| 4.1.3 Status Messages | AA | ✅ | aria-live used |

**Puntuación Objetivo**: 100% WCAG 2.1 AA Compliance

---

## 7. Testing Manual Checklist

### Keyboard Navigation
- [ ] Tab recorre todos los elementos
- [ ] Shift+Tab regresa
- [ ] Enter activa botones/links
- [ ] Space activa checkboxes
- [ ] Arrow keys en listas/tabs
- [ ] Escape cierra modals
- [ ] No hay keyboard traps

### Screen Reader (NVDA)
- [ ] Page title anunciado
- [ ] Headings leídos en orden
- [ ] Links tienen propósito claro
- [ ] Form labels asociados
- [ ] Errores anunciados
- [ ] Cambios dinámicos anunciados
- [ ] Funcionalidad completa sin mouse

### Visual
- [ ] Contraste legible
- [ ] Texto redimensionable
- [ ] Sin parpadeos
- [ ] Layouts responsivos
- [ ] Iconos tienen alternativa

---

## 8. Guía de Desarrollo

### HTML Semántico

```html
<!-- ✅ USAR ELEMENTOS SEMÁNTICOS -->
<header>Encabezado</header>
<nav>Navegación</nav>
<main>Contenido principal</main>
<article>Artículo</article>
<section>Sección</section>
<aside>Contenido relacionado</aside>
<footer>Pie de página</footer>

<!-- ❌ EVITAR -->
<div class="header">
<div class="nav">
<div class="main">
```

### WAI-ARIA Roles (Solo si necesario)

```html
<!-- Solo si no hay elemento semántico -->
<div role="button" tabindex="0">Botón custom</div>

<!-- Mejor: usar <button> -->
<button>Botón</button>
```

### Colores No Suficientes

```html
<!-- ❌ INCORRECTO: Solo color indica estado -->
<input style="border-color: red" />

<!-- ✅ CORRECTO: Color + ícono + texto -->
<input aria-invalid="true" style="border-color: red" />
<span role="alert">❌ Campo requerido</span>
```

---

## 9. Monitoreo Continuo

### Herramientas
- Google Lighthouse (CI/CD)
- axe-core (automated)
- WAVE (periodic manual)
- Screen reader testing (monthly)

### Frecuencia
- [ ] Automated tests: cada commit
- [ ] Manual testing: semanal
- [ ] Full audit: mensual
- [ ] Screen reader: trimestral

