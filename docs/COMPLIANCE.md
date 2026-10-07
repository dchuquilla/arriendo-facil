# Guía de Cumplimiento - Arriendo Fácil

**Última Actualización**: Octubre 2026

## 1. Compliance Checklist

### GDPR (Protección General de Datos)
- [x] Política de privacidad clara y accesible
- [x] Consentimiento explícito para marketing
- [x] Derecho al olvido implementado
- [x] Portabilidad de datos
- [x] Notificación de brechas en 72 horas
- [x] Data Protection Officer designado
- [x] Registro de actividades de procesamiento
- [x] Evaluación de impacto (DPIA) para datos sensibles
- [x] Cláusulas de transferencia de datos

### CCPA (California Privacy Rights)
- [x] Política de privacidad en español/inglés
- [x] Derecho a saber qué datos se recopilan
- [x] Derecho a eliminar información
- [x] Derecho a opt-out de venta de datos
- [x] Acceso a datos personales
- [x] No discriminación por ejercer derechos

### Ecuador (LPED)
- [x] Política de privacidad en español
- [x] Consentimiento para recopilación de datos
- [x] Derechos de acceso y rectificación
- [x] Protección de menores de edad
- [x] Cumplimiento tributario (facturas, retenciones)

### LGPD (Brasil)
- [x] Bases legales para procesamiento
- [x] Consentimiento explícito
- [x] Derechos de titulares de datos
- [x] Responsable de datos (Data Controller)

---

## 2. Requisitos por Plataforma

### Apple App Store

#### Privacy Label (PrivacyManifest.json)
```json
{
  "NSPrivacyTracking": false,
  "NSPrivacyTrackingDomains": [],
  "NSPrivacyTrackedDataTypes": [
    {
      "NSPrivacyTrackedDataType": "NSPrivacyTrackedDataTypeUserID",
      "NSPrivacyTrackedDataTypeLinked": false,
      "NSPrivacyTrackedDataTypeTracking": false,
      "NSPrivacyTrackedDataTypePurposes": ["NSPrivacyTrackedDataTypePurposeAppFunctionality"]
    }
  ],
  "NSPrivacyCollectedDataTypes": []
}
```

#### Requisitos
- [ ] Privacy Policy publicada y actualizada
- [ ] Descripción clara de qué datos se recopilan
- [ ] PrivacyManifest.json incluido
- [ ] No rastrear sin consentimiento
- [ ] Cumplir restricciones de rastreo de IDFA
- [ ] Remover datos cuando usuario lo solicite
- [ ] No vender datos de usuarios

### Google Play Store

#### Policy Requirements
- [ ] Privacy Policy en URL pública
- [ ] Cumplir Google Play Developer Policy
- [ ] Declaración de permisos justificados
- [ ] No recopilar datos innecesarios
- [ ] Cumplir GDPR si usuarios son de UE
- [ ] Consentimiento para publicidad personalizada

#### Permisos Justificados
```
Permiso                    | Propósito                  | Crítico?
Location                   | Encontrar propiedades      | No (opcional)
Camera                      | Fotos de propiedades       | No (opcional)
Contacts                    | Contactar inquilinos       | No (opcional)
Calendar                    | Programar visitas          | No (opcional)
Photos                      | Subir documentos           | Sí
Internet                    | Comunicación con servidor  | Sí
```

---

## 3. Cumplimiento Tributario (Ecuador/SRI)

### Facturación
- [x] Emisión de comprobantes electrónicos obligatorio
- [x] Firma digital con certificado SRI
- [x] Secuenciales únicos por tipo de comprobante
- [x] Retención en la fuente (1%)
- [x] Envío a SRI en 24 horas

### Documentación Requerida
- [ ] RUC del propietario registrado
- [ ] Datos de facturador principal
- [ ] Certificado digital válido
- [ ] Claves de acceso generadas correctamente
- [ ] Auditoría de emisiones mensual

### RIDE (Representación Impresa de Documento Electrónico)
- [x] Código QR con datos de factura
- [x] Información del SRI
- [x] Fecha y hora de emisión
- [x] Firma de recepción

---

## 4. Requisitos de Datos Sensibles

### Información de Identidad
- **Encriptación**: AES-256-GCM o Libsodium Secretbox
- **Almacenamiento**: Base de datos encriptada
- **Acceso**: Solo propietario y admins autorizados
- **Auditoría**: Todos los accesos registrados
- **Retención**: 7 años (requisito tributario)

### Información Financiera
- **Encriptación**: End-to-end en tránsito y reposo
- **Cumplimiento**: PCI DSS Level 1
- **Acceso**: Restringido a procesamiento
- **Purga**: Después de 7 años

### Información de Menores
- **Edad mínima**: 18 años
- **Consentimiento parental**: Si aplica
- **Protección especial**: No vender datos

---

## 5. Seguridad de Datos

### Implementación Requerida
- [x] SSL/TLS 1.2+ para todas las conexiones
- [x] HSTS headers
- [x] CSP (Content Security Policy)
- [x] Rate limiting
- [x] CSRF protection
- [x] SQL injection prevention
- [x] XSS protection
- [x] Input validation
- [x] Output escaping

### Cambios de Contraseña
- [ ] Requerir cambio cada 90 días para admins
- [ ] Historial de 5 últimas contraseñas
- [ ] Mínimo 12 caracteres, caracteres especiales
- [ ] 2FA para admins

### Auditoría de Seguridad
- [ ] Penetration testing anual
- [ ] Scanning de vulnerabilidades trimestral
- [ ] Auditoría de dependencias mensual
- [ ] Review de logs semanal

---

## 6. Incidentes y Notificación

### Plan de Respuesta a Incidentes

1. **Detección** (0-1 hora)
   - Alertas de seguridad automáticas
   - Notificación a equipo de seguridad
   - Aislamiento de sistemas afectados

2. **Contención** (1-4 horas)
   - Prevenir propagación
   - Resguardo de logs
   - Comunicación interna

3. **Investigación** (4-72 horas)
   - Análisis root cause
   - Scope del incidente
   - Datos comprometidos

4. **Notificación** (72 horas)
   - Notificar afectados
   - Notificar autoridades (si aplica)
   - Notificar plataformas (App Store, Play Store)

5. **Remediación** (Ongoing)
   - Fix de vulnerabilidades
   - Actualizaciones de seguridad
   - Post-mortem

---

## 7. Testing y Validación

### Automatic Security Tests
```bash
# Auditar dependencias
composer audit

# SAST (Static Analysis)
phpstan analyse includes/ --level=8

# Dependency check
php bin/security-checker security:check composer.lock

# OWASP Top 10 scan
zaproxy scan-web.js https://app.arriendofacil.com
```

### Manual Testing
- [ ] SQL injection testing
- [ ] XSS testing
- [ ] CSRF testing
- [ ] Authentication bypass
- [ ] Authorization bypass
- [ ] File upload bypass
- [ ] Rate limit bypass

### Compliance Testing
- [ ] GDPR data export
- [ ] GDPR data deletion
- [ ] Privacy policy terms compliance
- [ ] Cookie consent implementation
- [ ] PCI compliance verification

---

## 8. Documentación y Registros

### Mantener Registros De:
1. Consentimientos (qué, cuándo, quién)
2. Transferencias de datos
3. Acceso a datos sensibles
4. Incidentes de seguridad
5. Evaluaciones de impacto (DPIA)
6. Evaluaciones de riesgo
7. Auditorías de seguridad

### Formatos
- [ ] Registro de consentimientos (CSV/JSON)
- [ ] Log de accesos (syslog)
- [ ] Log de cambios (Git)
- [ ] Log de incidentes (JIRA/Wiki)

---

## 9. Política de Retención

| Tipo de Dato | Retención | Justificación |
|--------------|-----------|---------------|
| Logs de acceso | 30 días | Seguridad |
| Transacciones | 7 años | Legal (SRI) |
| Contratos | 7 años | Legal (SRI) |
| Facturas | Indefinido | Legal |
| Datos de cuenta inactiva | 2 años | Retención razonable |
| Cookies de analytics | 2 años | Análisis |
| Backup incremental | 30 días | Recuperación |
| Backup full | 1 año | Recuperación |

---

## 10. Contactos Regulatorios

### Ecuador
- **SUPERTEL** (Superintendencia de Telecomunicaciones)
  - Email: reclamos@supertel.gob.ec
  - Teléfono: 1-800-123-000
  - Sitio: https://www.supertel.gob.ec

- **SRI** (Servicio de Rentas Internas)
  - Sitio: https://www.sri.gob.ec
  - Cumplimiento tributario

### Unión Europea
- **ICO** (Information Commissioner's Office)
  - Sitio: https://ico.org.uk
  - GDPR compliance

### Otros
- **IAMAI** (Internet & Mobile Association of India)
- **PDPC** (Singapore)
- **OAIC** (Australia)

---

## 11. Actualizaciones Requeridas

- [ ] Actualizar Privacy Policy anualmente
- [ ] Revisar DPIA cada 2 años
- [ ] Auditar compliance cuatrimestralmente
- [ ] Penetration testing anualmente
- [ ] DPO training anualmente
- [ ] Staff security training cada 6 meses

