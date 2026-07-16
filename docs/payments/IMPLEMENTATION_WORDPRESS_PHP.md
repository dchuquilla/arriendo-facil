# Guía de Implementación: Pagos en WordPress/PHP
## Deuna + Banco Pichincha - Plugin Architecture

---

## Fase 1: Setup Base del Plugin (Semana 1)

### 1.1 Crear Estructura de Directorios

```
includes/payments/
├── class-deuna-auth.php
├── class-deuna-orders.php
├── class-pichincha-transfers.php
├── class-payment-processor.php
├── class-deuna-webhook-handler.php
├── class-pichincha-webhook-handler.php
├── class-ledger-manager.php
├── class-payment-validator.php
└── interface-payment-provider.php
```

### 1.2 Crear Tablas en Base de Datos

**Archivo:** `includes/class-activator.php`

```php
<?php
class Arriendo_Facil_Activator {
  public static function activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Tabla: Órdenes Deuna
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_orders (
      id VARCHAR(50) PRIMARY KEY,
      deuna_id VARCHAR(100) UNIQUE NOT NULL,
      property_id VARCHAR(50) NOT NULL,
      guest_email VARCHAR(255) NOT NULL,
      guest_phone VARCHAR(20),
      amount DECIMAL(15, 2) NOT NULL,
      currency VARCHAR(3) DEFAULT 'USD',
      status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
      deuna_transaction_id VARCHAR(100),
      qr_data LONGTEXT,
      idempotency_key VARCHAR(36) UNIQUE NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      paid_at DATETIME,
      expires_at DATETIME,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      
      KEY idx_status (status),
      KEY idx_guest_email (guest_email),
      KEY idx_created_at (created_at),
      CONSTRAINT fk_property_id FOREIGN KEY (property_id) REFERENCES {$wpdb->posts}(ID)
    ) $charset_collate;");

    // Tabla: Pagos Confirmados
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_payments (
      id VARCHAR(36) PRIMARY KEY,
      order_id VARCHAR(50) UNIQUE NOT NULL,
      amount DECIMAL(15, 2) NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'COMPLETED',
      transaction_id VARCHAR(100) NOT NULL,
      received_at DATETIME NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      
      KEY idx_status (status),
      KEY idx_received_at (received_at),
      CONSTRAINT fk_order_id FOREIGN KEY (order_id) REFERENCES {$wpdb->prefix}af_orders(id)
    ) $charset_collate;");

    // Tabla: Balances de Propietarios
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_owner_balances (
      owner_id BIGINT PRIMARY KEY,
      available_balance DECIMAL(15, 2) DEFAULT 0,
      pending_balance DECIMAL(15, 2) DEFAULT 0,
      total_earnings DECIMAL(15, 2) DEFAULT 0,
      commission_paid DECIMAL(15, 2) DEFAULT 0,
      last_payout_at DATETIME,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      
      CONSTRAINT fk_owner_id FOREIGN KEY (owner_id) REFERENCES {$wpdb->users}(ID)
    ) $charset_collate;");

    // Tabla: Payouts (Transferencias a Propietarios)
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_payouts (
      id VARCHAR(100) PRIMARY KEY,
      owner_id BIGINT NOT NULL,
      order_id VARCHAR(50) NOT NULL,
      amount DECIMAL(15, 2) NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
      
      recipient_account VARCHAR(20) NOT NULL,
      recipient_bank VARCHAR(10) NOT NULL,
      recipient_name VARCHAR(100),
      
      bank_transfer_id VARCHAR(100),
      idempotency_key VARCHAR(36) UNIQUE NOT NULL,
      retry_count INT DEFAULT 0,
      last_error TEXT,
      last_retry_at DATETIME,
      
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      estimated_completion DATETIME,
      completed_at DATETIME,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      
      KEY idx_owner_status (owner_id, status),
      KEY idx_created_at (created_at),
      KEY idx_completed_at (completed_at),
      CONSTRAINT fk_payout_owner FOREIGN KEY (owner_id) REFERENCES {$wpdb->users}(ID),
      CONSTRAINT fk_payout_order FOREIGN KEY (order_id) REFERENCES {$wpdb->prefix}af_orders(id)
    ) $charset_collate;");

    // Tabla: Ledger Contable
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_ledger_entries (
      id VARCHAR(36) PRIMARY KEY,
      order_id VARCHAR(50),
      owner_id BIGINT,
      payout_id VARCHAR(100),
      
      type VARCHAR(50) NOT NULL,
      amount DECIMAL(15, 2) NOT NULL,
      description TEXT NOT NULL,
      reference_id VARCHAR(100),
      metadata JSON,
      
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      
      KEY idx_order_id (order_id),
      KEY idx_owner_id (owner_id),
      KEY idx_type (type),
      KEY idx_created_at (created_at),
      CONSTRAINT fk_ledger_order FOREIGN KEY (order_id) REFERENCES {$wpdb->prefix}af_orders(id),
      CONSTRAINT fk_ledger_owner FOREIGN KEY (owner_id) REFERENCES {$wpdb->users}(ID),
      CONSTRAINT fk_ledger_payout FOREIGN KEY (payout_id) REFERENCES {$wpdb->prefix}af_payouts(id)
    ) $charset_collate;");

    // Tabla: Idempotency Keys (Ya existe probablemente)
    // Si no:
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_idempotency_keys (
      key_hash VARCHAR(36) PRIMARY KEY,
      operation_type VARCHAR(50) NOT NULL,
      response_payload LONGTEXT NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME NOT NULL,
      
      KEY idx_expires_at (expires_at),
      KEY idx_operation_type (operation_type)
    ) $charset_collate;");

    // Tabla: Webhook Logs (Auditoría)
    $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}af_webhook_logs (
      id VARCHAR(36) PRIMARY KEY,
      provider VARCHAR(50) NOT NULL,
      event VARCHAR(100) NOT NULL,
      external_order_id VARCHAR(100),
      external_transfer_id VARCHAR(100),
      status VARCHAR(20),
      payload LONGTEXT NOT NULL,
      
      received_at DATETIME NOT NULL,
      processed_at DATETIME,
      http_status INT,
      error_message TEXT,
      
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      
      KEY idx_provider (provider),
      KEY idx_received_at (received_at),
      KEY idx_external_order_id (external_order_id),
      KEY idx_processed_at (processed_at)
    ) $charset_collate;");

    // Limpiar idempotency keys expiradas (opcional)
    wp_schedule_event(time(), 'daily', 'af_cleanup_idempotency_keys');
  }

  public static function deactivate() {
    wp_clear_scheduled_hook('af_cleanup_idempotency_keys');
  }
}

// Cleanup cron
add_action('af_cleanup_idempotency_keys', function() {
  global $wpdb;
  $wpdb->query("DELETE FROM {$wpdb->prefix}af_idempotency_keys WHERE expires_at < NOW()");
});
```

---

## Fase 2: Autenticación OAuth (Semana 1)

### 2.1 Clase Deuna Auth

**Archivo:** `includes/payments/class-deuna-auth.php`

```php
<?php
/**
 * Deuna OAuth 2.0 Token Management
 */
class Arriendo_Facil_Deuna_Auth {
  const TOKEN_TRANSIENT_KEY = 'af_deuna_token';
  const TOKEN_TRANSIENT_TTL = 3300; // 55 minutos (< 3600 del token real)

  /**
   * Obtener token OAuth válido de Deuna
   * 
   * @return string|WP_Error Token o error
   */
  public static function get_token() {
    // 1. Intentar obtener del cache (transient)
    $cached_token = get_transient(self::TOKEN_TRANSIENT_KEY);
    if ($cached_token) {
      return $cached_token;
    }

    // 2. Obtener nuevo token
    $response = wp_remote_post(
      'https://api.deuna.io/v1/auth/token',
      array(
        'method'      => 'POST',
        'headers'     => array('Content-Type' => 'application/x-www-form-urlencoded'),
        'body'        => array(
          'grant_type'    => 'client_credentials',
          'client_id'     => defined('ARRIENDO_FACIL_DEUNA_CLIENT_ID') 
            ? ARRIENDO_FACIL_DEUNA_CLIENT_ID 
            : '',
          'client_secret' => defined('ARRIENDO_FACIL_DEUNA_SECRET') 
            ? ARRIENDO_FACIL_DEUNA_SECRET 
            : '',
          'scope'         => 'orders:create webhooks:verify'
        ),
        'timeout'     => 5,
        'sslverify'   => true
      )
    );

    // 3. Manejar errores
    if (is_wp_error($response)) {
      error_log('Deuna auth error: ' . $response->get_error_message());
      return new WP_Error('deuna_auth_failed', 'Failed to get Deuna token');
    }

    // 4. Procesar respuesta
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $status = wp_remote_retrieve_response_code($response);

    if ($status !== 200 || empty($body['access_token'])) {
      error_log('Deuna token error: ' . wp_remote_retrieve_body($response));
      return new WP_Error('deuna_token_invalid', 'Invalid token response');
    }

    // 5. Cachear token
    $token = $body['access_token'];
    set_transient(self::TOKEN_TRANSIENT_KEY, $token, self::TOKEN_TRANSIENT_TTL);

    return $token;
  }

  /**
   * Invalidar token cache (si expira o hay error)
   */
  public static function invalidate_token() {
    delete_transient(self::TOKEN_TRANSIENT_KEY);
  }
}
```

### 2.2 Clase Pichincha Auth

**Archivo:** `includes/payments/class-pichincha-auth.php`

```php
<?php
/**
 * Banco Pichincha Cash Management OAuth
 */
class Arriendo_Facil_Pichincha_Auth {
  const TOKEN_TRANSIENT_KEY = 'af_pichincha_token';
  const TOKEN_TRANSIENT_TTL = 3300;

  const API_SANDBOX = 'https://api-sandbox.pichincha.com';
  const API_PROD    = 'https://api.pichincha.com';

  /**
   * Obtener token Pichincha
   * 
   * @return string|WP_Error
   */
  public static function get_token() {
    $cached = get_transient(self::TOKEN_TRANSIENT_KEY);
    if ($cached) {
      return $cached;
    }

    $api_base = self::get_api_base();
    $response = wp_remote_post(
      $api_base . '/oauth2/token',
      array(
        'method'      => 'POST',
        'headers'     => array('Content-Type' => 'application/x-www-form-urlencoded'),
        'body'        => array(
          'grant_type'    => 'client_credentials',
          'client_id'     => defined('ARRIENDO_FACIL_PICHINCHA_CLIENT_ID') 
            ? ARRIENDO_FACIL_PICHINCHA_CLIENT_ID 
            : '',
          'client_secret' => defined('ARRIENDO_FACIL_PICHINCHA_SECRET') 
            ? ARRIENDO_FACIL_PICHINCHA_SECRET 
            : ''
        ),
        'timeout'     => 5,
        'sslverify'   => true
      )
    );

    if (is_wp_error($response)) {
      error_log('Pichincha auth error: ' . $response->get_error_message());
      return new WP_Error('pichincha_auth_failed', 'Pichincha auth failed');
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['access_token'])) {
      error_log('Pichincha token error: ' . wp_remote_retrieve_body($response));
      return new WP_Error('pichincha_token_invalid', 'Invalid token');
    }

    $token = $body['access_token'];
    set_transient(self::TOKEN_TRANSIENT_KEY, $token, self::TOKEN_TRANSIENT_TTL);

    return $token;
  }

  /**
   * Determinar si estamos en sandbox o producción
   * 
   * @return string
   */
  private static function get_api_base() {
    $environment = defined('ARRIENDO_FACIL_ENVIRONMENT') 
      ? ARRIENDO_FACIL_ENVIRONMENT 
      : 'sandbox';
    
    return $environment === 'production' ? self::API_PROD : self::API_SANDBOX;
  }

  public static function invalidate_token() {
    delete_transient(self::TOKEN_TRANSIENT_KEY);
  }
}
```

---

## Fase 3: Crear Órdenes en Deuna (Semana 1-2)

### 3.1 Clase de Órdenes Deuna

**Archivo:** `includes/payments/class-deuna-orders.php`

```php
<?php
/**
 * Deuna Orders Management
 */
class Arriendo_Facil_Deuna_Orders {
  
  /**
   * Crear orden en Deuna
   * 
   * @param array $args {
   *   @type int $amount Monto en centavos/dólares
   *   @type int $property_id ID de la propiedad
   *   @type string $guest_email Email del huésped
   *   @type string $guest_phone Teléfono del huésped
   *   @type string $guest_first_name Nombre del huésped
   *   @type string $guest_last_name Apellido del huésped
   *   @type string $check_in_date Fecha check-in (YYYY-MM-DD)
   *   @type string $check_out_date Fecha check-out (YYYY-MM-DD)
   *   @type int $nights Noches de hospedaje
   * }
   * 
   * @return array|WP_Error Orden creada o error
   */
  public static function create_order($args) {
    global $wpdb;

    // Validar argumentos
    if (empty($args['amount']) || empty($args['property_id']) || empty($args['guest_email'])) {
      return new WP_Error('invalid_args', 'Missing required order parameters');
    }

    // Generar IDs
    $order_id = 'RES-' . time() . '-' . substr(md5(wp_generate_uuid4()), 0, 8);
    $idempotency_key = wp_generate_uuid4();

    // Calcular comisión
    $commission = $args['amount'] * 0.10;

    // Obtener token Deuna
    $token = Arriendo_Facil_Deuna_Auth::get_token();
    if (is_wp_error($token)) {
      return $token;
    }

    // Preparar payload
    $payload = array(
      'amount'         => (float) $args['amount'],
      'currency'       => 'USD',
      'orderId'        => $order_id,
      'description'    => sprintf(
        'Reserva propiedad %d (%d noches)',
        $args['property_id'],
        $args['nights']
      ),
      'idempotencyKey' => $idempotency_key,
      'customer'       => array(
        'email'     => $args['guest_email'],
        'phone'     => $args['guest_phone'] ?? '',
        'firstName' => $args['guest_first_name'] ?? '',
        'lastName'  => $args['guest_last_name'] ?? ''
      ),
      'metadata'       => array(
        'propertyId'           => $args['property_id'],
        'checkInDate'          => $args['check_in_date'],
        'checkOutDate'         => $args['check_out_date'],
        'nights'               => $args['nights'],
        'platformCommission'   => $commission
      ),
      'notificationUrl' => home_url('/wp-json/arriendo-facil/v1/webhooks/deuna/payment'),
      'expiresIn'       => 900 // 15 minutos
    );

    // Enviar a Deuna
    $response = wp_remote_post(
      'https://api.deuna.io/v1/orders',
      array(
        'method'      => 'POST',
        'headers'     => array(
          'Content-Type'    => 'application/json',
          'Authorization'   => 'Bearer ' . $token,
          'X-Idempotency-Key' => $idempotency_key
        ),
        'body'        => json_encode($payload),
        'timeout'     => 10,
        'sslverify'   => true
      )
    );

    // Manejar errores
    if (is_wp_error($response)) {
      error_log('Deuna order error: ' . $response->get_error_message());
      return new WP_Error('deuna_order_failed', 'Failed to create order');
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    // Si ya existe (409 Conflict), retornar existente
    if ($status_code === 409) {
      $existing = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$wpdb->prefix}af_orders WHERE id = %s", $order_id),
        ARRAY_A
      );
      if ($existing) {
        return array(
          'order_id' => $existing['id'],
          'deuna_id' => $existing['deuna_id'],
          'qr_base64' => $existing['qr_data'],
          'expires_at' => $existing['expires_at'],
          'is_retry' => true
        );
      }
    }

    if ($status_code !== 200 || empty($body['id'])) {
      error_log('Deuna error: ' . wp_remote_retrieve_body($response));
      return new WP_Error('deuna_invalid_response', 'Invalid response from Deuna');
    }

    // Guardar en BD
    $wpdb->insert(
      $wpdb->prefix . 'af_orders',
      array(
        'id'                 => $order_id,
        'deuna_id'           => $body['id'],
        'property_id'        => $args['property_id'],
        'guest_email'        => $args['guest_email'],
        'guest_phone'        => $args['guest_phone'] ?? null,
        'amount'             => $args['amount'],
        'currency'           => 'USD',
        'status'             => 'PENDING',
        'qr_data'            => $body['qrCode']['data'] ?? null,
        'idempotency_key'    => $idempotency_key,
        'created_at'         => current_time('mysql'),
        'expires_at'         => $body['expiresAt'] ?? null
      ),
      array('%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s')
    );

    return array(
      'order_id'    => $order_id,
      'deuna_id'    => $body['id'],
      'qr_base64'   => $body['qrCode']['data'] ?? null,
      'expires_at'  => $body['expiresAt'] ?? null,
      'is_retry'    => false
    );
  }
}
```

---

## Fase 4: Webhooks de Pagos (Semana 2)

### 4.1 Validación de HMAC

**Archivo:** `includes/payments/class-webhook-validator.php`

```php
<?php
/**
 * Webhook Validation (HMAC-SHA256)
 */
class Arriendo_Facil_Webhook_Validator {

  /**
   * Validar firma HMAC de Deuna
   * 
   * @param string $raw_body Raw body del request
   * @param string $signature Header X-Deuna-Signature
   * @param string $secret DEUNA_WEBHOOK_SECRET
   * 
   * @return bool
   */
  public static function validate_deuna_signature($raw_body, $signature, $secret) {
    // Formato: sha256=hash
    list($algorithm, $hash) = explode('=', $signature);

    if ($algorithm !== 'sha256') {
      return false;
    }

    // Calcular HMAC esperado
    $expected_hash = hash_hmac('sha256', $raw_body, $secret);

    // Usar hash_equals para prevenir timing attacks
    return hash_equals($hash, $expected_hash);
  }

  /**
   * Validar timestamp del webhook (máximo 5 minutos)
   * 
   * @param string $timestamp ISO 8601 timestamp
   * @param int $max_age_seconds Edad máxima permitida
   * 
   * @return bool
   */
  public static function validate_webhook_timestamp($timestamp, $max_age_seconds = 300) {
    $webhook_time = strtotime($timestamp);
    $now = time();
    $age_seconds = $now - $webhook_time;

    // Webhook debe tener menos de 5 min y no ser del futuro (con tolerancia)
    return $age_seconds <= $max_age_seconds && $age_seconds >= -30;
  }
}
```

### 4.2 Webhook Handler REST API

**Archivo:** `includes/payments/class-deuna-webhook-handler.php`

```php
<?php
/**
 * Deuna Webhook Handler (REST API)
 */
class Arriendo_Facil_Deuna_Webhook_Handler {

  /**
   * Registrar endpoint REST
   */
  public static function register_rest_route() {
    register_rest_route('arriendo-facil/v1', '/webhooks/deuna/payment', array(
      'methods'             => 'POST',
      'callback'            => array(__CLASS__, 'handle_payment_webhook'),
      'permission_callback' => '__return_true', // Validamos con HMAC
    ));
  }

  /**
   * Manejar webhook de pago de Deuna
   * 
   * @param WP_REST_Request $request
   * 
   * @return WP_REST_Response
   */
  public static function handle_payment_webhook(WP_REST_Request $request) {
    // 1. Capturar raw body
    $raw_body = file_get_contents('php://input');
    $signature = $request->get_header('x_deuna_signature');
    $timestamp = $request->get_header('x_deuna_timestamp');

    // 2. Validar firma HMAC
    $secret = defined('ARRIENDO_FACIL_DEUNA_WEBHOOK_SECRET') 
      ? ARRIENDO_FACIL_DEUNA_WEBHOOK_SECRET 
      : '';

    if (!Arriendo_Facil_Webhook_Validator::validate_deuna_signature($raw_body, $signature, $secret)) {
      error_log('Invalid Deuna webhook signature');
      return new WP_REST_Response(array('error' => 'Unauthorized'), 401);
    }

    // 3. Validar timestamp
    if (!Arriendo_Facil_Webhook_Validator::validate_webhook_timestamp($timestamp)) {
      error_log('Deuna webhook timestamp out of range');
      return new WP_REST_Response(array('error' => 'Timestamp expired'), 400);
    }

    // 4. Parse payload
    $data = $request->get_json_params();
    $webhook_id = $data['id'] ?? null;
    $payment_data = $data['data'] ?? array();

    if (!$webhook_id || !$payment_data) {
      return new WP_REST_Response(array('error' => 'Invalid payload'), 400);
    }

    // 5. Guardar webhook log
    self::log_webhook($webhook_id, $payment_data);

    // 6. Responder inmediatamente (< 100ms)
    // Procesar después en cron
    do_action('af_process_payment_webhook', $payment_data);

    return new WP_REST_Response(array('acknowledged' => true), 200);
  }

  /**
   * Guardar webhook en logs (auditoría)
   */
  private static function log_webhook($webhook_id, $data) {
    global $wpdb;

    $wpdb->insert(
      $wpdb->prefix . 'af_webhook_logs',
      array(
        'id'                 => wp_generate_uuid4(),
        'provider'           => 'DEUNA',
        'event'              => 'payment.completed',
        'external_order_id'  => $data['orderId'] ?? null,
        'status'             => $data['status'] ?? null,
        'payload'            => json_encode($data),
        'received_at'        => current_time('mysql'),
        'http_status'        => 200
      ),
      array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
    );
  }
}

// Registrar endpoint al cargar API
add_action('rest_api_init', array('Arriendo_Facil_Deuna_Webhook_Handler', 'register_rest_route'));
```

---

## Fase 5: Procesamiento Asincrónico (Semana 2-3)

### 5.1 Payment Processor (Reemplaza Bull)

**Archivo:** `includes/payments/class-payment-processor.php`

```php
<?php
/**
 * Payment Processing (Async via WP-Cron)
 */
class Arriendo_Facil_Payment_Processor {

  /**
   * Procesar pago confirmado
   * Este método es llamado por WP-Cron
   * 
   * @param array $payment_data Datos del webhook
   */
  public static function process_payment(array $payment_data) {
    global $wpdb;

    $deuna_order_id = $payment_data['orderId'] ?? null;
    $amount = $payment_data['amount'] ?? 0;
    $status = $payment_data['status'] ?? null;

    if (!$deuna_order_id || $status !== 'COMPLETED') {
      return;
    }

    // Transacción: procesar pago
    $wpdb->query('START TRANSACTION');

    try {
      // 1. Buscar orden
      $order = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT * FROM {$wpdb->prefix}af_orders WHERE deuna_id = %s",
          $deuna_order_id
        ),
        ARRAY_A
      );

      if (!$order) {
        throw new Exception("Order not found: {$deuna_order_id}");
      }

      // 2. Verificar si ya fue procesada (idempotencia)
      $existing_payment = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT * FROM {$wpdb->prefix}af_payments WHERE order_id = %s",
          $order['id']
        ),
        ARRAY_A
      );

      if ($existing_payment) {
        // Ya fue procesada, rollback
        $wpdb->query('ROLLBACK');
        return;
      }

      // 3. Marcar orden como PAID
      $wpdb->update(
        $wpdb->prefix . 'af_orders',
        array(
          'status'                  => 'PAID',
          'paid_at'                 => current_time('mysql'),
          'deuna_transaction_id'    => $payment_data['transactionId'] ?? null
        ),
        array('id' => $order['id']),
        array('%s', '%s', '%s')
      );

      // 4. Crear registro de pago
      $wpdb->insert(
        $wpdb->prefix . 'af_payments',
        array(
          'id'              => wp_generate_uuid4(),
          'order_id'        => $order['id'],
          'amount'          => $amount,
          'status'          => 'COMPLETED',
          'transaction_id'  => $payment_data['transactionId'] ?? null,
          'received_at'     => current_time('mysql')
        ),
        array('%s', '%s', '%f', '%s', '%s', '%s')
      );

      // 5. Calcular comisión y balance
      $commission = $amount * 0.10;
      $net_amount = $amount - $commission;

      // 6. Actualizar balance del propietario
      $owner_id = get_post_field('post_author', $order['property_id']);

      // Verificar si existe registro de balance
      $balance_exists = $wpdb->get_var(
        $wpdb->prepare(
          "SELECT COUNT(*) FROM {$wpdb->prefix}af_owner_balances WHERE owner_id = %d",
          $owner_id
        )
      );

      if ($balance_exists) {
        $wpdb->query(
          $wpdb->prepare(
            "UPDATE {$wpdb->prefix}af_owner_balances 
             SET available_balance = available_balance + %f,
                 total_earnings = total_earnings + %f,
                 updated_at = NOW()
             WHERE owner_id = %d",
            $net_amount,
            $amount,
            $owner_id
          )
        );
      } else {
        $wpdb->insert(
          $wpdb->prefix . 'af_owner_balances',
          array(
            'owner_id'            => $owner_id,
            'available_balance'   => $net_amount,
            'total_earnings'      => $amount
          ),
          array('%d', '%f', '%f')
        );
      }

      // 7. Crear entradas de ledger
      self::create_ledger_entries($order['id'], $owner_id, $amount, $commission, $net_amount);

      // 8. Enqueue payout (crear transfer)
      $payout_id = wp_generate_uuid4();
      $wpdb->insert(
        $wpdb->prefix . 'af_payouts',
        array(
          'id'                  => $payout_id,
          'owner_id'            => $owner_id,
          'order_id'            => $order['id'],
          'amount'              => $net_amount,
          'status'              => 'PENDING',
          'idempotency_key'     => wp_generate_uuid4(),
          'created_at'          => current_time('mysql')
        ),
        array('%s', '%d', '%s', '%f', '%s', '%s', '%s')
      );

      $wpdb->query('COMMIT');

      // 9. Agendar procesamiento de payout
      do_action('af_process_payout_pending', $payout_id, $owner_id, $net_amount);

      error_log("Payment processed successfully: {$order['id']}");

    } catch (Exception $e) {
      $wpdb->query('ROLLBACK');
      error_log("Payment processing error: " . $e->getMessage());
    }
  }

  /**
   * Crear entradas de ledger
   */
  private static function create_ledger_entries($order_id, $owner_id, $amount, $commission, $net_amount) {
    global $wpdb;

    $entries = array(
      array(
        'id'           => wp_generate_uuid4(),
        'order_id'     => $order_id,
        'owner_id'     => $owner_id,
        'type'         => 'PAYMENT_RECEIVED',
        'amount'       => $amount,
        'description'  => "Pago recibido - Reserva {$order_id}",
        'created_at'   => current_time('mysql')
      ),
      array(
        'id'           => wp_generate_uuid4(),
        'order_id'     => $order_id,
        'owner_id'     => null,
        'type'         => 'COMMISSION_CHARGED',
        'amount'       => -$commission,
        'description'  => "Comisión plataforma (10%) - {$order_id}",
        'created_at'   => current_time('mysql')
      ),
      array(
        'id'           => wp_generate_uuid4(),
        'order_id'     => $order_id,
        'owner_id'     => $owner_id,
        'type'         => 'BALANCE_INCREASE',
        'amount'       => $net_amount,
        'description'  => "Saldo disponible para retiro - {$order_id}",
        'created_at'   => current_time('mysql')
      )
    );

    foreach ($entries as $entry) {
      $wpdb->insert(
        $wpdb->prefix . 'af_ledger_entries',
        $entry,
        array('%s', '%s', '%d', '%s', '%f', '%s', '%s')
      );
    }
  }
}

// Registrar action para procesar webhooks
add_action('af_process_payment_webhook', array('Arriendo_Facil_Payment_Processor', 'process_payment'));

// Registrar cron para procesar pagos pendientes
if (!wp_next_scheduled('af_process_pending_payments')) {
  wp_schedule_event(time(), 'every_5_minutes', 'af_process_pending_payments');
}
add_action('af_process_pending_payments', array('Arriendo_Facil_Payment_Processor', 'process_pending_payments'));
```

---

## Fase 6: Transferencias a Banco Pichincha (Semana 3)

### 6.1 Payout Manager

**Archivo:** `includes/payments/class-payout-manager.php`

```php
<?php
/**
 * Payout Management (Transfers to Owner Bank Accounts)
 */
class Arriendo_Facil_Payout_Manager {

  /**
   * Crear transferencia en Banco Pichincha
   * 
   * @param string $payout_id ID del payout
   * @param int $owner_id ID del propietario
   * @param float $amount Monto a transferir
   */
  public static function create_transfer($payout_id, $owner_id, $amount) {
    global $wpdb;

    // 1. Validar cuenta bancaria del propietario
    $owner = get_userdata($owner_id);
    if (!$owner) {
      error_log("Owner not found: {$owner_id}");
      self::mark_payout_failed($payout_id, 'Owner not found');
      return;
    }

    // Obtener datos bancarios (custom user meta)
    $bank_account = get_user_meta($owner_id, 'bank_account_number', true);
    $bank_code = get_user_meta($owner_id, 'bank_code', true);

    if (!$bank_account || !$bank_code) {
      // Propietario no configuró cuenta - esperar a que complete
      $wpdb->update(
        $wpdb->prefix . 'af_payouts',
        array('status' => 'WAITING_BANK_INFO'),
        array('id' => $payout_id),
        array('%s'),
        array('%s')
      );

      // Notificar propietario
      wp_mail(
        $owner->user_email,
        'Completa tu información bancaria',
        'Complete tus datos bancarios para recibir tus ganancias.'
      );
      return;
    }

    // 2. Obtener token Pichincha
    $token = Arriendo_Facil_Pichincha_Auth::get_token();
    if (is_wp_error($token)) {
      error_log("Failed to get Pichincha token");
      self::schedule_retry($payout_id);
      return;
    }

    // 3. Preparar payload
    $idempotency_key = wp_generate_uuid4();
    $payload = array(
      'idempotencyKey' => $idempotency_key,
      'beneficiary'    => array(
        'accountNumber' => $bank_account,
        'bankCode'      => $bank_code,
        'accountType'   => 'CHECKING',
        'accountHolder' => array(
          'name'               => $owner->display_name,
          'identification'     => get_user_meta($owner_id, 'identification_number', true),
          'identificationType' => 'CI'
        )
      ),
      'amount'        => (float) $amount,
      'currency'      => 'USD',
      'description'   => 'Retiro de ganancias',
      'reference'     => $payout_id,
      'metadata'      => array('ownerUserId' => $owner_id)
    );

    // 4. Enviar a Pichincha
    $api_base = defined('ARRIENDO_FACIL_ENVIRONMENT') && ARRIENDO_FACIL_ENVIRONMENT === 'production' 
      ? 'https://api.pichincha.com' 
      : 'https://api-sandbox.pichincha.com';

    $response = wp_remote_post(
      $api_base . '/v1/transfers',
      array(
        'method'      => 'POST',
        'headers'     => array(
          'Content-Type'        => 'application/json',
          'Authorization'       => 'Bearer ' . $token,
          'X-Idempotency-Key'   => $idempotency_key,
          'X-Request-ID'        => wp_generate_uuid4()
        ),
        'body'        => json_encode($payload),
        'timeout'     => 15,
        'sslverify'   => true
      )
    );

    // 5. Manejar respuesta
    if (is_wp_error($response)) {
      error_log("Pichincha request error: " . $response->get_error_message());
      self::schedule_retry($payout_id);
      return;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    // Si es 409 (ya existe por idempotencia), recuperar existente
    if ($status_code === 409) {
      $existing = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT * FROM {$wpdb->prefix}af_payouts WHERE idempotency_key = %s",
          $idempotency_key
        ),
        ARRAY_A
      );

      if ($existing) {
        error_log("Transfer already exists (idempotency): {$existing['id']}");
        return;
      }
    }

    if ($status_code !== 200 || empty($body['id'])) {
      error_log("Pichincha error: " . wp_remote_retrieve_body($response));
      self::schedule_retry($payout_id);
      return;
    }

    // 6. Actualizar payout con transfer ID
    $wpdb->update(
      $wpdb->prefix . 'af_payouts',
      array(
        'status'                   => 'IN_PROGRESS',
        'bank_transfer_id'         => $body['id'],
        'idempotency_key'          => $idempotency_key,
        'estimated_completion'     => $body['estimatedCompletionAt'] ?? null
      ),
      array('id' => $payout_id),
      array('%s', '%s', '%s', '%s')
    );

    error_log("Transfer created: {$body['id']} for payout {$payout_id}");
  }

  /**
   * Agendar reintento si falla
   */
  private static function schedule_retry($payout_id) {
    global $wpdb;

    $payout = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}af_payouts WHERE id = %s",
        $payout_id
      ),
      ARRAY_A
    );

    if (!$payout) {
      return;
    }

    $retry_count = (int) $payout['retry_count'] + 1;
    if ($retry_count > 5) {
      self::mark_payout_failed($payout_id, 'Max retries exceeded');
      return;
    }

    // Exponential backoff: 5s, 10s, 20s, 40s, 80s
    $delay = pow(2, $retry_count - 1) * 5;

    $wpdb->update(
      $wpdb->prefix . 'af_payouts',
      array(
        'retry_count'     => $retry_count,
        'last_retry_at'   => current_time('mysql')
      ),
      array('id' => $payout_id)
    );

    // Agendar reintento
    wp_schedule_single_event(time() + $delay, 'af_retry_payout', array($payout_id));
  }

  /**
   * Marcar payout como fallido
   */
  private static function mark_payout_failed($payout_id, $error_message) {
    global $wpdb;

    $wpdb->update(
      $wpdb->prefix . 'af_payouts',
      array(
        'status'        => 'FAILED',
        'last_error'    => $error_message
      ),
      array('id' => $payout_id)
    );

    // Enviar alerta
    error_log("CRITICAL: Payout {$payout_id} failed: {$error_message}");
    // TODO: Enviar a Slack/email
  }
}

// Registrar acción para procesar payouts
add_action('af_process_payout_pending', array('Arriendo_Facil_Payout_Manager', 'create_transfer'), 10, 3);
add_action('af_retry_payout', array('Arriendo_Facil_Payout_Manager', 'create_transfer'), 10, 3);
```

---

## Resumen: Estructura Final del Plugin

```
includes/payments/
├── class-deuna-auth.php                    # OAuth tokens
├── class-deuna-orders.php                  # Create orders + QR
├── class-pichincha-auth.php                # OAuth tokens BP
├── class-pichincha-transfers.php           # Create transfers
├── class-webhook-validator.php             # HMAC validation
├── class-deuna-webhook-handler.php         # REST API endpoint
├── class-payment-processor.php             # Process payments (WP-Cron)
├── class-payout-manager.php                # Create payouts (transfers)
├── class-ledger-manager.php                # Ledger entries
└── interface-payment-provider.php          # Abstract interface
```

**wp-config.php (agregar constantes):**
```php
// Deuna
define('ARRIENDO_FACIL_DEUNA_CLIENT_ID', 'xxxxx');
define('ARRIENDO_FACIL_DEUNA_SECRET', 'xxxxx');
define('ARRIENDO_FACIL_DEUNA_WEBHOOK_SECRET', 'xxxxx');

// Banco Pichincha
define('ARRIENDO_FACIL_PICHINCHA_CLIENT_ID', 'xxxxx');
define('ARRIENDO_FACIL_PICHINCHA_SECRET', 'xxxxx');

// Environment
define('ARRIENDO_FACIL_ENVIRONMENT', 'sandbox'); // 'production' para prod
```

**Próximos documentos a adaptar:**
- [ ] QUICK_REFERENCE.md → wpdb queries
- [ ] TESTING_VALIDATION.md → PHPUnit examples
- [ ] Actualizar PAYMENTS_ARCHITECTURE_README.md con stack PHP/MySQL

