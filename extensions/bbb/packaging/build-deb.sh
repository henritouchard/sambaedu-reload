#!/bin/bash
# =============================================================================
# build-deb.sh — Story 57.1 (outil de DEV/QA interne)
#
# Fabrique, SANS AUCUN privilège root, le paquet Debian de l'extension
# « Visioconférences », conforme au contrat de paquet `sambaedu-ext-*` de la
# Story 56.2 :
#
#   - `Package: sambaedu-ext-bbb` EXACTEMENT — le helper root le vérifie par
#     `dpkg-deb --field <deb> Package` et refuse tout écart ;
#   - nom de fichier `sambaedu-ext-bbb_<version>_all.deb` ;
#   - unité systemd LIVRÉE par le paquet dans /lib/systemd/system/, jamais
#     activée ni démarrée par lui (c'est l'orchestrateur SE5 qui le fait, APRÈS
#     avoir posé le fichier d'environnement) ;
#   - `vendor/` EMBARQUÉ : la cible n'a pas composer, et n'aura jamais à sortir
#     sur le réseau pour terminer une installation.
#
# ⚠️ CE N'EST PAS UN LIVRABLE ÉDITEUR. L'outillage de publication destiné aux
# développeurs tiers (génération d'index, gestion des clés, starter kit) relève
# de la Story 58.2. Ce script est un prototype interne au dépôt SE5, taillé pour
# la validation manuelle sur la VM.
#
# Usage : packaging/build-deb.sh [--version <x.y.z>] [répertoire de sortie]
#         (défauts : --version <celle du manifest>, /tmp/sambaedu-ext-bbb)
# =============================================================================

set -euo pipefail

KEY="bbb"
PKG="sambaedu-ext-${KEY}"
EXT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION=""
OUT=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --version)
            [[ $# -ge 2 ]] || { echo "--version attend une valeur (ex. 1.1.0)" >&2; exit 1; }
            VERSION="$2"
            shift 2
            ;;
        --version=*)
            VERSION="${1#--version=}"
            shift
            ;;
        -h|--help)
            sed -n '2,30p' "$0" >&2
            exit 0
            ;;
        *)
            OUT="$1"
            shift
            ;;
    esac
done

OUT="${OUT:-/tmp/sambaedu-ext-bbb}"

for tool in dpkg-deb php composer; do
    command -v "$tool" >/dev/null 2>&1 || { echo "Outil requis manquant : $tool" >&2; exit 1; }
done

# La version par défaut est celle du manifest : une seule source de vérité.
if [[ -z "$VERSION" ]]; then
    VERSION="$(php -r '$m = json_decode(file_get_contents($argv[1]), true); echo $m["version"] ?? "";' "$EXT_DIR/manifest.json")"
fi

# Le champ `Version` d'un paquet Debian n'accepte pas n'importe quoi, et il est
# interpolé dans un nom de fichier servi par HTTP.
[[ "$VERSION" =~ ^[0-9][0-9a-zA-Z.+~-]*$ ]] \
    || { echo "Version de paquet invalide : « $VERSION »" >&2; exit 1; }

BUILD="$OUT/build"
STAGE="$BUILD/usr/share/${PKG}"

rm -rf "$BUILD"
mkdir -p "$BUILD/DEBIAN" "$STAGE" "$BUILD/lib/systemd/system" "$OUT/packages"

# ── 1. Le code + ses dépendances ─────────────────────────────────────────────

cp -a "$EXT_DIR/public" "$EXT_DIR/src" "$EXT_DIR/views" "$STAGE/"
cp -a "$EXT_DIR/composer.json" "$EXT_DIR/manifest.json" "$STAGE/"
[[ -f "$EXT_DIR/composer.lock" ]] && cp -a "$EXT_DIR/composer.lock" "$STAGE/"

# `--no-dev` : PHPUnit n'a rien à faire sur un serveur d'établissement.
# `--classmap-authoritative` : plus aucune recherche de fichier au chargement —
# le contenu du paquet est figé, autant le dire à l'autoloader.
composer install \
    --working-dir="$STAGE" \
    --no-dev \
    --no-interaction \
    --no-progress \
    --classmap-authoritative \
    --quiet

# ── 2. L'unité systemd — LIVRÉE, jamais activée ──────────────────────────────

cp -a "$EXT_DIR/packaging/${PKG}.service" "$BUILD/lib/systemd/system/${PKG}.service"

# ── 3. Les métadonnées Debian ────────────────────────────────────────────────
#
# `Depends` : la VM de SE5 n'a PAS pdo_sqlite pour le PHP du core — c'est
# `php-sqlite3` qui l'apporte. `php-xml` couvre SimpleXML et `php-mbstring` les
# `mb_*` : ces deux-là ne viennent PAS du code de l'extension mais de la
# bibliothèque BigBlueButton, qui les exige dans son propre `composer.json`
# (`ext-simplexml`, `ext-mbstring`). Ils sont donc bien nécessaires — le
# `composer.json` de l'extension les déclare aussi, pour que les deux listes
# disent la même chose (review 57.1 #4).

cat > "$BUILD/DEBIAN/control" <<CONTROL
Package: ${PKG}
Version: ${VERSION}
Section: web
Priority: optional
Architecture: all
Depends: php-cli, php-sqlite3, php-curl, php-xml, php-mbstring
Maintainer: SambaEdu <sambaedu@example.invalid>
Description: Extension SambaEdu 5 « Visioconférences » (BigBlueButton)
 Application autonome servie sous /ext/bbb par SambaEdu 5. Elle s'authentifie
 par OpenID Connect auprès de son instance, conserve son état dans sa propre
 base SQLite, et n'accède à aucune donnée du serveur SambaEdu.
 .
 Le paquet livre son unité systemd sans l'activer : c'est SambaEdu qui la
 démarre, après avoir posé /etc/sambaedu/extensions/bbb.env.
CONTROL

# Contrat de paquet 56.2 : volontairement VIDE de tout enable/start. Et vide,
# aussi, de tout mkdir/chown d'état — `StateDirectory=` de l'unité s'en charge,
# parce que `DynamicUser=yes` rend l'UID volatil (voir l'unité, section ÉTAT).
cat > "$BUILD/DEBIAN/postinst" <<'POSTINST'
#!/bin/sh
set -e
exit 0
POSTINST
chmod 0755 "$BUILD/DEBIAN/postinst"

# ── 4. Le paquet ─────────────────────────────────────────────────────────────

DEB="$OUT/packages/${PKG}_${VERSION}_all.deb"
dpkg-deb --build --root-owner-group "$BUILD" "$DEB" >/dev/null

SHA256="$(sha256sum "$DEB" | cut -d' ' -f1)"

cat <<SUMMARY
Paquet construit.

  fichier : $DEB
  paquet  : $(dpkg-deb --field "$DEB" Package)
  version : $(dpkg-deb --field "$DEB" Version)
  sha256  : $SHA256

Publier un dépôt de test servable :
  packaging/publish-test-repo.sh --version $VERSION $OUT
SUMMARY
