# 🧪 Staging Validation Test Suite
**Date**: 2026-10-07  
**Phase**: 3.1 Staging Environment Testing  
**Duration**: 2-3 days

---

## Pre-Staging Checklist

Before deploying to staging, verify:

- [ ] Staging WordPress is clean (fresh install or backup restored)
- [ ] Database is replicated from production (anonymized)
- [ ] .env file configured for staging (test credentials)
- [ ] SSL certificates valid for staging domain
- [ ] Backup strategy in place
- [ ] Monitoring/alerts configured for staging
- [ ] Team access configured

---

## Stage 1: Critical Path Testing (Day 1)

### 1.1 Plugin Installation & Activation
```bash
# Steps:
1. Upload arriendo-facil.zip to staging
2. Extract to wp-content/plugins/
3. Activate plugin in WordPress admin
4. Verify no fatal errors in error log
5. Check WP_DEBUG_LOG for warnings
```

**Acceptance Criteria**:
- ✅ Plugin activates without errors
- ✅ Database tables created (wp_af_*)
- ✅ Admin menu visible
- ✅ No white screen of death
- ✅ WP_DEBUG_LOG clean

**Test Script**:
```php
<?php
// In staging WordPress
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '/var/log/wordpress-debug.log' );

// Check plugin is loaded
if ( ! function_exists( 'get_arriendo_facil' ) ) {
    wp_die( 'Plugin not loaded' );
}

// Check tables exist
global $wpdb;
$tables = array(
    'af_accommodations',
    'af_leases',
    'af_guests',
    'af_billing_ledger',
    'af_security_logs'
);

foreach ( $tables as $table ) {
    if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}{$table}'" ) ) {
        wp_die( "Table missing: {$table}" );
    }
}

echo 'Plugin installation: OK';
?>
```

### 1.2 Core Functionality Tests

**AJAX Endpoints**:
- [ ] Issue Invoice endpoint
- [ ] Download Invoice endpoint
- [ ] Add Visit endpoint
- [ ] Billing API endpoints
- [ ] Calendar API endpoints

**Test Method**:
```bash
# Test each AJAX endpoint
curl -X POST https://staging.example.com/wp-admin/admin-ajax.php \
  -d "action=af_issue_invoice&nonce=VALID_NONCE&lease_id=123" \
  -H "Cookie: wordpress_logged_in=..." \
  -v
```

**Expected**: 200 OK, JSON response, no 403/429 errors (unless testing rate limit)

### 1.3 Rate Limiting Tests

**Login Rate Limiting**:
```bash
# Test 1: Normal login (should work)
curl -X POST https://staging.example.com/wp-login.php \
  -d "log=admin&pwd=password&wp-submit=Log+In" \
  -c cookies.txt

# Test 2: 11 failed logins (should block on 11th)
for i in {1..12}; do
  curl -X POST https://staging.example.com/wp-login.php \
    -d "log=admin&pwd=wrongpassword&wp-submit=Log+In"
  echo "Attempt $i"
done

# Expected: Attempts 1-10 show login form, attempt 11+ shows rate limit error
```

**API Rate Limiting**:
```bash
# Test: Issue invoices until rate limited
for i in {1..6}; do
  curl -X POST https://staging.example.com/wp-admin/admin-ajax.php \
    -d "action=af_issue_invoice&nonce=VALID&lease_id=123" \
    -H "Cookie: ..." \
    -w "Status: %{http_code}\n"
  echo "Invoice $i"
  sleep 1
done

# Expected: Requests 1-5 work, request 6 gets 429 Too Many Requests
```

**Acceptance Criteria**:
- ✅ Login blocks after 10 failed attempts
- ✅ Invoice creation blocks after 5 per hour
- ✅ Download blocks after 20 per hour
- ✅ Visit creation blocks after 30 per day
- ✅ Rate limit headers present (X-RateLimit-*)

### 1.4 CSRF Protection Verification

**Test Method**:
```php
<?php
// Attempt AJAX without nonce (should fail)
$response = wp_remote_post( 'https://staging.example.com/wp-admin/admin-ajax.php', array(
    'body' => array(
        'action' => 'af_issue_invoice',
        // Missing 'nonce' parameter
        'lease_id' => 123
    ),
    'sslverify' => false
) );

// Expected: 403 Forbidden or error message
if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
    echo 'CSRF protection: OK';
} else {
    echo 'CSRF protection: FAILED - endpoint accepted request without nonce';
}
?>
```

**Acceptance Criteria**:
- ✅ All AJAX endpoints require nonce
- ✅ Invalid nonce returns 403
- ✅ No data modification without nonce
- ✅ All POST requests protected

---

## Stage 2: Security Features Testing (Day 1-2)

### 2.1 Authentication & Authorization

**Test Cases**:
1. Guest user cannot access admin functions
   ```bash
   curl -X POST https://staging.example.com/wp-admin/admin-ajax.php \
     -d "action=af_issue_invoice&nonce=VALID&lease_id=123"
   # Expected: 403 Forbidden
   ```

2. Wrong capability user blocked
   ```php
   // Log in as subscriber (no edit_posts)
   // Try to issue invoice
   // Expected: Permission denied error
   ```

3. Multi-role access control
   ```php
   // Test combinations:
   // - af_property_admin + edit_posts
   // - af_guest_tenant + no edit_posts
   // - administrator (all access)
   // - subscriber (no access)
   ```

**Acceptance Criteria**:
- ✅ Role-based access control working
- ✅ Capability checks in place
- ✅ No privilege escalation possible
- ✅ Cross-tenant isolation verified

### 2.2 Input Validation

**Test Cases**:
1. SQL injection attempt
   ```bash
   curl -X POST https://staging.example.com/wp-admin/admin-ajax.php \
     -d "action=af_billing_lease_search&search=1' OR '1'='1&nonce=VALID" \
     -H "Cookie: ..."
   # Expected: Safe error message, no SQL injection
   ```

2. XSS attempt in form
   ```bash
   curl -X POST https://staging.example.com/wp-admin/admin-ajax.php \
     -d "action=af_save_accommodation_meta&name=<script>alert('xss')</script>&nonce=VALID" \
     -H "Cookie: ..."
   # Expected: Script sanitized, no XSS
   ```

3. Path traversal attempt
   ```bash
   curl -X GET "https://staging.example.com/wp-admin/admin-ajax.php?action=af_download_ride&file=../../../../etc/passwd&nonce=VALID"
   # Expected: File not found or access denied
   ```

4. XXE injection attempt
   ```bash
   # Upload XML with malicious entity
   # Expected: Safely parsed or rejected
   ```

**Acceptance Criteria**:
- ✅ All inputs sanitized
- ✅ No SQL injection possible
- ✅ No XSS vulnerabilities
- ✅ No path traversal possible
- ✅ No XXE exploitation possible

### 2.3 Data Protection & Logging

**Test Cases**:
1. Sensitive data not exposed
   ```bash
   # Check response bodies don't contain:
   # - Passwords
   # - API keys
   # - Database credentials
   # - PII (phone, email in clear)
   ```

2. Secure logging
   ```bash
   # Check logs don't contain:
   # - Passwords
   # - API keys
   # - Credit card numbers
   # - Full SSN
   # Verify email/phone masked
   ```

3. Encrypted field verification
   ```php
   // Query database
   SELECT * FROM wp_af_guests WHERE email = ?
   // Expected: Email encrypted/hashed in storage
   ```

**Acceptance Criteria**:
- ✅ Sensitive data never in responses
- ✅ Logs properly redacted
- ✅ Data encrypted at rest
- ✅ No PII leakage

---

## Stage 3: Performance Testing (Day 2)

### 3.1 Response Time Benchmarks

**Test Endpoints**:
```bash
# Measure response time for key endpoints
endpoints=(
  "https://staging.example.com/wp-admin/admin-ajax.php?action=af_calendar_events"
  "https://staging.example.com/wp-admin/admin-ajax.php?action=af_accommodation_search"
  "https://staging.example.com/wp-admin/admin-ajax.php?action=af_billing_lease_search"
)

for endpoint in "${endpoints[@]}"; do
  time curl -s "$endpoint" -H "Cookie: wordpress_logged_in=..." > /dev/null
done

# Expected: p50 < 200ms, p95 < 500ms, p99 < 1000ms
```

**Acceptance Criteria**:
- ✅ p50 response time < 200ms
- ✅ p95 response time < 500ms
- ✅ p99 response time < 1000ms
- ✅ No queries > 5 seconds

### 3.2 Database Performance

```php
<?php
// Check slow queries
global $wpdb;
$wpdb->show_errors();

// Time complex query
$start = microtime( true );
$results = $wpdb->get_results( 
    $wpdb->prepare( 
        "SELECT l.*, g.* FROM {$wpdb->prefix}af_leases l 
         LEFT JOIN {$wpdb->prefix}af_guests g ON l.guest_id = g.id 
         WHERE l.accommodation_id = %d LIMIT 100",
        123
    )
);
$duration = microtime( true ) - $start;

echo "Query time: " . round( $duration * 1000, 2 ) . "ms";
// Expected: < 100ms
?>
```

**Acceptance Criteria**:
- ✅ No N+1 query problems
- ✅ Indexes used correctly
- ✅ Join queries < 100ms
- ✅ Full-text search < 500ms

### 3.3 Load Testing

```bash
# Use Apache Bench to simulate 100 concurrent users
ab -n 1000 -c 100 \
  -H "Cookie: wordpress_logged_in=..." \
  https://staging.example.com/wp-admin/admin-ajax.php?action=af_calendar_events

# Expected output:
# - Requests per second: > 100
# - Failed requests: 0
# - Average response time: < 500ms
# - No rate limit false positives
```

**Acceptance Criteria**:
- ✅ Handles 100 concurrent users
- ✅ No memory leaks
- ✅ No connection pool exhaustion
- ✅ Rate limiting accurate

---

## Stage 4: Integration Testing (Day 2-3)

### 4.1 Third-Party API Integration

**Stripe Integration**:
```bash
# Test payment processing
1. Create test charge via Stripe API
2. Verify webhook received
3. Check billing ledger updated
4. Verify rate limiting on payment endpoint
```

**SRI Integration (Ecuador)**:
```bash
# Test invoice signing
1. Generate test invoice
2. Verify SRI signature
3. Test with test certificate
4. Verify error handling for invalid cert
```

**Email Integration**:
```bash
# Test SendGrid sending
1. Trigger invoice email
2. Verify email received
3. Check attachment included
4. Test templating
```

**Acceptance Criteria**:
- ✅ Stripe payments work
- ✅ SRI signatures valid
- ✅ Emails deliver
- ✅ Webhook handling correct
- ✅ Error handling graceful

### 4.2 Mobile App Integration

**iOS**:
```bash
# Test against staging API
1. Log in to staging
2. Verify endpoints return expected JSON
3. Test rate limiting behavior
4. Check error messages
5. Verify SSL certificate accepted
```

**Android**:
```bash
# Same as iOS
1. Log in to staging
2. Verify API compatibility
3. Test on multiple API levels (21, 23, 29, 33)
4. Check certificate pinning (if implemented)
```

**Acceptance Criteria**:
- ✅ iOS app connects to staging
- ✅ Android app connects to staging
- ✅ API responses correct format
- ✅ Rate limiting respected
- ✅ Error handling working

---

## Stage 5: Error Handling & Recovery (Day 3)

### 5.1 Error Scenarios

**Database Connection Failure**:
```bash
# Simulate by stopping database
1. Kill database connection
2. Try to issue invoice
3. Expected: Graceful error message (not SQL error)
4. Resume database
5. Verify recovery works
```

**API Timeout**:
```bash
# Simulate slow SRI response
1. Set timeout to 1 second
2. Call SRI API
3. Expected: Timeout error, not hang
4. Verify logs show timeout
```

**File System Error**:
```bash
# Simulate upload directory not writable
1. Remove write permissions on upload dir
2. Try to upload document
3. Expected: Permission error message
4. Restore permissions
5. Verify recovery
```

**Acceptance Criteria**:
- ✅ All errors handled gracefully
- ✅ No exception stack traces shown
- ✅ User-friendly messages
- ✅ Errors logged
- ✅ Recovery possible

### 5.2 Rollback Testing

```bash
# Test rollback procedure
1. Note current plugin version
2. Deactivate plugin
3. Replace with previous version
4. Activate previous version
5. Verify data integrity
6. Expected: Everything still works

# Then upgrade again
1. Activate new version
2. Run any migrations
3. Verify upgrade successful
```

**Acceptance Criteria**:
- ✅ Rollback succeeds
- ✅ Data remains intact
- ✅ Users still work
- ✅ No data loss

---

## Staging Sign-Off

### Must Pass Before Production

```
Required Tests:
✅ Plugin installation - MUST PASS
✅ Rate limiting - MUST PASS
✅ CSRF protection - MUST PASS
✅ Authentication - MUST PASS
✅ SQL injection protection - MUST PASS
✅ XSS protection - MUST PASS
✅ Performance benchmarks - MUST PASS
✅ API integration - MUST PASS
✅ Mobile app integration - MUST PASS
✅ Error handling - MUST PASS
✅ Rollback procedure - MUST PASS

Expected Results:
✅ Error rate < 0.1%
✅ Response time p95 < 500ms
✅ No critical vulnerabilities
✅ All rate limits working
✅ CSRF protection active
✅ Logs properly redacted
✅ Mobile apps connecting
✅ API integrations working

Approval:
👤 Security Team: _________________ Date: _______
👤 DevOps Team: ___________________ Date: _______
👤 Development Team: _______________ Date: _______
👤 Product Team: ___________________ Date: _______

GO/NO-GO Decision: [ ] GO  [ ] NO-GO

If NO-GO, issues to fix:
_________________________________
_________________________________
_________________________________
```

---

## Daily Monitoring During Staging

```
Track these metrics daily:
- Error rate (target: < 0.1%)
- Response time p95 (target: < 500ms)
- Rate limit accuracy (target: 100%)
- Security log entries (investigate anomalies)
- Memory usage (target: stable)
- Database connections (target: < 50)
- Failed API calls (target: 0)

Update dashboard:
https://staging-monitoring.example.com/dashboard
```

---

## Test Evidence Collection

For each test, save:
- ✅ Screenshot of passing test
- ✅ cURL/test output
- ✅ Response headers
- ✅ Performance metrics
- ✅ Error logs

Use this evidence for:
- Security audit trail
- Compliance documentation
- Issue debugging
- Future reference

---

## Success Criteria Summary

| Category | Target | Status |
|----------|--------|--------|
| Installation | 100% success | ⏳ Pending |
| Rate Limiting | 100% accurate | ⏳ Pending |
| Security | 0 vulnerabilities | ⏳ Pending |
| Performance | p95 < 500ms | ⏳ Pending |
| API Integration | 100% working | ⏳ Pending |
| Error Handling | 100% graceful | ⏳ Pending |
| Rollback | 100% successful | ⏳ Pending |

---

**Next Step**: Deploy to staging and execute tests  
**Estimated Time**: 2-3 days  
**Owner**: QA Team + DevOps  

*Follow up with detailed test results before proceeding to production.*
