# Integración de APIs Booking y Airbnb - Arquitectura Detallada

**Versión:** 1.0  
**Fecha:** 2026-07-08  
**Autor:** Arquitecto Senior de Software  
**Estado:** Plan de Implementación

---

## ÍNDICE

1. [Análisis de Arquitectura Actual](#análisis-de-arquitectura-actual)
2. [Arquitectura de Datos](#arquitectura-de-datos)
3. [Integración con APIs](#integración-con-apis)
4. [Sincronización de Disponibilidad](#sincronización-de-disponibilidad)
5. [UI/UX - Interfaz de Usuario](#uiux---interfaz-de-usuario)
6. [Gestión de Errores y Concurrencia](#gestión-de-errores-y-concurrencia)
7. [Seguridad](#seguridad)
8. [Performance](#performance)
9. [Plan de Implementación](#plan-de-implementación-ordenado-sin-breaking-changes)
10. [Riesgos y Mitigaciones](#riesgos-y-mitigaciones)

---

## ANÁLISIS DE ARQUITECTURA ACTUAL

### Estado Presente

- **Sistema:** Plugin WordPress con Custom Post Type `accommodation`
- **Disponibilidad:** Post meta `_af_is_occupied` (booleano: 0/1)
- **Estructura:** Metadatos por acomodación (_af_address, _af_bedrooms, _af_bathrooms, etc.)
- **Multi-tenant:** Soporta múltiples propietarios (_af_owner_id)
- **Búsqueda:** REST API en `/af/v1/accommodations/search`
- **Admin UI:** Wizard de formulario multi-paso en lugar de editor clásico
- **Patrones:** Clases especializadas por dominio, hooks de WordPress, AJAX para operaciones async

### Implicaciones para OTA

1. **Datos Distribuidos:** Los IDs de propiedades remotas deben vivir como post meta para mantener coherencia
2. **Disponibilidad Simple:** Sistema binario (ocupado/libre) debe reconciliarse con datos más granulares de Booking/Airbnb
3. **Multi-tenant:** Cada propietario necesita credenciales separadas por plataforma
4. **Extensibilidad:** El plugin ya tiene patrón de `Arriendo_Facil_*` clases, debe seguirse

---

## ARQUITECTURA DE DATOS

### 1. Nuevos Metadatos de Acomodación

Agregados a cada post de tipo `accommodation`:

| Meta Key | Tipo | Descripción |
|----------|------|-------------|
| `_af_booking_property_id` | string | ID de propiedad en Booking.com |
| `_af_airbnb_listing_id` | string | ID de listing en Airbnb |
| `_af_sync_enabled` | boolean | Activar/desactivar sincronización |
| `_af_last_sync_timestamp` | int | Unix timestamp del último sync exitoso |
| `_af_sync_errors` | JSON | Errores del último intento de sync |

### 2. Tabla: `wp_af_otas_sync_log`

Registra todos los intentos de sincronización para auditería y debugging:

```sql
CREATE TABLE IF NOT EXISTS wp_af_otas_sync_log (
    id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    accommodation_id  BIGINT(20) UNSIGNED NOT NULL,
    ota_source        VARCHAR(20) NOT NULL,          -- 'booking' o 'airbnb'
    sync_type         VARCHAR(50) NOT NULL,          -- 'availability', 'full', 'manual'
    remote_property_id VARCHAR(100) NOT NULL,
    status            VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending, success, failed
    local_was_occupied TINYINT(1),
    remote_is_occupied TINYINT(1),
    remote_booked_dates JSON DEFAULT NULL,           -- array de fechas
    error_message     TEXT DEFAULT NULL,
    request_payload   LONGTEXT DEFAULT NULL,         -- para debugging
    response_payload  LONGTEXT DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY accommodation_id (accommodation_id),
    KEY ota_source (ota_source),
    KEY status (status),
    KEY created_at (created_at)
)
```

**Ventajas:**
- Historial completo de sincronizaciones
- Debugging facilitado
- Análisis de errores patrones
- No afecta post meta (más limpio)

### 3. Tabla: `wp_af_ota_credentials`

Almacena credenciales encriptadas de propietarios:

```sql
CREATE TABLE IF NOT EXISTS wp_af_ota_credentials (
    id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id          BIGINT(20) UNSIGNED NOT NULL,
    ota_platform      VARCHAR(20) NOT NULL,          -- 'booking' o 'airbnb'
    api_key_encrypted VARCHAR(255) NOT NULL,
    account_identifier VARCHAR(100) NOT NULL,        -- username, account ID, etc
    connected         TINYINT(1) NOT NULL DEFAULT 0,
    last_verified     DATETIME DEFAULT NULL,
    status            VARCHAR(20) NOT NULL DEFAULT 'inactive',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY owner_platform (owner_id, ota_platform),
    KEY ota_platform (ota_platform)
)
```

**Ventajas:**
- Credenciales no en post meta (más seguro)
- Un único par de credenciales por propietario por plataforma
- Encriptación centralizada
- Fácil de revocar/cambiar

---

## INTEGRACIÓN CON APIS

### 1. Clase Base: `class-ota-api-client-base.php`

Define interfaz estándar que todas las integraciones implementan:

```php
abstract class Arriendo_Facil_OTA_API_Client_Base {
    
    protected $api_key;
    protected $account_identifier;
    protected $timeout = 15;
    protected $max_retries = 3;
    protected $retry_delay_seconds = 2;
    
    /**
     * Obtiene disponibilidad para una fecha específica.
     */
    abstract public function get_availability( $date_from, $date_to );
    
    /**
     * Verifica si una propiedad está ocupada.
     */
    abstract public function check_property_occupied( $property_id, $date_from = null, $date_to = null );
    
    /**
     * Valida que las credenciales sean válidas.
     */
    abstract public function validate_credentials();
    
    /**
     * Realiza request con retry logic y rate limiting.
     */
    protected function request( $endpoint, $method = 'GET', $body = null );
}
```

**Beneficios:**
- Interfaz consistente
- Fácil de agregar nuevas plataformas (Vrbo, Expedia, etc.)
- Retry logic y rate limiting centralizados
- Error handling estándar

### 2. Cliente Booking.com: `class-booking-api-client.php`

**Endpoints Utilizados:**

- `GET /v1/properties/{propertyId}` - Información de propiedad
- `GET /v1/availabilities` - Calendario completo
- `GET /v1/reservations` - Lista de reservas activas
- `POST /webhooks` - Configurar notificaciones (opcional)

**Autenticación:**
- Header: `Authorization: Bearer {API_KEY}`
- Partner ID y Property ID en requests

**Sincronización:**

```php
public function sync_property_availability( $property_id, $date_from = null, $date_to = null ) {
    // 1. Obtener reservas desde Booking para el período
    // 2. Si hay reservas → propiedad ocupada
    // 3. Si NO hay reservas → propiedad disponible
    // 4. Retornar array: ['is_occupied' => bool, 'booked_dates' => [...], ...]
}
```

### 3. Cliente Airbnb: `class-airbnb-api-client.php`

**Endpoints Utilizados:**

- `GET /listings/{listingId}` - Detalles del listing
- `GET /listings/{listingId}/availability_calendar` - Calendario
- Airbnb Public API (si disponible en tu región)

**Nota:** Airbnb no expone API pública oficial ampliamente. Opciones:
1. **Airbnb Connect** (si está disponible): OAuth + API oficial
2. **Unofficial API**: usar web scraping (menos confiable)
3. **Webhooks**: Airbnb puede enviar eventos (requiere aprobación)

**Para MVP:** Integración básica con credenciales y polling

### 4. Orchestrator: `class-ota-sync-manager.php`

Orquesta toda la sincronización:

```php
class Arriendo_Facil_OTA_Sync_Manager {
    
    /**
     * Punto de entrada: sincroniza una acomodación con todas plataformas configuradas.
     */
    public function sync_accommodation( $accommodation_id, $sources = array( 'booking', 'airbnb' ) ) {
        // 1. Validar acomodación
        // 2. Para cada fuente:
        //    a. Obtener credenciales del propietario
        //    b. Crear cliente API
        //    c. Obtener estado remoto
        //    d. Reconciliar con estado local
        //    e. Registrar en log
        // 3. Retornar resultados
    }
    
    /**
     * Reconcilia disponibilidad local vs remota.
     * Estrategia: remoto tiene prioridad (más reciente).
     */
    private function reconcile_occupancy( $accommodation_id, $source, $remote_status ) {
        $local_occupied = Arriendo_Facil_Accommodation_Occupied_Admin::is_occupied( $accommodation_id );
        $remote_occupied = $remote_status['is_occupied'];
        
        // Si remoto dice ocupado → marcar como ocupado localmente
        if ( $remote_occupied && ! $local_occupied ) {
            update_post_meta( $accommodation_id, '_af_is_occupied', 1 );
            do_action( 'af_accommodation_marked_occupied', $accommodation_id, $source );
        }
        // Opcional: avisar si hay desacuerdo (remoto libre, local ocupado)
    }
}
```

---

## SINCRONIZACIÓN DE DISPONIBILIDAD

### 1. Mecanismo: Hybrid (Recomendado)

**Opción A: WP-Cron (Fallback)**
- Frecuencia: cada 2 horas (configurable)
- Confiable pero menos inmediato
- Ideal para MVP y baja concurrencia

**Opción B: Webhooks (Real-time)**
- Booking/Airbnb notifican cambios inmediatamente
- Requiere endpoint público y configuración manual
- Más rápido pero complejo

**Opción C: Hybrid (Recomendado)**
- Webhooks si están configurados (real-time)
- WP-Cron como fallback (cada 2 horas)
- Lo mejor de ambos mundos

### 2. Implementación WP-Cron

En `arriendo-facil.php`:

```php
// Registrar intervalo personalizado
add_filter( 'cron_schedules', function( $schedules ) {
    $schedules['every_2_hours'] = array(
        'interval' => 2 * HOUR_IN_SECONDS,
        'display' => 'Cada 2 horas',
    );
    return $schedules;
});

// Agendar acción
add_action( 'af_sync_ota_availability', array( 'Arriendo_Facil_OTA_Sync_Manager', 'process_scheduled_sync' ) );

if ( ! wp_next_scheduled( 'af_sync_ota_availability' ) ) {
    wp_schedule_event( time(), 'every_2_hours', 'af_sync_ota_availability' );
}

// Handler static
class Arriendo_Facil_OTA_Sync_Manager {
    public static function process_scheduled_sync() {
        $manager = new self();
        
        // Obtener todas las acomodaciones con sync habilitado
        $accommodations = get_posts( array(
            'post_type' => 'accommodation',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_af_sync_enabled',
                    'value' => 1,
                ),
            ),
        ) );
        
        // Sincronizar en batch
        foreach ( $accommodations as $accom ) {
            $manager->sync_accommodation( $accom->ID );
            // Pequeña pausa para respetar rate limits
            sleep( 1 );
        }
    }
}
```

### 3. Endpoint Webhook: `class-ota-webhook-handler.php`

```php
class Arriendo_Facil_OTA_Webhook_Handler {
    
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_webhook_routes' ) );
    }
    
    public function register_webhook_routes() {
        register_rest_route(
            'af/v1',
            '/ota/webhook/(?P<platform>booking|airbnb)',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_webhook' ),
                'permission_callback' => array( $this, 'verify_webhook_signature' ),
            )
        );
    }
    
    public function handle_webhook( WP_REST_Request $request ) {
        $platform = $request->get_param( 'platform' );
        $payload = $request->get_json_params();
        
        // Obtener el ID de propiedad del payload
        $property_id = $this->extract_property_id( $platform, $payload );
        
        // Buscar accommodation por property ID
        $accommodation_id = $this->find_accommodation_by_property_id( $platform, $property_id );
        
        if ( ! $accommodation_id ) {
            return new WP_REST_Response( array( 'success' => false ), 404 );
        }
        
        // Sincronizar
        $manager = new Arriendo_Facil_OTA_Sync_Manager();
        $result = $manager->sync_accommodation( $accommodation_id, array( $platform ) );
        
        return new WP_REST_Response( array( 'success' => true, 'result' => $result ) );
    }
    
    private function verify_webhook_signature( WP_REST_Request $request ) {
        $platform = $request->get_param( 'platform' );
        $signature = $request->get_header( 'X-Signature' ) ?? '';
        $body = $request->get_body();
        
        if ( 'booking' === $platform ) {
            $secret = get_option( 'af_booking_webhook_secret' );
            $expected = hash_hmac( 'sha256', $body, $secret );
            return hash_equals( $expected, $signature );
        }
        
        // Validación similar para Airbnb
        return false;
    }
}
```

**URL del Webhook:** `https://tu-sitio.com/wp-json/af/v1/ota/webhook/booking`

---

## UI/UX - INTERFAZ DE USUARIO

### 1. Sección en Meta Box de Acomodación

En `/admin/views/accommodation-meta-box.php`, nueva sección:

```html
<!-- SECCIÓN: Integraciones con OTAs -->
<div class="af-accom-section">
    <div class="af-accom-section__header">
        <span class="af-accom-section__icon">🌐</span>
        <h3 class="af-accom-section__title">Vincular con plataformas de alquiler</h3>
    </div>
    
    <div class="af-accom-section__body">
        
        <!-- Booking.com -->
        <div class="af-field">
            <label for="af_booking_property_id" class="af-field__label">
                ID de Propiedad Booking.com
            </label>
            <input 
                type="text" 
                id="af_booking_property_id"
                name="af_booking_property_id" 
                value="<?php echo esc_attr( $booking_property_id ); ?>" 
                placeholder="Ej: 123456"
                class="regular-text" 
            />
            <p class="description">
                Encontralo en tu panel de Booking: 
                <strong>Propiedades → Configuración → Código de propiedad</strong>
            </p>
        </div>
        
        <!-- Airbnb -->
        <div class="af-field">
            <label for="af_airbnb_listing_id" class="af-field__label">
                ID de Listing Airbnb
            </label>
            <input 
                type="text" 
                id="af_airbnb_listing_id"
                name="af_airbnb_listing_id" 
                value="<?php echo esc_attr( $airbnb_listing_id ); ?>" 
                placeholder="Ej: 987654"
                class="regular-text" 
            />
            <p class="description">
                Tu ID de listing está en la URL: 
                <strong>airbnb.com/rooms/[ID_AQUI]</strong>
            </p>
        </div>
        
        <!-- Sync Toggle -->
        <div class="af-field">
            <label class="af-checkbox-label">
                <input 
                    type="checkbox" 
                    name="af_sync_enabled" 
                    value="1" 
                    <?php checked( $sync_enabled, 1 ); ?> 
                />
                <span>Sincronizar disponibilidad automáticamente</span>
            </label>
            <p class="description">
                Si está activado, la disponibilidad en Booking/Airbnb se reflejará aquí cada 2 horas
            </p>
        </div>
        
        <!-- Last Sync Status -->
        <?php if ( $last_sync_timestamp ) : ?>
            <div class="af-field">
                <p class="description">
                    <strong>✓ Última sincronización:</strong> 
                    <?php echo wp_date( 'd/m/Y H:i', $last_sync_timestamp ); ?>
                </p>
            </div>
        <?php endif; ?>
        
        <!-- Manual Sync Button -->
        <div class="af-field">
            <button 
                type="button" 
                class="button button-secondary" 
                id="af-sync-ota-now"
                data-accommodation-id="<?php echo (int) $post_id; ?>"
            >
                Sincronizar Ahora
            </button>
            <span id="af-sync-status" class="description"></span>
        </div>
        
    </div>
</div>
```

### 2. Página de Configuración OTA

Nueva página: `/admin/views/ota-integrations-settings.php`

```html
<div class="wrap">
    <h1>Integraciones con Plataformas de Alquiler</h1>
    
    <p class="description">
        Configura tus credenciales de API para sincronizar disponibilidad automáticamente 
        entre Booking.com, Airbnb y ArriendoFacil.
    </p>
    
    <form method="POST" action="options.php">
        <?php settings_fields( 'af_ota_settings' ); ?>
        
        <!-- BOOKING.COM -->
        <table class="form-table">
            <h3>Booking.com</h3>
            
            <tr>
                <th scope="row">
                    <label for="af_booking_api_key">API Key</label>
                </th>
                <td>
                    <input 
                        type="password" 
                        id="af_booking_api_key" 
                        name="af_booking_api_key" 
                        value="<?php echo esc_attr( get_option( 'af_booking_api_key' ) ); ?>" 
                        class="regular-text" 
                        autocomplete="off" 
                    />
                    <p class="description">
                        Obtén tu API key en: 
                        <a href="https://extranet.booking.com" target="_blank">Extranet.booking.com</a>
                        → Ajustes → API
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="af_booking_partner_id">Partner ID</label>
                </th>
                <td>
                    <input 
                        type="text" 
                        id="af_booking_partner_id" 
                        name="af_booking_partner_id" 
                        value="<?php echo esc_attr( get_option( 'af_booking_partner_id' ) ); ?>" 
                        class="regular-text" 
                    />
                    <p class="description">
                        Tu ID de partner en Booking.com
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">&nbsp;</th>
                <td>
                    <button 
                        type="button" 
                        class="button" 
                        id="af-booking-test-connection"
                    >
                        Probar Conexión
                    </button>
                    <span id="af-booking-test-result"></span>
                </td>
            </tr>
        </table>
        
        <!-- AIRBNB -->
        <table class="form-table">
            <h3>Airbnb</h3>
            
            <tr>
                <th scope="row">
                    <label for="af_airbnb_api_key">Access Token</label>
                </th>
                <td>
                    <input 
                        type="password" 
                        id="af_airbnb_api_key" 
                        name="af_airbnb_api_key" 
                        value="<?php echo esc_attr( get_option( 'af_airbnb_api_key' ) ); ?>" 
                        class="regular-text" 
                        autocomplete="off" 
                    />
                    <p class="description">
                        Access token de 
                        <a href="https://www.airbnb.com/api" target="_blank">Airbnb API</a>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">&nbsp;</th>
                <td>
                    <button 
                        type="button" 
                        class="button" 
                        id="af-airbnb-test-connection"
                    >
                        Probar Conexión
                    </button>
                    <span id="af-airbnb-test-result"></span>
                </td>
            </tr>
        </table>
        
        <!-- CONFIGURACIÓN DE SINCRONIZACIÓN -->
        <table class="form-table">
            <h3>Configuración de Sincronización</h3>
            
            <tr>
                <th scope="row">
                    <label for="af_ota_sync_interval">Frecuencia de Sincronización</label>
                </th>
                <td>
                    <select id="af_ota_sync_interval" name="af_ota_sync_interval">
                        <option value="hourly" <?php selected( get_option( 'af_ota_sync_interval' ), 'hourly' ); ?>>
                            Cada hora
                        </option>
                        <option value="every_2_hours" <?php selected( get_option( 'af_ota_sync_interval' ), 'every_2_hours' ); ?>>
                            Cada 2 horas (recomendado)
                        </option>
                        <option value="every_4_hours" <?php selected( get_option( 'af_ota_sync_interval' ), 'every_4_hours' ); ?>>
                            Cada 4 horas
                        </option>
                        <option value="daily" <?php selected( get_option( 'af_ota_sync_interval' ), 'daily' ); ?>>
                            Diariamente
                        </option>
                    </select>
                    <p class="description">
                        Con qué frecuencia se sincronizará la disponibilidad automáticamente
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">&nbsp;</th>
                <td>
                    <label>
                        <input 
                            type="checkbox" 
                            name="af_ota_enable_webhooks" 
                            value="1"
                            <?php checked( get_option( 'af_ota_enable_webhooks' ), 1 ); ?> 
                        />
                        Usar webhooks si están disponibles (más rápido)
                    </label>
                    <p class="description">
                        Si está activado, Booking/Airbnb notificarán cambios en tiempo real.
                        <br />Requiere configurar webhooks en las plataformas manualmente.
                    </p>
                </td>
            </tr>
        </table>
        
        <?php submit_button( 'Guardar Configuración' ); ?>
    </form>
    
    <!-- INFORMACIÓN DE WEBHOOKS -->
    <div class="notice notice-info">
        <p>
            <strong>URLs de Webhooks (para configurar en las plataformas):</strong>
            <ul>
                <li><code>https://tu-sitio.com/wp-json/af/v1/ota/webhook/booking</code></li>
                <li><code>https://tu-sitio.com/wp-json/af/v1/ota/webhook/airbnb</code></li>
            </ul>
        </p>
    </div>
</div>
```

### 3. Agregar Submenu en Admin

En `/admin/class-admin.php`:

```php
public function register_admin_menus() {
    add_submenu_page(
        'arriendo-facil',
        __( 'Integraciones OTA', 'arriendo-facil' ),
        __( 'Integraciones OTA', 'arriendo-facil' ),
        'manage_options',
        'af-ota-integrations',
        array( $this, 'render_ota_integrations_page' )
    );
}

public function render_ota_integrations_page() {
    include ARRIENDO_FACIL_PLUGIN_DIR . 'admin/views/ota-integrations-settings.php';
}
```

### 4. JavaScript para AJAX Sync Manual

En `/assets/js/admin-ota-integrations.js`:

```javascript
(function($) {
    $(document).ready(function() {
        // Sincronización manual en meta box
        $('#af-sync-ota-now').on('click', function(e) {
            e.preventDefault();
            
            const accommodationId = $(this).data('accommodation-id');
            const statusEl = $('#af-sync-status');
            
            statusEl.text('Sincronizando...').css('color', '#666');
            
            $.post(
                afAdmin.ajaxUrl,
                {
                    action: 'af_sync_accommodation_manual',
                    accommodation_id: accommodationId,
                    nonce: afAdmin.syncNonce
                },
                function(response) {
                    if (response.success) {
                        statusEl
                            .text('✓ Sincronizado: ' + response.data.timestamp)
                            .css('color', '#28a745');
                    } else {
                        statusEl
                            .text('✗ Error: ' + (response.data.message || 'Unknown error'))
                            .css('color', '#dc3545');
                    }
                }
            );
        });
        
        // Test de conexión Booking
        $('#af-booking-test-connection').on('click', function(e) {
            e.preventDefault();
            
            const resultEl = $('#af-booking-test-result');
            resultEl.text('Probando...').css('color', '#666');
            
            $.post(
                afAdmin.ajaxUrl,
                {
                    action: 'af_test_booking_connection',
                    nonce: afAdmin.settingsNonce
                },
                function(response) {
                    if (response.success) {
                        resultEl
                            .text('✓ Conexión exitosa')
                            .css('color', '#28a745');
                    } else {
                        resultEl
                            .text('✗ Error: ' + (response.data.message || 'Connection failed'))
                            .css('color', '#dc3545');
                    }
                }
            );
        });
        
        // Test de conexión Airbnb
        $('#af-airbnb-test-connection').on('click', function(e) {
            e.preventDefault();
            
            const resultEl = $('#af-airbnb-test-result');
            resultEl.text('Probando...').css('color', '#666');
            
            $.post(
                afAdmin.ajaxUrl,
                {
                    action: 'af_test_airbnb_connection',
                    nonce: afAdmin.settingsNonce
                },
                function(response) {
                    if (response.success) {
                        resultEl
                            .text('✓ Conexión exitosa')
                            .css('color', '#28a745');
                    } else {
                        resultEl
                            .text('✗ Error: ' + (response.data.message || 'Connection failed'))
                            .css('color', '#dc3545');
                    }
                }
            );
        });
    });
})(jQuery);
```

---

## GESTIÓN DE ERRORES Y CONCURRENCIA

### 1. Error Handling Centralizado

```php
class Arriendo_Facil_OTA_Sync_Manager {
    
    private function handle_sync_error( $accommodation_id, $source, Exception $e ) {
        // Datos del error
        $error_data = array(
            'type' => get_class( $e ),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'timestamp' => current_time( 'mysql' ),
            'retry_count' => (int) get_post_meta( $accommodation_id, "_af_{$source}_retry_count", true ),
        );
        
        // Guardar error en meta
        update_post_meta( $accommodation_id, "_af_{$source}_last_error", wp_json_encode( $error_data ) );
        
        // Log en tabla
        $this->log_sync( $accommodation_id, $source, array(), 'failed', $e );
        
        // Retry automático (máximo 3 reintentos)
        if ( $error_data['retry_count'] < 3 ) {
            wp_schedule_single_event( 
                time() + HOUR_IN_SECONDS, 
                'af_retry_ota_sync', 
                array( $accommodation_id, $source ) 
            );
            update_post_meta( $accommodation_id, "_af_{$source}_retry_count", $error_data['retry_count'] + 1 );
        } else {
            // Notificar al propietario después de 3 reintentos fallidos
            do_action( 'af_ota_sync_failed_critical', $accommodation_id, $source, $error_data );
        }
    }
}
```

### 2. Prevenir Race Conditions (Locks)

```php
class Arriendo_Facil_OTA_Sync_Manager {
    
    /**
     * Adquiere un lock para evitar 2 syncs simultáneos de la misma acomodación.
     * Usa transients (atómicos).
     */
    private function acquire_sync_lock( $accommodation_id, $timeout = 300 ) {
        $lock_key = "af_sync_lock_{$accommodation_id}";
        
        // Si el transient no existe, lo crea (atómico)
        if ( ! get_transient( $lock_key ) ) {
            set_transient( $lock_key, 1, $timeout );
            return true;
        }
        
        return false; // Ya está sincronizando
    }
    
    /**
     * Libera el lock.
     */
    private function release_sync_lock( $accommodation_id ) {
        delete_transient( "af_sync_lock_{$accommodation_id}" );
    }
    
    /**
     * Sincronización con lock.
     */
    public function sync_accommodation( $accommodation_id, $sources = array() ) {
        if ( ! $this->acquire_sync_lock( $accommodation_id ) ) {
            return new WP_Error( 'sync_in_progress', 'Ya está sincronizando esta acomodación' );
        }
        
        try {
            // ... lógica de sincronización ...
            
            $this->reconcile_occupancy( $accommodation_id, $sources );
            return array( 'success' => true );
            
        } catch ( Exception $e ) {
            $this->handle_sync_error( $accommodation_id, $sources[0], $e );
            return new WP_Error( 'sync_failed', $e->getMessage() );
            
        } finally {
            $this->release_sync_lock( $accommodation_id );
        }
    }
}
```

### 3. Logging Detallado en Base de Datos

```php
private function log_sync( $accommodation_id, $source, $remote_status, $status = 'success', $error = null ) {
    global $wpdb;
    
    $local_occupied = Arriendo_Facil_Accommodation_Occupied_Admin::is_occupied( $accommodation_id );
    
    $wpdb->insert(
        $wpdb->prefix . 'af_otas_sync_log',
        array(
            'accommodation_id'   => $accommodation_id,
            'ota_source'         => $source,
            'sync_type'          => 'availability',
            'remote_property_id' => $remote_status['property_id'] ?? '',
            'status'             => $status,
            'local_was_occupied' => (int) $local_occupied,
            'remote_is_occupied' => (int) $remote_status['is_occupied'] ?? 0,
            'remote_booked_dates' => wp_json_encode( $remote_status['booked_dates'] ?? array() ),
            'error_message'      => $error ? $error->getMessage() : null,
            'request_payload'    => wp_json_encode( $remote_status['_request'] ?? array() ),
            'response_payload'   => wp_json_encode( $remote_status['_response'] ?? array() ),
            'created_at'         => current_time( 'mysql' ),
        ),
        array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
    );
    
    // Actualizar timestamp en acomodación
    update_post_meta( $accommodation_id, '_af_last_sync_timestamp', time() );
}
```

---

## SEGURIDAD

### 1. Encriptación de Credenciales

```php
class Arriendo_Facil_OTA_Credentials {
    
    /**
     * Guarda una credencial encriptada para un propietario y plataforma.
     */
    public static function save_encrypted( $owner_id, $platform, $api_key, $account_id ) {
        $encrypted = self::encrypt( $api_key );
        
        global $wpdb;
        $wpdb->replace(
            $wpdb->prefix . 'af_ota_credentials',
            array(
                'owner_id'             => $owner_id,
                'ota_platform'         => $platform,
                'api_key_encrypted'    => $encrypted,
                'account_identifier'   => $account_id,
                'connected'            => 0, // Será 1 después de validar
                'updated_at'           => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%d', '%s' )
        );
    }
    
    /**
     * Obtiene una credencial desencriptada.
     */
    public static function get_decrypted( $owner_id, $platform ) {
        global $wpdb;
        
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT api_key_encrypted, account_identifier FROM $wpdb->prefix" . "af_ota_credentials 
             WHERE owner_id = %d AND ota_platform = %s",
            $owner_id,
            $platform
        ) );
        
        if ( ! $row ) {
            return null;
        }
        
        return array(
            'api_key' => self::decrypt( $row->api_key_encrypted ),
            'account_id' => $row->account_identifier,
        );
    }
    
    /**
     * Encripta usando Sodium (PHP 7.2+) con fallback a wp_hash.
     */
    private static function encrypt( $data ) {
        if ( function_exists( 'sodium_crypto_secretbox' ) ) {
            $key = pack( 'H*', hash( 'sha256', SECURE_AUTH_KEY . SECURE_AUTH_SALT ) );
            $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $encrypted = sodium_crypto_secretbox( $data, $nonce );
            
            return base64_encode( $nonce . $encrypted );
        }
        
        // Fallback a wp_hash (menos seguro, pero mejor que nada)
        return wp_hash( $data );
    }
    
    /**
     * Desencripta.
     */
    private static function decrypt( $data ) {
        if ( function_exists( 'sodium_crypto_secretbox_open' ) ) {
            $key = pack( 'H*', hash( 'sha256', SECURE_AUTH_KEY . SECURE_AUTH_SALT ) );
            $data = base64_decode( $data, true );
            
            if ( false === $data || strlen( $data ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
                return false;
            }
            
            $nonce = substr( $data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $encrypted = substr( $data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            
            return sodium_crypto_secretbox_open( $encrypted, $nonce, $key );
        }
        
        return false;
    }
}
```

### 2. Validación de Nonces y Permisos

```php
// En settings page - agregar field hidden
wp_nonce_field( 'af_ota_settings_nonce', 'af_ota_nonce' );

// En AJAX handler
public function handle_manual_sync() {
    check_ajax_referer( 'af_sync_nonce', 'nonce' );
    
    // Validar usuario
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
    }
    
    $accommodation_id = absint( $_POST['accommodation_id'] );
    
    // Validar propietario
    $owner_id = get_post_meta( $accommodation_id, '_af_owner_id', true );
    if ( ! current_user_can( 'manage_options' ) && get_current_user_id() !== $owner_id ) {
        wp_send_json_error( array( 'message' => 'No permission for this accommodation' ), 403 );
    }
    
    // ... continuar ...
}
```

### 3. Rate Limiting

```php
class Arriendo_Facil_OTA_API_Client_Base {
    
    /**
     * Respeta rate limiting antes de hacer request.
     */
    protected function respect_rate_limit( $platform ) {
        $rate_limit_key = "af_ota_rate_limit_{$platform}";
        $current_count = (int) get_transient( $rate_limit_key );
        
        if ( $current_count >= 100 ) { // 100 requests por minuto
            throw new Exception( "Rate limit exceeded for $platform. Try again later." );
        }
        
        set_transient( $rate_limit_key, $current_count + 1, MINUTE_IN_SECONDS );
    }
}
```

### 4. Validación de Webhook Signature

```php
class Arriendo_Facil_OTA_Webhook_Handler {
    
    private function verify_webhook_signature( WP_REST_Request $request ) {
        $platform = $request->get_param( 'platform' );
        $signature = $request->get_header( 'X-Signature' ) ?? '';
        $body = $request->get_body();
        
        if ( 'booking' === $platform ) {
            $secret = get_option( 'af_booking_webhook_secret' );
            if ( ! $secret ) {
                return false;
            }
            
            $expected = hash_hmac( 'sha256', $body, $secret );
            return hash_equals( $expected, $signature );
        }
        
        if ( 'airbnb' === $platform ) {
            $secret = get_option( 'af_airbnb_webhook_secret' );
            if ( ! $secret ) {
                return false;
            }
            
            // Airbnb usa X-Airbnb-Signature
            $expected = hash_hmac( 'sha256', $body, $secret );
            return hash_equals( $expected, $signature );
        }
        
        return false;
    }
}
```

---

## PERFORMANCE

### 1. Batch Processing

```php
class Arriendo_Facil_OTA_Sync_Manager {
    
    /**
     * Sincroniza múltiples acomodaciones evitando hammering de APIs.
     */
    public function sync_batch( $accommodation_ids, $platform = 'all', $delay_between_requests = 1 ) {
        if ( ! is_array( $accommodation_ids ) ) {
            $accommodation_ids = array( $accommodation_ids );
        }
        
        $platforms = 'all' === $platform ? array( 'booking', 'airbnb' ) : array( $platform );
        $results = array();
        
        foreach ( $accommodation_ids as $accom_id ) {
            foreach ( $platforms as $plat ) {
                try {
                    $this->sync_accommodation( $accom_id, array( $plat ) );
                    $results[ $accom_id ][ $plat ] = 'success';
                } catch ( Exception $e ) {
                    $results[ $accom_id ][ $plat ] = 'error: ' . $e->getMessage();
                }
                
                // Delay para respetar rate limits
                sleep( $delay_between_requests );
            }
        }
        
        return $results;
    }
}
```

### 2. Caché de Disponibilidad

```php
// En OTA API Client
public function get_availability( $property_id, $date_from, $date_to, $use_cache = true ) {
    $cache_key = "af_availability_{$property_id}_" . md5( "{$date_from}_{$date_to}" );
    
    if ( $use_cache ) {
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }
    }
    
    // Obtener de API
    $availability = $this->request( "/availabilities", 'GET', array(
        'property_id' => $property_id,
        'date_from' => $date_from,
        'date_to' => $date_to,
    ) );
    
    // Cachear por 30 minutos
    set_transient( $cache_key, $availability, 30 * MINUTE_IN_SECONDS );
    
    return $availability;
}
```

### 3. Lazy Loading en Admin

```php
// En meta box - cargar status de sync vía AJAX
?>
<div id="af-sync-status-container">
    <p class="description">Cargando...</p>
</div>

<script>
jQuery(document).ready(function($) {
    if (afWizard.postId) {
        $.get(
            '/wp-json/af/v1/accommodation/' + afWizard.postId + '/ota-status',
            function(data) {
                $('#af-sync-status-container').html(
                    '<p class="description"><strong>✓ Última sincronización:</strong> ' + 
                    data.last_sync_human + '</p>'
                );
            }
        );
    }
});
</script>
```

---

## PLAN DE IMPLEMENTACIÓN ORDENADO (SIN BREAKING CHANGES)

### FASE 1: Base de Datos e Infraestructura (Semana 1-2)

**Tareas:**
1. ✅ Crear tablas `wp_af_otas_sync_log` y `wp_af_ota_credentials` en `class-activator.php`
2. ✅ Agregar migrations script para instalaciones existentes
3. ✅ Crear clase base `class-ota-api-client-base.php`
4. ✅ Agregar nuevos metadatos a `class-accommodation.php`:
   - `_af_booking_property_id`
   - `_af_airbnb_listing_id`
   - `_af_sync_enabled`
   - `_af_last_sync_timestamp`
5. ✅ Crear `class-ota-credentials.php` para encriptación

**Archivos a crear:**
- `/includes/class-ota-api-client-base.php`
- `/includes/class-ota-credentials.php`
- `/includes/class-ota-sync-manager.php`

**Archivos a modificar:**
- `/includes/class-activator.php` (agregar tablas)
- `/includes/class-accommodation.php` (agregar metadatos)

### FASE 2: Integraciones API (Semana 3-4)

**Tareas:**
1. ✅ Implementar `class-booking-api-client.php`
   - Endpoints: properties, availabilities, reservations
   - Retry logic y rate limiting
   - Tests con datos mock
2. ✅ Implementar `class-airbnb-api-client.php`
   - Endpoints básicos
   - Validación de credenciales
   - Tests
3. ✅ Crear `class-ota-webhook-handler.php`
   - Registrar endpoints REST
   - Validación de signatures

**Archivos a crear:**
- `/includes/class-booking-api-client.php`
- `/includes/class-airbnb-api-client.php`
- `/includes/class-ota-webhook-handler.php`

**Tests:**
- `/tests/OTA_Booking_ClientTest.php`
- `/tests/OTA_Airbnb_ClientTest.php`
- `/tests/OTA_Sync_ManagerTest.php`

### FASE 3: Interfaz de Usuario (Semana 5)

**Tareas:**
1. ✅ Agregar campos OTA a meta box de acomodación
   - IDs de propiedades (Booking/Airbnb)
   - Toggle de sync
   - Botón de sync manual
2. ✅ Crear página de Configuración OTA
   - Campos para API keys
   - Selectores de frecuencia
   - Botones de test de conexión
3. ✅ Agregar submenu en admin

**Archivos a crear:**
- `/admin/views/ota-integrations-settings.php`
- `/assets/js/admin-ota-integrations.js`
- `/assets/css/admin-ota-integrations.css`

**Archivos a modificar:**
- `/admin/views/accommodation-meta-box.php` (agregar sección)
- `/admin/class-admin.php` (agregar submenu)

### FASE 4: Automatización con WP-Cron (Semana 6)

**Tareas:**
1. ✅ Registrar WP-Cron hook en `arriendo-facil.php`
   - Intervalo configurable (default: every 2 hours)
   - Batch processing de acomodaciones
2. ✅ Crear static method para procesar syncs programados
3. ✅ Agregar AJAX endpoint para manual sync
4. ✅ Crear handler para retry automático

**Archivos a modificar:**
- `/arriendo-facil.php` (agregar cron)
- `/includes/class-ota-sync-manager.php` (static method)
- `/admin/class-admin.php` (AJAX handlers)

### FASE 5: Polish y Testing (Semana 7)

**Tareas:**
1. ✅ Tests E2E de todo el flujo
2. ✅ Agregar notificaciones al propietario (email/admin notice)
3. ✅ Dashboard de status de syncs (admin)
4. ✅ Documentación y video tutorial
5. ✅ Performance profiling
6. ✅ Security audit

**Archivos a crear:**
- `/admin/views/ota-sync-dashboard.php`
- `/includes/class-ota-notification.php`
- Documentation in `/docs/OTA_INTEGRATION.md`

---

## RIESGOS Y MITIGACIONES

| # | Riesgo | Severidad | Mitigación |
|---|--------|-----------|-----------|
| 1 | API downtime de Booking/Airbnb | ALTA | Exponential backoff, retry logic, notificación al propietario. Nunca bloquear UI. |
| 2 | Rate limiting de APIs | MEDIA | Queue con delays, batch processing, respetar headers Rate-Limit. |
| 3 | Desincronización entre plataformas | ALTA | Sync cada 2h, webhooks, resolver conflictos favoreciendo "ocupado" (más seguro). |
| 4 | Credenciales expuestas | CRÍTICA | Encriptación Sodium, DB storage (no meta), constants en wp-config. |
| 5 | Race conditions (sync simultáneo) | MEDIA | Transient locks, validar timestamps. |
| 6 | Webhook spoofing | ALTA | Validar firma HMAC, validar IP origen si es posible. |
| 7 | Saturación con muchos owners | MEDIA | Queue asincrónica, WP-Cron, batch, limit 1 sync/accom cada 30min. |
| 8 | Datos inconsistentes post-crash | MEDIA | Siempre logear antes de aplicar cambios, usar transactions, rollback plan. |
| 9 | Acomodación aparece disponible remotamente pero ocupada localmente | ALTA | Dashboard de alertas, notificación al propietario, opción de sincronizar manualmente. |
| 10 | Breaking changes en APIs de Booking/Airbnb | MEDIA | Versionar clients, deprecation warnings, tests de regresión. |

---

## CONFIGURACIÓN RECOMENDADA

### En `wp-config.php`

```php
// OTA Integration settings
define( 'AF_OTA_BOOKING_ENABLED', true );
define( 'AF_OTA_AIRBNB_ENABLED', true );
define( 'AF_OTA_SYNC_INTERVAL', 'every_2_hours' );
define( 'AF_OTA_ENABLE_WEBHOOKS', true );
define( 'AF_OTA_AUTO_MARK_OCCUPIED', true );
define( 'AF_OTA_WEBHOOK_TIMEOUT', 30 );

// Seguridad
define( 'AF_OTA_CREDENTIALS_ENCRYPTION_KEY', SECURE_AUTH_KEY );
```

### En Settings de WordPress

```php
// Credenciales de APIs (encriptadas)
af_booking_api_key
af_booking_partner_id
af_airbnb_api_key

// Configuración
af_ota_sync_interval
af_ota_enable_webhooks
af_ota_auto_mark_occupied
af_ota_webhook_secret_booking
af_ota_webhook_secret_airbnb
```

---

## NOTAS FINALES

### Retrocompatibilidad
- ✅ Campos meta opcionales (no requeridos)
- ✅ Sync desactivado por default
- ✅ No afecta acomodaciones sin credenciales
- ✅ Propiedades existentes funcionan igual

### Escalabilidad
- ✅ Diseño soporta múltiples plataformas (Vrbo, Expedia, etc.)
- ✅ Batch processing para N acomodaciones
- ✅ Caché de disponibilidad
- ✅ Logging centralizado para análisis

### Monitoreo
- Dashboard widget con status de syncs
- Alertas por errores críticos
- Logs detallados en `wp_af_otas_sync_log`
- Email notifications al propietario

