#!/bin/bash

# ============================================================================
# SCRIPT DE REMEDIACIÓN - INCIDENTE DE SEGURIDAD: CLAVES EXPUESTAS EN GIT
# ============================================================================
#
# Este script automatiza la limpieza del historio de git para eliminar
# las credenciales comprometidas.
#
# ⚠️ ADVERTENCIA: Este script REESCRIBE el historio de git.
# 
# ANTES DE EJECUTAR:
# 1. Rotar Cloudflare token y AWS keys en los dashboards
# 2. Actualizar credenciales en código/producción
# 3. Backup del repositorio
# 4. Avisar a todos los colaboradores
#
# DESPUÉS DE EJECUTAR:
# 1. Todos ejecutan: git fetch --all && git reset --hard origin/main
# 2. Verificar que aplicación sigue funcionando
# ============================================================================

set -e  # Salir si hay error

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# ============================================================================
# CONFIGURACIÓN
# ============================================================================

REPO_DIR="/Users/dchuquilla/projects/arriendo-facil/arriendo-facil"
BACKUP_DIR="${REPO_DIR}-backup-$(date +%Y-%m-%d-%H%M%S)"
FILE_TO_DELETE="includes/other"
REMOTE="origin"
BRANCH="main"

# ============================================================================
# FUNCIONES DE UTILIDAD
# ============================================================================

print_banner() {
    echo -e "${BLUE}"
    echo "╔════════════════════════════════════════════════════════════════╗"
    echo "║  SCRIPT DE REMEDIACIÓN - CREDENCIALES EXPUESTAS EN GIT        ║"
    echo "║  Severidad: CRÍTICA                                           ║"
    echo "╚════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

print_step() {
    local step=$1
    local description=$2
    echo -e "\n${BLUE}[PASO $step]${NC} $description"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

wait_confirmation() {
    local prompt=$1
    read -p "$(echo -e ${YELLOW}$prompt${NC}) (s/n): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Ss]$ ]]; then
        print_error "Operación cancelada por usuario"
        exit 1
    fi
}

# ============================================================================
# PRE-CHECKS
# ============================================================================

print_banner

print_step "1" "Verificación de pre-requisitos"

# Verificar que git está instalado
if ! command -v git &> /dev/null; then
    print_error "git no está instalado"
    exit 1
fi
print_success "git está disponible"

# Verificar que estamos en un repositorio git
if [ ! -d "$REPO_DIR/.git" ]; then
    print_error "No es un repositorio git válido: $REPO_DIR"
    exit 1
fi
print_success "Repositorio git válido"

cd "$REPO_DIR"
print_success "Navegado a: $REPO_DIR"

# Verificar que el archivo existe en el historio
if ! git log --all --full-history -- "$FILE_TO_DELETE" | grep -q "commit"; then
    print_warning "El archivo $FILE_TO_DELETE no se encontró en el historio"
    print_warning "Puede que ya haya sido eliminado previamente"
else
    print_success "Archivo $FILE_TO_DELETE encontrado en historio"
fi

# Verificar que no hay cambios sin comitear
if [ -n "$(git status --porcelain)" ]; then
    print_error "Hay cambios sin comitear. Hacer commit o stash primero:"
    git status
    exit 1
fi
print_success "Working directory limpio"

# ============================================================================
# CONFIRMACIONES
# ============================================================================

print_step "2" "Confirmaciones de seguridad"

print_warning "IMPORTANTE: Antes de continuar, confirma que:"
echo "  1. ✓ Has rotado el token de Cloudflare"
echo "  2. ✓ Has rotado las AWS Access Keys"
echo "  3. ✓ Has actualizado el código con credenciales nuevas"
echo "  4. ✓ La aplicación funciona con credenciales nuevas"
echo "  5. ✓ Has hecho backup del repositorio"
echo "  6. ✓ Has avisado a todos los colaboradores"
echo ""

wait_confirmation "¿Entiendes y confirmas todos los puntos anteriores?"

wait_confirmation "¿REALMENTE deseas proceder con la eliminación de credenciales del historio de git?"

# ============================================================================
# BACKUP
# ============================================================================

print_step "3" "Crear backup del repositorio"

print_warning "Creando backup en: $BACKUP_DIR"
cp -r "$REPO_DIR" "$BACKUP_DIR"
print_success "Backup creado exitosamente"

# ============================================================================
# MÉTODO 1: BFG Repo-Cleaner (RECOMENDADO)
# ============================================================================

print_step "4" "Verificar disponibilidad de BFG Repo-Cleaner"

if command -v bfg &> /dev/null; then
    print_success "BFG Repo-Cleaner está instalado"
    USE_BFG=true
else
    print_warning "BFG Repo-Cleaner no está instalado"
    read -p "$(echo -e ${YELLOW}¿Deseas instalar BFG? (s/n)${NC}) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Ss]$ ]]; then
        print_step "4b" "Instalar BFG Repo-Cleaner"
        brew install bfg
        USE_BFG=true
    else
        USE_BFG=false
    fi
fi

# ============================================================================
# LIMPIAR HISTORIO CON BFG (si disponible)
# ============================================================================

if [ "$USE_BFG" = true ]; then
    print_step "5" "Crear mirror clone para limpieza con BFG"
    
    MIRROR_DIR="${REPO_DIR}.git.mirror"
    if [ -d "$MIRROR_DIR" ]; then
        print_warning "Mirror ya existe, eliminando..."
        rm -rf "$MIRROR_DIR"
    fi
    
    git clone --mirror "$REPO_DIR" "$MIRROR_DIR"
    print_success "Mirror clone creado: $MIRROR_DIR"
    
    print_step "6" "Ejecutar BFG para eliminar archivo"
    
    bfg --delete-files "$FILE_TO_DELETE" "$MIRROR_DIR"
    print_success "BFG completó la limpieza"
    
    print_step "7" "Refrescar y reempacar historio"
    
    cd "$MIRROR_DIR"
    git reflog expire --expire=now --all
    git gc --prune=now --aggressive
    print_success "Historio refrescado y reempacado"
    cd "$REPO_DIR"
    
    print_step "8" "Sincronizar cambios de vuelta al repositorio local"
    
    git fetch mirror '+refs/heads/*:refs/heads/*' --force || true
    git fetch mirror '+refs/tags/*:refs/tags/*' --force || true
    
    # Actualizar local repo desde mirror
    cd "$MIRROR_DIR"
    git update-ref -d refs/heads/master
    cd "$REPO_DIR"
    
    print_success "Cambios sincronizados"
    
else
    # ========================================================================
    # MÉTODO 2: git-filter-branch (ALTERNATIVA)
    # ========================================================================
    
    print_step "5" "Usar git-filter-branch para limpiar historio"
    
    print_warning "Ejecutando git-filter-branch (esto puede tardar)..."
    
    git filter-branch --tree-filter "rm -f '$FILE_TO_DELETE'" -- --all
    print_success "git-filter-branch completó la limpieza"
    
    print_step "6" "Refrescar reflog"
    
    git reflog expire --expire=now --all
    git gc --prune=now --aggressive
    print_success "Historio refrescado"
fi

# ============================================================================
# VERIFICACIÓN
# ============================================================================

print_step "9" "Verificar que credenciales fueron eliminadas"

if git log --all --full-history -p -- "$FILE_TO_DELETE" | grep -i "cfat_\|Access Key\|Secret"; then
    print_error "FALLO: Aún hay credenciales en el historio"
    exit 1
fi

print_success "Verificación exitosa: No hay credenciales en el historio"

# ============================================================================
# PUSH FORZADO
# ============================================================================

print_step "10" "Push forzado de cambios"

print_warning "Ejecutando: git push --force-with-lease --all $REMOTE"
wait_confirmation "¿Proceder con el push forzado?"

git push --force-with-lease --all "$REMOTE"
print_success "Push forzado completado"

git push --force-with-lease --tags "$REMOTE" || true
print_success "Tags pushed"

# ============================================================================
# POST-ACCIÓN
# ============================================================================

print_step "11" "Acciones post-remediación"

echo -e "\n${GREEN}✓ LIMPIEZA COMPLETADA${NC}\n"

echo -e "${YELLOW}ACCIONES PENDIENTES:${NC}"
echo ""
echo "1. NOTIFICAR A COLABORADORES:"
echo "   Todos deben ejecutar los siguientes comandos:"
echo ""
echo "   ${BLUE}git fetch --all${NC}"
echo "   ${BLUE}git reset --hard origin/$BRANCH${NC}"
echo ""

echo "2. VERIFICAR FUNCIONAMIENTO:"
echo "   ${BLUE}cd $REPO_DIR${NC}"
echo "   ${BLUE}npm install && npm test${NC}"
echo "   (O el comando de build de tu proyecto)"
echo ""

echo "3. LIMPIAR BACKUP:"
echo "   Si todo funciona correctamente, puedes eliminar:"
echo "   ${BLUE}rm -rf $BACKUP_DIR${NC}"
echo "   ${BLUE}rm -rf ${REPO_DIR}.git.mirror${NC}"
echo ""

echo "4. VERIFICAR LOGS:"
echo "   ${BLUE}git log --all --full-history -- '$FILE_TO_DELETE'${NC}"
echo "   (Debe estar vacío)"
echo ""

echo "5. DOCUMENTAR INCIDENTE:"
echo "   - Documentar paso a paso lo que se hizo"
echo "   - Guardar este log"
echo "   - Revisar auditoría de Cloudflare/AWS"
echo ""

echo -e "${RED}⚠️ RECUERDA:${NC}"
echo "   - El archivo backup está en: $BACKUP_DIR"
echo "   - El mirror está en: ${REPO_DIR}.git.mirror"
echo "   - Estos ocupan espacio y deben eliminarse después"
echo ""

# ============================================================================
# LIMPIAR CERTIFICADOS/MIRROR
# ============================================================================

read -p "$(echo -e ${YELLOW}¿Deseas limpiar el mirror y otros archivos temporales? (s/n)${NC}) " -n 1 -r
echo
if [[ $REPLY =~ ^[Ss]$ ]]; then
    print_step "12" "Limpiar archivos temporales"
    
    if [ -d "${REPO_DIR}.git.mirror" ]; then
        rm -rf "${REPO_DIR}.git.mirror"
        print_success "Mirror eliminado"
    fi
    
    # No eliminar backup - es demasiado peligroso automaticamente
    echo -e "${YELLOW}Backup guardado en: $BACKUP_DIR${NC}"
    echo "Elimínalo manualmente cuando confirmes que todo funciona:"
    echo "  rm -rf $BACKUP_DIR"
fi

echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║  ✓ SCRIPT DE REMEDIACIÓN COMPLETADO                          ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════════╝${NC}"
echo ""
