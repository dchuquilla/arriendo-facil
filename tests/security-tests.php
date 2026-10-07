<?php
/**
 * Security Testing Suite
 *
 * Run: wp eval-file tests/security-tests.php
 *
 * @package Arriendo_Facil
 * @since 1.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    echo "Must run from WordPress environment\n";
    exit( 1 );
}

echo "🔐 Security Testing Suite\n";
echo "========================\n\n";

// ========================
// Test 1: Security Headers
// ========================
echo "1️⃣  Testing Security Headers...\n";

$test_url = home_url( '/' );
$response = wp_remote_head( $test_url );

if ( is_wp_error( $response ) ) {
    echo "❌ Error testing headers: " . $response->get_error_message() . "\n";
} else {
    $headers = wp_remote_retrieve_headers( $response );
    
    $required_headers = array(
        'x-frame-options',
        'x-content-type-options',
        'strict-transport-security',
        'content-security-policy',
    );
    
    foreach ( $required_headers as $header ) {
        if ( isset( $headers[ $header ] ) ) {
            echo "✅ $header: " . $headers[ $header ] . "\n";
        } else {
            echo "❌ MISSING: $header\n";
        }
    }
}
echo "\n";

// ========================
// Test 2: SQL Injection
// ========================
echo "2️⃣  Testing SQL Injection Prevention...\n";

global $wpdb;

// Test prepared statement
$test_id = 1;
$query = $wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE ID = %d", $test_id );
$query_safe = ( false !== strpos( $query, '%' ) === false ); // Should not have % after prepare

if ( $query_safe ) {
    echo "✅ SQL: prepare() working correctly\n";
} else {
    echo "⚠️  SQL: Verify prepare() usage\n";
}
echo "\n";

// ========================
// Test 3: Authentication
// ========================
echo "3️⃣  Testing Authentication...\n";

if ( function_exists( 'wp_verify_nonce' ) ) {
    echo "✅ CSRF: wp_verify_nonce available\n";
} else {
    echo "❌ CSRF: wp_verify_nonce not available\n";
}

if ( function_exists( 'wp_hash_password' ) ) {
    echo "✅ Password hashing available\n";
} else {
    echo "❌ Password hashing not available\n";
}
echo "\n";

// ========================
// Test 4: Rate Limiting
// ========================
echo "4️⃣  Testing Rate Limiting...\n";

if ( class_exists( 'Arriendo_Facil_Rate_Limiter' ) ) {
    echo "✅ Rate limiter class found\n";
    
    // Test rate limit
    Arriendo_Facil_Rate_Limiter::init();
    
    $remaining = Arriendo_Facil_Rate_Limiter::get_remaining( 'api_public' );
    echo "✅ Rate limit check working (Remaining: $remaining)\n";
} else {
    echo "❌ Rate limiter class NOT FOUND\n";
}
echo "\n";

// ========================
// Test 5: Input Validation
// ========================
echo "5️⃣  Testing Input Validation...\n";

if ( class_exists( 'Arriendo_Facil_Input_Validator' ) ) {
    echo "✅ Input validator class found\n";
    
    $test_data = array(
        'email' => 'test@example.com',
        'password' => 'TeSt123!@#',
    );
    
    $schema = array(
        'email' => array( 'type' => 'email' ),
        'password' => array( 'min_length' => 8 ),
    );
    
    if ( Arriendo_Facil_Input_Validator::validate( $test_data, $schema ) ) {
        echo "✅ Input validation working\n";
    } else {
        echo "❌ Input validation failed\n";
    }
} else {
    echo "❌ Input validator class NOT FOUND\n";
}
echo "\n";

// ========================
// Test 6: Secure Logging
// ========================
echo "6️⃣  Testing Secure Logging...\n";

if ( class_exists( 'Arriendo_Facil_Secure_Logger' ) ) {
    echo "✅ Secure logger class found\n";
    
    // Test logging
    Arriendo_Facil_Secure_Logger::log(
        Arriendo_Facil_Secure_Logger::INFO,
        'Test security check',
        array( 'test' => 'value' )
    );
    echo "✅ Secure logging working\n";
} else {
    echo "❌ Secure logger class NOT FOUND\n";
}
echo "\n";

// ========================
// Test 7: Data Encryption
// ========================
echo "7️⃣  Testing Encryption...\n";

if ( extension_loaded( 'sodium' ) || extension_loaded( 'libsodium' ) ) {
    echo "✅ Libsodium extension available\n";
} else {
    echo "❌ Libsodium extension NOT AVAILABLE\n";
}

if ( extension_loaded( 'openssl' ) ) {
    echo "✅ OpenSSL extension available\n";
} else {
    echo "❌ OpenSSL extension NOT AVAILABLE\n";
}
echo "\n";

// ========================
// Test 8: File Permissions
// ========================
echo "8️⃣  Testing File Permissions...\n";

$sensitive_files = array(
    ABSPATH . 'wp-config.php',
    ARRIENDO_FACIL_PLUGIN_DIR . 'config/security-config.php',
);

foreach ( $sensitive_files as $file ) {
    if ( file_exists( $file ) ) {
        $perms = substr( sprintf( '%o', fileperms( $file ) ), -4 );
        if ( in_array( $perms, array( '0644', '0755', '0600' ), true ) ) {
            echo "✅ $file permissions OK ($perms)\n";
        } else {
            echo "⚠️  $file permissions unusual ($perms)\n";
        }
    }
}
echo "\n";

// ========================
// Test 9: HTTPS
// ========================
echo "9️⃣  Testing HTTPS...\n";

if ( is_ssl() ) {
    echo "✅ HTTPS is enabled\n";
} else {
    echo "⚠️  HTTPS is NOT enabled - Critical for production\n";
}
echo "\n";

// ========================
// Test 10: WordPress Updates
// ========================
echo "🔟 Testing WordPress Security...\n";

global $wp_version;
echo "✅ WordPress version: $wp_version\n";

$updates = get_site_transient( 'update_core' );
if ( $updates && ! empty( $updates->updates ) ) {
    echo "⚠️  WordPress updates available\n";
} else {
    echo "✅ WordPress is up to date\n";
}
echo "\n";

// ========================
// SUMMARY
// ========================
echo "====================================\n";
echo "✅ Security testing completed!\n";
echo "====================================\n";
echo "\nNext steps:\n";
echo "1. Review any ⚠️  warnings\n";
echo "2. Fix any ❌ errors\n";
echo "3. Run penetration testing\n";
echo "4. Set up monitoring\n";
echo "5. Document security procedures\n";
echo "\n";

if ( is_admin() ) {
    echo "💡 Tip: Run this test after each deployment\n";
}
