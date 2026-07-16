# Diagramas y Flujos de Arquitectura
## Marketplace de Arriendos - Modelo Agregador

---

## 1. Flujo End-to-End: Reserva → Pago → Dispersión

```
┌────────────────────────────────────────────────────────────────────────────────┐
│                          FLUJO COMPLETO DE PAGOS                               │
└────────────────────────────────────────────────────────────────────────────────┘

ACTOR: HUÉSPED
═══════════════════════════════════════════════════════════════════════════════

T0:  ┌─────────────────┐
     │  Guest selecciona│
     │  fechas + prop   │
     │  → Inicia reserva│
     └────────┬─────────┘
              │
              ▼
T1:  ┌──────────────────────────────────────┐
     │ Backend: POST /api/v1/orders         │
     │ ├─ Calcula: $1000                    │
     │ ├─ Obtiene token Deuna (cache)      │
     │ └─ Crea Orden en Deuna               │
     └────────┬─────────────────────────────┘
              │ Deuna responde con:
              │ { id: "order-deuna-abc123",
              │   qrCode: "iVBORw0..." }
              ▼
T2:  ┌──────────────────────────────────────┐
     │ BD: INSERT orders                    │
     │ ├─ status: PENDING                   │
     │ ├─ qr_data: base64                   │
     │ └─ idempotency_key: uuid             │
     └────────┬─────────────────────────────┘
              │
              ▼
T3:  ┌──────────────────────────────────────┐
     │ Frontend muestra QR                  │
     │ Código:                              │
     │ ┌────────────────┐                   │
     │ │  ██████ ██     │                   │
     │ │  ██  ██ ██     │ (QR dinámico)     │
     │ │  ██████ ██     │                   │
     │ └────────────────┘                   │
     │ Expiración: 15 minutos               │
     └────────┬─────────────────────────────┘
              │
              ▼
T4:  ┌──────────────────────────────────────┐
     │ Guest: Escanea QR con app bancaria  │
     │ ├─ Abre app de su banco             │
     │ ├─ Confirma dinero                  │
     │ └─ Transacción se procesa           │
     └────────┬─────────────────────────────┘
              │
              ▼
T5:  ┌──────────────────────────────────────┐
     │ Dinero entra a cuenta recaudadora   │
     │ (Banco Pichincha - Tu plataforma)   │
     │ Monto: $1000 USD                     │
     └────────┬─────────────────────────────┘
              │
              ▼
T6:  ┌──────────────────────────────────────┐
     │ Deuna → POST /webhooks/.../confirmed│
     │ Payload:                             │
     │ {                                    │
     │   "id": "webhook-evt-123",          │
     │   "event": "payment.completed",     │
     │   "data": {                         │
     │     "orderId": "order-deuna-abc",   │
     │     "status": "COMPLETED",          │
     │     "amount": 1000,                 │
     │     "transactionId": "TXN-999"      │
     │   },                                │
     │   "signature": "sha256=abc123..."   │
     │ }                                   │
     └────────┬─────────────────────────────┘
              │
              ▼
T7:  ┌──────────────────────────────────────┐
     │ Backend Webhook Handler             │
     │ ├─ Valida HMAC (sha256)             │
     │ ├─ Verifica timestamp (< 5 min)     │
     │ ├─ Guarda en webhook_logs           │
     │ └─ Responde 200 OK (< 100ms)        │
     └────────┬─────────────────────────────┘
              │
              ▼
T8:  ┌──────────────────────────────────────┐
     │ Enqueue: payment.confirmed          │
     │ (Bull + Redis)                      │
     │ Job:                                 │
     │ {                                    │
     │   "orderId": "order-deuna-abc",     │
     │   "amount": 1000                    │
     │ }                                    │
     └────────┬─────────────────────────────┘
              │
              ▼
T9:  ┌──────────────────────────────────────┐
     │ Queue Consumer #1 (1 concurrencia) │
     │ ├─ Busca orden en BD                │
     │ ├─ Valida no duplicada             │
     │ ├─ Marca como PAID                 │
     │ └─ Crea payment record              │
     └────────┬─────────────────────────────┘
              │
              ▼
T10: ┌──────────────────────────────────────┐
     │ Calcula Comisión                    │
     │ ├─ Gross: $1000                    │
     │ ├─ Comisión (10%): $100            │
     │ ├─ Neto: $900                      │
     │ └─ Owner earnings: +$900            │
     └────────┬─────────────────────────────┘
              │
              ▼
T11: ┌──────────────────────────────────────┐
     │ Actualizar Ledger Contable          │
     │ Entradas:                           │
     │ 1. PAYMENT_RECEIVED: +$1000        │
     │ 2. COMMISSION_CHARGED: -$100       │
     │ 3. BALANCE_INCREASE: +$900         │
     │                                     │
     │ BD: owner_balances                 │
     │ available_balance += 900            │
     └────────┬─────────────────────────────┘
              │
              ▼
T12: ┌──────────────────────────────────────┐
     │ Enqueue: payout.pending             │
     │ {                                    │
     │   "payoutId": "uuid",              │
     │   "ownerId": "owner-123",          │
     │   "amount": 900,                    │
     │   "orderId": "RES-..."             │
     │ }                                   │
     └────────┬─────────────────────────────┘
              │
              ▼
T13: ┌──────────────────────────────────────┐
     │ Queue Consumer #2 (2 concurrencia) │
     │ ├─ Obtiene info propietario        │
     │ ├─ Valida cuenta bancaria          │
     │ └─ Obtiene token Pichincha         │
     └────────┬─────────────────────────────┘
              │
              ▼
T14: ┌──────────────────────────────────────┐
     │ POST /banco-pichincha/transfers    │
     │ Payload:                             │
     │ {                                    │
     │   "idempotencyKey": "uuid-xxxx",    │
     │   "beneficiary": {                  │
     │     "accountNumber": "1234567890",  │
     │     "bankCode": "011",              │
     │     "accountHolder": {              │
     │       "name": "JUAN PEREZ",         │
     │       "identification": "1234..."   │
     │     }                               │
     │   },                                │
     │   "amount": 900,                    │
     │   "currency": "USD",                │
     │   "description": "Retiro..."        │
     │ }                                    │
     │ (Validación: Idempotency Key)       │
     └────────┬─────────────────────────────┘
              │
              ▼
T15: ┌──────────────────────────────────────┐
     │ Pichincha responde:                 │
     │ {                                    │
     │   "id": "trf-pichincha-xyz789",     │
     │   "status": "PENDING",              │
     │   "estimatedCompletionAt": "..."    │
     │ }                                    │
     └────────┬─────────────────────────────┘
              │
              ▼
T16: ┌──────────────────────────────────────┐
     │ BD: payouts UPDATE                  │
     │ ├─ status: IN_PROGRESS              │
     │ ├─ bank_transfer_id: trf-xyz789     │
     │ └─ estimated_completion: T+10 min   │
     └────────┬─────────────────────────────┘
              │
              ▼
T17: ┌──────────────────────────────────────┐
     │ Cron Job: Reconciliación (c/ 5 min)│
     │ ├─ GET /transfers/{trf-xyz789}      │
     │ ├─ Status = COMPLETED               │
     │ └─ Actualizar BD: status = COMPLETED│
     └────────┬─────────────────────────────┘
              │
              ▼
T18: ┌──────────────────────────────────────┐
     │ 💰 FONDOS RECIBIDOS EN CUENTA      │
     │    PROPIETARIO                      │
     │ Saldo: +$900                        │
     │ Cuenta: 1234...7890                 │
     │ Banco: Pichincha (interbancario OK) │
     └────────┬─────────────────────────────┘
              │
              ▼
T19: ┌──────────────────────────────────────┐
     │ Owner dashboard actualiza:          │
     │ ├─ Available balance: $900          │
     │ ├─ Total earned: $900               │
     │ ├─ Last payout: timestamp           │
     │ └─ Historial de transacciones       │
     └──────────────────────────────────────┘

FIN ✅

═════════════════════════════════════════════════════════════════════════════════
```

---

## 2. Arquitectura de Seguridad: HMAC Validation

```
┌────────────────────────────────────────────────────────────────────────────────┐
│                     VALIDACIÓN DE WEBHOOKS (HMAC-SHA256)                       │
└────────────────────────────────────────────────────────────────────────────────┘

ESCENARIO 1: Webhook Legítimo
══════════════════════════════════════════════════════════════════════════════════

Deuna genera webhook:
┌──────────────────────────────────────────────────────────────────┐
│ const payload = JSON.stringify({                                 │
│   "id": "evt-123",                                               │
│   "data": { "orderId": "xyz", "status": "COMPLETED", ... }      │
│ });                                                              │
│                                                                  │
│ const signature = crypto                                         │
│   .createHmac('sha256', DEUNA_WEBHOOK_SECRET)                  │
│   .update(payload)                                              │
│   .digest('hex');                                               │
│                                                                  │
│ // Envía:                                                        │
│ // X-Deuna-Signature: sha256=abc123def456...                   │
└──────────────────────────────────────────────────────────────────┘
                          ↓ POST
                 Tu Servidor Recibe
                          ↓
Tu Servidor valida:
┌──────────────────────────────────────────────────────────────────┐
│ const incomingSignature = req.headers['x-deuna-signature'];     │
│ // "sha256=abc123def456..."                                      │
│                                                                  │
│ const expectedSignature = crypto                                │
│   .createHmac('sha256', DEUNA_WEBHOOK_SECRET)                  │
│   .update(req.rawBody)  // ⚠️ DEBE ser raw, no parsed         │
│   .digest('hex');                                               │
│ // "abc123def456..."                                             │
│                                                                  │
│ if (incomingSignature === `sha256=${expectedSignature}`) {     │
│   ✅ Webhook válido - Procesar                                  │
│ } else {                                                         │
│   ❌ Signature no coincide - Rechazar (401)                    │
│ }                                                                │
└──────────────────────────────────────────────────────────────────┘


ESCENARIO 2: Intento de Ataque (Webhook Falsificado)
══════════════════════════════════════════════════════════════════════════════════

Hacker intercepta webhook válido y lo reenvía:
┌──────────────────────────────────────────────────────────────────┐
│ POST /webhooks/deuna/payment-confirmed                           │
│ X-Deuna-Signature: sha256=abc123def456...                       │
│ {                                                                │
│   "id": "evt-123",                                               │
│   "data": {                                                      │
│     "orderId": "xyz",                                            │
│     "status": "COMPLETED",                                       │
│     "amount": 99999  ← HACKER MODIFICÓ                          │
│   }                                                              │
│ }                                                                │
└──────────────────────────────────────────────────────────────────┘
                          ↓ POST
                 Tu Servidor Valida
                          ↓
┌──────────────────────────────────────────────────────────────────┐
│ const incomingSignature = "sha256=abc123def456..."              │
│                                                                  │
│ const expectedSignature = crypto                                │
│   .createHmac('sha256', DEUNA_WEBHOOK_SECRET)                  │
│   .update(modifiedPayload)  // El payload modificado            │
│   .digest('hex');                                               │
│ // "xyz789abc123..."  ← DISTINTO!                              │
│                                                                  │
│ if (incomingSignature === `sha256=${expectedSignature}`) {     │
│   // "sha256=abc123def456..." !== "sha256=xyz789abc123..."     │
│   ❌ RECHAZADO - No coincide                                   │
│   → Retorna 401 Unauthorized                                    │
│ }                                                                │
└──────────────────────────────────────────────────────────────────┘

FIN: Ataque bloqueado ✅


ESCENARIO 3: Doble Proceso (Replay Attack)
══════════════════════════════════════════════════════════════════════════════════

Deuna envía webhook 2 veces (redundancia de sistema):
┌──────────────────────────────────────────────────────────────────┐
│ Webhook #1 → Tu servidor (procesado)                             │
│   BD: webhook_logs INSERT { webhook_event_id: "evt-123" }       │
│   Status: payment confirmado 1 vez                               │
│                                                                  │
│ Webhook #2 (idéntico) → Tu servidor                             │
│   BD: webhook_logs SELECT WHERE webhook_event_id = "evt-123"    │
│   Encontrado y ya procesado!                                    │
│   → Retorna 200 OK (idempotente, sin duplicar)                 │
└──────────────────────────────────────────────────────────────────┘

FIN: Duplicado ignorado, sin efectos ✅

```

---

## 3. Flujo de Idempotencia: Transfer a Banco

```
┌────────────────────────────────────────────────────────────────────────────────┐
│                  IDEMPOTENCY: Evitar Doble Pago                                │
└────────────────────────────────────────────────────────────────────────────────┘

ESCENARIO 1: First Request (Normal)
══════════════════════════════════════════════════════════════════════════════════

Client:
┌──────────────────────────────────────────────────────────────────┐
│ POST /api/v1/owners/owner-123/request-payout                    │
│ X-Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000       │
│ {                                                                │
│   "amount": 900,                                                 │
│   "recipientAccount": "1234567890",                             │
│   "recipientBank": "011"                                        │
│ }                                                                │
└──────────────────────────────────────────────────────────────────┘
                          ↓
Backend:
┌──────────────────────────────────────────────────────────────────┐
│ 1. Buscar en idempotency_keys                                   │
│    SELECT * WHERE key = '550e8400-...'                          │
│    → No encontrado                                              │
│                                                                  │
│ 2. Procesar request:                                            │
│    - Obtener token Pichincha                                    │
│    - POST /pichincha/transfers (con mismo X-Idempotency-Key)   │
│    - Pichincha responde: { id: "trf-xyz789", status: "PENDING"}│
│                                                                  │
│ 3. Guardar en DB:                                               │
│    idempotency_keys INSERT {                                    │
│      key: '550e8400-...',                                       │
│      operation_type: 'CREATE_TRANSFER',                         │
│      response_payload: '{"transferId": "trf-xyz789"}',          │
│      expires_at: NOW() + 24 HOURS                               │
│    }                                                             │
│                                                                  │
│ 4. Response (201):                                              │
│    {                                                             │
│      "transferId": "trf-xyz789",                                │
│      "status": "PENDING"                                        │
│    }                                                             │
└──────────────────────────────────────────────────────────────────┘


ESCENARIO 2: Retry (Network Error)
══════════════════════════════════════════════════════════════════════════════════

T1: Client envía request (como arriba)
    Backend responde pero response se pierde (timeout, 500, etc)

T2: Client reintenta (mismo X-Idempotency-Key):
┌──────────────────────────────────────────────────────────────────┐
│ POST /api/v1/owners/owner-123/request-payout                    │
│ X-Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000       │
│ (idéntico al anterior)                                          │
└──────────────────────────────────────────────────────────────────┘
                          ↓
Backend:
┌──────────────────────────────────────────────────────────────────┐
│ 1. Buscar en idempotency_keys                                   │
│    SELECT * WHERE key = '550e8400-...'                          │
│    → ENCONTRADO!                                                │
│    Cached response: {"transferId": "trf-xyz789"}               │
│                                                                  │
│ 2. Retornar cached response (sin procesar de nuevo):            │
│    HTTP 200                                                      │
│    X-Idempotency-Replay: true                                   │
│    {                                                             │
│      "transferId": "trf-xyz789",                                │
│      "status": "PENDING",                                       │
│      "isIdempotentReplay": true                                 │
│    }                                                             │
│                                                                  │
│    ✅ NO se crea segunda transferencia                          │
│    ✅ Propietario NO recibe dinero 2 veces                      │
└──────────────────────────────────────────────────────────────────┘


ESCENARIO 3: Pichincha ya procesó la transferencia (409)
══════════════════════════════════════════════════════════════════════════════════

Backend intenta crear transfer en Pichincha:
┌──────────────────────────────────────────────────────────────────┐
│ POST https://api.pichincha.com/v1/transfers                     │
│ X-Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000       │
│                                                                  │
│ Pichincha responde:                                             │
│ HTTP 409 Conflict                                               │
│ "Transfer with this idempotency key already exists"            │
│                                                                  │
│ Backend maneja 409:                                             │
│ ├─ Busca en payouts WHERE idempotency_key = '550e8400-...'    │
│ ├─ Encuentra: { id: "trf-xyz789", status: "COMPLETED" }        │
│ └─ Retorna exitosamente (transfer ya fue procesada)            │
└──────────────────────────────────────────────────────────────────┘

FIN: Todas las rutas convergen a una sola transferencia ✅

```

---

## 4. Ciclo de Vida de Estados

```
┌────────────────────────────────────────────────────────────────────────────────┐
│                        STATE MACHINE: Órdenes y Payouts                        │
└────────────────────────────────────────────────────────────────────────────────┘

ÓRDENES (orders)
════════════════════════════════════════════════════════════════════════════════════

                    ┌──────────────────┐
                    │    PENDING       │ (Creada, QR mostrado)
                    └────────┬─────────┘
                             │
                   ┌─────────┴──────────┐
                   │                    │
        (Guest paga) │                  │ (Expira luego de 15 min)
                   ▼                    ▼
            ┌───────────────┐    ┌──────────────┐
            │     PAID      │    │   EXPIRED    │
            └────────┬──────┘    └──────────────┘
                     │
                     │ (Webhook procesado)
                     ▼
            ┌───────────────┐
            │  PAID (Final) │ → Ledger actualizado
            └───────────────┘    Balance propietario ↑


PAYOUTS (payouts)
════════════════════════════════════════════════════════════════════════════════════

┌──────────────────┐
│    PENDING       │ (Esperando procesar)
└────────┬─────────┘
         │
         │ (Banco info completa)
         ▼
┌──────────────────┐
│  IN_PROGRESS     │ (Transfer enviado a Pichincha)
└────────┬─────────┘
         │
    ┌────┴───────────────┐
    │                    │
    │ (Completado)       │ (Falló)
    ▼                    ▼
┌────────────────┐  ┌──────────────┐
│   COMPLETED    │  │    FAILED    │
└────────────────┘  └──────────────┘
  Fondos en cuenta   Retry automático
  Ledger actualizado Alertas enviadas


TRANSICIONES ESPECIALES
════════════════════════════════════════════════════════════════════════════════════

WAITING_BANK_INFO
├─ Razón: Owner no configuró cuenta bancaria
├─ Acción: Notificar propietario
└─ Resolución: Owner completa datos → Auto-reintento

CANCELLED
├─ Razón: Propietario solicita cancelación
└─ Acción: Restaurar balance (si aplica)

```

---

## 5. Monitoreo: Dashboard de Métricas

```
┌────────────────────────────────────────────────────────────────────────────────┐
│                           PROMETHEUS METRICS                                    │
└────────────────────────────────────────────────────────────────────────────────┘

REAL-TIME DASHBOARD
════════════════════════════════════════════════════════════════════════════════════

┌─────────────────────────────────────────────────────────────────────────────┐
│ ÓRDENES CREADAS (Última 1 hora)                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│ Status:                                                                     │
│   ├─ PENDING:  45 órdenes (actual)                                        │
│   ├─ PAID:     1,234 órdenes (completadas)                                │
│   ├─ EXPIRED:  3 órdenes (expiradas)                                      │
│   └─ FAILED:   0 órdenes                                                   │
│                                                                             │
│ Monto Total Procesado:                                                      │
│   $1,234,560.00 USD (Gross Revenue)                                         │
│   $123,456.00 USD (Platform Commission, 10%)                               │
│   $1,111,104.00 USD (Owner Earnings)                                        │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│ PAYOUTS (Estado actual)                                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│ En Cola:       12 payouts (waiting)                                         │
│ En Progreso:   5 payouts (to Pichincha)                                     │
│ Completados:   3,456 payouts (en últimas 24h)                             │
│ Fallidos:      2 payouts (retry scheduled)                                 │
│                                                                             │
│ Monto Total Dispersado (últimas 24h):                                       │
│   $45,678.90 USD                                                           │
│                                                                             │
│ Tiempo Promedio:                                                            │
│   Queue → Payout: 2 minutos                                                 │
│   Payout → Completed: 8 minutos                                             │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│ COLAS DE MENSAJES                                                            │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│ payment.confirmed:                                                          │
│   ├─ Waiting: 3                                                             │
│   ├─ Active:  1                                                             │
│   ├─ Delayed: 0                                                             │
│   └─ Failed:  0                                                             │
│                                                                             │
│ payout.pending:                                                             │
│   ├─ Waiting: 12                                                            │
│   ├─ Active:  2                                                             │
│   ├─ Delayed: 0                                                             │
│   └─ Failed:  2 (retrying)                                                 │
│                                                                             │
│ payout.retry:                                                               │
│   ├─ Waiting: 1                                                             │
│   ├─ Active:  0                                                             │
│   └─ Failed:  0                                                             │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│ INTEGRACIONES EXTERNAS                                                       │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│ Deuna OAuth Token Cache:                                                    │
│   ├─ Status: ✅ VALID                                                       │
│   ├─ Expires in: 45 minutos                                                │
│   └─ Requests (última 1h): 1,234 reutilizadas, 2 nuevos tokens            │
│                                                                             │
│ Banco Pichincha API:                                                        │
│   ├─ Status: ✅ HEALTHY                                                     │
│   ├─ Response Time (p95): 340ms                                             │
│   ├─ Error Rate: 0.02%                                                      │
│   └─ Circuit Breaker: CLOSED (Normal)                                       │
│                                                                             │
│ Database Connections:                                                       │
│   ├─ Active: 8/20                                                           │
│   ├─ Waiting: 0                                                             │
│   └─ Avg Query Time: 45ms                                                   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘

ALERTAS ACTIVAS
════════════════════════════════════════════════════════════════════════════════════

├─ ⚠️  WARNING: Pichincha API latency above 500ms (p95: 580ms)
│       └─ Time: 14:35 UTC
│       └─ Action: Monitoring, no action yet
│
├─ 🔴 CRITICAL: 2 payouts stuck in FAILED status for > 1 hour
│       └─ Payouts: [payout-abc123, payout-def456]
│       └─ Time: 14:22 UTC
│       └─ Action: Manual review required
│
└─ ℹ️  INFO: Daily reconciliation completed successfully
        └─ Records: 12,345 orders, 11,234 payouts
        └─ Time: 03:15 UTC

```

---

## 6. Tabla Comparativa: Métodos de Pago

```
┌────────────────────────────────────────────────────────────────────────────────┐
│              COMPARATIVA: Aggregator vs. Payfac vs. Gateway                    │
└────────────────────────────────────────────────────────────────────────────────┘

╔════════════════════════════════════════════════════════════════════════════════╗
║                                AGGREGATOR (Tu Modelo)                          ║
║════════════════════════════════════════════════════════════════════════════════║

┌─ REQUISITOS
│  ├─ Licencia: Fácil (Deuna ya agregada a Pichincha)
│  ├─ Compliance: Medio (PCI-DSS, GDPR)
│  ├─ Setup: 2-4 semanas
│  └─ Costo: Comisión por transacción (5-15% típico)
│
├─ FLUJO
│  ├─ 1. Guest paga vía QR Deuna (no toca tarjeta)
│  ├─ 2. Dinero va a tu cuenta recaudadora (BP)
│  ├─ 3. Tú dispersas a propietarios
│  └─ 4. Tú controlas todo el ledger
│
├─ VENTAJAS
│  ├─ ✅ Control total del dinero
│  ├─ ✅ Máxima transparencia
│  ├─ ✅ Mejor para compliance
│  ├─ ✅ Relación directa con propietarios
│  └─ ✅ Datos para análisis (tuyos)
│
└─ DESVENTAJAS
   ├─ ❌ Responsabilidad de dispersión
   ├─ ❌ Manejo de disputas
   ├─ ❌ Reconciliación manual
   ├─ ❌ Riesgo operacional
   └─ ❌ Requiere colas + monitoreo


╔════════════════════════════════════════════════════════════════════════════════╗
║                            PAYFAC (PayFacilitator)                            ║
║════════════════════════════════════════════════════════════════════════════════║

┌─ REQUISITOS
│  ├─ Licencia: Difícil (necesitas acreditación de Visa/MasterCard)
│  ├─ Compliance: Alto (PCI-DSS L1, AML/KYC)
│  ├─ Setup: 3-6 meses
│  └─ Costo: Comisión + fee fijo
│
├─ FLUJO
│  ├─ 1. Tú eres "merchant" de tus propietarios
│  ├─ 2. Guest paga directamente a sub-merchant del propietario
│  ├─ 3. Tú creas sub-merchant accounts
│  └─ 4. Red procesa y dispersa
│
├─ VENTAJAS
│  ├─ ✅ Máximo control regulatorio
│  ├─ ✅ Escalable a 1000s de merchants
│  └─ ✅ Less liability en algunos casos
│
└─ DESVENTAJAS
   ├─ ❌ Muy caro y complejo
   ├─ ❌ Requiere KYC de propietarios
   ├─ ❌ Compliance muy estricto
   └─ ❌ No es viable para MVP


╔════════════════════════════════════════════════════════════════════════════════╗
║                          GATEWAY (Stripe/Adyen)                               ║
║════════════════════════════════════════════════════════════════════════════════║

┌─ REQUISITOS
│  ├─ Licencia: Ninguno (Gateway se encarga)
│  ├─ Compliance: Bajo (Gateway cumple)
│  ├─ Setup: 1-2 semanas
│  └─ Costo: Comisión alta (3-4%) + fee
│
├─ FLUJO
│  ├─ 1. Guest paga a Stripe (Stripe maneja dinero)
│  ├─ 2. Stripe te dispersa neto a ti
│  ├─ 3. Tú dispersas a propietarios
│  └─ 4. Stripe maneja reconciliación
│
├─ VENTAJAS
│  ├─ ✅ Cero compliance burden
│  ├─ ✅ Setup rápido
│  └─ ✅ Soporte excelente
│
└─ DESVENTAJAS
   ├─ ❌ Costo muy alto (3-4%)
   ├─ ❌ Menos control
   ├─ ❌ Datos limitados
   └─ ❌ Margen muy reducido


║════════════════════════════════════════════════════════════════════════════════║
║                         RECOMENDACIÓN PARA ARRIENDO-FACIL                     ║
║════════════════════════════════════════════════════════════════════════════════║

📊 MATRIZ DE DECISIÓN:

                Costo   │ Control │ Rapidez │ Escalabilidad │ Compliance
    ────────────────────┼─────────┼─────────┼───────────────┼─────────────
    Aggregator (✓ TU)   │  Bajo   │  Alto   │    Rápido     │  Manejable
    Payfac              │ Altísimo│  Máximo │     Lento      │ Máximo
    Gateway (Stripe)    │  Alto   │ Medio   │    Rápido     │   Cero
    ────────────────────┴─────────┴─────────┴───────────────┴─────────────

✅ ELECCIÓN: Aggregator (Deuna + Banco Pichincha)

  Por qué:
  ├─ 10% comisión vs 3% (gateway) = Margen sano
  ├─ Control total del flujo de dinero
  ├─ Pocos propietarios inicialmente (escalabilidad)
  ├─ Mejor para compliance ecuatoriano
  ├─ Deuna ya es agregador → responsabilidad compartida
  └─ Setup en semanas, no meses

```

---

## 7. Matriz de Decisiones: Estados Críticos

```
┌────────────────────────────────────────────────────────────────────────────────┐
│                    TOMAR DECISIONES EN SITUACIONES CRÍTICAS                    │
└────────────────────────────────────────────────────────────────────────────────┘

PROBLEMA 1: Webhook de Deuna se pierde (timeout)
════════════════════════════════════════════════════════════════════════════════════
┌─────────────────────────────┐
│ Síntoma:                    │
│ Orden en BD: PENDING        │
│ Pero dinero en cuenta ✅    │
└─────────────────────────────┘
                ↓
   SOLUCIÓN: Cron Job Reconciliación
   ├─ Cada 5 minutos
   ├─ Buscar órdenes PENDING creadas hace > 10 minutos
   ├─ Verificar estado en Deuna API
   ├─ Si COMPLETED en Deuna: Actualizar BD a PAID
   └─ Procesar como pago normal


PROBLEMA 2: Transfer a Pichincha responde 500 (server error)
════════════════════════════════════════════════════════════════════════════════════
┌─────────────────────────────┐
│ Síntoma:                    │
│ Propietario ve balance      │
│ Pero fondos no llegaron     │
└─────────────────────────────┘
                ↓
   SOLUCIÓN: Circuit Breaker + Retry Automático
   ├─ 1er intento: Inmediato (fail = 2s exponential retry)
   ├─ 2-5 intentos: 5 segundos cada uno
   ├─ Si sigue fallando: Mover a payout.retry
   ├─ payout.retry: Intentos cada 30 min (hasta 10 veces)
   └─ Si aún falla: Alertar + Manual review


PROBLEMA 3: Dos requests simultáneos para transfer (race condition)
════════════════════════════════════════════════════════════════════════════════════
┌─────────────────────────────┐
│ Síntoma:                    │
│ Propietario recibe $900 × 2 │
└─────────────────────────────┘
                ↓
   PREVENCIÓN: Idempotency Key + BD Lock
   ├─ Cliente envía X-Idempotency-Key único
   ├─ Backend verifica en idempotency_keys tabla
   ├─ Si no existe: Procesar + Guardar resultado
   ├─ Si existe: Retornar cached response
   └─ Pichincha también valida: si 409 → return existing


PROBLEMA 4: Propietario sin cuenta bancaria
════════════════════════════════════════════════════════════════════════════════════
┌─────────────────────────────┐
│ Síntoma:                    │
│ Payout job falla            │
│ Fondos bloqueados            │
└─────────────────────────────┘
                ↓
   SOLUCIÓN: Validación previa + Estado WAITING_BANK_INFO
   ├─ Antes de enqueue payout:
   │  └─ Validar: owner.bank_account_number != null
   ├─ Si falta:
   │  ├─ Payout state = WAITING_BANK_INFO
   │  ├─ Notificar propietario (email + push)
   │  └─ Reintento automático cada 24h
   ├─ Cuando completa:
   │  ├─ Disparar webhook de confirmación
   │  └─ Requeue payout automáticamente


PROBLEMA 5: Balance negativo (double-spend bug)
════════════════════════════════════════════════════════════════════════════════════
┌─────────────────────────────┐
│ Síntoma:                    │
│ owner_balances.available = -$50
│ (bug en cálculo de comisión) │
└─────────────────────────────┘
                ↓
   PREVENCIÓN: Validaciones + Ledger Audit
   ├─ 1. Constraint en BD:
   │  └─ ALTER TABLE owner_balances
   │      ADD CONSTRAINT check_balance CHECK (available_balance >= 0);
   ├─ 2. Ledger completo (no touch balance directamente):
   │  └─ Todas las operaciones = INSERT en ledger_entries
   ├─ 3. Daily audit:
   │  ├─ SUM(ledger_entries) debe = owner_balances
   │  └─ Si no: Generar alert
   └─ 4. Test:
      └─ Simular 1000 órdenes simultáneas


PROBLEMA 6: Pichincha API está en mantenimiento (6 horas)
════════════════════════════════════════════════════════════════════════════════════
┌─────────────────────────────┐
│ Síntoma:                    │
│ Payouts no se pueden enviar │
│ Propietarios esperando      │
└─────────────────────────────┘
                ↓
   SOLUCIÓN: Graceful Degradation
   ├─ Circuit Breaker abierto
   │  ├─ Todos los payouts → state = PENDING_RETRY
   │  └─ Requeue cada 10 minutos
   ├─ Propietarios notificados:
   │  └─ "Transfer delayed, trying again"
   ├─ Dashboard muestra:
   │  └─ "Pichincha temporarily unavailable"
   └─ Manual fallback:
      ├─ Admin puede iniciar transfer batch manualmente
      └─ O diferir hasta recovery

```

---

## Resumen Ejecutivo

| Aspecto | Detalles |
|---------|----------|
| **Modelo** | Agregador (Deuna + Banco Pichincha) |
| **Flujo** | Reserva → QR → Pago → Webhook → Ledger → Payout → Bank |
| **Órdenes** | Status: PENDING → PAID → (Webhook) |
| **Payouts** | Status: PENDING → IN_PROGRESS → COMPLETED |
| **Seguridad** | HMAC-SHA256 webhooks, Idempotency Keys, Circuit Breaker |
| **Base de Datos** | 6 tablas principales + vistas para reportes |
| **Colas** | Bull + Redis (payment.confirmed, payout.pending, payout.retry) |
| **Monitoreo** | Prometheus metrics + Slack/PagerDuty alerts |
| **SLA** | Pago → Propietario: ~10-15 minutos |
| **Escalabilidad** | 1000s órdenes/día, <200ms latency |

