# Arriendo Fácil

A WordPress plugin that manages accommodations and provides a complete rental-management platform including:

- **Accommodation management** – Custom Post Type with address, bedrooms, bathrooms, monthly rent, owner, and availability status.
- **Cleaning services** – Define cleaning service types (standard, deep-clean, move-in/out) and track cleaning requests with statuses (*pending → in_progress → completed*).
- **Lease management** – Create and manage rental contracts linked to accommodations and guests, with lifecycle statuses (*draft → active → expired/terminated*).
- **Owner contact** – Send and track messages directed to property owners, with email notification on receipt.
- **Guest management** – Maintain guest profiles including identity data, with AI-powered scoring.
- **AI-powered features**
  - **Cost prediction** – Predict the recommended monthly rental cost for an accommodation.
  - **Document generation** – Auto-generate lease documents using AI and attach them to lease records.
  - **Guest scoring** – Score a guest's suitability and generate a plain-language summary.

## Requirements

- WordPress 5.9+
- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.3+

## Installation

1. Upload the `arriendo-facil` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Arriendo Fácil → AI Settings** and configure the AI API URL and key.

## Plugin Structure

```
arriendo-facil.php            Main plugin file (bootstrap)
includes/
  class-activator.php         Activation / deactivation (DB setup)
  class-accommodation.php     Accommodation CPT
  class-cleaning-service.php  Cleaning service CPT + request management
  class-lease.php             Lease management
  class-owner-contact.php     Owner contact management
  class-guest.php             Guest management + AI scoring
  class-ai-service.php        AI service integration
admin/
  class-admin.php             Admin menus and AJAX handlers
  views/
    dashboard.php             Summary dashboard
    leases.php                Lease list + document generation
    cleaning-requests.php     Cleaning request list
    owner-contacts.php        Owner contact list
    guests.php                Guest list + AI scoring
    ai-settings.php           AI API configuration
    accommodation-meta-box.php
    cleaning-service-meta-box.php
assets/
  css/admin.css               Admin stylesheet
  js/admin.js                 Admin JavaScript (jQuery)
tests/
  bootstrap.php               PHPUnit bootstrap (WordPress stubs)
  AIServiceTest.php           Unit tests for AI service
```

## Running Tests

```bash
composer install
./vendor/bin/phpunit
```

## AI Integration

The plugin communicates with an external AI API (configurable via **Arriendo Fácil → AI Settings**). The API must accept `POST` requests with a JSON body:

```json
{
  "action": "predict_cost | generate_document | score_guest",
  "data": { ... }
}
```

Expected responses:

| Action              | Response key(s)                      |
|---------------------|--------------------------------------|
| `predict_cost`      | `predicted_cost`                     |
| `generate_document` | `document_url`                       |
| `score_guest`       | `score`, `summary`                   |

All AI interactions are logged in the `wp_af_ai_logs` database table.

## Database Tables

| Table                        | Purpose                          |
|------------------------------|----------------------------------|
| `wp_af_leases`               | Lease records                    |
| `wp_af_cleaning_requests`    | Cleaning request records         |
| `wp_af_owner_contacts`       | Owner contact messages           |
| `wp_af_ai_logs`              | AI action audit log              |
| `wp_af_guests`               | Guest profiles                   |

## License

GPL-2.0-or-later
