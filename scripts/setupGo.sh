#!/bin/bash
# ============================================================================
# SambaEdu — Setup toolchain Go (agent) + cache partagé + dépendances
# ============================================================================
# Installe la toolchain Go ÉPINGLÉE (version + SHA-256), la rend disponible sur
# le PATH de tous (symlink /usr/local/bin + /etc/profile.d), prépare un cache Go
# PARTAGÉ inscriptible par l'utilisateur qui lance `php artisan test`
# (www-admin), puis télécharge/valide les dépendances du module `agent/`.
#
# Objectif : après cette étape, `go` est sur le PATH ET `php artisan test`
# (pont tests/Feature/Agent/GoAgentTest.php) peut lancer `go test ./...` sans
# heurter de problème de droits sur le cache.
#
# IDEMPOTENT : no-op si la bonne version est déjà installée et les deps en cache.
# Appelé par scripts/update.sh (setup_go) — donc aussi par install.sh.
# Utilisable seul : sudo scripts/setupGo.sh [--toolchain-only]
#
# ⚠️ La version épinglée DOIT rester alignée avec scripts/build-agent.sh
#    (GO_VERSION/GO_SHA256) — bump volontaire des deux ensemble (source :
#    https://go.dev/dl/).
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"

# --- Toolchain Go épinglée (alignée avec build-agent.sh) ----------------------
GO_VERSION="1.26.4"
GO_SHA256="1153d3d50e0ac764b447adfe05c2bcf08e889d42a02e0fe0259bd47f6733ad7f"
GO_PREFIX="/usr/local/go"

# Utilisateur qui exécute PHP/`php artisan test` (cf. mémoire www-admin uid 599).
WEB_USER="${SAMBAEDU_WEB_USER:-www-admin}"

# Cache Go PARTAGÉ — mêmes chemins que le pont PHPUnit (GoAgentTest.php), sous
# storage/ (inscriptible par www-admin, hors git). GOMODCACHE dérive de GOPATH.
GO_PATH_DIR="$APP_DIR/storage/framework/cache/go"
GO_CACHE_DIR="$APP_DIR/storage/framework/cache/go-build"

TOOLCHAIN_ONLY=false
[[ "${1:-}" == "--toolchain-only" ]] && TOOLCHAIN_ONLY=true

log()         { echo -e "\033[0;34m[setupGo]\033[0m $*"; }
log_success() { echo -e "\033[0;32m[✓]\033[0m $*"; }
log_warning() { echo -e "\033[1;33m[!]\033[0m $*"; }
log_error()   { echo -e "\033[0;31m[✗]\033[0m $*"; }

# --- Prérequis ----------------------------------------------------------------
if [[ ! -f "$APP_DIR/agent/go.mod" ]]; then
    log "Module agent (agent/go.mod) absent — rien à faire."
    exit 0
fi

# Détermine si on doit (ré)installer la toolchain (absente ou version != épinglée).
need_install=true
GO_BIN=""
if [[ -x "$GO_PREFIX/bin/go" ]] && "$GO_PREFIX/bin/go" version 2>/dev/null | grep -q "go${GO_VERSION}"; then
    GO_BIN="$GO_PREFIX/bin/go"
    need_install=false
elif command -v go >/dev/null 2>&1 && go version 2>/dev/null | grep -q "go${GO_VERSION}"; then
    GO_BIN="$(command -v go)"
    need_install=false
fi

# --- Installation toolchain (root requis) -------------------------------------
if [[ "$need_install" == true ]]; then
    if [[ "${EUID}" -ne 0 ]]; then
        log_error "Toolchain Go ${GO_VERSION} absente et droits root requis pour l'installer dans $GO_PREFIX."
        log_error "Relancer : sudo scripts/setupGo.sh"
        exit 1
    fi
    log "Installation go${GO_VERSION} dans $GO_PREFIX..."
    tarball="$(mktemp /tmp/go.XXXXXX.tar.gz)"
    trap 'rm -f "$tarball"' EXIT
    curl -fsSL "https://go.dev/dl/go${GO_VERSION}.linux-amd64.tar.gz" -o "$tarball"
    echo "${GO_SHA256}  ${tarball}" | sha256sum -c - >/dev/null
    rm -rf "$GO_PREFIX"
    tar -C /usr/local -xzf "$tarball"
    GO_BIN="$GO_PREFIX/bin/go"
    log_success "Toolchain installée : $("$GO_BIN" version)"
else
    log_success "Toolchain déjà présente : $("$GO_BIN" version)"
fi

# --- PATH système : symlink /usr/local/bin (non-login) + profile.d (login) ----
if [[ "${EUID}" -eq 0 ]]; then
    for tool in go gofmt; do
        if [[ -x "$GO_PREFIX/bin/$tool" ]]; then
            ln -sf "$GO_PREFIX/bin/$tool" "/usr/local/bin/$tool"
        fi
    done
    cat > /etc/profile.d/sambaedu-go.sh <<EOF
# Toolchain Go SambaEdu (posée par scripts/setupGo.sh)
export PATH="\$PATH:${GO_PREFIX}/bin"
EOF
    chmod 0644 /etc/profile.d/sambaedu-go.sh
    log_success "Go disponible sur le PATH (/usr/local/bin/go + profile.d)"
else
    log_warning "Non-root : symlink PATH + profile.d non posés (toolchain utilisable via $GO_BIN)."
fi

if [[ "$TOOLCHAIN_ONLY" == true ]]; then
    log "Mode --toolchain-only : cache + deps ignorés."
    exit 0
fi

# --- Cache Go partagé (inscriptible par l'utilisateur des tests) --------------
# Détermine le propriétaire cible : www-admin s'il existe, sinon l'utilisateur
# courant (poste de dev sans pool PHP dédié).
cache_owner="$WEB_USER"
if ! id "$WEB_USER" >/dev/null 2>&1; then
    cache_owner="$(id -un)"
fi

mkdir -p "$GO_PATH_DIR" "$GO_CACHE_DIR"
if [[ "${EUID}" -eq 0 ]]; then
    chown -R "$cache_owner":"$cache_owner" "$GO_PATH_DIR" "$GO_CACHE_DIR" 2>/dev/null || true
fi
chmod 0775 "$GO_PATH_DIR" "$GO_CACHE_DIR"

# --- Téléchargement + validation des dépendances du module agent --------------
# Exécuté EN TANT QUE le propriétaire du cache pour que le module cache et le
# build cache aient les bons droits (sinon `go test` lancé par www-admin ne peut
# pas écrire). GOTOOLCHAIN=local : jamais de fetch réseau d'une autre toolchain.
log "Téléchargement des dépendances du module agent (go mod download + verify)..."

# Commande deps montée comme une chaîne (env inline) pour être rejouable telle
# quelle sous `sudo -u <cache_owner>`.
go_deps_inner="cd '$APP_DIR/agent' && \
GOPATH='$GO_PATH_DIR' GOCACHE='$GO_CACHE_DIR' GOTOOLCHAIN=local GOFLAGS=-mod=mod '$GO_BIN' mod download && \
GOPATH='$GO_PATH_DIR' GOCACHE='$GO_CACHE_DIR' GOTOOLCHAIN=local '$GO_BIN' mod verify"

deps_ok=false
if [[ "${EUID}" -eq 0 && "$cache_owner" != "root" ]]; then
    if sudo -u "$cache_owner" -H bash -c "$go_deps_inner"; then
        deps_ok=true
    fi
else
    if bash -c "$go_deps_inner"; then
        deps_ok=true
    fi
fi

if [[ "$deps_ok" == true ]]; then
    log_success "Dépendances Go en cache ($GO_PATH_DIR) — go test prêt."
else
    log_warning "go mod download/verify a échoué — vérifier l'accès réseau à proxy.golang.org. L'update continue."
fi

# Note : on ne lance PAS `go mod tidy` (modifierait go.mod/go.sum) — réservé au dev.
log_success "setupGo terminé."
