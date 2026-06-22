#!/bin/bash
#
# restoreLegacyApache.sh — Bascule TEMPORAIRE d'Apache vers l'ancienne interface SE4.
#
# C'est l'inverse de setupApache.sh : au lieu de servir SE5 (sambaedu-reload) en
# vhost principal sur le port 80 (avec le legacy SE4 relégué en loopback:8082),
# on remet le **SE4 legacy en frontal sur *:80** (DocumentRoot /var/www/sambaedu)
# et on désactive le vhost SE5 le temps voulu.
#
# RÉVERSIBLE : `off` restaure l'état SE5 EXACT. Le vhost SE5 actif n'est pas
# régénéré mais simplement mis de côté (stash) puis remis — on préserve donc les
# directives ajoutées hors setupApache.sh (XSendFile, etc.).
#
# Le vhost legacy loopback (127.0.0.1:8082) n'est PAS touché : il continue de
# tourner, inoffensif. Seul le vhost SE5 qui occupe *:80 est échangé.
#
# Usage (en root, SUR LA VM se4fs) :
#   bash scripts/restoreLegacyApache.sh on        # SE4 legacy sur le port 80
#   bash scripts/restoreLegacyApache.sh off       # retour à SE5
#   bash scripts/restoreLegacyApache.sh status    # état courant
#

set -euo pipefail

APACHE_SITES_AVAILABLE="/etc/apache2/sites-available"
APACHE_SITES_ENABLED="/etc/apache2/sites-enabled"

SE5_NAME="sambaedu.conf"                       # vhost SE5 occupant *:80
LEGACY_AVAIL="$APACHE_SITES_AVAILABLE/sambaedu-legacy.conf"  # source du vhost SE4 (loopback:8082)

SE4_DIRECT_NAME="sambaedu-se4-direct.conf"
SE4_AVAIL="$APACHE_SITES_AVAILABLE/$SE4_DIRECT_NAME"
SE4_ENABLED="$APACHE_SITES_ENABLED/$SE4_DIRECT_NAME"

STASH_DIR="/etc/apache2/se4-direct-stash"
MARKER="$STASH_DIR/.active"

# ─── Helpers ─────────────────────────────────────────────────────────────────

die()  { echo "ERREUR : $*" >&2; exit 1; }
info() { echo "  $*"; }

require_root_on_vm() {
    [ "$(id -u)" -eq 0 ] || die "Ce script doit être exécuté en root."
    [ -d "$APACHE_SITES_ENABLED" ] || die "$APACHE_SITES_ENABLED introuvable — exécute ce script SUR la VM se4fs, pas sur l'hôte."
}

# Recharge Apache après contrôle de syntaxe ; rollback fourni en cas d'échec.
reload_or_rollback() {
    local rollback_fn="$1"
    if ! apache2ctl configtest 2>/tmp/se4-configtest.log; then
        echo "--- apache2ctl configtest a ÉCHOUÉ ---" >&2
        cat /tmp/se4-configtest.log >&2
        info "Annulation des changements..."
        "$rollback_fn"
        die "Configuration Apache invalide — état précédent restauré."
    fi
    systemctl reload apache2
    info "Apache rechargé."
}

# Génère le vhost SE4 sur *:80 à partir du vhost legacy déployé (source de vérité,
# maintenue par setupApache.sh). Fallback : le backup SE4 le plus récent.
build_se4_vhost() {
    local servername="$1"
    local src=""

    if [ -f "$LEGACY_AVAIL" ]; then
        src="$LEGACY_AVAIL"
    else
        src=$(ls -t /etc/apache2/backups-*/sambaedu.conf.backup 2>/dev/null | head -1 || true)
    fi
    [ -n "$src" ] && [ -f "$src" ] || die "Aucune source de vhost SE4 ($LEGACY_AVAIL ni backup)."
    info "Source du vhost SE4 : $src"

    {
        echo "# Vhost SE4 legacy en frontal sur *:80 — TEMPORAIRE."
        echo "# Généré par restoreLegacyApache.sh à partir de : $src"
        echo "# Retour à SE5 : bash scripts/restoreLegacyApache.sh off"
    } > "$SE4_AVAIL"

    # La source a-t-elle déjà un ServerName ? (backup SE4 = oui ; legacy loopback = non)
    local has_sn=0
    grep -qiE '^[[:space:]]*ServerName' "$src" && has_sn=1

    # En une passe awk : rebinde la 1re ligne <VirtualHost ...> sur *:80 et, si la
    # source n'avait pas de ServerName, l'injecte juste après.
    awk -v sn="$servername" -v has_sn="$has_sn" '
        /<VirtualHost[^>]*>/ && !injected {
            print "<VirtualHost *:80>"
            if (has_sn == 0) print "    ServerName " sn
            injected = 1
            next
        }
        { print }
    ' "$src" >> "$SE4_AVAIL"
}

# ─── on ──────────────────────────────────────────────────────────────────────

cmd_on() {
    require_root_on_vm

    if [ -f "$MARKER" ]; then
        info "Déjà en mode SE4 (marqueur présent). Rien à faire."
        exit 0
    fi

    # ServerName récupéré AVANT le stash, depuis le vhost SE5 actif.
    local servername
    servername=$(grep -oP 'ServerName\s+\K\S+' "$APACHE_SITES_ENABLED/$SE5_NAME" 2>/dev/null || echo "se4fs")

    echo "=== Bascule vers l'ancienne interface SE4 (port 80) ==="

    mkdir -p "$STASH_DIR"

    # 1. Mettre de côté le vhost SE5 qui occupe *:80 (fichier OU symlink, tel quel).
    if [ -e "$APACHE_SITES_ENABLED/$SE5_NAME" ] || [ -L "$APACHE_SITES_ENABLED/$SE5_NAME" ]; then
        mv "$APACHE_SITES_ENABLED/$SE5_NAME" "$STASH_DIR/$SE5_NAME"
        info "Vhost SE5 mis de côté : $STASH_DIR/$SE5_NAME"
    else
        info "WARN : aucun $SE5_NAME actif (déjà désactivé ?)."
    fi

    # 2. Générer et activer le vhost SE4 sur *:80.
    build_se4_vhost "$servername"
    ln -sf "$SE4_AVAIL" "$SE4_ENABLED"
    info "Vhost SE4 activé sur *:80 (ServerName $servername)."

    # 3. Contrôle + reload (rollback = cmd_off silencieux).
    touch "$MARKER"
    reload_or_rollback _rollback_on

    echo ""
    echo "✓ SE4 legacy servi sur http://$servername/ (port 80)."
    echo "  SE5 désactivé temporairement. Retour : bash scripts/restoreLegacyApache.sh off"
}

# Rollback de cmd_on : retire le vhost SE4, remet le SE5 en place.
_rollback_on() {
    rm -f "$SE4_ENABLED" "$SE4_AVAIL"
    if [ -e "$STASH_DIR/$SE5_NAME" ] || [ -L "$STASH_DIR/$SE5_NAME" ]; then
        mv "$STASH_DIR/$SE5_NAME" "$APACHE_SITES_ENABLED/$SE5_NAME"
    fi
    rm -f "$MARKER"
}

# ─── off ─────────────────────────────────────────────────────────────────────

cmd_off() {
    require_root_on_vm

    if [ ! -f "$MARKER" ]; then
        info "Pas en mode SE4 (aucun marqueur). Rien à faire."
        exit 0
    fi

    echo "=== Retour à l'interface SE5 (port 80) ==="

    # 1. Retirer le vhost SE4 direct.
    rm -f "$SE4_ENABLED" "$SE4_AVAIL"
    info "Vhost SE4 direct retiré."

    # 2. Restaurer le vhost SE5 mis de côté (à l'identique).
    if [ -e "$STASH_DIR/$SE5_NAME" ] || [ -L "$STASH_DIR/$SE5_NAME" ]; then
        mv "$STASH_DIR/$SE5_NAME" "$APACHE_SITES_ENABLED/$SE5_NAME"
        info "Vhost SE5 restauré."
    else
        die "Stash $STASH_DIR/$SE5_NAME introuvable — vhost SE5 à réactiver manuellement (setupApache.sh)."
    fi

    rm -f "$MARKER"

    # 3. Contrôle + reload. On restaure un vhost SE5 connu-valide : pas de
    # rollback automatique, mais on alerte fort si la syntaxe casse.
    if ! apache2ctl configtest 2>/tmp/se4-configtest.log; then
        cat /tmp/se4-configtest.log >&2
        die "configtest KO après restauration SE5 — NE PAS recharger Apache, inspecter $APACHE_SITES_ENABLED."
    fi
    systemctl reload apache2
    info "Apache rechargé."

    echo ""
    echo "✓ SE5 de nouveau servi sur le port 80."
}

# ─── status ──────────────────────────────────────────────────────────────────

cmd_status() {
    [ -d "$APACHE_SITES_ENABLED" ] || die "$APACHE_SITES_ENABLED introuvable — exécute ce script SUR la VM se4fs."

    echo "=== État Apache ==="
    if [ -f "$MARKER" ]; then
        echo "  Mode      : SE4 LEGACY sur *:80 (temporaire)"
    else
        echo "  Mode      : SE5 (sambaedu-reload) sur *:80 — état nominal"
    fi
    echo "  apache2   : $(systemctl is-active apache2 2>/dev/null || echo inconnu)"
    echo "  vhosts actifs :"
    ls -1 "$APACHE_SITES_ENABLED" | sed 's/^/    - /'
    echo "  syntaxe   : $(apache2ctl configtest 2>&1 | tail -1)"
}

# ─── Dispatch ────────────────────────────────────────────────────────────────

case "${1:-}" in
    on)     cmd_on ;;
    off)    cmd_off ;;
    status) cmd_status ;;
    *)
        echo "Usage : bash $0 {on|off|status}"
        echo "  on     — sert l'ancienne interface SE4 sur le port 80 (désactive SE5)"
        echo "  off    — retour à SE5 sur le port 80"
        echo "  status — affiche le mode courant"
        exit 1
        ;;
esac
