# Arquitectura de Pagos y Dispersión de Fondos
## Marketplace de Arriendos - Modelo Agregador (Deuna + Banco Pichincha)

**Versión:** 1.0  
**Autor:** Equipo Técnico  
**Fecha:** 2026-07-16  
**Estado:** Diseño Técnico

---

## Tabla de Contenidos

1. [Visión General](#visión-general)
2. [Flujo de Negocio End-to-End](#flujo-de-negocio-end-to-end)
3. [Arquitectura de Sistemas](#arquitectura-de-sistemas)
4. [Endpoints REST y Payloads](#endpoints-rest-y-payloads)
5. [Diseño del Ledger (Base de Datos)](#diseño-del-ledger-base-de-datos)
6. [Seguridad e Idempotencia](#seguridad-e-idempotencia)
7. [Manejo de Reintentos y Colas](#manejo-de-reintentos-y-colas)
8. [Casos de Borde y Excepciones](#casos-de-borde-y-excepciones)
9. [Monitoreo y Auditoría](#monitoreo-y-auditoría)

---

## Visión General

### Modelo Agregador

Tu plataforma actúa como **Agregador de Pagos** ante Deuna y como **Ordenante de Transferencias** ante Banco Pichincha:

```
┌─────────────┐
│    Guest    │ (Huésped)
└──────┬──────┘
       │ Reserva + Pago con QR
       ▼
┌──────────────────────┐
│  Plataforma MP       │ (Tu Sistema)
│  ├─ Credenciales     │
│  │  Deuna (OAuth)    │
│  ├─ Ledger de Pagos  │
│  └─ Comisiones       │
└────┬──────────────┬──┘
     │ QR Dinámico  │ 
     ▼              │ Webhook
  ┌──────────┐      │ (Async)
  │  DEUNA   │◄─────┘
  │ (BPA)    │
  └────┬─────┘
       │ Dinero entra a
       │ cuenta recaudadora
       ▼
┌──────────────────────┐
│  Cuenta Recaudadora  │
│  (Banco Pichincha)   │
└────┬─────────────────┘
     │ Resto: Neto a propietario
     ├─ Menos comisión (10%)
     │
     ▼
┌──────────────────────┐
│ Cash Management API  │
│ (Banco Pichincha)    │
└────┬─────────────────┘
     │ Transferencia automática
     ▼
┌──────────────────────┐
│  Cuenta Propietario  │
│  (Interbanc. OK)     │
└──────────────────────┘
```

---

## Flujo de Negocio End-to-End

### Secuencia Temporal

```
T=0s   | Guest inicia reserva → Backend genera orden
       |
T=1s   | Backend: POST /deuna/orders (OAuth 2.0)
       | ├─ amount: 1000 USD
       | ├─ idempotencyKey: uuid
       | └─ Respuesta: orderId + QR base64/URL
       |
T=2s   | Frontend muestra QR → Guest escanea
       |
T=30s  | Guest realiza pago en app bancaria
       |
T=45s  | Deuna procesa pago → Dinero entra a Recaudadora
       |
T=50s  | Deuna: POST /webhook/payment-confirmed
       | ├─ orderId
       | ├─ status: "COMPLETED"
       | ├─ signature: HMAC-SHA256
       | └─ timestamp
       |
T=51s  | Backend:
       | ├─ Valida firma HMAC
       | ├─ Marca orden como PAID en DB
       | ├─ Calcula comisión (10% = 100)
       | ├─ Saldo neto al owner = 900
       | ├─ Enqueue: Cash Management request
       |
T=55s  | Backend: POST /banco-pichincha/transfers (OAuth)
       | ├─ idempotencyKey: uuid (importante!)
       | ├─ amount: 900
       | ├─ owner_account_id: xxxx
       | └─ Respuesta: transferId
       |
T=60s  | Banco Pichincha procesa transferencia
       | → Fondos a cuenta propietario
       |
T=120s | Backend: GET /banco-pichincha/transfers/{transferId}
       | ├─ status: "COMPLETED"
       | └─ Actualiza ledger
```

---

## Arquitectura de Sistemas

### Componentes Principales

```
┌─────────────────────────────────────────────────────────────┐
│                   PLATAFORMA DE ARRIENDOS                   │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │             Capa de Presentación (Next.js)           │   │
│  │  ├─ Flujo de reserva                                 │   │
│  │  ├─ QR dinámico mostrado                             │   │
│  │  └─ Dashboard de propietarios                        │   │
│  └────────────────┬─────────────────────────────────────┘   │
│                   │                                          │
│  ┌────────────────▼─────────────────────────────────────┐   │
│  │        Capa de Aplicación (Node.js/Express)          │   │
│  │  ┌──────────────────────────────────────────────┐    │   │
│  │  │  Payment Orchestration Layer                 │    │   │
│  │  │  ├─ OrderService (crear orden Deuna)        │    │   │
│  │  │  ├─ WebhookHandler (Deuna callbacks)        │    │   │
│  │  │  ├─ CommissionCalculator                    │    │   │
│  │  │  ├─ PayoutOrchestrator (Banco Pichincha)   │    │   │
│  │  │  └─ IdempotencyManager                      │    │   │
│  │  └──────────────────────────────────────────────┘    │   │
│  └─────┬──────────────────────────────────────────────┬─┘   │
│        │                                              │      │
│  ┌─────▼──────────────────┐            ┌──────────────▼─┐    │
│  │   Integración Deuna    │            │ Integración BP │    │
│  │  ├─ Auth (OAuth 2.0)   │            │ ├─ Auth OAuth  │    │
│  │  ├─ /orders            │            │ ├─ /transfers  │    │
│  │  ├─ /webhook (RxJSON)  │            │ ├─ GET status  │    │
│  │  └─ Timeout handling   │            │ └─ Retries     │    │
│  └──────┬─────────────────┘            └─────┬──────────┘    │
│         │                                     │               │
│  ┌──────▼─────────────────────────────────────▼────────────┐ │
│  │      Capa de Persistencia (PostgreSQL)                  │ │
│  │  ├─ orders (Deuna)                                      │ │
│  │  ├─ payments (Transacciones)                            │ │
│  │  ├─ owner_balances (Billeteras)                         │ │
│  │  ├─ payouts (Transferencias salida)                     │ │
│  │  ├─ ledger_entries (Registros contables)                │ │
│  │  ├─ idempotency_keys (Anti-duplicación)                 │ │
│  │  └─ webhook_logs (Auditoría)                            │ │
│  └──────────────────────────────────────────────────────────┘ │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │       Capa de Encolado de Mensajes (RabbitMQ/SQS)    │   │
│  │  ├─ Queue: payment.confirmed                         │   │
│  │  ├─ Queue: payout.pending                            │   │
│  │  ├─ Queue: payout.retry                              │   │
│  │  └─ Queue: reconciliation.daily                       │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │      Servicios Auxiliares                            │   │
│  │  ├─ Logger (Winston/Pino)                            │   │
│  │  ├─ Metrics (Prometheus)                             │   │
│  │  ├─ Vault (Credenciales sensibles)                   │   │
│  │  └─ Cron Jobs (Reconciliación)                       │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│              SISTEMAS EXTERNOS (Integraciones)              │
├─────────────────────────────────────────────────────────────┤
│  ┌──────────────────────────────────────────────────────┐   │
│  │  DEUNA (BPA)                                         │   │
│  │  ├─ POST https://api.deuna.io/v1/orders            │   │
│  │  ├─ POST https://api.deuna.io/v1/auth/token        │   │
│  │  └─ Webhook ← POST https://tudominio.com/webhooks  │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  BANCO PICHINCHA (Cash Management)                  │   │
│  │  ├─ POST https://api-sandbox.pichincha.com/transfer │   │
│  │  ├─ GET https://api-sandbox.pichincha.com/transfer/{id}  │
│  │  └─ POST https://api-sandbox.pichincha.com/auth    │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

---

## Endpoints REST y Payloads

### A. Autenticación Deuna (OAuth 2.0)

**Endpoint:** `POST https://api.deuna.io/v1/auth/token`

**Responsabilidad:** Obtener Bearer Token corporativo válido por 1 hora aprox.

**Payload Request:**
```json
{
  "grant_type": "client_credentials",
  "client_id": "{{DEUNA_CLIENT_ID}}",
  "client_secret": "{{DEUNA_CLIENT_SECRET}}",
  "scope": "orders:create webhooks:verify"
}
```

**Respuesta (200 OK):**
```json
{
  "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "scope": "orders:create webhooks:verify"
}
```

**Código Backend (Node.js):**
```javascript
// src/services/deuna/auth.ts
import axios from 'axios';
import NodeCache from 'node-cache';

const tokenCache = new NodeCache({ stdTTL: 3300 }); // 55 min < 1 hour

export async function getDeunaToken(): Promise<string> {
  const cached = tokenCache.get('deuna_token');
  if (cached) return cached;

  try {
    const response = await axios.post(
      'https://api.deuna.io/v1/auth/token',
      {
        grant_type: 'client_credentials',
        client_id: process.env.DEUNA_CLIENT_ID,
        client_secret: process.env.DEUNA_CLIENT_SECRET,
        scope: 'orders:create webhooks:verify'
      },
      { timeout: 5000 }
    );

    tokenCache.set('deuna_token', response.data.access_token);
    return response.data.access_token;
  } catch (error) {
    logger.error('Failed to get Deuna token', error);
    throw new DeunaAuthError('OAuth token acquisition failed');
  }
}
```

---

### B. Crear Orden en Deuna (Generar QR)

**Endpoint:** `POST https://api.deuna.io/v1/orders`

**Headers Requeridos:**
```
Authorization: Bearer {access_token}
Content-Type: application/json
X-Idempotency-Key: {uuid}
```

**Payload Request:**
```json
{
  "amount": 1000.00,
  "currency": "USD",
  "orderId": "RES-2026-07-16-12345",
  "description": "Reserva propiedad ID: 789 (15-18 julio)",
  "idempotencyKey": "550e8400-e29b-41d4-a716-446655440000",
  "customer": {
    "email": "guest@example.com",
    "phone": "+593987654321",
    "firstName": "Juan",
    "lastName": "Perez"
  },
  "metadata": {
    "propertyId": "789",
    "checkInDate": "2026-07-15",
    "checkOutDate": "2026-07-18",
    "nights": 3,
    "platformCommission": 100.00
  },
  "returnUrl": "https://tudominio.com/reservas/RES-2026-07-16-12345/success",
  "notificationUrl": "https://tudominio.com/webhooks/deuna/payment-confirmed",
  "expiresIn": 900
}
```

**Respuesta (200 OK):**
```json
{
  "id": "order-deuna-abc123",
  "orderId": "RES-2026-07-16-12345",
  "status": "PENDING",
  "amount": 1000.00,
  "currency": "USD",
  "qrCode": {
    "format": "base64",
    "data": "iVBORw0KGgoAAAANSUhEUgAAAyQAASCAYAAAC...",
    "url": "https://deuna-qr-storage.s3.amazonaws.com/qr-abc123.png"
  },
  "createdAt": "2026-07-16T14:30:00Z",
  "expiresAt": "2026-07-16T14:45:00Z",
  "metadata": {
    "propertyId": "789"
  }
}
```

**Código Backend (Node.js):**
```javascript
// src/services/deuna/orders.ts
import crypto from 'crypto';
import { getDeunaToken } from './auth';

export interface CreateOrderRequest {
  amount: number;
  currency: string;
  propertyId: string;
  guestEmail: string;
  guestPhone: string;
  checkInDate: string;
  checkOutDate: string;
}

export async function createDeunaOrder(
  req: CreateOrderRequest,
  tx: Transaction
): Promise<{ orderId: string; deunaId: string; qrBase64: string }> {
  
  const idempotencyKey = crypto.randomUUID();
  const orderId = `RES-${Date.now()}-${crypto.randomBytes(4).toString('hex')}`;
  
  const token = await getDeunaToken();
  
  try {
    const response = await axios.post(
      'https://api.deuna.io/v1/orders',
      {
        amount: req.amount,
        currency: req.currency,
        orderId,
        description: `Reserva propiedad ${req.propertyId}`,
        idempotencyKey,
        customer: {
          email: req.guestEmail,
          phone: req.guestPhone
        },
        metadata: {
          propertyId: req.propertyId,
          checkInDate: req.checkInDate,
          checkOutDate: req.checkOutDate
        },
        notificationUrl: `${process.env.BASE_URL}/webhooks/deuna/payment-confirmed`,
        expiresIn: 900
      },
      {
        headers: {
          'Authorization': `Bearer ${token}`,
          'X-Idempotency-Key': idempotencyKey
        },
        timeout: 10000
      }
    );

    // Guardar en BD
    await tx('orders').insert({
      id: orderId,
      deuna_id: response.data.id,
      property_id: req.propertyId,
      guest_email: req.guestEmail,
      amount: req.amount,
      status: 'PENDING',
      qr_data: response.data.qrCode.data,
      idempotency_key: idempotencyKey,
      created_at: new Date(),
      expires_at: new Date(response.data.expiresAt)
    });

    return {
      orderId,
      deunaId: response.data.id,
      qrBase64: response.data.qrCode.data
    };

  } catch (error) {
    logger.error('Failed to create Deuna order', { orderId, error });
    throw new DeunaOrderError('Order creation failed');
  }
}
```

---

### C. Webhook de Confirmación de Pago (Deuna → Tu Plataforma)

**Endpoint:** `POST https://tudominio.com/webhooks/deuna/payment-confirmed`

**Headers que Deuna envía:**
```
Content-Type: application/json
X-Deuna-Signature: sha256={HMAC_SHA256_HEX}
X-Deuna-Timestamp: 2026-07-16T14:35:00Z
```

**Payload Recibido:**
```json
{
  "event": "payment.completed",
  "id": "evt-webhook-12345",
  "timestamp": "2026-07-16T14:35:00Z",
  "data": {
    "orderId": "order-deuna-abc123",
    "externalOrderId": "RES-2026-07-16-12345",
    "status": "COMPLETED",
    "amount": 1000.00,
    "currency": "USD",
    "paymentMethod": "mobile_banking",
    "paymentProcessor": "PICHINCHA",
    "transactionId": "TXN-pichincha-999",
    "paidAt": "2026-07-16T14:34:50Z",
    "metadata": {
      "propertyId": "789"
    }
  }
}
```

**Código Backend para Validación:**
```javascript
// src/webhooks/deuna-payment.ts
import crypto from 'crypto';
import { Router } from 'express';

const router = Router();

function validateDeunaSignature(
  payload: string,
  signature: string,
  secret: string
): boolean {
  const expectedSignature = `sha256=${crypto
    .createHmac('sha256', secret)
    .update(payload)
    .digest('hex')}`;

  return crypto.timingSafeEqual(
    Buffer.from(signature),
    Buffer.from(expectedSignature)
  );
}

router.post('/deuna/payment-confirmed', async (req, res) => {
  try {
    const rawBody = req.rawBody; // Necesitas capturar el raw body
    const signature = req.headers['x-deuna-signature'] as string;

    // Validar firma HMAC
    if (!validateDeunaSignature(
      rawBody,
      signature,
      process.env.DEUNA_WEBHOOK_SECRET!
    )) {
      logger.warn('Invalid Deuna webhook signature', { signature });
      return res.status(401).json({ error: 'Unauthorized' });
    }

    const { data } = req.body;
    const { orderId, externalOrderId, status, amount } = data;

    // Guardar webhook log ANTES de procesar
    await db('webhook_logs').insert({
      id: crypto.randomUUID(),
      provider: 'DEUNA',
      event: 'payment.completed',
      external_order_id: externalOrderId,
      status,
      payload: JSON.stringify(data),
      received_at: new Date(),
      processed_at: null,
      http_status: null
    });

    if (status !== 'COMPLETED') {
      logger.info('Payment not completed', { externalOrderId, status });
      return res.status(200).json({ acknowledged: true });
    }

    // Procesar pago de forma idempotente
    await processPaymentConfirmation({
      deunaOrderId: orderId,
      externalOrderId,
      amount,
      transactionId: data.transactionId,
      paidAt: data.paidAt
    });

    res.status(200).json({ acknowledged: true });

  } catch (error) {
    logger.error('Webhook processing error', error);
    // Retornar 500 para que Deuna reintente
    res.status(500).json({ error: 'Internal server error' });
  }
});

async function processPaymentConfirmation(params: any) {
  const trx = await db.transaction();
  
  try {
    // Buscar orden
    const order = await trx('orders')
      .where({ deuna_id: params.deunaOrderId })
      .first();

    if (!order) {
      throw new PaymentNotFoundError(`Order not found: ${params.deunaOrderId}`);
    }

    // Idempotencia: verificar si ya fue procesada
    const existingPayment = await trx('payments')
      .where({ order_id: order.id, status: 'COMPLETED' })
      .first();

    if (existingPayment) {
      logger.info('Payment already processed', { orderId: order.id });
      await trx.rollback();
      return;
    }

    // Marcar orden como pagada
    await trx('orders')
      .where({ id: order.id })
      .update({
        status: 'PAID',
        deuna_transaction_id: params.transactionId,
        paid_at: params.paidAt
      });

    // Crear entrada de pago
    const payment = await trx('payments').insert({
      id: crypto.randomUUID(),
      order_id: order.id,
      amount: order.amount,
      status: 'COMPLETED',
      transaction_id: params.transactionId,
      received_at: params.paidAt
    }).returning('*');

    // Calcular comisión
    const commission = order.amount * 0.10; // 10%
    const netAmount = order.amount - commission;

    // Obtener propietario
    const property = await trx('properties')
      .where({ id: order.property_id })
      .first();

    const ownerId = property.owner_id;

    // Actualizar balance del propietario
    await trx('owner_balances')
      .where({ owner_id: ownerId })
      .increment('available_balance', netAmount);

    // Crear entradas de ledger
    await trx('ledger_entries').insert([
      {
        id: crypto.randomUUID(),
        order_id: order.id,
        owner_id: ownerId,
        type: 'PAYMENT_RECEIVED',
        amount: order.amount,
        description: `Pago recibido - Reserva ${order.id}`,
        created_at: new Date()
      },
      {
        id: crypto.randomUUID(),
        order_id: order.id,
        owner_id: null,
        type: 'COMMISSION_CHARGED',
        amount: -commission,
        description: `Comisión plataforma (10%) - Reserva ${order.id}`,
        created_at: new Date()
      },
      {
        id: crypto.randomUUID(),
        order_id: order.id,
        owner_id: ownerId,
        type: 'BALANCE_INCREASE',
        amount: netAmount,
        description: `Saldo disponible para retiro - Reserva ${order.id}`,
        created_at: new Date()
      }
    ]);

    // Enqueue payout request
    await enqueuePayoutIfEligible(ownerId, netAmount);

    await trx.commit();
    logger.info('Payment processed successfully', { 
      orderId: order.id, 
      amount: order.amount,
      netAmount 
    });

  } catch (error) {
    await trx.rollback();
    throw error;
  }
}
```

**Middleware para Capturar Raw Body:**
```javascript
// src/middleware/raw-body.ts
import express from 'express';
import bodyParser from 'body-parser';

export function rawBodyMiddleware() {
  return express.json({
    verify: (req: any, res, buf, encoding) => {
      if (req.path === '/webhooks/deuna/payment-confirmed') {
        req.rawBody = buf.toString(encoding || 'utf8');
      }
    }
  });
}
```

---

### D. Autenticación Banco Pichincha (OAuth 2.0)

**Endpoint:** `POST https://api-sandbox.pichincha.com/oauth2/token` (sandbox)

**Payload Request:**
```json
{
  "grant_type": "client_credentials",
  "client_id": "{{PICHINCHA_CLIENT_ID}}",
  "client_secret": "{{PICHINCHA_CLIENT_SECRET}}"
}
```

**Respuesta (200 OK):**
```json
{
  "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "scope": "payment:transfer"
}
```

**Código Backend:**
```javascript
// src/services/banco-pichincha/auth.ts
import axios from 'axios';
import NodeCache from 'node-cache';

const tokenCache = new NodeCache({ stdTTL: 3300 });

export async function getPichinchaBPToken(): Promise<string> {
  const cached = tokenCache.get('pichincha_token');
  if (cached) return cached;

  try {
    const response = await axios.post(
      `${process.env.PICHINCHA_API_BASE}/oauth2/token`,
      {
        grant_type: 'client_credentials',
        client_id: process.env.PICHINCHA_CLIENT_ID,
        client_secret: process.env.PICHINCHA_CLIENT_SECRET
      },
      { timeout: 5000 }
    );

    tokenCache.set('pichincha_token', response.data.access_token);
    return response.data.access_token;
  } catch (error) {
    logger.error('Failed to get Pichincha token', error);
    throw new PichinchaBPAuthError('OAuth token acquisition failed');
  }
}
```

---

### E. Transferencia de Fondos (Cash Management)

**Endpoint:** `POST https://api-sandbox.pichincha.com/v1/transfers`

**Headers Requeridos:**
```
Authorization: Bearer {access_token}
Content-Type: application/json
X-Idempotency-Key: {uuid}
X-Request-ID: {uuid}
```

**Payload Request:**
```json
{
  "idempotencyKey": "550e8400-e29b-41d4-a716-446655440000",
  "beneficiary": {
    "accountNumber": "1234567890",
    "bankCode": "011", // Pichincha = 011, Banco Guayaquil = 012, etc.
    "accountType": "CHECKING", // CHECKING o SAVINGS
    "accountHolder": {
      "name": "JUAN PEREZ FLORES",
      "identification": "1234567890", // RUC o CI
      "identificationType": "CI"
    }
  },
  "amount": 900.00,
  "currency": "USD",
  "description": "Retiro de ganancias - Reserva RES-2026-07-16-12345",
  "reference": "RES-2026-07-16-12345", // Referencia única de tu plataforma
  "metadata": {
    "orderId": "RES-2026-07-16-12345",
    "payoutBatchId": "BATCH-2026-07-16-001",
    "ownerUserId": "owner-789"
  }
}
```

**Respuesta (200 OK):**
```json
{
  "id": "trf-pichincha-xyz789",
  "status": "PENDING",
  "amount": 900.00,
  "currency": "USD",
  "reference": "RES-2026-07-16-12345",
  "beneficiary": {
    "accountNumber": "****7890",
    "bankCode": "011",
    "accountHolder": {
      "name": "JUAN PEREZ FLORES"
    }
  },
  "createdAt": "2026-07-16T14:37:00Z",
  "estimatedCompletionAt": "2026-07-16T14:47:00Z"
}
```

**Código Backend:**
```javascript
// src/services/banco-pichincha/transfers.ts
import crypto from 'crypto';
import axios from 'axios';
import { getPichinchaBPToken } from './auth';

export interface TransferRequest {
  ownerId: string;
  amount: number;
  recipientAccountNumber: string;
  recipientBankCode: string;
  recipientName: string;
  recipientId: string;
  orderId: string;
  batchId: string;
}

export async function createTransfer(
  params: TransferRequest,
  trx: Transaction
): Promise<{ transferId: string; status: string }> {
  
  const idempotencyKey = crypto.randomUUID();
  const token = await getPichinchaBPToken();

  try {
    const response = await axios.post(
      `${process.env.PICHINCHA_API_BASE}/v1/transfers`,
      {
        idempotencyKey,
        beneficiary: {
          accountNumber: params.recipientAccountNumber,
          bankCode: params.recipientBankCode,
          accountType: 'CHECKING', // O desde BD del propietario
          accountHolder: {
            name: params.recipientName,
            identification: params.recipientId,
            identificationType: 'CI'
          }
        },
        amount: params.amount,
        currency: 'USD',
        description: `Retiro de ganancias - Reserva ${params.orderId}`,
        reference: params.orderId,
        metadata: {
          orderId: params.orderId,
          payoutBatchId: params.batchId,
          ownerUserId: params.ownerId
        }
      },
      {
        headers: {
          'Authorization': `Bearer ${token}`,
          'X-Idempotency-Key': idempotencyKey,
          'X-Request-ID': crypto.randomUUID()
        },
        timeout: 15000
      }
    );

    // Guardar payout request en BD
    await trx('payouts').insert({
      id: response.data.id,
      owner_id: params.ownerId,
      order_id: params.orderId,
      amount: params.amount,
      status: 'PENDING',
      recipient_account: params.recipientAccountNumber,
      recipient_bank: params.recipientBankCode,
      bank_transfer_id: response.data.id,
      idempotency_key: idempotencyKey,
      created_at: new Date(),
      estimated_completion: response.data.estimatedCompletionAt
    });

    return {
      transferId: response.data.id,
      status: response.data.status
    };

  } catch (error) {
    // Revisar si es error de idempotencia (ya existe)
    if (error.response?.status === 409) {
      logger.warn('Transfer already exists (idempotency)', { idempotencyKey });
      // Recuperar el transfer existente
      const existingTransfer = await trx('payouts')
        .where({ idempotency_key: idempotencyKey })
        .first();
      
      if (existingTransfer) {
        return {
          transferId: existingTransfer.id,
          status: existingTransfer.status
        };
      }
    }

    logger.error('Failed to create transfer', {
      ownerId: params.ownerId,
      amount: params.amount,
      error
    });
    throw new PichinchaBPTransferError('Transfer creation failed');
  }
}

// Consultar estado de transferencia
export async function getTransferStatus(transferId: string): Promise<any> {
  const token = await getPichinchaBPToken();

  try {
    const response = await axios.get(
      `${process.env.PICHINCHA_API_BASE}/v1/transfers/${transferId}`,
      {
        headers: {
          'Authorization': `Bearer ${token}`
        },
        timeout: 5000
      }
    );

    return response.data;

  } catch (error) {
    logger.error('Failed to fetch transfer status', { transferId, error });
    throw new PichinchaBPTransferError('Transfer status fetch failed');
  }
}
```

---

### F. Endpoint Interno: Consultar Balance Propietario

**Endpoint:** `GET /api/v1/owners/{ownerId}/balance`

**Respuesta:**
```json
{
  "ownerId": "owner-789",
  "availableBalance": 1500.50,
  "pendingPayouts": 2300.00,
  "totalEarnings": 3800.50,
  "lastPayoutAt": "2026-07-15T10:30:00Z",
  "breakdown": [
    {
      "orderId": "RES-2026-07-16-12345",
      "amount": 900.00,
      "status": "AVAILABLE",
      "reservationDates": "2026-07-15 a 2026-07-18"
    }
  ]
}
```

---

## Diseño del Ledger (Base de Datos)

### Diagrama ER (Entity-Relationship)

```
orders (Órdenes de Deuna)
  ├─ PK: id
  ├─ deuna_id
  ├─ property_id → properties.id
  ├─ guest_email
  ├─ amount
  ├─ status (PENDING, PAID, FAILED, EXPIRED)
  ├─ deuna_transaction_id
  ├─ qr_data
  ├─ idempotency_key [UNIQUE]
  ├─ created_at
  ├─ paid_at
  └─ expires_at

payments (Transacciones de Pago)
  ├─ PK: id
  ├─ order_id → orders.id [FK]
  ├─ amount
  ├─ status (COMPLETED, FAILED, PENDING)
  ├─ transaction_id
  ├─ received_at
  └─ created_at

owner_balances (Billeteras de Propietarios)
  ├─ PK: owner_id
  ├─ owner_id → users.id [FK]
  ├─ available_balance (dinero listo para retirar)
  ├─ pending_balance (dinero en tránsito)
  ├─ total_earnings
  ├─ last_payout_at
  └─ updated_at

payouts (Transferencias a Propietarios)
  ├─ PK: id
  ├─ owner_id → users.id [FK]
  ├─ order_id → orders.id [FK]
  ├─ amount
  ├─ status (PENDING, IN_PROGRESS, COMPLETED, FAILED)
  ├─ recipient_account
  ├─ recipient_bank
  ├─ bank_transfer_id (ID en Pichincha)
  ├─ idempotency_key [UNIQUE]
  ├─ retry_count
  ├─ last_error
  ├─ created_at
  ├─ estimated_completion
  └─ completed_at

ledger_entries (Libro Contable Completo)
  ├─ PK: id
  ├─ order_id → orders.id [FK, nullable]
  ├─ owner_id → users.id [FK, nullable]
  ├─ payout_id → payouts.id [FK, nullable]
  ├─ type (PAYMENT_RECEIVED, COMMISSION_CHARGED, BALANCE_INCREASE, TRANSFER_INITIATED, TRANSFER_COMPLETED, TRANSFER_FAILED, ADJUSTMENT)
  ├─ amount (puede ser negativo)
  ├─ description
  ├─ reference_id (uuid externo)
  ├─ created_at
  └─ metadata (JSON)

idempotency_keys (Prevención de Duplicados)
  ├─ PK: key
  ├─ operation_type (CREATE_ORDER, CREATE_TRANSFER, etc.)
  ├─ response_payload (JSON serializado)
  ├─ created_at
  └─ expires_at (TTL: 24 horas)

webhook_logs (Auditoría de Webhooks)
  ├─ PK: id
  ├─ provider (DEUNA, PICHINCHA)
  ├─ event
  ├─ external_order_id
  ├─ status
  ├─ payload (JSON)
  ├─ received_at
  ├─ processed_at
  ├─ http_status
  └─ error_message (si aplica)
```

---

### SQL: Creación de Tablas

```sql
-- ============================================
-- Tablas de Órdenes y Pagos
-- ============================================

CREATE TABLE orders (
  id VARCHAR(50) PRIMARY KEY,
  deuna_id VARCHAR(100) NOT NULL UNIQUE,
  property_id VARCHAR(50) NOT NULL,
  guest_email VARCHAR(255) NOT NULL,
  guest_phone VARCHAR(20),
  amount DECIMAL(15, 2) NOT NULL CHECK (amount > 0),
  currency CHAR(3) DEFAULT 'USD',
  status VARCHAR(20) NOT NULL CHECK (status IN ('PENDING', 'PAID', 'FAILED', 'EXPIRED', 'CANCELLED')),
  deuna_transaction_id VARCHAR(100),
  qr_data LONGTEXT, -- BASE64 encoded PNG
  idempotency_key UUID NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  paid_at TIMESTAMP,
  expires_at TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE RESTRICT,
  INDEX idx_status (status),
  INDEX idx_guest_email (guest_email),
  INDEX idx_created_at (created_at)
);

CREATE TABLE payments (
  id UUID PRIMARY KEY,
  order_id VARCHAR(50) NOT NULL UNIQUE,
  amount DECIMAL(15, 2) NOT NULL,
  status VARCHAR(20) NOT NULL CHECK (status IN ('COMPLETED', 'FAILED', 'PENDING')),
  transaction_id VARCHAR(100) NOT NULL,
  received_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
  INDEX idx_status (status),
  INDEX idx_received_at (received_at)
);

-- ============================================
-- Tablas de Balances y Payouts
-- ============================================

CREATE TABLE owner_balances (
  owner_id VARCHAR(50) PRIMARY KEY,
  available_balance DECIMAL(15, 2) DEFAULT 0 CHECK (available_balance >= 0),
  pending_balance DECIMAL(15, 2) DEFAULT 0 CHECK (pending_balance >= 0),
  total_earnings DECIMAL(15, 2) DEFAULT 0,
  commission_paid DECIMAL(15, 2) DEFAULT 0,
  last_payout_at TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_available_balance (available_balance)
);

CREATE TABLE payouts (
  id VARCHAR(100) PRIMARY KEY, -- ID de Pichincha
  owner_id VARCHAR(50) NOT NULL,
  order_id VARCHAR(50) NOT NULL,
  amount DECIMAL(15, 2) NOT NULL CHECK (amount > 0),
  status VARCHAR(20) NOT NULL CHECK (status IN ('PENDING', 'IN_PROGRESS', 'COMPLETED', 'FAILED', 'CANCELLED')),
  
  -- Datos del beneficiario
  recipient_account VARCHAR(20) NOT NULL,
  recipient_bank VARCHAR(10) NOT NULL,
  recipient_name VARCHAR(100),
  
  -- Trazabilidad
  bank_transfer_id VARCHAR(100),
  idempotency_key UUID NOT NULL UNIQUE,
  retry_count INT DEFAULT 0,
  last_error TEXT,
  last_retry_at TIMESTAMP,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  estimated_completion TIMESTAMP,
  completed_at TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
  INDEX idx_owner_status (owner_id, status),
  INDEX idx_created_at (created_at),
  INDEX idx_completed_at (completed_at)
);

-- ============================================
-- Tabla de Ledger Contable
-- ============================================

CREATE TABLE ledger_entries (
  id UUID PRIMARY KEY,
  order_id VARCHAR(50),
  owner_id VARCHAR(50),
  payout_id VARCHAR(100),
  
  type VARCHAR(50) NOT NULL CHECK (type IN (
    'PAYMENT_RECEIVED',
    'COMMISSION_CHARGED',
    'BALANCE_INCREASE',
    'TRANSFER_INITIATED',
    'TRANSFER_COMPLETED',
    'TRANSFER_FAILED',
    'ADJUSTMENT',
    'REFUND',
    'CHARGEBACK'
  )),
  
  amount DECIMAL(15, 2) NOT NULL,
  description TEXT NOT NULL,
  reference_id VARCHAR(100),
  metadata JSON,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (payout_id) REFERENCES payouts(id) ON DELETE SET NULL,
  INDEX idx_order_id (order_id),
  INDEX idx_owner_id (owner_id),
  INDEX idx_type (type),
  INDEX idx_created_at (created_at),
  INDEX idx_payout_id (payout_id)
);

-- ============================================
-- Tabla de Claves de Idempotencia
-- ============================================

CREATE TABLE idempotency_keys (
  key UUID PRIMARY KEY,
  operation_type VARCHAR(50) NOT NULL,
  response_payload LONGTEXT NOT NULL, -- JSON serializado
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  
  INDEX idx_expires_at (expires_at),
  INDEX idx_operation_type (operation_type)
);

-- ============================================
-- Tabla de Webhooks (Auditoría)
-- ============================================

CREATE TABLE webhook_logs (
  id UUID PRIMARY KEY,
  provider VARCHAR(50) NOT NULL,
  event VARCHAR(100) NOT NULL,
  external_order_id VARCHAR(100),
  external_transfer_id VARCHAR(100),
  status VARCHAR(20),
  payload LONGTEXT NOT NULL, -- JSON completo
  
  received_at TIMESTAMP NOT NULL,
  processed_at TIMESTAMP,
  http_status INT,
  error_message TEXT,
  
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  INDEX idx_provider (provider),
  INDEX idx_received_at (received_at),
  INDEX idx_external_order_id (external_order_id),
  INDEX idx_processed_at (processed_at)
);

-- ============================================
-- Vistas Útiles para Reportes
-- ============================================

CREATE VIEW v_owner_ledger AS
SELECT 
  l.owner_id,
  l.type,
  l.amount,
  l.created_at,
  l.description,
  o.id as order_id,
  p.id as payout_id
FROM ledger_entries l
LEFT JOIN orders o ON l.order_id = o.id
LEFT JOIN payouts p ON l.payout_id = p.id
WHERE l.owner_id IS NOT NULL
ORDER BY l.created_at DESC;

CREATE VIEW v_daily_summary AS
SELECT 
  DATE(o.paid_at) as transaction_date,
  COUNT(o.id) as total_orders,
  SUM(o.amount) as gross_revenue,
  SUM(o.amount * 0.10) as platform_commission,
  SUM(o.amount * 0.90) as owner_earnings,
  COUNT(DISTINCT p.owner_id) as unique_owners
FROM orders o
LEFT JOIN properties pr ON o.property_id = pr.id
LEFT JOIN payouts p ON o.id = p.order_id
WHERE o.status = 'PAID'
GROUP BY DATE(o.paid_at)
ORDER BY transaction_date DESC;
```

---

### Índices Críticos para Performance

```sql
-- Búsquedas frecuentes
CREATE INDEX idx_orders_by_property_status ON orders(property_id, status);
CREATE INDEX idx_payouts_by_owner_status ON payouts(owner_id, status);
CREATE INDEX idx_ledger_by_owner_date ON ledger_entries(owner_id, created_at DESC);

-- Reconciliación
CREATE INDEX idx_payments_by_transaction_id ON payments(transaction_id);
CREATE INDEX idx_payouts_by_transfer_id ON payouts(bank_transfer_id);

-- Auditoría
CREATE INDEX idx_webhook_logs_by_external_order ON webhook_logs(external_order_id);
CREATE INDEX idx_idempotency_by_key ON idempotency_keys(key);

-- Limpieza de datos expirados
CREATE INDEX idx_idempotency_by_expiry ON idempotency_keys(expires_at);
```

---

## Seguridad e Idempotencia

### 1. Estrategia de Idempotencia (Prevenir Duplicados)

**El Problema:**
```
T=50s | Tu Backend: POST /pichincha/transfers (networkTimeout)
T=55s | Backend retry: POST /pichincha/transfers (misma data)
       ↓
       Pichincha recibe 2 solicitudes idénticas → Crea 2 transferencias
       → El propietario recibe dinero 2 veces
```

**Solución: Idempotency Keys**

Toda operación financiera DEBE incluir un UUID único que identifique la transacción:

```javascript
// src/services/payments/idempotency.ts
import crypto from 'crypto';
import { db } from '../../db';

export interface IdempotencyCheckResult {
  isNewRequest: boolean;
  cachedResponse?: any;
}

export async function checkIdempotency(
  key: UUID,
  operationType: string,
  ttlMinutes: number = 1440 // 24 horas
): Promise<IdempotencyCheckResult> {
  
  const existing = await db('idempotency_keys')
    .where({ key })
    .first();

  if (existing) {
    return {
      isNewRequest: false,
      cachedResponse: JSON.parse(existing.response_payload)
    };
  }

  return { isNewRequest: true };
}

export async function storeIdempotencyResult(
  key: UUID,
  operationType: string,
  response: any,
  ttlMinutes: number = 1440
): Promise<void> {
  
  const expiresAt = new Date(Date.now() + ttlMinutes * 60000);

  await db('idempotency_keys').insert({
    key,
    operation_type: operationType,
    response_payload: JSON.stringify(response),
    created_at: new Date(),
    expires_at: expiresAt
  });
}

// ============================================
// Uso en tu servicio de Transfers
// ============================================

export async function createTransferWithIdempotency(
  params: TransferRequest,
  idempotencyKey: UUID
): Promise<{ transferId: string; isRetry: boolean }> {

  // 1. Verificar si ya fue procesada
  const idempCheck = await checkIdempotency(
    idempotencyKey,
    'CREATE_TRANSFER',
    1440 // 24 horas
  );

  if (!idempCheck.isNewRequest) {
    logger.info('Idempotent retry detected', { idempotencyKey });
    return {
      transferId: idempCheck.cachedResponse.transferId,
      isRetry: true
    };
  }

  // 2. Es una solicitud nueva
  const trx = await db.transaction();

  try {
    const token = await getPichinchaBPToken();

    const response = await axios.post(
      `${process.env.PICHINCHA_API_BASE}/v1/transfers`,
      {
        idempotencyKey,
        beneficiary: { /* ... */ },
        amount: params.amount,
        // ... resto del payload
      },
      {
        headers: {
          'Authorization': `Bearer ${token}`,
          'X-Idempotency-Key': idempotencyKey
        },
        timeout: 15000
      }
    );

    // 3. Guardar resultado en BD
    const result = {
      transferId: response.data.id,
      status: response.data.status
    };

    await storeIdempotencyResult(
      idempotencyKey,
      'CREATE_TRANSFER',
      result
    );

    // 4. Registrar payout
    await trx('payouts').insert({
      id: response.data.id,
      owner_id: params.ownerId,
      order_id: params.orderId,
      amount: params.amount,
      status: 'PENDING',
      idempotency_key: idempotencyKey,
      created_at: new Date()
    });

    await trx.commit();

    return {
      transferId: result.transferId,
      isRetry: false
    };

  } catch (error) {
    await trx.rollback();

    // ⚠️ Importante: Si Pichincha responde 409 (Conflict),
    // significa que la transferencia YA existe (fue procesada)
    if (error.response?.status === 409) {
      logger.warn('Transfer already exists in Pichincha', { idempotencyKey });
      
      // Recuperar el transfer existente
      const existingPayout = await db('payouts')
        .where({ idempotency_key: idempotencyKey })
        .first();

      if (existingPayout) {
        return {
          transferId: existingPayout.id,
          isRetry: true
        };
      }
    }

    throw error;
  }
}
```

**Implementación en Endpoints:**
```javascript
// src/routes/payments.ts
router.post('/api/v1/owners/:ownerId/request-payout', async (req, res) => {
  try {
    const { amount, recipientAccount, recipientBank } = req.body;
    const idempotencyKey = req.headers['x-idempotency-key'] as UUID;

    // ⚠️ Validar que el cliente envíe idempotencyKey
    if (!idempotencyKey) {
      return res.status(400).json({
        error: 'X-Idempotency-Key header is required'
      });
    }

    const result = await createTransferWithIdempotency(
      {
        ownerId: req.params.ownerId,
        amount,
        recipientAccountNumber: recipientAccount,
        recipientBankCode: recipientBank,
        // ...
      },
      idempotencyKey
    );

    // Si es un retry, incluir header indicando que fue idempotente
    if (result.isRetry) {
      res.set('X-Idempotency-Replay', 'true');
    }

    res.status(200).json({
      transferId: result.transferId,
      isIdempotentReplay: result.isRetry
    });

  } catch (error) {
    // ... manejo de errores
  }
});
```

---

### 2. Validación de Firmas HMAC en Webhooks

**El Problema:**
```
Un hacker envía POST /webhooks/deuna/payment-confirmed
con payload:
{
  "orderId": "RES-xyz",
  "status": "COMPLETED",
  "amount": 10000.00
}
↓
Tu plataforma acredita $10,000 falsamente al propietario
```

**Solución: Validar HMAC-SHA256**

Deuna (y Pichincha) firman sus webhooks con HMAC usando su secret:

```javascript
// src/webhooks/deuna-payment.ts
import crypto from 'crypto';

// Middleware que captura el raw body ANTES de JSON parsing
export function captureRawBody(req: any, res: any, buf: Buffer, encoding: string) {
  if (req.path === '/webhooks/deuna/payment-confirmed') {
    req.rawBody = buf.toString(encoding || 'utf8');
  }
}

export function validateDeunaWebhookSignature(
  rawBody: string,
  signature: string
): boolean {
  
  // El secret es proporcionado por Deuna en su panel
  const secret = process.env.DEUNA_WEBHOOK_SECRET;
  
  // Deuna envía: X-Deuna-Signature: sha256={HEX}
  // El formato es: sha256=<HMAC-SHA256-HEX>
  const [algorithm, hash] = signature.split('=');

  if (algorithm !== 'sha256') {
    logger.warn('Unknown signature algorithm', { algorithm });
    return false;
  }

  // Calcular HMAC esperado
  const expectedHash = crypto
    .createHmac('sha256', secret)
    .update(rawBody)
    .digest('hex');

  // Usar timingSafeEqual para prevenir timing attacks
  return crypto.timingSafeEqual(
    Buffer.from(hash),
    Buffer.from(expectedHash)
  );
}

// ============================================
// Uso en Express
// ============================================

app.use(express.json({ verify: captureRawBody }));

app.post('/webhooks/deuna/payment-confirmed', async (req, res) => {
  try {
    const signature = req.headers['x-deuna-signature'] as string;
    const rawBody = req.rawBody;

    // 1. Validar firma
    if (!validateDeunaWebhookSignature(rawBody, signature)) {
      logger.error('Invalid webhook signature', {
        path: req.path,
        signature: signature?.substring(0, 20) + '...'
      });
      return res.status(401).json({ error: 'Unauthorized' });
    }

    // 2. Procesar webhook (ya validado)
    const { data } = req.body;
    await processPaymentConfirmation(data);

    res.status(200).json({ acknowledged: true });

  } catch (error) {
    logger.error('Webhook error', error);
    res.status(500).json({ error: 'Processing error' });
  }
});
```

**Test unitario:**
```javascript
// test/webhooks.test.ts
import crypto from 'crypto';

describe('Webhook Signature Validation', () => {
  it('should validate correct HMAC signature', () => {
    const secret = 'test-secret';
    const payload = JSON.stringify({ status: 'COMPLETED' });

    const hmac = crypto
      .createHmac('sha256', secret)
      .update(payload)
      .digest('hex');

    const signature = `sha256=${hmac}`;

    const isValid = validateDeunaWebhookSignature(payload, signature);
    expect(isValid).toBe(true);
  });

  it('should reject tampered payload', () => {
    const secret = 'test-secret';
    const payload = JSON.stringify({ status: 'COMPLETED' });
    
    const hmac = crypto
      .createHmac('sha256', secret)
      .update(payload)
      .digest('hex');

    const signature = `sha256=${hmac}`;
    const tamperedPayload = JSON.stringify({ status: 'FAILED', amount: 99999 });

    const isValid = validateDeunaWebhookSignature(tamperedPayload, signature);
    expect(isValid).toBe(false);
  });

  it('should reject with wrong secret', () => {
    const secret = 'correct-secret';
    const wrongSecret = 'wrong-secret';
    const payload = JSON.stringify({ status: 'COMPLETED' });

    const hmac = crypto
      .createHmac('sha256', secret)
      .update(payload)
      .digest('hex');

    const signature = `sha256=${hmac}`;

    // Simular validación con secret incorrecto
    const expectedHmac = crypto
      .createHmac('sha256', wrongSecret)
      .update(payload)
      .digest('hex');

    expect(hmac).not.toBe(expectedHmac);
  });
});
```

---

### 3. Replay Attack Prevention

**El Problema:**
```
Hacker captura un webhook exitoso:
POST /webhooks/deuna/payment-confirmed
{ orderId: "xyz", status: "COMPLETED" }

Lo reenvia 100 veces → Se acredita el pago 100 veces
```

**Solución: Validar Timestamp y Nonce**

```javascript
export function validateDeunaWebhookFreshness(
  timestamp: string,
  maxAgeSeconds: number = 300 // 5 minutos
): boolean {
  
  const webhookTime = new Date(timestamp).getTime();
  const now = Date.now();
  const ageSeconds = (now - webhookTime) / 1000;

  if (ageSeconds > maxAgeSeconds || ageSeconds < -30) {
    logger.warn('Webhook timestamp out of range', {
      timestamp,
      ageSeconds,
      maxAge: maxAgeSeconds
    });
    return false;
  }

  return true;
}

// Usar UUID en webhook para prevenir duplicados
app.post('/webhooks/deuna/payment-confirmed', async (req, res) => {
  try {
    const { id: webhookId, timestamp, data } = req.body;

    // 1. Validar firma
    const signature = req.headers['x-deuna-signature'] as string;
    if (!validateDeunaWebhookSignature(req.rawBody, signature)) {
      return res.status(401).json({ error: 'Unauthorized' });
    }

    // 2. Validar freshness
    if (!validateDeunaWebhookFreshness(timestamp)) {
      return res.status(400).json({ error: 'Timestamp too old' });
    }

    // 3. Verificar que no fue procesado antes
    const existing = await db('webhook_logs')
      .where({ webhook_id: webhookId })
      .first();

    if (existing && existing.processed_at) {
      logger.info('Duplicate webhook, skipping', { webhookId });
      return res.status(200).json({ acknowledged: true }); // Idempotente
    }

    // 4. Procesar
    await processPaymentConfirmation(data);

    // 5. Marcar como procesado
    if (existing) {
      await db('webhook_logs')
        .where({ webhook_id: webhookId })
        .update({ processed_at: new Date() });
    }

    res.status(200).json({ acknowledged: true });

  } catch (error) {
    logger.error('Webhook error', error);
    res.status(500).json({ error: 'Processing error' });
  }
});
```

---

### 4. Protección de Secretos

```bash
# .env (NO commitar a Git)
DEUNA_CLIENT_ID=client_prod_xxxx
DEUNA_CLIENT_SECRET=secret_prod_xxxx
DEUNA_WEBHOOK_SECRET=webhook_secret_xxxx

PICHINCHA_CLIENT_ID=bp_prod_yyyy
PICHINCHA_CLIENT_SECRET=bp_secret_yyyy
PICHINCHA_API_BASE=https://api.pichincha.com

DATABASE_URL=postgresql://...
VAULT_TOKEN=xxx
```

**Usar Vault para secretos:**
```javascript
// src/config/vault.ts
import VaultClient from 'node-vault';

const vault = new VaultClient({
  endpoint: process.env.VAULT_ADDR,
  token: process.env.VAULT_TOKEN
});

export async function getSecrets() {
  const secrets = await vault.read('secret/data/payments/integrations');
  return {
    deunaClientId: secrets.data.data.deuna_client_id,
    deunaClientSecret: secrets.data.data.deuna_client_secret,
    pichinchaBPClientId: secrets.data.data.pichincha_client_id,
    // ...
  };
}
```

---

## Manejo de Reintentos y Colas

### Arquitectura de Colas con RabbitMQ/AWS SQS

**Flujo:**
```
Webhook Deuna (Pago confirmado)
  ↓
  Guardar en DB (transacción)
  ↓
  Publicar evento en Queue: "payment.confirmed"
  ↓
  Responder inmediatamente (200 OK) a Deuna
  ↓
  Consumer (background worker) procesa
  ├─ Calcular comisión
  ├─ Actualizar balances
  ├─ Enqueue: "payout.pending"
  ↓
  Payout Consumer
  ├─ Validar elegibilidad
  ├─ Crear transfer en Pichincha
  ├─ Guardar result
  ├─ Si falla: re-enqueue con delay
```

**Implementación con Bull (Node.js):**

```javascript
// src/queues/index.ts
import Queue from 'bull';
import redis from 'redis';

const redisClient = redis.createClient({
  host: process.env.REDIS_HOST,
  port: parseInt(process.env.REDIS_PORT || '6379')
});

// ============================================
// Colas
// ============================================

export const paymentConfirmedQueue = new Queue('payment.confirmed', {
  redis: { client: redisClient },
  defaultJobOptions: {
    attempts: 3,
    backoff: {
      type: 'exponential',
      delay: 2000 // Inicia con 2s, luego 4s, 8s
    },
    removeOnComplete: true,
    removeOnFail: false
  }
});

export const payoutPendingQueue = new Queue('payout.pending', {
  redis: { client: redisClient },
  defaultJobOptions: {
    attempts: 5,
    backoff: {
      type: 'exponential',
      delay: 5000
    },
    removeOnComplete: true,
    removeOnFail: false
  }
});

export const payoutRetryQueue = new Queue('payout.retry', {
  redis: { client: redisClient },
  defaultJobOptions: {
    attempts: 10,
    backoff: {
      type: 'exponential',
      delay: 30000 // Esperar 30s, 60s, 120s, ...
    },
    removeOnComplete: true,
    removeOnFail: true
  }
});

// ============================================
// Processadores
// ============================================

paymentConfirmedQueue.process(
  1, // Concurrencia: procesar 1 a la vez
  async (job) => {
    const { orderId, amount } = job.data;

    try {
      await processPaymentConfirmed(orderId, amount);
      return { success: true };
    } catch (error) {
      logger.error('Payment processing failed', { orderId, error });
      throw error; // Bull reintentará automáticamente
    }
  }
);

payoutPendingQueue.process(2, async (job) => {
  const { payoutId, ownerId, amount } = job.data;

  try {
    const result = await createTransferInPickincha(payoutId, ownerId, amount);
    return { success: true, transferId: result.transferId };
  } catch (error) {
    // Si es error temporal (timeout, rate limit): reintentará
    // Si es error permanente: loguear y descartar
    if (error.isTemporary) {
      throw error;
    } else {
      logger.error('Permanent payout error', { payoutId, error });
      // Actualizar estado a FAILED en BD
      await db('payouts')
        .where({ id: payoutId })
        .update({ status: 'FAILED', last_error: error.message });
      
      return { success: false, reason: error.message };
    }
  }
});

payoutRetryQueue.process(1, async (job) => {
  const { payoutId, retryReason } = job.data;
  
  try {
    const payout = await db('payouts').where({ id: payoutId }).first();
    const result = await createTransferInPickincha(
      payoutId,
      payout.owner_id,
      payout.amount
    );
    return { success: true };
  } catch (error) {
    logger.warn('Payout retry failed', { payoutId, attempt: job.attemptsMade });
    throw error;
  }
});

// ============================================
// Event Listeners para Monitoring
// ============================================

paymentConfirmedQueue.on('failed', (job, err) => {
  logger.error('Payment job failed after retries', {
    jobId: job.id,
    orderId: job.data.orderId,
    attempts: job.attemptsMade,
    error: err.message
  });

  // Enviar alerta (Slack, PagerDuty, etc.)
  alerting.sendAlert({
    severity: 'high',
    message: `Payment processing failed: ${job.data.orderId}`,
    context: job.data
  });
});

payoutPendingQueue.on('failed', (job, err) => {
  logger.error('Payout job failed after retries', {
    jobId: job.id,
    payoutId: job.data.payoutId,
    attempts: job.attemptsMade
  });

  // Mueve a cola de retry con delay más largo
  payoutRetryQueue.add(
    {
      payoutId: job.data.payoutId,
      retryReason: 'Max attempts exceeded, retrying in batch'
    },
    { delay: 300000 } // Reintentar en 5 minutos
  );
});

// ============================================
// Enqueue desde Webhook
// ============================================

export async function enqueuedPaymentConfirmed(
  orderId: string,
  amount: number
): Promise<void> {
  await paymentConfirmedQueue.add(
    { orderId, amount },
    { jobId: `payment-${orderId}` } // Evita duplicados
  );
}

export async function enqueuPayoutPending(
  payoutId: string,
  ownerId: string,
  amount: number
): Promise<void> {
  await payoutPendingQueue.add(
    { payoutId, ownerId, amount },
    { jobId: `payout-${payoutId}` }
  );
}
```

**Procesador de pago confirmado:**
```javascript
// src/services/payments/process-payment.ts
export async function processPaymentConfirmed(
  orderId: string,
  amount: number
): Promise<void> {

  const trx = await db.transaction();

  try {
    // 1. Obtener orden
    const order = await trx('orders')
      .where({ deuna_id: orderId })
      .first();

    if (!order) {
      throw new Error(`Order not found: ${orderId}`);
    }

    // 2. Verificar si ya fue procesada
    const existing = await trx('payments')
      .where({ order_id: order.id })
      .first();

    if (existing) {
      logger.info('Payment already processed', { orderId: order.id });
      await trx.rollback();
      return;
    }

    // 3. Actualizar estado de orden
    await trx('orders')
      .where({ id: order.id })
      .update({ status: 'PAID', paid_at: new Date() });

    // 4. Crear registro de pago
    await trx('payments').insert({
      id: crypto.randomUUID(),
      order_id: order.id,
      amount,
      status: 'COMPLETED',
      received_at: new Date()
    });

    // 5. Calcular comisión y actualizar balance
    const commission = amount * 0.10;
    const netAmount = amount - commission;

    const property = await trx('properties')
      .where({ id: order.property_id })
      .first();

    const ownerId = property.owner_id;

    await trx('owner_balances')
      .where({ owner_id: ownerId })
      .increment('available_balance', netAmount);

    // 6. Crear entradas de ledger
    await trx('ledger_entries').insert([
      {
        id: crypto.randomUUID(),
        order_id: order.id,
        owner_id: ownerId,
        type: 'PAYMENT_RECEIVED',
        amount,
        description: `Pago por reserva ${order.id}`
      },
      {
        id: crypto.randomUUID(),
        order_id: order.id,
        owner_id: null,
        type: 'COMMISSION_CHARGED',
        amount: -commission,
        description: 'Comisión plataforma 10%'
      },
      {
        id: crypto.randomUUID(),
        order_id: order.id,
        owner_id: ownerId,
        type: 'BALANCE_INCREASE',
        amount: netAmount,
        description: 'Saldo disponible para retiro'
      }
    ]);

    // 7. Crear payout request
    const payoutId = crypto.randomUUID();
    
    await trx('payouts').insert({
      id: payoutId,
      owner_id: ownerId,
      order_id: order.id,
      amount: netAmount,
      status: 'PENDING',
      idempotency_key: crypto.randomUUID(),
      created_at: new Date()
    });

    await trx.commit();

    // 8. Enqueue payout (DESPUÉS de commit)
    await enqueuPayoutPending(payoutId, ownerId, netAmount);

  } catch (error) {
    await trx.rollback();
    throw error;
  }
}
```

**Dashboard de monitoreo:**
```javascript
// src/api/routes/admin/queues.ts
router.get('/admin/queues/stats', async (req, res) => {
  const stats = {
    paymentConfirmed: {
      waiting: await paymentConfirmedQueue.getWaitingCount(),
      active: await paymentConfirmedQueue.getActiveCount(),
      delayed: await paymentConfirmedQueue.getDelayedCount(),
      failed: await paymentConfirmedQueue.getFailedCount()
    },
    payoutPending: {
      waiting: await payoutPendingQueue.getWaitingCount(),
      active: await payoutPendingQueue.getActiveCount(),
      delayed: await payoutPendingQueue.getDelayedCount(),
      failed: await payoutPendingQueue.getFailedCount()
    }
  };

  res.json(stats);
});
```

---

## Casos de Borde y Excepciones

### 1. Pago Confirmado pero Transfer Falla

**Escenario:**
```
T=50s | Webhook Deuna: pago COMPLETED
       | Balance propietario: +$900
       |
T=55s | Intento transfer a Pichincha: ERROR (timeout)
       | El propietario ve $900 disponibles
       | pero los fondos no llegaron a su cuenta
```

**Solución:**
```javascript
// src/services/payouts/monitor.ts
export async function reconcilePayouts(): Promise<void> {
  // Ejecutar cada 5 minutos como cron job
  
  const pendingPayouts = await db('payouts')
    .where({ status: 'PENDING' })
    .andWhere('created_at', '<', db.raw("NOW() - INTERVAL '10 minutes'"));

  for (const payout of pendingPayouts) {
    try {
      // Consultar estado en Pichincha
      const status = await getTransferStatus(payout.bank_transfer_id);

      if (status === 'COMPLETED') {
        // Transfer fue exitoso, actualizar DB
        await db('payouts')
          .where({ id: payout.id })
          .update({ status: 'COMPLETED', completed_at: new Date() });

      } else if (status === 'FAILED') {
        // Transfer falló, restaurar balance
        await restoreOwnerBalance(payout.owner_id, payout.amount);
        
        await db('payouts')
          .where({ id: payout.id })
          .update({ status: 'FAILED' });

      } else if (status === 'IN_PROGRESS') {
        // Esperar más
        logger.info('Transfer still in progress', { payoutId: payout.id });
      }

    } catch (error) {
      logger.error('Reconciliation error', { payoutId: payout.id, error });
      // Re-enqueue para próximo ciclo
    }
  }
}

async function restoreOwnerBalance(ownerId: string, amount: number): Promise<void> {
  const trx = await db.transaction();

  try {
    await trx('owner_balances')
      .where({ owner_id: ownerId })
      .increment('available_balance', amount);

    await trx('ledger_entries').insert({
      id: crypto.randomUUID(),
      owner_id: ownerId,
      type: 'ADJUSTMENT',
      amount,
      description: 'Balance restaurado (transfer fallido)',
      created_at: new Date()
    });

    await trx.commit();
  } catch (error) {
    await trx.rollback();
    throw error;
  }
}

// Registrar cron job
cron.schedule('*/5 * * * *', async () => {
  try {
    await reconcilePayouts();
  } catch (error) {
    logger.error('Reconciliation cron failed', error);
  }
});
```

---

### 2. Doble Pago (Webhook duplicado de Deuna)

**Escenario:**
```
Deuna envía webhook 2 veces por un mismo pago
→ Sin protección: se acredita 2x al propietario
```

**Solución: Webhook Idempotencia**
```javascript
// Ya implementado arriba con webhook_logs
// Deuna asigna event ID único a cada webhook

app.post('/webhooks/deuna/payment-confirmed', async (req, res) => {
  const { id: webhookEventId, data } = req.body;

  // Verificar si ya fue procesado
  const existing = await db('webhook_logs')
    .where({ webhook_event_id: webhookEventId })
    .first();

  if (existing && existing.processed_at) {
    logger.info('Duplicate webhook ignored', { webhookEventId });
    return res.status(200).json({ acknowledged: true });
  }

  // Procesar normalmente...
});
```

---

### 3. Propietario sin Cuenta Bancaria Configurada

**Escenario:**
```
Pago completado, balance disponible
Pero propietario no ha ingresado sus datos bancarios
→ No se puede crear transfer
```

**Solución:**
```javascript
// src/services/payouts/eligibility.ts
export async function isPayoutEligible(ownerId: string): Promise<{
  eligible: boolean;
  reason?: string;
}> {
  
  const owner = await db('users').where({ id: ownerId }).first();
  
  if (!owner.bank_account_number) {
    return { 
      eligible: false, 
      reason: 'Owner has not configured bank account' 
    };
  }

  if (!owner.bank_account_verified) {
    return { 
      eligible: false, 
      reason: 'Bank account not verified' 
    };
  }

  const balance = await db('owner_balances')
    .where({ owner_id: ownerId })
    .first();

  if (balance.available_balance < 10.00) {
    return { 
      eligible: false, 
      reason: 'Balance below minimum ($10)' 
    };
  }

  return { eligible: true };
}

// Usar en processor:
export async function processPaymentConfirmed(...) {
  // ... código previo ...

  // 7. Crear payout request solo si es elegible
  const eligibility = await isPayoutEligible(ownerId);
  
  if (eligibility.eligible) {
    // Enqueue payout
    await enqueuPayoutPending(payoutId, ownerId, netAmount);
  } else {
    // Marcar como PENDING_VERIFICATION o similar
    await trx('payouts')
      .where({ id: payoutId })
      .update({ status: 'WAITING_BANK_INFO', reason: eligibility.reason });

    // Enviar notificación al propietario
    await sendNotification(ownerId, {
      type: 'PAYOUT_WAITING_BANK_INFO',
      message: 'Complete your bank information to receive your earnings'
    });
  }
}
```

---

### 4. Rate Limiting de Pichincha

**Escenario:**
```
Tu plataforma intenta 1000 transfers en 1 minuto
Pichincha: "429 Too Many Requests"
→ Colas de payout se acumulan
```

**Solución: Circuit Breaker + Backoff**
```javascript
// src/services/banco-pichincha/circuit-breaker.ts
import CircuitBreaker from 'opossum';

const pichinchaBreakerOptions = {
  timeout: 30000,
  errorThresholdPercentage: 50,
  resetTimeout: 300000, // Reset después de 5 minutos
  name: 'pichincha-transfers'
};

const createTransferBreaker = new CircuitBreaker(
  async (params) => createTransferWithIdempotency(params),
  pichinchaBreakerOptions
);

createTransferBreaker.on('open', () => {
  logger.warn('Pichincha circuit breaker opened (too many failures)');
  // Todas las siguientes llamadas fallarán inmediatamente
});

createTransferBreaker.on('halfOpen', () => {
  logger.info('Pichincha circuit breaker testing recovery...');
});

createTransferBreaker.on('close', () => {
  logger.info('Pichincha circuit breaker closed (recovered)');
});

export async function safeCreateTransfer(params: any) {
  try {
    return await createTransferBreaker.fire(params);
  } catch (error) {
    if (error.message.includes('circuit breaker is open')) {
      // Circuit abierto: descartar o diferir
      logger.warn('Circuit breaker open, requeuing payout');
      // Re-enqueue con delay más largo
      await payoutRetryQueue.add(params, { delay: 600000 });
      throw new TemporaryError('Pichincha temporarily unavailable');
    }
    throw error;
  }
}
```

---

### 5. Conciliación Fallida (Datos Inconsistentes)

**Escenario:**
```
BD: order status = PAID, balance = +$900
Deuna: order status = PENDING
Pichincha: no existe transfer con ese ID
→ Estado inconsistente
```

**Solución: Auditoría y Reconciliación Automática**
```javascript
// src/jobs/daily-reconciliation.ts
export async function dailyReconciliation(): Promise<void> {
  logger.info('Starting daily reconciliation...');

  const trx = await db.transaction();

  try {
    // 1. Verificar órdenes pagadas sin pago registrado
    const orphanedOrders = await trx('orders')
      .where({ status: 'PAID' })
      .whereNotExists(
        trx('payments').whereRaw('orders.id = payments.order_id')
      );

    if (orphanedOrders.length > 0) {
      logger.warn('Found orphaned paid orders', { count: orphanedOrders.length });
      alerting.sendAlert({
        severity: 'critical',
        message: `${orphanedOrders.length} orders paid but no payment records`
      });
    }

    // 2. Verificar payouts sin transfer ID
    const orphanedPayouts = await trx('payouts')
      .where({ status: 'IN_PROGRESS' })
      .whereNull('bank_transfer_id')
      .andWhere('created_at', '<', db.raw("NOW() - INTERVAL '1 hour'"));

    for (const payout of orphanedPayouts) {
      logger.error('Payout without transfer ID', { payoutId: payout.id });
      // Intentar recrear transfer
      await createTransferWithIdempotency({
        ownerId: payout.owner_id,
        amount: payout.amount,
        // ...
      }, payout.idempotency_key);
    }

    // 3. Comparar totales de ledger con balances
    const ledgerTotals = await trx('ledger_entries')
      .where({ owner_id: 'owner-123' })
      .sum('amount as total');

    const balance = await trx('owner_balances')
      .where({ owner_id: 'owner-123' })
      .first();

    if (ledgerTotals.total !== balance.available_balance) {
      logger.error('Balance mismatch detected', {
        ownerId: 'owner-123',
        ledgerTotal: ledgerTotals.total,
        balance: balance.available_balance
      });
      // Manual review required
    }

    await trx.commit();
    logger.info('Reconciliation completed');

  } catch (error) {
    await trx.rollback();
    logger.error('Reconciliation failed', error);
    throw error;
  }
}

// Ejecutar diariamente a las 3 AM
cron.schedule('0 3 * * *', async () => {
  try {
    await dailyReconciliation();
  } catch (error) {
    alerting.sendAlert({
      severity: 'critical',
      message: 'Daily reconciliation failed',
      error: error.message
    });
  }
});
```

---

### 6. Webhook Timeout (Deuna espera respuesta > 30s)

**Escenario:**
```
Tu webhook tarda 45 segundos (porque BD está lenta)
Deuna timeout → Reintenta 3 veces → Falla permanentemente
→ Pago no se registra
```

**Solución: Responder Inmediatamente**
```javascript
app.post('/webhooks/deuna/payment-confirmed', async (req, res) => {
  const { data } = req.body;

  // 1. Guardar en BD de forma RÁPIDA
  await db('webhook_logs').insert({
    id: crypto.randomUUID(),
    provider: 'DEUNA',
    event: 'payment.completed',
    payload: JSON.stringify(data),
    received_at: new Date(),
    processed_at: null // Aún no procesado
  });

  // 2. RESPONDER INMEDIATAMENTE (< 100ms)
  res.status(200).json({ acknowledged: true });

  // 3. Procesar ASINCRONAMENTE (enqueue)
  try {
    await enqueuedPaymentConfirmed(data.orderId, data.amount);
  } catch (error) {
    logger.error('Failed to enqueue', error);
    // Ya respondimos al webhook, pero encolado falló
    // El cron de reconciliación lo detectará
  }
});
```

---

## Monitoreo y Auditoría

### Métricas Clave

```javascript
// src/monitoring/metrics.ts
import prometheus from 'prom-client';

export const metrics = {
  // Órdenes
  ordersCreated: new prometheus.Counter({
    name: 'orders_created_total',
    help: 'Total orders created',
    labelNames: ['status']
  }),

  ordersProcessingDuration: new prometheus.Histogram({
    name: 'orders_processing_duration_seconds',
    help: 'Time to process order payment',
    buckets: [1, 5, 10, 30, 60]
  }),

  // Pagos
  paymentsReceived: new prometheus.Counter({
    name: 'payments_received_total',
    help: 'Total payments received',
    labelNames: ['currency']
  }),

  paymentAmount: new prometheus.Gauge({
    name: 'payment_amount_total',
    help: 'Total amount received in payments',
    labelNames: ['currency']
  }),

  // Payouts
  payoutsCreated: new prometheus.Counter({
    name: 'payouts_created_total',
    help: 'Total payouts created',
    labelNames: ['bank']
  }),

  payoutsCompleted: new prometheus.Counter({
    name: 'payouts_completed_total',
    help: 'Total payouts completed successfully',
    labelNames: ['bank']
  }),

  payoutsDuration: new prometheus.Histogram({
    name: 'payouts_duration_seconds',
    help: 'Time from payout creation to completion',
    buckets: [30, 300, 900, 1800, 3600]
  }),

  // Colas
  queueLength: new prometheus.Gauge({
    name: 'queue_length',
    help: 'Current queue length',
    labelNames: ['queue_name', 'status']
  }),

  // Webhooks
  webhooksReceived: new prometheus.Counter({
    name: 'webhooks_received_total',
    help: 'Total webhooks received',
    labelNames: ['provider', 'event']
  }),

  webhookProcessingDuration: new prometheus.Histogram({
    name: 'webhook_processing_duration_seconds',
    help: 'Time to process webhook',
    buckets: [0.1, 0.5, 1, 5, 10]
  })
};

// Uso
metrics.ordersCreated.inc({ status: 'PENDING' });
const end = metrics.ordersProcessingDuration.startTimer();
// ... procesar ...
end();
```

### Logging Estructurado

```javascript
// src/logger/index.ts
import winston from 'winston';
import { v4 as uuid } from 'uuid';

const logger = winston.createLogger({
  level: process.env.LOG_LEVEL || 'info',
  format: winston.format.json(),
  defaultMeta: { service: 'payments-service' },
  transports: [
    new winston.transports.File({ 
      filename: 'error.log', 
      level: 'error',
      maxsize: 5242880, // 5MB
      maxFiles: 5
    }),
    new winston.transports.File({ filename: 'combined.log' })
  ]
});

// En ambiente de desarrollo
if (process.env.NODE_ENV !== 'production') {
  logger.add(new winston.transports.Console({
    format: winston.format.simple()
  }));
}

// Uso con correlationId
export function logWithContext(context: any, message: string, data?: any) {
  logger.info(message, {
    correlationId: context.correlationId,
    userId: context.userId,
    orderId: context.orderId,
    ...data
  });
}

// Middleware Express
app.use((req, res, next) => {
  req.correlationId = req.headers['x-correlation-id'] || uuid();
  next();
});
```

### Alertas

```javascript
// src/alerting/index.ts
import slack from '@slack/web-api';
import pagerduty from 'pagerduty';

const slackClient = new slack.WebClient(process.env.SLACK_BOT_TOKEN);
const pdClient = new pagerduty.RestClient({
  token: process.env.PAGERDUTY_TOKEN
});

export async function sendAlert(alert: {
  severity: 'low' | 'medium' | 'high' | 'critical';
  title: string;
  message: string;
  context?: any;
}) {
  const color = {
    low: '#36a64f',
    medium: '#ffa500',
    high: '#ff6600',
    critical: '#ff0000'
  }[alert.severity];

  // Slack
  if (alert.severity !== 'low') {
    await slackClient.chat.postMessage({
      channel: process.env.ALERTS_CHANNEL,
      blocks: [
        {
          type: 'header',
          text: {
            type: 'plain_text',
            text: `🚨 ${alert.title}`
          }
        },
        {
          type: 'section',
          text: {
            type: 'mrkdwn',
            text: alert.message
          }
        },
        ...(alert.context ? [{
          type: 'section',
          text: {
            type: 'mrkdwn',
            text: '```' + JSON.stringify(alert.context, null, 2) + '```'
          }
        }] : [])
      ]
    });
  }

  // PagerDuty (solo para critical)
  if (alert.severity === 'critical') {
    await pdClient.incidents.create({
      incident: {
        type: 'incident_reference',
        title: alert.title,
        body: {
          type: 'incident_body',
          details: JSON.stringify(alert.context)
        },
        urgency: 'high'
      }
    });
  }
}
```

---

## Resumen de Puntos Clave

| Componente | Implementación |
|-----------|----------------|
| **OAuth 2.0** | Cache tokens (55 min), reintentos exponenciales |
| **Órdenes Deuna** | Idempotency Key único por orden |
| **Webhooks** | Validación HMAC-SHA256, deduplicación con event ID |
| **Ledger** | Transacciones ACID, 5 NF |
| **Transfers** | Idempotency Key, Circuit Breaker, reconciliación cron |
| **Colas** | Bull + Redis, reintentos exponenciales (5-10 intentos) |
| **Seguridad** | Secretos en Vault, rate limiting, logging estructurado |
| **Monitoreo** | Prometheus + Grafana, alertas Slack/PagerDuty |

---

**Documento de Referencia:**
Este documento describe la arquitectura para un marketplace fintech con 2 integradores clave. Para producción:

1. ✅ Validar endpoint URLs exactas con Deuna y Pichincha
2. ✅ Implementar unit tests para cada servicio
3. ✅ Load testing de colas (Bull, Redis)
4. ✅ Disaster recovery y failover procedures
5. ✅ Compliance: PCI-DSS, GDPR, normativa ECU

