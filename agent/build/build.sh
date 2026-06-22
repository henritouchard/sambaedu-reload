#!/usr/bin/env bash
# =============================================================================
# build.sh — Build + signature Authenticode de l'agent Go (Story 24.5, AC6)
# =============================================================================
# Produit dans agent/build/dist/ le binaire de production :
#   - binaire Go STATIQUE unique (CGO_ENABLED=0), cross-compilé Windows
#     (GOOS=windows GOARCH=amd64) — zéro dépendance runtime sur le poste
#     (NFR6) ;
#   - SIGNÉ Authenticode via osslsigncode (le build tourne sur l'hôte Linux ;
#     Set-AuthenticodeSignature exigerait Windows) avec le certificat
#     code-signing émis par la CA interne SambaEdu (racine déjà déployée sur
#     les postes par la chaîne iPXE 23.3) ;
#   - signature VÉRIFIÉE par le build lui-même (osslsigncode verify).
#
# Usage :
#   CODESIGN_PFX=/chemin/sambaedu-codesign.pfx \
#   CODESIGN_CA=/chemin/ca-root.crt \
#   [CODESIGN_PASS=...] [TIMESTAMP_URL=...] [VERSION=x.y.z] \
#   agent/build/build.sh
#
# Variables :
#   GO             binaire go (défaut : `go` du PATH, sinon ~/go-toolchain/go/bin/go)
#   VERSION        version injectée (-ldflags -X) ; défaut : shared/version.go
#   CODESIGN_PFX   PFX code-signing (clé + chaîne) émis par la CA interne.
#                  Émission : cf. agent/README.md §Signature (la PKI vit sur
#                  le serveur SE5 : storage/keys/pki/). Le PFX ne se commit
#                  JAMAIS dans le repo.
#   CODESIGN_PASS  mot de passe du PFX (optionnel)
#   CODESIGN_CA    certificat RACINE (PEM) pour la vérification post-build
#                  (ex. storage/keys/pki/ca-root.crt côté serveur)
#   TIMESTAMP_URL  serveur d'horodatage (défaut : digicert ; vide = pas
#                  d'horodatage, lab hors-ligne)
#   OSSLSIGNCODE   binaire osslsigncode (défaut : PATH, sinon
#                  ~/.local/opt/osslsigncode/usr/bin/osslsigncode)
#   ALLOW_UNSIGNED=1  sortie non signée — DEV/TEST UNIQUEMENT : un artefact
#                  non signé ne se DÉPLOIE jamais (NFR6, SmartScreen).
# =============================================================================
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
agent_dir="$(dirname "$here")"
dist_dir="$here/dist"

# --- Toolchain ----------------------------------------------------------------
GO="${GO:-}"
if [[ -z "$GO" ]]; then
    if command -v go >/dev/null 2>&1; then
        GO=go
    elif [[ -x "$HOME/go-toolchain/go/bin/go" ]]; then
        GO="$HOME/go-toolchain/go/bin/go"
    else
        echo "ERREUR : toolchain Go introuvable (PATH ou ~/go-toolchain) — cf. agent/README.md §Toolchain." >&2
        exit 1
    fi
fi

OSSLSIGNCODE="${OSSLSIGNCODE:-}"
if [[ -z "$OSSLSIGNCODE" ]]; then
    if command -v osslsigncode >/dev/null 2>&1; then
        OSSLSIGNCODE=osslsigncode
    elif [[ -x "$HOME/.local/opt/osslsigncode/usr/bin/osslsigncode" ]]; then
        OSSLSIGNCODE="$HOME/.local/opt/osslsigncode/usr/bin/osslsigncode"
    fi
fi

# --- Version (source unique : shared/version.go, injectable -X) ----------------
default_version="$(grep -oP 'Version = "\K[^"]+' "$agent_dir/shared/version.go")"
VERSION="${VERSION:-$default_version}"

# --- 1. Build statique cross-compilé -------------------------------------------
mkdir -p "$dist_dir"
unsigned="$dist_dir/sambaedu-agent-$VERSION-unsigned.exe"
signed="$dist_dir/sambaedu-agent-$VERSION.exe"

echo "Build : agent Go $VERSION (windows/amd64, statique)…"
# -buildvcs=false : la version vient de -ldflags -X (shared/version.go), pas du
# VCS. Sans ce flag, `go build` (buildvcs=auto) lance `git` dans le repo pour
# estampiller commit/date ; ça échoue (exit 128, build avorté) si le repo est
# owné par un autre user que celui qui builde (ex. déploiement root sur arbo
# owned uid 1000). On rend donc le build insensible à la méthode de déploiement.
(cd "$agent_dir" && CGO_ENABLED=0 GOOS=windows GOARCH=amd64 \
    "$GO" build -trimpath -buildvcs=false \
    -ldflags "-s -w -X sambaedu/agent/shared.Version=$VERSION" \
    -o "$unsigned" ./windows)
echo "Binaire : $unsigned ($(du -h "$unsigned" | cut -f1))"

# --- 2. Signature Authenticode ---------------------------------------------------
if [[ -z "${CODESIGN_PFX:-}" ]]; then
    if [[ "${ALLOW_UNSIGNED:-0}" == "1" ]]; then
        echo "AVERTISSEMENT : CODESIGN_PFX absent + ALLOW_UNSIGNED=1 → binaire NON SIGNÉ (jamais déployable, NFR6)." >&2
        exit 0
    fi
    cat >&2 <<'EOF'
ERREUR : CODESIGN_PFX non défini — le binaire de production DOIT être signé
Authenticode avec la CA interne SambaEdu (NFR6 : non signé = SmartScreen
bloque). Émettre le certificat code-signing depuis la PKI interne
(storage/keys/pki/ sur le serveur SE5) : cf. agent/README.md §Signature.
(Pour un build de dev local non déployable : ALLOW_UNSIGNED=1.)
EOF
    exit 1
fi

if [[ -z "$OSSLSIGNCODE" ]]; then
    echo "ERREUR : osslsigncode introuvable (apt-get download osslsigncode + dpkg -x, cf. agent/README.md §Signature)." >&2
    exit 1
fi

sign_args=(sign -pkcs12 "$CODESIGN_PFX"
    -n "SambaEdu Agent (desired-state)"
    -i "https://www.sambaedu.org"
    -h sha256)
if [[ -n "${CODESIGN_PASS:-}" ]]; then
    sign_args+=(-pass "$CODESIGN_PASS")
fi
# Horodatage : la signature reste valide après expiration du certificat.
# TIMESTAMP_URL="" pour un lab hors-ligne.
TIMESTAMP_URL="${TIMESTAMP_URL-http://timestamp.digicert.com}"
if [[ -n "$TIMESTAMP_URL" ]]; then
    sign_args+=(-ts "$TIMESTAMP_URL")
fi

echo "Signature Authenticode (osslsigncode)…"
rm -f "$signed" # osslsigncode refuse d'écraser une sortie existante (re-run du build)
"$OSSLSIGNCODE" "${sign_args[@]}" -in "$unsigned" -out "$signed"

# --- 3. Vérification post-build (le build se contrôle lui-même) -------------------
if [[ -n "${CODESIGN_CA:-}" ]]; then
    echo "Vérification de la signature (chaîne → CA interne)…"
    "$OSSLSIGNCODE" verify -in "$signed" -CAfile "$CODESIGN_CA"
else
    echo "AVERTISSEMENT : CODESIGN_CA non défini — vérification de la chaîne IMPOSSIBLE." >&2
    echo "Passer CODESIGN_CA=<ca-root.crt> (la racine déployée par iPXE 23.3) pour un build de production." >&2
    exit 1
fi

rm -f "$unsigned"
echo ""
echo "Build OK : $signed"
echo "Déploiement lab : copier vers C:\\Program Files\\SambaEdu\\Agent\\agent.exe puis « agent.exe install -server-url http://<serveur-se5> » (cf. agent/README.md)."
