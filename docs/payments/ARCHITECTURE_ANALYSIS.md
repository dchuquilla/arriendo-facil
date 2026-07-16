# 🔍 Análisis de Compatibilidad: Arquitectura de Pagos
## Plugin WordPress vs Documentación Generada

---

## ⚠️ Incompatibilidades Detectadas

### 1. **Stack Tecnológico** ❌ INCOMPATIBLE

| Documento Asume | Realidad del Proyecto | Impacto |
|-----------------|----------------------|---------|
| **Node.js + Express** | **PHP (WordPress Plugin)** | CRÍTICO |
| PostgreSQL | MySQL/MariaDB (WordPress) | CRÍTICO |
| Bull + Redis | WordPress Cron (WP-Cron) | CRÍTICO |
| Knex migrations | WordPress hooks/post types | CRÍTICO |
| Jest testing | PHPUnit (ya existe) | MENOR |
| Prometheus metrics | No hay sistema de métricas | MENOR |

### 2. **Estructura de Directorios** ⚠️ PARCIALMENTE COMPATIBLE

```
DOCUMENTADO:
src/
├── services/deuna/
├── services/banco-pichincha/
├── queues/
├── webhooks/
└── db/migrations/

REALIDAD (Plugin WordPress):
includes/
├── class-accommodation.php
├── class-guest.php
├── billing/
└── apisaits/
```

### 3. **Autenticación OAuth** ✅ COMPATIBLE
- Documentación: `axios.post()` para obtener tokens
- WordPress: Usar `wp_remote_post()` en su lugar
- **Cambio mínimo**: Reemplazar HTTP client

### 4. **Base de Datos** ⚠️ REQUIERE ADAPTACIÓN

```
DOCUMENTADO: PostgreSQL con Knex migrations
  ├─ orders
  ├─ payments
  ├─ owner_balances
  ├─ payouts
  ├─ ledger_entries
  └─ idempotency_keys

REALIDAD: WordPress (MySQL/MariaDB)
  - Usar tablas prefijadas: wp_{prefix}_orders
  - Usar wpdb->get_results() en lugar de queries
  - Migraciones: wp_cli o custom activation hooks
```

### 5. **Colas de Mensajes** ❌ INCOMPATIBLE

```
DOCUMENTADO: Bull + Redis
  ├─ payment.confirmed
  ├─ payout.pending
  └─ payout.retry

REALIDAD: WordPress no tiene colas nativas
SOLUCIÓN:
  ├─ Opción A: WP-Cron (built-in, simple)
  ├─ Opción B: External queue (AWS SQS, RabbitMQ)
  └─ Opción C: Custom cron table en BD
```

### 6. **Webhooks** ✅ COMPATIBLE (CON CAMBIOS)

```
DOCUMENTADO: Express routes
  POST /webhooks/deuna/payment-confirmed

REALIDAD: WordPress REST API
  POST /wp-json/arriendo-facil/v1/webhooks/deuna/payment-confirmed

O: Custom webhook handler con register_rest_route()
```

---

## ✅ Plan de Adaptación (Recomendado)

### Paso 1: Mantener Arquitectura Conceptual
```
├─ Mismo flujo (Deuna → BD → Payout → Bank)
├─ Mismos cálculos (comisión, ledger)
├─ Mismo HMAC-SHA256 validation
├─ Mismas idempotency keys
└─ Mismos state machines
```

### Paso 2: Adaptar Stack Técnico

**ANTES (Node.js):**
```javascript
// src/services/deuna/auth.ts
import axios from 'axios';
const response = await axios.post('/auth/token', data);
```

**DESPUÉS (WordPress PHP):**
```php
// includes/class-deuna-auth.php
$response = wp_remote_post(
  'https://api.deuna.io/v1/auth/token',
  array(
    'method'      => 'POST',
    'body'        => json_encode($data),
    'headers'     => array('Content-Type' => 'application/json'),
    'timeout'     => 5,
    'sslverify'   => true
  )
);
```

### Paso 3: Adaptar Base de Datos

**BEFORE (SQL migrations):**
```javascript
// knexfile.js
CREATE TABLE orders (...)
CREATE TABLE payments (...)
```

**AFTER (WordPress tables):**
```php
// includes/class-activator.php
global $wpdb;
$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_orders (...)");
$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_payments (...)");
```

### Paso 4: Adaptar Colas

**BEFORE (Bull + Redis):**
```javascript
// src/queues/index.ts
const queue = new Queue('payment.confirmed', { redis });
queue.process(async (job) => { ... });
```

**AFTER (WP-Cron):**
```php
// arriendo-facil.php
add_action('af_process_payment_confirmed', array('Arriendo_Facil_Payment_Processor', 'process'), 10, 1);

if (!wp_next_scheduled('af_process_payment_confirmed')) {
  wp_schedule_event(time(), 'every_5_minutes', 'af_process_payment_confirmed');
}
```

### Paso 5: Adaptar Webhooks

**BEFORE (Express route):**
```javascript
// src/routes/webhooks.ts
app.post('/webhooks/deuna/payment-confirmed', (req, res) => { ... });
```

**AFTER (REST API):**
```php
// includes/class-deuna-webhook-handler.php
add_action('rest_api_init', function() {
  register_rest_route('arriendo-facil/v1', '/webhooks/deuna/payment', array(
    'methods'             => 'POST',
    'callback'            => array('Arriendo_Facil_Deuna_Webhook', 'handle_payment'),
    'permission_callback' => '__return_true', // Validar con HMAC
  ));
});
```

---

## 📋 Documentos a Adaptar

### CRÍTICO ❌ (Requiere reescritura)
1. **IMPLEMENTATION_GUIDE.md**
   - Cambiar npm → Composer
   - Cambiar Knex → wpdb
   - Cambiar Express → WordPress REST API
   - Cambiar Bull → WP-Cron
   - **Acción:** Crear `IMPLEMENTATION_GUIDE_WORDPRESS.md`

### PARCIALMENTE ✅ (Adaptar términos)
2. **ARCHITECTURE_PAYMENTS.md**
   - Endpoints: Reemplazar URLs Express por URLs REST API
   - Código: Cambiar syntax Node.js → PHP
   - DB: Cambiar PostgreSQL → MySQL
   - **Acción:** Crear versión PHP de ejemplos

### COMPATIBLE ✅ (Solo actualizar nombres)
3. **ARCHITECTURE_DIAGRAMS.md**
   - ASCII flows: Sin cambios (conceptualmente igual)
   - Flujos: Sin cambios
   - State machines: Sin cambios
   - **Acción:** Agregar nota: "Technology-agnostic, applicable to WordPress"

### NECESITA AJUSTES ⚠️ (Métodos específicos)
4. **QUICK_REFERENCE.md**
   - Cambiar SQL queries (MySQL syntax)
   - Cambiar debugging queries → wpdb calls
   - **Acción:** Agregar sección "WordPress-specific queries"

5. **TESTING_VALIDATION.md**
   - Cambiar Jest → PHPUnit
   - Cambiar mocking axios → mocking wp_remote_post
   - **Acción:** Agregar ejemplos PHPUnit

6. **PAYMENTS_ARCHITECTURE_README.md**
   - Actualizar stack a PHP/MySQL/WP-Cron
   - Sin cambios estructurales
   - **Acción:** Actualizar tabla "Stack Técnico"

---

## 🔧 Cómo Integrar en WordPress

### Estructura recomendada del plugin:

```
arriendo-facil/
├── arriendo-facil.php (main plugin file)
├── includes/
│   ├── payments/
│   │   ├── class-deuna-auth.php         ← NEW
│   │   ├── class-deuna-orders.php       ← NEW
│   │   ├── class-pichincha-transfers.php ← NEW
│   │   ├── class-payment-processor.php   ← NEW (reemplaza Bull queue)
│   │   ├── class-payment-webhook.php     ← NEW
│   │   ├── class-ledger-manager.php      ← NEW
│   │   └── class-idempotency-manager.php ← ALREADY EXISTS
│   ├── class-activator.php (crear tables aquí)
│   ├── billing/
│   │   └── (ya existe)
│   └── ... (resto)
├── admin/
│   └── class-payment-admin.php           ← NEW (dashboard)
└── tests/
    └── PaymentsTest.php                   ← NEW
```

---

## 🎯 Pasos Concretos (MVP)

### Fase 1: Setup Base (Semana 1)
```
✅ Crear clase Deuna_Auth
✅ Crear clase Pichincha_Transfers
✅ Adaptar tablas a WordPress (en activator.php)
✅ Crear clase Ledger_Manager
❌ NO USAR: Bull, Redis, Knex, Node.js
```

### Fase 2: Webhooks (Semana 2)
```
✅ Crear endpoint REST API para webhook Deuna
✅ Validación HMAC-SHA256
✅ Deduplicación con idempotency_keys
✅ Guardar en webhook_logs
```

### Fase 3: Procesamiento (Semana 2-3)
```
✅ Crear Payment_Processor (reemplaza queue)
✅ Usar WP-Cron para procesamiento async
✅ Actualizar ledger al completar pago
✅ Calcular comisión y balance
```

### Fase 4: Payouts (Semana 3)
```
✅ Crear clase Payout_Manager
✅ Validar cuenta bancaria del propietario
✅ Crear transfer en Pichincha
✅ WP-Cron para reconciliación (cada 5 min)
```

---

## 📊 Tabla Comparativa: Cambios por Componente

| Componente | Documentado | WordPress Real | Cambio |
|-----------|------------|-----------------|--------|
| **HTTP Client** | axios | wp_remote_post() | Syntax |
| **BD Query** | Knex | $wpdb->get_results() | Syntax |
| **Auth** | JWT + OAuth | WordPress nonces + OAuth | Syntax |
| **Async Job** | Bull queue | WP-Cron action | Completo |
| **Webhook** | Express route | REST API register_rest_route() | Completo |
| **Migration** | Knex migrations | Activator hook | Completo |
| **Testing** | Jest | PHPUnit | Completo |
| **Logging** | Winston | error_log() o custom | Sintaxis |
| **Secrets** | Vault | wp-config.php constants | Completo |
| **Alerts** | PagerDuty/Slack | Admin notice o email | Integración |

---

## 🚨 Cosas a Cambiar

### 1. HTTP Requests
```php
// ❌ NEVER use file_get_contents() for APIs
// ❌ NO: $data = json_decode(file_get_contents($url));

// ✅ USE: WordPress native function
$response = wp_remote_post('https://api.deuna.io/v1/orders', array(
  'body'    => json_encode($data),
  'headers' => array('Authorization' => 'Bearer ' . $token),
  'timeout' => 5,
));

if (is_wp_error($response)) {
  error_log('Deuna API error: ' . $response->get_error_message());
  return false;
}

$body = json_decode(wp_remote_retrieve_body($response), true);
```

### 2. Database Queries
```php
// ❌ NEVER use global $wpdb directly in complex logic

// ✅ USE: Class with prepared statements
class Arriendo_Facil_Orders {
  public function create_order($data) {
    global $wpdb;
    
    $wpdb->insert(
      $wpdb->prefix . 'af_orders',
      array(
        'deuna_id'       => $data['deuna_id'],
        'amount'         => $data['amount'],
        'status'         => 'PENDING',
        'created_at'     => current_time('mysql'),
      ),
      array('%s', '%f', '%s', '%s')
    );
  }
}
```

### 3. Async Processing
```php
// ❌ NEVER: Heavy processing in webhook handler
// This blocks the request (< 100ms requirement fails)

// ✅ USE: Schedule WP-Cron action
public static function handle_payment_webhook($data) {
  // 1. Save webhook log
  // 2. Validate HMAC
  // 3. Enqueue for processing
  
  do_action('af_process_payment_confirmed', $data);
  
  // Return 200 immediately
  wp_send_json_success(array('acknowledged' => true));
}

// Later, in separate cron execution
add_action('af_process_payment_confirmed', array('Payment_Processor', 'process'));
```

### 4. Storage de Secrets
```php
// ❌ NEVER in code or .env
// DEUNA_CLIENT_SECRET=secret123

// ✅ USE: wp-config.php constants (or plugin settings)
// wp-config.php:
define('ARRIENDO_FACIL_DEUNA_CLIENT_ID', 'prod_xxxxx');
define('ARRIENDO_FACIL_DEUNA_SECRET', 'secret_xxxxx');

// Uso en clase:
$client_id = defined('ARRIENDO_FACIL_DEUNA_CLIENT_ID') 
  ? ARRIENDO_FACIL_DEUNA_CLIENT_ID 
  : '';
```

---

## 📖 Documentación Corregida Requerida

### Crear NUEVO:
1. **IMPLEMENTATION_GUIDE_WORDPRESS.php.md** - Reescribir completamente en PHP
2. **PAYMENTS_DATABASE_SCHEMA.sql** - Actualizar para WordPress tables
3. **WORDPRESS_PAYMENTS_PLUGIN.md** - Guía específica de plugin

### Actualizar EXISTENTES:
1. ARCHITECTURE_PAYMENTS.md - Cambiar ejemplos a PHP
2. QUICK_REFERENCE.md - Agregar wpdb queries
3. TESTING_VALIDATION.md - Cambiar a PHPUnit

### MANTENER SIN CAMBIOS:
1. ARCHITECTURE_DIAGRAMS.md - Conceptualmente igual
2. PAYMENTS_ARCHITECTURE_README.md - Actualizar solo tabla de stack

---

## ✅ Checklist de Verificación

- [ ] Todos los ejemplos de código convertidos a PHP
- [ ] Endpoints cambiar de Express a REST API
- [ ] BD migrations cambiar de Knex a SQL directo
- [ ] Colas cambiar de Bull a WP-Cron
- [ ] Testing cambiar de Jest a PHPUnit
- [ ] Secrets cambiar de Vault a wp-config.php
- [ ] Logging usar wp_error_log()
- [ ] Webhooks usar register_rest_route()
- [ ] HTTP calls usar wp_remote_post()
- [ ] DB queries usar $wpdb prepared statements

---

## 🎯 Recomendación Final

**NO DESCARTES la documentación actual**, es 95% útil:
- ✅ Arquitectura conceptual (igual)
- ✅ Flujos de negocio (igual)
- ✅ Seguridad (igual: HMAC, idempotencia, ledger)
- ✅ Casos de borde (igual)
- ✅ State machines (igual)

**SÍ NECESITAS ADAPTAR:**
- 🔧 Ejemplos de código (Node.js → PHP)
- 🔧 Stack tecnológico (PostgreSQL → MySQL)
- 🔧 Async processing (Bull → WP-Cron)
- 🔧 HTTP client (axios → wp_remote_post)
- 🔧 DB queries (Knex → wpdb)

**Tiempo estimado de adaptación:** 2-3 días
**Documentos nuevos a crear:** 3
**Documentos a actualizar:** 3

