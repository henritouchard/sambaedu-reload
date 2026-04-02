#!/bin/bash
# ============================================================================
# SambaEdu — Mise à jour du .env
# Ajoute les variables présentes dans .env.example mais absentes du .env,
# puis les pré-remplit depuis /etc/sambaedu/ (même logique que create-env.sh).
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"

# Colors
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log()         { echo -e "${BLUE}[env]${NC} $*"; }
log_success() { echo -e "${GREEN}[✓]${NC} $*"; }
log_warning() { echo -e "${YELLOW}[!]${NC} $*"; }

cd "$APP_DIR"

if [[ ! -f .env.example || ! -f .env ]]; then
    log_warning ".env.example ou .env introuvable"
    exit 0
fi

# ── Détection des variables manquantes ──
missing_keys=$(comm -23 \
    <(grep -E '^[A-Z_]+=' .env.example | cut -d= -f1 | sort) \
    <(grep -E '^[A-Z_]+=' .env | cut -d= -f1 | sort) || true)

if [[ -z "$missing_keys" ]]; then
    log_success "Toutes les variables d'environnement sont présentes"
    exit 0
fi

log_warning "Variables présentes dans .env.example mais absentes de .env — ajout..."

# ── Ajouter chaque variable manquante avec sa valeur par défaut ──
while IFS= read -r key; do
    default_line=$(grep -E "^${key}=" .env.example | head -1)
    echo "$default_line" >> .env
    log "  + $default_line"
done <<< "$missing_keys"

# ── Pré-remplir depuis /etc/sambaedu/ (même logique que create-env.sh) ──
if [[ -f "/etc/sambaedu/sambaedu.conf" ]]; then
    source <(grep -E '^[a-z_]+ = ' /etc/sambaedu/sambaedu.conf | sed 's/ = /=/g')
fi
if [[ -d "/etc/sambaedu/sambaedu.conf.d" ]]; then
    for conf_file in /etc/sambaedu/sambaedu.conf.d/*.conf; do
        [[ -f "$conf_file" ]] || continue
        source <(grep -E '^[a-z_]+ = ' "$conf_file" | sed 's/ = /=/g')
    done
fi

[ -n "${se4ad_ip:-}" ] && sed -i "s|SAMBAEDU_SE4AD_IP=.*|SAMBAEDU_SE4AD_IP=$se4ad_ip|" .env
[ -n "${se4fs_ip:-}" ] && sed -i "s|SAMBAEDU_SE4FS_IP=.*|SAMBAEDU_SE4FS_IP=$se4fs_ip|" .env
[ -n "${se4fs_name:-}" ] && sed -i "s|SAMBAEDU_SE4FS_NAME=.*|SAMBAEDU_SE4FS_NAME=$se4fs_name|" .env
[ -n "${ipxe_url:-}" ] && sed -i "s|SAMBAEDU_IPXE_URL=.*|SAMBAEDU_IPXE_URL=$ipxe_url|" .env
[ -n "${se4install_name:-}" ] && sed -i "s|SAMBAEDU_SE4INSTALL_NAME=.*|SAMBAEDU_SE4INSTALL_NAME=$se4install_name|" .env
[ -n "${se4install_passwd:-}" ] && sed -i "s|SAMBAEDU_SE4INSTALL_PASSWD=.*|SAMBAEDU_SE4INSTALL_PASSWD=$se4install_passwd|" .env

log_success "Variables manquantes ajoutées au .env"
