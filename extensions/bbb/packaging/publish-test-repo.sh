#!/bin/bash
# =============================================================================
# publish-test-repo.sh — Story 57.1 (outil de DEV/QA interne)
#
# Publie l'extension « Visioconférences » dans un dépôt de catalogue SIGNÉ,
# conforme au format v1 de la Story 56.1 :
#
#   <out>/repo/index.json        catalogue, manifest v1 EMBARQUÉ inline
#   <out>/repo/index.json.sig    base64( sodium_crypto_sign_detached(octets, sk) )
#   <out>/repo/source.pub        base64 de la clé publique Ed25519 (32 octets)
#   <out>/repo/packages/*.deb    les paquets référencés
#
# Le `sha256` injecté dans le manifest est celui du .deb RÉELLEMENT construit,
# en 64 hexadécimaux MINUSCULES (le validateur de SE5 refuse les majuscules —
# signature de défaut connue de l'Epic 56). Il est couvert transitivement par la
# signature de l'index : patron apt `Release → Packages → .deb`.
#
# ── Republication ────────────────────────────────────────────────────────────
#
# Si `<out>/source.key` existe déjà, la clé est RÉUTILISÉE : le pin TOFU d'une
# source SE5 est posé à l'ajout et jamais renégocié — régénérer une paire ferait
# tomber la source en `error`, ce qui n'est pas ce qu'on veut tester.
#
# ⚠️ CE N'EST PAS UN LIVRABLE ÉDITEUR (voir build-deb.sh). Outillage public :
# Story 58.2.
#
# Usage : packaging/publish-test-repo.sh [--version <x.y.z>] [répertoire]
#         (défaut : /tmp/sambaedu-ext-bbb)
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
            [[ $# -ge 2 ]] || { echo "--version attend une valeur" >&2; exit 1; }
            VERSION="$2"; shift 2 ;;
        --version=*)
            VERSION="${1#--version=}"; shift ;;
        -h|--help)
            sed -n '2,32p' "$0" >&2; exit 0 ;;
        *)
            OUT="$1"; shift ;;
    esac
done

OUT="${OUT:-/tmp/sambaedu-ext-bbb}"

command -v php >/dev/null 2>&1 || { echo "Outil requis manquant : php" >&2; exit 1; }
php -r 'exit(extension_loaded("sodium") ? 0 : 1);' \
    || { echo "L'extension PHP sodium est requise (signature Ed25519)." >&2; exit 1; }

if [[ -z "$VERSION" ]]; then
    VERSION="$(php -r '$m = json_decode(file_get_contents($argv[1]), true); echo $m["version"] ?? "";' "$EXT_DIR/manifest.json")"
fi

REUSE_KEY=0
if [[ -f "$OUT/source.key" ]]; then
    REUSE_KEY=1
    echo "Dépôt existant détecté dans $OUT — republication avec la clé déjà pinnée."
fi

mkdir -p "$OUT/repo/packages"

# ── 1. Le paquet, construit ici (le dépôt sert ce qui a été VRAIMENT bâti) ────

bash "$EXT_DIR/packaging/build-deb.sh" --version "$VERSION" "$OUT/repo" >/dev/null

DEB="$OUT/repo/packages/${PKG}_${VERSION}_all.deb"
[[ -f "$DEB" ]] || { echo "Paquet introuvable après construction : $DEB" >&2; exit 1; }

# ── 2. L'index, le manifest injecté, la signature ────────────────────────────

# Le script PHP passe par un fichier temporaire plutôt que par `php -r '...'` :
# une apostrophe française dans un commentaire refermerait la chaîne shell.
PHP_SCRIPT="$(mktemp)"
trap 'rm -f "$PHP_SCRIPT"' EXIT

cat > "$PHP_SCRIPT" <<'PHPSCRIPT'
<?php

$extDir  = $argv[1];
$out     = $argv[2];
$deb     = $argv[3];
$version = $argv[4];
$reuse   = $argv[5] === "1";

$manifest = json_decode(file_get_contents($extDir . "/manifest.json"), true);
if (! is_array($manifest)) {
    fwrite(STDERR, "manifest.json illisible\n");
    exit(1);
}

// La version publiée prime sur celle du fichier : c'est elle qui nomme le
// paquet réellement servi.
$manifest["version"] = $version;

// Le bloc `install` du manifest COMMITÉ porte un sha256 de REMPLISSAGE (64
// zéros) : la valeur réelle n'est connaissable qu'une fois le paquet construit.
// On l'injecte ici, en minuscules — le validateur de SE5 refuse les majuscules —
// avec le chemin relatif du .deb. Publier le manifest tel quel ferait échouer
// l'installation à la frontière fail-closed, jamais après.
$manifest["install"]["channel"]        = "deb";
$manifest["install"]["package"]        = "packages/" . basename($deb);
$manifest["install"]["sha256"]         = strtolower(hash_file("sha256", $deb));
$manifest["install"]["redirect_paths"] = ["/ext/bbb/oidc/callback"];

$index = [
    "index_version" => 1,
    "name"          => "Dépôt de test SE5 — extensions SambaEdu",
    "publisher"     => "SambaEdu",
    "extensions"    => [$manifest],
];

$encoded = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents($out . "/repo/index.json", $encoded);

if ($reuse) {
    $sk = base64_decode(trim(file_get_contents($out . "/source.key")), true);
    if ($sk === false || $sk === "") {
        fwrite(STDERR, "Clé privée de test illisible : " . $out . "/source.key\n");
        exit(1);
    }
    $pk = sodium_crypto_sign_publickey_from_secretkey($sk);
} else {
    $pair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($pair);
    $pk = sodium_crypto_sign_publickey($pair);
}

// La signature porte sur les OCTETS EXACTS servis, jamais sur une forme
// re-encodée : SE5 vérifie AVANT de décoder.
file_put_contents($out . "/repo/index.json.sig", base64_encode(sodium_crypto_sign_detached($encoded, $sk)));
file_put_contents($out . "/repo/source.pub", base64_encode($pk));
file_put_contents($out . "/source.key", base64_encode($sk));
chmod($out . "/source.key", 0600);

echo strtolower(hash_file("sha256", $deb)), "\n";
PHPSCRIPT

php "$PHP_SCRIPT" "$EXT_DIR" "$OUT" "$DEB" "$VERSION" "$REUSE_KEY" > "$OUT/.sha256"

SHA256="$(cat "$OUT/.sha256")"
rm -f "$OUT/.sha256"

cat <<SUMMARY

Dépôt de test prêt.

  paquet       : $DEB
  sha256       : $SHA256
  dépôt        : $OUT/repo
  clé publique : $(cat "$OUT/repo/source.pub")
  clé privée   : $OUT/source.key  (jetable — ne quitte jamais ce répertoire)

Servir le dépôt :
  cd $OUT/repo && python3 -m http.server 8099

Puis dans SE5 : /admin/extensions/sources → « Ajouter une source »
  URL : http://<ip du serveur de test>:8099
  Clé publique : (collée depuis repo/source.pub — obligatoire en http://)

Enfin :
  php artisan ext:sources:sync
  php artisan ext:install ${KEY}

SUMMARY
