#!/bin/bash
# =============================================================================
# sambaedu-ext-helper.sh — Story 56.2
#
# LA frontière de privilège du système d'extensions SE5.
#
# www-admin (l'utilisateur PHP-FPM) n'exécute JAMAIS de commande root
# directement : il appelle ce script par `sudo -n`, via l'unique ligne
#
#     www-admin ALL=(root) NOPASSWD: /usr/share/sambaedu/sbin/sambaedu-ext-helper.sh
#
# posée par `ensure_extension_engine` (install.sh / update.sh) dans
# /etc/sudoers.d/sambaedu-ext. C'est exactement le patron déjà en place pour le
# DHCP (make_dhcpd_conf.sh), avec UNE différence justifiée : un helper à
# SOUS-COMMANDES plutôt que N scripts, parce que la surface sudoers reste d'une
# seule ligne et que TOUTES les validations vivent au même endroit, côté root.
#
# ─────────────────────────────────────────────────────────────────────────────
# CE QUE CE SCRIPT GARANTIT — ET CE QU'IL NE GARANTIT PAS (review 56.2 #1)
#
# Ce helper contraint la FORME de ce qui est fait en root : les chemins écrits,
# le namespace des paquets, le contenu de la configuration Apache, la manière
# dont le secret circule. Sur ce périmètre, il re-valide tout comme si
# l'appelant était hostile, sans jamais faire confiance au PHP.
#
# Il ne garantit PAS l'AUTHENTICITÉ du paquet installé. C'est structurel, pas un
# oubli : l'empreinte de référence et la clé publique de la source vivent en
# base, que www-admin lit et écrit. Un helper qui demanderait le sha256 attendu
# à son appelant ne vérifierait qu'une chose que cet appelant a choisie — une
# garantie en trompe-l'œil. La chaîne de confiance réelle (index Ed25519 signé →
# sha256 du paquet → refus fail-closed AVANT toute exécution) est appliquée par
# `ExtensionInstallService`, et elle protège contre ce qu'elle doit protéger :
# un dépôt tiers hostile, un miroir corrompu, un MITM.
#
# Face à un www-admin DÉJÀ compromis, ce script n'est donc pas une frontière
# infranchissable — et il ne pourrait pas l'être seul. C'est cohérent avec le
# reste du projet : le même compte pilote déjà `make_dhcpd_conf.sh`, les ACL du
# système de fichiers, le SYSVOL et les GPO du parc. Ce qu'il apporte reste
# réel : un bug du moteur ne peut pas écrire hors des chemins dérivés, ni poser
# une conf Apache arbitraire, ni écraser un paquet système, ni fuiter un secret
# par argv. Pour en faire une vraie frontière il faudrait retirer à www-admin
# l'écriture du staging (le helper devenant seul écrivain) — chantier
# d'exploitation, hors périmètre de cette story.
#
# Concrètement, ce qui EST re-validé ici :
#
#   * <key> est re-validée contre ^[a-z0-9][a-z0-9_-]{0,63}$ ;
#   * les chemins (unité, env, fragment) sont DÉRIVÉS de <key>, jamais reçus
#     en argument — on ne peut donc pas faire écrire ce script ailleurs ;
#   * le .deb doit se trouver SOUS le staging (realpath comparé) ;
#   * `dpkg-deb --field <deb> Package` DOIT valoir sambaedu-ext-<key> : un .deb
#     au bon sha256 mais au mauvais nom de paquet ne peut pas remplacer un
#     paquet système ;
#   * le fragment Apache est GÉNÉRÉ ici depuis un template interne. Accepter du
#     contenu de configuration arbitraire depuis www-admin serait un
#     équivalent-root (un SetHandler ou un Alias malveillant suffirait) ;
#   * `reload-apache` fait `apache2ctl configtest` D'ABORD : un fragment
#     invalide ne peut jamais tuer Apache. Jamais de `restart`, seulement
#     `reload`.
#
# Le SECRET du client OIDC arrive par STDIN (`write-env`), jamais en argument :
# argv est lisible dans /proc/<pid>/cmdline, dans un `ps` de n'importe quel
# utilisateur, et sudo journalise la commande complète.
#
# Les sous-commandes `remove-*` et `disable-service` sont IDEMPOTENTES : un
# composant déjà absent est un succès (exit 0), pour que `ext:remove` soit à la
# fois la désinstallation nominale et l'outil de nettoyage d'un état dégradé.
# =============================================================================

set -euo pipefail

readonly ENV_DIR="/etc/sambaedu/extensions"
readonly FRAGMENT_DIR="/etc/apache2/sambaedu-ext.d"
readonly STAGING_DIR="/var/www/sambaedu-reload/storage/app/extensions/packages"
# Staging ROOT-ONLY (0700) : le paquet y est FIGÉ avant d'être inspecté puis
# installé. Sans cette copie, le fichier inspecté par `dpkg-deb --field` et le
# fichier réellement passé à `apt-get` peuvent différer — le staging d'origine
# est accessible en écriture à www-admin, qui peut l'échanger entre les deux.
readonly FROZEN_DIR="/var/lib/sambaedu/ext-packages"
readonly PACKAGE_PREFIX="sambaedu-ext-"

die() {
    echo "sambaedu-ext-helper: $*" >&2
    exit 2
}

usage() {
    cat >&2 <<'USAGE'
Usage: sambaedu-ext-helper.sh <sous-commande> [args]

  write-env       <key>          (contenu du fichier .env sur STDIN)
  remove-env      <key>
  install-package <key> <deb>    (<deb> = chemin absolu sous le staging)
  remove-package  <key>
  enable-service  <key>
  disable-service <key>
  write-fragment  <key> <port>
  remove-fragment <key>
  reload-apache
USAGE
    exit 2
}

# ── Validations ──────────────────────────────────────────────────────────────

require_root() {
    [[ "$(id -u)" -eq 0 ]] || die "doit être exécuté en root (via sudo)"
}

validate_key() {
    local key="${1:-}"
    [[ -n "$key" ]] || die "clé d'extension manquante"
    [[ "$key" =~ ^[a-z0-9][a-z0-9_-]{0,63}$ ]] \
        || die "clé d'extension invalide : « $key »"
}

validate_port() {
    local port="${1:-}"
    [[ "$port" =~ ^[0-9]{4,5}$ ]] || die "port invalide : « $port »"
    [[ "$port" -ge 1024 && "$port" -le 65535 ]] || die "port hors plage : « $port »"
}

# Le .deb DOIT être un fichier régulier situé SOUS le staging, comparé après
# résolution des liens symboliques : sans ce contrôle, un lien posé dans le
# staging ferait installer n'importe quel fichier de la machine.
validate_deb_path() {
    local deb="${1:-}"
    [[ -n "$deb" ]] || die "chemin du paquet manquant"
    [[ -f "$deb" ]] || die "paquet introuvable"

    local real staging_real
    real="$(readlink -f -- "$deb")" || die "chemin de paquet non résolvable"
    staging_real="$(readlink -f -- "$STAGING_DIR" 2>/dev/null || true)"
    [[ -n "$staging_real" ]] || die "staging de paquets absent : $STAGING_DIR"

    [[ "$real" == "$staging_real"/* ]] \
        || die "paquet hors du staging autorisé"

    [[ -f "$real" ]] || die "paquet introuvable après résolution"
    echo "$real"
}

# Le NOM DE PAQUET déclaré dans le .deb doit appartenir au namespace de la clé.
# C'est ce contrôle qui empêche un manifest tiers — même parfaitement signé —
# de faire installer ou écraser un paquet système (openssh-server, sudo…).
validate_deb_package_name() {
    local deb="$1" key="$2" declared expected
    expected="${PACKAGE_PREFIX}${key}"

    command -v dpkg-deb >/dev/null 2>&1 || die "dpkg-deb indisponible"

    declared="$(dpkg-deb --field "$deb" Package 2>/dev/null | tr -d '[:space:]')" \
        || die "paquet illisible (dpkg-deb)"

    [[ "$declared" == "$expected" ]] \
        || die "nom de paquet refusé : « $declared » (attendu « $expected »)"
}

# ── Sous-commandes ───────────────────────────────────────────────────────────

cmd_write_env() {
    local key="$1"
    validate_key "$key"

    install -d -m 0700 -o root -g root "$ENV_DIR"

    local target="${ENV_DIR}/${key}.env"
    local tmp
    tmp="$(mktemp "${ENV_DIR}/.${key}.XXXXXX")"
    chmod 0600 "$tmp"
    chown root:root "$tmp"

    # Le contenu (qui porte le client_secret OIDC) arrive par STDIN. Il n'est
    # ni affiché, ni journalisé, ni passé en argument.
    cat > "$tmp"

    mv -f "$tmp" "$target"
    chmod 0600 "$target"
    chown root:root "$target"
}

cmd_remove_env() {
    local key="$1"
    validate_key "$key"
    rm -f "${ENV_DIR}/${key}.env"
}

cmd_install_package() {
    local key="$1" deb="$2"
    validate_key "$key"

    local real
    real="$(validate_deb_path "$deb")"

    # ── Figer le paquet AVANT de l'inspecter (review 56.2 #1) ───────────────
    # Le staging d'origine est accessible en écriture à www-admin. Inspecter
    # puis installer le MÊME chemin laisse une fenêtre : entre le
    # `dpkg-deb --field` qui valide le nom et l'`apt-get` qui exécute les
    # maintainer scripts en root, le fichier peut être remplacé. On copie donc
    # sous un répertoire root-only, et TOUT ce qui suit — inspection comme
    # installation — porte sur cette copie, que l'appelant ne peut plus
    # atteindre.
    install -d -m 0700 -o root -g root "$FROZEN_DIR"

    local frozen="${FROZEN_DIR}/${key}.deb"
    rm -f "$frozen"
    cp -- "$real" "$frozen"
    chmod 0600 "$frozen"
    chown root:root "$frozen"

    # `trap` plutôt qu'un `rm` final : la copie ne doit pas survivre à un échec
    # d'apt (`set -e` sort immédiatement).
    trap 'rm -f "$frozen"' RETURN

    validate_deb_package_name "$frozen" "$key"

    DEBIAN_FRONTEND=noninteractive apt-get install -y "$frozen"
}

cmd_remove_package() {
    local key="$1"
    validate_key "$key"

    local pkg="${PACKAGE_PREFIX}${key}"

    # Idempotence : un paquet jamais installé n'est pas une erreur.
    if dpkg-query -W -f='${Status}' "$pkg" 2>/dev/null | grep -q 'install ok installed'; then
        DEBIAN_FRONTEND=noninteractive apt-get purge -y "$pkg"
    fi
}

cmd_enable_service() {
    local key="$1"
    validate_key "$key"
    systemctl enable --now "${PACKAGE_PREFIX}${key}.service"
}

cmd_disable_service() {
    local key="$1"
    validate_key "$key"

    local unit="${PACKAGE_PREFIX}${key}.service"

    # Idempotent : `stop`/`disable` d'une unité absente ne doit pas échouer.
    systemctl stop "$unit" >/dev/null 2>&1 || true
    systemctl disable "$unit" >/dev/null 2>&1 || true
    return 0
}

# Le fragment est GÉNÉRÉ ICI, jamais reçu. `retry=0` : un backend arrêté rend
# 503 tout de suite plutôt que de faire attendre le client.
cmd_write_fragment() {
    local key="$1" port="$2"
    validate_key "$key"
    validate_port "$port"

    install -d -m 0755 -o root -g root "$FRAGMENT_DIR"

    local target="${FRAGMENT_DIR}/${key}.conf"
    local tmp
    tmp="$(mktemp "${FRAGMENT_DIR}/.${key}.XXXXXX")"

    cat > "$tmp" <<FRAGMENT
# Géré par SE5 (sambaedu-ext-helper.sh) — extension ${key}. Ne pas éditer.
ProxyPass        "/ext/${key}" "http://127.0.0.1:${port}/" retry=0
ProxyPassReverse "/ext/${key}" "http://127.0.0.1:${port}/"
RequestHeader set X-Forwarded-Prefix "/ext/${key}"
FRAGMENT

    chmod 0644 "$tmp"
    chown root:root "$tmp"
    mv -f "$tmp" "$target"
}

cmd_remove_fragment() {
    local key="$1"
    validate_key "$key"
    rm -f "${FRAGMENT_DIR}/${key}.conf"
}

# configtest D'ABORD : un fragment invalide fait échouer CETTE commande (le
# moteur compense) au lieu d'empêcher Apache de redémarrer plus tard. Jamais de
# `restart` : un reload ne coupe aucune connexion en cours.
cmd_reload_apache() {
    apache2ctl configtest
    systemctl reload apache2
}

# ── Dispatch ─────────────────────────────────────────────────────────────────

main() {
    require_root

    local subcommand="${1:-}"
    [[ -n "$subcommand" ]] || usage
    shift || true

    case "$subcommand" in
        write-env)        [[ $# -eq 1 ]] || usage; cmd_write_env "$1" ;;
        remove-env)       [[ $# -eq 1 ]] || usage; cmd_remove_env "$1" ;;
        install-package)  [[ $# -eq 2 ]] || usage; cmd_install_package "$1" "$2" ;;
        remove-package)   [[ $# -eq 1 ]] || usage; cmd_remove_package "$1" ;;
        enable-service)   [[ $# -eq 1 ]] || usage; cmd_enable_service "$1" ;;
        disable-service)  [[ $# -eq 1 ]] || usage; cmd_disable_service "$1" ;;
        write-fragment)   [[ $# -eq 2 ]] || usage; cmd_write_fragment "$1" "$2" ;;
        remove-fragment)  [[ $# -eq 1 ]] || usage; cmd_remove_fragment "$1" ;;
        reload-apache)    [[ $# -eq 0 ]] || usage; cmd_reload_apache ;;
        *)                die "sous-commande inconnue : « $subcommand »" ;;
    esac
}

main "$@"
