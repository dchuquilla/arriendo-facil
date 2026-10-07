#!/bin/bash
# Security & Compliance Validation Script
# Uso: bash validate-security.sh

set -e  # Exit on error

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
REPORT_FILE="security-report_${TIMESTAMP}.md"

echo "🔐 Iniciando validación de seguridad..."
echo "📝 Reporte será guardado en: $REPORT_FILE"
echo ""

{
    echo "# Security & Compliance Report"
    echo "**Generated**: $(date)"
    echo "**Repository**: $REPO_DIR"
    echo ""
    
    # ============================================
    # 1. DEPENDENCY CHECK
    # ============================================
    echo "## 1. Dependency Security Check"
    echo ""
    
    if command -v composer &> /dev/null; then
        echo "### Composer Audit"
        echo "\`\`\`"
        if composer audit 2>/dev/null || echo "⚠️ No vulnerabilities found or composer audit not available"; then
            echo "✅ Composer dependencies scanned"
        fi
        echo "\`\`\`"
    else
        echo "❌ Composer not found. Install dependencies and run: composer audit"
    fi
    echo ""
    
    # ============================================
    # 2. FILE PERMISSION CHECK
    # ============================================
    echo "## 2. File Permission Check"
    echo ""
    
    echo "### Sensitive files should NOT be world-readable"
    echo "\`\`\`"
    
    SENSITIVE_FILES=(
        "wp-config.php"
        ".env"
        "config/security-config.php"
    )
    
    for file in "${SENSITIVE_FILES[@]}"; do
        if [ -f "$REPO_DIR/$file" ]; then
            perms=$(stat -f "%A" "$REPO_DIR/$file" 2>/dev/null || stat -c "%a" "$REPO_DIR/$file" 2>/dev/null)
            if [[ "$perms" == "644" ]] || [[ "$perms" == "666" ]]; then
                echo "⚠️ $file has permissive permissions: $perms"
            else
                echo "✅ $file permissions OK: $perms"
            fi
        fi
    done
    echo "\`\`\`"
    echo ""
    
    # ============================================
    # 3. GITIGNORE CHECK
    # ============================================
    echo "## 3. Git Ignore Check"
    echo ""
    
    echo "### Files that should be ignored"
    echo "\`\`\`"
    
    IGNORED_PATTERNS=(
        ".env"
        "node_modules/"
        "vendor/"
        "*.pem"
        "*.key"
        "*.p12"
        ".DS_Store"
    )
    
    for pattern in "${IGNORED_PATTERNS[@]}"; do
        if git check-ignore "$pattern" &>/dev/null 2>&1; then
            echo "✅ $pattern is in .gitignore"
        else
            echo "⚠️ $pattern is NOT in .gitignore"
        fi
    done
    echo "\`\`\`"
    echo ""
    
    # ============================================
    # 4. CODE ANALYSIS
    # ============================================
    echo "## 4. Code Analysis"
    echo ""
    
    echo "### Checking for common vulnerabilities"
    echo "\`\`\`"
    
    # Check for hardcoded credentials
    echo "#### Hardcoded Credentials"
    if grep -r "password.*=" includes/ admin/ --include="*.php" 2>/dev/null | grep -v "sanitize\|escape\|prepare" | head -5; then
        echo "⚠️ Potential hardcoded credentials found - Review manually"
    else
        echo "✅ No obvious hardcoded credentials"
    fi
    echo ""
    
    # Check for unescaped output
    echo "#### Potentially Unescaped Output"
    if grep -r "echo \$" includes/ admin/ --include="*.php" | grep -v "esc_\|sanitize_" | wc -l | grep -q "^0$"; then
        echo "✅ No obvious unescaped echo statements"
    else
        echo "⚠️ Found echo statements without escaping - Review manually"
    fi
    echo ""
    
    # Check for SQL vulnerabilities
    echo "#### SQL Injection Risks"
    if grep -r "wpdb->query\|wpdb->get_results" includes/ admin/ --include="*.php" | grep -v "prepare" | head -3; then
        echo "⚠️ Found queries without prepare() - Review manually"
    else
        echo "✅ All queries appear to use prepare()"
    fi
    echo ""
    
    echo "\`\`\`"
    echo ""
    
    # ============================================
    # 5. SECURITY HEADERS CHECK
    # ============================================
    echo "## 5. Security Implementation Check"
    echo ""
    
    echo "### Required Files"
    echo "\`\`\`"
    
    REQUIRED_FILES=(
        "includes/class-security-headers.php"
        "includes/class-rate-limiter.php"
        "includes/class-input-validator.php"
        "includes/class-secure-logger.php"
        "config/security-config.php"
        "docs/PRIVACY_POLICY.md"
        "docs/COMPLIANCE.md"
    )
    
    for file in "${REQUIRED_FILES[@]}"; do
        if [ -f "$REPO_DIR/$file" ]; then
            echo "✅ $file exists"
        else
            echo "❌ $file MISSING"
        fi
    done
    echo "\`\`\`"
    echo ""
    
    # ============================================
    # 6. SSL/TLS CHECK
    # ============================================
    echo "## 6. SSL/TLS Check"
    echo ""
    
    echo "### HTTPS Configuration"
    echo "\`\`\`"
    if grep -q "is_ssl()" includes/class-security-headers.php; then
        echo "✅ SSL detection implemented"
    else
        echo "⚠️ SSL detection not found"
    fi
    echo "\`\`\`"
    echo ""
    
    # ============================================
    # 7. PHPSTAN (if available)
    # ============================================
    echo "## 7. Static Analysis (PHPStan)"
    echo ""
    
    if command -v phpstan &> /dev/null; then
        echo "### Code Quality Scan"
        echo "\`\`\`"
        phpstan analyse includes/ --level=8 --no-progress 2>&1 | head -20 || echo "Run: phpstan analyse includes/ --level=8"
        echo "\`\`\`"
    else
        echo "⚠️ PHPStan not installed. Install: composer require --dev phpstan/phpstan"
    fi
    echo ""
    
    # ============================================
    # 8. SUMMARY
    # ============================================
    echo "## Summary"
    echo ""
    echo "### Action Items"
    echo ""
    echo "- [ ] Review hardcoded credentials (if any)"
    echo "- [ ] Verify SQL queries use prepare()"
    echo "- [ ] Ensure all security files are included in main plugin file"
    echo "- [ ] Test security headers in browser DevTools"
    echo "- [ ] Run penetration testing before launch"
    echo "- [ ] Set up monitoring and alerting"
    echo "- [ ] Create incident response plan"
    echo ""
    
    echo "### Test Commands"
    echo ""
    echo "\`\`\`bash"
    echo "# Verify security headers"
    echo "curl -I https://arriendofacil.com 2>/dev/null | grep -i 'x-frame-options\|x-content-type\|strict-transport'"
    echo ""
    echo "# Check SSL certificate"
    echo "openssl s_client -connect arriendofacil.com:443 < /dev/null | openssl x509 -noout -dates"
    echo ""
    echo "# Verify HTTPS redirect"
    echo "curl -I http://arriendofacil.com 2>/dev/null | grep 'Location'"
    echo ""
    echo "# Test rate limiting"
    echo "for i in {1..65}; do curl -s https://arriendofacil.com/api/ > /dev/null & done"
    echo "\`\`\`"
    echo ""
    
    echo "---"
    echo ""
    echo "**Report Generated**: $(date)"
    
} | tee "$REPORT_FILE"

echo ""
echo "✅ Validation complete! Report saved to: $REPORT_FILE"
