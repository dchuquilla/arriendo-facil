# 🔴 INCIDENTE DE SEGURIDAD: CLAVES SENSIBLES EXPUESTAS EN GIT

**SEVERIDAD: CRÍTICA**  
**FECHA REPORTE**: 2026-10-07  
**ESTADO**: EN PROCESO  

---

## 📋 RESUMEN EJECUTIVO

Se han detectado **credenciales de Cloudflare y AWS** expuestas en el historial del repositorio git. Estas claves permiten acceso completo a:
- ✅ Cloudflare R2 Storage (lectura, escritura, eliminación)
- ✅ AWS S3/Cloudflare R2 buckets
- ✅ Todas las APIs de Cloudflare

**ACCIÓN REQUERIDA**: Rotación inmediata de credenciales (HOY).

---

## 🔐 CLAVES COMPROMETIDAS

| # | Tipo | Valor | Formato | Estado |
|---|------|-------|---------|--------|
| 1 | Cloudflare API Token | `cfat_SmYDRP5DLmGj6QKkErlmvgy5UlxLVv6iKh4PMX1Y6d67cfbd` | cfat_* | ⚠️ ACTIVO |
| 2 | AWS Access Key ID | `9e50a8dfa4d53fdcea91e918f13013ca` | Hex 32 chars | ⚠️ ACTIVO |
| 3 | AWS Secret Key | `4aa1d107e4aea6db701176300da58c47c3fb12b39b5411cb237f78ed38f0c9b` | SHA256-like | ⚠️ ACTIVO |
| 4 | R2 Endpoint URL | `https://8088bc29e75206bf131252a55578c8b5.r2.cloudflarestorage.com` | URL | 🔍 IDENTIFIER |

---

## 📍 UBICACIÓN DE EXPOSICIÓN

### Archivo Comprometido
```
includes/other
```

### Contenido Expuesto
```
//no tomar en cuenta el siguiente código, es solo para pruebas
//Credenciales de bucket cloudflare
token Value= cfat_SmYDRP5DLmGj6QKkErlmvgy5UlxLVv6iKh4PMX1Y6d67cfbd

credenciales del s3 de r2
Access Key ID= 9e50a8dfa4d53fdcea91e918f13013ca
Secret Access Key= 4aa1d107e4aea6db701176300da58c47c3fb12b39b5411cb237f78ed38f0c9b

Use jurisdiction-specific endpoints for S3 clients:
Default
https://8088bc29e75206bf131252a55578c8b5.r2.cloudflarestorage.com

https://8088bc29e75206bf131252a55578c8b5.r2.cloudflarestorage.com
```

---

## ⏱️ TIMELINE DE EXPOSICIÓN

### Fase 1: Introducción (COMMIT e550462)
- **Commit**: `e550462f04d08c09d45de829599813f50ebfdab7`
- **Fecha**: Martes, 24 de Marzo de 2026, 12:16:06 -0500
- **Autor**: Dario Chuquilla
- **Mensaje**: "✨ Add feature/xxx"
- **Acción**: ➕ Agregó `includes/other` con todas las credenciales
- **DURACIÓN EXPUESTA**: ~6 meses (Marzo → Septiembre)

### Fase 2: Intento de Eliminación (COMMIT 59d129c)
- **Commit**: `59d129c35086b536b1376f19e53d1d220600849f`
- **Fecha**: Miércoles, 30 de Septiembre de 2026, 13:07:57 -0500
- **Autor**: Dario Chuquilla
- **Mensaje**: "feat: Add P12 converter and validator..."
- **Acción**: ❌ Eliminó `includes/other` del working directory
- **PROBLEMA**: El archivo aún existe en el historio de git (~7 días exponible)

### Fase 3: Descubrimiento (HOJE)
- **Reporte**: Usuario reportó exposición de API de Cloudflare
- **Acción**: Análisis de seguridad iniciado
- **Estado**: EN PROCESO

---

## 🚨 RIESGOS IDENTIFICADOS

### Acceso Potencial Comprometido
```
☑️ Lectura de todos los archivos en R2 Storage
☑️ Escritura/Modificación de archivos en R2
☑️ Eliminación de archivos en R2 (destructivo)
☑️ Listar buckets y archivos
☑️ Cambiar permisos y configuraciones
☑️ Crear/eliminar buckets
☑️ Todas las operaciones de API de Cloudflare
```

### Impacto de Confidencialidad
- **ALTA** - Acceso a archivos potencialmente sensibles (contratos, documentos, datos de propiedades)
- **ALTA** - Posibilidad de extracción de datos

### Impacto de Integridad
- **ALTA** - Posibilidad de modificar/inyectar archivos maliciosos
- **ALTA** - Riesgo de datos corruptos

### Impacto de Disponibilidad
- **CRÍTICA** - Posibilidad de eliminar todos los recursos en R2
- **CRÍTICA** - Aplicación podría quedar sin acceso a recursos

---

## ✅ ACCIONES INMEDIATAS (DEBE HACERSE HOY)

### 1️⃣ ROTACIÓN DE CREDENCIALES - AHORA MISMO

#### Cloudflare (10 min)
```
1. Ir a: https://dash.cloudflare.com/profile/api-tokens
2. Buscar: "cfat_SmYDRP5DLmGj6QKkErlmvgy5UlxLVv6iKh4PMX1Y6d67cfbd"
3. Hacer clic: "Edit" o "Revoke"
4. Seleccionar: "Revoke"
5. Confirmar: "Yes, revoke"
6. Crear nuevo token: "Create Token" → Permiso: "Account.R2 Tokens Write"
7. Copiar: Nuevo token
8. Actualizar: app/config y producción
```

#### AWS (15 min)
```
1. Ir a: https://console.aws.amazon.com/iamv2/home#/users
2. Buscar: User que tiene la Access Key "9e50a8dfa4d53fdcea91e918f13013ca"
3. Hacer clic: "Security credentials"
4. Seleccionar: Access Key
5. Hacer clic: "Deactivate" (primero)
6. Esperar: Confirmar que app sigue funcionando con nuevo key
7. Hacer clic: "Delete" (después de probar)
8. Crear nuevo: "Create access key"
9. Copiar: Access Key ID + Secret Access Key
10. Actualizar: app/config y producción
```

### 2️⃣ AUDITAR ACCESO NO AUTORIZADO (30 min)

#### Cloudflare R2
```
1. Ir a: https://dash.cloudflare.com/
2. R2 → Bucket → "Activity log" o "Access logs"
3. Filtrar: Últimas 2 semanas
4. Buscar: Accesos sospechosos, IPs extrañas
5. Descargar: Logs completos si hay sospecha
6. Revisar: Qué archivos fueron accedidos/modificados
```

#### AWS CloudTrail
```
1. Ir a: https://console.aws.amazon.com/cloudtrail
2. Eventos recientes
3. Filtrar: S3, Cloudflare R2 actions
4. Buscar: Accesos de la Access Key comprometida
5. Revisar: ListBucket, GetObject, PutObject, DeleteObject
6. Documentar: IPs, timestamps, archivos accedidos
```

### 3️⃣ NOTIFICAR A PROVEEDORES (15 min)

#### Cloudflare
```
Email: security@cloudflare.com
Asunto: API Token Exposure - cfat_SmYDRP5DLmGj6QKkErlmvgy5UlxLVv6iKh4PMX1Y6d67cfbd

Mensaje:
- Descripción: Token expuesto en public git repository
- Token: cfat_SmYDRP5DLmGj6QKkErlmvgy5UlxLVv6iKh4PMX1Y6d67cfbd
- Duración exposición: Marzo 24, 2026 - Septiembre 30, 2026
- Acción tomada: Token revocado hoy
- Solicitud: Auditar accesos a token en el período
```

#### AWS
```
Email: abuse@aws.amazon.com
Asunto: AWS Access Key Exposure in Public Repository

Mensaje:
- Access Key ID: 9e50a8dfa4d53fdcea91e918f13013ca
- Duración exposición: ~6 meses (Marzo - Septiembre)
- Acción tomada: Key desactivada/eliminada hoy
- Solicitud: Revisar accesos sospechosos en período
```

---

## 🛠️ LIMPIEZA DEL HISTORIO DE GIT

### ⚠️ ADVERTENCIA
Una vez ejecutado, el historio de git será reescrito. Todos los que trabajen en el repo deben hacer:
```bash
git fetch --all
git reset --hard origin/main
```

### OPCIÓN A: BFG Repo-Cleaner (RECOMENDADO)
```bash
# 1. Instalar BFG
brew install bfg

# 2. Navegar a folder de git
cd /Users/dchuquilla/projects/arriendo-facil/arriendo-facil

# 3. Crear mirror clone
git clone --mirror . arriendo-facil.git.mirror

# 4. Ejecutar BFG para eliminar el archivo
bfg --delete-files includes/other arriendo-facil.git.mirror

# 5. Refrescar y reempacar
cd arriendo-facil.git.mirror
git reflog expire --expire=now --all
git gc --prune=now --aggressive
cd ..

# 6. Push forzado (PELIGROSO - requiere confirmación)
# ⚠️ TODOS LOS COLABORADORES DEBEN ACTUALIZAR DESPUÉS
git -C arriendo-facil.git.mirror push --mirror --force https://github.com/tu-usuario/arriendo-facil.git
```

### OPCIÓN B: git-filter-branch
```bash
# 1. Navegar a repo
cd /Users/dchuquilla/projects/arriendo-facil/arriendo-facil

# 2. Crear backup
git clone . ../arriendo-facil-backup-2026-10-07

# 3. Ejecutar filter-branch
git filter-branch --tree-filter 'rm -f includes/other' -- --all

# 4. Limpiar reflog
git reflog expire --expire=now --all
git gc --prune=now --aggressive

# 5. Push forzado
git push --force --all origin
git push --force --tags origin

# 6. Avisar a colaboradores para `git reset --hard origin/main`
```

---

## 📞 CADENA DE COMUNICACIÓN

### Notificaciones Urgentes
```
1. Líderes técnicos / Security team - AHORA
2. Product/Project managers - HOY
3. Compliance/Legal - HOY
4. Clientes (si datos fueron accedidos) - HOY
5. Público (solo si muy grave) - HOY/MAÑANA
```

### Mensaje Plantilla
```
SUBJECT: 🔴 INCIDENTE SEGURIDAD - Claves Expuestas en Git

Tenemos un incidente de seguridad:

QUÉ: Credenciales de Cloudflare y AWS se encontraban en el historio de git

CUÁNDO: Desde 24 de Marzo 2026 (introducidas), 30 de Septiembre 2026 (descubiertas)

DÓNDE: Archivo `includes/other` en commits e550462 y 59d129c

QUÉ COMPROMETIDO:
- Cloudflare API Token
- AWS S3/R2 Access & Secret Keys
- Potencial acceso a todos los archivos en R2

QUÉ HACEMOS:
- ✅ Rotando todas las credenciales AHORA
- ✅ Auditando logs de acceso
- ✅ Limpiando historio de git
- ✅ Notificando a proveedores

ACCIÓN: Todos los desarrolladores deben hacer `git fetch --all && git reset --hard origin/main` después del push

CONTACTO: [Tu nombre] - [Email/Teléfono]
```

---

## 📝 CHECKLIST DE REMEDIACIÓN

### Inmediato (HOY - 1 hora)
- [ ] Revocar Cloudflare API Token: `cfat_SmYDRP5DLmGj6QKkErlmvgy5UlxLVv6iKh4PMX1Y6d67cfbd`
- [ ] Generar nuevo token de Cloudflare
- [ ] Actualizar código/config con nuevo token
- [ ] Probar funcionamiento de Cloudflare R2
- [ ] Revocar AWS Access Key: `9e50a8dfa4d53fdcea91e918f13013ca`
- [ ] Generar nuevo Access Key de AWS
- [ ] Actualizar código/config con nuevo key
- [ ] Probar funcionamiento de AWS/R2
- [ ] Avisar a equipo sobre cambios

### Dentro de 2 horas (HOY)
- [ ] Notificar a Cloudflare sobre exposición
- [ ] Notificar a AWS sobre exposición
- [ ] Auditar logs de Cloudflare (últimas 2 semanas)
- [ ] Auditar logs de AWS (últimas 2 semanas)
- [ ] Documentar cualquier acceso sospechoso
- [ ] Revisar si se descargaron datos sensibles

### Dentro de 4 horas (HOY)
- [ ] Ejecutar limpieza de historio de git (BFG o filter-branch)
- [ ] Push forzado al repositorio
- [ ] Avisar a todos los colaboradores para actualizar repos locales
- [ ] Verificar que nuevo código funciona correctamente
- [ ] Desplegar a producción con credenciales nuevas

### Fin de día (HOY)
- [ ] Documentar incidente completo
- [ ] Crear post-mortem
- [ ] Reunión de seguridad con líderes técnicos
- [ ] Revisar políticas de git (pre-commit hooks)
- [ ] Implementar git-secrets en proyecto

### Semana que viene
- [ ] Auditoría completa de otros repositorios
- [ ] Implementar GitHub/GitLab secret scanning
- [ ] Capacitación de equipo sobre secrets management
- [ ] Mejorar CI/CD pipeline para detectar secrets

---

## 🔍 VERIFICACIÓN DE MITIGACIÓN

### Cómo Verificar que Todo Está Limpio

```bash
# 1. Verificar que archivo no está en historio
git log --all --full-history -- includes/other

# Resultado esperado: (vacío, sin commits)

# 2. Verificar que credenciales antiguas no funcionan
# Intentar con token viejo en Cloudflare API
curl -H "Authorization: Bearer cfat_SmYDRP5DLmGj6QKkErlmvgy5UlxLVv6iKh4PMX1Y6d67cfbd" \
  https://api.cloudflare.com/client/v4/user

# Resultado esperado: { "success": false, "errors": [...], "result": null }

# 3. Verificar que nuevo token funciona
curl -H "Authorization: Bearer NEW_TOKEN_HERE" \
  https://api.cloudflare.com/client/v4/user

# Resultado esperado: { "success": true, "result": { "id": "...", "email": "..." } }
```

---

## 📚 REFERENCIAS

### Herramientas
- **BFG Repo-Cleaner**: https://rtyley.github.io/bfg-repo-cleaner/
- **git-secrets**: https://github.com/awslabs/git-secrets
- **TruffleHog**: https://github.com/trufflesecurity/trufflehog

### Políticas
- **OWASP Secrets Management**: https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html
- **CWE-798**: Use of Hard-Coded Credentials: https://cwe.mitre.org/data/definitions/798.html

### Documentación
- **Cloudflare API Tokens**: https://dash.cloudflare.com/profile/api-tokens
- **AWS Access Keys**: https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_access-keys.html
- **Git History Rewriting**: https://git-scm.com/book/en/v2/Git-Tools-Rewriting-History

---

## 📞 CONTACTOS DE EMERGENCIA

| Rol | Contacto |
|-----|----------|
| Security Lead | [Nombre] - [Email] |
| DevOps/Infra | [Nombre] - [Email] |
| Product Lead | [Nombre] - [Email] |
| Legal/Compliance | [Nombre] - [Email] |

---

**DOCUMENTO CONFIDENCIAL**  
**Generado**: 2026-10-07  
**Próxima revisión**: 2026-10-08  
**Estado**: ACTIVO - ACCIÓN REQUERIDA
