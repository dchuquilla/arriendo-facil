╔═══════════════════════════════════════════════════════════════════════════╗
║                                                                           ║
║           ❌ SOLUCIÓN: FIRMA INVALIDA (Error 39 del SRI)                ║
║                                                                           ║
║  Problema:    SRI rechaza la firma con "FIRMA INVALIDA"                 ║
║  Causa:       P12 en formato antiguo O cadena CA no se extrae           ║
║  Solución:    Usar las herramientas de diagnóstico y conversión         ║
║                                                                           ║
╚═══════════════════════════════════════════════════════════════════════════╝


⚡ SOLUCIÓN RÁPIDA EN 3 PASOS:
═════════════════════════════════

PASO 1: Diagnosticar
──────────────────
Ejecuta desde la raíz de WordPress:

  php wp-cli.phar eval-file wp-content/plugins/arriendo-facil/DIAGNOSTICO_P12_DETALLADO.php

Busca en la salida:
  ✓ "✓ NO SE DETECTARON PROBLEMAS CRÍTICOS" → P12 está OK, ir a PASO 3
  ❌ "❌ openssl_pkcs12_read() FALLÓ" → P12 en formato antiguo, ir a PASO 2
  ❌ "Certificado EXPIRADO" → Renovar certificado
  ❌ "Cadena CA está vacía" → Ejecutar "Reconstruir cadena CA" en admin


PASO 2: Convertir P12 (SOLO si PASO 1 mostró error)
────────────────────────────────────────────────────
Ejecuta desde terminal (NO WordPress):

  cd /ruta/a/tu/certificado
  php /ruta/a/wordpress/wp-content/plugins/arriendo-facil/CONVERTIDOR_P12_VALIDO.php ./cert.p12 "TuContraseña"

Resultado:
  ✓ Genera cert_convertido.p12


PASO 3: Recargar certificado en WordPress
──────────────────────────────────────────
1. Ve a: Facturación → Configuración SRI
2. Sube: cert_convertido.p12 (o el original si PASO 1 fue OK)
3. Ingresa: Contraseña
4. Haz clic: "Reconstruir cadena CA" (si cadena estaba vacía)
5. Haz clic: "Test firma XML" (debería ver ✓ exitoso)


🔍 DIAGNÓSTICOS DISPONIBLES:
═════════════════════════════

1. DIAGNOSTICO_P12_DETALLADO.php
   Qué: Verifica si el P12 es válido y se puede leer
   Cuándo: Cuando FIRMA INVALIDA persiste
   Cómo: php wp-cli.phar eval-file DIAGNOSTICO_P12_DETALLADO.php

2. CLI_DIAGNOSTICO_CADENA.php
   Qué: Verifica si la cadena CA se guardó y se incluye en la firma
   Cuándo: Cuando quieres confirmar que cadena está bien
   Cómo: php wp-cli.phar eval-file CLI_DIAGNOSTICO_CADENA.php

3. DIAGNOSTICO_CADENA_CA.md
   Qué: Guía completa de verificación de cadena CA
   Cuándo: Para entender el problema en profundidad
   Dónde: Archivo .md en la raíz del plugin


🔧 HERRAMIENTAS:
═════════════════

1. CONVERTIDOR_P12_VALIDO.php
   ¿Qué hace?  Convierte P12 antiguo a formato OpenSSL 3.x compatible
   ¿Cuándo?    Cuando openssl_pkcs12_read() falla
   ¿Cómo?      php CONVERTIDOR_P12_VALIDO.php ./cert.p12 "TuContraseña"

2. SOLUCION_FIRMA_INVALIDA_P12.md
   ¿Qué hace?  Explicación completa con árbol de decisión
   ¿Cuándo?    Para entender qué hacer en cada caso
   ¿Dónde?     Archivo .md en la raíz del plugin


📋 DECISIÓN RÁPIDA:
═══════════════════

¿Ve FIRMA INVALIDA?
    │
    ├─ ¿DIAGNOSTICO_P12_DETALLADO.php muestra "openssl_pkcs12_read FALLÓ"?
    │  Sí  → Ejecuta CONVERTIDOR_P12_VALIDO.php
    │  No  → Próximo
    │
    └─ ¿Muestra "Cadena CA está vacía" o "Certificados en firma: 1"?
       Sí  → Ve a Admin, Panel Facturación > Config SRI > "Reconstruir cadena CA"
       No  → Próximo
       │
       └─ ¿Es certificado UANATACA?
          Sí  → Cambiar a ambiente PRODUCCIÓN (no PRUEBAS)
          No  → Contactar entidad certificadora


❓ PREGUNTAS FRECUENTES:
═════════════════════════

P: ¿Qué es FIRMA INVALIDA?
R: Error 39 del SRI. Significa que el certificado usado para firmar no es
   válido para el SRI. Causas: P12 antiguo, cadena CA incompleta, cert
   vencido, o CA no en trust store del SRI.

P: ¿Puedo usar el P12 original en otros sistemas?
R: Sí. El problema es específico de cómo PHP/OpenSSL lo procesa. La
   conversión mantiene el mismo certificado y clave privada, solo cambia
   el empaquetamiento.

P: ¿Qué es "cadena CA"?
R: Son los certificados intermedios entre tu certificado y el root del SRI.
   Sin ellos, SRI no puede validar que tu certificado sea confiable.

P: ¿Por qué "Reconstruir cadena CA" falla?
R: Porque tu servidor no puede conectarse a internet (firewall/proxy).
   En ese caso, agrega manualmente:
   Facturación > Config SRI > "Cadena CA Manual" > pega PEM

P: ¿Qué hago si nada funciona?
R: 1. Guarda diagnostico.txt
   2. Contacta a tu hosting para habilitar shell_exec
   3. Contacta a tu entidad certificadora (BCE, UANATACA, etc.)
   4. Si es test, cambia a PRODUCCIÓN (si tienes cuenta)


📞 CONTACTOS:
═══════════════

Banco Central Ecuador (Certificación):
  https://www.bce.fin.ec/

UANATACA (Si usas certs de UANATACA):
  https://www.uanataca.com/

SecurityData / ACE (Otras entidades):
  https://www.acecr.com/ (Costa Rica, similar en Ecuador)


✅ CHECKLIST:
═══════════════

- [ ] Ejecutar DIAGNOSTICO_P12_DETALLADO.php
- [ ] Si falla, ejecutar CONVERTIDOR_P12_VALIDO.php
- [ ] Recargar P12 en Admin
- [ ] Ejecutar "Reconstruir cadena CA" (si necesario)
- [ ] Ejecutar CLI_DIAGNOSTICO_CADENA.php
- [ ] Verificar que "Certificados en firma: 2+" (usuario + intermedios)
- [ ] Haz clic "Test firma XML" en Admin
- [ ] Revisar logs para "✓ Todo validado"
- [ ] Intentar emitir factura de prueba
- [ ] Si aún falla y es UANATACA, cambiar a PRODUCCIÓN

═════════════════════════════════════════════════════════════════════════════
Para más información, abre: SOLUCION_FIRMA_INVALIDA_P12.md
═════════════════════════════════════════════════════════════════════════════
