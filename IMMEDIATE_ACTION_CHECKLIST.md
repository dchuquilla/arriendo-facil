# CHECKLIST DE ACCIÓN INMEDIATA

**INCIDENTE**: Credenciales de Cloudflare y AWS expuestas en git  
**SEVERIDAD**: 🔴 CRÍTICA  
**TIEMPO TOTAL**: ~30-45 minutos  
**FECHA**: 2026-10-07  

---

## ✅ FASE 1: ROTACIÓN DE CREDENCIALES (10 minutos)

### Cloudflare API Token
```
ACTUAL (COMPROMETIDO): cfat_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
ESTADO: ⚠️ ACTIVO - DEBE REVOCARSE AHORA
```

**PASOS:**
1. [ ] Abrir https://dash.cloudflare.com/profile/api-tokens
2. [ ] Buscar el token en la lista
3. [ ] Hacer clic en "..." → "Edit" o "Revoke"
4. [ ] Seleccionar "Revoke" y confirmar
5. [ ] Crear NUEVO token:
   - [ ] Clic en "Create Token"
   - [ ] Permiso: "Account > R2 Tokens > Write"
   - [ ] Scope: Account (o específico si aplica)
   - [ ] Copiar token generado
6. [ ] Actualizar en código local: `config/security-config.php` o `.env`
7. [ ] Actualizar en producción
8. [ ] Probar: `curl -H "Authorization: Bearer NUEVO_TOKEN" https://api.cloudflare.com/client/v4/user`

### AWS Access Keys
```
ACCESS KEY ID (COMPROMETIDO): XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
SECRET KEY (COMPROMETIDO):    XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
ESTADO: ⚠️ ACTIVO - DEBE REVOCARSE AHORA
```

**PASOS:**
1. [ ] Abrir https://console.aws.amazon.com/iamv2/home#/users
2. [ ] Encontrar el usuario que tiene esa Access Key
3. [ ] Clic en "Security credentials"
4. [ ] Localizar la Access Key: `XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX`
5. [ ] Clic en "Deactivate" (primero, NO borrar todavía)
6. [ ] Ir a aplicación y verificar que aún funciona con NUEVO key
7. [ ] SI funciona: Volver a AWS y hacer clic en "Delete"
8. [ ] Crear NUEVO Access Key:
   - [ ] Clic en "Create access key"
   - [ ] Seleccionar "Application running outside AWS"
   - [ ] Copiar Access Key ID y Secret Access Key
9. [ ] Actualizar en código local
10. [ ] Actualizar en producción
11. [ ] Probar conexión a R2/S3

---

## ✅ FASE 2: AUDITAR ACCESO NO AUTORIZADO (15 minutos)

### Cloudflare R2 Logs
```
PERIODO: 24 Marzo 2026 - 7 Octubre 2026 (6+ meses)
REVISAR: Accesos sospechosos, IPs extrañas, modificaciones no autorizadas
```

**PASOS:**
1. [ ] Abrir https://dash.cloudflare.com/
2. [ ] Ir a R2 → Bucket
3. [ ] Buscar "Activity log" o "Logs"
4. [ ] Filtrar por fecha: 2026-03-24 al 2026-10-07
5. [ ] Revisar:
   - [ ] ¿Hay accesos desde IPs desconocidas?
   - [ ] ¿Se descargó contenido sensible?
   - [ ] ¿Se crearon/borraron archivos?
   - [ ] ¿Se cambió permisos?
6. [ ] DOCUMENTAR cualquier acceso sospechoso
7. [ ] Revisar qué archivos fueron accedidos

### AWS CloudTrail
```
REVISAR: Acciones de S3, Cloudflare R2, API calls
PERIODO: 24 Marzo 2026 - 7 Octubre 2026
```

**PASOS:**
1. [ ] Abrir https://console.aws.amazon.com/cloudtrail
2. [ ] Eventos recientes
3. [ ] Filtrar por Access Key: `XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX`
4. [ ] Revisar acciones:
   - [ ] ListBucket
   - [ ] GetObject (descargas)
   - [ ] PutObject (uploads/cambios)
   - [ ] DeleteObject (borrados)
5. [ ] DOCUMENTAR:
   - [ ] Qué archivos fueron accedidos
   - [ ] Cuándo fue (fecha/hora)
   - [ ] Desde qué IP
   - [ ] Si fue desde ubicación desconocida
6. [ ] ¿Hay evidencia de acceso no autorizado?
   - [ ] Sí → Contactar a seguridad, potencial breach
   - [ ] No → Continuar con siguiente paso

---

## ✅ FASE 3: NOTIFICAR A PROVEEDORES (5 minutos)

### Notificar a Cloudflare
```
Email: security@cloudflare.com
Asunto: Exposed API Token - cfat_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
```

**TEMPLATE:**
```
Subject: URGENT - Exposed Cloudflare API Token

Dear Cloudflare Security Team,

We discovered an API token was accidentally committed to a public git repository.

Details:
- Token: cfat_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
- Format: cfat_* (Cloudflare API Token)
- Exposure Period: March 24, 2026 - September 30, 2026
- Discovered: October 7, 2026
- Action Taken: Token revoked today

Request: Please review access logs for this token during the exposure period
and advise if there's evidence of unauthorized access.

Contact: [Your Name] - [Email] - [Phone]

Regards
```

**PASOS:**
1. [ ] Copiar template anterior
2. [ ] Personalizar con datos
3. [ ] Enviar a: security@cloudflare.com
4. [ ] Guardar confirmación de envío

### Notificar a AWS
```
Email: abuse@aws.amazon.com
Asunto: Exposed AWS Access Keys in Public Repository
```

**TEMPLATE:**
```
Subject: URGENT - Exposed AWS Access Keys

Dear AWS Security Team,

We discovered AWS access keys were accidentally committed to a public git repository.

Details:
- Access Key ID: XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
- Secret Access Key: XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
- Exposure Period: March 24, 2026 - September 30, 2026
- Discovered: October 7, 2026
- Action Taken: Keys deactivated/deleted today

Request: Please review API access logs for these keys during the exposure period
and advise if there's evidence of unauthorized access.

Repository: https://github.com/tu-usuario/arriendo-facil (PUBLIC)
Commits: e550462 (added), 59d129c (attempted removal)

Contact: [Your Name] - [Email] - [Phone]

Regards
```

**PASOS:**
1. [ ] Copiar template anterior
2. [ ] Personalizar con datos
3. [ ] Enviar a: abuse@aws.amazon.com
4. [ ] Guardar confirmación de envío

---

## ✅ FASE 4: LIMPIAR HISTORIO DE GIT (15-20 minutos)

### Preparación
```
⚠️ ADVERTENCIA: Esto REESCRIBE el historio de git
   Todos los colaboradores deben actualizar sus repositorios locales
```

**PASOS:**
1. [ ] Verificar que código ya está actualizado con credenciales nuevas
2. [ ] Avisar a TODOS los desarrolladores:
   - [ ] "Vamos a reescribir el historio de git"
   - [ ] "Después deben ejecutar: git fetch --all && git reset --hard origin/main"
3. [ ] Crear backup:
   ```bash
   cp -r /Users/dchuquilla/projects/arriendo-facil/arriendo-facil \
        /Users/dchuquilla/projects/arriendo-facil/arriendo-facil-backup-2026-10-07
   ```
4. [ ] Ejecutar script de remediación:
   ```bash
   chmod +x remediate-security-incident.sh
   ./remediate-security-incident.sh
   ```
5. [ ] Seguir las instrucciones interactivas del script
6. [ ] El script hará:
   - [ ] Crear mirror clone
   - [ ] Ejecutar BFG para eliminar `includes/other`
   - [ ] Limpiar historio
   - [ ] Push forzado

### Verificar Limpieza
```bash
# Después del script, ejecutar:
git log --all --full-history -- includes/other
# Resultado esperado: (vacío, sin commits)

git log -p --all | grep -i "cfat_\|Access Key\|Secret Access Key"
# Resultado esperado: (vacío, sin coincidencias)
```

**PASOS:**
1. [ ] Ejecutar verificaciones anteriores
2. [ ] Confirmar que no hay credenciales en el historio

---

## ✅ FASE 5: NOTIFICAR A COLABORADORES (5 minutos)

### Mensaje a Equipo
```
ASUNTO: 🔴 INCIDENTE SEGURIDAD - Acción requerida

Tenemos un incidente de seguridad:

QUÉ: Credenciales de Cloudflare y AWS se encontraban en el historio de git

ESTADO: Remediaremos HOY

ACCIÓN REQUERIDA:

Después de las 4 PM, todos deben ejecutar en su terminal:

  cd /path/to/arriendo-facil
  git fetch --all
  git reset --hard origin/main

⚠️ IMPORTANTE:
- Perderán cambios locales sin comitear
- Hacer stash primero si tienen WIP
- Esto es REQUERIDO para sincronizarse con el nuevo historio

PREGUNTAS: Contactar a [Security Lead]
```

**PASOS:**
1. [ ] Enviar mensaje a Slack/Email
2. [ ] Hacer follow-up en 2 horas
3. [ ] Verificar que todos actualizaron

---

## ✅ FASE 6: IMPLEMENTAR PROTECCIONES FUTURAS (30 minutos - NO HOY)

### git-secrets en Pre-commit
```bash
# Instalar
brew install git-secrets

# En el repo
git secrets --install
git secrets --register-aws
git secrets --add 'cfat_'  # Agregar Cloudflare token pattern
```

**PASOS (para después):**
1. [ ] Instalar git-secrets en la máquina
2. [ ] Configurar global o por repo
3. [ ] Agregar patrones personalizados
4. [ ] Documentar en README

### .gitignore Improvements
```
# Agregar a .gitignore:
.env
.env.local
.env.*.local
config/local*
config/*-local*
includes/other
includes/secrets*
includes/credentials*
settings/api*
```

**PASOS (para después):**
1. [ ] Editar `.gitignore`
2. [ ] Agregar patrones anteriores
3. [ ] Commit y push
4. [ ] Comunicar a equipo

### GitHub Secret Scanning
```
GitHub automáticamente escanea por:
- AWS credentials
- GitHub tokens
- Private keys
- Etc.
```

**PASOS (para después):**
1. [ ] Ir a Repo → Settings → Security
2. [ ] Habilitar "Secret scanning" (si no está)
3. [ ] Habilitar "Push protection"
4. [ ] Revisar "Secret scanning alerts" existentes

---

## 📊 ESTADO DEL INCIDENTE

| Fase | Tarea | Estado | Inicio | Fin |
|------|-------|--------|--------|-----|
| 1 | Rotar Cloudflare token | ⬜ | | |
| 1 | Rotar AWS keys | ⬜ | | |
| 1 | Actualizar código | ⬜ | | |
| 1 | Probar funcionamiento | ⬜ | | |
| 2 | Auditar Cloudflare logs | ⬜ | | |
| 2 | Auditar AWS CloudTrail | ⬜ | | |
| 2 | Documentar hallazgos | ⬜ | | |
| 3 | Notificar Cloudflare | ⬜ | | |
| 3 | Notificar AWS | ⬜ | | |
| 4 | Limpiar historio de git | ⬜ | | |
| 4 | Verificar limpieza | ⬜ | | |
| 5 | Notificar colaboradores | ⬜ | | |
| 5 | Verificar que todos actualizaron | ⬜ | | |

---

## 📞 CONTACTOS CLAVE

| Rol | Nombre | Email | Teléfono |
|-----|--------|-------|----------|
| Security Lead | | | |
| DevOps/Infra | | | |
| Product Lead | | | |
| Development Lead | | | |

---

## 📝 NOTAS DE AUDITORÍA

**Commit introduciendo credenciales:**
- Commit: e550462f04d08c09d45de829599813f50ebfdab7
- Fecha: 24 Marzo 2026
- Archivo: includes/other

**Commit intentando eliminar (incompleto):**
- Commit: 59d129c35086b536b1376f19e53d1d220600849f
- Fecha: 30 Septiembre 2026
- Acción: Eliminó del working directory, pero no del historio

**Descubrimiento:**
- Fecha: 7 Octubre 2026
- Duración de exposición: ~6 meses 2 semanas

---

**DOCUMENTO**: CHECKLIST DE ACCIÓN INMEDIATA  
**SEVERIDAD**: 🔴 CRÍTICA  
**TIEMPO ESTIMADO**: 30-45 minutos  
**ESTADO**: PENDIENTE DE EJECUCIÓN  
